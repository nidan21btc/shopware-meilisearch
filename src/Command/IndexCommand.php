<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Command;

use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Search\Indexer;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'shopware-meilisearch:search:index', description: 'Atomically rebuild local search indexes for storefront domains.')]
final class IndexCommand extends Command
{
    public function __construct(
        private readonly Indexer $indexer,
        private readonly AbstractSalesChannelContextFactory $contexts,
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sales-channel', null, InputOption::VALUE_REQUIRED, 'Limit indexing to this sales channel UUID');
        $this->addOption('language', null, InputOption::VALUE_REQUIRED, 'Limit indexing to this language UUID');
        $this->addOption('enabled-only', null, InputOption::VALUE_NONE, 'Only index channels where the local overlay is enabled');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domains = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel_id, LOWER(HEX(d.language_id)) language_id
             FROM sales_channel_domain d INNER JOIN sales_channel c ON c.id = d.sales_channel_id WHERE c.active = 1'
        );
        $indexed = 0;
        $failed = false;
        foreach ($domains as $domain) {
            if (($input->getOption('sales-channel') && $input->getOption('sales-channel') !== $domain['channel_id'])
                || ($input->getOption('language') && $input->getOption('language') !== $domain['language_id'])) {
                continue;
            }
            if ($input->getOption('enabled-only') && !$this->config->getBool('ShopwareMeilisearch.config.enabled', $domain['channel_id'])) {
                continue;
            }
            try {
                $context = $this->contexts->create(Uuid::randomHex(), $domain['channel_id'], [SalesChannelContextService::LANGUAGE_ID => $domain['language_id']]);
                $count = $this->indexer->rebuild($context);
                $output->writeln(sprintf('%s / %s: %d products', $domain['channel_id'], $domain['language_id'], $count));
                ++$indexed;
            } catch (\Throwable $e) {
                $failed = true;
                // Do not print HTTP exception messages, which may contain request details.
                $output->writeln(sprintf('<error>Failed: %s / %s (%s)</error>', $domain['channel_id'], $domain['language_id'], $e::class));
                if ($e::class === \RuntimeException::class || $e::class === \InvalidArgumentException::class) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                }
            }
        }

        return $failed || ($indexed === 0 && !$input->getOption('enabled-only')) ? self::FAILURE : self::SUCCESS;
    }
}
