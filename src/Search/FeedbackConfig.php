<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

final class FeedbackConfig
{
    /** Validate all locales before saving or starting an index rebuild. */
    public static function parse(string $json, string $kind): array
    {
        if (!in_array($kind, ['popularity'], true)) {
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

            }
        }

        return $languages;
    }
}
