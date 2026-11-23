<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
use App\Database\{MigrationExecutionException as E,MigrationPlan,Migration006GrantPlan,ConnectionFactory};
$n=0;$check=static function(bool $ok,string $name)use(&$n):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$n++;};
foreach([['42000',1142],['HY000',1215],['HY000',1822],['HY000',3780],['HY000',2013]] as [$state,$native]) {
    $pdo=new PDOException('SECRET_PASSWORD_RAW_DSN_SQL_MUST_NOT_ESCAPE');
    $pdo->errorInfo=[$state,$native,'SECRET_PASSWORD_RAW_DSN_SQL_MUST_NOT_ESCAPE'];
    $e=E::fromFailure($pdo,2,'DDL');$d=$e->diagnostics();
    $check($d['statement_number']===2 && $d['execution_stage']==='DDL','failed DDL index and stage preserved');
    $check($d['sqlstate']===$state && $d['native_code']===$native,'safe original PDO diagnostics preserved');
    $check(!str_contains(json_encode($e),'SECRET_PASSWORD') && !str_contains($e->getMessage(),'SECRET_PASSWORD'),'JSON/message exclude raw exception and SQL');
    $check($e->getPrevious()===null && $d['automatic_retry']===false && $d['partial_apply_possible']===true,'no raw previous exception or retry');
}
foreach(['LEDGER_PROGRESS','POSTCONDITIONS','LEDGER_APPLIED'] as $stage) {
    $d=E::fromFailure(new RuntimeException('SECRET_RAW'),2,$stage)->diagnostics();
    $check($d['execution_stage']===$stage && !isset($d['sqlstate']) && !str_contains(json_encode($d),'SECRET_RAW'),'non-DDL failure distinguished safely');
}
$bad=new PDOException('SECRET_RAW');$bad->errorInfo=['invalid secret',null,'SECRET_RAW'];
$d=E::fromFailure($bad,999,'untrusted secret')->diagnostics();
$check($d['sqlstate']==='UNKNOWN' && $d['statement_number']===null && $d['execution_stage']==='UNKNOWN','untrusted diagnostic labels sanitized');
$runner=file_get_contents(dirname(__DIR__,2).'/app/Database/MigrationRunner.php');
$check(str_contains($runner,'MigrationExecutionException::fromFailure($originalError,$statementNumber,$executionStage)'),'Runner forwards original error before it is lost');
$check(str_contains($runner,"failure_code='statement_failed'") && str_contains($runner,"status='failed'"),'ledger failure semantics unchanged');
$cli=file_get_contents(dirname(__DIR__,2).'/bin/db-migration-006.php');
$check(strpos($cli,'catch(App\\Database\\MigrationExecutionException')<strpos($cli,'catch(Throwable'),'CLI forwards safe structured exception before generic catch');
$check($d['migration']===MigrationPlan::VEHICLE006['version'],'diagnostic migration label fixed');
// Document the current exact profile; do not silently widen privileges in a diagnostic patch.
$check(Migration006GrantPlan::preRules()['tables']['vehicles']['REFERENCES']===null,'006 now requires parent table REFERENCES for FK creation');
$readOnly=App\Database\SqlStatements::split(file_get_contents(dirname(__DIR__,2).'/scripts/migration-006-partial-diagnostic-readonly.sql'));
$check(count($readOnly)>0 && array_all($readOnly,static fn(string $sql):bool=>str_starts_with($sql,'SELECT ') || str_starts_with($sql,'SHOW GRANTS ')),'operator diagnostic file has SELECT/SHOW only, never recovery SQL');
echo "PASS: $n Migration 006 execution diagnostic checks; no PDO connection or migration\n";
