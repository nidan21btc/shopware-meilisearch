<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

final class FeedbackConfig
{
    /** Append reviewed hints within each language and product; never silently drop existing hints. */
    public static function mergeHints(string $existingJson, string $additionalJson): string
    {
        $existing = self::parse($existingJson, 'hints');
        $additional = self::parse($additionalJson, 'hints');
        foreach ($additional as $locale => $products) {
            foreach ($products as $id => $terms) {
                $current = $existing[$locale][$id] ?? [];
                foreach ($terms as $term) {
                    if (!in_array($term, $current, true)) $current[] = $term;
                }
                if (count($current) > 50) throw new \InvalidArgumentException('Merging would exceed 50 hints for a product.');
                $existing[$locale][$id] = $current;
            }
        }
        $json = json_encode((object) $existing, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 20 * 1024 * 1024) throw new \InvalidArgumentException('Merged hints exceed 20 MB.');

        return $json;
    }

    /** Validate all locales before saving or starting an index rebuild. */
    public static function parse(string $json, string $kind): array
    {
        if (!in_array($kind, ['popularity', 'hints'], true)) {
            throw new \InvalidArgumentException('Unsupported feedback type.');
        }
        $languages = trim($json) === '' ? [] : json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($languages) || ($languages !== [] && array_is_list($languages))) {
            throw new \InvalidArgumentException('Feedback must be an object keyed by locale.');
        }
        foreach ($languages as $locale => $products) {
            if (!is_string($locale) || !preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})+$/D', $locale)
                || !is_array($products) || ($products !== [] && array_is_list($products))) {
                throw new \InvalidArgumentException('Invalid feedback locale or product map.');
            }
            foreach ($products as $id => $value) {
                if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
                    throw new \InvalidArgumentException('Invalid feedback product UUID.');
                }
                if ($kind === 'popularity' && (!is_int($value) || $value < 0 || $value > 1000000)) {
                    throw new \InvalidArgumentException('Invalid popularity weight.');
                }
                if ($kind === 'hints') {
                    if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
                        throw new \InvalidArgumentException('Invalid product query hints.');
                    }
                    foreach ($value as $term) {
                        if (!is_string($term) || trim($term) === '' || mb_strlen($term) > 80) {
                            throw new \InvalidArgumentException('Invalid product query hint.');
                        }
                    }
                }
            }
        }

        return $languages;
    }
}
