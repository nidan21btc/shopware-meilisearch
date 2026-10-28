<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Subscriber;

use ShopwareMeilisearch\Search\DirtyState;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigMultipleChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class IndexInvalidationSubscriber implements EventSubscriberInterface
{
    private const ENTITIES = [
        'product', 'product_translation', 'product_visibility', 'product_category', 'product_category_tree',
        'product_property', 'product_option', 'product_manufacturer', 'product_manufacturer_translation',
        'category', 'category_translation', 'property_group_option', 'property_group_option_translation',
        'unit', 'unit_translation', 'delivery_time', 'delivery_time_translation',
        'sales_channel', 'sales_channel_domain', 'sales_channel_language', 'language', 'locale',
    ];
    private const INDEX_CONFIG = ['enabled', 'indexFields', 'indexFieldsByLanguage', 'quantityUnits', 'synonyms', 'popularityWeights'];

    public function __construct(private readonly DirtyState $state, private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [EntityWrittenContainerEvent::class => 'written', SystemConfigMultipleChangedEvent::class => 'configChanged'];
    }

    public function written(EntityWrittenContainerEvent $event): void
    {
        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }
        foreach (self::ENTITIES as $entity) {
            if ($event->getEventByEntityName($entity) !== null) {
                $this->mark();
                return;
            }
        }
    }

    public function configChanged(SystemConfigMultipleChangedEvent $event): void
    {
        foreach (array_keys($event->getConfig()) as $key) {
            if (str_starts_with($key, 'ShopwareMeilisearch.config.')
                && in_array(substr($key, strlen('ShopwareMeilisearch.config.')), self::INDEX_CONFIG, true)) {
                $this->mark();
                return;
            }
        }
    }

    private function mark(): void
    {
        try {
            $this->state->markDirty();
        } catch (\Throwable $error) {
            // An indexing problem must never prevent saving a product.
            $this->logger->error('Could not mark local search index for refresh', ['exceptionClass' => $error::class]);
        }
    }
}
