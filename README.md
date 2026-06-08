### 🛠️ Built by [4DMIND](mailto:hi@4dmind.com) - Statamic specialists

**Stuck on a hard Statamic build, inheriting a project that's gone off the rails, or fighting slow page loads?**
We specialize in complex Statamic CMS work - performance optimization, image &
asset pipelines, and rescuing stalled or failed projects.
Tell us what's broken: **[hi@4dmind.com](mailto:hi@4dmind.com)**

---

# libvips driver for Statamic Glide

> Use [libvips](https://www.libvips.org/) as the image manipulation driver for
> Statamic's Glide, alongside the built-in GD and Imagick drivers.

libvips is **dramatically faster** and far more **memory-efficient** than GD or Imagick,
especially for large images, thanks to its streaming, shrink-on-load pipeline.
This addon lets you switch Statamic's image processing to libvips by changing a
single config value.

## Requirements

- PHP 8.2+
- Statamic 5 or 6
- [libvips](https://www.libvips.org/install.html) installed on the server
  (8.13+ recommended). **AVIF/HEIC support is optional and needs extra codecs -
  see [AVIF & HEIC support](#avif--heic-support).**
- A way for PHP to talk to libvips - **one** of:
  - the [`vips` PHP extension](https://github.com/libvips/php-vips-ext) (fastest), or
  - the `ffi` extension (bundled with PHP 7.4+), used automatically by `jcupitt/vips`

## How to Install

```bash
composer require fdmind/statamic-libvips
```

Then make sure libvips is available on your server:

```bash
# macOS
brew install vips

# Debian / Ubuntu
apt-get install libvips42
```

> Working with **AVIF or HEIC** images (AVIF as a source *or* as `fm=avif`
> output; HEIC as a source)? Those need extra codec libraries - see
> [AVIF & HEIC support](#avif--heic-support) below.

Verify PHP can reach it:

```bash
php -r "echo Jcupitt\Vips\Config::version();"   # prints e.g. 8.18.2
```

## AVIF & HEIC support

JPEG, PNG, GIF, WebP, TIFF and BMP work with any standard libvips build. **AVIF
and HEIC are different**: libvips handles them through
[libheif](https://github.com/strukturag/libheif), which in turn relies on
separate **AV1 / HEVC codecs**. A libvips that lacks these can't touch AVIF/HEIC
even though the addon accepts them as valid uploads - you'll see errors like
*"unable to read"* on AVIF/HEIC sources, or a save failure when requesting
`fm=avif`.

Crucially, **reading and writing are separate capabilities**:

| You want to… | Needs a codec for… | Library |
|--------------|--------------------|---------|
| **Read** AVIF source images | AV1 **decoding** | `dav1d` (preferred) or `aom` |
| **Output** AVIF (`fm=avif`) | AV1 **encoding** | `aom` (or `rav1e`/`svt-av1`) |
| **Read** HEIC source images | HEVC **decoding** | `libde265` |

> This addon can **output** to AVIF (`fm=avif`) but **not** to HEIC - there is no
> `fm=heic`. HEIC files are supported as *sources* only; when manipulated they're
> re-encoded to another format (AVIF by default, or whatever `fm` you request),
> so reading HEIC also wants an AVIF/WebP/JPEG **encoder** available for the
> output. That's why there's no HEVC-encoder (`x265`) row here.

### Installing the codecs

**macOS (Homebrew)** - `brew install vips` already bundles libheif with AVIF and
HEIC support, so it works out of the box.

**Debian / Ubuntu 24.04+ (libheif 1.17+)** - codecs load as runtime plugins, so
no libvips rebuild is needed. Install only what you use, then **restart PHP-FPM**:

```bash
sudo apt-get update
sudo apt-get install -y \
  libheif-plugin-dav1d \    # read AVIF  (AV1 decode)
  libheif-plugin-aomenc \   # write AVIF (AV1 encode) - only if you output fm=avif
  libheif-plugin-libde265   # read HEIC  (HEVC decode) - only if you have HEIC sources

sudo service php8.4-fpm restart      # match your PHP-FPM service name
```

After installing, clear the Glide cache so any previously-failed renders are
regenerated:

```bash
php artisan statamic:glide:clear
```

> **Older distros** (e.g. Ubuntu 22.04 / libheif 1.12) predate the plugin model,
> and their packaged libheif often can't do AVIF at all. There you'll need a
> newer libheif/libvips from a maintained PPA, a backport, or a custom build.

### Verifying AVIF support

Run this with the **same PHP that serves your site** (i.e. via PHP-FPM's
binary/config - FFI and codec availability can differ between CLI and FPM):

```bash
php -r '
require "vendor/autoload.php";
use Jcupitt\Vips\Image;
// Read test: point at a real .avif file
try { $im = Image::newFromFile("/path/to/image.avif"); echo "AVIF read: OK {$im->width}x{$im->height}\n"; }
catch (\Throwable $e) { echo "AVIF read: FAIL - {$e->getMessage()}\n"; }
// Write test
try { echo "AVIF write: OK (".strlen(Image::black(32,32)->add(128)->cast("uchar")->writeToBuffer(".avif")).") bytes\n"; }
catch (\Throwable $e) { echo "AVIF write: FAIL - {$e->getMessage()}\n"; }
'
```

If a step prints `FAIL`, install the matching codec from the table above and
restart PHP-FPM.

> **No AVIF on the host and can't change it?** Serve **WebP** instead - it's
> supported by every libvips build, compresses nearly as well, and has universal
> browser support. Just use `fm=webp` (or a WebP preset) in place of AVIF.

## How to Use

Set the image manipulation driver to `vips` in `config/statamic/assets.php`:

```php
'image_manipulation' => [
    // ...
    'driver' => 'vips', 
],
```

That's it. Every Glide manipulation - the `{{ glide }}` tag, the `glide`
modifier, presets, Control Panel thumbnails and asset focal-point crops - now
runs through libvips. No template changes are required.

> **Tip:** to switch drivers per-environment, make the value env-driven:
> `'driver' => env('STATAMIC_IMAGE_DRIVER', 'gd')`, then set
> `STATAMIC_IMAGE_DRIVER=vips` in production.

### Configuration (optional)

Publish the config file to tune encoding behaviour:

```bash
php artisan vendor:publish --tag=statamic-libvips-config
```

```php
// config/statamic-libvips.php
return [
    'quality'   => 90,     // default quality for lossy formats when "q" is absent
    'strip'     => true,   // strip EXIF/XMP metadata (ICC is converted to sRGB)
    'interlace' => true,   // progressive JPEG / interlaced PNG
    'linear'    => false,  // resize in linear light (more accurate, slower)
    'smart_crop'=> false,  // attention-based crop when fit=crop has no focal point
    'encoders'  => [ /* per-format libvips saver options */ ],
];
```

## Supported Glide parameters

All standard Glide manipulation parameters are supported:

| Parameter | Notes |
|-----------|-------|
| `w`, `h`, `dpr` | Width / height / device-pixel-ratio |
| `fit` | `contain`, `max`, `fill`, `fill-max`, `stretch`, `crop`, `cover` - including positioned (`crop-top-left`…) and focal/zoom (`crop-25-75-2`) variants |
| `crop` | Explicit `w,h,x,y` coordinate crop |
| `or` | `auto` (EXIF), `0`, `90`, `180`, `270` |
| `fm` | `jpg`, `pjpg`, `png`, `gif`, `webp`, `avif`, `tiff`, `bmp` - `avif` output requires an AV1 encoder ([AVIF & HEIC support](#avif--heic-support)) |
| `q` | Quality for lossy formats |
| `bri`, `con`, `gam` | Brightness / contrast / gamma |
| `sharp`, `blur`, `pixel` | Sharpen / blur / pixelate |
| `filt` | `greyscale`, `sepia` |
| `flip` | `h`, `v`, `both` |
| `bg` | Background colour (flattens transparency / fills padding) |
| `border` | `width,color,method` (`overlay`, `expand`, `shrink`) |
| `mark*` | Watermark (`mark`, `markw`, `markh`, `markx`, `marky`, `markpad`, `markpos`, `markalpha`, `markfit`) |

### Notes & differences

- Output is produced by libvips' own high-quality resamplers, so pixels won't be
  byte-identical to GD/Imagick, but parameter semantics match Glide.
- `bri`/`con`/`gam`/`sharp`/`blur` map libvips operations onto Glide's 0–100
  ranges; results are visually equivalent rather than mathematically identical.
- Setting `smart_crop = true` uses libvips' attention-based cropping for
  `fit=crop`/`fit=cover` requests that don't specify a focal point or position.
- The cache memory of the long-running queue worker is kept in check by
  disabling libvips' operation cache.

## Pre-generating presets in parallel

Statamic ships `php please statamic:assets:generate-presets`, but it processes
images one at a time (or hands them to your queue). This addon adds a command
that saturates every CPU core to warm your entire preset cache as fast as the
server allows - ideal after a bulk import or a deploy.

```bash
php please assets:generate-presets-parallel
```

How it works:

1. It enumerates every image asset × every warm preset (the same set Statamic
   warms on upload, including CP thumbnails) into a **SQLite database** at
   `storage/statamic-libvips/presets.sqlite`.
2. It spawns a pool of worker processes (one per CPU core by default). Each
   worker atomically claims a batch of jobs from the database, generates them,
   and records the result. libvips runs single-threaded *per worker* so N
   workers fill N cores without oversubscription.
3. The database doubles as the progress + orchestration source, so the run is
   **resumable** and **crash-tolerant**: re-running continues where it left off,
   and jobs left in-flight by a crashed worker are automatically requeued.

### Options

| Option | Description |
|--------|-------------|
| `--workers=` | Number of worker processes. Defaults to the CPU core count. |
| `--batch=` | Jobs each worker claims per iteration (default `5`). |
| `--containers=` | Comma-separated container handles to include (default: all). |
| `--excluded-containers=` | Comma-separated container handles to skip. |
| `--presets=` | Comma-separated preset names to include (default: all warm presets). |
| `--fresh` | Discard any existing run and rebuild the work list from scratch. |
| `--retry-failed` | Requeue jobs that failed on a previous run. |
| `--force` | Regenerate even if a cached image already exists. |

```bash
# Warm just two containers with 16 workers
php please assets:generate-presets-parallel --containers=articles,blog --workers=16

# Resume an interrupted run (default behaviour - just run it again)
php please assets:generate-presets-parallel

# Start over from scratch and re-encode everything
php please assets:generate-presets-parallel --fresh --force
```

Already-generated images are skipped automatically (Glide caches the result), so
you usually don't need `--force` - only when source images or preset definitions
have changed.

> Works with any Glide driver, but pairs especially well with the `vips` driver
> for maximum throughput. Requires `pdo_sqlite` (bundled with PHP).

## Testing

```bash
composer test    # or: vendor/bin/phpunit
```

Tests that exercise libvips are skipped automatically when php-vips isn't
installed.

## License

Proprietary - Copyright © 2026 4DMIND. All rights reserved.

You are free to download, install, and use this addon, including in commercial
projects. Redistributing the addon itself (in whole or in part, modified or
unmodified) as a standalone package or offering is not permitted. The software
is provided "as is", without warranty, and 4DMIND accepts no liability for its
use or any malfunction. See [LICENSE](LICENSE) for the full terms.
