<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Quality
    |--------------------------------------------------------------------------
    |
    | The encoding quality used when Glide doesn't request a specific quality
    | via the "q" parameter. Applies to lossy formats (jpeg, webp, avif).
    |
    */

    'quality' => 90,

    /*
    |--------------------------------------------------------------------------
    | Strip Metadata
    |--------------------------------------------------------------------------
    |
    | Remove EXIF/ICC/XMP metadata from generated images. Stripping reduces
    | file size and avoids leaking camera/location data. The orientation is
    | always honoured before stripping. ICC profiles are converted to sRGB.
    |
    */

    'strip' => true,

    /*
    |--------------------------------------------------------------------------
    | Interlace / Progressive
    |--------------------------------------------------------------------------
    |
    | Save JPEGs as progressive and PNGs as interlaced. Slightly larger files
    | that render progressively while downloading.
    |
    */

    'interlace' => true,

    /*
    |--------------------------------------------------------------------------
    | Linear Light Processing
    |--------------------------------------------------------------------------
    |
    | Process resizes in linear light space. Produces more accurate results
    | (especially when downsizing high-contrast imagery) at the cost of speed.
    |
    */

    'linear' => false,

    /*
    |--------------------------------------------------------------------------
    | Smart Crop
    |--------------------------------------------------------------------------
    |
    | When a "fit=crop" / "fit=cover" request has no explicit focal point or
    | position, use libvips' attention-based smart cropping to keep the most
    | interesting part of the image. When false, falls back to a centre crop.
    |
    */

    'smart_crop' => false,

    /*
    |--------------------------------------------------------------------------
    | Per-format Encoder Options
    |--------------------------------------------------------------------------
    |
    | Extra options passed to the underlying libvips savers. These are merged
    | on top of the quality/strip/interlace settings above. See the libvips
    | documentation for the full list of options per saver.
    |
    */

    'encoders' => [
        'jpeg' => ['optimize_coding' => true, 'trellis_quant' => true],
        'png' => ['compression' => 6, 'palette' => false],
        'webp' => ['effort' => 4],
        'avif' => ['effort' => 4],
        'gif' => [],
    ],

];
