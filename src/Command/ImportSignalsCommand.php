<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Command;

use ShopwareMeilisearch\Search\FeedbackConfig;
use ShopwareMeilisearch\Search\QueryClickFeedback;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'shopware-meilisearch:search:import-signals', description: 'Import reviewed, language-specific local search signals from JSON files.')]
final class ImportSignalsCommand extends Command
{
    public function __construct(private readonly SystemConfigService $config, private readonly string|\ShopwareMeilisearch\Security\Secret $signalKey = '')
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('popularity', null, InputOption::VALUE_REQUIRED, 'JSON file with per-product counts');
        $this->addOption('query-hints', null, InputOption::VALUE_REQUIRED, 'Reviewed JSON file with per-product search terms');
        $this->addOption('query-clicks', null, InputOption::VALUE_REQUIRED, 'Reviewed keyed query/product click signals');
        $this->addOption('sales-channel', null, InputOption::VALUE_REQUIRED, 'Limit configuration to this sales channel UUID');
        $this->addOption('merge-query-hints', null, InputOption::VALUE_NONE, 'Append reviewed hints to the effective existing hints');
        $this->addOption('merge-query-clicks', null, InputOption::VALUE_NONE, 'Merge keyed query click signals without removing existing entries');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = $input->getOption('sales-channel');
        if ($channel !== null && (!is_string($channel) || !preg_match('/^[a-f0-9]{32}$/D', $channel))) {
            throw new \InvalidArgumentException('Invalid sales channel UUID.');
        }
        $files = ['popularity' => $input->getOption('popularity'), 'hints' => $input->getOption('query-hints'), 'queryClicks' => $input->getOption('query-clicks')];
        if (!$files['popularity'] && !$files['hints'] && !$files['queryClicks']) {
            throw new \InvalidArgumentException('Provide --popularity, --query-hints and/or --query-clicks.');
        }
        if ($input->getOption('merge-query-hints') && !$files['hints']) {
            throw new \InvalidArgumentException('--merge-query-hints requires --query-hints.');
        }
        if ($input->getOption('merge-query-clicks') && !$files['queryClicks']) {
            throw new \InvalidArgumentException('--merge-query-clicks requires --query-clicks.');
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
            if ($kind === 'queryClicks') {
                QueryClickFeedback::parse($json, (string) $this->signalKey);
                if ($input->getOption('merge-query-clicks')) {
                    $existing = $this->config->getString('ShopwareMeilisearch.config.queryClickFeedback', $channel);
                    $json = QueryClickFeedback::merge($existing, $json, (string) $this->signalKey);
                }
            }
            else FeedbackConfig::parse($json, $kind);
            if ($kind === 'hints' && $input->getOption('merge-query-hints')) {
                $existing = $this->config->getString('ShopwareMeilisearch.config.queryHints', $channel);
                $json = FeedbackConfig::mergeHints($existing, $json);
            }
            $setting = match ($kind) { 'popularity' => 'popularityWeights', 'hints' => 'queryHints', 'queryClicks' => 'queryClickFeedback' };
            $values[$setting] = $json;
        }
        foreach ($values as $key => $json) {
            $this->config->set('ShopwareMeilisearch.config.' . $key, $json, $channel);
        }
        $output->writeln(isset($values['popularityWeights']) || isset($values['queryHints'])
            ? 'Imported signals. Run shopware-meilisearch:search:index to publish indexed signals.'
            : 'Imported query-specific signals; no index rebuild is needed.');

        return self::SUCCESS;
    }
}
