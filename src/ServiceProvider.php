<?php

namespace Fdmind\StatamicLibvips;

use Fdmind\StatamicLibvips\Glide\VipsApi;
use Illuminate\Support\Facades\Facade;
use Intervention\Image\ImageManager;
use League\Glide\Server;
use Statamic\Imaging\GlideManager;
use Statamic\Imaging\ImageValidator;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic-libvips.php', 'statamic-libvips');

        // VipsApi only depends on our own config; safe to build unconditionally
        // so commands/tests can resolve it directly.
        $this->app->singleton(VipsApi::class, function ($app) {
            return new VipsApi($app['config']->get('statamic-libvips', []));
        });

        $this->bindGlideManager();
        $this->bindImageValidator();
    }

    public function bootAddon()
    {
        $this->publishes([
            __DIR__.'/../config/statamic-libvips.php' => config_path('statamic-libvips.php'),
        ], 'statamic-libvips-config');

        if (! $this->usingVips($this->app)) {
            return;
        }

        // Statamic's core GlideServiceProvider registers *after* this addon and
        // re-binds ImageValidator with a closure that calls
        // ImageManager::withDriver('vips') — which throws. Re-assert our binding
        // here in the boot phase (after all register() calls) so ours wins, and
        // drop anything resolved during boot so it rebuilds against our bindings.
        $this->bindImageValidator();

        Facade::clearResolvedInstance(GlideManager::class);
        $this->app->forgetInstance(GlideManager::class);
        $this->app->forgetInstance(ImageValidator::class);
        $this->app->forgetInstance(Server::class);
    }

    protected function bindGlideManager(): void
    {
        // Replaces the Intervention-backed Glide server everywhere it's resolved
        // (the Server singleton + GlideManager::clearAsset()). The driver is read
        // at resolution time, not registration time, because config may not be
        // populated yet when this provider registers (e.g. Testbench).
        $this->app->bind(GlideManager::class, function ($app) {
            return $this->usingVips($app)
                ? new VipsGlideManager
                : new GlideManager;
        });
    }

    protected function bindImageValidator(): void
    {
        // Statamic's own ImageValidator binding calls ImageManager::withDriver()
        // which throws on the "vips" string; swap in our extension-only validator.
        // For every other driver we faithfully rebuild core's validator — but how
        // it's constructed depends on the installed Intervention Image major
        // version, so that work lives in makeNativeImageValidator().
        $this->app->bind(ImageValidator::class, function ($app) {
            if ($this->usingVips($app)) {
                return new VipsImageValidator;
            }

            return $this->makeNativeImageValidator($app);
        });
    }

    /**
     * Rebuild Statamic's own ImageValidator for the non-vips path, matching the
     * installed Intervention Image version:
     *
     *  - Intervention v2 (Statamic with intervention/image ^2): ImageValidator
     *    validates extensions from the driver *string* and takes no driver
     *    instance — and ImageManager has no static gd()/imagick() factories, so
     *    calling them is a fatal "undefined method".
     *  - Intervention v3: ImageValidator requires a DriverInterface.
     *
     * We reflect on the constructor to tell them apart, keeping the addon a safe
     * drop-in on both — including while dormant on a plain gd/imagick site.
     */
    protected function makeNativeImageValidator($app): ImageValidator
    {
        $constructor = (new \ReflectionClass(ImageValidator::class))->getConstructor();

        if (! $constructor || $constructor->getNumberOfRequiredParameters() === 0) {
            return new ImageValidator;
        }

        return new ImageValidator($this->interventionDriver($app));
    }

    protected function usingVips($app): bool
    {
        return $app['config']->get('statamic.assets.image_manipulation.driver') === 'vips';
    }

    /**
     * Mirror GlideServiceProvider's driver resolution for the non-vips path so
     * our binding is a faithful drop-in for gd / imagick / custom drivers.
     */
    protected function interventionDriver($app)
    {
        $driver = $app['config']->get('statamic.assets.image_manipulation.driver', 'gd');

        $manager = match ($driver) {
            'gd' => ImageManager::gd(),
            'imagick' => ImageManager::imagick(),
            default => ImageManager::withDriver($driver),
        };

        return $manager->driver();
    }
}
