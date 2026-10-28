<?php declare(strict_types=1);

// Mutates one product translation and search configuration temporarily. This
// is intentionally restricted to an isolated test database and test server.
$url = getenv('DATABASE_URL') ?: '';
$parts = parse_url($url);
if (!is_array($parts) || !preg_match('/^\/el_search_test_[a-f0-9]+$/D', $parts['path'] ?? '')) {
    throw new RuntimeException('Use an explicitly isolated el_search_test_* database.');
}
$root = getenv('PROJECT_ROOT') ?: '';
$shop = getenv('EL_SEARCH_TEST_URL') ?: '';
$host = getenv('EL_SEARCH_TEST_HOST') ?: '';
$shopParts = parse_url($shop);
if ($root === '' || !is_file($root . '/vendor/autoload.php') || !is_array($shopParts)
    || ($shopParts['scheme'] ?? '') !== 'http' || ($shopParts['host'] ?? '') !== '127.0.0.1'
    || $host === '' || !preg_match('/^[a-zA-Z0-9.-]+$/D', $host)) {
    throw new RuntimeException('Set PROJECT_ROOT and a loopback EL_SEARCH_TEST_URL plus EL_SEARCH_TEST_HOST.');
}
foreach (['PROJECT_ROOT', 'DATABASE_URL', 'APP_URL', 'SHOPWARE_MEILISEARCH_URL', 'SHOPWARE_MEILISEARCH_KEY'] as $name) {
    if (getenv($name) !== false) $_SERVER[$name] = $_ENV[$name] = getenv($name);
}
$loader = require $root . '/vendor/autoload.php';
$kernel = Shopware\Core\Framework\Adapter\Kernel\KernelFactory::create('dev', true, $loader);
$kernel->boot();
$db = $kernel->getContainer()->get(Doctrine\DBAL\Connection::class);
$config = $kernel->getContainer()->get(Shopware\Core\System\SystemConfig\SystemConfigService::class);
if ((int) $db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1') !== 0
    || (int) $db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1') !== 0) {
    throw new RuntimeException('Disable apps and webhooks in the disposable test copy before writing products.');
}
$domains = $db->fetchAllAssociative('SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel_id, LOWER(HEX(d.language_id)) language_id, l.code locale
    FROM sales_channel_domain d JOIN sales_channel c ON c.id=d.sales_channel_id
    JOIN language lang ON lang.id=d.language_id JOIN locale l ON l.id=lang.locale_id WHERE c.active=1');
$localeIds = [];
$channelIds = [];
foreach ($domains as $domain) {
    if (in_array($domain['locale'], ['de-DE','en-GB','es-ES','fr-FR','it-IT'], true)) {
        $localeIds[$domain['locale']] = $domain['language_id'];
        $channelIds[$domain['channel_id']] = true;
    }
}
if (count($localeIds) !== 5 || count($channelIds) !== 1) throw new RuntimeException('Test copy needs the five expected locales in one sales channel.');
$channel = array_key_first($channelIds);
$languageHex = implode(',', array_map(static fn (string $id): string => "UNHEX('" . $id . "')", array_values($localeIds)));
$live = Shopware\Core\Defaults::LIVE_VERSION;
$product = $db->fetchAssociative("SELECT LOWER(HEX(p.id)) id, LOWER(HEX(p.version_id)) version_id
    FROM product p JOIN product_visibility pv ON pv.product_id=p.id AND pv.product_version_id=p.version_id
    JOIN product_translation t ON t.product_id=p.id AND t.product_version_id=p.version_id
    WHERE p.version_id=UNHEX(:live) AND p.active=1 AND p.parent_id IS NULL AND pv.sales_channel_id=UNHEX(:channel) AND pv.visibility>=20
    AND t.language_id IN ($languageHex) AND t.name IS NOT NULL AND t.name<>''
    AND NOT EXISTS (SELECT 1 FROM product child WHERE child.parent_id=p.id AND child.version_id=p.version_id)
    GROUP BY p.id,p.version_id HAVING COUNT(DISTINCT t.language_id)=5 LIMIT 1", ['live' => $live, 'channel' => $channel]);
if ($product === false) throw new RuntimeException('No suitable five-language test product was found.');
$original = [];
foreach ($localeIds as $locale => $languageId) {
    $row = $db->fetchAssociative('SELECT name, custom_fields, custom_search_keywords, keywords, description FROM product_translation WHERE product_id=UNHEX(:id) AND product_version_id=UNHEX(:version) AND language_id=UNHEX(:language)',
        ['id' => $product['id'], 'version' => $product['version_id'], 'language' => $languageId]);
    if ($row === false) throw new RuntimeException('Missing product translation.');
    $original[$locale] = $row;
}
$configuration = [];
foreach (['indexFields', 'indexFieldsByLanguage'] as $name) {
    $key = 'ShopwareMeilisearch.config.' . $name;
    $row = $db->fetchAssociative('SELECT configuration_value FROM system_config WHERE sales_channel_id IS NULL AND configuration_key=:key', ['key' => $key]);
    if ($row === false) throw new RuntimeException('Test copy must have an explicit global field configuration.');
    $configuration[$key] = json_decode($row['configuration_value'], true, 512, JSON_THROW_ON_ERROR)['_value'];
    if (!is_string($configuration[$key])) throw new RuntimeException('Unexpected field configuration format.');
}
if ((int) $db->fetchOne("SELECT COUNT(*) FROM system_config WHERE sales_channel_id=UNHEX(:channel)
    AND configuration_key IN ('ShopwareMeilisearch.config.indexFields','ShopwareMeilisearch.config.indexFieldsByLanguage')", ['channel' => $channel]) !== 0) {
    throw new RuntimeException('Test copy must not override fields for the sales channel.');
}
$tokens = [];
foreach ($localeIds as $locale => $_) {
    // Long, different alphabetic strings avoid accidental typo matches.
    foreach (['customFields', 'customSearchKeywords', 'keywords', 'description'] as $field) {
        $tokens[$locale][$field] = 'probe' . strtr(bin2hex(random_bytes(12)), '0123456789', 'ghijklmnop');
    }
}
$reindex = static function () use ($root): void {
    $process = new Symfony\Component\Process\Process([PHP_BINARY, 'bin/console', 'shopware-meilisearch:search:index'], $root);
    $process->setTimeout(600);
    $process->mustRun();
};
$changed = false;
try {
    $db->beginTransaction();
    foreach ($localeIds as $locale => $languageId) {
        $fields = $original[$locale]['custom_fields'] === null ? [] : json_decode($original[$locale]['custom_fields'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($fields)) throw new RuntimeException('Unexpected custom field format.');
        $fields['essenzen_language_probe'] = $tokens[$locale]['customFields'];
        $db->executeStatement('UPDATE product_translation SET custom_fields=:fields, custom_search_keywords=:custom_keywords, keywords=:keywords, description=:description WHERE product_id=UNHEX(:id) AND product_version_id=UNHEX(:version) AND language_id=UNHEX(:language)',
            ['fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'custom_keywords' => json_encode([$tokens[$locale]['customSearchKeywords']], JSON_THROW_ON_ERROR), 'keywords' => $tokens[$locale]['keywords'], 'description' => $tokens[$locale]['description'], 'id' => $product['id'], 'version' => $product['version_id'], 'language' => $languageId]);
    }
    $db->commit();
    $changed = true;
    $config->set('ShopwareMeilisearch.config.indexFields', '["name","keywords","customSearchKeywords","description","customFields.essenzen_language_probe"]');
    $config->set('ShopwareMeilisearch.config.indexFieldsByLanguage', '{}');
    $reindex();
    $context = stream_context_create(['http' => ['header' => "Host: " . $host . "\r\n", 'timeout' => 30, 'follow_location' => 0, 'max_redirects' => 0]]);
    foreach ($localeIds as $searchLocale => $_) {
        foreach ($tokens as $tokenLocale => $fieldTokens) foreach ($fieldTokens as $field => $token) {
            $path = '/' . substr($searchLocale, 0, 2) . '/shopware-meilisearch?' . http_build_query(['q' => $token]);
            $html = file_get_contents(rtrim($shop, '/') . $path, false, $context);
            if ($html === false || !str_contains($html, 'data-el-result-status')) throw new RuntimeException('Invalid storefront response.');
            $found = str_contains($html, 'data-product-id="' . $product['id'] . '"');
            if ($found !== ($searchLocale === $tokenLocale)) throw new RuntimeException('Search language isolation failed for ' . $searchLocale . ' in ' . $field . '.');
        }
        echo $searchLocale . " language isolation passed.\n";
    }
    $overrides = [
        'de-DE' => ['name', 'keywords'],
        'en-GB' => ['name', 'customFields.essenzen_language_probe'],
        'es-ES' => ['name', 'description'],
        'fr-FR' => ['name', 'customSearchKeywords'],
        'it-IT' => ['name'],
    ];
    $expectedField = ['de-DE' => 'keywords', 'en-GB' => 'customFields', 'es-ES' => 'description', 'fr-FR' => 'customSearchKeywords', 'it-IT' => null];
    $config->set('ShopwareMeilisearch.config.indexFieldsByLanguage', json_encode($overrides, JSON_THROW_ON_ERROR));
    $reindex();
    foreach ($localeIds as $locale => $_) {
        foreach ($tokens[$locale] as $field => $token) {
            $path = '/' . substr($locale, 0, 2) . '/shopware-meilisearch?' . http_build_query(['q' => $token]);
            $html = file_get_contents(rtrim($shop, '/') . $path, false, $context);
            if ($html === false || !str_contains($html, 'data-el-result-status')) throw new RuntimeException('Invalid storefront response.');
            $found = str_contains($html, 'data-product-id="' . $product['id'] . '"');
            if ($found !== ($field === $expectedField[$locale])) throw new RuntimeException('Language-specific field configuration failed for ' . $locale . ' in ' . $field . '.');
        }
        echo $locale . " field override passed.\n";
    }
    // A product without a translated name may be found by explicitly selected
    // product text in that same language, never by another locale's text.
    $config->set('ShopwareMeilisearch.config.indexFieldsByLanguage', '{}');
    foreach ($localeIds as $languageId) {
        $db->executeStatement("UPDATE product_translation SET name='' WHERE product_id=UNHEX(:id) AND product_version_id=UNHEX(:version) AND language_id=UNHEX(:language)",
            ['id' => $product['id'], 'version' => $product['version_id'], 'language' => $languageId]);
    }
    $reindex();
    foreach ($localeIds as $searchLocale => $_) {
        foreach ($tokens as $tokenLocale => $fieldTokens) foreach ($fieldTokens as $field => $token) {
            $path = '/' . substr($searchLocale, 0, 2) . '/shopware-meilisearch?' . http_build_query(['q' => $token]);
            $html = file_get_contents(rtrim($shop, '/') . $path, false, $context);
            if ($html === false || !str_contains($html, 'data-el-result-status')) throw new RuntimeException('Invalid storefront response.');
            $found = str_contains($html, 'data-product-id="' . $product['id'] . '"');
            if ($found !== ($searchLocale === $tokenLocale)) throw new RuntimeException('Unnamed product language isolation failed for ' . $searchLocale . ' in ' . $field . '.');
            if ($found && !str_contains($html, 'el-search__missing-name')) throw new RuntimeException('Missing-name indicator was not shown.');
        }
        echo $searchLocale . " unnamed product language isolation passed.\n";
    }
} finally {
    if ($db->isTransactionActive()) $db->rollBack();
    if ($changed) {
        $db->beginTransaction();
        try {
            foreach ($localeIds as $locale => $languageId) {
                $db->executeStatement('UPDATE product_translation SET name=:name, custom_fields=:fields, custom_search_keywords=:custom_keywords, keywords=:keywords, description=:description WHERE product_id=UNHEX(:id) AND product_version_id=UNHEX(:version) AND language_id=UNHEX(:language)',
                    ['name' => $original[$locale]['name'], 'fields' => $original[$locale]['custom_fields'], 'custom_keywords' => $original[$locale]['custom_search_keywords'], 'keywords' => $original[$locale]['keywords'], 'description' => $original[$locale]['description'], 'id' => $product['id'], 'version' => $product['version_id'], 'language' => $languageId]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        foreach ($configuration as $key => $value) $config->set($key, $value);
        $reindex();
        echo "Test data and search indexes restored.\n";
    }
    $kernel->shutdown();
}
