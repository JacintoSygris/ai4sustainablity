<?php
// No inherited credential/environment reads; every connection is process-local config.
$web = dirname(__DIR__, 2);
require $web.'/vendor/autoload.php';
$args = getopt('', ['engine:', 'root:', 'prefix:', 'suite:', 'filter::', 'mutant::', 'job::','profile:']);
if (!isset($args['profile']) || !is_file($args['profile'])) { throw new RuntimeException('explicit native fixture profile required'); }
$GLOBALS['t11ProfilePath'] = $args['profile'];
$GLOBALS['t11Engine'] = $args['engine'];
$GLOBALS['t11Root'] = $args['root'];
$GLOBALS['t11Prefix'] = $args['prefix'];
$GLOBALS['t11Profile'] = json_decode(file_get_contents($args['profile']), true, 512, JSON_THROW_ON_ERROR);
if (isset($args['mutant'])) { $GLOBALS['t11Mutant']=$args['mutant']; require $args['mutant']; }
require $web.'/tests/Support/learning-synthetic-fixture.php';
if (isset($args['job'])) {
    $job=json_decode(file_get_contents($args['job']),true,128,JSON_THROW_ON_ERROR);
    $fixture=new class('nativeRole') extends \Tests\TestCase { use \T11NativeApplication; };
    $fixture->createApplication();
    t11NativeRole($job);
    exit(0);
}
$argv = ['t11', '--no-configuration', '--do-not-cache-result', '--colors=never', '--log-junit', $args['root'].'/junit.xml'];
if (! empty($args['filter'])) { $argv[]='--filter'; $argv[]=$args['filter']; }
$argv[]=$web.'/tests/Native/Services/'.$args['suite'];
exit((new PHPUnit\TextUI\Application)->run($argv));
