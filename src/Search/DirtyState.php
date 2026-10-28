<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Doctrine\DBAL\Connection;

/** A monotonic revision avoids losing changes that arrive during a rebuild. */
final class DirtyState
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function markDirty(): void
    {
        $this->connection->executeStatement('INSERT INTO `shopware_meilisearch_state` (id, revision, indexed_revision)
            VALUES (1, 1, 0) ON DUPLICATE KEY UPDATE revision = revision + 1');
    }

    /** Returns the revision to build, or null when there is no pending work. */
    public function revisionToBuild(): ?int
    {
        $state = $this->connection->fetchAssociative('SELECT revision, indexed_revision,
            (indexed_at IS NULL OR indexed_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 15 MINUTE)) AS expired
            FROM `shopware_meilisearch_state` WHERE id = 1');
        if ($state === false) {
            throw new \RuntimeException('Search indexing state is missing.');
        }
        if ((int) $state['revision'] <= (int) $state['indexed_revision'] && !(bool) $state['expired']) {
            return null;
        }

        return (int) $state['revision'];
    }

    public function completed(int $revision): void
    {
        $this->connection->executeStatement('UPDATE `shopware_meilisearch_state`
            SET indexed_revision = GREATEST(indexed_revision, CAST(:revision AS UNSIGNED)), indexed_at = UTC_TIMESTAMP(3) WHERE id = 1', ['revision' => $revision]);
    }
}
