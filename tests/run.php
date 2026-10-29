<?php declare(strict_types=1);

// Run from any directory: php custom/plugins/ShopwareMeilisearch/tests/run.php
require (getenv('SHOPWARE_ROOT') ?: dirname(__DIR__, 4)) . '/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'ShopwareMeilisearch\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use ShopwareMeilisearch\Command\AuditIndexFieldsCommand;
use ShopwareMeilisearch\Search\DocumentBuilder;
use ShopwareMeilisearch\Search\Engine;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\Aggregate\CategoryTranslation\CategoryTranslationCollection;
use Shopware\Core\Content\Category\Aggregate\CategoryTranslation\CategoryTranslationEntity;
use Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationCollection;
use Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationEntity;
use ShopwareMeilisearch\Search\LanguageText;
use ShopwareMeilisearch\Search\IndexFields;
use ShopwareMeilisearch\Search\SearchInput;
use ShopwareMeilisearch\Search\Discovery;
use ShopwareMeilisearch\Search\SearchService;
use ShopwareMeilisearch\Search\TypoRelevance;
use ShopwareMeilisearch\Search\PartialMatches;
use ShopwareMeilisearch\Search\SearchFold;
use ShopwareMeilisearch\Search\ShortQueryPrecision;
use ShopwareMeilisearch\Search\MultiwordPrecision;
use ShopwareMeilisearch\Search\QuantityUnits;
use ShopwareMeilisearch\Search\QuantityRelevance;
use ShopwareMeilisearch\Search\VariantQuantityLabel;
use ShopwareMeilisearch\Search\VariantOptionLabels;
use ShopwareMeilisearch\Search\NameAliases;
use ShopwareMeilisearch\Search\ReferenceUnitLabels;
use ShopwareMeilisearch\Search\IdentifierFallback;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\System\Unit\UnitEntity;
use Shopware\Core\System\Unit\Aggregate\UnitTranslation\UnitTranslationEntity;
use Shopware\Core\System\Unit\Aggregate\UnitTranslation\UnitTranslationCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOptionTranslation\PropertyGroupOptionTranslationEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOptionTranslation\PropertyGroupOptionTranslationCollection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

$channel = str_repeat('a', 32);
$language = str_repeat('b', 32);
check(PartialMatches::terms('Test Blume') === ['Test', 'Blume'], 'Partial matching recognizes separate language-local words');
check(PartialMatches::terms('AB-123 Blüten') === [] && PartialMatches::terms('Bach') === [] && PartialMatches::terms('eins zwei drei vier fünf sechs') === [], 'Partial matching excludes identifiers, single words and long queries');
check(IdentifierFallback::isCode('FE062M') && IdentifierFallback::isCode('9781234567890') && IdentifierFallback::isCode('AB-123'), 'Recognize exact product identifiers');
check(!IdentifierFallback::isCode('flower') && !IdentifierFallback::isCode('FE062M flowers') && !IdentifierFallback::isCode('123?x') && !IdentifierFallback::isCode(str_repeat('1', 65)), 'Identifier fallback excludes natural-language, malformed and overlong queries');
check(IdentifierFallback::exactCode('fe062m', 'FE062M') && IdentifierFallback::exactCode("A\u{0308}12", 'Ä12'), 'Identifier lookup accepts case and canonical Unicode equivalents');
check(!IdentifierFallback::exactCode('A12', 'Ä12') && !IdentifierFallback::exactCode('FE062', 'FE062M') && !IdentifierFallback::exactCode('FE062M', null), 'Identifier lookup rejects accent folding, prefixes and absent values');
check(SearchService::sortByDisplayName(['b', 'a', 'c'], ['a' => 'AB123', 'b' => 'Blüte', 'c' => 'Zitrone'], 'de-DE') === ['a', 'b', 'c'], 'Sort displayed labels without using another language');
$one = str_repeat('1', 32); $two = str_repeat('2', 32); $three = str_repeat('3', 32);
check(PartialMatches::append([$one], [[['id' => $one], ['id' => $three]], [['id' => $two], ['id' => 'invalid']]]) === [$one, $two, $three], 'Partial matches preserve existing order, alternate terms, and remove duplicate or invalid IDs');
check(!PartialMatches::hideParent($one, $two, [$one => true]), 'A partial variant must not hide a primary parent');
check(PartialMatches::hideParent($one, $two, [$one => true, $two => true]) && PartialMatches::hideParent($one, $two, []), 'Ordinary parent and variant deduplication remains active');
foreach (['Bachblüten'=>'bachblueten','BACHBLÜTEN'=>'bachblueten','Fuß'=>'fuss','Größe'=>'groesse',"Blu\u{0308}ten"=>'blueten','RQ7'=>'rq7','B-10'=>'b-10','9781234567890'=>'9781234567890',''=>'', 'Rescue'=>'rescue','Blue'=>'blue','Bluebell'=>'bluebell','Feuer'=>'feuer','Queen'=>'queen','Aqua'=>'aqua','Essenz'=>'essenz','Mass'=>'mass','Poesie'=>'poesie'] as $input=>$expected) check(SearchFold::text((string) $input) === $expected, 'Deterministic one-way folding: '.$input);
check(SearchFold::usesAliases('Bachblüten') && SearchFold::usesAliases('Bachblueten 10 ml') && SearchFold::usesAliases('Oel') && SearchFold::usesAliases('Muesli') && !SearchFold::usesAliases('Queen') && !SearchFold::usesAliases('Blue') && !SearchFold::usesAliases('Feuer') && !SearchFold::usesAliases('Rescue') && !SearchFold::usesAliases('RQ7'), 'Enable needed spellings while avoiding unrelated short and code prefixes');
check(SearchFold::aliases('Feuer und Wasser') === '' && SearchFold::aliases('Bachblüten') === 'bachblueten', 'Index folded aliases only when transliteration changes text');
check(PartialMatches::quantityPhrase('Emergency 30 ml') === 'Emergency' && PartialMatches::quantityPhrase('30 ml Emergency') === 'Emergency' && PartialMatches::quantityPhrase('Emergency 30ml') === 'Emergency', 'Recognize quantities before or after a natural-language phrase');
foreach (['Emergency ml','Emergency 30','Emergency 10 ml 30 ml','AB-30ml','10 ml','ab 30 ml'] as $invalid) check(PartialMatches::quantityPhrase($invalid) === null, 'Do not promote ambiguous quantities or short names');
$shortHits = [];
for ($n = 0; $n < 20; ++$n) {
    $shortHits[] = ['id' => str_pad(dechex($n), 32, '0', STR_PAD_LEFT), '_rankingScoreDetails' => ['typo' => ['typoCount' => 0]]];
}
$shortHits[] = ['id' => str_repeat('f', 32), '_rankingScoreDetails' => ['typo' => ['typoCount' => 1]]];
check(count(ShortQueryPrecision::exactIds('bach', $shortHits)) === 20, 'Short word separates precise matches from typo matches');
check(ShortQueryPrecision::exactIds('ba', $shortHits) === null && ShortQueryPrecision::exactIds('ab12', $shortHits) === null, 'Short-query precision excludes short text and identifiers');
check(ShortQueryPrecision::exactIds('bach', array_slice($shortHits, 1)) === null && ShortQueryPrecision::exactIds('bach', [['id' => $one]]) === null, 'Keep typo matches when precise results or metadata are insufficient');
$oneExactAndTypo = [$shortHits[0], $shortHits[20]];
check(ShortQueryPrecision::exactIds('rose', $oneExactAndTypo) === null && count(ShortQueryPrecision::exactIds('rose', $oneExactAndTypo, 1)) === 1, 'English short queries can retain exact hits before optional typo matches');
check(ShortQueryPrecision::exactIds('rose', [$shortHits[20]], 1) === null, 'Retain typo matches if no exact hit exists');
check(DocumentBuilder::settings([], IndexFields::DEFAULTS, 'en-GB')['typoTolerance']['minWordSizeForTypos'] === ['oneTypo' => 4, 'twoTypos' => 9], 'English permits one typo at four letters without loosening two-typo tolerance');
check(SearchInput::fromRequest(Request::create('/?q=bach&similar=1'))->includeSimilar, 'Similar-result option is parsed');
check(SearchInput::fromRequest(Request::create('/?q=bach'), true)->includeSimilar, 'Locale may choose expanded results by default');
check(!SearchInput::fromRequest(Request::create('/?q=bach&similar=0'), true)->includeSimilar, 'Explicit choice can hide default similar results');
check(SearchInput::fromRequest(Request::create('/?q=bach+blueten'))->includeSimilar, 'Multiword searches include weaker matches by default');
check(!SearchInput::fromRequest(Request::create('/?q=bach+blueten&similar=0'))->includeSimilar, 'Multiword weaker matches may be explicitly hidden');
$multiHits = [];
for ($i = 0; $i < 21; ++$i) $multiHits[] = ['id' => sprintf('%032x', $i + 1), '_rankingScoreDetails' => ['typo' => ['typoCount' => $i === 20 ? 2 : $i % 2]]];
check(count(MultiwordPrecision::strongIds('Test Blume', $multiHits) ?? []) === 20, 'Multiword precision keeps zero- and one-typo results');
check(MultiwordPrecision::strongIds('Test Blume', array_slice($multiHits, 0, 20)) === null, 'Do not offer a narrower view without weaker matches');
check(MultiwordPrecision::strongIds('AB-123 Blüten', $multiHits) === null, 'Do not apply natural-language precision to product codes');
$composed = SearchInput::fromRequest(new Request(['q' => "café"]));
$decomposed = SearchInput::fromRequest(new Request(['q' => "cafe\u{0301}"]));
check($composed->query === $decomposed->query && $decomposed->query === 'café', 'Equivalent accented input uses the same canonical query');
check(count(ShortQueryPrecision::exactIds($decomposed->query, $shortHits)) === 20, 'Canonical four-letter input receives the same short-word precision');
$rankingHit = static fn (string $id, int $words, int $typos, float $attribute): array => [
    'id' => $id, '_rankingScoreDetails' => [
        'words' => ['matchingWords' => $words], 'typo' => ['typoCount' => $typos],
        'proximity' => ['score' => 1.0], 'attribute' => ['score' => $attribute],
    ],
];
$ranking = [$rankingHit('exact-low', 2, 0, 0.2), $rankingHit('exact-high', 2, 0, 0.9),
    $rankingHit('typo-low', 2, 1, 0.2), $rankingHit('typo-high', 2, 1, 0.9),
    $rankingHit('typo-tie', 2, 1, 0.9), $rankingHit('fewer-words', 1, 1, 1.0)];
check(array_column(TypoRelevance::reorder($ranking), 'id') === ['exact-low', 'exact-high', 'typo-high', 'typo-tie', 'typo-low', 'fewer-words'], 'Reorder only equally matched typo buckets; preserve ties and exact matches');
$incompleteRanking = [$ranking[0], ['id' => 'missing']];
check(TypoRelevance::reorder($incompleteRanking) === $incompleteRanking, 'Preserve engine ordering when score details are missing');
check(Engine::indexName($channel, $language) === 'shopware_' . $channel . '_' . $language, 'Index isolation');
check(AuditIndexFieldsCommand::fieldValue(['customFields' => ['example' => ['one', 'two']]], 'customFields.example') === ['one', 'two'], 'Read nested indexed custom fields');
check(AuditIndexFieldsCommand::fieldValue(['customFields' => []], 'customFields.example') === null, 'Missing indexed field remains absent');
check(AuditIndexFieldsCommand::hasText(['', null, '  ', '0']), 'Count zero as an indexed text value');
check(!AuditIndexFieldsCommand::hasText(['', null, '  ']), 'Do not count empty indexed values');

try {
    Engine::indexName('../other', $language);
    throw new RuntimeException('Invalid index scope accepted');
} catch (InvalidArgumentException) {
    ++$checks;
}

$product = new SalesChannelProductEntity();
$product->setId(str_repeat('c', 32));
$product->setParentId(str_repeat('d', 32));
$product->setProductNumber('EL-001');
$product->setTranslated(['name' => 'Bachblüten Rescue', 'description' => '<p>Ruhe &amp; Gelassenheit</p>', 'keywords' => 'Notfall']);
$product->setCustomSearchKeywords(['Rescuetropfen']);
$translation = new ProductTranslationEntity();
$translation->setUniqueIdentifier('product-de');
$translation->setLanguageId($language);
$translation->setName('Bachblüten Rescue');
$translation->setDescription('<p>Ruhe &amp; Gelassenheit</p>');
$translation->setKeywords('Notfall');
$translation->setCustomSearchKeywords(['Rescuetropfen']);
$product->setTranslations(new ProductTranslationCollection([$translation]));
$unit = new UnitEntity();
$unitTranslation = new UnitTranslationEntity();
$unitTranslation->setUniqueIdentifier('unit-de');
$unitTranslation->setLanguageId($language);
$unitTranslation->setShortCode('ml');
$unit->setTranslations(new UnitTranslationCollection([$unitTranslation]));
$product->setUnit($unit);
$unitDocument = (new DocumentBuilder())->build($product, $language, ['name', 'unitShortCode']);
check($unitDocument['unitShortCode'] === 'ml', 'Configured unit abbreviation must use the current translation');
check(LanguageText::field($unit, str_repeat('f', 32), 'shortCode') === null, 'Unit abbreviation must not fall back across languages');
$active = new CategoryEntity();
$active->setId(str_repeat('e', 32));
$active->setActive(true);
$active->setTranslated(['name' => 'Blütenessenzen']);
$categoryTranslation = new CategoryTranslationEntity();
$categoryTranslation->setUniqueIdentifier('category-de');
$categoryTranslation->setLanguageId($language);
$categoryTranslation->setName('Blütenessenzen');
$active->setTranslations(new CategoryTranslationCollection([$categoryTranslation]));
$inactive = new CategoryEntity();
$inactive->setId(str_repeat('f', 32));
$inactive->setActive(false);
$inactive->setTranslated(['name' => 'Interne Kategorie']);
$product->setCategories(new CategoryCollection([$active, $inactive]));
$document = (new DocumentBuilder())->build($product, $language);
check($document['name'] === 'Bachblüten Rescue', 'Use translated product name');
check($document['groupId'] === str_repeat('d', 32), 'Group sibling variants');
check($document['description'] === 'Ruhe & Gelassenheit', 'Strip description HTML');
check($document['keywords'] === 'Notfall' && $document['customSearchKeywords'] === ['Rescuetropfen'], 'Include custom search keywords separately');
check($document['categories'] === ['Blütenessenzen'], 'Exclude inactive categories');
check((new DocumentBuilder())->build($product, $channel) === null, 'Never index a fallback name in another language');
$unnamedTranslation = new ProductTranslationEntity();
$unnamedTranslation->setUniqueIdentifier('product-unnamed');
$unnamedTranslation->setLanguageId(str_repeat('9', 32));
$unnamedTranslation->setKeywords('local-only-keyword');
$product->getTranslations()->add($unnamedTranslation);
$unnamedDocument = (new DocumentBuilder())->build($product, str_repeat('9', 32), ['name', 'keywords']);
check(($unnamedDocument['unnamed'] ?? false) === true && $unnamedDocument['keywords'] === 'local-only-keyword', 'Unnamed product can use selected current-language text');
check((new DocumentBuilder())->build($product, str_repeat('9', 32), ['name', 'categories', 'productNumber']) === null, 'Generic fields and identifiers do not qualify unnamed documents');
$unnamedTranslation->setName('<style>hiddenname</style>');
check((new DocumentBuilder())->build($product, str_repeat('9', 32), ['name', 'categories', 'productNumber']) === null, 'Invisible name markup cannot qualify a generic unnamed product');
$unnamedTranslation->setName(null);
$unnamedTranslation->setDescription('<p> </p>');
check((new DocumentBuilder())->build($product, str_repeat('9', 32), ['description']) === null, 'Empty HTML does not qualify an unnamed product');
$unnamedTranslation->setDescription('<style>red</style><script>foreignterm()</script>');
check((new DocumentBuilder())->build($product, str_repeat('9', 32), ['description']) === null, 'Invisible script and style do not qualify an unnamed product');
$unnamedTranslation->setDescription('<p>Lokaler Text</p><script>foreignterm()</script>');
$visibleDocument = (new DocumentBuilder())->build($product, str_repeat('9', 32), ['description']);
check(($visibleDocument['description'] ?? '') === 'Lokaler Text', 'Only visible current-language description enters the index');
$english = new ProductTranslationEntity();
$english->setUniqueIdentifier('product-en');
$english->setLanguageId($channel);
$english->setName('Rescue drops');
$product->getTranslations()->add($english);
$englishDocument = (new DocumentBuilder())->build($product, $channel);
check($englishDocument['name'] === 'Rescue drops', 'Use only requested language');
check($englishDocument['description'] === '', 'No German fallback description in English index');
check($englishDocument['keywords'] === '', 'No German fallback keywords in English index');
check($englishDocument['categories'] === [], 'No German fallback categories in English index');
check(!array_key_exists('tags', $englishDocument), 'Untranslated global tags cannot leak languages');
$translation->setName('Blüten-Essenz');
$english->setName('Flower-Essence');
$germanAliases = (new DocumentBuilder())->build($product, $language, ['name']);
$englishAliases = (new DocumentBuilder())->build($product, $channel, ['name']);
check($germanAliases['nameHyphenAliases'] === ['BlütenEssenz'], 'German aliases derive only from German names');
check($englishAliases['nameHyphenAliases'] === ['FlowerEssence'], 'English aliases derive only from English names');
$translation->setDescription('Local description');
check(!isset((new DocumentBuilder())->build($product, $language, ['description'])['nameHyphenAliases']), 'Excluding names also excludes derived aliases');
$translation->setName('Bachblüten Rescue');
$translation->setDescription('<p>Ruhe &amp; Gelassenheit</p>');
$english->setName('Rescue drops');
$translation->setCustomFields(['search_themes' => '<p>Geborgenheit &amp; Vertrauen</p>', 'internal_note' => 'Nicht indizieren']);
$fields = IndexFields::parse('["customFields.search_themes", "name"]');
$customDocument = (new DocumentBuilder())->build($product, $language, $fields);
check($customDocument['customFields']['search_themes'] === 'Geborgenheit & Vertrauen', 'Configured custom field indexed and HTML removed');
check(!isset($customDocument['customFields']['internal_note']), 'Unselected custom fields excluded');
check(!isset($customDocument['description'], $customDocument['keywords'], $customDocument['productNumber']), 'Unselected standard fields excluded');
check(($customDocument['folded']['name'] ?? null) === 'bachblueten rescue' && !isset($customDocument['folded']['description'], $customDocument['folded']['manufacturer']), 'Only configured text contributes folded aliases');
check(DocumentBuilder::settings([], $fields)['searchableAttributes'] === ['customFields.search_themes', 'name', 'nameHyphenAliases', 'folded.customFields__search_themes', 'folded.name'], 'Configured priority and folded aliases are preserved');
check(NameAliases::hyphenCompounds('Blüten-Essenz und Rose‐Water') === ['BlütenEssenz', 'RoseWater'], 'Derive local alphabetic hyphen compounds');
check(NameAliases::hyphenCompounds('Alpha-Beta-Gamma-Delta') === ['AlphaBeta', 'GammaDelta', 'AlphaBetaGammaDelta'], 'Keep pairwise aliases and join complete multi-hyphen names');
check(NameAliases::hyphenCompounds('Alpha-Beta‐Gamma') === ['AlphaBeta', 'AlphaBetaGamma'], 'Join mixed Unicode hyphen compounds');
check(NameAliases::hyphenCompounds('ABC-123 A-B AB-CD') === [], 'Do not generate aliases for identifiers or very short pieces');
check(!in_array('nameHyphenAliases', DocumentBuilder::settings([], ['description'])['searchableAttributes'], true), 'Do not add name-derived aliases when name is excluded');
check(!in_array('popularity:desc', DocumentBuilder::settings([], $fields, 'de-DE')['rankingRules'], true), 'Search does not use historical popularity');
check(DocumentBuilder::settings([], $fields, 'de-DE')['typoTolerance']['minWordSizeForTypos']['oneTypo'] === 4, 'German short-word typo tolerance');
check(DocumentBuilder::settings([], $fields, 'en-GB')['typoTolerance']['minWordSizeForTypos']['oneTypo'] === 4, 'English supports guarded short-word typo tolerance');
check((new DocumentBuilder())->build($product, $channel, $fields)['customFields']['search_themes'] === '', 'Custom fields cannot fallback across languages');
$parent = new SalesChannelProductEntity();
$parentTranslation = new ProductTranslationEntity();
$parentTranslation->setUniqueIdentifier('parent-de');
$parentTranslation->setLanguageId($language);
$parentTranslation->setCustomFields(['search_themes' => 'Vertrauen', 'parent_only' => 'Ruhe']);
$parent->setTranslations(new ProductTranslationCollection([$parentTranslation]));
$product->setParent($parent);
check(LanguageText::customField($product, $language, 'parent_only') === 'Ruhe', 'Inherit a missing custom field from the parent in the same language');
check(LanguageText::customField($product, $language, 'search_themes') === '<p>Geborgenheit &amp; Vertrauen</p>', 'Variant custom fields override inherited values');
check(LanguageText::customField($product, $channel, 'parent_only') === null, 'Do not inherit a parent custom field across languages');
check(IndexFields::parse('') === IndexFields::DEFAULTS, 'Default field selection');
$litreId = '00000000000000000000000000100031';
$quantityUnits = QuantityUnits::parse(json_encode([
    '00000000000000000000000000100031' => ['symbol'=>'ml','factor'=>1000],
    '00000000000000000000000000100032' => ['symbol'=>'ml','factor'=>1],
    '00000000000000000000000000100033' => ['symbol'=>'g','factor'=>1000],
    '00000000000000000000000000100034' => ['symbol'=>'g','factor'=>1],
], JSON_THROW_ON_ERROR));
$variant = new SalesChannelProductEntity();
$variant->setId(str_repeat('a', 32));
$variant->setParentId(str_repeat('b', 32));
$variant->setUnitId('00000000000000000000000000100031');
$variant->setPurchaseUnit(0.03);
check(VariantQuantityLabel::forProduct($variant, 'Essence', $language, 'de-DE', $quantityUnits) === '30 ml', 'Distinguish a variant with a neutral converted quantity');
check(VariantQuantityLabel::forProduct($variant, 'Essence 30 ml', $language, 'en-GB', $quantityUnits) === null, 'Do not repeat the same quantity already in the product name');
$variant->setPurchaseUnit(0.0001);
check(VariantQuantityLabel::forProduct($variant, 'Essence', $language, 'de-DE', $quantityUnits) === '0,1 ml' && VariantQuantityLabel::forProduct($variant, 'Essence', $language, 'en-GB', $quantityUnits) === '0.1 ml', 'Show the exact quantity using the current locale decimal separator');
$variant->setUnit($unit);
$variant->setUnitId('00000000000000000000000000100032');
check(VariantQuantityLabel::forProduct($variant, 'Essence', $language, 'de-DE', []) === '0,0001 ml', 'Use the current language SI unit without a configured conversion');
check(VariantQuantityLabel::forProduct($variant, 'Essence', str_repeat('f', 32), 'en-GB', []) === null, 'Do not use another language unit translation');
$variant->setParentId(null);
check(VariantQuantityLabel::forProduct($variant, 'Essence', $language, 'de-DE', $quantityUnits) === null, 'Do not attach a variant size to a parent product');
$variant->setParentId(str_repeat('b', 32));
$variant->setProductNumber('ITEM-01');
$option = new PropertyGroupOptionEntity();$option->setId(str_repeat('1', 32));
$optionDe = new PropertyGroupOptionTranslationEntity();$optionDe->setUniqueIdentifier('option-de');$optionDe->setLanguageId($language);$optionDe->setName('Rot');
$optionEn = new PropertyGroupOptionTranslationEntity();$optionEn->setUniqueIdentifier('option-en');$optionEn->setLanguageId($channel);$optionEn->setName('Red');
$option->setTranslations(new PropertyGroupOptionTranslationCollection([$optionDe, $optionEn]));
$variant->setOptions(new PropertyGroupOptionCollection([$option]));
check(VariantOptionLabels::forProduct($variant, 'Essence', $language, '30 ml') === ['labels' => ['Rot'], 'showNumber' => false], 'Show the option in the requested language');
check(VariantOptionLabels::forProduct($variant, 'Essence', $channel, '30 ml') === ['labels' => ['Red'], 'showNumber' => false], 'Never borrow the German option into English');
check(VariantOptionLabels::forProduct($variant, 'Essence Rot', $language, '30 ml') === ['labels' => [], 'showNumber' => false], 'Do not duplicate an option already in the name');
check(VariantOptionLabels::forProduct($variant, 'Essence', str_repeat('f', 32), '30 ml') === ['labels' => [], 'showNumber' => true], 'Use an article number when an option translation is missing');
$optionDe->setName('30 ml');
check(VariantOptionLabels::forProduct($variant, 'Essence', $language, '30 ml') === ['labels' => [], 'showNumber' => false], 'Do not repeat the same quantity as an option');
$variant->setOptions(new PropertyGroupOptionCollection());
check(VariantOptionLabels::forProduct($variant, 'Essence', $language, null) === ['labels' => [], 'showNumber' => true], 'An undescribed variant uses its article number');
$sibling = new SalesChannelProductEntity();$sibling->setId(str_repeat('c', 32));$sibling->setParentId(str_repeat('b', 32));$sibling->setProductNumber('ITEM-02');
$variantNames = [$variant->getId() => 'Essence', $sibling->getId() => 'Essence'];
$sameSizes = [$variant->getId() => '30 ml', $sibling->getId() => '30 ml'];
$otherSizes = [$variant->getId() => '30 ml', $sibling->getId() => '50 ml'];
check(VariantOptionLabels::addNumbersForCollisions([$variant, $sibling], $variantNames, $sameSizes, [], []) === [$variant->getId() => 'ITEM-01', $sibling->getId() => 'ITEM-02'], 'Identically described siblings receive unique article numbers');
check(VariantOptionLabels::addNumbersForCollisions([$variant, $sibling], $variantNames, $otherSizes, [], []) === [], 'Different sizes already distinguish sibling variants');
$sibling->setParentId(str_repeat('d', 32));
check(VariantOptionLabels::addNumbersForCollisions([$variant, $sibling], $variantNames, $sameSizes, [], []) === [], 'Unrelated products do not create sibling collisions');
check(QuantityUnits::tokens('00000000000000000000000000100032', 8.0, $quantityUnits) === ['8 ml', '8ml', '0.008 l', '0.008l'], 'Index configured millilitre quantities and equivalent litres');
check(QuantityUnits::tokens('00000000000000000000000000100033', 0.05, $quantityUnits) === ['50 g', '50g', '0.05 kg', '0.05kg'], 'Index configured kilogram quantities as grams and kilograms');
check(QuantityUnits::tokens('00000000000000000000000000100034', 50.0, $quantityUnits) === ['50 g', '50g', '0.05 kg', '0.05kg'], 'Index configured gram quantities');
check(ReferenceUnitLabels::label($litreId, 1.0, 'Deutsch', [$litreId=>'litro'], $quantityUnits) === ['amount'=>1.0,'unit'=>'litro'], 'Use only the current language translation for a reference unit');
check(ReferenceUnitLabels::label($litreId, 1.0, 'l', [], $quantityUnits) === ['amount'=>1.0,'unit'=>'l'], 'A neutral SI symbol needs no translation');
check(ReferenceUnitLabels::label($litreId, 1.0, 'Deutsch', [], $quantityUnits) === ['amount'=>'1000','unit'=>'ml'], 'Convert an untranslated unit to the explicitly mapped neutral symbol');
check(ReferenceUnitLabels::label($litreId, 1.0, 'Deutsch', [], []) === null, 'Do not borrow a unit label from another language');
check(QuantityUnits::tokens($litreId, 0.01, $quantityUnits) === ['10 ml', '10ml', '0.01 l', '0.01l'], 'Convert the configured physical quantity into unit symbols');
check(QuantityUnits::tokens($litreId, 0.0001, $quantityUnits) === ['0.1 ml', '0.1ml', '0.0001 l', '0.0001l'], 'Preserve fractional quantities');
check(QuantityUnits::queryKey('Produkt 1 ml') === 'ml:1', 'Recognize an explicit whole-unit quantity');
check(QuantityUnits::isStandalone('30 ml') && QuantityUnits::isStandalone('30ml') && !QuantityUnits::isStandalone('ab 30 ml') && !QuantityUnits::isStandalone('Emergency 30 ml'), 'Only a pure quantity retains standalone ranking');
check(QuantityUnits::queryKey('Produkt 0,10ml') === 'ml:0.1', 'Normalize decimal comma and trailing zeroes');
check(QuantityUnits::queryKey('Produkt 00.010 l') === 'ml:10', 'Normalize leading and fractional zeroes and convert litres');
check(QuantityUnits::queryKey('Produkt 1 kg') === 'g:1000' && QuantityUnits::queryKey('Produkt 1 l') === 'ml:1000', 'Keep mass and volume separate while converting SI units');
check(QuantityUnits::tokens($litreId, 1.001, [$litreId => ['symbol' => 'kg', 'factor' => 1]]) === ['1.001 kg', '1.001kg', '1001 g', '1001g'] && QuantityUnits::key($litreId, 1.001, [$litreId => ['symbol' => 'kg', 'factor' => 1]]) === 'g:1001', 'Convert known mass without treating an exact product value as an ambiguous query');
check(QuantityUnits::tokens($litreId, 0.00000000001, $quantityUnits) === ['0.00000001 ml', '0.00000001ml'], 'Do not round an unsupported tiny equivalent');
check(QuantityUnits::tokens($litreId, 0.000000000001, $quantityUnits) === [], 'Do not index a quantity rounded to zero');
check(QuantityUnits::tokens($litreId, 0.000000000014, $quantityUnits) === [], 'Do not index a materially rounded small product quantity');
check(QuantityUnits::tokens($litreId, 0.001000000004, $quantityUnits) === [], 'Do not promote a different size after rounding a custom factor conversion');
check(QuantityUnits::tokens($litreId, 0.029, $quantityUnits)[0] === '29 ml', 'Allow ordinary floating point representation error');
check(QuantityUnits::queryKey('Produkt 1 ml 2 ml') === null && QuantityUnits::queryKey('AB-10ml') === null && QuantityUnits::queryKey('Produkt -1 ml') === null, 'Do not guess at multiple quantities, article codes or negative amounts');
check(QuantityUnits::queryKey('Produkt 1 ml + 2 ml') === null && QuantityUnits::queryKey('Produkt 1ml-2ml') === null && QuantityUnits::queryKey('Produkt - 1 ml') === null && QuantityUnits::queryKey('Produkt −1 ml') === null && QuantityUnits::queryKey('Produkt + 1 ml') === null, 'Do not prioritize one amount in signed quantities or ranges');
check(PartialMatches::withoutTrailingQuantity('Flower Essence 0.03 l') === 'Flower Essence' && PartialMatches::withoutTrailingQuantity('Blüte 30ml') === 'Blüte', 'Keep the entire name when the trailing amount is safely separable');
check(PartialMatches::withoutTrailingQuantity('AB-30ml') === null && PartialMatches::withoutTrailingQuantity('Flower 1 ml + 2 ml') === null && PartialMatches::withoutTrailingQuantity('Flower 1,000 ml') === null && PartialMatches::withoutTrailingQuantity('10 ml Flower') === null && PartialMatches::withoutTrailingQuantity('Flower 30ml extra') === null && PartialMatches::withoutTrailingQuantity('Flower 2 x 30ml') === null, 'Never drop an ambiguous quantity, code, nonfinal amount or pack multiplier');
check(QuantityUnits::queryKey('Produkt 1,000 ml') === null && QuantityUnits::queryKey('Produkt 1.000 ml') === null && QuantityUnits::queryKey('Produkt 0,100 ml') === 'ml:0.1', 'Do not guess ambiguous thousands separators');
check(QuantityUnits::key($litreId, 0.001001, $quantityUnits) === 'ml:1.001', 'Preserve known product quantities even when user spelling would be ambiguous');
$quantityHits = [
    ['id'=>'fraction', 'quantityKey'=>'ml:0.1', '_rankingScoreDetails'=>['words'=>['matchingWords'=>1], 'typo'=>['typoCount'=>0]]],
    ['id'=>'whole', 'quantityKey'=>'ml:1', '_rankingScoreDetails'=>['words'=>['matchingWords'=>1], 'typo'=>['typoCount'=>0]]],
    ['id'=>'weaker', 'quantityKey'=>'ml:1', '_rankingScoreDetails'=>['words'=>['matchingWords'=>0], 'typo'=>['typoCount'=>0]]],
];
check(array_column(QuantityRelevance::reorder($quantityHits, 'ml:1'), 'id') === ['whole', 'fraction', 'weaker'], 'Prefer the exact quantity only within equally matched word and typo groups');
check(QuantityRelevance::reorder($quantityHits, null) === $quantityHits, 'Ambiguous quantities retain the original order');
$rank = ['words'=>['matchingWords'=>2], 'typo'=>['typoCount'=>0]];
$weakerRank = ['words'=>['matchingWords'=>1], 'typo'=>['typoCount'=>0]];
$combinedHits = [['id'=>'other', 'quantityKey'=>'ml:30', '_rankingScoreDetails'=>$rank], ['id'=>'matching','quantityKey'=>'ml:30','_rankingScoreDetails'=>$rank], ['id'=>'weak','quantityKey'=>'ml:30','_rankingScoreDetails'=>$weakerRank]];
$combinedNames = ['other'=>'Emergency Roll On', 'matching'=>'Emergency Essence', 'weak'=>'Emergency Essence'];
check(array_column(QuantityRelevance::promoteCombined($combinedHits, 'ml:30', $combinedNames, 'Emergency Essence'), 'id') === ['matching','other','weak'], 'Promote exact phrase and quantity only inside strongest group');
check(QuantityRelevance::promoteCombined($combinedHits, 'ml:30', $combinedNames, 'Emerg') === $combinedHits, 'A prefix cannot match whole product words');
check(QuantityRelevance::promoteCombined($combinedHits, null, $combinedNames, 'Emergency') === $combinedHits, 'Ambiguous quantity preserves ranking');
check(QuantityRelevance::wholeWords('Bachblueten', 'Bachblüten Essenz') && !QuantityRelevance::wholeWords('Bachblueten', 'Roll On'), 'Names match all complete folded words');
check(array_column(QuantityRelevance::promoteCombined($combinedHits, 'ml:30', ['matching'=>['Essenz','Bachblüten']], 'Bachblueten'), 'id')[0] === 'matching', 'A matching category or manufacturer can establish phrase relevance');
check(array_column(QuantityRelevance::promoteCombined($combinedHits, 'ml:30', ['matching'=>['Bach','Blüten']], 'Bach Blüten'), 'id')[0] === 'other', 'Phrase words must match within the same field');
check(array_column(QuantityRelevance::preciseCombined([['id'=>'exact','_rankingScoreDetails'=>['typo'=>['typoCount'=>0]]],['id'=>'typo','_rankingScoreDetails'=>['typo'=>['typoCount'=>1]]]]), 'id') === ['exact'], 'Combined search keeps precise words when available');
check(QuantityRelevance::preciseCombined([['id'=>'typo','_rankingScoreDetails'=>['typo'=>['typoCount'=>1]]]])[0]['id'] === 'typo', 'Keep fallback hits if no precise match exists');
foreach (['Emerg 30 ml', 'Rose 10 ml', 'Oel 10 ml'] as $combinedQuery) {
    $phrase = PartialMatches::quantityPhrase($combinedQuery);
    check($phrase !== null && QuantityRelevance::usesPreciseCombined($phrase), 'Short combined product phrase uses precise matching: '.$combinedQuery);
    $sample = [['id'=>'exact', '_rankingScoreDetails'=>['typo'=>['typoCount'=>0]]], ['id'=>'typo', '_rankingScoreDetails'=>['typo'=>['typoCount'=>1]]]];
    check(array_column(QuantityRelevance::preciseCombined($sample), 'id') === ['exact'], 'Short combined product phrase excludes typo matches when exact matches exist');
}
check(!QuantityRelevance::usesPreciseCombined('Emergency') && !QuantityRelevance::usesPreciseCombined('ab'), 'Precision applies only to single 3–5-letter product terms');

check(QuantityRelevance::reorder([['id'=>'unknown']], 'ml:1') === [['id'=>'unknown']], 'Missing ranking metadata retains the original order');

check(QuantityUnits::tokens(str_repeat('f', 32), 0.01, $quantityUnits) === [], 'Do not guess unknown units');
check(QuantityUnits::tokens($litreId, 0.01, QuantityUnits::parse('{}')) === [], 'An explicit empty mapping disables quantity indexing');
check(in_array('quantity', IndexFields::DEFAULTS, true), 'Default field selection includes physical quantity');
$product->setUnitId($litreId);
$product->setPurchaseUnit(0.01);
$germanQuantity = (new DocumentBuilder())->build($product, $language, ['name', 'quantity'], $quantityUnits);
$englishQuantity = (new DocumentBuilder())->build($product, $channel, ['name', 'quantity'], $quantityUnits);
check($germanQuantity['quantity'] === ['10 ml', '10ml', '0.01 l', '0.01l'] && $englishQuantity['quantity'] === ['10 ml', '10ml', '0.01 l', '0.01l'] && $germanQuantity['quantityKey'] === 'ml:10' && $englishQuantity['quantityKey'] === 'ml:10', 'Physical quantity is language neutral without translated text');
check(!isset((new DocumentBuilder())->build($product, $language, ['name'], $quantityUnits)['quantityKey']), 'Unselected quantity key is not indexed');
check(!isset((new DocumentBuilder())->build($product, $language, ['name'], $quantityUnits)['quantity']), 'Unselected quantity is not indexed');
$product->setPurchaseUnit(0.001000000004);
$roundedQuantity = (new DocumentBuilder())->build($product, $language, ['name', 'quantity'], $quantityUnits);
check($roundedQuantity['quantity'] === [] && !isset($roundedQuantity['quantityKey']), 'A materially rounded size cannot enter a product document or affect relevance');
$product->setUnitId(null);
$product->setPurchaseUnit(null);

foreach (['[]', '[1]', '{"wrong":{"symbol":"ml","factor":1000}}', '{"00000000000000000000000000100031":{"symbol":"bad","factor":1000}}', '{"00000000000000000000000000100031":{"symbol":"ml","factor":-1}}'] as $invalidQuantity) {
    try { QuantityUnits::parse($invalidQuantity); throw new RuntimeException('Invalid quantity mapping accepted'); }
    catch (InvalidArgumentException) { check(true, 'Reject invalid quantity mapping'); }
}

foreach (['searchKeywords', 'product.searchKeywords'] as $unsafeField) {
    try {
        IndexFields::parse(json_encode([$unsafeField], JSON_THROW_ON_ERROR));
        throw new RuntimeException('Cross-language Shopware keyword index was accepted');
    } catch (InvalidArgumentException $error) {
        check(str_contains($error->getMessage(), 'fallback-language'), 'Explain why generated Shopware keywords are excluded');
    }
}
check(in_array('customFields.example', IndexFields::parse('["name","customFields.example"]'), true), 'Custom fields can be configured explicitly');
check(IndexFields::text('<p>Blüten</p><p>Ruhe</p><br>Vertrauen') === 'Blüten Ruhe Vertrauen', 'Preserve word boundaries between adjacent HTML blocks');
check(IndexFields::text('<p>Lavendel</p><STYLE>.fake{color:red}</STYLE><script>hiddenSearchTerm()</script><p>Öl</p>') === 'Lavendel Öl', 'Exclude script and style contents from indexed text');
check(IndexFields::text('<style>unrelated</style><script>irrelevant()</script>') === '', 'Non-visible HTML alone does not create searchable content');
check(IndexFields::forLocale('["name"]', '{"de-DE":["name","customFields.german"]}', 'en-GB') === ['name'], 'Other languages use the global field selection');
check(IndexFields::forLocale('["name"]', '{"de-DE":["name","customFields.german"]}', 'de-DE') === ['name', 'customFields.german'], 'Apply only the matching language field override');
try {
    IndexFields::forLocale('', '{"en-GB":["bad.field"]}', 'de-DE');
    throw new RuntimeException('Invalid field override accepted');
} catch (InvalidArgumentException) {
    ++$checks;
}
check((new DocumentBuilder())->build($product, $language, ['options'])['options'] === [], 'Options are a supported standard field');
foreach (['[]', '["name","name"]', '["customer.email"]', '["customFields.a.b"]', '{"name":1}'] as $invalid) {
    try {
        IndexFields::parse($invalid);
        throw new RuntimeException('Invalid field configuration accepted');
    } catch (InvalidArgumentException) {
        ++$checks;
    }
}
check(count((new DocumentBuilder())->build($product, $language, IndexFields::STANDARD)) > 10, 'All advertised standard fields are supported');
$input = SearchInput::fromRequest(new Request(['q' => ' Bachblüten ', 'page' => '-2', 'sort' => 'price-asc', 'manufacturer' => [$channel]]));
check($input->query === 'Bachblüten' && $input->page === 1 && $input->minPrice === null, 'Normalize search input');
try { SearchInput::fromRequest(new Request(['minPrice' => '4.0150'])); throw new RuntimeException('Removed price filter accepted'); } catch (\Symfony\Component\HttpKernel\Exception\BadRequestHttpException) { ++$checks; }
check($input->manufacturers === [$channel], 'Validated facet IDs');
$root = str_repeat('c', 32); $child = str_repeat('d', 32); $otherRoot = str_repeat('e', 32);
$selected = SearchInput::fromRequest(new Request(['q' => 'test', 'category' => [$root, $child, $otherRoot], 'manufacturer' => [$channel]]));
$withoutRoot = $selected->withoutCategory($root);
check($withoutRoot->categories === [$child, $otherRoot] && $withoutRoot->manufacturers === [$channel] && $withoutRoot->query === 'test', 'Ignore only the current navigation root in old or direct category filters');
check($selected->withoutCategory($otherRoot)->categories === [$root, $child], 'A different sales channel root is treated independently');
check($selected->withoutCategory(str_repeat('f', 32)) === $selected, 'Keep search input unchanged when its root is not selected');
check(Discovery::suggestions('{"de-DE":["schutz"],"en-GB":["care"]}', 'en-GB') === ['care'], 'Suggestions stay in their configured language');
check(Discovery::suggestions('{"de-DE":[]}', 'de-DE') === [], 'An empty list suppresses suggestions');
check(Discovery::suggestions('{broken', 'de-DE') === [], 'Invalid suggestion configuration does not break search');
check(Discovery::featured('{"de-DE":["aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"],"en-GB":[]}', 'en-GB') === [], 'Featured products are language-specific');
check(Discovery::featured('{"de-DE":["aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","bad"]}', 'de-DE') === [str_repeat('a', 32)], 'Only valid featured product identifiers are used');
check(Discovery::featured('', 'de-DE') === [] && Discovery::featured('', 'fr-FR') === [], 'Featured products have no shop-specific defaults');
check(Discovery::featured('{"de-DE":[]}', 'de-DE') === [], 'An explicit empty list disables reference highlights for the language');
check(Discovery::featured('', 'nl-NL') === [], 'Reference highlights are not borrowed by other languages');
check(SearchService::withoutMatchedParents(['parent', 'child', 'other'], ['parent']) === ['child', 'other'], 'Show matching variants without a duplicate parent');
$preciseFacets = [['id' => 'a', 'name' => 'A', 'count' => 25], ['id' => 'b', 'name' => 'B', 'count' => 3]];
$expandedFacets = [['id' => 'a', 'name' => 'A', 'count' => 30], ['id' => 'b', 'name' => 'B', 'count' => 7], ['id' => 'c', 'name' => 'C', 'count' => 2]];
check(SearchService::precisionFacetCounts($preciseFacets, $expandedFacets, 20) === [
    ['id' => 'a', 'name' => 'A', 'count' => 25], ['id' => 'b', 'name' => 'B', 'count' => 7], ['id' => 'c', 'name' => 'C', 'count' => 2],
], 'Facet counts follow per-choice precise/expanded thresholds');
check(SearchService::withoutMatchedParents(['parent'], []) === ['parent'], 'Keep a parent when no variant matched');
check(SearchService::matchedParents(['child' => 'parent'], ['parent' => true, 'child' => true], ['parent' => true]) === [], 'A variant excluded by active filters must not hide its parent');
check(SearchService::matchedParents(['child' => 'parent'], ['parent' => true, 'child' => true], ['child' => true]) === ['parent'], 'An eligible matching variant still hides its parent');
check(SearchService::matchedParents(['child' => 'parent'], ['parent' => true], ['child' => true]) === [], 'A partial variant must not hide a primary parent after filtering');
$priceInput = new SearchInput('', 1, 'relevance', [], [], 10.0, 15.0, false);
check(SearchService::filterByDisplayedPrice(['one', 'two', 'three', 'missing'], ['one' => 10.0, 'two' => 15.0, 'three' => 15.01], $priceInput) === ['one', 'two'], 'Filter by the actual displayed price and include the bounds');
foreach ([['sort' => 'random'], ['minPrice' => '-1'], ['minPrice' => '1e3'], ['minPrice' => '1.00001'], ['minPrice' => '20', 'maxPrice' => '10'], ['manufacturer' => ['bad-id']], ['q' => str_repeat('a', 151)]] as $invalid) {
    try {
        SearchInput::fromRequest(new Request($invalid));
        throw new RuntimeException('Invalid search request accepted');
    } catch (BadRequestHttpException) {
        ++$checks;
    }
}
check(!isset($document['price'], $document['customerId']), 'No contextual data in documents');
$settings = DocumentBuilder::settings(['rescue' => ['notfall']]);
check($settings['displayedAttributes'] === ['id', 'unnamed', 'quantityKey'], 'Only IDs, missing-name flags and normalized quantities are returned for hydration');
check($settings['typoTolerance']['disableOnAttributes'] === ['productNumber', 'ean', 'manufacturerNumber', 'quantity', 'nameHyphenAliases'], 'Exact identifiers, quantities and generated name aliases');
check(json_decode(json_encode(DocumentBuilder::settings([])), true)['synonyms'] === [], 'Empty synonyms encode as JSON object');
check(str_contains(json_encode(DocumentBuilder::settings([])), '"synonyms":{}'), 'Engine requires object for synonyms');

$settingsRequests = 0;
$settingsClient = new MockHttpClient(static function () use (&$settingsRequests) {
    ++$settingsRequests;
    return new MockResponse('["name","description"]');
});
$settingsEngine = new Engine($settingsClient, 'http://127.0.0.1:7700', 'test-key');
$publishedSettings = new \ShopwareMeilisearch\Search\PublishedSearchFields($settingsEngine, new \Symfony\Component\Cache\Adapter\ArrayAdapter());
$fetched = false;
check($publishedSettings->get('index-a', $fetched) === ['name','description'] && $fetched && $settingsRequests === 1, 'Read published fields on cache miss');
check($publishedSettings->get('index-a', $fetched) === ['name','description'] && !$fetched && $settingsRequests === 1, 'Warm published fields do not contact engine');
$publishedSettings->invalidate('index-a');
check($publishedSettings->get('index-a', $fetched) === ['name','description'] && $fetched && $settingsRequests === 2, 'Index publication invalidates cached fields');
check($publishedSettings->get('index-b', $fetched) === ['name','description'] && $fetched && $settingsRequests === 3, 'Published field caches are isolated by index');
$settingsFaultCount = 0;
$faultClient = new MockHttpClient(static function () use (&$settingsFaultCount) {
    ++$settingsFaultCount;
    return $settingsFaultCount === 1 ? new MockResponse('{"error":"unavailable"}', ['http_code'=>503]) : new MockResponse('["name"]');
});
$faultEngine = new Engine($faultClient, 'http://127.0.0.1:7700', 'test-key');
$faultFields = new \ShopwareMeilisearch\Search\PublishedSearchFields($faultEngine, new \Symfony\Component\Cache\Adapter\ArrayAdapter());
try { $faultFields->get('broken-index', $fetched); throw new RuntimeException('Expected settings failure'); } catch (\Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface) { ++$checks; }
check($faultFields->get('broken-index', $fetched) === ['name'] && $settingsFaultCount === 2, 'Failed settings reads are not cached');


$retryCalls = [];
$retryHttp = new MockHttpClient(static function ($method, $url, $options) use (&$retryCalls) {
    $retryCalls[] = json_decode($options['body'], true);
    return count($retryCalls) === 1
        ? new MockResponse('{"code":"invalid_search_attributes_to_search_on"}', ['http_code' => 400])
        : new MockResponse('{"hits":[{"id":"ok"}]}');
});
$retryEngine = new Engine($retryHttp, 'http://127.0.0.1:7700', 'test-key');
$retryFields = new \ShopwareMeilisearch\Search\PublishedSearchFields($retryEngine, new \Symfony\Component\Cache\Adapter\ArrayAdapter());
$retryLog = new \Monolog\Logger('test');
$retryHandler = new \Monolog\Handler\TestHandler();
$retryLog->pushHandler($retryHandler);
$retrySearch = new \ShopwareMeilisearch\Search\SearchRequest($retryEngine, $retryFields, $retryLog);
$retryCount = 0;
$retryResult = $retrySearch->execute('index-a', ['q'=>'oel','attributesToSearchOn'=>['missing']], 'Öl', $retryCount);
check(($retryResult['hits'][0]['id'] ?? '') === 'ok' && $retryCount === 2, 'Stale field restriction retries exactly once');
check(!isset($retryCalls[1]['attributesToSearchOn']) && $retryCalls[1]['q'] === 'Öl', 'Retry uses original query without stale restriction');
check($retryHandler->hasWarningRecords() && ($retryHandler->getRecords()[0]['context']['index'] ?? '') === 'index-a', 'Retry logs a warning with the index');
foreach ([['other_error', true, 1], ['invalid_search_attributes_to_search_on', false, 1], ['invalid_search_attributes_to_search_on', true, 2]] as [$errorCode, $restricted, $expectedCalls]) {
    $failureCount = 0;
    $faultHttp = new MockHttpClient(static function () use (&$failureCount, $errorCode) {
        ++$failureCount;
        return new MockResponse(json_encode(['code'=>$errorCode]), ['http_code'=>400]);
    });
    $faultEngine = new Engine($faultHttp, 'http://127.0.0.1:7700', 'test-key');
    $faultFields = new \ShopwareMeilisearch\Search\PublishedSearchFields($faultEngine, new \Symfony\Component\Cache\Adapter\ArrayAdapter());
    $faultSearch = new \ShopwareMeilisearch\Search\SearchRequest($faultEngine, $faultFields, new \Psr\Log\NullLogger());
    $payload = ['q'=>'test']; if ($restricted) $payload['attributesToSearchOn'] = ['name'];
    $calls = 0; $caught = false;
    try { $faultSearch->execute('index-a', $payload, 'test', $calls); } catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface) { $caught = true; }
    check($caught && $calls === $expectedCalls && $failureCount === $expectedCalls, 'Only the specific stale-field error can retry, at most once');
}


$requests = [];
$responses = [
    new MockResponse('{"taskUid":7}', ['http_code' => 202]),
    new MockResponse('{"status":"succeeded"}'),
    new MockResponse('{"message":"not found"}', ['http_code' => 404]),
    new MockResponse('{"taskUid":8}', ['http_code' => 202]),
    new MockResponse('{"status":"failed","error":{"code":"invalid_settings"}}'),
];
$http = new MockHttpClient(static function ($method, $url, $options) use (&$responses, &$requests) {
    $requests[] = [$method, $url, $options];
    return array_shift($responses);
});
$engine = new Engine($http, 'http://127.0.0.1:7700/', 'test-key');
$engine->mutate('POST', '/indexes', ['uid' => 'test', 'primaryKey' => 'id']);
check(count($requests) === 2, 'Await asynchronous mutations');
check($requests[0][1] === 'http://127.0.0.1:7700/indexes', 'Engine URL construction');
check(in_array('Authorization: Bearer test-key', $requests[0][2]['headers'], true), 'Private engine authentication');
check($requests[0][2]['no_proxy'] === '*', 'Search requests bypass environment proxies');
foreach (['https://example.com', 'http://127.0.0.1.example.com', 'http://user@127.0.0.1', 'http://127.0.0.1?remote=1', 'http://192.168.1.2:7700'] as $invalidUrl) {
    try {
        (new Engine(new MockHttpClient(), $invalidUrl, 'key'))->request('GET', '/health');
        throw new RuntimeException('Nonlocal search URL was accepted');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'loopback'), 'Reject nonlocal or malformed search URLs');
    }
}
$ipv6 = new Engine(new MockHttpClient([new MockResponse('{}')]), 'http://[::1]:7700', 'key');
check($ipv6->request('GET', '/health') === [], 'Accept IPv6 loopback');
check($engine->request('GET', '/indexes/missing', allowMissing: true) === null, 'Missing index handled');
$compatible = new Engine(new MockHttpClient([new MockResponse('{"pkgVersion":"1.56.0"}')]), 'http://127.0.0.1:7700', 'key');
$compatible->ensureCompatible();
$compatible->ensureCompatible();
++$checks;
try {
    (new Engine(new MockHttpClient([new MockResponse('{"pkgVersion":"1.8.0"}')]), 'http://127.0.0.1:7700', 'key'))->ensureCompatible();
    throw new RuntimeException('Unsupported engine version was accepted');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), '1.9'), 'Reject unsupported search engine versions');
}
try {
    $engine->mutate('PATCH', '/indexes/test/settings', []);
    throw new RuntimeException('Failed task was accepted');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'invalid_settings'), 'Surface failed tasks');
}
try {
    (new Engine($http, 'http://127.0.0.1:7700', ''))->request('GET', '/health');
    throw new RuntimeException('Empty key was accepted');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'not configured'), 'Require explicit engine key');
}

$xml = new DOMDocument();
$xml->load(dirname(__DIR__) . '/src/Resources/config/config.xml');
check($xml->schemaValidate((getenv('SHOPWARE_ROOT') ?: dirname(__DIR__, 4)) . '/vendor/shopware/core/System/SystemConfig/Schema/config.xsd'), 'Shopware configuration schema');
echo "$checks checks passed.\n";
