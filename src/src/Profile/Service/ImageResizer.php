<?php

declare(strict_types=1);

namespace App\Profile\Service;

use Psr\Log\LoggerInterface;

/**
 * Downscales stored images (FR-062's thumbnail, FR-071's "auto-resize if larger").
 *
 * **Degrades instead of failing.** GD is not compiled into every PHP build — it is absent from
 * this project's own `php:8.2-fpm-alpine` base until the image is rebuilt with the `gd`
 * extension the Dockerfile now installs. An uploader that threw without it would take the whole
 * profile screen down on a environment that skipped a rebuild, to avoid producing a smaller
 * copy of a file it had already stored successfully. So a missing extension means "no
 * thumbnail", logged once per process, and the original is used in its place.
 *
 * That is a real trade-off and worth naming: without GD, FR-071's auto-resize does not happen,
 * so a trainer's 3000px logo is served at 3000px. It is a slow header, not a broken one, and
 * the 2MB size limit still bounds it.
 *
 * Concrete rather than an interface with a Null sibling. The MANIFEST rules out "interfaces
 * without a real boundary", and there is no second implementation in prospect — swapping GD for
 * Imagick would change this class's body, not its callers. `isAvailable()` states the runtime
 * fact the callers actually branch on.
 */
final class ImageResizer
{
    /**
     * Only the formats `ImageUploader` accepts. Keeping the two lists in step matters: a format
     * that passes validation but cannot be read here would store an image whose thumbnail
     * silently never appears.
     */
    private const READERS = [
        'image/png' => 'imagecreatefrompng',
        'image/jpeg' => 'imagecreatefromjpeg',
    ];

    private bool $unavailabilityLogged = false;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        return \extension_loaded('gd') && \function_exists('imagecreatetruecolor');
    }

    /**
     * Writes a copy of `$sourcePath` at `$targetPath`, scaled to fit inside the given box.
     *
     * Aspect ratio is preserved and images already smaller than the box are copied unchanged
     * rather than enlarged — upscaling a 40px avatar to 200px produces a blurrier picture, not
     * a bigger one.
     *
     * @return bool whether a file now exists at `$targetPath`
     */
    public function resizeToFit(string $sourcePath, string $targetPath, string $mimeType, int $maxWidth, int $maxHeight): bool
    {
        if (!$this->isAvailable()) {
            $this->logUnavailable();

            return false;
        }

        $reader = self::READERS[$mimeType] ?? null;

        // The `function_exists` half looks redundant — PHPStan's stubs declare both readers — but
        // GD can be built without JPEG support, in which case `imagecreatefromjpeg` is genuinely
        // absent while `extension_loaded('gd')` is still true. Removing it would turn that build
        // into a fatal error instead of an unresized image.
        // @phpstan-ignore function.alreadyNarrowedType
        if (null === $reader || !\function_exists($reader)) {
            $this->logger->warning('No GD reader for this image type; storing the original unresized.', [
                'mimeType' => $mimeType,
            ]);

            return false;
        }

        $dimensions = @getimagesize($sourcePath);

        if (false === $dimensions || $dimensions[0] < 1 || $dimensions[1] < 1) {
            return false;
        }

        [$width, $height] = $dimensions;
        $scale = min($maxWidth / $width, $maxHeight / $height, 1.0);

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        /** @var \GdImage|false $source */
        $source = @$reader($sourcePath);

        if (false === $source) {
            $this->logger->warning('GD could not decode a stored image.', ['path' => $sourcePath]);

            return false;
        }

        try {
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

            if (false === $canvas) {
                return false;
            }

            // PNGs carry transparency and JPEGs do not. Without this the alpha channel is
            // flattened onto black, so a logo with a transparent background arrives as a logo
            // on a black square — the single most visible way this could go wrong.
            if ('image/png' === $mimeType) {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);

                if (false !== $transparent) {
                    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
                }
            }

            if (!imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
                return false;
            }

            $written = 'image/png' === $mimeType
                ? imagepng($canvas, $targetPath)
                : imagejpeg($canvas, $targetPath, 85);

            imagedestroy($canvas);

            return $written;
        } finally {
            imagedestroy($source);
        }
    }

    private function logUnavailable(): void
    {
        if ($this->unavailabilityLogged) {
            return;
        }

        $this->unavailabilityLogged = true;

        $this->logger->warning('The GD extension is not loaded: uploaded images are stored at their original size and no thumbnails are generated. Rebuild the php-fpm image to enable resizing.');
    }
}
