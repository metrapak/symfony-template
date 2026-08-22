<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Profile\Dto\StoredImage;
use App\Profile\Exception\ImageRejected;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The only way a file gets from a browser onto this platform's disk (FR-062, FR-071, NFR-066).
 *
 * Uploads are the classic remote-code-execution route into a PHP application, so four rules
 * hold here and none of them is optional:
 *
 *  - **Type is decided by content, never by name.** The extension a browser sends is attacker
 *    controlled, and so is the `Content-Type` header. `getimagesize()` is what actually
 *    decides, because it fails on anything that is not a parseable image — a PHP file renamed
 *    to `.png` does not survive it. The extension is then checked *against* the detected type,
 *    which is a separate rejection with its own message (NFR-066's "type-validated by content
 *    rather than extension").
 *  - **Nothing is stored under a caller-supplied name.** The stored filename is random, and
 *    the extension is derived from the detected type — not from the upload. A path from the
 *    client can traverse; a path we generated cannot.
 *  - **Files live outside the web root.** `var/uploads/` is not served by nginx, so no request
 *    reaches a stored file except through a controller that has already decided who may see
 *    it. This is what makes G-24 a smaller problem than it would otherwise be, and it is also
 *    why the stored path is never a URL.
 *  - **SVG is refused** (G-24). It is a script-bearing document, not a raster image: served
 *    inline it executes, and sanitizing it properly is a project of its own. FR-071 lists it,
 *    the same requirement's "auto-resize if larger" implies raster anyway, and dropping it is
 *    the recommendation the task carried. PNG and JPEG only.
 *
 * The 2MB limit from FR-071 is applied to profile photos too. FR-062 states no limit for those,
 * which cannot be what is meant — an unbounded upload endpoint is a disk-exhaustion primitive —
 * so the branding limit is the default for both.
 */
final readonly class ImageUploader
{
    public const MAX_BYTES = 2 * 1_048_576;

    /**
     * Detected type to the extension we store it under. Also serves as the allow-list: a type
     * absent from this map is rejected, so adding a format is one edit and cannot be done by
     * accident.
     */
    private const ACCEPTED = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
    ];

    /**
     * FR-071's recommended logo size, and the box a photo is fitted into. Generous enough that
     * a resized image still looks right on a high-density display, small enough that a header
     * is not shipping a camera original.
     */
    private const FULL_BOX = 1024;
    private const LOGO_BOX = 400;
    private const THUMBNAIL_BOX = 200;

    public function __construct(
        private ImageResizer $resizer,
        private Filesystem $filesystem,
        private LoggerInterface $logger,
        private string $uploadRoot,
    ) {
    }

    /**
     * Validates and stores a profile photo, with a thumbnail (FR-062).
     *
     * @throws ImageRejected
     */
    public function storeProfilePhoto(UploadedFile $file): StoredImage
    {
        return $this->store($file, 'photos', self::FULL_BOX, withThumbnail: true);
    }

    /**
     * Validates and stores a branding logo (FR-071).
     *
     * No thumbnail: the logo is only ever rendered in a header at roughly its stored size, and
     * a second file nothing displays is a file to keep in step for no reason.
     */
    public function storeLogo(UploadedFile $file): StoredImage
    {
        return $this->store($file, 'logos', self::LOGO_BOX, withThumbnail: false);
    }

    /**
     * Deletes a stored image and its thumbnail, tolerating anything already gone.
     *
     * FR-062 says an upload replaces the previous photo, and "replaces" has to include the
     * bytes: a photo the user believes they removed but which is still readable through its
     * old path is a privacy failure, not a housekeeping oversight. Failure to unlink is logged
     * rather than raised — the row has already moved on, and refusing the save because a stale
     * file could not be removed would leave the user unable to change their photo at all.
     */
    public function delete(?string ...$paths): void
    {
        foreach ($paths as $path) {
            if (null === $path) {
                continue;
            }

            $absolute = $this->absolutePathFor($path);

            if (null === $absolute || !$this->filesystem->exists($absolute)) {
                continue;
            }

            try {
                $this->filesystem->remove($absolute);
            } catch (\Throwable $e) {
                $this->logger->error('A replaced upload could not be deleted.', [
                    'path' => $path,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Resolves a stored relative path to an absolute one, or null if it escapes the root.
     *
     * The containment check is not paranoia about our own generated names — it is what stops a
     * path that reached the database through some future code path from being served. The
     * serving controller calls this, so "the file is inside the upload root" is proven at the
     * moment of reading rather than assumed from the moment of writing.
     */
    public function absolutePathFor(?string $relativePath): ?string
    {
        if (null === $relativePath || '' === $relativePath) {
            return null;
        }

        $root = rtrim($this->uploadRoot, '/');
        $candidate = $root . '/' . ltrim($relativePath, '/');

        // Compared before realpath() so a traversal in a non-existent path is refused rather
        // than turning into null and being mistaken for "no file".
        if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
            return null;
        }

        $real = realpath($candidate);
        $realRoot = realpath($root);

        if (false === $real || false === $realRoot || !str_starts_with($real, $realRoot . \DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    /**
     * @throws ImageRejected
     */
    private function store(UploadedFile $file, string $namespace, int $box, bool $withThumbnail): StoredImage
    {
        $size = $file->getSize();

        if (false === $size) {
            throw ImageRejected::couldNotStore();
        }

        // Checked before anything reads the file, so an oversized upload is refused without
        // being parsed. `post_max_size` may reject a much larger one before PHP hands it over
        // at all; this is the limit the requirement names.
        if ($size > self::MAX_BYTES) {
            throw ImageRejected::tooLarge($size, self::MAX_BYTES);
        }

        $detected = $this->detectImageType($file->getPathname());

        if (!isset(self::ACCEPTED[$detected])) {
            throw ImageRejected::unsupportedType($detected);
        }

        $extension = self::ACCEPTED[$detected];
        $claimed = mb_strtolower($file->getClientOriginalExtension());

        // `jpg` and `jpeg` are the same format under two spellings; anything else disagreeing
        // is the renamed-file case NFR-066 asks about.
        if ('' !== $claimed && !$this->extensionMatches($claimed, $extension)) {
            throw ImageRejected::contentDoesNotMatchExtension($claimed, $detected);
        }

        $relativeDirectory = $namespace . '/' . date('Y/m');
        $absoluteDirectory = rtrim($this->uploadRoot, '/') . '/' . $relativeDirectory;

        try {
            $this->filesystem->mkdir($absoluteDirectory, 0o750);
        } catch (\Throwable $e) {
            $this->logger->error('The upload directory could not be created.', [
                'directory' => $absoluteDirectory,
                'exception' => $e,
            ]);

            throw ImageRejected::couldNotStore();
        }

        // 16 bytes from the CSPRNG, like an invitation code: a stored file must not be
        // guessable from anything the uploader knows, and a sequential or name-derived
        // filename would make the serving controller's authorization the *only* thing between
        // a stranger and somebody's child's photograph.
        $basename = bin2hex(random_bytes(16));
        $relativePath = $relativeDirectory . '/' . $basename . '.' . $extension;
        $absolutePath = $absoluteDirectory . '/' . $basename . '.' . $extension;

        try {
            $file->move($absoluteDirectory, $basename . '.' . $extension);
        } catch (\Throwable $e) {
            $this->logger->error('An upload could not be moved into place.', ['exception' => $e]);

            throw ImageRejected::couldNotStore();
        }

        // Downscale in place: FR-071's "auto-resize if larger", and for photos the difference
        // between storing a phone original and something a page can load. A resizer that
        // cannot run leaves the original exactly as it is.
        $this->resizer->resizeToFit($absolutePath, $absolutePath, $detected, $box, $box);

        $thumbnailPath = $withThumbnail
            ? $this->makeThumbnail($absolutePath, $relativePath, $detected, $extension)
            : null;

        return new StoredImage(
            path: $relativePath,
            thumbnailPath: $thumbnailPath,
            mimeType: $detected,
            sizeInBytes: (int) (@filesize($absolutePath) ?: $size),
        );
    }

    private function makeThumbnail(string $absolutePath, string $relativePath, string $mimeType, string $extension): ?string
    {
        $thumbnailRelative = preg_replace('/\.' . preg_quote($extension, '/') . '$/', '_thumb.' . $extension, $relativePath);

        if (null === $thumbnailRelative || $thumbnailRelative === $relativePath) {
            return null;
        }

        $thumbnailAbsolute = rtrim($this->uploadRoot, '/') . '/' . $thumbnailRelative;

        return $this->resizer->resizeToFit($absolutePath, $thumbnailAbsolute, $mimeType, self::THUMBNAIL_BOX, self::THUMBNAIL_BOX)
            ? $thumbnailRelative
            : null;
    }

    /**
     * The detected MIME type.
     *
     * @throws ImageRejected when the file is not a parseable image at all
     *
     * `getimagesize()` rather than `UploadedFile::getMimeType()`: the latter consults finfo,
     * which reports a type for *any* file including a PHP script, while this one parses the
     * image header and fails on anything that is not really an image. finfo alone would happily
     * accept `text/x-php`, and the allow-list would be the only thing standing in the way.
     *
     * A successful call always reports a `mime`; the only failure mode is the `false` above, and
     * a type outside `ACCEPTED` is what the caller rejects.
     */
    private function detectImageType(string $path): string
    {
        $info = @getimagesize($path);

        if (false === $info) {
            throw ImageRejected::notAnImage();
        }

        return $info['mime'];
    }

    private function extensionMatches(string $claimed, string $canonical): bool
    {
        $normalized = 'jpeg' === $claimed ? 'jpg' : $claimed;

        return $normalized === $canonical;
    }
}
