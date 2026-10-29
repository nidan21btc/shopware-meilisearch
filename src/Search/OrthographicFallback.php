<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

/** Local spelling fallback, never applied across languages or to article codes. */
final class OrthographicFallback
{
    public static function german(string $query, string $locale): ?string
    {
        if (!preg_match('/^de(?:-|$)/i', $locale) || mb_strlen($query, 'UTF-8') < 4) return null;
        $phrase = PartialMatches::withoutTrailingQuantity($query);
        if ($phrase === null && !preg_match('/^[\p{L}\p{M}]+$/uD', $query)) return null;
        $text = $phrase ?? $query;
        $lower = mb_strtolower($text, 'UTF-8');
        $alternate = str_replace(['ae', 'oe', 'ue', 'ss'], ['ä', 'ö', 'ü', 'ß'], $lower);
        if ($alternate === $lower) return null;
        if ($phrase === null) return $alternate;
        if (!preg_match('/(\s+[0-9]{1,9}(?:[.,][0-9]{1,8})?\s*(?:ml|kg|l|g)\s*)$/iuD', $query, $match)) return null;
        return $alternate . $match[1];
    }
}
