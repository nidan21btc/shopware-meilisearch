<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Controller;

use ShopwareMeilisearch\Search\SearchInput;
use ShopwareMeilisearch\Search\SearchService;
use ShopwareMeilisearch\Search\Discovery;
use ShopwareMeilisearch\Search\PopularSearches;
use Psr\Log\LoggerInterface;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class SearchController extends StorefrontController
{
    public function __construct(
        private readonly SearchService $search,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly \ShopwareMeilisearch\Security\SearchRequestGuard $guard,
        private readonly PopularSearches $popularSearches,
    ) {
    }

    #[Route(path: '/shopware-meilisearch', name: 'frontend.shopware.meilisearch', methods: ['GET'], defaults: ['XmlHttpRequest' => true, '_httpCache' => false])]
    public function search(Request $request, SalesChannelContext $context): Response
    {
        if (!$this->config->getBool('ShopwareMeilisearch.config.enabled', $context->getSalesChannelId())) {
            throw new NotFoundHttpException();
        }
        if (($denied = $this->guard->enter($request)) !== null) return $denied;
        // Four-letter English typos can themselves be valid short words.
        // Keep the exact matches first, but show their alternatives by default.
        $input = SearchInput::fromRequest($request, $context->getLanguageInfo()->localeCode === 'en-GB');
        try {
            $result = $this->search->search($input, $context);
            $popular = Discovery::suggestions($this->config->getString('ShopwareMeilisearch.config.popularQueries', $context->getSalesChannelId()), $context->getLanguageInfo()->localeCode);
            try {
                if ($request->query->getString('recordPopular') === '1' && $result['total'] > 0 && $input->page === 1 && $input->manufacturers === [] && $input->categories === []) {
                    $this->popularSearches->record($context->getSalesChannelId(), $context->getLanguageId(), $input->query);
                }
                $automatic = $this->popularSearches->suggestions($context->getSalesChannelId(), $context->getLanguageId());
                if ($automatic !== []) $popular = $automatic;
            } catch (\Throwable $error) {
                // Analytics must never prevent a successful product search.
                $this->logger->warning('Popular search aggregation unavailable', ['exceptionClass' => $error::class]);
            }
            $response = $this->renderStorefront('@ShopwareMeilisearch/storefront/search/results.html.twig', ['result' => $result, 'input' => $input, 'popularQueries' => $popular]);
        } catch (\Throwable $error) {
            $this->logger->error('Local search failed', ['exceptionClass' => $error::class, 'salesChannelId' => $context->getSalesChannelId(), 'languageId' => $context->getLanguageId()]);
            $response = $this->renderStorefront('@ShopwareMeilisearch/storefront/search/error.html.twig', ['query' => $input->query]);
            $response->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
