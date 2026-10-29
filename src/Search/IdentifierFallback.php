<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\SalesChannel\AbstractProductCloseoutFilterFactory;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductCollection;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/** Find exact, configured language-neutral codes without borrowing a product name. */
final class IdentifierFallback
{
    /** @param SalesChannelRepository<SalesChannelProductCollection> $products */
    public function __construct(private readonly Engine $engine, private readonly SalesChannelRepository $products,
        private readonly ParentTranslations $parents, private readonly AbstractProductCloseoutFilterFactory $closeoutFilter,
        private readonly SystemConfigService $config)
    {
    }

    public static function isCode(string $query): bool
    {
        return (bool) preg_match('/^(?=.*[0-9])[\p{L}\p{M}\p{N}._\/-]{1,64}$/uD', $query);
    }

    public static function exactCode(string $query, ?string $value): bool
    {
        if ($value === null) return false;
        $left = \Normalizer::normalize($query, \Normalizer::FORM_C);
        $right = \Normalizer::normalize($value, \Normalizer::FORM_C);
        return is_string($left) && is_string($right)
            && mb_strtolower($left, 'UTF-8') === mb_strtolower($right, 'UTF-8');
    }

    /** @return array<string, string> Product ID to language-neutral article number. */
    public function find(string $query, string $index, SalesChannelContext $context, ?array $publishedFields = null): array
    {
        if (!self::isCode($query)) return [];
        // Read the published index settings: a pending config change must not
        // enable searching an identifier excluded from the current index.
        $searchable = $publishedFields ?? $this->engine->request('GET', '/indexes/' . $index . '/settings/searchable-attributes');
        $fields = array_values(array_intersect(['productNumber', 'ean', 'manufacturerNumber'], $searchable ?? []));
        if ($fields === []) return [];
        $criteria = (new Criteria())->setLimit(SearchService::MAX_CANDIDATES);
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, array_map(static fn (string $field) => new EqualsFilter($field, $query), $fields)));
        $criteria->addFilter(new ProductAvailableFilter($context->getSalesChannelId(), ProductVisibilityDefinition::VISIBILITY_SEARCH));
        if ($this->config->getBool('core.listing.hideCloseoutProductsWhenOutOfStock', $context->getSalesChannelId())) {
            $criteria->addFilter($this->closeoutFilter->create($context));
        }
        $criteria->addAssociation('translations');
        $entities = $this->products->search($criteria, $context)->getEntities();
        $this->parents->load($entities, $context);
        $result = [];
        foreach ($entities as $product) {
            $name = LanguageText::field($product, $context->getLanguageId(), 'name');
            $number = $product->getProductNumber();
            $values = ['productNumber' => $number, 'ean' => $product->getEan(), 'manufacturerNumber' => $product->getManufacturerNumber()];
            $exact = false;
            foreach ($fields as $field) {
                if (self::exactCode($query, $values[$field])) { $exact = true; break; }
            }
            // Database collations can be accent-insensitive. Check the actual
            // selected identifier again before adding an untranslated product.
            if ($exact && (!is_string($name) || trim($name) === '') && trim($number) !== '') {
                $result[$product->getId()] = $number;
            }
        }

        return $result;
    }
}
