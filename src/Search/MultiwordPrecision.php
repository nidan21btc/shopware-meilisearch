<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

/** Allow an optional narrower view when many multiword hits need two typos. */
final class MultiwordPrecision
{
    /** @return list<string>|null */
    public static function strongIds(string $query, array $hits, int $minimum = SearchInput::PAGE_SIZE): ?array
    {
        if ($minimum < 1 || $minimum > SearchInput::PAGE_SIZE) throw new \InvalidArgumentException('Invalid precision threshold.');
        if (PartialMatches::terms($query) === []) return null;
        $strong = [];
        $weaker = false;
        foreach ($hits as $hit) {
            $id = $hit['id'] ?? null;
            $typos = $hit['_rankingScoreDetails']['typo']['typoCount'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id) || !is_int($typos) || $typos < 0) return null;
            if ($typos <= 1) $strong[] = $id;
            else $weaker = true;
        }
        return $weaker && count($strong) >= $minimum ? $strong : null;
    }
}
