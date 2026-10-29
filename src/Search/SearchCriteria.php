<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\SalesChannel\AbstractProductCloseoutFilterFactory;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/** Apply the same visibility and selected filters to results and facets. */
final class SearchCriteria
{
    public function __construct(private readonly AbstractProductCloseoutFilterFactory $closeoutFilter, private readonly SystemConfigService $config)
    {
    }

    public function applyFilters(Criteria $criteria, SearchInput $input): void
    {
        if ($input->manufacturers !== []) {
            $criteria->addFilter(new EqualsAnyFilter('manufacturerId', $input->manufacturers));
        }
        if ($input->categories !== []) {
            $criteria->addFilter(new EqualsAnyFilter('categoriesRo.id', $input->categories));
        }
    }

    public function applyAvailability(Criteria $criteria, SalesChannelContext $context): void
    {
        $criteria->addFilter(new ProductAvailableFilter($context->getSalesChannelId(), ProductVisibilityDefinition::VISIBILITY_SEARCH));
        if ($this->config->getBool('core.listing.hideCloseoutProductsWhenOutOfStock', $context->getSalesChannelId())) {
            $criteria->addFilter($this->closeoutFilter->create($context));
        }
    }


}
