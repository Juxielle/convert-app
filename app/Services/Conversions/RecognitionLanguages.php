<?php

namespace App\Services\Conversions;

final class RecognitionLanguages
{
    public static function catalog(): array
    {
        return json_decode(file_get_contents(base_path('config/recognition-languages.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function options(string $tool): array
    {
        $ocr = $tool === 'ocr-image';
        $languages = [['code' => $ocr ? 'fr-en' : 'auto', 'label' => $ocr ? 'Français et anglais' : 'Détection automatique']];
        foreach (self::catalog() as $language) {
            if ($ocr || $language['speech'] !== null) {
                $languages[] = ['code' => $language['code'], 'label' => $language['label']];
            }
        }

        return $languages;
    }
}
