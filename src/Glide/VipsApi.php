<?php

namespace Fdmind\StatamicLibvips\Glide;

use Fdmind\StatamicLibvips\Exceptions\VipsException;
use Fdmind\StatamicLibvips\Support\Color;
use Jcupitt\Vips\Config as VipsConfig;
use Jcupitt\Vips\Image as VipsImage;
use League\Glide\Api\ApiInterface;
use Throwable;

/**
 * A libvips powered implementation of Glide's manipulation API.
 *
 * Glide's default API hands a binary source to Intervention Image, runs a
 * chain of manipulators against it and encodes the result. libvips can't be
 * plugged into Intervention as a driver, so instead this class interprets the
 * same Glide parameters directly against libvips' (much faster) pipeline.
 *
 * The parameter semantics intentionally mirror League\Glide\Manipulators\* so
 * that swapping the driver produces visually equivalent output.
 */
class VipsApi implements ApiInterface
{
    public function __construct(protected array $config = []) {}

    /**
     * The full set of query parameters Glide should treat as manipulation
     * params. Mirrors the union of every manipulator's getApiParams().
     */
    public function getApiParams(): array
    {
        return [
            // Globals
            'p', 'q', 'fm', 's',
            // Size
            'w', 'h', 'fit', 'dpr',
            // Orientation / crop
            'or', 'crop',
            // Adjustments
            'bri', 'con', 'gam', 'sharp', 'blur', 'pixel', 'filt', 'flip',
            // Background / border
            'bg', 'border',
            // Watermark
            'mark', 'markw', 'markh', 'markx', 'marky', 'markpad', 'markfit', 'markpos', 'markalpha',
        ];
    }

    /**
     * Perform the manipulations and return the encoded image binary.
     *
     * The $source parameter is intentionally left untyped: Glide 2.x
     * (Statamic 5) declares ApiInterface::run($source, array $params) with
     * no parameter type, while Glide 3.x (Statamic 6) declares
     * run(string $source, array $params): string. An untyped parameter is
     * compatible with both (it widens 3.x's `string`), and the `: string`
     * return type is valid against 2.x (which has none) and matches 3.x.
     *
     * @param  string  $source  Source image binary data.
     */
    public function run($source, array $params): string
    {
        $this->ensureVipsAvailable();

        try {
            return $this->process($source, $params);
        } catch (VipsException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new VipsException('libvips failed to process the image: '.$e->getMessage(), previous: $e);
        }
    }

    protected function process(string $source, array $params): string
    {
        $format = $this->resolveFormat($source, $params);

        // 1. Orientation + 2. manual crop + 3. size, in Glide's manipulator order.
        $image = $this->applyGeometry($source, $params);

        // 4-11. Adjustments and effects.
        $image = $this->applyEffects($image, $params);

        // 12. Watermark, 13. background, 14. border.
        $image = $this->applyOverlays($image, $params);

        return $this->encode($image, $format, $params);
    }

    /* -----------------------------------------------------------------
     | Geometry: orientation, crop coordinates, resize / fit
     | ----------------------------------------------------------------- */

    protected function applyGeometry(string $source, array $params): VipsImage
    {
        $orientation = $this->getOrientation($params);
        $cropCoords = $this->getCropCoordinates($params);
        [$width, $height, $fit, $dpr] = $this->resolveSizeParams($params);

        $autoRotate = $orientation === 'auto';

        // Explicit-degree rotation or a manual crop must happen before sizing
        // (Glide runs Orientation -> Crop -> Size), which means we can't lean on
        // thumbnail's shrink-on-load and instead decode fully first.
        if (! $autoRotate || $cropCoords) {
            $image = VipsImage::newFromBuffer($source);

            if ($autoRotate) {
                $image = $image->autorot();
            } else {
                $image = $image->autorot(); // normalise to remove any tag...
                if ($orientation !== '0') {
                    $image = $image->rotate((float) $orientation);
                }
            }

            if ($cropCoords) {
                $image = $this->cropCoordinates($image, $cropCoords);
            }

            return $this->resizeDecoded($image, $width, $height, $fit, $params);
        }

        // Fast path: orientation is automatic and there's no manual crop, so we
        // can use thumbnail_buffer with shrink-on-load straight from the source.
        if ($width === null && $height === null) {
            return VipsImage::newFromBuffer($source)->autorot();
        }

        return $this->thumbnail($source, $width, $height, $fit, $params);
    }

    /**
     * Resize an already-decoded image (no shrink-on-load available).
     */
    protected function resizeDecoded(VipsImage $image, ?int $width, ?int $height, string $fit, array $params): VipsImage
    {
        if ($width === null && $height === null) {
            return $image;
        }

        [$width, $height] = $this->resolveMissingDimensions($image->width, $image->height, $width, $height);

        if (in_array($fit, ['crop', 'cover'], true)) {
            return $this->coverCrop($image, $width, $height, $this->cropFocus($fit, $params));
        }

        $scale = match ($fit) {
            'stretch' => 'force',
            'max', 'fill-max' => 'down',
            default => 'both',
        };

        $resized = $image->thumbnail_image($width, [
            'height' => $height ?: 100000000,
            'size' => $scale,
            'linear' => (bool) ($this->config['linear'] ?? false),
        ]);

        if (in_array($fit, ['fill', 'fill-max'], true)) {
            $resized = $this->pad($resized, $width, $height, $params);
        }

        return $resized;
    }

    /**
     * Fast resize directly from the encoded buffer using shrink-on-load.
     */
    protected function thumbnail(string $source, ?int $width, ?int $height, string $fit, array $params): VipsImage
    {
        // Cover / crop need oriented source dimensions to compute the cover box.
        if (in_array($fit, ['crop', 'cover'], true)) {
            [$iw, $ih] = $this->orientedSize($source);
            [$width, $height] = $this->resolveMissingDimensions($iw, $ih, $width, $height);
            [$cw, $ch] = $this->coverBox($iw, $ih, $width, $height, $this->cropFocus($fit, $params)['zoom']);

            $image = VipsImage::thumbnail_buffer($source, max(1, $cw), [
                'height' => max(1, $ch),
                'size' => 'both',
                'linear' => (bool) ($this->config['linear'] ?? false),
            ]);

            return $this->cropToFocus($image, $width, $height, $this->cropFocus($fit, $params));
        }

        $scale = match ($fit) {
            'stretch' => 'force',
            'max', 'fill-max' => 'down',
            default => 'both',
        };

        // thumbnail requires a width; when only a height is given use a huge
        // width and let the height constrain the result.
        $targetWidth = $width ?? 100000000;

        $options = [
            'size' => $scale,
            'linear' => (bool) ($this->config['linear'] ?? false),
        ];

        if ($height !== null) {
            $options['height'] = $height;
        }

        $image = VipsImage::thumbnail_buffer($source, max(1, $targetWidth), $options);

        if (in_array($fit, ['fill', 'fill-max'], true) && $width !== null && $height !== null) {
            $image = $this->pad($image, $width, $height, $params);
        }

        return $image;
    }

    /* -----------------------------------------------------------------
     | Crop helpers
     | ----------------------------------------------------------------- */

    /**
     * Cover/crop on an already-decoded image.
     */
    protected function coverCrop(VipsImage $image, int $width, int $height, array $focus): VipsImage
    {
        [$cw, $ch] = $this->coverBox($image->width, $image->height, $width, $height, $focus['zoom']);

        $resized = $image->thumbnail_image($cw, [
            'height' => $ch,
            'size' => 'force',
            'linear' => (bool) ($this->config['linear'] ?? false),
        ]);

        return $this->cropToFocus($resized, $width, $height, $focus);
    }

    /**
     * Compute the "cover" resize box: smallest box >= target keeping aspect.
     * Mirrors Glide Size::resolveCropResizeDimensions() including zoom.
     *
     * @return array{0:int,1:int}
     */
    protected function coverBox(int $iw, int $ih, int $width, int $height, float $zoom): array
    {
        if ($height > $width * ($ih / $iw)) {
            $cw = $height * ($iw / $ih);
            $ch = $height;
        } else {
            $cw = $width;
            $ch = $width * ($ih / $iw);
        }

        return [
            (int) round($cw * $zoom),
            (int) round($ch * $zoom),
        ];
    }

    /**
     * Extract a width x height window from an already cover-sized image,
     * offset towards the focal point. Mirrors Glide Size::resolveCropOffset().
     */
    protected function cropToFocus(VipsImage $image, int $width, int $height, array $focus): VipsImage
    {
        $iw = $image->width;
        $ih = $image->height;

        $width = min($width, $iw);
        $height = min($height, $ih);

        if ($focus['attention']) {
            // smartcrop scans the whole image, so it needs random access. A
            // thumbnail_buffer result is a lazy sequential pipeline; materialise
            // it first to avoid "out of order read" on the underlying decoder.
            return $image->copyMemory()->smartcrop($width, $height, ['interesting' => 'attention']);
        }

        $offsetX = (int) (($iw * $focus['x'] / 100) - ($width / 2));
        $offsetY = (int) (($ih * $focus['y'] / 100) - ($height / 2));

        $offsetX = max(0, min($offsetX, $iw - $width));
        $offsetY = max(0, min($offsetY, $ih - $height));

        return $image->crop($offsetX, $offsetY, $width, $height);
    }

    /**
     * Manual crop from explicit "crop=w,h,x,y" coordinates.
     */
    protected function cropCoordinates(VipsImage $image, array $c): VipsImage
    {
        [$w, $h, $x, $y] = $c;

        // Mirror Glide's boundary clamping.
        if ($x > ($image->width - $w)) {
            $x = $image->width - $w;
        }
        if ($y > ($image->height - $h)) {
            $y = $image->height - $h;
        }

        $w = min($w, $image->width - $x);
        $h = min($h, $image->height - $y);

        return $image->crop(max(0, $x), max(0, $y), max(1, $w), max(1, $h));
    }

    /**
     * Resolve the focal point / smart-crop intent for a cover|crop fit.
     *
     * @return array{x:float,y:float,zoom:float,attention:bool}
     */
    protected function cropFocus(string $fit, array $params): array
    {
        $rawFit = (string) ($params['fit'] ?? '');

        // Numeric focal point: crop-25-75 or crop-25-75-2 (with zoom).
        if (preg_match('/^crop-(\d{1,3})-(\d{1,3})(?:-(\d{1,3}(?:\.\d+)?))?$/', $rawFit, $m)) {
            $zoom = (float) ($m[3] ?? 1);
            if ($m[1] > 100 || $m[2] > 100 || $zoom > 100) {
                return ['x' => 50, 'y' => 50, 'zoom' => 1.0, 'attention' => false];
            }

            return ['x' => (float) $m[1], 'y' => (float) $m[2], 'zoom' => $zoom, 'attention' => false];
        }

        // Named position: cover-top-left, crop-bottom, etc.
        $position = trim(str_replace(['crop-', 'cover-', 'crop', 'cover'], '', $rawFit), '-');

        $map = [
            'top-left' => [0, 0], 'top' => [50, 0], 'top-right' => [100, 0],
            'left' => [0, 50], 'center' => [50, 50], 'right' => [100, 50],
            'bottom-left' => [0, 100], 'bottom' => [50, 100], 'bottom-right' => [100, 100],
        ];

        if ($position === '' && ($this->config['smart_crop'] ?? false)) {
            return ['x' => 50, 'y' => 50, 'zoom' => 1.0, 'attention' => true];
        }

        [$x, $y] = $map[$position] ?? [50, 50];

        return ['x' => (float) $x, 'y' => (float) $y, 'zoom' => 1.0, 'attention' => false];
    }

    /* -----------------------------------------------------------------
     | Fill padding & overlays
     | ----------------------------------------------------------------- */

    /**
     * Centre the image on a width x height canvas (fit=fill / fill-max).
     */
    protected function pad(VipsImage $image, int $width, int $height, array $params): VipsImage
    {
        $bg = (string) ($params['bg'] ?? '');
        $x = (int) (($width - $image->width) / 2);
        $y = (int) (($height - $image->height) / 2);

        if ($bg !== '') {
            $image = $this->ensureNoAlpha($image);

            return $image->embed($x, $y, $width, $height, [
                'extend' => 'background',
                'background' => Color::fromString($bg)->toRgb(),
            ]);
        }

        // Transparent padding: ensure an alpha channel exists.
        $image = $this->ensureAlpha($image);

        return $image->embed($x, $y, $width, $height, [
            'extend' => 'background',
            'background' => [0, 0, 0, 0],
        ]);
    }

    protected function applyOverlays(VipsImage $image, array $params): VipsImage
    {
        $image = $this->applyWatermark($image, $params);

        // Background: flatten transparency onto a solid colour.
        if (($bg = (string) ($params['bg'] ?? '')) !== '' && $image->hasAlpha()) {
            $image = $image->flatten(['background' => Color::fromString($bg)->toRgb()]);
        }

        $image = $this->applyBorder($image, $params);

        return $image;
    }

    protected function applyWatermark(VipsImage $image, array $params): VipsImage
    {
        $mark = (string) ($params['mark'] ?? '');
        if ($mark === '') {
            return $image;
        }

        $path = $this->watermarkPath($mark);
        if (! $path || ! is_file($path)) {
            return $image;
        }

        try {
            $watermark = VipsImage::newFromFile($path, ['access' => 'sequential']);
        } catch (Throwable) {
            return $image;
        }

        $dpr = $this->getDpr($params);

        // Size the watermark relative to the base image where requested.
        $markW = $this->dimension($image->width, $params['markw'] ?? null, $dpr);
        $markH = $this->dimension($image->height, $params['marky'] ?? null, $dpr);
        if ($markW || $markH) {
            $watermark = $watermark->thumbnail_image($markW ?: $watermark->width, [
                'height' => $markH ?: 100000000,
                'size' => 'both',
            ]);
        }

        $watermark = $this->ensureAlpha($watermark);

        if (($alpha = $this->getMarkAlpha($params)) < 100) {
            $bands = $watermark->bandsplit();
            $last = count($bands) - 1;
            $bands[$last] = $bands[$last]->multiply($alpha / 100);
            $watermark = VipsImage::bandjoin($bands);
        }

        $pad = (int) round($this->dimension($image->width, $params['markpad'] ?? null, $dpr) ?: 0);
        [$x, $y] = $this->watermarkPosition(
            (string) ($params['markpos'] ?? 'center'),
            $image->width,
            $image->height,
            $watermark->width,
            $watermark->height,
            $pad,
            $params,
            $dpr,
        );

        $image = $this->ensureAlpha($image);

        return $image->composite2($watermark, 'over', ['x' => $x, 'y' => $y]);
    }

    protected function watermarkPosition(string $pos, int $bw, int $bh, int $ww, int $wh, int $pad, array $params, float $dpr): array
    {
        // Explicit offsets win.
        $explicitX = isset($params['markx']) ? $this->dimension($bw, $params['markx'], $dpr) : null;
        $explicitY = isset($params['marky']) ? $this->dimension($bh, $params['marky'], $dpr) : null;

        $map = [
            'top-left' => [0, 0], 'top' => [($bw - $ww) / 2, 0], 'top-right' => [$bw - $ww, 0],
            'left' => [0, ($bh - $wh) / 2], 'center' => [($bw - $ww) / 2, ($bh - $wh) / 2], 'right' => [$bw - $ww, ($bh - $wh) / 2],
            'bottom-left' => [0, $bh - $wh], 'bottom' => [($bw - $ww) / 2, $bh - $wh], 'bottom-right' => [$bw - $ww, $bh - $wh],
        ];

        [$x, $y] = $map[$pos] ?? $map['center'];

        // Apply padding inset from edges.
        if (str_contains($pos, 'left')) {
            $x += $pad;
        } elseif (str_contains($pos, 'right')) {
            $x -= $pad;
        }
        if (str_contains($pos, 'top')) {
            $y += $pad;
        } elseif (str_contains($pos, 'bottom')) {
            $y -= $pad;
        }

        return [
            (int) round($explicitX ?? $x),
            (int) round($explicitY ?? $y),
        ];
    }

    protected function applyBorder(VipsImage $image, array $params): VipsImage
    {
        $border = (string) ($params['border'] ?? '');
        if ($border === '') {
            return $image;
        }

        $values = explode(',', $border);
        $dpr = $this->getDpr($params);
        $width = $this->dimension($image->width, $values[0] ?? null, $dpr);
        if (! $width) {
            return $image;
        }
        $width = (int) round($width);
        $color = Color::fromString($values[1] ?? 'ffffff');
        $method = match ($values[2] ?? 'overlay') {
            'expand' => 'expand',
            'shrink' => 'shrink',
            default => 'overlay',
        };

        if ($method === 'expand') {
            return $this->ensureNoAlpha($image)->embed($width, $width, $image->width + $width * 2, $image->height + $width * 2, [
                'extend' => 'background',
                'background' => $color->toRgb(),
            ]);
        }

        if ($method === 'shrink') {
            $inner = $this->ensureNoAlpha($image)->thumbnail_image(max(1, $image->width - $width * 2), [
                'height' => max(1, $image->height - $width * 2),
                'size' => 'force',
            ]);

            return $inner->embed($width, $width, $image->width, $image->height, [
                'extend' => 'background',
                'background' => $color->toRgb(),
            ]);
        }

        // Overlay: draw a frame inside the existing canvas.
        $image = $image->copyMemory();
        $rgb = $color->toRgb();
        if ($image->bands === 4) {
            $rgb[] = 255;
        }
        $image->draw_rect($rgb, 0, 0, $image->width, $image->height, ['fill' => false]);
        for ($i = 1; $i < $width; $i++) {
            $image->draw_rect($rgb, $i, $i, $image->width - $i * 2, $image->height - $i * 2, ['fill' => false]);
        }

        return $image;
    }

    /* -----------------------------------------------------------------
     | Colour adjustments & effects
     | ----------------------------------------------------------------- */

    protected function applyEffects(VipsImage $image, array $params): VipsImage
    {
        if (($bri = $this->intParam($params, 'bri', -100, 100)) !== null) {
            $image = $this->brightness($image, $bri);
        }

        if (($con = $this->intParam($params, 'con', -100, 100)) !== null) {
            $image = $this->contrast($image, $con);
        }

        if (($gam = $this->floatParam($params, 'gam', 0.1, 9.99)) !== null) {
            $image = $this->gamma($image, $gam);
        }

        if (($sharp = $this->intParam($params, 'sharp', 0, 100)) !== null && $sharp > 0) {
            $image = $this->sharpen($image, $sharp);
        }

        $filt = $params['filt'] ?? null;
        if ($filt === 'greyscale') {
            $image = $this->greyscale($image);
        } elseif ($filt === 'sepia') {
            $image = $this->sepia($image);
        }

        if (($flip = $params['flip'] ?? null) && in_array($flip, ['h', 'v', 'both'], true)) {
            if ($flip === 'h' || $flip === 'both') {
                $image = $image->flip('horizontal');
            }
            if ($flip === 'v' || $flip === 'both') {
                $image = $image->flip('vertical');
            }
        }

        if (($blur = $this->intParam($params, 'blur', 0, 100)) !== null && $blur > 0) {
            $image = $image->gaussblur($blur * 0.3);
        }

        if (($pixel = $this->intParam($params, 'pixel', 0, 1000)) !== null && $pixel > 1) {
            $image = $this->pixelate($image, $pixel);
        }

        return $image;
    }

    protected function brightness(VipsImage $image, int $level): VipsImage
    {
        // -100..100 -> additive offset in 8-bit space (Glide/Intervention semantics).
        $offset = $level / 100 * 255;

        return $this->overColour($image, fn ($c) => $c->linear([1, 1, 1], [$offset, $offset, $offset]));
    }

    protected function contrast(VipsImage $image, int $level): VipsImage
    {
        // -100..100 -> multiplicative factor pivoting around mid-grey (128).
        $factor = (259 * ($level + 255)) / (255 * (259 - $level));
        $offset = 128 - $factor * 128;

        return $this->overColour($image, fn ($c) => $c->linear([$factor, $factor, $factor], [$offset, $offset, $offset]));
    }

    protected function gamma(VipsImage $image, float $gamma): VipsImage
    {
        return $this->overColour($image, fn ($c) => $c->gamma(['exponent' => 1.0 / $gamma]));
    }

    protected function sharpen(VipsImage $image, int $level): VipsImage
    {
        // Scale the unsharp-mask strength by the requested 0..100 level.
        return $image->sharpen([
            'sigma' => 0.5,
            'x1' => 2,
            'm1' => 0,
            'm2' => 3 * ($level / 100) + 0.5,
        ]);
    }

    protected function greyscale(VipsImage $image): VipsImage
    {
        $hadAlpha = $image->hasAlpha();
        $alpha = $hadAlpha ? $image->extract_band($image->bands - 1) : null;

        $grey = $image->colourspace('b-w')->colourspace('srgb');

        return $alpha ? $grey->bandjoin($alpha) : $grey;
    }

    protected function sepia(VipsImage $image): VipsImage
    {
        $grey = $this->greyscale($image);

        return $this->overColour($grey, function ($c) {
            // Classic sepia tone via per-channel scaling of the grey value.
            return $c->multiply([1.07, 0.74, 0.43])->cast('uchar');
        });
    }

    protected function pixelate(VipsImage $image, int $size): VipsImage
    {
        $w = $image->width;
        $h = $image->height;

        $small = $image->resize(1 / $size, ['kernel' => 'nearest']);

        return $small->resize($size, ['kernel' => 'nearest'])->crop(0, 0, $w, $h);
    }

    /* -----------------------------------------------------------------
     | Encoding
     | ----------------------------------------------------------------- */

    protected function encode(VipsImage $image, string $format, array $params): string
    {
        $quality = $this->quality($params);
        $strip = (bool) ($this->config['strip'] ?? true);
        $interlace = (bool) ($this->config['interlace'] ?? true);
        $encoders = $this->config['encoders'] ?? [];

        [$suffix, $options] = match ($format) {
            'png' => ['.png', array_merge(['interlace' => $interlace], $encoders['png'] ?? [])],
            'gif' => ['.gif', $encoders['gif'] ?? []],
            'webp' => ['.webp', array_merge(['Q' => $quality], $encoders['webp'] ?? [])],
            'avif' => ['.avif', array_merge(['Q' => $quality], $encoders['avif'] ?? [])],
            'tiff' => ['.tif', []],
            'bmp' => ['.bmp', []],
            default => ['.jpg', array_merge(['Q' => $quality, 'interlace' => $interlace], $encoders['jpeg'] ?? [])],
        };

        // JPEG/BMP can't carry an alpha channel; flatten onto white.
        if (in_array($format, ['jpg', 'jpeg', 'bmp', '', 'pjpg'], true) && $image->hasAlpha()) {
            $image = $image->flatten(['background' => [255, 255, 255]]);
        }

        $options['strip'] = $strip;

        return $image->writeToBuffer($suffix, $options);
    }

    protected function resolveFormat(string $source, array $params): string
    {
        $fm = strtolower((string) ($params['fm'] ?? ''));

        $map = [
            'pjpg' => 'jpg', 'jpg' => 'jpg', 'jpeg' => 'jpg',
            'png' => 'png', 'gif' => 'gif', 'webp' => 'webp',
            'avif' => 'avif', 'tiff' => 'tiff', 'bmp' => 'bmp',
        ];

        if (isset($map[$fm])) {
            return $map[$fm];
        }

        // Fall back to the source format.
        try {
            $loader = VipsImage::findLoadBuffer($source);
        } catch (Throwable) {
            return 'jpg';
        }

        return match (true) {
            str_contains((string) $loader, 'png') => 'png',
            str_contains((string) $loader, 'gif') => 'gif',
            str_contains((string) $loader, 'webp') => 'webp',
            str_contains((string) $loader, 'heif'), str_contains((string) $loader, 'avif') => 'avif',
            str_contains((string) $loader, 'tiff') => 'tiff',
            default => 'jpg',
        };
    }

    /* -----------------------------------------------------------------
     | Parameter parsing helpers (mirroring Glide manipulators)
     | ----------------------------------------------------------------- */

    /**
     * @return array{0:?int,1:?int,2:string,3:float}
     */
    protected function resolveSizeParams(array $params): array
    {
        $w = (int) ($params['w'] ?? 0);
        $h = (int) ($params['h'] ?? 0);
        $width = $w <= 0 ? null : $w;
        $height = $h <= 0 ? null : $h;
        $dpr = $this->getDpr($params);

        if ($width !== null) {
            $width = (int) round($width * $dpr);
        }
        if ($height !== null) {
            $height = (int) round($height * $dpr);
        }

        return [$width, $height, $this->getFit($params), $dpr];
    }

    protected function getFit(array $params): string
    {
        $fit = (string) ($params['fit'] ?? '');

        if (in_array($fit, ['contain', 'fill', 'max', 'stretch', 'fill-max', 'cover'], true)) {
            return $fit;
        }

        if (preg_match('/^(crop|cover)(-top-left|-top|-top-right|-left|-center|-right|-bottom-left|-bottom|-bottom-right?)*$/', $fit)) {
            return 'cover';
        }

        if (preg_match('/^(crop)(-\d{1,3}-\d{1,3}(?:-\d{1,3}(?:\.\d+)?)?)*$/', $fit)) {
            return 'crop';
        }

        return 'contain';
    }

    protected function getDpr(array $params): float
    {
        $dpr = $params['dpr'] ?? null;
        if (! is_numeric($dpr) || $dpr < 0 || $dpr > 8) {
            return 1.0;
        }

        return (float) $dpr;
    }

    protected function getOrientation(array $params): string
    {
        $or = (string) ($params['or'] ?? '');

        return in_array($or, ['0', '90', '180', '270'], true) ? $or : 'auto';
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}|null [width, height, x, y]
     */
    protected function getCropCoordinates(array $params): ?array
    {
        $crop = (string) ($params['crop'] ?? '');
        if ($crop === '') {
            return null;
        }

        $c = explode(',', $crop);
        if (count($c) !== 4 || ! array_product(array_map('is_numeric', $c))) {
            return null;
        }
        if ($c[0] <= 0 || $c[1] <= 0 || $c[2] < 0 || $c[3] < 0) {
            return null;
        }

        return [(int) $c[0], (int) $c[1], (int) $c[2], (int) $c[3]];
    }

    /**
     * @return array{0:int,1:int}
     */
    protected function resolveMissingDimensions(int $iw, int $ih, ?int $width, ?int $height): array
    {
        if ($width === null && $height === null) {
            return [$iw, $ih];
        }

        if ($width === null) {
            $width = (int) round($height * ($iw / $ih));
        } elseif ($height === null) {
            $height = (int) round($width * ($ih / $iw));
        }

        return [$width, $height];
    }

    protected function quality(array $params): int
    {
        $q = $params['q'] ?? null;
        if (is_numeric($q) && $q >= 0 && $q <= 100) {
            return (int) $q;
        }

        return (int) ($this->config['quality'] ?? 90);
    }

    protected function intParam(array $params, string $key, int $min, int $max): ?int
    {
        $v = $params[$key] ?? null;
        if ($v === null || $v === '' || ! preg_match('/^-?\d+$/', (string) $v)) {
            return null;
        }
        $v = (int) $v;

        return ($v < $min || $v > $max) ? null : $v;
    }

    protected function floatParam(array $params, string $key, float $min, float $max): ?float
    {
        $v = $params[$key] ?? null;
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return null;
        }
        $v = (float) $v;

        return ($v < $min || $v > $max) ? null : $v;
    }

    protected function getMarkAlpha(array $params): int
    {
        $a = $params['markalpha'] ?? null;
        if (! is_numeric($a) || $a < 0 || $a > 100) {
            return 100;
        }

        return (int) $a;
    }

    /**
     * Resolve a Glide dimension value which may be a pixel count or a
     * percentage of a reference dimension. Returns null when unset/invalid.
     */
    protected function dimension(int $reference, $value, float $dpr): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && str_ends_with($value, 'w')) {
            return (float) rtrim($value, 'w') / 100 * $reference;
        }
        if (is_string($value) && str_ends_with($value, 'h')) {
            return (float) rtrim($value, 'h') / 100 * $reference;
        }
        if (is_numeric($value)) {
            return (float) $value * $dpr;
        }

        return null;
    }

    /* -----------------------------------------------------------------
     | libvips utilities
     | ----------------------------------------------------------------- */

    /**
     * Oriented dimensions of an encoded buffer without a full decode.
     *
     * @return array{0:int,1:int}
     */
    protected function orientedSize(string $source): array
    {
        $image = VipsImage::newFromBuffer($source, '', ['access' => 'sequential']);
        $w = $image->width;
        $h = $image->height;

        $orientation = 1;
        if ($image->typeof('orientation') !== 0) {
            $orientation = (int) $image->get('orientation');
        }

        // EXIF orientations 5-8 swap width and height.
        if (in_array($orientation, [5, 6, 7, 8], true)) {
            return [$h, $w];
        }

        return [$w, $h];
    }

    /**
     * Apply a colour-only transform while preserving any alpha channel.
     */
    protected function overColour(VipsImage $image, callable $fn): VipsImage
    {
        if (! $image->hasAlpha()) {
            return $fn($image)->cast('uchar');
        }

        $alpha = $image->extract_band($image->bands - 1);
        $colour = $image->extract_band(0, ['n' => $image->bands - 1]);

        return $fn($colour)->cast('uchar')->bandjoin($alpha);
    }

    protected function ensureAlpha(VipsImage $image): VipsImage
    {
        return $image->hasAlpha() ? $image : $image->bandjoin(255);
    }

    protected function ensureNoAlpha(VipsImage $image): VipsImage
    {
        return $image->hasAlpha() ? $image->flatten() : $image;
    }

    protected function watermarkPath(string $mark): ?string
    {
        $base = function_exists('public_path') ? public_path() : getcwd();

        return rtrim($base, '/').'/'.ltrim($mark, '/');
    }

    protected function ensureVipsAvailable(): void
    {
        if (! class_exists(VipsImage::class)) {
            throw new VipsException(
                'The php-vips library is not installed. Run "composer require jcupitt/vips" '.
                'and ensure libvips is installed on the server.'
            );
        }

        if (! extension_loaded('vips') && ! extension_loaded('ffi')) {
            throw new VipsException(
                'php-vips requires either the "vips" PHP extension or the "ffi" extension to talk to libvips.'
            );
        }

        // Keep memory in check for the long-running queue worker.
        if (class_exists(VipsConfig::class)) {
            VipsConfig::cacheSetMax(0);
        }
    }
}
