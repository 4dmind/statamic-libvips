<?php

namespace Fdmind\StatamicLibvips\Commands;

use Fdmind\StatamicLibvips\Support\PresetJobStore;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Glide;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Generate every asset preset manipulation in parallel, using as many worker
 * processes as the server can handle.
 *
 * Work is enumerated once into a SQLite database (one row per
 * container/asset/preset), then a pool of worker processes drains it. The DB is
 * also the progress + resume source: re-running continues where it left off,
 * and crashed workers' in-flight rows are requeued automatically.
 */
class GeneratePresetsParallel extends Command
{
    use RunsInPlease;

    protected $signature = 'assets:generate-presets-parallel
        {--workers= : Number of worker processes (defaults to the CPU core count).}
        {--batch=5 : Jobs each worker claims per iteration.}
        {--containers= : Comma separated container handles to include (default: all).}
        {--excluded-containers= : Comma separated container handles to exclude.}
        {--presets= : Comma separated preset names to include (default: all warm presets).}
        {--fresh : Discard any existing run and rebuild the work list from scratch.}
        {--retry-failed : Requeue jobs that failed on a previous run.}
        {--force : Regenerate even if a cached image already exists.}';

    protected $description = 'Generate asset preset manipulations in parallel.';

    public function handle(): int
    {
        $store = new PresetJobStore($this->storePath());
        $store->migrate();

        if ($this->option('fresh')) {
            $store->truncate();
        }

        // Seed the work list unless we're resuming a run that still has rows.
        if ($store->stats()['total'] === 0) {
            $this->seed($store);
        } else {
            $this->components->info('Resuming existing run. Use --fresh to start over.');
        }

        // Recover anything left mid-flight by a previously crashed worker, and
        // optionally requeue failures.
        $store->resetStale();
        if ($this->option('retry-failed')) {
            $requeued = $store->resetFailed();
            $this->components->info("Requeued {$requeued} failed job(s).");
        }

        $stats = $store->stats();
        $total = $stats['total'];

        if ($total === 0) {
            $this->components->warn('No image presets to generate.');

            return self::SUCCESS;
        }

        if ($stats['pending'] === 0) {
            $this->components->info('Everything is already generated. Nothing to do.');
            $this->summary($store);

            return self::SUCCESS;
        }

        $workers = $this->workerCount();
        $this->components->info("Generating {$stats['pending']} of {$total} presets across {$workers} workers.");

        $this->drain($store, $workers, $total);

        $store->resetStale();
        $this->summary($store);

        return $store->stats()['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Enumerate containers -> image assets -> warm presets into the store.
     */
    protected function seed(PresetJobStore $store): void
    {
        $only = $this->listOption('containers');
        $excluded = $this->listOption('excluded-containers');
        $presetFilter = $this->listOption('presets');
        $force = (bool) $this->option('force');

        $containers = AssetContainer::all()
            ->when($only, fn ($c) => $c->filter(fn ($container) => in_array($container->handle(), $only)))
            ->reject(fn ($container) => in_array($container->handle(), $excluded));

        $seeded = 0;

        foreach ($containers as $container) {
            $assets = $container->assets()->filter->isImage();

            $this->components->task(
                "Scanning [{$container->handle()}] ({$assets->count()} images)",
                function () use ($assets, $container, $presetFilter, $force, $store, &$seeded) {
                    $rows = [];

                    foreach ($assets as $asset) {
                        // Force a clean slate here, in the single-threaded
                        // orchestrator, so workers never race on the same asset.
                        if ($force) {
                            Glide::clearAsset($asset);
                        }

                        foreach ($asset->warmPresets() as $preset) {
                            if ($presetFilter && ! in_array($preset, $presetFilter)) {
                                continue;
                            }

                            $rows[] = [
                                'container' => $container->handle(),
                                'path' => $asset->path(),
                                'preset' => $preset,
                            ];
                        }
                    }

                    $seeded += $store->seed($rows);
                }
            );
        }

        $this->components->info("Queued {$seeded} preset job(s).");
    }

    /**
     * Spawn the worker pool and poll the store to drive a progress bar.
     */
    protected function drain(PresetJobStore $store, int $workers, int $total): void
    {
        $command = $this->workerCommand($store);

        /** @var array<int, Process> $processes */
        $processes = [];
        for ($i = 0; $i < $workers; $i++) {
            $processes[$i] = $this->startWorker($command, $i);
        }

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  done:%done% failed:%failed% (%elapsed%)');
        $bar->setMessage('0', 'done');
        $bar->setMessage('0', 'failed');

        $maxRespawns = $workers * 5;
        $respawns = 0;

        do {
            usleep(250_000);

            $stats = $store->stats();
            $bar->setMessage((string) $stats['done'], 'done');
            $bar->setMessage((string) $stats['failed'], 'failed');
            $bar->setProgress($stats['done'] + $stats['failed']);

            $running = false;
            foreach ($processes as $i => $process) {
                if ($process->isRunning()) {
                    $running = true;

                    continue;
                }

                // A worker exited. Normally that means the queue is drained, but
                // if pending work remains it crashed — respawn a replacement.
                if ($store->pending() > 0 && $respawns < $maxRespawns) {
                    $processes[$i] = $this->startWorker($command, $i);
                    $respawns++;
                    $running = true;
                }
            }
        } while ($running || $store->pending() > 0);

        $bar->finish();
        $this->newLine(2);

        if ($respawns > 0) {
            $this->components->warn("Respawned {$respawns} crashed worker(s).");
        }
    }

    protected function startWorker(array $command, int $id): Process
    {
        $process = new Process([...$command, '--worker='.$id]);
        $process->setTimeout(null);
        $process->start();

        return $process;
    }

    /**
     * @return array<int, string>
     */
    protected function workerCommand(PresetJobStore $store): array
    {
        $php = (new PhpExecutableFinder)->find() ?: 'php';

        return [
            $php,
            base_path('please'),
            'statamic-libvips:presets-worker',
            '--store='.$store->path(),
            '--batch='.max(1, (int) $this->option('batch')),
        ];
    }

    protected function summary(PresetJobStore $store): void
    {
        $stats = $store->stats();

        $this->components->twoColumnDetail('<fg=green>Generated</>', (string) $stats['done']);
        if ($stats['failed'] > 0) {
            $this->components->twoColumnDetail('<fg=red>Failed</>', (string) $stats['failed']);
        }
        if ($stats['pending'] + $stats['processing'] > 0) {
            $this->components->twoColumnDetail('<fg=yellow>Incomplete</>', (string) ($stats['pending'] + $stats['processing']));
        }

        foreach ($store->failures() as $failure) {
            $this->components->error(
                "[{$failure['container']}] {$failure['path']} ({$failure['preset']}): {$failure['error']}"
            );
        }
    }

    protected function workerCount(): int
    {
        if ($option = $this->option('workers')) {
            return max(1, (int) $option);
        }

        return $this->cpuCores();
    }

    protected function cpuCores(): int
    {
        if (function_exists('shell_exec')) {
            $count = (int) (@shell_exec('nproc 2>/dev/null')
                ?: @shell_exec('sysctl -n hw.ncpu 2>/dev/null'));

            if ($count > 0) {
                return $count;
            }
        }

        return 4;
    }

    /**
     * @return array<int, string>
     */
    protected function listOption(string $name): array
    {
        $value = $this->option($name);

        if (! $value) {
            return [];
        }

        return array_filter(array_map('trim', explode(',', $value)));
    }

    protected function storePath(): string
    {
        $dir = storage_path('statamic-libvips');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir.'/presets.sqlite';
    }
}
