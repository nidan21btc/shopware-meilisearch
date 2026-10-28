<?php declare(strict_types=1);
require_once __DIR__.'/pending_revision.php';
// Verify missing reference-unit reporting in an isolated Shopware copy.
use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Command\IndexCommand;
use ShopwareMeilisearch\ScheduledTask\ReindexTaskHandler;
use ShopwareMeilisearch\Search\DirtyState;
use ShopwareMeilisearch\Search\DocumentBuilder;
use ShopwareMeilisearch\Search\Engine;
use ShopwareMeilisearch\Search\Indexer;
use ShopwareMeilisearch\Search\ParentTranslations;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\HttpClient;

$database=parse_url(getenv('DATABASE_URL')?:'');$engineUrl=parse_url(getenv('SHOPWARE_MEILISEARCH_URL')?:'');$shop=parse_url(getenv('EL_SEARCH_TEST_URL')?:'');$host=getenv('EL_SEARCH_TEST_HOST')?:'';
if(!is_array($database)||!preg_match('/^\/el_search_test_[a-f0-9]+$/D',$database['path']??'')||!in_array($database['host']??'', ['localhost','127.0.0.1'],true)
 ||!is_array($engineUrl)||($engineUrl['scheme']??'')!=='http'||($engineUrl['host']??'')!=='127.0.0.1'
 ||!is_array($shop)||($shop['scheme']??'')!=='http'||($shop['host']??'')!=='127.0.0.1'||!preg_match('/^[a-zA-Z0-9.-]+$/D',$host))throw new RuntimeException('Isolated database and loopback test services required.');
foreach(['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name)if(getenv($name)!==false)$_SERVER[$name]=$_ENV[$name]=getenv($name);
$root=getenv('PROJECT_ROOT')?:'';if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('Test project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$container=$kernel->getContainer();$db=$container->get(Connection::class);$config=$container->get(SystemConfigService::class);
if((int)$db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1')||(int)$db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1'))throw new RuntimeException('Apps and webhooks must be disabled.');
$rows=$db->fetchAllAssociative("SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel,LOWER(HEX(d.language_id)) language,o.code locale FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code IN ('de-DE','en-GB','es-ES','fr-FR','it-IT')");
$domains=[];foreach($rows as $row)$domains[$row['locale']]=$row;
if(count($domains)!==5||count(array_unique(array_column($domains,'channel')))!==1)throw new RuntimeException('Five languages in one active test channel are required.');
$channel=$domains['de-DE']['channel'];if(!$config->getBool('ShopwareMeilisearch.config.enabled',$channel))throw new RuntimeException('Enable local search in the test channel first.');
$engine=new Engine(HttpClient::create(),getenv('SHOPWARE_MEILISEARCH_URL')?:'',getenv('SHOPWARE_MEILISEARCH_KEY')?:'');
$unitId=(getenv('EL_TEST_LITRE_UNIT_ID') ?: '');if(!preg_match('/^[a-f0-9]{32}$/D',$unitId))throw new RuntimeException('Set EL_TEST_LITRE_UNIT_ID for the isolated fixture.');
if((\ShopwareMeilisearch\Search\QuantityUnits::parse($config->getString('ShopwareMeilisearch.config.quantityUnits',$channel))[$unitId]['symbol']??null)!=='ml')throw new RuntimeException('A configured test unit mapping is required.');
$original=$db->fetchAssociative('SELECT name FROM unit_translation WHERE unit_id=UNHEX(:unit) AND language_id=UNHEX(:language)',['unit'=>$unitId,'language'=>$domains['de-DE']['language']]);
if($original===false)throw new RuntimeException('German test unit translation is required.');
foreach($domains as $locale=>$domain)if($locale!=='de-DE'&&(int)$db->fetchOne('SELECT COUNT(*) FROM unit_translation WHERE unit_id=UNHEX(:unit) AND language_id=UNHEX(:language)',['unit'=>$unitId,'language'=>$domain['language']]))throw new RuntimeException('The other test languages must lack the unit translation.');
$key='ShopwareMeilisearch.config.quantityUnits';
if((int)$db->fetchOne('SELECT COUNT(*) FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NOT NULL',['key'=>$key]))throw new RuntimeException('No channel-specific unit mapping allowed.');
$prior=$db->fetchAssociative('SELECT configuration_value FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$key]);
$state=new DirtyState($db);$indexer=new Indexer($engine,$container->get('sales_channel.product.repository'),new DocumentBuilder(),new ParentTranslations($container->get('product.repository')),$config,$root.'/var/shopware-meilisearch');
$command=new IndexCommand($indexer,$container->get(CachedSalesChannelContextFactory::class),$db,$config);$handler=new ReindexTaskHandler($container->get('scheduled_task.repository'),new NullLogger(),$command,$state,$root.'/var/shopware-meilisearch');
$run=static function(int $expected)use($root):array{
 $process=new Symfony\Component\Process\Process([PHP_BINARY,'bin/console','shopware-meilisearch:search:audit-reference-units','--require-complete'],$root);$process->setTimeout(120);
 if($process->run()!==$expected)throw new RuntimeException('Unexpected reference-unit audit status.');$data=json_decode($process->getOutput(),true,512,JSON_THROW_ON_ERROR);
 if(!is_array($data)||count($data)!==5)throw new RuntimeException('Five language reports required.');return array_column($data,null,'locale');
};
$repository=$container->get('unit_translation.repository');$context=Context::createDefaultContext();$changed=false;$token='Pruefeinheit'.bin2hex(random_bytes(4));
try{
 $before=$run(0);foreach($before as $report)if(($report['missingReferenceUnits']??null)!==0||($report['calculatedReferencePrices']??0)<1)throw new RuntimeException('Expected complete baseline reference units.');
 $changed=true;$repository->update([['unitId'=>$unitId,'languageId'=>$domains['de-DE']['language'],'name'=>$token]],$context);$config->set($key,'{}');
 $after=$run(1);
 if(($after['de-DE']['missingReferenceUnits']??-1)!==0)throw new RuntimeException('The German unit translation should remain available.');
 foreach(['en-GB','es-ES','fr-FR','it-IT'] as $locale)if(($after[$locale]['missingReferenceUnits']??0)<1||($after[$locale]['missingByUnitId'][$unitId]??0)<1)throw new RuntimeException('The missing unit was not reported in '.$locale);
 echo "Reference-unit audit detected missing units in four languages and passed for the translated language.\n";
}finally{
 if($changed){
  $repository->update([['unitId'=>$unitId,'languageId'=>$domains['de-DE']['language'],'name'=>$original['name']]],$context);
  if($prior===false)$config->delete($key);else $config->set($key,json_decode($prior['configuration_value'],true,512,JSON_THROW_ON_ERROR)['_value']);
  if(searchPendingRevisionForTest($db)!==null)$state->requestRebuild();$handler->run();
  $restored=$run(0);foreach($restored as $report)if(($report['missingReferenceUnits']??null)!==0)throw new RuntimeException('Reference units were not restored.');
  echo "Original unit translation, mapping and indexes restored.\n";
 }
 $kernel->shutdown();
}
