<?php declare(strict_types=1);
require (getenv('SHOPWARE_ROOT') ?: dirname(__DIR__,4)).'/vendor/autoload.php';
spl_autoload_register(static function(string $class):void{if(str_starts_with($class,'ShopwareMeilisearch\\'))require dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,strlen('ShopwareMeilisearch\\'))).'.php';});
use ShopwareMeilisearch\Search\SearchInput;
use Symfony\Component\HttpFoundation\Request;
$count=0;
$check=static function(bool $ok,string $name)use(&$count):void{if(!$ok)throw new RuntimeException($name);++$count;};
foreach([null,false,true,0,-1,151,2.5,'2.5','invalid','',[]] as $value)$check(SearchInput::minimumQueryLength($value)===2,'invalid config falls back to 2');
foreach([1,2,3,150,'1','3','150'] as $value)$check(SearchInput::minimumQueryLength($value)===(int)$value,'valid config is retained');
foreach(['',' ', 'a','ä',"a\u{0308}",'😀','Öl','ab','abc'] as $query){$input=SearchInput::fromRequest(new Request(['q'=>$query]));foreach([1,2,3,150] as $minimum)$check($input->isTooShort($minimum)===($input->query!==''&&mb_strlen(Normalizer::normalize(trim($query)))<$minimum),'Unicode boundary');}
$cards=(new Shopware\Core\System\SystemConfig\Util\ConfigReader())->read(dirname(__DIR__).'/src/Resources/config/config.xml');$field=array_values(array_filter($cards[0]['elements'],static fn(array $e):bool=>($e['name']??'')==='minimumQueryLength'))[0]??null;
$check($field!==null,'backend field exists');$check($field['type']==='int','integer field');$check((int)$field['min']===1&&(int)$field['max']===150,'backend bounds');$check($field['defaultValue']===2,'backend default');
echo "$count minimum query checks passed.\n";
