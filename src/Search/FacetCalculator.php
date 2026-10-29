<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/** Compute category and manufacturer facets for the same eligible search hits. */
final class FacetCalculator
{
    /**
     * @param SalesChannelRepository<SalesChannelProductCollection> $products
     * @param EntityRepository<ProductManufacturerCollection> $manufacturers
     * @param EntityRepository<CategoryCollection> $categories
     */
    public function __construct(
        private readonly SalesChannelRepository $products,
        private readonly EntityRepository $manufacturers,
        private readonly EntityRepository $categories,
        private readonly SearchCriteria $criteria,
    ) {
    }

    /** @return array{0: array, 1: array} */
    public function calculate(SearchInput $input, bool $hasFilters, array $allCandidates, array $familyMap, array $familyPrimaryIds, array $prices, ?array $preciseIds, bool $hasSimilar, array $unrestrictedCandidates, int $minimumPrecise, SalesChannelContext $context): array
    {
        // The discovery template does not render facets. Avoid aggregating the
        // entire catalog just to show the initial product carousel. Explicit
        // filter requests retain the ordinary facet behavior.
        $manufacturerFacets = [];
        $categoryFacets = [];
        if ($input->query !== '' || $hasFilters) {
            // A facet ignores its own selections (OR within that facet) but must
            // reflect the other facet and the current price range.
            $manufacturerFacets = $this->filteredFacet('manufacturer', 'manufacturerId', $this->manufacturers, $allCandidates, $familyMap, $familyPrimaryIds, $input, $prices, $context);
            $categoryFacets = $this->filteredFacet('category', 'categoriesRo.id', $this->categories, $allCandidates, $familyMap, $familyPrimaryIds, $input, $prices, $context);
            if ($preciseIds !== null && !$input->includeSimilar) {
                // A facet choice may leave fewer precise hits than the threshold,
                // in which case the result view automatically includes similar
                // hits. Conversely another choice can restore the precise view.
                $preciseManufacturers = $hasSimilar ? $manufacturerFacets : $this->filteredFacet('manufacturer', 'manufacturerId', $this->manufacturers, $preciseIds, $familyMap, $familyPrimaryIds, $input, $prices, $context);
                $preciseCategories = $hasSimilar ? $categoryFacets : $this->filteredFacet('category', 'categoriesRo.id', $this->categories, $preciseIds, $familyMap, $familyPrimaryIds, $input, $prices, $context);
                $allManufacturers = $hasSimilar ? $this->filteredFacet('manufacturer', 'manufacturerId', $this->manufacturers, $unrestrictedCandidates, $familyMap, $familyPrimaryIds, $input, $prices, $context) : $manufacturerFacets;
                $allCategories = $hasSimilar ? $this->filteredFacet('category', 'categoriesRo.id', $this->categories, $unrestrictedCandidates, $familyMap, $familyPrimaryIds, $input, $prices, $context) : $categoryFacets;
                $manufacturerFacets = self::precisionFacetCounts($preciseManufacturers, $allManufacturers, $minimumPrecise);
                $categoryFacets = self::precisionFacetCounts($preciseCategories, $allCategories, $minimumPrecise);
            }
        }
        // Hide only this sales channel's navigation root; other categories and
        // the indexed data remain unchanged. Apply after merging both facet sets.
        $navigationRoot = $context->getSalesChannel()->getNavigationCategoryId();
        $categoryFacets = array_values(array_filter($categoryFacets, static fn (array $facet): bool => $facet['id'] !== $navigationRoot));
        return [$manufacturerFacets, $categoryFacets];
    }

    /** Facet counts follow the same short-word threshold as filtered results. */
    public static function precisionFacetCounts(array $precise, array $all, int $threshold): array
    {
        $preciseById = array_column($precise, null, 'id');
        $allById = array_column($all, null, 'id');
        $result = [];
        foreach ($allById + $preciseById as $id => $value) {
            $exact = $preciseById[$id]['count'] ?? 0;
            $value['count'] = $exact >= $threshold || !isset($allById[$id]) ? $exact : $allById[$id]['count'];
            $result[] = $value;
        }
        usort($result, static fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name']));
        return $result;
    }

    /** @param EntityRepository<CategoryCollection>|EntityRepository<ProductManufacturerCollection> $repository */
    private function filteredFacet(string $facet, string $field, EntityRepository $repository, array $candidates, array $families, array $primaryIds, SearchInput $input, array $prices, SalesChannelContext $context): array
    {
        $other = new SearchInput($input->query, $input->page, $input->sort,
            $facet === 'manufacturer' ? [] : $input->manufacturers,
            $facet === 'category' ? [] : $input->categories,
            $input->minPrice, $input->maxPrice, $input->includeSimilar);
        $selected = $facet === 'manufacturer' ? $input->manufacturers : $input->categories;
        $eligible = [];
        if ($candidates !== []) {
            $criteria = (new Criteria($candidates))->setLimit(SearchService::MAX_CANDIDATES);
            $this->criteria->applyAvailability($criteria, $context);
            $this->criteria->applyFilters($criteria, $other);
            $eligible = $this->products->searchIds($criteria, $context)->getIds();
            if ($other->minPrice !== null || $other->maxPrice !== null) $eligible = SearchService::filterByDisplayedPrice($eligible, $prices, $other);
        }
        $parentIds = SearchService::matchedParents($families, $primaryIds, array_fill_keys($eligible, true));
        $visible = SearchService::withoutMatchedParents($eligible, $parentIds);
        $hidden = array_values(array_diff($eligible, $visible));
        $aggregate = function (array $ids) use ($facet, $field, $context): ?TermsResult {
            if ($ids === []) return null; // Criteria([]) would aggregate the entire catalog.
            $criteria = new Criteria($ids);
            $this->criteria->applyAvailability($criteria, $context);
            // The catalog can have more than 100 categories. Truncating a
            // bucket list also makes precise/full facet counts incomparable.
            $criteria->addAggregation(new TermsAggregation($facet, $field, SearchService::MAX_CANDIDATES));
            $result = $this->products->aggregate($criteria, $context)->get($facet);
            return $result instanceof TermsResult ? $result : null;
        };

        $visibleResult = $aggregate($visible);
        if ($facet === 'manufacturer' || $facet === 'category') {
            $counts = [];
            foreach ($visibleResult?->getBuckets() ?? [] as $bucket) {
                $key = $bucket->getKey();
                if (is_string($key) && preg_match('/^[a-f0-9]{32}$/D', $key)) $counts[$key] = $bucket->getCount();
            }
            // A hidden parent reappears for a facet value unless an eligible
            // matching child also has that same manufacturer or category.
            $hiddenSet = array_fill_keys($hidden, true);
            $eligibleSet = array_fill_keys($eligible, true);
            $childrenByParent = [];
            foreach ($families as $child => $parent) {
                if (isset($hiddenSet[$parent], $eligibleSet[$child]) && PartialMatches::hideParent($parent, $child, $primaryIds)) {
                    $childrenByParent[$parent][] = $child;
                }
            }
            $ids = array_keys($hiddenSet);
            foreach ($childrenByParent as $children) array_push($ids, ...$children);
            $memberships = [];
            foreach (array_chunk(array_values(array_unique($ids)), 1000) as $batch) {
                $criteria = (new Criteria($batch))->setLimit(1000);
                if ($facet === 'manufacturer') $criteria->addFields(['id', 'manufacturerId']);
                else $criteria->addAssociation('categoriesRo')->addFields(['id', 'categoriesRo.id']);
                $this->criteria->applyAvailability($criteria, $context);
                foreach ($this->products->search($criteria, $context)->getEntities() as $product) {
                    $values = [];
                    if ($facet === 'manufacturer') {
                        $brand = $product->get('manufacturerId');
                        if (is_string($brand)) $values[] = $brand;
                    } else {
                        foreach ($product->get('categoriesRo') ?? [] as $category) $values[] = $category->getId();
                    }
                    $memberships[$product->getId()] = array_fill_keys($values, true);
                }
            }
            foreach ($childrenByParent as $parent => $children) {
                foreach (array_keys($memberships[$parent] ?? []) as $value) {
                    foreach ($children as $child) if (isset($memberships[$child][$value])) continue 2;
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
            return $this->facetFromCounts($counts, $repository, $context, $selected);
        }

        return $this->facet($visibleResult, $aggregate($hidden), $repository, $context, $selected);
    }

    /** @param EntityRepository<CategoryCollection>|EntityRepository<ProductManufacturerCollection> $repository */
    private function facet(?TermsResult $result, ?TermsResult $supplemental, EntityRepository $repository, SalesChannelContext $context, array $selected = []): array
    {
        if ($result === null && $supplemental === null && $selected === []) {
            return [];
        }
        $counts = [];
        foreach ([$result, $supplemental] as $aggregation) {
            foreach ($aggregation?->getBuckets() ?? [] as $bucket) {
                $key = $bucket->getKey();
                if (is_string($key) && preg_match('/^[a-f0-9]{32}$/D', $key)) {
                    $counts[$key] ??= $bucket->getCount();
                }
            }
        }
        return $this->facetFromCounts($counts, $repository, $context, $selected);
    }

    /** @param EntityRepository<CategoryCollection>|EntityRepository<ProductManufacturerCollection> $repository */
    private function facetFromCounts(array $counts, EntityRepository $repository, SalesChannelContext $context, array $selected): array
    {
        // A selected option must remain removable even if another filter now
        // leaves it with no matching products.
        foreach ($selected as $id) $counts[$id] ??= 0;
        if ($counts === []) {
            return [];
        }
        $criteria = new Criteria(array_keys($counts));
        $criteria->addAssociation('translations');
        $values = [];
        foreach ($repository->search($criteria, $context->getContext())->getEntities() as $entity) {
            if (method_exists($entity, 'getActive') && !$entity->getActive()) {
                continue;
            }
            $name = LanguageText::field($entity, $context->getLanguageId(), 'name');
            if (is_string($name) && trim($name) !== '') {
                $values[] = ['id' => $entity->getId(), 'name' => $name, 'count' => $counts[$entity->getId()]];
            }
        }
        usort($values, static fn (array $a, array $b) => ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name']));

        return $values;
    }
}
