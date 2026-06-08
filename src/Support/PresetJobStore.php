<?php

namespace Fdmind\StatamicLibvips\Support;

use PDO;
use Throwable;

/**
 * A tiny SQLite-backed work queue used to orchestrate parallel preset
 * generation across multiple worker processes.
 *
 * One row = one (container, asset path, preset) unit of work. The orchestrator
 * seeds the table; worker processes atomically claim pending rows, process them
 * and mark them done/failed. The same table doubles as the progress source the
 * orchestrator polls to render its progress bar.
 *
 * WAL journalling lets the orchestrator read progress while workers write, and
 * "BEGIN IMMEDIATE" + busy_timeout serialise the claim step so no two workers
 * ever grab the same row.
 */
class PresetJobStore
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected PDO $pdo;

    public function __construct(protected string $path)
    {
        $this->pdo = new PDO('sqlite:'.$path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // WAL: concurrent readers + a single writer. busy_timeout makes writers
        // wait (rather than error) when another worker holds the write lock.
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA synchronous=NORMAL');
        $this->pdo->exec('PRAGMA busy_timeout=15000');
    }

    public function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                container TEXT NOT NULL,
                path TEXT NOT NULL,
                preset TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                worker INTEGER,
                attempts INTEGER NOT NULL DEFAULT 0,
                error TEXT,
                started_at TEXT,
                finished_at TEXT,
                UNIQUE(container, path, preset)
            )
        SQL);

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS jobs_status_index ON jobs (status)');
    }

    public function truncate(): void
    {
        $this->pdo->exec('DELETE FROM jobs');
        $this->pdo->exec('DELETE FROM sqlite_sequence WHERE name = \'jobs\'');
    }

    /**
     * Bulk-insert work items. Duplicate (container, path, preset) tuples are
     * ignored so re-seeding an existing run is a no-op for already-known work.
     *
     * @param  iterable<array{container:string,path:string,preset:string}>  $rows
     */
    public function seed(iterable $rows): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO jobs (container, path, preset) VALUES (:container, :path, :preset)'
        );

        $inserted = 0;
        $this->pdo->exec('BEGIN');

        try {
            foreach ($rows as $row) {
                $stmt->execute([
                    ':container' => $row['container'],
                    ':path' => $row['path'],
                    ':preset' => $row['preset'],
                ]);
                $inserted += $stmt->rowCount();
            }
            $this->pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }

        return $inserted;
    }

    /**
     * Atomically claim up to $limit pending rows for the given worker.
     *
     * @return array<int, array{id:int,container:string,path:string,preset:string}>
     */
    public function claim(int $worker, int $limit): array
    {
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $rows = $this->pdo
                ->query('SELECT id, container, path, preset FROM jobs WHERE status = \''.self::PENDING.'\' ORDER BY id LIMIT '.max(1, $limit))
                ->fetchAll();

            if ($rows) {
                $ids = array_column($rows, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                $update = $this->pdo->prepare(
                    'UPDATE jobs SET status = \''.self::PROCESSING.'\', worker = ?, attempts = attempts + 1, started_at = ? WHERE id IN ('.$placeholders.')'
                );
                $update->execute([$worker, $this->now(), ...$ids]);
            }

            $this->pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }

        return $rows;
    }

    public function complete(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE jobs SET status = \''.self::DONE.'\', error = NULL, finished_at = ? WHERE id = ?'
        );
        $stmt->execute([$this->now(), $id]);
    }

    public function fail(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE jobs SET status = \''.self::FAILED.'\', error = ?, finished_at = ? WHERE id = ?'
        );
        $stmt->execute([mb_substr($error, 0, 2000), $this->now(), $id]);
    }

    /**
     * Requeue rows left "processing" by a crashed worker from a previous run.
     */
    public function resetStale(): int
    {
        return $this->pdo->exec(
            'UPDATE jobs SET status = \''.self::PENDING.'\', worker = NULL, started_at = NULL WHERE status = \''.self::PROCESSING.'\''
        );
    }

    public function resetFailed(): int
    {
        return $this->pdo->exec(
            'UPDATE jobs SET status = \''.self::PENDING.'\', error = NULL, started_at = NULL, finished_at = NULL WHERE status = \''.self::FAILED.'\''
        );
    }

    /**
     * @return array{pending:int,processing:int,done:int,failed:int,total:int}
     */
    public function stats(): array
    {
        $counts = [self::PENDING => 0, self::PROCESSING => 0, self::DONE => 0, self::FAILED => 0];

        foreach ($this->pdo->query('SELECT status, COUNT(*) AS c FROM jobs GROUP BY status') as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }

        return [
            'pending' => $counts[self::PENDING],
            'processing' => $counts[self::PROCESSING],
            'done' => $counts[self::DONE],
            'failed' => $counts[self::FAILED],
            'total' => array_sum($counts),
        ];
    }

    /**
     * Rows still waiting to be processed (claimable now or in flight).
     */
    public function outstanding(): int
    {
        return (int) $this->pdo
            ->query('SELECT COUNT(*) FROM jobs WHERE status IN (\''.self::PENDING.'\', \''.self::PROCESSING.'\')')
            ->fetchColumn();
    }

    public function pending(): int
    {
        return (int) $this->pdo
            ->query('SELECT COUNT(*) FROM jobs WHERE status = \''.self::PENDING.'\'')
            ->fetchColumn();
    }

    /**
     * @return array<int, array{container:string,path:string,preset:string,error:string}>
     */
    public function failures(int $limit = 50): array
    {
        return $this->pdo
            ->query('SELECT container, path, preset, error FROM jobs WHERE status = \''.self::FAILED.'\' ORDER BY id LIMIT '.max(1, $limit))
            ->fetchAll();
    }

    public function path(): string
    {
        return $this->path;
    }

    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
