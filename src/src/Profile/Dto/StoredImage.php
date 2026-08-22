<?php

declare(strict_types=1);

namespace App\Profile\Dto;

/**
 * Where an accepted upload ended up (FR-062, FR-071).
 *
 * Both paths are relative to the private upload root and neither is a URL: NFR-066 keeps
 * uploads outside the web root, and the controller that serves them decides who may look.
 *
 * `thumbnailPath` is null when no smaller copy was produced — GD absent, or an image already
 * small enough that a second identical file would be waste. Callers fall back to the full
 * image, which is why this is nullable rather than defaulted to the original: "there is no
 * thumbnail" and "the thumbnail is the original" are different facts, and only the first one
 * lets a later backfill find the images that still need one.
 */
final readonly class StoredImage
{
    public function __construct(
        public string $path,
        public ?string $thumbnailPath,
        public string $mimeType,
        public int $sizeInBytes,
    ) {
    }
}
