<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Local, process-shared admission control for one shop server, before DAL/context work. */
final class SearchRequestGuard implements EventSubscriberInterface
{
    private const ATTRIBUTE = '_shopware_meilisearch_lease';
    private const TTL = 120;
    public function __construct(private readonly string $directory, private readonly int $concurrency = 2, private readonly int $globalRate = 2, private readonly int $clientRate = 2) {
        if ($concurrency < 1 || $concurrency > 10 || $globalRate < 1 || $globalRate > 10 || $clientRate < 1 || $clientRate > 10) throw new \InvalidArgumentException('Search admission limits must be between 1 and 10.');
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 4096], KernelEvents::FINISH_REQUEST => ['onFinish', -4096]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !preg_match('~/shopware-meilisearch/?$~D', rawurldecode($event->getRequest()->getPathInfo()))) return;
        if (($response = $this->enter($event->getRequest())) !== null) $event->setResponse($response);
    }

    public function onFinish(FinishRequestEvent $event): void { $this->release($event->getRequest()); }

    public function enter(Request $request): ?Response
    {
        if ($request->attributes->has(self::ATTRIBUTE)) return null;
        $lock = null;
        try {
            if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) throw new \RuntimeException();
            if (is_link($this->directory) || (fileperms($this->directory) & 0022)) throw new \RuntimeException();
            $path = $this->directory . '/admission.json';
            if (is_link($path)) throw new \RuntimeException();
            $mask = umask(0077);
            try { $lock = @fopen($path, 'c+'); } finally { umask($mask); }
            if (!$lock) throw new \RuntimeException();
            // Never queue PHP workers behind another admission check.
            if (!flock($lock, LOCK_EX | LOCK_NB)) return $this->denied(429);
            if (fstat($lock)['size'] > 262144) throw new \RuntimeException();
            $text = stream_get_contents($lock);
            $state = $text === '' ? [] : json_decode($text, true, 16, JSON_THROW_ON_ERROR);
            $now = microtime(true);
            // No IP addresses are persisted, and storage is bounded even under IP rotation.
            $client = hash('sha256', $request->getClientIp() ?? 'unknown');
            $clients = array_filter($state['clients'] ?? [], static fn (array $s): bool => $s['seen'] > $now - self::TTL);
            $globalBurst = max(8, $this->globalRate * 4);
            $clientBurst = $this->clientRate * 3;
            $minuteBurst = $this->clientRate * 30;
            $global = $state['global'] ?? ['tokens' => (float) $globalBurst, 'at' => $now];
            $visitor = $clients[$client] ?? ['tokens' => (float) $clientBurst, 'at' => $now, 'minute' => (float) $minuteBurst, 'seen' => $now];
            $global['tokens'] = min($globalBurst, $global['tokens'] + max(0, $now - $global['at']) * $this->globalRate);
            $visitor['tokens'] = min($clientBurst, $visitor['tokens'] + max(0, $now - $visitor['at']) * $this->clientRate);
            $visitor['minute'] = min($minuteBurst, $visitor['minute'] + max(0, $now - $visitor['at']) * $this->clientRate / 2);
            if ($global['tokens'] < 1 || $visitor['tokens'] < 1 || $visitor['minute'] < 1 || (!isset($clients[$client]) && count($clients) >= 1024)) return $this->denied(429);
            $global['tokens']--; $global['at'] = $now;
            $visitor['tokens']--; $visitor['minute']--; $visitor['at'] = $visitor['seen'] = $now;
            $clients[$client] = $visitor;
            $encoded = json_encode(['global' => $global, 'clients' => $clients], JSON_THROW_ON_ERROR);
            rewind($lock);
            if (!ftruncate($lock, 0) || fwrite($lock, $encoded) !== strlen($encoded) || !fflush($lock)) throw new \RuntimeException();
            // Nonblocking OS locks survive client disconnects and are released on worker exit.
            for ($i = 0; $i < $this->concurrency; ++$i) {
                $slotPath = $this->directory . '/slot-' . $i;
                if (is_link($slotPath)) throw new \RuntimeException();
                $mask = umask(0077);
                try { $slot = @fopen($slotPath, 'c'); } finally { umask($mask); }
                if (!$slot) throw new \RuntimeException();
                if (flock($slot, LOCK_EX | LOCK_NB)) {
                    $request->attributes->set(self::ATTRIBUTE, $slot);
                    return null;
                }
                fclose($slot);
            }
            return $this->denied(429);
        } catch (\Throwable) {
            // An unavailable limiter must never expose an unlimited endpoint.
            return $this->denied(503);
        } finally {
            if (is_resource($lock)) fclose($lock);
        }
    }

    public function release(Request $request): void
    {
        $slot = $request->attributes->get(self::ATTRIBUTE);
        if (is_resource($slot)) fclose($slot);
        $request->attributes->remove(self::ATTRIBUTE);
    }

    private function denied(int $status): Response
    {
        return new Response('', $status, ['Retry-After' => '2', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
