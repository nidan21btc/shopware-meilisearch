<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Command;

use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Search\DocumentBuilder;
use ShopwareMeilisearch\Search\Engine;
use ShopwareMeilisearch\Search\IndexFields;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'shopware-meilisearch:search:audit-index-fields', description: 'Summarize published search field coverage by sales channel and language.')]
final class AuditIndexFieldsCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly Engine $engine, private readonly SystemConfigService $config)
    {
        parent::__construct();
    }

    /** Check only whether a value contains searchable text; never expose it. */
    public static function hasText(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) if (self::hasText($item)) return true;
            return false;
        }
        return is_scalar($value) && trim((string) $value) !== '';
    }

    public static function fieldValue(array $document, string $field): mixed
    {
        $value = $document;
        foreach (explode('.', $field) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return null;
            $value = $value[$part];
        }
        return $value;
    }

    protected function configure(): void
    {
        $this->addOption('sales-channel', null, InputOption::VALUE_REQUIRED, 'Limit the report to one sales channel UUID');
        $this->addOption('require-current', null, InputOption::VALUE_NONE, 'Return failure if automatic updates are disabled, fields differ, a revision is pending, or it changes during the audit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domains = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel_id, LOWER(HEX(d.language_id)) language_id, l.code locale
             FROM sales_channel_domain d INNER JOIN sales_channel s ON s.id=d.sales_channel_id
             INNER JOIN language lang ON lang.id=d.language_id INNER JOIN locale l ON l.id=lang.locale_id
             WHERE s.active=1 ORDER BY channel_id, language_id'
        );
        $requestedChannel = $input->getOption('sales-channel');
        if ($requestedChannel !== null) {
            if (!is_string($requestedChannel) || !preg_match('/^[a-f0-9]{32}$/D', $requestedChannel)) throw new \InvalidArgumentException('Expected a sales channel UUID.');
            $domains = array_values(array_filter($domains, static fn (array $domain): bool => $domain['channel_id'] === $requestedChannel));
        }
        $report = [];
        $failed = false;
        $state = $this->connection->fetchAssociative('SELECT revision,indexed_revision FROM shopware_meilisearch_state WHERE id=1');
        if ($state === false) throw new \RuntimeException('Search indexing state is missing.');
        foreach ($domains as $domain) {
            $channel = $domain['channel_id']; $locale = $domain['locale'];
            try {
                $index = Engine::indexName($channel, $domain['language_id']);
                $before = $this->engine->request('GET', '/indexes/' . $index, allowMissing: true);
                if ($before === null) {
                    $failed = true;
                    $report[] = ['salesChannelId'=>$channel, 'languageId'=>$domain['language_id'], 'locale'=>$locale, 'error'=>'index_missing'];
                    continue;
                }
                if (!is_string($before['updatedAt'] ?? null)) throw new \RuntimeException('Invalid index metadata');
                $fields = $this->engine->request('GET', '/indexes/' . $index . '/settings/searchable-attributes', allowMissing: true);
                if ($fields === null) throw new \RuntimeException('Published index missing');
                if (!array_is_list($fields) || array_filter($fields, static fn ($field): bool => !is_string($field))) {
                    throw new \RuntimeException('Unexpected index field configuration');
                }
                $configured = IndexFields::forLocale(
                    $this->config->getString('ShopwareMeilisearch.config.indexFields', $channel),
                    $this->config->getString('ShopwareMeilisearch.config.indexFieldsByLanguage', $channel), $locale
                );
                $expected = DocumentBuilder::settings([], $configured, $locale)['searchableAttributes'];
                $counts = array_fill_keys($fields, 0); $offset = 0; $unnamed = 0;
                do {
                    $response = $this->engine->request('GET', '/indexes/' . $index . '/documents?limit=1000&offset=' . $offset);
                    $documents = $response['results'] ?? null;
                    if (!is_array($documents) || !array_is_list($documents)) throw new \RuntimeException('Unexpected index document response');
                    foreach ($documents as $document) {
                        if (!is_array($document)) throw new \RuntimeException('Unexpected index document');
                        foreach ($fields as $field) if (self::hasText(self::fieldValue($document, $field))) ++$counts[$field];
                        if (($document['unnamed'] ?? false) === true) ++$unnamed;
                    }
                    $batch = count($documents); $offset += $batch;
                } while ($batch === 1000);
                $after = $this->engine->request('GET', '/indexes/' . $index);
                if (($after['updatedAt'] ?? null) !== $before['updatedAt']) {
                    $failed = true;
                    $report[] = ['salesChannelId'=>$channel, 'languageId'=>$domain['language_id'], 'locale'=>$locale, 'error'=>'index_changed_retry'];
                    continue;
                }
                $report[] = ['salesChannelId'=>$channel, 'languageId'=>$domain['language_id'], 'locale'=>$locale,
                    'indexedProducts'=>$offset, 'indexedWithoutName'=>$unnamed,
                    'automaticUpdatesEnabled'=>$this->config->getBool('ShopwareMeilisearch.config.enabled', $channel),
                    'fieldConfigurationMatchesPublishedIndex'=>$fields === $expected, 'fieldCoverage'=>$counts];
            } catch (\Throwable $error) {
                // Do not print response bodies, indexed content, credentials or HTTP URLs.
                $failed = true;
                $report[] = ['salesChannelId'=>$channel, 'languageId'=>$domain['language_id'], 'locale'=>$locale, 'errorClass'=>$error::class];
            }
        }
        // A write or completed rebuild during this multi-index audit makes the
        // combined report non-atomic, even if the final revision is clean.
        $after = $this->connection->fetchAssociative('SELECT revision,indexed_revision FROM shopware_meilisearch_state WHERE id=1');
        if ($after === false) throw new \RuntimeException('Search indexing state is missing.');
        $changed = (int) $state['revision'] !== (int) $after['revision'] || (int) $state['indexed_revision'] !== (int) $after['indexed_revision'];
        $pending = (int) $state['revision'] > (int) $state['indexed_revision'] || (int) $after['revision'] > (int) $after['indexed_revision'];
        foreach ($report as &$entry) {
            if (array_key_exists('fieldCoverage', $entry)) {
                $entry['indexRevisionPending'] = $pending;
                $entry['indexRevisionChangedDuringAudit'] = $changed;
                if ($input->getOption('require-current') && (!$entry['automaticUpdatesEnabled'] || !$entry['fieldConfigurationMatchesPublishedIndex'] || $pending || $changed)) $failed = true;
            }
        }
        unset($entry);
        if ($input->getOption('require-current') && $report === []) $failed = true;
        $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
