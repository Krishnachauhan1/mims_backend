<?php

namespace App\Support;

/** Normalizes UI placeholders (em dash, etc.) to empty for API payloads. */
final class DisplayValue
{
    public static function isPlaceholder(?string $value): bool
    {
        $t = trim((string) $value);
        if ($t === '') {
            return true;
        }
        if (in_array($t, ['—', '---', '-', '–', 'null', 'NULL'], true)) {
            return true;
        }

        return (bool) preg_match('/^[\-–—\.]+$/u', $t);
    }

    public static function normalize(?string $value): ?string
    {
        if (self::isPlaceholder($value)) {
            return null;
        }

        return trim((string) $value);
    }
}
