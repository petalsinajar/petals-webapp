<?php

namespace App\Modules\Media\Services;

use Intervention\Image\Colors\Rgb\Colorspace as Rgb;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use kornrunner\Blurhash\Blurhash;

/**
 * Original upload → WebP renditions. Decoding applies the EXIF orientation, and every
 * rendition is re-encoded with all metadata stripped (EXIF, GPS, XMP, ICC comments).
 */
class ImageProcessor
{
    /** @param  array<string, int>  $sizes  name => longest edge */
    public function __construct(
        private readonly array $sizes,
        private readonly int $quality,
        private readonly string $driver = 'imagick',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('eventapp.media.image.sizes'),
            config('eventapp.media.image.quality'),
            config('eventapp.media.image.driver'),
        );
    }

    /**
     * @return array{width: int, height: int, blurhash: string, renditions: array<string, string>}
     *
     * @throws DecoderException when the file isn't a readable image
     */
    public function process(string $contents): array
    {
        $manager = $this->manager();
        $image = $manager->read($contents); // already upright (autoOrientation)

        $renditions = [];
        foreach ($this->sizes as $name => $edge) {
            $copy = clone $image;
            $copy->scaleDown(width: $edge, height: $edge);
            $renditions[$name] = (string) $copy->toWebp(quality: $this->quality, strip: true);
        }

        return [
            'width' => $image->width(),
            'height' => $image->height(),
            'blurhash' => $this->blurhash(clone $image),
            'renditions' => $renditions,
        ];
    }

    /** A 4×3-component blurhash computed on a tiny copy (fast, and plenty for a placeholder). */
    private function blurhash(ImageInterface $image): string
    {
        $image->scaleDown(width: 32, height: 32);
        $pixels = [];
        for ($y = 0; $y < $image->height(); $y++) {
            $row = [];
            for ($x = 0; $x < $image->width(); $x++) {
                [$r, $g, $b] = $image->pickColor($x, $y)->convertTo(new Rgb)->toArray();
                $row[] = [$r, $g, $b];
            }
            $pixels[] = $row;
        }

        return Blurhash::encode($pixels, 4, 3);
    }

    private function manager(): ImageManager
    {
        $driver = $this->driver === 'imagick' && extension_loaded('imagick') ? new ImagickDriver : new GdDriver;

        return new ImageManager($driver, autoOrientation: true, strip: true);
    }
}
