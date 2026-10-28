<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

/** Optional exact-query click feedback; keys contain no plaintext query. */
final class QueryClickFeedback
{
    public static function keyCheck(string $secret): string
    {
        if ($secret === '') throw new \InvalidArgumentException('A local search key is required.');

        return hash_hmac('sha256', 'shopware-meilisearch/query-click-feedback/v1', $secret);
    }

    public static function fingerprint(string $locale, string $query, string $secret): string
    {
        if ($secret === '' || !preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})+$/D', $locale)) {
            throw new \InvalidArgumentException('A local key and valid locale are required.');
        }
        $query = \Normalizer::normalize($query, \Normalizer::FORM_C);
        if ($query === false) throw new \InvalidArgumentException('Invalid query encoding.');
        $query = mb_strtolower(trim($query), 'UTF-8');
        $query = preg_replace('/\s+/u', ' ', $query);
        if ($query === null || $query === '' || mb_strlen($query, 'UTF-8') > 150) throw new \InvalidArgumentException('Invalid query.');

        return hash_hmac('sha256', $locale . "\0" . $query, $secret);
    }

    /** @return array<string, array<string, array<string, int>>> */
    public static function parse(string $json, string $secret): array
    {
        if (strlen($json) > 20 * 1024 * 1024) throw new \InvalidArgumentException('Query feedback exceeds 20 MB.');
        if (trim($json) === '') return [];
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_string($data['keyCheck'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $data['keyCheck']) || !hash_equals(self::keyCheck($secret), $data['keyCheck'])) {
            throw new \InvalidArgumentException('Query feedback was created with a different local search key or has an invalid format.');
        }
        $locales = $data['locales'] ?? null;
        if (!is_array($locales) || ($locales !== [] && array_is_list($locales))) throw new \InvalidArgumentException('Query feedback must be keyed by locale.');
        foreach ($locales as $locale => $queries) {
            if (!is_string($locale) || !preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})+$/D', $locale)
                || !is_array($queries) || ($queries !== [] && array_is_list($queries)) || count($queries) > 20000) {
                throw new \InvalidArgumentException('Invalid query feedback locale or query map.');
            }
            foreach ($queries as $fingerprint => $products) {
                if (!is_string($fingerprint) || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)
                    || !is_array($products) || ($products !== [] && array_is_list($products)) || count($products) > 5) {
                    throw new \InvalidArgumentException('Invalid query fingerprint or product map.');
                }
                foreach ($products as $id => $weight) {
                    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id) || !is_int($weight) || $weight < 2 || $weight > 1000000) {
                        throw new \InvalidArgumentException('Invalid query feedback product or weight.');
                    }
                }
            }
        }

        return $locales;
    }

    /** Merge by locale, fingerprint and product; repeated imports are idempotent. */
    public static function merge(string $existingJson, string $additionalJson, string $secret): string
    {
        $existing = self::parse($existingJson, $secret);
        $additional = self::parse($additionalJson, $secret);
        foreach ($additional as $locale => $queries) foreach ($queries as $fingerprint => $products) foreach ($products as $id => $weight) {
            $existing[$locale][$fingerprint][$id] = max($existing[$locale][$fingerprint][$id] ?? 0, $weight);
        }
        $json = json_encode(['version' => 1, 'keyCheck' => self::keyCheck($secret), 'locales' => (object) $existing], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::parse($json, $secret); // Reject overflow instead of dropping a product or language.

        return $json;
    }

    /** Keep the first three results; only reorder products already eligible. */
    public static function rerank(array $ids, array $weights, ?array $exactIds = null): array
    {
        if (!$weights || count($ids) <= 3) return $ids;
        if ($exactIds !== null) {
            $exact = array_fill_keys($exactIds, true);
            $precise = array_values(array_filter($ids, static fn (string $id): bool => isset($exact[$id])));
            $similar = array_values(array_filter($ids, static fn (string $id): bool => !isset($exact[$id])));
            return array_merge(self::rerank($precise, $weights), self::rerank($similar, $weights));
        }
        $head = array_slice($ids, 0, 3);
        $tail = array_slice($ids, 3);
        $positions = array_flip($tail);
        $promoted = array_values(array_filter($tail, static fn (string $id): bool => isset($weights[$id])));
        usort($promoted, static fn (string $a, string $b): int => ($weights[$b] <=> $weights[$a]) ?: ($positions[$a] <=> $positions[$b]));
        $promoted = array_slice($promoted, 0, 5);
        $selected = array_fill_keys($promoted, true);

        return array_merge($head, $promoted, array_values(array_filter($tail, static fn (string $id): bool => !isset($selected[$id]))));
    }
}
