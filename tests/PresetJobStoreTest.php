<?php

namespace Fdmind\StatamicLibvips\Tests;

use Fdmind\StatamicLibvips\Support\PresetJobStore;
use PHPUnit\Framework\Attributes\Test;

class PresetJobStoreTest extends TestCase
{
    protected string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir().'/libvips-presets-'.uniqid().'.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath.'-wal', $this->dbPath.'-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    protected function store(): PresetJobStore
    {
        $store = new PresetJobStore($this->dbPath);
        $store->migrate();

        return $store;
    }

    protected function rows(int $n): array
    {
        return array_map(fn ($i) => [
            'container' => 'assets',
            'path' => "img/photo-{$i}.jpg",
            'preset' => 'small',
        ], range(1, $n));
    }

    #[Test]
    public function it_seeds_and_counts_jobs(): void
    {
        $store = $this->store();
        $inserted = $store->seed($this->rows(10));

        $this->assertSame(10, $inserted);
        $this->assertSame(10, $store->stats()['pending']);
        $this->assertSame(10, $store->stats()['total']);
    }

    #[Test]
    public function it_ignores_duplicate_work_items(): void
    {
        $store = $this->store();
        $store->seed($this->rows(5));
        $reinserted = $store->seed($this->rows(5));

        $this->assertSame(0, $reinserted);
        $this->assertSame(5, $store->stats()['total']);
    }

    #[Test]
    public function it_claims_pending_jobs_and_marks_them_processing(): void
    {
        $store = $this->store();
        $store->seed($this->rows(10));

        $claimed = $store->claim(worker: 1, limit: 4);

        $this->assertCount(4, $claimed);
        $this->assertSame(6, $store->stats()['pending']);
        $this->assertSame(4, $store->stats()['processing']);
    }

    #[Test]
    public function concurrent_workers_never_claim_the_same_job(): void
    {
        $this->store()->seed($this->rows(20));

        // Two independent connections to the same database file, like two
        // separate worker processes.
        $a = new PresetJobStore($this->dbPath);
        $b = new PresetJobStore($this->dbPath);

        $claimedIds = [];
        $rounds = 0;

        do {
            $batchA = array_column($a->claim(1, 3), 'id');
            $batchB = array_column($b->claim(2, 3), 'id');

            // No id may appear in both workers' claims.
            $this->assertEmpty(array_intersect($batchA, $batchB));

            $claimedIds = array_merge($claimedIds, $batchA, $batchB);
            $rounds++;
        } while (($batchA || $batchB) && $rounds < 50);

        // Every job claimed exactly once.
        $this->assertCount(20, $claimedIds);
        $this->assertCount(20, array_unique($claimedIds));
        $this->assertSame(0, $a->pending());
    }

    #[Test]
    public function it_records_completions_and_failures(): void
    {
        $store = $this->store();
        $store->seed($this->rows(3));
        $claimed = $store->claim(1, 3);

        $store->complete($claimed[0]['id']);
        $store->fail($claimed[1]['id'], 'boom');

        $stats = $store->stats();
        $this->assertSame(1, $stats['done']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(1, $stats['processing']);

        $failures = $store->failures();
        $this->assertCount(1, $failures);
        $this->assertSame('boom', $failures[0]['error']);
    }

    #[Test]
    public function it_requeues_stale_processing_jobs(): void
    {
        $store = $this->store();
        $store->seed($this->rows(5));
        $store->claim(1, 5); // simulate a crash: left processing, never completed

        $this->assertSame(5, $store->stats()['processing']);

        $reset = $store->resetStale();

        $this->assertSame(5, $reset);
        $this->assertSame(5, $store->stats()['pending']);
        $this->assertSame(0, $store->stats()['processing']);
    }

    #[Test]
    public function it_requeues_failed_jobs(): void
    {
        $store = $this->store();
        $store->seed($this->rows(2));
        $claimed = $store->claim(1, 2);
        $store->fail($claimed[0]['id'], 'nope');
        $store->fail($claimed[1]['id'], 'nope');

        $this->assertSame(2, $store->resetFailed());
        $this->assertSame(2, $store->stats()['pending']);
        $this->assertSame(0, $store->stats()['failed']);
    }

    #[Test]
    public function truncate_clears_all_jobs(): void
    {
        $store = $this->store();
        $store->seed($this->rows(7));
        $store->truncate();

        $this->assertSame(0, $store->stats()['total']);
    }

    #[Test]
    public function claim_returns_empty_when_drained(): void
    {
        $store = $this->store();
        $store->seed($this->rows(2));
        $store->claim(1, 5);

        $this->assertSame([], $store->claim(1, 5));
    }
}
