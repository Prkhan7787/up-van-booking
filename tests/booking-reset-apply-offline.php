<?php
declare(strict_types=1);
define('BOOKING_RESET_RUNNER_TEST',true);
require dirname(__DIR__).'/scripts/booking-reset-runner.php';

$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{
    if(!$ok)throw new RuntimeException($message);
    $checks++;
};
$reject=static function(callable $action,string $reason)use($check):void{
    try{$action();throw new LogicException('Expected guard did not reject');}
    catch(RuntimeException $error){$check($error->getMessage()===$reason,$reason);}
};

// These argument failures must occur before any listener probe or database connection.
$reject(static fn()=>resetMain(['runner','apply','--actor-id=11']),'EXPLICIT_APPLY_SCOPE_REQUIRED');
$reject(static fn()=>resetMain(['runner','apply','--actor-id=11','--approved-scope=wrong']),'EXPLICIT_APPLY_SCOPE_REQUIRED');
$reject(static fn()=>resetMain(['runner','rehearsal','--actor-id=11','--approved-scope='.RESET_APPLY_SCOPE]),'RESET_ARGUMENTS_REJECTED');
$reject(static fn()=>resetMain(['runner','unknown','--actor-id=11']),'RESET_MODE_REJECTED');
$reject(static fn()=>resetMain(['runner','apply','--actor-id=12','--approved-scope='.RESET_APPLY_SCOPE]),'ACTOR_ID_11_REQUIRED');
$scopeEntries=[];
foreach(resetSnapshot() as $id=>[$status,$version])$scopeEntries[]="$id=$status.v$version";
$check(RESET_APPLY_SCOPE===RESET_DB.':'.RESET_UUID.':'.implode(',',$scopeEntries),'Apply approval text exactly matches the six reviewed snapshot rows');

$expected=[];
foreach(resetSnapshot() as $id=>[$status,$version]){
    $expected[$id]=[
        'id'=>(string)$id,'workflow_status'=>$status,'version'=>(string)($version+1),
        'deleted_at'=>'2026-11-23 01:00:00.000000','deleted_by'=>'11',
        'deletion_reason'=>RESET_REASON,'updated_by'=>'11',
    ];
}
resetCheckAppliedRows($expected,11);$check(true,'All six tombstones match the reviewed snapshot and actor');
foreach([
    ['workflow_status','submitted'],['version','1'],['deleted_at',null],
    ['deleted_by','12'],['deletion_reason','other'],['updated_by','12'],
] as [$field,$wrong]){
    $bad=$expected;$bad[32][$field]=$wrong;
    $reject(static fn()=>resetCheckAppliedRows($bad,11),'POST_RESET_TARGET_MISMATCH_ID_32');
}
$bad=$expected;unset($bad[34]);
$reject(static fn()=>resetCheckAppliedRows($bad,11),'POST_RESET_TARGET_COUNT_MISMATCH');
$bad=$expected;$bad[35]=$expected[34];
$reject(static fn()=>resetCheckAppliedRows($bad,11),'POST_RESET_TARGET_COUNT_MISMATCH');

$before=['decisions'=>'4','notifications'=>'2','history'=>'28','audit'=>'191'];
$after=['decisions'=>'4','notifications'=>'2','history'=>'28','audit'=>'197'];
resetCheckPreservedCounts($before,$after,6);$check(true,'Audit increases by six; other preserved records do not change');
foreach(['decisions','notifications','history'] as $key){
    $bad=$after;$bad[$key]=(string)((int)$bad[$key]+1);
    $reject(static fn()=>resetCheckPreservedCounts($before,$bad,6),'PRESERVED_COUNT_CHANGED_'.$key);
}
$bad=$after;$bad['audit']='196';
$reject(static fn()=>resetCheckPreservedCounts($before,$bad,6),'AUDIT_COUNT_MISMATCH');

$runner=file_get_contents(dirname(__DIR__).'/scripts/booking-reset-runner.php');
$wrapper=file_get_contents(dirname(__DIR__).'/scripts/booking-reset-maintenance-run.ps1');
$check(is_string($runner)&&is_string($wrapper),'Apply sources are readable');
$check(substr_count($runner,'$db->commit()')===1,'Only one commit call exists');
$scopeGuard=strpos($runner,"if(\$mode==='apply'){");
$preflightEnd=strpos($runner,"if(\$mode==='preflight')return 0;");
$preCommitChecks=strpos($runner,'resetCheckAppliedRows(resetRollbackRows($db),$actor);');
$auditCheck=strpos($runner,'resetCheckPreservedCounts($beforePreserved,resetPreservedCounts($db),6);');
$commit=strpos($runner,'$db->commit()');
$rollback=strpos($runner,'$db->rollBack()');
$committedMarker=strpos($runner,'APPLY_COMMITTED=YES');
$postflight=strpos($runner,'POSTFLIGHT=PASS');
$check($scopeGuard!==false && $preflightEnd>$scopeGuard && $preCommitChecks>$preflightEnd && $auditCheck>$preCommitChecks && $commit>$auditCheck,'Scope, snapshot, tombstones and audit precede Apply commit');
$check($rollback>$commit && $committedMarker>$rollback && $postflight>$committedMarker,'Rehearsal rollback and Apply postflight keep their separate completion paths');
$check(str_contains($runner,"if(\$mode==='apply'){\n                resetMaintenanceWindow();") && str_contains($runner,'APPLY_COMMIT_OUTCOME=UNVERIFIED'),'Apply rechecks maintenance and reports uncertain commit');
$check(str_contains($wrapper,'EXPLICIT_APPLY_SCOPE_REQUIRED') && str_contains($wrapper,'RESET_APPLY_POSTFLIGHT_MISSING'),'Wrapper requires a scope and confirmed postflight');
$check(str_contains($wrapper,RESET_APPLY_SCOPE),'Wrapper and runner bind the same database, UUID, IDs and versions');
$check(str_contains($runner,"if(resetViewDefinition(\$db)!==\$viewDefinition || (int)\$db->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn()!==0)"),'Postflight verifies the actual calendar View and unchanged definition');
$check(!preg_match('/\b(?:TRUNCATE|DELETE\s+FROM)\b/i',$runner),'No hard delete or truncate was added');

echo "PASS: booking reset Apply offline ($checks checks); no MySQL connection made.\n";
