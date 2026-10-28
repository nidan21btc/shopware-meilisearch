<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Security;

use Psr\Log\LoggerInterface;

/** Bounded, best-effort counters shared by PHP workers; no request data is stored. */
final class AdmissionMetrics
{
    public function __construct(private readonly string $directory, private readonly LoggerInterface $logger, private readonly ?\Closure $clock = null) {}

    public function record(string $event, array $limits, float $durationMs = 0, int $active = 0): void
    {
        if (!in_array($event, ['accepted', 'completed', 'failed', 'concurrency', 'global_rate', 'client_rate', 'client_capacity', 'limiter_busy', 'limiter_error'], true)) return;
        $file = null;
        try {
            if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) return;
            if (is_link($this->directory) || (fileperms($this->directory) & 0022)) return;
            $path = $this->directory . '/metrics.json';
            if (is_link($path)) return;
            $mask = umask(0077);
            try { $file = @fopen($path, 'c+'); } finally { umask($mask); }
            if (!$file || !flock($file, LOCK_EX | LOCK_NB)) return;
            if ((fstat($file)['size'] ?? PHP_INT_MAX) > 8192) return;
            $now = $this->clock ? ($this->clock)() : microtime(true);
            $text = stream_get_contents($file);
            try { $state = $text === '' ? [] : json_decode($text, true, 8, JSON_THROW_ON_ERROR); } catch (\Throwable) { $state = []; }
            if (!is_array($state) || !isset($state['since'], $state['counts'], $state['lastEmission']) || !is_array($state['counts'])) $state = $this->fresh($now, 0);
            if ($now - $state['since'] >= 60 && $now - $state['lastEmission'] >= 60) {
                $counts = $state['counts'];
                $rejected = array_sum(array_diff_key($counts, array_flip(['accepted', 'completed', 'failed'])));
                $context = ['windowSeconds' => round($now - $state['since'], 3), 'counts' => $counts,
                    'peakActive' => $state['peak'], 'durationMeanMs' => $state['finished'] ? round($state['durationSum'] / $state['finished'], 2) : null,
                    'durationMaxMs' => round($state['durationMax'], 2), 'limits' => $limits];
                $state = $this->fresh($now, $now);
                // Persist the emission gate before logging, also if a handler throws.
                $this->save($file, $state);
                try { $this->logger->log($rejected || ($counts['failed'] ?? 0) ? 'warning' : 'info', 'Search admission summary', $context); } catch (\Throwable) {}
            }
            $state['counts'][$event] = min(1000000000, ($state['counts'][$event] ?? 0) + 1);
            $state['peak'] = max($state['peak'], $active);
            if ($event === 'completed' || $event === 'failed') {
                $durationMs = max(0, min(3600000, $durationMs));
                ++$state['finished'];
                $state['durationSum'] += $durationMs;
                $state['durationMax'] = max($state['durationMax'], $durationMs);
            }
            $this->save($file, $state);
        } catch (\Throwable) {
            // Observability must not change admission decisions or break search.
        } finally { if (is_resource($file)) fclose($file); }
    }

    private function fresh(float $now, float $lastEmission): array
    {
        return ['since' => $now, 'lastEmission' => $lastEmission, 'counts' => [], 'peak' => 0, 'finished' => 0, 'durationSum' => 0.0, 'durationMax' => 0.0];
    }

    private function save($file, array $state): void
    {
        $text = json_encode($state, JSON_THROW_ON_ERROR);
        if (strlen($text) > 8192) throw new \RuntimeException();
        rewind($file);
        if (!ftruncate($file, 0) || fwrite($file, $text) !== strlen($text) || !fflush($file)) throw new \RuntimeException();
    }
}
