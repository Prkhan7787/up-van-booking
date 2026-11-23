<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
use App\Database\{ConnectionFactory,GrantVerifier,Migration006GrantPlan,MigrationTestGrantPlan,MigrationPlan};

$n=0;
$check=static function(bool $ok,string $name)use(&$n):void{if(!$ok){fwrite(STDERR,'FAIL: '.$name.PHP_EOL);exit(1);}$n++;};
$reject=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable){return true;}};
$account="'up_van_test_migrator'@'127.0.0.1'";
$base=[
    "GRANT USAGE ON *.* TO $account",
    'GRANT CREATE ON `up\\_van\\_booking\\_test`.* TO '.$account,
    "GRANT SELECT ON `up_van_booking_test`.`auth_login_buckets` TO $account",
    "GRANT SELECT (`id`) ON `up_van_booking_test`.`booking_requests` TO $account",
    "GRANT SELECT, INSERT, UPDATE ON `up_van_booking_test`.`schema_migrations` TO $account",
    "GRANT INSERT, ALTER ON `up_van_booking_test`.`users` TO $account",
    "GRANT SELECT (`id`) ON `up_van_booking_test`.`vehicle_assignments` TO $account",
    "GRANT SELECT (`id`, `registration_number`, `registration_province`, `label`, `passenger_capacity`, `is_active`, `created_by`, `updated_by`, `created_at`, `updated_at`), INSERT, ALTER ON `up_van_booking_test`.`vehicles` TO $account",
];
$child="GRANT ALTER, INSERT ON `up_van_booking_test`.`booking_requests` TO $account";
$reference="GRANT REFERENCES ON `up_van_booking_test`.`vehicles` TO $account";
$six=[...$base,$child,$reference];
$baseline=MigrationTestGrantPlan::rules();
$check(ConnectionFactory::validateTestMigrationGrants($base)===null,'001–005 original scope still passes');
$check($reject(fn()=>ConnectionFactory::validateTestMigrationGrants($six)),'001–005 still rejects 006 privileges');
$check(ConnectionFactory::validateMigration006Grants($six)===null,'006 exact reviewed grant union passes');
$newPre006=array_map(static fn(string $g):string=>str_replace('`registration_province`, ','',$g),$six);
$pre006Result=GrantVerifier::evaluate($newPre006,'up_van_booking_test',Migration006GrantPlan::preRules(),false,true);
$check($pre006Result['status']==='PASS','fresh schema 005 account satisfies pre-006 profile');
$check(ConnectionFactory::validateMigration006Grants($newPre006,false,true,'pre')===null,'factory validates explicit schema 005 profile');
$check($pre006Result['unexpected_privileges']===[],'schema 005 grants have no unexpected privileges');
$check($reject(fn()=>ConnectionFactory::validateMigration006Grants($six,false,true,'pre')),'pre-006 rejects province grant absent from schema');
$check(ConnectionFactory::validateMigration006Grants($newPre006,false,true,'post')===null,'same nine-column grants pass postflight without grant mutation');
$check(ConnectionFactory::validateMigration006Grants($six,false,true,'post')===null,'post-006 explicitly supports reviewed legacy province read');
$check(!isset(Migration006GrantPlan::postRules()['tables']['booking_requests']['SELECT'][1]),'requested vehicle SELECT is unnecessary for migrator metadata postflight');
$tableSelect=[...$newPre006,"GRANT SELECT ON `up_van_booking_test`.`vehicles` TO $account"];
$check($reject(fn()=>ConnectionFactory::validateMigration006Grants($tableSelect)),'broad table SELECT is not a workaround for absent province column');
$check(MigrationTestGrantPlan::rules()===$baseline,'006 evaluation does not mutate baseline');
foreach(['pre','post'] as $phase) {
    foreach([$child,$reference,$base[4]] as $missing) {
        $check($reject(fn()=>ConnectionFactory::validateMigration006Grants(array_values(array_diff($newPre006,[$missing])),false,true,$phase)),'phase rejects missing DDL/FK/ledger privilege');
    }
    foreach(["GRANT SELECT ON `up_van_booking_test`.`vehicles` TO $account", "GRANT SELECT (`requested_vehicle_id`) ON `up_van_booking_test`.`booking_requests` TO $account", "GRANT DROP ON `up_van_booking_test`.`vehicles` TO $account", "GRANT SELECT ON *.* TO $account", "$child WITH GRANT OPTION"] as $extra) {
        $check($reject(fn()=>ConnectionFactory::validateMigration006Grants([...$newPre006,$extra],false,true,$phase)),'phase rejects unexpected column/table/global/drop/delegation');
    }
}
$check($reject(fn()=>Migration006GrantPlan::profiles('unknown')),'unknown phase rejected');
$ledger=array_map(static fn(array $m):array=>['version'=>$m['version'],'checksum'=>$m['sha256'],'status'=>'applied','completed_statements'=>count($m['statements']),'total_statements'=>count($m['statements'])],MigrationPlan::load(true));
$check(Migration006GrantPlan::phaseFromLedger(array_slice($ledger,0,5))==='pre','clean 001–005 ledger chooses pre');
$check(Migration006GrantPlan::phaseFromLedger($ledger)==='post','clean applied 006 ledger chooses post');
foreach(['status'=>'started','checksum'=>str_repeat('0',64),'completed_statements'=>1,'total_statements'=>3] as $key=>$bad) {
    $dirty=$ledger;$dirty[5][$key]=$bad;
    $check($reject(fn()=>Migration006GrantPlan::phaseFromLedger($dirty)),'partial/changed 006 cannot select a relaxed phase');
}
$dirty=$ledger;$dirty[0]['checksum']=str_repeat('0',64);
$check($reject(fn()=>Migration006GrantPlan::phaseFromLedger($dirty)),'baseline checksum drift rejected');
$check($reject(fn()=>Migration006GrantPlan::phaseFromLedger(array_slice($ledger,0,4))),'missing baseline migration rejected');
$check($reject(fn()=>ConnectionFactory::validateMigration006Grants($base)),'006 rejects missing DDL/FK grants');
foreach([$child,$reference] as $missing) {
    $check($reject(fn()=>ConnectionFactory::validateMigration006Grants(array_values(array_diff($six,[$missing])))),'each required delta is mandatory');
}
$noInsert=[...$base,"GRANT ALTER ON `up_van_booking_test`.`booking_requests` TO $account",$reference];
$check($reject(fn()=>ConnectionFactory::validateMigration006Grants($noInsert)),'child INSERT required alongside ALTER');
$noCreate=array_values(array_filter($six,static fn(string $s):bool=>!str_starts_with($s,'GRANT CREATE ON')));
$check($reject(fn()=>ConnectionFactory::validateMigration006Grants($noCreate)),'effective CREATE coverage required');
foreach([
    "GRANT ALTER ON *.* TO $account",
    "GRANT ALTER ON `up\\_van\\_booking\\_test`.* TO $account",
    "GRANT DROP ON `up_van_booking_test`.`vehicles` TO $account",
    "GRANT UPDATE ON `up_van_booking_test`.`booking_requests` TO $account",
    "GRANT SELECT ON `up_van_booking_test`.`booking_requests` TO $account",
    "GRANT REFERENCES (`registration_number`) ON `up_van_booking_test`.`vehicles` TO $account",
    "GRANT REFERENCES (`label`) ON `up_van_booking_test`.`vehicles` TO $account",
    "GRANT ALTER ON `up_van_booking`.`booking_requests` TO $account",
    "$child WITH GRANT OPTION",
] as $extra)$check($reject(fn()=>ConnectionFactory::validateMigration006Grants([...$six,$extra])),'unexpected privilege/scope rejected');
$check($reject(fn()=>ConnectionFactory::validateMigration006Grants($six,false,false)),'unresolved roles fail closed');
$legacyId="GRANT REFERENCES (`id`) ON `up_van_booking_test`.`vehicles` TO $account";
$check(ConnectionFactory::validateMigration006Grants([...$newPre006,$legacyId],false,true,'pre')===null,'table REFERENCES plus exact historical id column duplicate accepted');
$check($reject(fn()=>ConnectionFactory::validateMigration006Grants([...array_values(array_diff($newPre006,[$reference])),$legacyId],false,true,'pre')),'column REFERENCES alone still fails required table scope');
$mixed=array_values(array_diff($newPre006,[$reference]));
foreach($mixed as &$g)if(str_contains($g,'`vehicles`'))$g=str_replace('INSERT, ALTER ON','INSERT, ALTER, REFERENCES, REFERENCES (`id`) ON',$g);
unset($g);
$check(ConnectionFactory::validateMigration006Grants($mixed,false,true,'pre')===null,'MySQL mixed table/column REFERENCES in the same SHOW GRANTS row passes exact profile');
$literal=array_map(static fn(string $g):string=>str_replace('`up\\_van\\_booking\\_test`.*','`up_van_booking_test`.*',$g),$six);
$check(ConnectionFactory::validateMigration006Grants($literal,true,true)===null,'literal database grants supported with partial_revokes');
$check(ConnectionFactory::evaluateAuthRuntimeGrants($six,false,true)['status']!=='PASS','runtime profile rejects migrator DDL');

final class SixGrantStatement extends PDOStatement {
    public function __construct(private string $value){}
    public function fetchColumn(int $column=0):mixed{return $this->value;}
}
final class SixIdentityPdo extends PDO {
    public function __construct(private string $schema,private string $account){}
    public function query(string $sql,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        return new SixGrantStatement(str_contains($sql,'DATABASE()')?$this->schema:$this->account);
    }
}
$check($reject(fn()=>ConnectionFactory::assertMigration006Grants(new SixIdentityPdo('up_van_booking','up_van_test_migrator@127.0.0.1'))),'006 connection rejects production');
$check($reject(fn()=>ConnectionFactory::assertMigration006Grants(new SixIdentityPdo('up_van_booking_test','up_van_auth_test@127.0.0.1'))),'006 connection rejects runtime identity before role/grant lookup');
$check($reject(fn()=>ConnectionFactory::assertMigration006Grants(new SixIdentityPdo('up_van_booking_test','up_van_test_migrator@localhost'))),'006 connection rejects wrong matched host');

$saved=[];
try {
    foreach(['HOST'=>'127.0.0.1','PORT'=>'3306','DATABASE'=>'up_van_booking_test','USERNAME'=>'up_van_test_migrator','PASSWORD'=>'offline-placeholder'] as $suffix=>$value) {
        $key='MIGRATION_TEST_DB_'.$suffix;$saved[$key]=getenv($key);putenv($key.'='.$value);
    }
    $check(ConnectionFactory::config('migration_test_006')===ConnectionFactory::config('migration_test'),'separate profile reuses existing credentials without new secrets');
    putenv('MIGRATION_TEST_DB_USERNAME=up_van_auth_test');
    $check($reject(fn()=>ConnectionFactory::config('migration_test_006')),'006 config rejects runtime account');
} finally {
    foreach($saved as $key=>$value){if($value===false)putenv($key);else putenv($key.'='.$value);}
}
$plan=MigrationPlan::load(true);
$check(count($plan)===6 && count($plan[5]['statements'])===2 && $plan[5]['sha256']==='61c0f5b4fe152c83142d49ba81070a70df2dc352a9073302809cee9696827748','006 checksum and statement count unchanged');
$cli=file_get_contents(dirname(__DIR__,2).'/bin/db-migration-006.php');
$check(str_contains($cli,"connect('migration_test_006')") && str_contains($cli,'$error->diagnostics'),'006 CLI selects explicit profile and emits safe missing-grant diagnostics');
$sql=file_get_contents(dirname(__DIR__,2).'/scripts/migration-006-schema005-migrator-grants.proposed.sql');
$sql=preg_replace('/^--.*$/m','',$sql);
$proposal=array_values(array_filter(array_map(static fn(string $s):string=>trim(preg_replace('/\s+/',' ',$s)),explode(';',$sql))));
$check(count($proposal)===7,'schema 005 proposal contains only seven reviewed grants');
$check(ConnectionFactory::validateMigration006Grants($proposal,false,true,'pre')===null,'actual proposed SQL matches pre profile');
$check(ConnectionFactory::validateMigration006Grants($proposal,false,true,'post')===null,'same proposed SQL matches post profile without adding grants');
$literalProposal=array_map(static fn(string $s):string=>str_replace('`up\\_van\\_booking\\_test`.*','`up_van_booking_test`.*',$s),$proposal);
$check(ConnectionFactory::validateMigration006Grants($literalProposal,true,true,'pre')===null,'proposal supports partial revokes with literal scope variant');
$check($reject(fn()=>ConnectionFactory::validateMigration006Grants($proposal,false,false,'pre')),'pre phase unresolved roles fail closed');
$check($reject(fn()=>ConnectionFactory::validateMigration006Grants($literalProposal,false,true,'pre')),'unescaped schema wildcard still rejected');
$profileSource=file_get_contents(dirname(__DIR__,2).'/app/Database/Migration006GrantPlan.php');
$factorySource=file_get_contents(dirname(__DIR__,2).'/app/Database/ConnectionFactory.php');
$check(str_contains($profileSource,'$state=$runner->status()') && str_contains($profileSource,"if(\$phase==='pre') \$runner->vehicle006Preflight()"),'schema phase reuses runner status and full preflight metadata checks');
$check(str_contains($factorySource,'$phase=Migration006GrantPlan::schemaPhase($pdo)') && str_contains($factorySource,"\$resolved['roles_resolved'],\$phase"),'live guard receives evidence-backed phase on same connection');
$check(str_contains($profileSource,'throw MigrationSchemaException::fromFailure($error,$runner)') && str_contains($profileSource,'if($error instanceof \\PDOException) throw $error'),'metadata and partial-schema errors are reported and rethrown, never a fallback phase');
echo 'PASS: '.$n.' Migration 006 grant profile offline checks; no database connection.'.PHP_EOL;
