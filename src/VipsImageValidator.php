<?php

namespace Fdmind\StatamicLibvips;

use Statamic\Imaging\ImageValidator;

/**
 * Validates uploaded/served image extensions for the vips driver without
 * needing an Intervention DriverInterface (which the parent requires only to
 * answer supports()). We override the constructor to skip that dependency and
 * answer extension support from libvips' known format list instead.
 */
class VipsImageValidator extends ImageValidator
{
    /**
     * Formats libvips can read & write in a typical build. Extra extensions
     * configured via statamic.assets.image_manipulation.additional_extensions
     * are merged in by isValidExtension().
     */
    protected array $supported = [
        'jpg', 'jpeg', 'pjpg', 'png', 'gif', 'webp', 'avif', 'tiff', 'tif', 'bmp', 'heic', 'heif',
    ];

    public function __construct()
    {
        // Intentionally does not call parent::__construct(): no driver needed.
    }

    public function isValidExtension($extension)
    {
        if (! $extension) {
            return false;
        }

        $extra = (array) config('statamic.assets.image_manipulation.additional_extensions', []);

        $allowed = array_map('strtolower', array_merge($this->supported, $extra));

        return in_array(strtolower($extension), $allowed, true);
    }
}
