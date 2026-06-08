<?php

namespace Fdmind\StatamicLibvips;

use Fdmind\StatamicLibvips\Glide\VipsApi;
use League\Glide\Server;
use Statamic\Imaging\GlideManager;

/**
 * Drop-in replacement for Statamic's GlideManager that swaps Glide's image
 * processing API for our libvips powered one.
 *
 * We deliberately let the parent build the fully-configured server (cache
 * disk, response factory, cache-path callables, presets, watermarks) using a
 * harmless "gd" driver so none of that private wiring has to be duplicated,
 * then replace the freshly-built Api with VipsApi. This covers both the main
 * Server singleton and GlideManager::clearAsset(), which builds its own server.
 */
class VipsGlideManager extends GlideManager
{
    public function server(array $config = []): Server
    {
        // Force a real, instantiable Intervention driver for the factory so it
        // doesn't choke on the "vips" driver string. The resulting Intervention
        // ImageManager is immediately discarded when we swap the Api below.
        $server = parent::server(array_merge(['driver' => 'gd'], $config));

        $server->setApi(app(VipsApi::class));

        return $server;
    }
}
