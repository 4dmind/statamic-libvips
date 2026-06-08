<?php

namespace Fdmind\StatamicLibvips\Tests;

use Fdmind\StatamicLibvips\Glide\VipsApi;
use Jcupitt\Vips\Image as VipsImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class VipsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(VipsImage::class)) {
            $this->markTestSkipped('php-vips is not installed.');
        }
    }

    protected function api(array $config = []): VipsApi
    {
        return new VipsApi(array_merge(['quality' => 85, 'strip' => true], $config));
    }

    /** A 600x400 sRGB test image with three colour bands. */
    protected function jpeg(int $w = 600, int $h = 400): string
    {
        return $this->fixture($w, $h, 3, '.jpg');
    }

    /** A 600x400 RGBA test image. */
    protected function png(int $w = 600, int $h = 400): string
    {
        return $this->fixture($w, $h, 4, '.png');
    }

    protected function fixture(int $w, int $h, int $bands, string $suffix): string
    {
        $image = VipsImage::black($w, $h)->add(array_slice([90, 140, 200, 255], 0, $bands))
            ->cast('uchar')
            ->copy(['interpretation' => $bands >= 3 ? 'srgb' : 'b-w']);

        return $image->writeToBuffer($suffix);
    }

    protected function read(string $buffer): VipsImage
    {
        return VipsImage::newFromBuffer($buffer);
    }

    #[Test]
    public function it_returns_the_full_set_of_glide_params(): void
    {
        $params = $this->api()->getApiParams();

        foreach (['w', 'h', 'fit', 'dpr', 'q', 'fm', 'crop', 'or', 'bri', 'con', 'gam', 'sharp', 'blur', 'pixel', 'filt', 'flip', 'bg', 'border'] as $p) {
            $this->assertContains($p, $params);
        }
    }

    #[Test]
    public function it_resizes_proportionally_with_only_a_width(): void
    {
        $out = $this->read($this->api()->run($this->jpeg(), ['w' => 300]));

        $this->assertSame(300, $out->width);
        $this->assertSame(200, $out->height);
    }

    #[Test]
    public function it_resizes_proportionally_with_only_a_height(): void
    {
        $out = $this->read($this->api()->run($this->jpeg(), ['h' => 200]));

        $this->assertSame(300, $out->width);
        $this->assertSame(200, $out->height);
    }

    #[Test]
    #[DataProvider('fitProvider')]
    public function it_honours_fit_modes(string $fit, int $expectedW, int $expectedH): void
    {
        $out = $this->read($this->api()->run($this->jpeg(), ['w' => 300, 'h' => 300, 'fit' => $fit]));

        $this->assertSame($expectedW, $out->width, "width for fit=$fit");
        $this->assertSame($expectedH, $out->height, "height for fit=$fit");
    }

    public static function fitProvider(): array
    {
        return [
            // source is 600x400 (3:2)
            'contain' => ['contain', 300, 200],
            'max' => ['max', 300, 200],
            'stretch' => ['stretch', 300, 300],
            'fill' => ['fill', 300, 300],
            'crop centre' => ['crop-center', 300, 300],
            'cover' => ['cover', 300, 300],
        ];
    }

    #[Test]
    public function it_does_not_upscale_with_fit_max(): void
    {
        $out = $this->read($this->api()->run($this->jpeg(200, 200), ['w' => 800, 'h' => 800, 'fit' => 'max']));

        $this->assertSame(200, $out->width);
        $this->assertSame(200, $out->height);
    }

    #[Test]
    public function it_applies_the_device_pixel_ratio(): void
    {
        $out = $this->read($this->api()->run($this->jpeg(), ['w' => 300, 'dpr' => 2]));

        $this->assertSame(600, $out->width);
    }

    #[Test]
    public function it_crops_to_explicit_coordinates(): void
    {
        $out = $this->read($this->api()->run($this->jpeg(), ['crop' => '200,150,10,20']));

        $this->assertSame(200, $out->width);
        $this->assertSame(150, $out->height);
    }

    #[Test]
    #[DataProvider('formatProvider')]
    public function it_converts_formats(string $fm, string $loaderNeedle): void
    {
        $out = $this->api()->run($this->jpeg(), ['w' => 100, 'fm' => $fm]);

        $this->assertStringContainsStringIgnoringCase($loaderNeedle, (string) VipsImage::findLoadBuffer($out));
    }

    public static function formatProvider(): array
    {
        return [
            'png' => ['png', 'png'],
            'webp' => ['webp', 'webp'],
            'gif' => ['gif', 'gif'],
            'jpg' => ['jpg', 'jpeg'],
        ];
    }

    #[Test]
    public function it_respects_quality_for_lossy_formats(): void
    {
        // A flat colour compresses to almost nothing regardless of quality, so
        // use a noisy source where quality measurably changes the file size.
        $noise = VipsImage::gaussnoise(400, 300)->bandjoin([
            VipsImage::gaussnoise(400, 300),
            VipsImage::gaussnoise(400, 300),
        ])->cast('uchar')->copy(['interpretation' => 'srgb'])->writeToBuffer('.png');

        $low = $this->api()->run($noise, ['fm' => 'webp', 'q' => 20]);
        $high = $this->api()->run($noise, ['fm' => 'webp', 'q' => 95]);

        $this->assertLessThan(strlen($high), strlen($low));
    }

    #[Test]
    public function it_preserves_alpha_through_resize_and_effects(): void
    {
        $out = $this->read($this->api()->run($this->png(), ['w' => 150, 'filt' => 'greyscale', 'fm' => 'png']));

        $this->assertTrue($out->hasAlpha());
    }

    #[Test]
    public function it_flattens_alpha_when_encoding_jpeg(): void
    {
        $out = $this->read($this->api()->run($this->png(), ['w' => 150, 'fm' => 'jpg']));

        $this->assertFalse($out->hasAlpha());
    }

    #[Test]
    public function it_brightens_and_darkens(): void
    {
        $base = $this->read($this->jpeg())->avg();
        $brighter = $this->read($this->api()->run($this->jpeg(), ['bri' => 40]))->avg();
        $darker = $this->read($this->api()->run($this->jpeg(), ['bri' => -40]))->avg();

        $this->assertGreaterThan($base, $brighter);
        $this->assertLessThan($base, $darker);
    }

    #[Test]
    public function it_converts_to_greyscale_visually(): void
    {
        // A greyscale image has equal-ish R, G, B per pixel; check band stats converge.
        $out = $this->read($this->api()->run($this->jpeg(), ['filt' => 'greyscale']));
        $stats = $out->stats();

        // stats() row 1..n are per-band; mean is column 4 (index 4).
        $meanR = $stats->getpoint(4, 1)[0];
        $meanG = $stats->getpoint(4, 2)[0];
        $meanB = $stats->getpoint(4, 3)[0];

        $this->assertEqualsWithDelta($meanR, $meanG, 1.0);
        $this->assertEqualsWithDelta($meanG, $meanB, 1.0);
    }

    #[Test]
    public function it_flips_and_flops(): void
    {
        $out = $this->read($this->api()->run($this->jpeg(), ['w' => 100, 'flip' => 'both']));

        $this->assertSame(100, $out->width);
    }

    #[Test]
    public function it_adds_an_expanding_border(): void
    {
        $out = $this->read($this->api()->run($this->jpeg(), ['w' => 100, 'border' => '10,ff0000,expand']));

        $this->assertSame(120, $out->width);
    }

    #[Test]
    public function it_passes_through_unmodified_when_no_params(): void
    {
        $out = $this->read($this->api()->run($this->jpeg(), []));

        $this->assertSame(600, $out->width);
        $this->assertSame(400, $out->height);
    }

    #[Test]
    public function it_uses_smart_cropping_when_enabled(): void
    {
        $out = $this->read($this->api(['smart_crop' => true])->run($this->jpeg(), ['w' => 200, 'h' => 200, 'fit' => 'crop']));

        $this->assertSame(200, $out->width);
        $this->assertSame(200, $out->height);
    }
}
