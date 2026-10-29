<?php declare(strict_types=1);
namespace ShopwareMeilisearch\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1793400000PopularSessions extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1793400000; }
    public function update(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS `shopware_meilisearch_popular_session` (
            `day` DATE NOT NULL, `sales_channel_id` BINARY(16) NOT NULL, `language_id` BINARY(16) NOT NULL,
            `session_hash` BINARY(32) NOT NULL, `query_hash` BINARY(32) NOT NULL, `query` VARCHAR(60) NOT NULL,
            PRIMARY KEY (`day`,`sales_channel_id`,`language_id`,`session_hash`,`query_hash`),
            KEY `popular_lookup` (`sales_channel_id`,`language_id`,`day`,`query_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}
