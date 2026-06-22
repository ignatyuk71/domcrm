<?php

namespace App\Services\Ai;

/**
 * Чистка тексту, який бачить модель або клієнт.
 * Винесено з AiAgentService: чисті функції без стану й залежностей.
 */
class TextScrubber
{
    /**
     * Внутрішні назви лінійок (вигадані для CRM, клієнти їх не знають).
     * Вирізаються з УСЬОГО, що бачить модель: каталог, картки, історія.
     * Модель не може сказати слово, якого ніколи не бачила.
     */
    private const INTERNAL_WORDS = ['halluci', 'luxury'];

    /** Прибрати службові слова з тексту для моделі. */
    public function scrub(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        $clean = preg_replace('/(?:' . implode('|', self::INTERNAL_WORDS) . ')/iu', '', $text);
        return trim(preg_replace('/[ \t]{2,}/u', ' ', $clean));
    }

    /**
     * Вирізати плейсхолдер фото («[зображення]» тощо). Фото шле лише send_photos;
     * якщо модель надрукувала позначку текстом — клієнт побачив би сміття.
     * Чистимо і вихідний текст, і історію (щоб модель не копіювала позначку далі).
     */
    public function stripPhotoPlaceholder(?string $text): string
    {
        $clean = preg_replace('/\[\s*(?:зображення|фото|image|photo|картинк[аи])\s*\]/iu', '', (string) $text);
        // Службова примітка памʼяті «(надіслала клієнту фото товарів: …)» — лише для
        // історії бота; якщо модель її скопіювала у відповідь — вирізаємо, щоб не злити клієнту.
        $clean = preg_replace('/\(\s*надісла[^)]*фото товарів[^)]*\)/iu', '', (string) $clean);
        return trim(preg_replace('/\n{3,}/u', "\n\n", (string) $clean));
    }
}
