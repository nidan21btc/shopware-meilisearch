<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Command;

use ShopwareMeilisearch\Security\DatabaseConfiguration;
use ShopwareMeilisearch\Security\Secret;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'shopware-meilisearch:config:import-env', description: 'Import the existing private search service configuration into the database.')]
final class ConfigureConnectionCommand extends Command
{
    public function __construct(private readonly DatabaseConfiguration $config, private readonly string $url, private readonly string $socket, private readonly Secret $searchKey, private readonly Secret $indexKey, private readonly Secret $statusKey, private readonly int $concurrency, private readonly int $globalRate, private readonly int $clientRate) { parent::__construct(); }
    protected function configure(): void { $this->addOption('replace', null, InputOption::VALUE_NONE, 'Replace an existing configuration after verifying the correct local service'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->config->save(['url' => $this->url, 'socket' => $this->socket, 'searchKey' => (string) $this->searchKey, 'indexKey' => (string) $this->indexKey, 'statusKey' => (string) $this->statusKey, 'concurrency' => $this->concurrency, 'globalRate' => $this->globalRate, 'clientRate' => $this->clientRate], (bool) $input->getOption('replace'));
        } catch (\Throwable) {
            $output->writeln('<error>Import failed. Check the existing configuration, local connection and credentials; no secret values are shown.</error>');
            return Command::FAILURE;
        }
        $output->writeln('Search service configuration stored in the database.');
        return Command::SUCCESS;
    }
}
