<?php declare(strict_types=1);

namespace ShopwareMeilisearch\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

final class ReindexTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'shopware.meilisearch.reindex';
    }

    public static function getDefaultInterval(): int
    {
        return 60;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
