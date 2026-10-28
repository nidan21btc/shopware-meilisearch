<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1793300000PopularSearches extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1793300000; }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS `shopware_meilisearch_popular_query` (
            `sales_channel_id` BINARY(16) NOT NULL,
            `language_id` BINARY(16) NOT NULL,
            `day` DATE NOT NULL,
            `query_hash` BINARY(32) NOT NULL,
            `query` VARCHAR(60) NOT NULL,
            `count` INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (`day`, `sales_channel_id`, `language_id`, `query_hash`),
            KEY `popular_lookup` (`sales_channel_id`, `language_id`, `day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}
