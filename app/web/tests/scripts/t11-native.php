<?php
$web=dirname(__DIR__,2);
$args=getopt('', ['engines::','require-positive-discovery','filter::','mutant::','suite::','allow-native-fixture','profile:']);
if (!isset($args['allow-native-fixture'], $args['profile']) || !is_file($args['profile'])) { fwrite(STDERR, "Explicit disposable native fixture permission and --profile are required.\n"); exit(2); }
$root=dirname($web).'/artifacts/learning-native/tests/'.bin2hex(random_bytes(16));
mkdir($root,0700,true);
$results=[];
$suites=['LearningCaseConcurrencyTest.php','LearningNormativeBoundaryTest.php'];
if(isset($args['suite'])) {
 if(!in_array($args['suite'],$suites,true))throw new RuntimeException('invalid T11 suite');
 $suites=[$args['suite']];
}
if(isset($args['mutant'])) {
 if(!in_array($args['mutant'],['lock','fence'],true))throw new RuntimeException('invalid T11 mutant');
 $mroot=dirname($root,2).'/mutants/'.bin2hex(random_bytes(16));mkdir($mroot,0700,true);
 $source=$args['mutant']==='lock'?$web.'/app/Services/CharacterizationStateTransaction.php':$web.'/app/Models/LearningBatch.php';
 $bytes=file_get_contents($source);
 if($args['mutant']==='lock')$mutated=str_replace('->lockForUpdate()','',$bytes,$count);
 else $mutated=str_replace("\$row->batch_id!==\$token['batch_id'] || \$row->fence!==\$token['fence'] || ",'',$bytes,$count);
 if($count!==($args['mutant']==='lock'?3:1))throw new RuntimeException('T11 mutant source shape changed');
 $mutantPath=$mroot.'/'.basename($source);file_put_contents($mutantPath,$mutated);
 file_put_contents($root.'/mutant.json',json_encode(['variant'=>$args['mutant'],'canonical_source'=>$source,'canonical_sha256'=>hash('sha256',$bytes),'copy'=>$mutantPath,'copy_sha256'=>hash('sha256',$mutated),'replacements'=>$count],JSON_PRETTY_PRINT));
}
foreach(explode(',',$args['engines']??'mysql,pgsql') as $engine) {
 if(!in_array($engine,['mysql','pgsql'],true))throw new RuntimeException('invalid T11 engine');
 $engineDeadline=microtime(true)+600;
 foreach($suites as $suite) {
  $case=$root.'/'.$engine.'-'.pathinfo($suite,PATHINFO_FILENAME);mkdir($case,0700,true);
  // Profile is HOST metadata. Runtime artifacts stay beneath the approved root.
  $argv=[PHP_BINARY,'-d','extension_dir='.ini_get('extension_dir'),'-d','extension=pdo_mysql','-d','extension=pdo_pgsql',$web.'/tests/scripts/t11-native-child.php','--profile='.$args['profile'],'--engine='.$engine,'--root='.$case,'--prefix=t11_'.bin2hex(random_bytes(5)).'_','--suite='.$suite];
  if(isset($args['filter']))$argv[]='--filter='.$args['filter'];
  if(isset($mutantPath))$argv[]='--mutant='.$mutantPath;
  $pipes=[];$p=proc_open($argv,[0=>['pipe','r'],1=>['file',$case.'/stdout.txt','w'],2=>['file',$case.'/stderr.txt','w']],$pipes,$web,null,['bypass_shell'=>true]);
  if(!is_resource($p))throw new RuntimeException('native child not started');fclose($pipes[0]);
  $deadline=$engineDeadline;$exit=null;
  do {$s=proc_get_status($p);if(!$s['running']){$exit=$s['exitcode'];break;}usleep(20000);}while(microtime(true)<$deadline);
  $timedOut=$exit===null;
  if($timedOut){proc_terminate($p);$exit=124;}proc_close($p);
  $counts=['discovered'=>0,'passed'=>0,'failed'=>0,'skipped'=>0,'assertion_failures'=>0,'errors'=>0];
  $junitPath=$case.'/junit.xml';$junitRaw=is_file($junitPath)?file_get_contents($junitPath):'';$junitDiagnostic=null;$incomplete=$timedOut;
  if($junitRaw===''){$incomplete=true;$junitDiagnostic='JUnit XML missing or empty';}
  else {
   $previous=libxml_use_internal_errors(true);$xml=simplexml_load_string($junitRaw);$xmlErrors=libxml_get_errors();libxml_clear_errors();libxml_use_internal_errors($previous);
   if($xml===false||!in_array($xml->getName(),['testsuite','testsuites'],true)){$incomplete=true;$junitDiagnostic=['message'=>'Invalid or incomplete JUnit XML','libxml_errors'=>array_map(fn($e)=>trim($e->message),$xmlErrors)];}
   else {foreach($xml->xpath('//testcase') as $test){$counts['discovered']++;if(isset($test->failure)||isset($test->error)){$counts['failed']++;if(isset($test->failure))$counts['assertion_failures']++;if(isset($test->error))$counts['errors']++;}elseif(isset($test->skipped))$counts['skipped']++;else $counts['passed']++;}}
  }
  $results[]=['engine'=>$engine,'suite'=>$suite,'argv'=>$argv,'cwd'=>$web,'exit'=>$exit,'root'=>$case,'counts'=>$counts,'incomplete'=>$incomplete,'timed_out'=>$timedOut,'junit_diagnostic'=>$junitDiagnostic,'junit_raw'=>$junitRaw,'stdout'=>file_get_contents($case.'/stdout.txt'),'stderr'=>file_get_contents($case.'/stderr.txt')];
 }
}
file_put_contents($root.'/result.json',json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['root'=>$root,'results'=>array_map(fn($r)=>array_intersect_key($r,array_flip(['engine','suite','exit','counts'])),$results)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
exit(array_filter($results,fn($r)=>$r['exit']!==0||$r['incomplete']||$r['counts']['skipped']!==0||$r['counts']['discovered']===0)?1:0);
