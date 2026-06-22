<?php

namespace App\Services\Ai;

use App\Models\AiPhoto;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Vision-частина агента: перцептивний відбиток картинок (dHash) і пошук
 * збігу фото клієнта з нашою галереєю. Винесено з AiAgentService.
 */
class ImageMatcher
{
    /**
     * Чи збігається фото клієнта з фото нашої галереї? (скрін нашого фото —
     * найчастіший випадок). Збіг = точний товар, модель не вгадує.
     * Відбитки галереї рахуються ліниво й кешуються в БД.
     */
    public function matchGalleryPhoto(string $bytes): ?AiPhoto
    {
        $hash = $this->imageHash($bytes);
        if (!$hash) {
            return null;
        }

        $best = null;
        $bestDist = 11; // поріг: ≤10 з 64 біт — впевнений збіг

        foreach (AiPhoto::with('products.color:id,name')->get() as $photo) {
            if (!$photo->phash) {
                $file = public_path($photo->path);
                if (!is_file($file)) {
                    continue;
                }
                $ph = $this->imageHash((string) file_get_contents($file));
                if (!$ph) {
                    continue;
                }
                $photo->forceFill(['phash' => $ph])->save();
            }
            $dist = $this->hashDistance($hash, $photo->phash);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $photo;
            }
        }

        return $best;
    }

    /**
     * Завантажити картинку клієнта (посилання Meta тимчасові, тягнемо одразу).
     * Не вдалося (протухло/завелике/не картинка) — null, історія деградує в текст.
     * @return array{mime: string, bytes: string}|null
     */
    public function fetchImage(string $url): ?array
    {
        try {
            $r = Http::timeout(10)->get($url);
            if (!$r->successful()) {
                return null;
            }
            $body = $r->body();
            if (strlen($body) > 4 * 1024 * 1024) { // ліміт API ~5MB на картинку, з запасом
                return null;
            }
            // Тип визначаємо з САМИХ байтів — заголовок сервера часто бреше
            // (PNG-скрін приходив як image/jpeg, і Claude його відхиляв).
            $info = @getimagesizefromstring($body);
            $mime = $info['mime'] ?? '';
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                return null; // невідомий/непідтримуваний формат — краще не слати
            }

            return ['mime' => $mime, 'bytes' => $body];
        } catch (\Throwable $e) {
            Log::warning('AI vision: не вдалося завантажити фото клієнта', ['url' => mb_substr($url, 0, 120), 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * dHash 8x8 (16 hex-символів) — перцептивний відбиток картинки.
     * Стійкий до стиснення/ресайзу, яким Meta мне скріни клієнтів.
     */
    private function imageHash(string $bytes): ?string
    {
        try {
            $img = @imagecreatefromstring($bytes);
            if (!$img) {
                return null;
            }
            $small = imagescale($img, 9, 8);
            imagedestroy($img);
            if (!$small) {
                return null;
            }

            $bits = '';
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 9; $x++) {
                    $c = imagecolorat($small, $x, $y);
                    $gray[$x] = (($c >> 16 & 0xFF) * 299 + ($c >> 8 & 0xFF) * 587 + ($c & 0xFF) * 114) / 1000;
                    if ($x > 0) {
                        $bits .= $gray[$x - 1] > $gray[$x] ? '1' : '0';
                    }
                }
            }
            imagedestroy($small);

            $hex = '';
            foreach (str_split($bits, 4) as $nibble) {
                $hex .= dechex(bindec($nibble));
            }

            return $hex;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Відстань Геммінга між двома hex-відбитками (менше = схожіші). */
    private function hashDistance(string $a, string $b): int
    {
        $d = 0;
        for ($i = 0; $i < min(strlen($a), strlen($b)); $i++) {
            $d += substr_count(str_pad(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), 4, '0', STR_PAD_LEFT), '1');
        }

        return $d;
    }
}
