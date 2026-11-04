<?php

namespace App\Services\Ai\Gpt;

use App\Models\AiPhoto;
use Illuminate\Support\Facades\Log;

/** Полегшені копії лише для вихідних фото GPT; оригінали галереї не змінюються. */
class DeliveryImage
{
    private const MAX_SIDE = 1600;
    private const TARGET_BYTES = 350 * 1024;
    private const MAX_SOURCE_BYTES = 12 * 1024 * 1024;
    private const MAX_PIXELS = 20_000_000;
    private const QUALITIES = [84, 80, 76];
    private const VERSION = 'v1';

    public function url(AiPhoto $photo): string
    {
        $original = $photo->url();
        if ($photo->purpose === 'size_guide') return $original;

        try {
            $relative = (string) $photo->path;
            if (! preg_match('~^ai-gallery/[a-zA-Z0-9_./-]+$~D', $relative) || str_contains($relative, '..')) return $original;
            $base = realpath(public_path('ai-gallery'));
            $source = realpath(public_path($relative));
            if (! $base || ! $source || ! str_starts_with($source, $base.DIRECTORY_SEPARATOR) || ! is_file($source)) return $original;
            $bytes = filesize($source);
            $info = @getimagesize($source);
            if (! $info || ! $bytes || $bytes > self::MAX_SOURCE_BYTES || $info[0] < 1 || $info[1] < 1
                || $info[0] * $info[1] > self::MAX_PIXELS) return $original;
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
            if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || ($info['mime'] ?? null) !== $mime) return $original;
            if ($bytes <= self::TARGET_BYTES && max($info[0], $info[1]) <= self::MAX_SIDE) return $original;
            if ($mime === 'image/jpeg' && ! function_exists('exif_read_data')) return $original;

            // GD не керує колірними профілями: сумнівні ICC/CMYK-джерела передаємо без перекодування.
            $header = (string) file_get_contents($source, false, null, 0, min($bytes, 128 * 1024));
            if (($info['channels'] ?? null) === 4 || ($mime === 'image/jpeg' && str_contains($header, 'ICC_PROFILE'))
                || ($mime === 'image/png' && str_contains($header, 'iCCP')) || ($mime === 'image/webp' && str_contains($header, 'ICCP'))) return $original;
            $sha = hash_file('sha256', $source);
            $key = hash('sha256', self::VERSION.'|'.self::MAX_SIDE.'|'.self::TARGET_BYTES.'|'.implode(',', self::QUALITIES).'|'.$relative.'|'.$sha);
            $directory = $base.'/gpt-delivery';
            if (is_link($directory)) return $original;
            if (! is_dir($directory) && ! @mkdir($directory, 0775) && ! is_dir($directory)) return $original;
            if (realpath($directory) !== $directory) return $original;
            $target = $directory.'/'.$key.'.jpg';
            if (is_link($target)) return $original;
            if ($this->validJpeg($target)) return url('ai-gallery/gpt-delivery/'.$key.'.jpg');

            if (! function_exists('imagecreatetruecolor') || ! $this->hasMemory($info[0] * $info[1], $bytes)) return $original;
            if (! $this->encode($source, $target, $mime, $bytes, $sha)) return $original;

            return url('ai-gallery/gpt-delivery/'.$key.'.jpg');
        } catch (\Throwable $error) {
            // Збій оптимізації не повинен перервати доставку вже підготовленої відповіді.
            Log::warning('Не вдалося підготувати легше фото GPT', ['photo_id' => $photo->id, 'error' => $error->getMessage()]);
            return $original;
        }
    }

    private function validJpeg(string $path): bool
    {
        if (is_link($path) || ! is_file($path) || ! is_readable($path) || filesize($path) < 128
            || file_get_contents($path, false, null, -2) !== "\xff\xd9") return false;
        $info = @getimagesize($path);
        return $info && ($info['mime'] ?? '') === 'image/jpeg' && $info[0] > 0 && $info[1] > 0
            && max($info[0], $info[1]) <= self::MAX_SIDE;
    }

    private function hasMemory(int $pixels, int $bytes): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') return true;
        $unit = strtolower(substr($limit, -1));
        $maximum = (int) $limit * match ($unit) { 'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1 };
        // Поворот може одночасно тримати два оригінали, зменшену копію та буфери кодека.
        $needed = $pixels * 12 + self::MAX_SIDE ** 2 * 5 + $bytes * 2 + 16 * 1024 * 1024;
        return $maximum > 0 && $maximum - memory_get_usage(true) > $needed;
    }

    private function encode(string $source, string $target, string $mime, int $sourceBytes, string $sha): bool
    {
        $image = $resized = null;
        $temporary = null;
        try {
            $image = match ($mime) {
                'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source) : false,
                'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($source) : false,
                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            };
            if (! $image) return false;
            if ($mime !== 'image/jpeg' && $this->hasTransparency($image)) return false;
            if ($mime === 'image/jpeg') $image = $this->orient($image, $source);
            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, self::MAX_SIDE / max($width, $height));
            $resized = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            if (! $resized || ! imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height)) return false;
            imagedestroy($image);
            $image = null;
            imageinterlace($resized, true);
            $temporary = tempnam(dirname($target), '.gpt-');
            if (! $temporary || dirname($temporary) !== dirname($target)) return false;
            foreach (self::QUALITIES as $quality) {
                if (! imagejpeg($resized, $temporary, $quality)) return false;
                clearstatcache(true, $temporary);
                if (filesize($temporary) <= self::TARGET_BYTES) break;
            }
            // Зберігаємо деталі: ціль ваги м’яка, а копія має бути щонайменше на 10% легшою.
            if (! $this->validJpeg($temporary) || filesize($temporary) >= $sourceBytes * 0.9
                || ! hash_equals($sha, hash_file('sha256', $source)) || is_link($target)) return false;
            if (! chmod($temporary, 0644) || ! rename($temporary, $target)) return false;
            $temporary = null;
            return true;
        } finally {
            if ($image instanceof \GdImage) imagedestroy($image);
            if ($resized instanceof \GdImage) imagedestroy($resized);
            if ($temporary && is_file($temporary)) @unlink($temporary);
        }
    }

    private function hasTransparency(\GdImage $image): bool
    {
        if (! imageistruecolor($image)) {
            for ($index = 0, $count = imagecolorstotal($image); $index < $count; $index++) {
                if ((imagecolorsforindex($image, $index)['alpha'] ?? 0) > 0) return true;
            }
            return false;
        }
        for ($y = 0, $height = imagesy($image); $y < $height; $y++) {
            for ($x = 0, $width = imagesx($image); $x < $width; $x++) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7f) !== 0) return true;
            }
        }
        return false;
    }

    private function orient(\GdImage $image, string $source): \GdImage
    {
        if (! function_exists('exif_read_data')) return $image;
        $orientation = (int) (@exif_read_data($source)['Orientation'] ?? 1);
        if (in_array($orientation, [2, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
        if ($orientation === 4) imageflip($image, IMG_FLIP_VERTICAL);
        $angle = match ($orientation) { 3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0 };
        if ($angle === 0) return $image;
        $rotated = imagerotate($image, $angle, 0);
        if (! $rotated) throw new \RuntimeException('Не вдалося зберегти поворот фото.');
        imagedestroy($image);
        return $rotated;
    }
}
