<?php

namespace App\Services\Conversions;

final class ImageCodec
{
    public static function header(string $path, array $limits): array
    {
        $info = @getimagesize($path);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new PipelineException('INVALID_IMAGE');
        }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w > $limits['max_dimension'] || $h > $limits['max_dimension'] || $w * $h > $limits['max_pixels']) {
            throw new PipelineException('IMAGE_TOO_COMPLEX');
        }
        // Bound container scanning and reject animation rather than silently dropping frames.
        $stream = fopen($path, 'rb');
        try {
            $prefix = fread($stream, 32);
            if ($info[2] === IMAGETYPE_JPEG) {
                fseek($stream, -2, SEEK_END);
                if (fread($stream, 2) !== "\xff\xd9") {
                    throw new PipelineException('INVALID_IMAGE');
                }
            }
            if ($info[2] === IMAGETYPE_WEBP && unpack('V', substr($prefix, 4, 4))[1] + 8 !== filesize($path)) {
                throw new PipelineException('INVALID_IMAGE');
            }
            if ($info[2] === IMAGETYPE_WEBP && substr($prefix, 12, 4) === 'VP8X' && (ord($prefix[20]) & 2)) {
                throw new PipelineException('ANIMATED_IMAGE_UNSUPPORTED');
            }
            if ($info[2] === IMAGETYPE_PNG) {
                fseek($stream, 8);
                for ($chunks = 0; $chunks < 4096; $chunks++) {
                    $chunk = fread($stream, 8);
                    if (strlen($chunk) !== 8) {
                        throw new PipelineException('INVALID_IMAGE');
                    }
                    $length = unpack('N', substr($chunk, 0, 4))[1];
                    $type = substr($chunk, 4);
                    if ($type === 'acTL') {
                        throw new PipelineException('ANIMATED_IMAGE_UNSUPPORTED');
                    }
                    if ($type === 'IEND') {
                        return $info;
                    }
                    if ($length > filesize($path) || fseek($stream, $length + 4, SEEK_CUR) !== 0) {
                        throw new PipelineException('INVALID_IMAGE');
                    }
                }
                throw new PipelineException('IMAGE_TOO_COMPLEX');
            }
        } finally {
            fclose($stream);
        }

        return $info;
    }

    public static function run(array $task): void
    {
        $info = self::header($task['input'], $task['limits']);
        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($task['input']),
            IMAGETYPE_PNG => @imagecreatefrompng($task['input']),
            IMAGETYPE_WEBP => @imagecreatefromwebp($task['input']),
        };
        if (! $source) {
            throw new PipelineException('INVALID_IMAGE');
        }
        $options = $task['options'];
        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(1, ($options['width'] ?? $width) / $width, ($options['height'] ?? $height) / $height);
        $w = max(1, (int) floor($width * $ratio));
        $h = max(1, (int) floor($height * $ratio));
        $dest = imagecreatetruecolor($w, $h);
        if ($task['target'] === 'jpg') {
            imagefill($dest, 0, 0, imagecolorallocate($dest, 255, 255, 255));
        } else {
            imagealphablending($dest, false);
            imagesavealpha($dest, true);
            imagefill($dest, 0, 0, imagecolorallocatealpha($dest, 0, 0, 0, 127));
        }
        imagecopyresampled($dest, $source, 0, 0, 0, 0, $w, $h, $width, $height);
        unset($source);
        stream_filter_register('conversion.byte_limit', ImageOutputFilter::class);
        $output = fopen($task['output'], 'wb');
        stream_filter_append($output, 'conversion.byte_limit', STREAM_FILTER_WRITE, $task['max_bytes']);
        $ok = match ($task['target']) {
            'jpg' => @imagejpeg($dest, $output, $options['quality'] ?? 82),
            'png' => @imagepng($dest, $output, 9),
            'webp' => @imagewebp($dest, $output, $options['quality'] ?? 82),
        };
        if (is_resource($output)) {
            fclose($output);
        }
        unset($dest);
        if (ImageOutputFilter::$exceeded || ! $ok || ! is_file($task['output']) || filesize($task['output']) < 1) {
            throw new PipelineException('INVALID_OUTPUT');
        }
        $result = self::header($task['output'], $task['limits']);
        $decoded = match ($task['target']) {
            'jpg' => @imagecreatefromjpeg($task['output']),
            'png' => @imagecreatefrompng($task['output']),
            'webp' => @imagecreatefromwebp($task['output']),
        };
        if (! $decoded) {
            throw new PipelineException('INVALID_OUTPUT');
        }
        unset($decoded);
        if ($result[0] !== $w || $result[1] !== $h || $result[2] !== ['jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP][$task['target']]) {
            throw new PipelineException('INVALID_OUTPUT');
        }
    }
}
