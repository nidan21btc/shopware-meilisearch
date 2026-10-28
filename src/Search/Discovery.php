<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

/** Curated initial suggestions, replaceable per locale through plugin settings. */
final class Discovery
{
    private const DEFAULTS = [];

    private const FEATURED_DEFAULTS = [];

    public static function suggestions(string $json, string $locale): array
    {
        try {
            $byLanguage = $json === '' ? [] : json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        $entries = is_array($byLanguage) ? ($byLanguage[$locale] ?? self::DEFAULTS[$locale] ?? []) : [];
        if (!is_array($entries) || !array_is_list($entries)) {
            return [];
        }
        $valid = array_filter($entries, static fn ($entry) => is_string($entry) && trim($entry) !== '' && mb_strlen($entry) <= 150);

        return array_slice(array_values(array_unique($valid)), 0, 10);
    }

    public static function featured(string $json, string $locale): array
    {
        try {
            $byLanguage = $json === '' ? [] : json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        $entries = is_array($byLanguage) ? ($byLanguage[$locale] ?? self::FEATURED_DEFAULTS[$locale] ?? []) : [];
        if (!is_array($entries) || !array_is_list($entries)) {
            return [];
        }
        $valid = array_filter($entries, static fn ($id) => is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id));

        return array_slice(array_values(array_unique($valid)), 0, 10);
    }
}
