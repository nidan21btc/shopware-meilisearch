<?php declare(strict_types=1);
require (getenv('SHOPWARE_ROOT') ?: dirname(__DIR__, 4)) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/Security/AdmissionMetrics.php';
require dirname(__DIR__) . '/src/Security/SearchRequestGuard.php';
use ShopwareMeilisearch\Security\AdmissionMetrics;
use ShopwareMeilisearch\Security\SearchRequestGuard;
use Symfony\Component\HttpFoundation\Request;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$root = sys_get_temp_dir() . '/admission-metrics-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$now = 1000.0;
$handler = new TestHandler();
$logger = new Logger('test', [$handler]);
$metrics = new AdmissionMetrics($root.'/metrics', $logger, static function () use (&$now): float { return $now; });
$limits = ['concurrency'=>2,'globalRate'=>2,'clientRate'=>2];
$guard = new SearchRequestGuard($root.'/guard', 2, 2, 2, $metrics);
$request = static fn(string $ip): Request => Request::create('/de/shopware-meilisearch', 'GET', ['q'=>'PRIVATE_SEARCH_TERM'], [], [], ['REMOTE_ADDR'=>$ip]);
try {
 $a=$request('192.0.2.1'); $b=$request('192.0.2.2');
 check($guard->enter($a)===null && $guard->enter($b)===null, 'Two workers admitted');
 check($guard->enter($request('192.0.2.3'))?->getStatusCode()===429,'Concurrency denied');
 $guard->release($a); $guard->release($a); $guard->release($b);
 // A separate recorder instance shares the same aggregate.
 (new AdmissionMetrics($root.'/metrics', $logger, static function () use (&$now): float { return $now; }))->record('client_rate',$limits);
 check(count($handler->getRecords())===0,'No per-request logging');
 $now=1060;
 $metrics->record('limiter_error',$limits);
 $records=$handler->getRecords();check(count($records)===1,'One summary after interval');
 $context=$records[0]->context;
 check($context['counts']===['accepted'=>2,'concurrency'=>1,'completed'=>2,'client_rate'=>1],'Shared reason counts and idempotent completion');
 check($context['peakActive']===2 && $context['durationMeanMs']>=0,'Peak and timing recorded');
 check($records[0]->level->getName()==='WARNING','Rejections warn');
 check($context['limits']===$limits,'Limits included');
 for($i=0;$i<500;$i++)$metrics->record('global_rate',$limits);
 check(count($handler->getRecords())===1,'Burst must not flood logs');
 $now=1120; $metrics->record('completed',$limits,12.5);
 check(count($handler->getRecords())===2,'Second window emitted');
 check($handler->getRecords()[1]->context['counts']===['limiter_error'=>1,'global_rate'=>500],'Technical failures distinct');
 $now=1180;$metrics->record('accepted',$limits,0,1);
 check($handler->getRecords()[2]->level->getName()==='INFO','Healthy window is info');
 check($handler->getRecords()[2]->context['durationMeanMs']===12.5,'Duration aggregation');
 $held=fopen($root.'/metrics/metrics.json','c+');flock($held,LOCK_EX);
 $metrics->record('client_rate',$limits); // Must not wait or alter admission.
 flock($held,LOCK_UN);fclose($held);
 check(!str_contains(json_encode($handler->getRecords()),'PRIVATE_SEARCH_TERM') && !str_contains(json_encode($handler->getRecords()),'192.0.2.'),'No request data in logs');
 check((fileperms($root.'/metrics/metrics.json') & 0077)===0,'Private counters');
 check(filesize($root.'/metrics/metrics.json')<8192,'Bounded state');
 // Response status is captured before release, including framework-generated 5xx.
 $kernel=new class implements \Symfony\Component\HttpKernel\HttpKernelInterface {
  public function handle(Request $request,int $type=self::MAIN_REQUEST,bool $catch=true): \Symfony\Component\HttpFoundation\Response { return new \Symfony\Component\HttpFoundation\Response(); }
 };
 file_put_contents($root.'/guard/admission.json','{}');
 $failure=$request('192.0.2.10');check($guard->enter($failure)===null,'Admit failure fixture');
 $guard->onResponse(new \Symfony\Component\HttpKernel\Event\ResponseEvent($kernel,$failure,$kernel::MAIN_REQUEST,new \Symfony\Component\HttpFoundation\Response('',503)));
 $guard->release($failure);
 check(json_decode(file_get_contents($root.'/metrics/metrics.json'),true)['counts']['failed']===1,'HTTP 5xx classified separately');
 // Independent processes contribute to shared counters without resetting them.
 if (function_exists('pcntl_fork')) {
  for($i=0;$i<5;$i++) {
   $pid=pcntl_fork(); if($pid===0){$metrics->record('client_capacity',$limits);exit(0);}pcntl_waitpid($pid,$status);check(pcntl_wexitstatus($status)===0,'Worker completed');
  }
  $state=json_decode(file_get_contents($root.'/metrics/metrics.json'),true);
  check($state['counts']['client_capacity']===5,'Process-shared counters');
 }
 // Five independent PHP workers hold real admission slots concurrently.
 if (function_exists('pcntl_fork')) {
  $workers=[];
  for($i=0;$i<5;$i++) {
   $pid=pcntl_fork();
   if($pid===-1)break;
   if($pid===0) {
    $parallel=new SearchRequestGuard($root.'/parallel',5,10,10,$metrics);
    $q=$request('192.0.2.50');$until=hrtime(true)+30_000_000_000;
    $denied=$parallel->enter($q);
    if($denied!==null){fwrite(STDERR,"Parallel worker $i denied: HTTP ".$denied->getStatusCode()."\n");exit(2);}
    touch($root.'/ready-'.$i);
    do {clearstatcache(true, $root.'/release'); if(is_file($root.'/release'))break; usleep(1000);} while(hrtime(true)<$until);
    $parallel->release($q);exit(is_file($root.'/release') ? 0 : 3);
   }
   $workers[]=$pid;
  }
  // Workers must outlive the parent's full readiness deadline, including on slow CI hosts.
  $until=hrtime(true)+10_000_000_000;
  while(count($workers)===5 && count(glob($root.'/ready-*'))<5 && hrtime(true)<$until)usleep(1000);
  $ready=count(glob($root.'/ready-*'))===5;
  $extra=$ready ? (new SearchRequestGuard($root.'/parallel',5,10,10,$metrics))->enter($request('192.0.2.51')) : null;
  touch($root.'/release');
  $failed=[];
  foreach($workers as $pid){$waited=pcntl_waitpid($pid,$status);if($waited!==$pid || !pcntl_wifexited($status) || pcntl_wexitstatus($status)!==0)$failed[]=$pid.':'.($waited===$pid && pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 'abnormal');}
  check(count($workers)===5,'Could not fork all parallel workers');
  check($failed===[],'Parallel workers failed (PID:exit): '.implode(', ', $failed));
  check($ready && $extra?->getStatusCode()===429,'Five workers fit and sixth is rejected');
  check(json_decode(file_get_contents($root.'/metrics/metrics.json'),true)['peak']===5,'Sampled peak across five worker processes');
 }
 // Logging failures must not reject an otherwise admitted request.
 $brokenLogger=new class extends \Psr\Log\AbstractLogger { public function log($level, string|\Stringable $message, array $context=[]): void {throw new RuntimeException('synthetic logging failure');} };
 $broken=new AdmissionMetrics($root.'/broken',$brokenLogger,static function () use (&$now): float{return $now;});
 $broken->record('accepted',$limits);$now+=60;$broken->record('accepted',$limits);
 check(is_file($root.'/broken/metrics.json'),'Logging failure isolated');
 // Actual rotating handler keeps private files and a bounded retention configuration.
 $rotating=new \Monolog\Handler\RotatingFileHandler($root.'/admission.log',14,Logger::INFO,true,0600,true);
 (new Logger('rotation',[$rotating]))->info('Synthetic summary',['accepted'=>1]);$rotating->close();
 $logFiles=glob($root.'/admission-*.log');check(count($logFiles)===1 && (fileperms($logFiles[0]) & 0077)===0,'Private rotating log');
 echo "Admission metrics passed: reason counts, peak/timing, process sharing, throttled summaries, privacy, contention and logging failures.\n";
} finally {
 foreach (glob($root.'/*') as $path) {if(is_dir($path)){foreach(glob($path.'/*') as $file)unlink($file);rmdir($path);}else unlink($path);}rmdir($root);
}
