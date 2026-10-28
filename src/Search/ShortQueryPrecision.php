<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

/** Identify weak typo matches for short words without suppressing them silently. */
final class ShortQueryPrecision
{
    public static function exactIds(string $query, array $hits, int $minimumExact = SearchInput::PAGE_SIZE): ?array
    {
        if ($minimumExact < 1 || $minimumExact > SearchInput::PAGE_SIZE) {
            throw new \InvalidArgumentException('Invalid short-query precision threshold.');
        }
        if (!preg_match('/^\p{L}{4}$/uD', $query)) {
            return null;
        }
        $exact = [];
        $hasTypo = false;
        foreach ($hits as $hit) {
            $id = $hit['id'] ?? null;
            $typos = $hit['_rankingScoreDetails']['typo']['typoCount'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id) || !is_int($typos)) {
                return null;
            }
            if ($typos === 0) {
                $exact[] = $id;
            } else {
                $hasTypo = true;
            }
        }

        return $hasTypo && count($exact) >= $minimumExact ? $exact : null;
    }
}
