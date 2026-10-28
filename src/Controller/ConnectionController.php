<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Controller;

use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use ShopwareMeilisearch\Security\DatabaseConfiguration;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class ConnectionController
{
    public function __construct(private readonly DatabaseConfiguration $config) {}

    #[Route(path: '/api/_action/shopware-meilisearch/connection', name: 'api.action.shopware_meilisearch.connection_get', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']])]
    public function get(): JsonResponse
    {
        try { $value = $this->config->get(); } catch (\Throwable) { $value = null; }
        if ($value === null) return new JsonResponse(['configured' => false], 200, ['Cache-Control' => 'no-store']);
        return new JsonResponse(['configured' => true, 'url' => $value['url'], 'socket' => $value['socket'], 'concurrency' => $value['concurrency'], 'globalRate' => $value['globalRate'], 'clientRate' => $value['clientRate']], 200, ['Cache-Control' => 'no-store']);
    }

    #[Route(path: '/api/_action/shopware-meilisearch/connection', name: 'api.action.shopware_meilisearch.connection_set', methods: ['POST'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']])]
    public function set(Request $request): JsonResponse
    {
        try {
            $input = $request->toArray();
            try { $old = $this->config->get() ?? []; } catch (\Throwable) { $old = []; }
            $next = [];
            foreach (['url', 'socket', 'concurrency', 'globalRate', 'clientRate'] as $key) $next[$key] = $input[$key] ?? null;
            foreach (['searchKey', 'indexKey', 'statusKey'] as $key) $next[$key] = ($input[$key] ?? '') !== '' ? $input[$key] : ($old[$key] ?? '');
            $this->config->save($next, true);
            return new JsonResponse(['saved' => true], 200, ['Cache-Control' => 'no-store']);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Invalid search configuration or database error.'], 400, ['Cache-Control' => 'no-store']);
        }
    }
}
