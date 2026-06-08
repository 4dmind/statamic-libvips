<?php

namespace Fdmind\StatamicLibvips\Tests;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;

class CommandRegistrationTest extends TestCase
{
    #[Test]
    public function the_parallel_command_is_registered(): void
    {
        $commands = $this->app->make(Kernel::class)->all();

        $this->assertArrayHasKey('assets:generate-presets-parallel', $commands);
    }

    #[Test]
    public function the_worker_command_is_registered_and_hidden(): void
    {
        $commands = $this->app->make(Kernel::class)->all();

        $this->assertArrayHasKey('statamic-libvips:presets-worker', $commands);
        $this->assertTrue($commands['statamic-libvips:presets-worker']->isHidden());
    }
}
