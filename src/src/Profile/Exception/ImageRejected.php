<?php

declare(strict_types=1);

namespace App\Profile\Exception;

/**
 * An uploaded file was refused (FR-062, FR-071, NFR-066).
 *
 * Each reason gets its own message because every one of them is a mistake the person uploading
 * can fix — the wrong file, a photo straight off a phone camera, a `.png` that is really a
 * screenshot renamed. Unlike an invitation code, there is nothing to enumerate here, so
 * uniform failure would only hide the fix.
 */
final class ImageRejected extends \DomainException implements ProfileException
{
    public static function tooLarge(int $sizeInBytes, int $limitInBytes): self
    {
        return new self(\sprintf(
            'That file is %.1f MB. The limit is %.0f MB — try a smaller image.',
            $sizeInBytes / 1_048_576,
            $limitInBytes / 1_048_576,
        ));
    }

    public static function unsupportedType(string $detectedType): self
    {
        return new self(\sprintf(
            'That file is a %s. Upload a PNG or JPEG image.',
            '' !== $detectedType ? $detectedType : 'file of an unrecognised type',
        ));
    }

    /**
     * The extension and the content disagree.
     *
     * Reported separately from an unsupported type because it is the one rejection that is
     * usually not a user error at all: it is a file whose name was changed, which is how an
     * upload filter that trusts the extension gets a payload past it (NFR-066).
     */
    public static function contentDoesNotMatchExtension(string $extension, string $detectedType): self
    {
        return new self(\sprintf(
            'That file is named ".%s" but its contents are %s. Rename it to match, or upload a different file.',
            $extension,
            $detectedType,
        ));
    }

    public static function notAnImage(): self
    {
        return new self('That file could not be read as an image. Upload a PNG or JPEG.');
    }

    public static function couldNotStore(): self
    {
        return new self('The image could not be saved. Please try again.');
    }
}
