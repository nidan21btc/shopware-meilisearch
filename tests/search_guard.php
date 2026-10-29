<?php declare(strict_types=1);
require (getenv('SHOPWARE_ROOT') ?: dirname(__DIR__, 4)) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/Security/SearchRequestGuard.php';
require dirname(__DIR__) . '/src/Security/Secret.php';
use ShopwareMeilisearch\Security\SearchRequestGuard;
use ShopwareMeilisearch\Security\Secret;
use Symfony\Component\HttpFoundation\Request;

function verify(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$root = sys_get_temp_dir() . '/search-guard-' . bin2hex(random_bytes(8)); mkdir($root, 0700);
$guard = new SearchRequestGuard($root, 2, 2, 2);
$r = static fn(string $ip): Request => Request::create('/de/shopware-meilisearch', 'GET', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_X_FORWARDED_FOR' => '192.0.2.99']);
try {
 $a=$r('192.0.2.1'); $b=$r('192.0.2.2'); $c=$r('192.0.2.3');
 verify($guard->enter($a)===null && (new SearchRequestGuard($root, 2, 2, 2))->enter($b)===null, 'Two independent workers must share capacity');
 verify($guard->enter($c)?->getStatusCode()===429, 'A third simultaneous search must not run');
 $guard->release($a); verify($guard->enter($c)===null, 'Completed search must free its slot');
 $guard->release($b); $guard->release($c);
 $text=file_get_contents($root.'/admission.json'); verify(!str_contains($text,'192.0.2.'), 'Raw client IP must not be persisted');
 verify((fileperms($root.'/admission.json') & 0077)===0, 'Limiter state must be private');
 // Token exhaustion across changing IPs must still be limited globally.
 $denied=false; for($i=4;$i<30;$i++){ $q=$r('192.0.2.'.$i); if($guard->enter($q)?->getStatusCode()===429){$denied=true;break;} $guard->release($q); }
 verify($denied,'Global bucket must stop distributed bursts');
 file_put_contents($root.'/admission.json','{}');
 for($i=0;$i<6;$i++){ $q=$r('192.0.2.42'); verify($guard->enter($q)===null,'Client burst allowance missing');$guard->release($q); }
 $spoofed=$r('192.0.2.42');$spoofed->headers->set('X-Forwarded-For','198.51.100.1');
 verify($guard->enter($spoofed)?->getStatusCode()===429,'Untrusted forwarded IP must not bypass client limits');
 $other=$r('192.0.2.77');verify($guard->enter($other)===null,'One client must not consume another client bucket');$guard->release($other);
 file_put_contents($root.'/admission.json','{}');
 $shared = new SearchRequestGuard($root, 10, 10, 10); $active = [];
 for($i=0;$i<10;$i++){ $q=$r('192.0.2.50');verify($shared->enter($q)===null,'Ten searches behind one shared IP must fit configured capacity');$active[]=$q; }
 verify($shared->enter($r('192.0.2.51'))?->getStatusCode()===429,'Configured capacity must reject an eleventh active request');
 foreach($active as $q)$shared->release($q);
 $q=$r('192.0.2.51');verify($shared->enter($q)===null,'Configured capacity must recover after completion');$shared->release($q);
 if (function_exists('pcntl_fork')) {
  file_put_contents($root.'/admission.json','{}');
  $pid=pcntl_fork();
  if ($pid===0) {
   $held=fopen($root.'/admission.json','c+'); flock($held,LOCK_EX); touch($root.'/lock-ready');
   usleep(30000); flock($held,LOCK_UN); fclose($held); exit(0);
  }
  $until=microtime(true)+2; while(!is_file($root.'/lock-ready') && microtime(true)<$until) usleep(1000);
  verify(is_file($root.'/lock-ready'),'Child must acquire admission lock');
  $q=$r('192.0.2.92'); verify($shared->enter($q)===null,'Brief lock contention must not reject a visitor');
  $shared->release($q); pcntl_waitpid($pid,$status); verify(pcntl_wexitstatus($status)===0,'Lock holder exited'); unlink($root.'/lock-ready');
  $held=fopen($root.'/admission.json','c+'); flock($held,LOCK_EX); $start=hrtime(true);
  $result=$shared->enter($r('192.0.2.93')); $elapsed=(hrtime(true)-$start)/1e6;
  flock($held,LOCK_UN); fclose($held);
  verify($result?->getStatusCode()===429 && $elapsed>=80 && $elapsed<500,'Stalled lock must be rejected after a bounded wait');
 }
 file_put_contents($root.'/admission.json','broken');verify($guard->enter($r('192.0.2.88'))?->getStatusCode()===503,'Invalid shared state must fail closed');
 $secret=$root.'/secret';file_put_contents($secret,str_repeat('a',64)."\n");chmod($secret,0600);verify((string)new Secret('', $secret)===str_repeat('a',64),'Protected secret file must load without trailing whitespace');
 chmod($secret,0644);try{(string)new Secret('', $secret);throw new RuntimeException('Unsafe secret accepted');}catch(RuntimeException $e){verify(str_contains($e->getMessage(),'not protected'),'Wrong failure');}
 echo "Admission checks passed: shared concurrency, global burst cap, private state, fail-closed behavior and protected credentials.\n";
} finally { foreach(glob($root.'/*') as $f)unlink($f); rmdir($root); }
