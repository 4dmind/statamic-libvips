<?php

namespace Fdmind\StatamicLibvips\Tests;

use Fdmind\StatamicLibvips\Glide\VipsApi;
use Fdmind\StatamicLibvips\VipsGlideManager;
use Fdmind\StatamicLibvips\VipsImageValidator;
use League\Glide\Server;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Imaging\GlideManager;
use Statamic\Imaging\ImageValidator;

class IntegrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('statamic.assets.image_manipulation.driver', 'vips');
    }

    #[Test]
    public function it_swaps_the_glide_manager(): void
    {
        $this->assertInstanceOf(VipsGlideManager::class, $this->app->make(GlideManager::class));
    }

    #[Test]
    public function it_swaps_the_image_validator(): void
    {
        $this->assertInstanceOf(VipsImageValidator::class, $this->app->make(ImageValidator::class));
    }

    #[Test]
    public function the_glide_server_uses_the_vips_api(): void
    {
        $server = $this->app->make(Server::class);

        $this->assertInstanceOf(Server::class, $server);
        $this->assertInstanceOf(VipsApi::class, $server->getApi());
    }

    #[Test]
    public function the_validator_accepts_vips_formats(): void
    {
        $validator = new VipsImageValidator;

        $this->assertTrue($validator->isValidExtension('jpg'));
        $this->assertTrue($validator->isValidExtension('webp'));
        $this->assertTrue($validator->isValidExtension('avif'));
        $this->assertFalse($validator->isValidExtension('txt'));
        $this->assertFalse($validator->isValidExtension(''));
    }
}

class NonVipsIntegrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('statamic.assets.image_manipulation.driver', 'gd');
    }

    #[Test]
    public function it_leaves_glide_alone_when_driver_is_not_vips(): void
    {
        $manager = $this->app->make(GlideManager::class);

        $this->assertInstanceOf(GlideManager::class, $manager);
        $this->assertNotInstanceOf(VipsGlideManager::class, $manager);
    }
}
