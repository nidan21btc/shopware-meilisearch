<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/** Shopware does not permit fetching the parent association through the DAL. */
final class ParentTranslations
{
    public function __construct(private readonly EntityRepository $products)
    {
    }

    public function load(iterable $products, SalesChannelContext $context): void
    {
        $parents = [];
        foreach ($products as $product) {
            if ($product->getParentId() !== null) {
                $parents[] = $product->getParentId();
            }
        }
        if ($parents === []) {
            return;
        }
        $criteria = new Criteria(array_values(array_unique($parents)));
        $criteria->addAssociation('translations');
        $entities = $this->products->search($criteria, $context->getContext())->getEntities();
        foreach ($products as $product) {
            $parent = $product->getParentId() === null ? null : $entities->get($product->getParentId());
            if ($parent instanceof ProductEntity) {
                $product->setParent($parent);
            }
        }
    }
}
