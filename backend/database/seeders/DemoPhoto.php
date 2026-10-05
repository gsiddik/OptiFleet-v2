<?php

namespace Database\Seeders;

use Illuminate\Http\UploadedFile;

/**
 * Demo/functional seeders only: a small, valid solid-colour PNG standing in for a photo (e.g. the
 * Retread Form's casing photos). Built in plain PHP so it needs no image extension. Never used by
 * the production baseline seeders.
 */
final class DemoPhoto
{
    public static function make(string $filename = 'demo-photo.png', array $rgb = [96, 96, 96], int $width = 64, int $height = 48): UploadedFile
    {
        $row = "\0".str_repeat(pack('C3', ...$rgb), $width);
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat($row, $height)))
            .$chunk('IEND', '');

        $path = tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($path, $png);

        return new UploadedFile($path, $filename, 'image/png', null, true);
    }
}
