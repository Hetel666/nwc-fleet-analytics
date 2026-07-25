<?php

namespace App\Services;

class GeofenceNameNormalizer
{
    public function normalize(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            $value = is_string($normalized) ? $normalized : $value;
        }

        $value = str_replace(
            ["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"],
            '-',
            $value
        );
        $value = preg_replace('/\s*-\s*/u', ' - ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }
}
