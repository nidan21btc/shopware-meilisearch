<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

/** Prefer an explicitly requested exact physical amount among equally matched results. */
final class QuantityRelevance
{
    /** Promote exact quantities only when the product also matches the full text phrase. */
    public static function promoteCombined(array $hits, ?string $key, array $phraseIds): array
    {
        if ($key === null || $phraseIds === []) return $hits;
        $allowed = array_fill_keys($phraseIds, true);
        $exact = []; $remaining = [];
        foreach ($hits as $hit) {
            if (isset($hit['id'], $allowed[$hit['id']]) && ($hit['quantityKey'] ?? null) === $key) $exact[] = $hit;
            else $remaining[] = $hit;
        }
        return array_merge($exact, $remaining);
    }

    public static function reorder(array $hits, ?string $key): array
    {
        if ($key === null || $hits === []) return $hits;
        $buckets = [];
        foreach ($hits as $hit) {
            $details = $hit['_rankingScoreDetails'] ?? [];
            $words = $details['words']['matchingWords'] ?? null;
            $typos = $details['typo']['typoCount'] ?? null;
            // Unknown response formats retain the search engine's order.
            if (!is_int($words) || !is_int($typos)) return $hits;
            $bucketKey = $words . ':' . $typos;
            $last = count($buckets) - 1;
            if ($last < 0 || $buckets[$last]['key'] !== $bucketKey) {
                $buckets[] = ['key' => $bucketKey, 'matching' => [], 'other' => []];
                ++$last;
            }
            $group = ($hit['quantityKey'] ?? null) === $key ? 'matching' : 'other';
            $buckets[$last][$group][] = $hit;
        }
        $result = [];
        foreach ($buckets as $bucket) {
            array_push($result, ...$bucket['matching'], ...$bucket['other']);
        }
        return $result;
    }
}
