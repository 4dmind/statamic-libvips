<?php

namespace Fdmind\StatamicLibvips\Tests;

use Fdmind\StatamicLibvips\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
