<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final readonly class SearchInput
{
    public const PAGE_SIZE = 20;

    public function __construct(
        public string $query,
        public int $page,
        public string $sort,
        public array $manufacturers,
        public array $categories,
        public ?float $minPrice,
        public ?float $maxPrice,
        public bool $includeSimilar,
    ) {
    }

    public static function fromRequest(Request $request, bool $defaultIncludeSimilar = false): self
    {
        $query = $request->query->getString('q');
        if (!mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 150) {
            throw new BadRequestHttpException('Invalid search query.');
        }
        // The same accented character can arrive as one code point or as a
        // letter followed by a combining mark. Normalize before applying
        // word-length and spelling rules so both forms behave identically.
        $query = \Normalizer::normalize($query, \Normalizer::FORM_C);
        if ($query === false) {
            throw new BadRequestHttpException('Invalid search query.');
        }
        $sort = $request->query->getString('sort', 'relevance');
        if (!in_array($sort, ['relevance', 'price-asc', 'price-desc', 'name-asc'], true)) {
            throw new BadRequestHttpException('Invalid sorting.');
        }
        if ($request->query->has('minPrice') || $request->query->has('maxPrice')) throw new BadRequestHttpException('Price range filters are not supported.');
        $min = null;
        $max = null;

        return new self(
            trim($query), max(1, min(500, $request->query->getInt('page', 1))), $sort,
            self::ids($request->query->all('manufacturer')),
            self::ids($request->query->all('category')), $min, $max,
            $request->query->has('similar') ? $request->query->getString('similar') === '1' : ($defaultIncludeSimilar || PartialMatches::terms($query) !== []),
        );
    }

    /** The navigation root is not an actionable category filter. */
    public function withoutCategory(string $id): self
    {
        if (!in_array($id, $this->categories, true)) return $this;
        return new self($this->query, $this->page, $this->sort, $this->manufacturers,
            array_values(array_filter($this->categories, static fn (string $category): bool => $category !== $id)),
            $this->minPrice, $this->maxPrice, $this->includeSimilar);
    }

    private static function ids(array $values): array
    {
        if (count($values) > 20) {
            throw new BadRequestHttpException('Too many filters.');
        }
        foreach ($values as $value) {
            if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/D', $value)) {
                throw new BadRequestHttpException('Invalid filter.');
            }
        }

        return array_values(array_unique($values));
    }

}
