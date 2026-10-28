<?php declare(strict_types=1);
require (getenv('SHOPWARE_ROOT') ?: dirname(__DIR__, 4)) . '/vendor/autoload.php';
$cards=(new Shopware\Core\System\SystemConfig\Util\ConfigReader())->read(dirname(__DIR__).'/src/Resources/config/config.xml');
$expected=['indexFields'=>'sw-meilisearch-index-fields','indexFieldsByLanguage'=>'sw-meilisearch-language-fields','quantityUnits'=>'sw-meilisearch-quantity-units','popularQueries'=>'sw-meilisearch-query-suggestions','synonyms'=>'sw-meilisearch-synonyms'];
foreach ($cards[0]['elements'] as $element) {
    $name=$element['name']??'';
    if (isset($expected[$name])) {
        if (($element['componentName']??null)!==$expected[$name]) throw new RuntimeException('Incorrect administration component: '.$name);
        unset($expected[$name]);
    }
}
if ($expected!==[]) throw new RuntimeException('Missing administration configuration components');
echo "Shopware configuration component schema passed.\n";
