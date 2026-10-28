<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Command;

use ShopwareMeilisearch\Search\FeedbackConfig;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'shopware-meilisearch:search:import-signals', description: 'Import reviewed, language-specific local search signals from JSON files.')]
final class ImportSignalsCommand extends Command
{
    public function __construct(private readonly SystemConfigService $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('popularity', null, InputOption::VALUE_REQUIRED, 'JSON file with per-product counts');
        $this->addOption('sales-channel', null, InputOption::VALUE_REQUIRED, 'Limit configuration to this sales channel UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = $input->getOption('sales-channel');
        if ($channel !== null && (!is_string($channel) || !preg_match('/^[a-f0-9]{32}$/D', $channel))) {
            throw new \InvalidArgumentException('Invalid sales channel UUID.');
        }
        $files = ['popularity' => $input->getOption('popularity')];
        if (!$files['popularity']) {
            throw new \InvalidArgumentException('Provide --popularity.');
        }
        $values = [];
        foreach ($files as $kind => $path) {
            if (!$path) {
                continue;
            }
            if (!is_string($path) || !is_file($path) || filesize($path) > 20 * 1024 * 1024) {
                throw new \InvalidArgumentException('Signal file is missing or exceeds 20 MB.');
            }
            $json = file_get_contents($path);
            if ($json === false) {
                throw new \RuntimeException('Cannot read signal file.');
            }
            FeedbackConfig::parse($json, $kind);
            $setting = 'popularityWeights';
            $values[$setting] = $json;
        }
        foreach ($values as $key => $json) {
            $this->config->set('ShopwareMeilisearch.config.' . $key, $json, $channel);
        }
        $output->writeln('Imported popularity. Run shopware-meilisearch:search:index to publish it.');

        return self::SUCCESS;
    }
}
