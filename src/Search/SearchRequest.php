<?php declare(strict_types=1);
namespace ShopwareMeilisearch\Search;

/** Recover once from a field restriction invalidated by a concurrent index swap. */
final class SearchRequest
{
    public function __construct(private readonly Engine $engine, private readonly PublishedSearchFields $publishedFields) {}

    public function execute(string $index, array $payload, string $originalQuery, int &$engineCalls): ?array
    {
        try {
            ++$engineCalls;
            return $this->engine->request('POST', '/indexes/' . $index . '/search', $payload);
        } catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface $error) {
            $body = json_decode($error->getResponse()->getContent(false), true);
            if (!isset($payload['attributesToSearchOn']) || ($body['code'] ?? '') !== 'invalid_search_attributes_to_search_on') throw $error;
            try { $this->publishedFields->invalidate($index); } catch (\Throwable) {}
            unset($payload['attributesToSearchOn']);
            $payload['q'] = $originalQuery;
            ++$engineCalls;
            return $this->engine->request('POST', '/indexes/' . $index . '/search', $payload);
        }
    }
}
