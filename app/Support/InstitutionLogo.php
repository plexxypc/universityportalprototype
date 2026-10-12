<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Checks a logo upload by its bytes.
 *
 * The stored name ignores the client filename. A declared image type that
 * disagrees with the bytes is refused. PNG and JPEG are accepted only when
 * the content type and a successful decode agree, and neither edge exceeds 2000.
 */
final class InstitutionLogo
{
    public const int MAX_EDGE_PIXELS = 2000;

    public const string MESSAGE_TYPE = 'The logo must be a PNG or JPEG.';

    public const string MESSAGE_SIZE = 'The logo is larger than the allowed size.';

    public const string MESSAGE_READ = 'The logo could not be read as an image.';

    public const string MESSAGE_DIMENSIONS = 'The logo must be at most 2000 by 2000 pixels.';

    public const string MESSAGE_MISMATCH = 'The logo content type does not match the file.';

    /**
     * Accept PNG or JPEG bytes, or throw a fixed message.
     *
     * A declared image type that disagrees with the bytes is refused.
     * An empty declaration is ignored.
     *
     * @return array{extension: string, mime: string}
     */
    public function inspect(string $contents, int $max_bytes, ?string $declared_mime = null): array
    {
        if ($contents === '' || strlen($contents) > $max_bytes) {
            throw new InvalidArgumentException(self::MESSAGE_SIZE);
        }

        $mime = $this->mime($contents);

        if ($mime !== 'image/png' && $mime !== 'image/jpeg') {
            throw new InvalidArgumentException(self::MESSAGE_TYPE);
        }

        if ($this->declaredTypeConflicts($declared_mime, $mime)) {
            throw new InvalidArgumentException(self::MESSAGE_MISMATCH);
        }

        $info = @getimagesizefromstring($contents);

        if ($info === false) {
            throw new InvalidArgumentException(self::MESSAGE_READ);
        }

        if ($info['mime'] !== $mime) {
            throw new InvalidArgumentException(self::MESSAGE_MISMATCH);
        }

        if (! function_exists('imagecreatefromstring')) {
            throw new InvalidArgumentException(self::MESSAGE_READ);
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            throw new InvalidArgumentException(self::MESSAGE_READ);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        unset($image);

        if ($width > self::MAX_EDGE_PIXELS || $height > self::MAX_EDGE_PIXELS) {
            throw new InvalidArgumentException(self::MESSAGE_DIMENSIONS);
        }

        return [
            'extension' => $mime === 'image/png' ? 'png' : 'jpg',
            'mime' => $mime,
        ];
    }

    /**
     * Whether the caller named an image type other than the one in the bytes.
     */
    private function declaredTypeConflicts(?string $declared_mime, string $mime): bool
    {
        if ($declared_mime === null || $declared_mime === '' || $declared_mime === 'application/octet-stream') {
            return false;
        }

        return $declared_mime !== $mime;
    }

    /**
     * Content type from the bytes, or an empty string when it cannot be read.
     */
    private function mime(string $contents): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($contents);

        return is_string($mime) ? $mime : '';
    }
}
