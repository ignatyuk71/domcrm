<?php

namespace App\Support;

/**
 * Стискання фото товарів: ресайз до розумного розміру + перекодування в JPEG.
 *
 * Оригінали з телефона важать 1–5 МБ (камера 4000+ px), а показуються
 * здебільшого в мініатюрах — тримати їх повнорозмірними нема сенсу.
 * Використовує лише GD (без зовнішніх пакетів). Прозорість PNG/WebP
 * кладеться на білий фон, EXIF-поворот телефонних JPEG застосовується
 * до пікселів (JPEG на виході EXIF не має).
 */
class ImageOptimizer
{
    public const MAX_SIDE = 1200;
    public const QUALITY = 82;

    /**
     * Перекодувати картинку в JPEG-файл $targetPath (ресайз до $maxSide по
     * довшій стороні). true — записано; false — джерело не картинка або GD
     * не впорався (тоді залишаємо оригінал як є).
     */
    public static function toJpeg(
        string $sourcePath,
        string $targetPath,
        int $maxSide = self::MAX_SIDE,
        int $quality = self::QUALITY
    ): bool {
        $info = @getimagesize($sourcePath);
        if (!$info) {
            return false;
        }

        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sourcePath),
            IMAGETYPE_GIF => @imagecreatefromgif($sourcePath),
            default => null,
        };
        if (!$src) {
            return false;
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $src = self::applyExifOrientation($src, $sourcePath);
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $ok = imagejpeg($dst, $targetPath, $quality);
        imagedestroy($dst);

        return (bool) $ok;
    }

    /** Повернути пікселі так, як каже EXIF Orientation (3/6/8). */
    private static function applyExifOrientation(\GdImage $img, string $sourcePath): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }

        $orientation = (int) (@exif_read_data($sourcePath)['Orientation'] ?? 1);
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $img;
        }

        $rotated = imagerotate($img, $angle, 0);
        if ($rotated === false) {
            return $img;
        }
        imagedestroy($img);

        return $rotated;
    }
}
