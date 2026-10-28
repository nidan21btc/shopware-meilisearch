<?php declare(strict_types=1);
// Read actual index coverage and temporarily change only the isolated test configuration.
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Symfony\Component\Process\Process;

$url=parse_url(getenv('DATABASE_URL')?:'');$engine=parse_url(getenv('SHOPWARE_MEILISEARCH_URL')?:'');
if(!is_array($url)||!preg_match('/^\/el_search_test_[a-f0-9]+$/D',$url['path']??'')||!in_array($url['host']??'', ['localhost','127.0.0.1'],true)
 ||!is_array($engine)||($engine['scheme']??'')!=='http'||($engine['host']??'')!=='127.0.0.1')throw new RuntimeException('Isolated database and local search server required.');
foreach(['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name)if(getenv($name)!==false)$_SERVER[$name]=$_ENV[$name]=getenv($name);
$root=getenv('PROJECT_ROOT')?:'';if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('Test project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$db=$kernel->getContainer()->get(Connection::class);
if((int)$db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1')||(int)$db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1'))throw new RuntimeException('Apps and webhooks must be disabled.');
$key='ShopwareMeilisearch.config.indexFields';
if((int)$db->fetchOne('SELECT COUNT(*) FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NOT NULL',['key'=>$key]))throw new RuntimeException('No channel-specific field overrides allowed.');
$row=$db->fetchAssociative('SELECT configuration_value FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$key]);
if($row===false)throw new RuntimeException('An explicit global field configuration is required.');
$enabledKey='ShopwareMeilisearch.config.enabled';
if((int)$db->fetchOne('SELECT COUNT(*) FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NOT NULL',['key'=>$enabledKey]))throw new RuntimeException('No channel-specific enabled override allowed.');
$enabledRow=$db->fetchAssociative('SELECT configuration_value FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$enabledKey]);
if($enabledRow===false)throw new RuntimeException('An explicit global enabled configuration is required.');
$run=static function(bool $strict=false,int $expectedExit=0)use($root):array{
 $args=[PHP_BINARY,'bin/console','shopware-meilisearch:search:audit-index-fields'];if($strict)$args[]='--require-current';
 $process=new Process($args,$root);$process->setTimeout(120);
 if($process->run()!==$expectedExit)throw new RuntimeException('Unexpected field audit exit status.');
 $data=json_decode($process->getOutput(),true,512,JSON_THROW_ON_ERROR);if(!is_array($data)||count($data)!==5)throw new RuntimeException('Expected five language reports.');return $data;
};
$check=static function(array $report,bool $matches):void{
 foreach($report as $entry){
  if(($entry['fieldConfigurationMatchesPublishedIndex']??null)!==$matches||!is_int($entry['indexedProducts']??null)||$entry['indexedProducts']<1)throw new RuntimeException('Incorrect field configuration or product count.');
  foreach($entry['fieldCoverage']??[] as $count)if(!is_int($count)||$count<0||$count>$entry['indexedProducts'])throw new RuntimeException('Invalid coverage count.');
  if(!array_key_exists('customFields.custom_products_local',$entry['fieldCoverage']??[])||($entry['fieldCoverage']['customFields.custom_products_local']??0)<1)throw new RuntimeException('Expected configured custom field coverage.');
 }
};
$check($run(),true);
$originalState=$db->fetchAssociative('SELECT revision,indexed_revision FROM shopware_meilisearch_state WHERE id=1');
if($originalState===false||(int)$originalState['revision']<1)throw new RuntimeException('A valid index revision is required.');
try{
 $db->executeStatement('UPDATE shopware_meilisearch_state SET indexed_revision=revision WHERE id=1');
 $check($run(true),true);
 $db->executeStatement('UPDATE system_config SET configuration_value=:value WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$enabledKey,'value'=>json_encode(['_value'=>false],JSON_THROW_ON_ERROR)]);
 $disabled=$run(true,1);$check($disabled,true);
 foreach($disabled as $entry)if(($entry['automaticUpdatesEnabled']??null)!==false)throw new RuntimeException('Disabled automatic updates were not reported.');
 $db->executeStatement('UPDATE system_config SET configuration_value=:value WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$enabledKey,'value'=>$enabledRow['configuration_value']]);
 $db->executeStatement('UPDATE system_config SET configuration_value=:value WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$key,'value'=>json_encode(['_value'=>'["name"]'],JSON_THROW_ON_ERROR)]);
 $check($run(),false);
 $check($run(true,1),false);
 $db->executeStatement('UPDATE system_config SET configuration_value=:value WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$key,'value'=>$row['configuration_value']]);
 $db->executeStatement('UPDATE shopware_meilisearch_state SET indexed_revision=revision-1 WHERE id=1');
 $pending=$run(true,1);$check($pending,true);
 foreach($pending as $entry)if(($entry['indexRevisionPending']??null)!==true)throw new RuntimeException('Pending revision was not reported.');
 echo "Field coverage, disabled updates, strict configuration checks and pending revisions verified for five languages.\n";
}finally{
 $db->executeStatement('UPDATE system_config SET configuration_value=:value WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$key,'value'=>$row['configuration_value']]);
 $db->executeStatement('UPDATE system_config SET configuration_value=:value WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$enabledKey,'value'=>$enabledRow['configuration_value']]);
 if((int)$db->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1')!==(int)$originalState['revision'])throw new RuntimeException('A concurrent write changed the revision; do not restore the old state.');
 $db->executeStatement('UPDATE shopware_meilisearch_state SET indexed_revision=:indexed WHERE id=1',['indexed'=>$originalState['indexed_revision']]);
 $check($run(),true);
 $kernel->shutdown();
 echo "Original field configuration restored.\n";
}
