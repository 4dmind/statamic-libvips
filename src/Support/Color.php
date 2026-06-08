<?php

namespace Fdmind\StatamicLibvips\Support;

/**
 * Minimal colour parser for Glide's "bg" / border colour values.
 *
 * Accepts the same inputs Glide's own Color helper supports: 3/4/6/8 digit
 * hex strings and a handful of named colours.
 */
class Color
{
    public function __construct(
        public int $r = 0,
        public int $g = 0,
        public int $b = 0,
        public int $a = 255,
    ) {}

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        $named = [
            'black' => '000000', 'white' => 'ffffff', 'red' => 'ff0000',
            'green' => '008000', 'blue' => '0000ff', 'yellow' => 'ffff00',
            'transparent' => '00000000',
        ];
        $value = $named[$value] ?? $value;

        $value = ltrim($value, '#');

        // Expand shorthand (rgb / rgba) to full length.
        if (in_array(strlen($value), [3, 4], true)) {
            $value = implode('', array_map(fn ($c) => $c.$c, str_split($value)));
        }

        if (strlen($value) === 8) {
            return new self(
                hexdec(substr($value, 0, 2)),
                hexdec(substr($value, 2, 2)),
                hexdec(substr($value, 4, 2)),
                hexdec(substr($value, 6, 2)),
            );
        }

        if (strlen($value) === 6) {
            return new self(
                hexdec(substr($value, 0, 2)),
                hexdec(substr($value, 2, 2)),
                hexdec(substr($value, 4, 2)),
            );
        }

        // Fallback: opaque black.
        return new self;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    public function toRgb(): array
    {
        return [$this->r, $this->g, $this->b];
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}
     */
    public function toRgba(): array
    {
        return [$this->r, $this->g, $this->b, $this->a];
    }
}
