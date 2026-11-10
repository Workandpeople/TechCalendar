<?php

namespace App\Services\Tech;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OnSitePhoto
{
    public function store(UploadedFile $photo, int $dossierId, bool $mymar = false): array
    {
        $dimensions = @getimagesize($photo->getPathname());
        $mime = $photo->getMimeType();
        if (! $dimensions || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $dimensions[0] * $dimensions[1] > 12000000 || max($dimensions[0], $dimensions[1]) > 8000) {
            throw ValidationException::withMessages(['photo' => 'Photo invalide ou trop grande. Réduisez-la à 12 mégapixels maximum.']);
        }
        $limit = ini_parse_quantity(ini_get('memory_limit'));
        if ($limit > 0 && memory_get_usage(true) + $dimensions[0] * $dimensions[1] * 8 + 16 * 1024 ** 2 > $limit) {
            throw ValidationException::withMessages(['photo' => 'Cette photo est trop grande pour être traitée. Réduisez sa taille avant de réessayer.']);
        }
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($photo->getPathname()),
            'image/png' => @imagecreatefrompng($photo->getPathname()),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($photo->getPathname()) : false,
        };
        if (! $image) {
            throw ValidationException::withMessages(['photo' => 'La photo ne peut pas être lue. Reprenez-la au format JPEG.']);
        }
        // Re-encode raster pixels only: do not retain EXIF/GPS or embedded payloads.
        $orientation = $mime === 'image/jpeg' && function_exists('exif_read_data')
            ? (int) (@exif_read_data($photo->getPathname(), 'IFD0')['Orientation'] ?? 1) : 1;
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, in_array($orientation, [4, 5], true) ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0
        };
        if ($angle) {
            $image = imagerotate($image, $angle, 0);
        }
        $ratio = min(1, 2000 / max(imagesx($image), imagesy($image)));
        $width = max(1, (int) round(imagesx($image) * $ratio));
        $height = max(1, (int) round(imagesy($image) * $ratio));
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        $stream = fopen('php://temp', 'w+b');
        try {
            if (! imagejpeg($canvas, $stream, 85)) {
                throw new RuntimeException('On-site photo encoding failed.');
            }
            $size = ftell($stream);
            rewind($stream);
            $path = ($mymar ? 'crm/mymar/' : 'crm/dossiers/').$dossierId.'/on-site/'.Str::uuid().'.jpg';
            if (! Storage::disk('local')->put($path, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('On-site photo storage failed.');
            }

            return ['path' => $path, 'mime_type' => 'image/jpeg', 'size' => $size];
        } finally {
            fclose($stream);
        }
    }
}
