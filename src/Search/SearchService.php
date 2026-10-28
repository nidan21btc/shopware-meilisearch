<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\DataAbstractionLayer\VariantListingConfig;
use Shopware\Core\Content\Product\SalesChannel\AbstractProductCloseoutFilterFactory;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class SearchService
{
    public const MAX_CANDIDATES = 5000;
    public function __construct(
        private readonly Engine $engine,
        private readonly SalesChannelRepository $products,
        private readonly EntityRepository $manufacturers,
        private readonly EntityRepository $categories,
        private readonly AbstractProductCloseoutFilterFactory $closeoutFilter,
        private readonly ParentTranslations $parents,
        private readonly CurrentLanguageNames $currentNames,
        private readonly IdentifierFallback $identifierFallback,
        private readonly ReferenceUnitLabels $referenceUnitLabels,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function search(SearchInput $input, SalesChannelContext $context): array
    {
        $result = $this->searchOnce($input, $context);
        if ($result['total'] > 0) return $result;

        // A stale, unavailable or filtered index hit is not a visible result.
        // Only try the same-language spelling alternative after all Shopware
        // visibility and customer filters have been applied.
        $alternate = OrthographicFallback::german($input->query, $context->getLanguageInfo()->localeCode);
        if ($alternate === null) return $result;
        try {
            $corrected = new SearchInput($alternate, $input->page, $input->sort, $input->manufacturers,
                $input->categories, $input->minPrice, $input->maxPrice, $input->includeSimilar);
            $fallback = $this->searchOnce($corrected, $context);
            if ($fallback['total'] > 0) {
                $fallback['orthographyFallback'] = true;
                return $fallback;
            }
        } catch (\Throwable) {
            // A failed optional retry must not replace the original response.
        }
        return $result;
    }

    private function searchOnce(SearchInput $input, SalesChannelContext $context): array
    {
        $index = Engine::indexName($context->getSalesChannelId(), $context->getLanguageId());
        // Retrieve IDs and the missing-name marker only. Context-dependent
        // filters, prices and sorting belong to Shopware.
        $needsTypoDetails = $input->query !== '';
        $response = $this->engine->request('POST', '/indexes/' . $index . '/search', [
            'q' => $input->query,
            'page' => 1,
            'hitsPerPage' => self::MAX_CANDIDATES,
            'attributesToRetrieve' => ['id', 'unnamed', 'quantityKey'],
            'showRankingScoreDetails' => $needsTypoDetails,
            // Keep rare distinguishing terms if a multi-word query has no
            // full match.
            'matchingStrategy' => 'frequency',
        ]);
        $candidates = [];
        $quantityKey = QuantityUnits::queryKey($input->query);
        $hits = $response['hits'] ?? [];
        if ($needsTypoDetails) {
            $hits = TypoRelevance::reorder($hits);
            $hits = QuantityRelevance::reorder($hits, $quantityKey);
        }
        // Preserve a precise view for short words while allowing similar hits.
        $minimumPrecise = $context->getLanguageInfo()->localeCode === 'en-GB' && PartialMatches::terms($input->query) === [] ? 1 : SearchInput::PAGE_SIZE;
        $preciseIds = ShortQueryPrecision::exactIds($input->query, $hits, $minimumPrecise)
            ?? MultiwordPrecision::strongIds($input->query, $hits, $minimumPrecise);
        $indexedUnnamedIds = [];
        foreach ($hits as $hit) {
            if (isset($hit['id']) && is_string($hit['id']) && preg_match('/^[a-f0-9]{32}$/D', $hit['id'])) {
                $candidates[] = $hit['id'];
                if ($input->query !== '' && ($hit['unnamed'] ?? false) === true) $indexedUnnamedIds[] = $hit['id'];
            }
        }
        // Relevance metadata can be large for broad queries. Shopware only
        // needs IDs after ordering, so release it before loading product data.
        unset($response['hits'], $hits, $hit);
        // Names can change before the next index swap. Keep nameless entries
        // only when the index explicitly marks their language-local text.
        $unnamed = $this->currentNames->missingCodes($indexedUnnamedIds, $context);
        $allowed = array_fill_keys($this->currentNames->keep($candidates, $context), true) + $unnamed;
        $candidates = array_values(array_filter($candidates, static fn (string $id): bool => isset($allowed[$id])));
        $directUnnamed = [];
        if (IdentifierFallback::isCode($input->query)) {
            try {
                $directUnnamed = $this->identifierFallback->find($input->query, $index, $context);
                $unnamed += $directUnnamed;
                $candidates = array_values(array_unique(array_merge(array_keys($directUnnamed), $candidates)));
            } catch (\Throwable $error) {
                // An optional exact-code lookup must not interrupt indexed search.
                $this->logger->warning('Local exact identifier lookup was skipped', ['exceptionClass' => $error::class]);
            }
        }
        $extraTruncated = count($candidates) > self::MAX_CANDIDATES;
        if ($extraTruncated) {
            $candidates = array_slice($candidates, 0, self::MAX_CANDIDATES);
            $unnamed = array_intersect_key($unnamed, array_fill_keys($candidates, true));
        }
        $primaryIds = array_fill_keys($candidates, true);
        if ($preciseIds !== null) {
            $preciseIds = array_values(array_filter($preciseIds, static fn (string $id): bool => isset($primaryIds[$id])));
            if (count($preciseIds) < $minimumPrecise) $preciseIds = null;
        }
        $broadened = false;
        $terms = PartialMatches::terms($input->query);
        $quantityExpansion = false;
        if ($terms === [] && ($phrase = PartialMatches::withoutTrailingQuantity($input->query)) !== null) {
            try {
                // Inspect the published index, not a configuration change that
                // may still be waiting for an index rebuild.
                $searchable = $this->engine->request('GET', '/indexes/' . $index . '/settings/searchable-attributes');
                if (is_array($searchable) && !in_array('*', $searchable, true) && !in_array('quantity', $searchable, true)) {
                    $terms = [$phrase];
                    $quantityExpansion = true;
                }
            } catch (\Throwable $error) {
                $this->logger->warning('Local quantity fallback was skipped', ['exceptionClass' => $error::class]);
            }
        }
        if ($terms !== [] && count($candidates) >= SearchInput::PAGE_SIZE) {
            try {
                // Raw index counts include stale, filtered and duplicate
                // variant-family hits. Only suppress the optional expansion
                // when a full page is actually visible in this context.
                if (!$this->needsPartialExpansion($candidates, $primaryIds, $input, $context)) $terms = [];
            } catch (\Throwable $error) {
                $this->logger->warning('Local partial-match preflight was skipped', ['exceptionClass' => $error::class]);
                $terms = [];
            }
        }
        if ($terms !== []) {
            $queries = array_map(static fn (string $term): array => [
                'indexUid' => $index, 'q' => $term, 'limit' => self::MAX_CANDIDATES,
                'attributesToRetrieve' => ['id', 'unnamed'], 'matchingStrategy' => $quantityExpansion ? 'all' : 'frequency',
            ], $terms);
            try {
                $partial = $this->engine->request('POST', '/multi-search', ['queries' => $queries]);
                $lists = array_map(static fn (array $result): array => $result['hits'] ?? [], $partial['results'] ?? []);
                // Filter before selecting each term's first page: otherwise
                // twenty matches in another manufacturer can hide the first
                // matching product in the customer's selected manufacturer.
                $wide = PartialMatches::append([], $lists, self::MAX_CANDIDATES);
                $extraTruncated = $extraTruncated || count($wide) > self::MAX_CANDIDATES
                    || array_filter($lists, static fn (array $hits): bool => count($hits) >= self::MAX_CANDIDATES) !== [];
                $addedIds = array_slice(array_values(array_diff($wide, $candidates)), 0, self::MAX_CANDIDATES);
                $flagged = [];
                foreach ($lists as $list) foreach ($list as $hit) {
                    if (($hit['unnamed'] ?? false) === true && isset($hit['id']) && is_string($hit['id']) && preg_match('/^[a-f0-9]{32}$/D', $hit['id'])) $flagged[] = $hit['id'];
                }
                $extraUnnamed = $this->currentNames->missingCodes(array_values(array_intersect($addedIds, $flagged)), $context);
                $extraAllowed = array_fill_keys($this->currentNames->keep($addedIds, $context), true) + $extraUnnamed;
                $valid = array_values(array_filter($addedIds, static fn (string $id): bool => isset($extraAllowed[$id])));
                $eligible = array_fill_keys($this->eligiblePartialIds($valid, $input, $context), true);
                $filteredLists = array_map(static fn (array $hits): array => array_values(array_filter($hits,
                    static fn (array $hit): bool => isset($hit['id']) && isset($eligible[$hit['id']]))), $lists);
                $perTerm = SearchInput::PAGE_SIZE;
                do {
                    $extended = PartialMatches::append($candidates, $filteredLists, $perTerm);
                    if (count($extended) - count($candidates) >= count($eligible)
                        || count($extended) >= self::MAX_CANDIDATES || $perTerm >= self::MAX_CANDIDATES
                        || !$this->needsPartialExpansion($extended, $primaryIds, $input, $context)) break;
                    // Parent/variant pairs can reduce twenty eligible hits to
                    // fewer cards; look further only while the page is sparse.
                    $perTerm = min(self::MAX_CANDIDATES, $perTerm * 2);
                } while (true);
                // Keep the previous unfiltered sample for facets that ignore
                // their own selection, including choices outside this filter.
                $samples = array_map(static fn (array $hits): array => array_values(array_filter(array_slice($hits, 0, SearchInput::PAGE_SIZE),
                    static fn (array $hit): bool => isset($hit['id']) && isset($extraAllowed[$hit['id']]))), $lists);
                $extended = PartialMatches::append($extended, $samples);
                $additional = array_slice($extended, count($candidates), max(0, self::MAX_CANDIDATES - count($candidates)));
                if (count($extended) > self::MAX_CANDIDATES) $extraTruncated = true;
                $unnamed += array_intersect_key($extraUnnamed, array_fill_keys($additional, true));
                $broadened = $additional !== [];
                $candidates = array_merge($candidates, $additional);
            } catch (\Throwable) {
                // The original search remains usable if the optional expansion fails.
            }
        }
        $featured = [];
        if ($input->query === '') {
            $configured = Discovery::featured($this->config->getString('ShopwareMeilisearch.config.featuredProductIds', $context->getSalesChannelId()), $context->getLanguageInfo()->localeCode);
            $featured = array_values(array_intersect($configured, $candidates));
            $candidates = array_values(array_unique(array_merge($featured, $candidates)));
        }
        $empty = ['products' => [], 'names' => [], 'productManufacturers' => [], 'unnamed' => [], 'variantQuantities' => [], 'variantOptions' => [], 'variantNumbers' => [], 'referenceUnits' => [], 'manufacturers' => [], 'categories' => [], 'total' => 0, 'page' => $input->page, 'pages' => 0, 'priceRange' => null, 'truncated' => false, 'featured' => false, 'broadened' => false, 'quantityFallback' => false, 'orthographyFallback' => false, 'hasSimilar' => false, 'facetCountsMayChange' => false];
        if ($candidates === []) {
            return $empty;
        }

        // A parent and its individually purchasable variants can all match the
        // same query. The reference shows the variants, not an extra parent
        // card. Keep a parent when no visible variant matched (e.g. an exact
        // parent article number), rather than hiding it unconditionally.
        $families = new Criteria($candidates);
        $families->setLimit(self::MAX_CANDIDATES)->addFields(['id', 'parentId']);
        $this->applyAvailability($families, $context);
        $familyMap = [];
        foreach ($this->products->search($families, $context)->getEntities() as $product) {
            $parentId = $product->get('parentId');
            if ($parentId !== null) {
                $familyMap[$product->getId()] = $parentId;
            }
        }
        // A typo-only variant must not hide a parent that matched exactly,
        // even after the customer elects to show similar results.
        $familyPrimaryIds = $preciseIds !== null ? array_fill_keys($preciseIds, true) : ($directUnnamed !== [] ? array_fill_keys(array_keys($directUnnamed), true) : $primaryIds);
        $parentIds = self::matchedParents($familyMap, $familyPrimaryIds);
        $filteredParentIds = $parentIds;
        $hasPriceFilter = $input->minPrice !== null || $input->maxPrice !== null;
        $priceSort = in_array($input->sort, ['price-asc', 'price-desc'], true);
        // CheapestPrice is a family price and can differ from the price shown
        // on an individual variant. Calculate the displayed prices in the
        // current sales-channel context before filtering or sorting by price.
        $displayedPrices = $hasPriceFilter || $priceSort ? $this->displayedPrices($candidates, $context) : null;
        $hasFilters = $input->manufacturers !== [] || $input->categories !== [] || $input->minPrice !== null || $input->maxPrice !== null;
        $eligibleSet = null;
        if ($hasFilters) {
            $eligibleCriteria = new Criteria($candidates);
            $eligibleCriteria->setLimit(self::MAX_CANDIDATES);
            $this->applyAvailability($eligibleCriteria, $context);
            $this->applyFilters($eligibleCriteria, $input);
            $eligibleIds = $this->products->searchIds($eligibleCriteria, $context)->getIds();
            if ($hasPriceFilter) $eligibleIds = self::filterByDisplayedPrice($eligibleIds, $displayedPrices ?? [], $input);
            $eligibleSet = array_fill_keys($eligibleIds, true);
            $filteredParentIds = self::matchedParents($familyMap, $familyPrimaryIds, $eligibleSet);
        }
        $unrestrictedCandidates = $candidates;
        $hasSimilar = false;
        if ($preciseIds !== null) {
            $preciseCriteria = new Criteria($preciseIds);
            $preciseCriteria->setLimit(self::MAX_CANDIDATES);
            $this->applyAvailability($preciseCriteria, $context);
            $this->applyFilters($preciseCriteria, $input);
            $eligible = $this->products->searchIds($preciseCriteria, $context)->getIds();
            if ($hasPriceFilter) $eligible = self::filterByDisplayedPrice($eligible, $displayedPrices ?? [], $input);
            $preciseSet = array_fill_keys($preciseIds, true);
            $preciseFamilies = array_intersect_key($familyMap, $preciseSet);
            $preciseParents = self::matchedParents($preciseFamilies, $familyPrimaryIds);
            $filteredPreciseParents = self::matchedParents($preciseFamilies, $familyPrimaryIds, array_fill_keys($eligible, true));
            $hasSimilar = count(self::withoutMatchedParents($eligible, $filteredPreciseParents)) >= $minimumPrecise;
            if ($hasSimilar && !$input->includeSimilar) {
                $candidates = array_values(array_filter($candidates, static fn (string $id): bool => isset($preciseSet[$id])));
                $parentIds = $preciseParents;
                $filteredParentIds = $filteredPreciseParents;
            }
        }
        $allCandidates = $candidates;
        $facetCandidates = self::withoutMatchedParents($candidates, $parentIds);
        $candidates = self::withoutMatchedParents($candidates, $filteredParentIds);
        $featured = array_values(array_intersect($featured, $candidates));
        if ($facetCandidates === [] && $candidates === []) {
            return $empty;
        }

        // A facet ignores its own selections (OR within that facet) but must
        // reflect the other facet and the current price range.
        $manufacturerFacets = $this->filteredFacet('manufacturer', 'manufacturerId', $this->manufacturers, $allCandidates, $familyMap, $familyPrimaryIds, $input, $displayedPrices ?? [], $context);
        $categoryFacets = $this->filteredFacet('category', 'categoriesRo.id', $this->categories, $allCandidates, $familyMap, $familyPrimaryIds, $input, $displayedPrices ?? [], $context);
        if ($preciseIds !== null && !$input->includeSimilar) {
            // A facet choice may leave fewer precise hits than the threshold,
            // in which case the result view automatically includes similar
            // hits. Conversely another choice can restore the precise view.
            $preciseManufacturers = $hasSimilar ? $manufacturerFacets : $this->filteredFacet('manufacturer', 'manufacturerId', $this->manufacturers, $preciseIds, $familyMap, $familyPrimaryIds, $input, $displayedPrices ?? [], $context);
            $preciseCategories = $hasSimilar ? $categoryFacets : $this->filteredFacet('category', 'categoriesRo.id', $this->categories, $preciseIds, $familyMap, $familyPrimaryIds, $input, $displayedPrices ?? [], $context);
            $allManufacturers = $hasSimilar ? $this->filteredFacet('manufacturer', 'manufacturerId', $this->manufacturers, $unrestrictedCandidates, $familyMap, $familyPrimaryIds, $input, $displayedPrices ?? [], $context) : $manufacturerFacets;
            $allCategories = $hasSimilar ? $this->filteredFacet('category', 'categoriesRo.id', $this->categories, $unrestrictedCandidates, $familyMap, $familyPrimaryIds, $input, $displayedPrices ?? [], $context) : $categoryFacets;
            $manufacturerFacets = self::precisionFacetCounts($preciseManufacturers, $allManufacturers, $minimumPrecise);
            $categoryFacets = self::precisionFacetCounts($preciseCategories, $allCategories, $minimumPrecise);
        }
        // Hide only this sales channel's navigation root; other categories and
        // the indexed data remain unchanged. Apply after merging both facet sets.
        $navigationRoot = $context->getSalesChannel()->getNavigationCategoryId();
        $categoryFacets = array_values(array_filter($categoryFacets, static fn (array $facet): bool => $facet['id'] !== $navigationRoot));
        $criteria = new Criteria($candidates);
        $this->applyAvailability($criteria, $context);
        $this->applyFilters($criteria, $input);
        $criteria->setLimit(self::MAX_CANDIDATES);
        $visibleIds = $this->products->searchIds($criteria, $context)->getIds();
        if ($hasPriceFilter) $visibleIds = self::filterByDisplayedPrice($visibleIds, $displayedPrices ?? [], $input);
        if ($input->sort === 'relevance') {
            $positions = array_flip($candidates);
            usort($visibleIds, static fn (string $a, string $b) => $positions[$a] <=> $positions[$b]);
        } elseif ($input->sort === 'name-asc') {
            // Sort the labels actually shown, with this language's collation.
            // The database's shared collation can order letters differently,
            // and Shopware can fall back to another language for missing names.
            $displayNames = $this->currentNames->names($visibleIds, $context) + $unnamed;
            $visibleIds = self::sortByDisplayName($visibleIds, $displayNames, $context->getLanguageInfo()->localeCode);
        } elseif ($priceSort) {
            $direction = $input->sort === 'price-desc' ? -1 : 1;
            usort($visibleIds, static function (string $a, string $b) use ($displayedPrices, $direction): int {
                if (!isset($displayedPrices[$a])) return isset($displayedPrices[$b]) ? 1 : strcmp($a, $b);
                if (!isset($displayedPrices[$b])) return -1;
                return $direction * ($displayedPrices[$a] <=> $displayedPrices[$b]) ?: strcmp($a, $b);
            });
        }
        $total = count($visibleIds);
        $visibleQuantityFallback = false;
        if ($quantityExpansion && $broadened) {
            foreach ($visibleIds as $id) {
                if (!isset($primaryIds[$id])) { $visibleQuantityFallback = true; break; }
            }
        }
        $pages = (int) ceil($total / SearchInput::PAGE_SIZE);
        $page = min($input->page, max(1, $pages));
        $pageIds = array_slice($visibleIds, ($page - 1) * SearchInput::PAGE_SIZE, SearchInput::PAGE_SIZE);
        $products = [];
        $names = [];
        $productManufacturers = [];
        $variantQuantities = [];
        $variantOptions = [];
        $variantNumbers = [];
        try {
            $quantityUnits = QuantityUnits::parse($this->config->getString('ShopwareMeilisearch.config.quantityUnits', $context->getSalesChannelId()));
        } catch (\Throwable) {
            $quantityUnits = [];
        }
        if ($pageIds !== []) {
            $hydrate = new Criteria($pageIds);
            $this->applyAvailability($hydrate, $context);
            foreach (['translations', 'cover.media', 'manufacturer.translations', 'options.group', 'options.translations', 'unit.translations'] as $association) {
                $hydrate->addAssociation($association);
            }
            $entities = $this->products->search($hydrate, $context)->getEntities();
            $this->parents->load($entities, $context);
            $entities->sortByIdArray($pageIds);
            foreach ($entities as $product) {
                $name = LanguageText::field($product, $context->getLanguageId(), 'name');
                if ((!is_string($name) || trim($name) === '') && isset($unnamed[$product->getId()])) {
                    $name = $unnamed[$product->getId()];
                }
                if (is_string($name) && trim($name) !== '') {
                    $products[] = $product;
                    $names[$product->getId()] = $name;
                    $manufacturer = LanguageText::field($product->getManufacturer(), $context->getLanguageId(), 'name');
                    if (is_string($manufacturer) && trim($manufacturer) !== '') $productManufacturers[$product->getId()] = trim($manufacturer);
                    $quantity = VariantQuantityLabel::forProduct($product, $name, $context->getLanguageId(), $context->getLanguageInfo()->localeCode, $quantityUnits);
                    if ($quantity !== null) $variantQuantities[$product->getId()] = $quantity;
                    $options = VariantOptionLabels::forProduct($product, $name, $context->getLanguageId(), $quantity);
                    if ($options['labels'] !== []) $variantOptions[$product->getId()] = $options['labels'];
                    if ($options['showNumber']) $variantNumbers[$product->getId()] = $product->getProductNumber();
                }
            }
        }
        // Equal visible labels can still occur for siblings with missing or
        // duplicate options. Give both cards their unique article numbers.
        $variantNumbers = VariantOptionLabels::addNumbersForCollisions($products, $names, $variantQuantities, $variantOptions, $variantNumbers);
        // Compute bounds only from the selected precise or expanded result
        // set. Prices for hidden parents remain available for later filters.
        $rangePrices = $displayedPrices === null ? [] : array_intersect_key($displayedPrices, array_fill_keys($allCandidates, true));

        return [
            'products' => $products,
            'names' => $names,
            'productManufacturers' => $productManufacturers,
            'unnamed' => $unnamed,
            'variantQuantities' => $variantQuantities,
            'variantOptions' => $variantOptions,
            'variantNumbers' => $variantNumbers,
            'referenceUnits' => $this->referenceUnitLabels->forProducts($products, $context),
            'manufacturers' => $manufacturerFacets,
            'categories' => $categoryFacets,
            'total' => $total, 'page' => $page, 'pages' => $pages,
            'priceRange' => $rangePrices !== [] ? ['min' => min($rangePrices), 'max' => max($rangePrices)] : null,
            'truncated' => $extraTruncated || ($response['totalHits'] ?? 0) >= self::MAX_CANDIDATES,
            'featured' => $featured !== [],
            'broadened' => $quantityExpansion ? $visibleQuantityFallback : $broadened,
            'quantityFallback' => $visibleQuantityFallback,
            'orthographyFallback' => false,
            'hasSimilar' => $hasSimilar,
            // Multiword partial expansion can change after a filter is
            // selected, so its existing facet counts are not fixed totals.
            'facetCountsMayChange' => PartialMatches::terms($input->query) !== [] || $quantityExpansion,
        ];
    }

    public static function withoutMatchedParents(array $candidates, array $parentIds): array
    {
        $parents = array_fill_keys($parentIds, true);

        return array_values(array_filter($candidates, static fn (string $id) => !isset($parents[$id])));
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

    /** Sort by the actual displayed labels, including untranslated article numbers. */
    public static function sortByDisplayName(array $ids, array $names, string $locale): array
    {
        $collator = new \Collator($locale);
        usort($ids, static function (string $a, string $b) use ($names, $collator): int {
            $left = $names[$a] ?? '';
            $right = $names[$b] ?? '';
            $comparison = $collator->compare($left, $right);
            return ($comparison === false ? strcmp($left, $right) : $comparison) ?: strcmp($a, $b);
        });

        return $ids;
    }

    /** Only an eligible matching variant may hide its parent in filtered results. */
    public static function matchedParents(array $families, array $primaryIds, ?array $eligible = null): array
    {
        $parents = [];
        foreach ($families as $child => $parent) {
            if (($eligible === null || isset($eligible[$child])) && PartialMatches::hideParent($parent, $child, $primaryIds)) {
                $parents[] = $parent;
            }
        }

        return $parents;
    }

    private function applyFilters(Criteria $criteria, SearchInput $input): void
    {
        if ($input->manufacturers !== []) {
            $criteria->addFilter(new EqualsAnyFilter('manufacturerId', $input->manufacturers));
        }
        if ($input->categories !== []) {
            $criteria->addFilter(new EqualsAnyFilter('categoriesRo.id', $input->categories));
        }
    }

    /** @return array<string, float> */
    private function displayedPrices(array $ids, SalesChannelContext $context): array
    {
        $prices = [];
        foreach (array_chunk($ids, 200) as $batch) {
            $criteria = new Criteria($batch);
            $criteria->addFields(['id', 'parentId', 'childCount', 'variantListingConfig', 'calculatedPrice', 'calculatedPrices', 'calculatedCheapestPrice',
                'prices.ruleId', 'prices.price', 'prices.quantityStart', 'prices.quantityEnd']);
            $this->applyAvailability($criteria, $context);
            foreach ($this->products->search($criteria, $context)->getEntities() as $product) {
                // A field-limited DAL query returns PartialEntity instances;
                // access calculated values through Entity::get in both cases.
                $calculated = $product->get('calculatedPrices');
                $price = ($calculated instanceof PriceCollection ? $calculated->last() : null) ?? $product->get('calculatedPrice');
                $listing = $product->get('variantListingConfig');
                $cheapest = $product->get('calculatedCheapestPrice');
                if ($product->get('parentId') === null && $listing instanceof VariantListingConfig && $listing->getDisplayParent() && $cheapest instanceof CalculatedPrice) {
                    $price = $cheapest;
                }
                if (!$price instanceof CalculatedPrice) throw new \RuntimeException('Shopware did not calculate a product price.');
                $prices[$product->getId()] = $price->getUnitPrice();
            }
        }

        return $prices;
    }

    public static function filterByDisplayedPrice(array $ids, array $prices, SearchInput $input): array
    {
        return array_values(array_filter($ids, static function (string $id) use ($prices, $input): bool {
            if (!array_key_exists($id, $prices)) return false;
            $price = $prices[$id];
            return ($input->minPrice === null || $price >= $input->minPrice)
                && ($input->maxPrice === null || $price <= $input->maxPrice);
        }));
    }

    private function needsPartialExpansion(array $ids, array $primaryIds, SearchInput $input, SalesChannelContext $context): bool
    {
        $criteria = (new Criteria($ids))->setLimit(self::MAX_CANDIDATES)->addFields(['id', 'parentId']);
        $this->applyAvailability($criteria, $context);
        $this->applyFilters($criteria, $input);
        $eligible = [];
        $families = [];
        foreach ($this->products->search($criteria, $context)->getEntities() as $product) {
            $id = $product->getId();
            $eligible[] = $id;
            $parent = $product->get('parentId');
            if ($parent !== null) $families[$id] = $parent;
        }
        if ($input->minPrice !== null || $input->maxPrice !== null) {
            $eligible = self::filterByDisplayedPrice($eligible, $this->displayedPrices($eligible, $context), $input);
        }
        $parents = self::matchedParents($families, $primaryIds, array_fill_keys($eligible, true));

        return count(self::withoutMatchedParents($eligible, $parents)) < SearchInput::PAGE_SIZE;
    }

    /** @param list<string> $ids @return list<string> */
    private function eligiblePartialIds(array $ids, SearchInput $input, SalesChannelContext $context): array
    {
        $eligible = [];
        foreach (array_chunk($ids, 1000) as $batch) {
            $criteria = (new Criteria($batch))->setLimit(1000);
            $this->applyAvailability($criteria, $context);
            $this->applyFilters($criteria, $input);
            $eligible = array_merge($eligible, $this->products->searchIds($criteria, $context)->getIds());
        }
        if ($input->minPrice !== null || $input->maxPrice !== null) {
            $eligible = self::filterByDisplayedPrice($eligible, $this->displayedPrices($eligible, $context), $input);
        }

        return $eligible;
    }

    private function applyAvailability(Criteria $criteria, SalesChannelContext $context): void
    {
        $criteria->addFilter(new ProductAvailableFilter($context->getSalesChannelId(), ProductVisibilityDefinition::VISIBILITY_SEARCH));
        if ($this->config->getBool('core.listing.hideCloseoutProductsWhenOutOfStock', $context->getSalesChannelId())) {
            $criteria->addFilter($this->closeoutFilter->create($context));
        }
    }

    private function filteredFacet(string $facet, string $field, EntityRepository $repository, array $candidates, array $families, array $primaryIds, SearchInput $input, array $prices, SalesChannelContext $context): array
    {
        $other = new SearchInput($input->query, $input->page, $input->sort,
            $facet === 'manufacturer' ? [] : $input->manufacturers,
            $facet === 'category' ? [] : $input->categories,
            $input->minPrice, $input->maxPrice, $input->includeSimilar);
        $selected = $facet === 'manufacturer' ? $input->manufacturers : $input->categories;
        $eligible = [];
        if ($candidates !== []) {
            $criteria = (new Criteria($candidates))->setLimit(self::MAX_CANDIDATES);
            $this->applyAvailability($criteria, $context);
            $this->applyFilters($criteria, $other);
            $eligible = $this->products->searchIds($criteria, $context)->getIds();
            if ($other->minPrice !== null || $other->maxPrice !== null) $eligible = self::filterByDisplayedPrice($eligible, $prices, $other);
        }
        $parentIds = self::matchedParents($families, $primaryIds, array_fill_keys($eligible, true));
        $visible = self::withoutMatchedParents($eligible, $parentIds);
        $hidden = array_values(array_diff($eligible, $visible));
        $aggregate = function (array $ids) use ($facet, $field, $context): ?TermsResult {
            if ($ids === []) return null; // Criteria([]) would aggregate the entire catalog.
            $criteria = new Criteria($ids);
            $this->applyAvailability($criteria, $context);
            // The catalog can have more than 100 categories. Truncating a
            // bucket list also makes precise/full facet counts incomparable.
            $criteria->addAggregation(new TermsAggregation($facet, $field, self::MAX_CANDIDATES));
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
                $this->applyAvailability($criteria, $context);
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
