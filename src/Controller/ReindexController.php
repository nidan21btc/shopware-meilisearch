<?php declare(strict_types=1);
namespace ShopwareMeilisearch\Controller;

use ShopwareMeilisearch\Search\DirtyState;
use Shopware\Core\PlatformRequest;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class ReindexController
{
    public function __construct(private readonly DirtyState $state) {}

    #[Route(path: '/api/_action/shopware-meilisearch/reindex', name: 'api.action.shopware_meilisearch.reindex', methods: ['POST'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']])]
    public function request(): JsonResponse
    {
        $this->state->requestRebuild();
        return new JsonResponse($this->state->status(), 202, ['Cache-Control' => 'no-store']);
    }

    #[Route(path: '/api/_action/shopware-meilisearch/reindex', name: 'api.action.shopware_meilisearch.reindex_status', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']])]
    public function status(): JsonResponse { return new JsonResponse($this->state->status(), 200, ['Cache-Control' => 'no-store']); }
}
