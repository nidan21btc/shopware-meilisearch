<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

/** Local spelling fallback, never applied across languages or to article codes. */
final class OrthographicFallback
{
    public static function german(string $query, string $locale): ?string
    {
        if (!preg_match('/^de(?:-|$)/i', $locale) || mb_strlen($query, 'UTF-8') < 4
            || !preg_match('/^[\p{L}\p{M}]+$/uD', $query)) {
            return null;
        }
        $lower = mb_strtolower($query, 'UTF-8');
        $alternate = str_replace(['ae', 'oe', 'ue', 'ss'], ['ä', 'ö', 'ü', 'ß'], $lower);

        return $alternate === $lower ? null : $alternate;
    }
}
