<?php declare(strict_types=1);
// Read-only comparison against Shopware's language-specific product translations.
use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Search\CurrentLanguageNames;
use ShopwareMeilisearch\Search\LanguageText;
use ShopwareMeilisearch\Search\ParentTranslations;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;

$url=parse_url(getenv('DATABASE_URL')?:'');
if (!is_array($url) || !preg_match('/^\/el_search_test_[a-f0-9]+$/D',$url['path']??'')) throw new RuntimeException('Isolated test database required.');
foreach (['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name) if (getenv($name)!==false) $_SERVER[$name]=$_ENV[$name]=getenv($name);
$root=getenv('PROJECT_ROOT')?:'';
if (!is_file($root.'/vendor/autoload.php')) throw new RuntimeException('Isolated project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$container=$kernel->getContainer();$db=$container->get(Connection::class);
$domains=$db->fetchAllAssociative("SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel, LOWER(HEX(d.language_id)) language, l.code locale FROM sales_channel_domain d JOIN language lang ON lang.id=d.language_id JOIN locale l ON l.id=lang.locale_id JOIN sales_channel c ON c.id=d.sales_channel_id WHERE c.active=1 AND l.code IN ('de-DE','en-GB','es-ES','fr-FR','it-IT')");
if (count($domains)!==5) throw new RuntimeException('Five test languages required.');
$ids=$db->fetchFirstColumn("SELECT LOWER(HEX(id)) FROM product WHERE version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND parent_id IS NOT NULL ORDER BY id LIMIT 300");
$ids=array_merge($ids,$db->fetchFirstColumn("SELECT LOWER(HEX(id)) FROM product WHERE version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND parent_id IS NULL ORDER BY id LIMIT 300"));
if(count($ids)!==600) throw new RuntimeException('Test catalog needs variants and parent products.');
$names=new CurrentLanguageNames($db);$parentLoader=new ParentTranslations($container->get('product.repository'));$repo=$container->get('sales_channel.product.repository');$factory=$container->get(CachedSalesChannelContextFactory::class);
foreach($domains as $domain){
 $context=$factory->create(Uuid::randomHex(),$domain['channel'],[SalesChannelContextService::LANGUAGE_ID=>$domain['language']]);
 $expected=[];$seen=[];
 foreach(array_chunk($ids,200) as $batch){
  $criteria=(new Criteria($batch))->setLimit(200);$criteria->addAssociation('translations');$entities=$repo->search($criteria,$context)->getEntities();$parentLoader->load($entities,$context);
  foreach($entities as $product){$seen[$product->getId()]=true;$name=LanguageText::field($product,$domain['language'],'name');if(is_string($name)&&trim($name)!=='')$expected[$product->getId()]=true;}
 }
 // Compare only products returned by the sales-channel repository; its normal
 // visibility checks are independent of the name check under test.
 $actual=array_fill_keys($names->keep($ids,$context),true);
 foreach($seen as $id=>$_)if(isset($actual[$id])!==isset($expected[$id]))throw new RuntimeException('Current language name mismatch in '.$domain['locale']);
 $ordered=$names->keep($ids,$context);if($ordered!==array_values(array_filter($ids,static fn(string $id):bool=>isset($actual[$id]))))throw new RuntimeException('Candidate ordering changed.');
 echo $domain['locale'].': '.count($expected)." current names retained.\n";
}
$kernel->shutdown();
