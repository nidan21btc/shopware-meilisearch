<?php declare(strict_types=1);
/** Inspect recorded changes independently of when automatic rebuilding is due. */
function searchPendingRevisionForTest(\Doctrine\DBAL\Connection $db): ?int
{
    $row=$db->fetchAssociative('SELECT revision,indexed_revision,indexed_at FROM shopware_meilisearch_state WHERE id=1');
    if($row===false)throw new RuntimeException('Missing test state');
    if((int)$row['revision']>(int)$row['indexed_revision'] || $row['indexed_at']===null || strtotime($row['indexed_at'].' UTC')<=time()-86400)return (int)$row['revision'];
    return null;
}
