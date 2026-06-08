<?php

namespace Fdmind\StatamicLibvips\Commands;

use Fdmind\StatamicLibvips\Support\PresetJobStore;
use Illuminate\Console\Command;
use Jcupitt\Vips\Config as VipsConfig;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\AssetContainer;
use Statamic\Imaging\PresetGenerator;
use Throwable;

/**
 * Internal worker process spawned by assets:generate-presets-parallel.
 *
 * It repeatedly claims a batch of pending jobs from the shared SQLite store,
 * generates each preset, and records the outcome — until no pending work
 * remains, then exits. Not intended to be run by hand.
 */
class GeneratePresetsWorker extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic-libvips:presets-worker
        {--store= : Path to the SQLite orchestration database.}
        {--worker=0 : Numeric id of this worker.}
        {--batch=5 : How many jobs to claim per iteration.}';

    protected $description = 'Internal worker for parallel preset generation.';

    protected $hidden = true;

    /** @var array<string, \Statamic\Contracts\Assets\AssetContainer|null> */
    protected array $containers = [];

    public function handle(PresetGenerator $generator): int
    {
        if (! $store = $this->option('store')) {
            $this->error('A --store path is required.');

            return self::FAILURE;
        }

        $worker = (int) $this->option('worker');
        $batch = max(1, (int) $this->option('batch'));

        // Each worker runs libvips single-threaded so N workers saturate N
        // cores without oversubscription (many 1-thread procs > 1 N-thread proc
        // for batch throughput). No-op when the vips driver isn't in use.
        if (class_exists(VipsConfig::class)) {
            VipsConfig::concurrencySet(1);
        }

        $store = new PresetJobStore($store);

        while ($jobs = $store->claim($worker, $batch)) {
            foreach ($jobs as $job) {
                try {
                    $asset = $this->resolveAsset($job['container'], $job['path']);

                    if (! $asset) {
                        throw new \RuntimeException('Asset not found.');
                    }

                    $generator->generate($asset, $job['preset']);
                    $store->complete($job['id']);
                } catch (Throwable $e) {
                    $store->fail($job['id'], $e->getMessage());
                }
            }
        }

        return self::SUCCESS;
    }

    protected function resolveAsset(string $container, string $path)
    {
        $this->containers[$container] ??= AssetContainer::findByHandle($container)
            ?? AssetContainer::find($container);

        return $this->containers[$container]?->asset($path);
    }
}
