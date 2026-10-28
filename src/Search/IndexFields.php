<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

final class IndexFields
{
    public const DEFAULTS = ['productNumber', 'ean', 'manufacturerNumber', 'name', 'quantity', 'keywords', 'customSearchKeywords', 'manufacturer', 'properties', 'categories', 'description'];
    public const STANDARD = ['productNumber', 'ean', 'manufacturerNumber', 'name', 'quantity', 'keywords', 'customSearchKeywords',
        'manufacturer', 'manufacturerDescription', 'properties', 'options', 'categories', 'description', 'metaTitle',
        'metaDescription', 'ogTitle', 'ogDescription', 'packUnit', 'packUnitPlural', 'unit', 'unitShortCode', 'deliveryTime',
        'weight', 'width', 'height', 'length', 'purchaseUnit', 'referenceUnit', 'releaseDate'];

    public static function forLocale(string $globalJson, string $overridesJson, string $locale): array
    {
        $default = self::parse($globalJson);
        if (trim($overridesJson) === '') {
            return $default;
        }
        $overrides = json_decode($overridesJson, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($overrides) || ($overrides !== [] && array_is_list($overrides))) {
            throw new \InvalidArgumentException('Language-specific fields must be an object keyed by locale.');
        }
        // Validate every language before publishing any index.
        foreach ($overrides as $language => $fields) {
            if (!is_string($language) || !preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})+$/D', $language) || !is_array($fields)) {
                throw new \InvalidArgumentException('Invalid locale or field list.');
            }
            self::parse(json_encode($fields, JSON_THROW_ON_ERROR));
        }

        return array_key_exists($locale, $overrides) ? self::parse(json_encode($overrides[$locale], JSON_THROW_ON_ERROR)) : $default;
    }

    /** Ordered list: earlier attributes receive more weight in attribute ranking. */
    public static function parse(string $json): array
    {
        if (trim($json) === '') {
            return self::DEFAULTS;
        }
        $fields = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($fields) || !array_is_list($fields) || count($fields) < 1 || count($fields) > 64) {
            throw new \InvalidArgumentException('Index fields must be a JSON list of 1 to 64 field names.');
        }
        foreach ($fields as $field) {
            if ($field === 'searchKeywords' || $field === 'product.searchKeywords') {
                throw new \InvalidArgumentException('Shopware-generated searchKeywords can contain fallback-language text. Use the language-local keywords or customSearchKeywords fields instead.');
            }
            if (!is_string($field) || (!in_array($field, self::STANDARD, true) && !preg_match('/^customFields\.[a-zA-Z0-9_]{1,128}$/D', $field))) {
                throw new \InvalidArgumentException('Unsupported index field. Use a supported standard field or customFields.FIELD_NAME.');
            }
        }
        if (count(array_unique($fields)) !== count($fields)) {
            throw new \InvalidArgumentException('Duplicate index fields are not allowed.');
        }

        return $fields;
    }

    /** Only scalar field content enters the index; administration objects do not. */
    public static function text(mixed $value): string|array
    {
        if (is_array($value)) {
            $texts = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $texts[] = self::text($item);
                }
            }

            return array_values(array_unique(array_filter($texts, static fn ($text) => $text !== '')));
        }
        if (!is_scalar($value)) {
            return '';
        }

        // Script and style contents are not visible product text and must not
        // produce matches or make an otherwise empty translation searchable.
        $html = preg_replace('~<\s*(script|style)\b[^>]*>.*?</\s*\1\s*>~is', ' ', (string) $value) ?? (string) $value;
        // Adjacent HTML blocks must not merge words ("</p><p>"), otherwise
        // terms present in product descriptions become impossible to find.
        $html = preg_replace('/<\s*\/?\s*(?:p|div|li|br|h[1-6]|tr|td|th|section|article|ul|ol)\b[^>]*>/i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
