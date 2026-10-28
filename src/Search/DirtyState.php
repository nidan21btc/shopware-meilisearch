<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Doctrine\DBAL\Connection;

/** Changes are recorded immediately, but background rebuilding is daily or explicitly requested. */
final class DirtyState
{
    public function __construct(private readonly Connection $connection) {}

    public function markDirty(): void
    {
        $this->connection->executeStatement('INSERT INTO `shopware_meilisearch_state` (id, revision, indexed_revision)
            VALUES (1, 1, 0) ON DUPLICATE KEY UPDATE revision = revision + 1');
    }

    public function revisionToBuild(): ?int
    {
        $state = $this->connection->fetchAssociative('SELECT revision,
            (indexed_at IS NULL OR indexed_at <= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY)) AS due,
            (requested_revision > completed_request_revision) AS requested,
            (attempted_at IS NULL OR attempted_at <= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 15 MINUTE)) AS retry_allowed
            FROM shopware_meilisearch_state WHERE id=1');
        if ($state === false) throw new \RuntimeException('Search indexing state is missing.');
        return ((bool) $state['due'] || (bool) $state['requested']) && (bool) $state['retry_allowed'] ? (int) $state['revision'] : null;
    }

    public function requestRebuild(): void
    {
        // Coalesce repeated button clicks, including while the requested build is running.
        $this->connection->executeStatement('UPDATE shopware_meilisearch_state SET requested_revision=requested_revision+1, attempted_at=NULL
            WHERE id=1 AND requested_revision=completed_request_revision');
    }

    public function requestedRevision(): int
    {
        return (int) $this->connection->fetchOne('SELECT requested_revision FROM shopware_meilisearch_state WHERE id=1');
    }

    public function started(): void
    {
        $this->connection->executeStatement('UPDATE shopware_meilisearch_state SET attempted_at=UTC_TIMESTAMP(3) WHERE id=1');
    }

    public function status(): array
    {
        $s = $this->connection->fetchAssociative('SELECT indexed_at, attempted_at, requested_revision > completed_request_revision AS pending FROM shopware_meilisearch_state WHERE id=1');
        if ($s === false) throw new \RuntimeException('Search indexing state is missing.');
        return ['indexedAt' => $s['indexed_at'], 'attemptedAt' => $s['attempted_at'], 'pending' => (bool) $s['pending']];
    }

    public function completed(int $revision, int $requestRevision = 0): void
    {
        $this->connection->executeStatement('UPDATE shopware_meilisearch_state SET indexed_revision=GREATEST(indexed_revision, CAST(:revision AS UNSIGNED)),
            completed_request_revision=GREATEST(completed_request_revision, CAST(:request AS UNSIGNED)), indexed_at=UTC_TIMESTAMP(3), attempted_at=NULL WHERE id=1',
            ['revision' => $revision, 'request' => $requestRevision]);
    }
}
