<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

/** Deterministic German transliteration; never reverse ae/oe/ue into umlauts. */
final class SearchFold
{
    public static function text(string $text): string
    {
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);
        if ($normalized === false) return '';
        return mb_strtolower(strtr($normalized, ['ä'=>'ae', 'ö'=>'oe', 'ü'=>'ue', 'Ä'=>'Ae', 'Ö'=>'Oe', 'Ü'=>'Ue', 'ß'=>'ss', 'ẞ'=>'SS']), 'UTF-8');
    }

    /** Avoid broad prefix matches such as blue -> blueten or feuer -> fuehrer. */
    public static function usesAliases(string $query): bool
    {
        $query = \Normalizer::normalize($query, \Normalizer::FORM_C);
        if ($query === false) return false;
        if (preg_match('/[äöüÄÖÜßẞ]/u', $query) || preg_match('/ss/iu', $query)) return true;
        return (bool) preg_match('/(?<![\p{L}\p{N}])(?:(?:ae|oe|ue)[\p{L}]+|[\p{L}]*[^q\W_](?:ae|oe|ue)[\p{L}]{2,})(?![\p{L}\p{N}])/iu', $query);
    }

    /** Only fields explicitly configured for search gain a folded companion. */
    public static function supports(string $field): bool
    {
        return !in_array($field, ['productNumber','ean','manufacturerNumber','quantity','weight','width','height','length','purchaseUnit','referenceUnit','releaseDate'], true);
    }

    /** Do not duplicate unmodified text in the index. */
    public static function aliases(string|array $value): string|array
    {
        if (is_array($value)) return array_values(array_filter(array_map(self::aliases(...), $value), static fn ($x) => is_string($x) && $x !== ''));
        $normal = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if ($normal === false || !preg_match('/[äöüÄÖÜßẞ]/u', $normal)) return '';
        return self::text($normal);
    }
}
