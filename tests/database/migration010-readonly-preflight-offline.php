<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';

use App\Database\ConnectionFactory;
use App\Database\DriverDatabaseIdentity;
use App\Database\GrantVerifier;
use App\Database\Migration010ReadOnlyGrantPlan as ReadOnlyPlan;
use App\Database\MigrationPlan;
use App\Database\SafetyException;

$checks=0;
$check=static function(bool $condition,string $label)use(&$checks):void{
    if(!$condition)throw new RuntimeException('FAIL: '.$label);
    $checks++;echo 'PASS: '.$label,PHP_EOL;
};
$rejected=static function(callable $action):bool{try{$action();return false;}catch(Throwable){return true;}};

$saved=[];
$profileKeys=['MIGRATION_PREFLIGHT_DB_HOST','MIGRATION_PREFLIGHT_DB_PORT','MIGRATION_PREFLIGHT_DB_DATABASE','MIGRATION_PREFLIGHT_DB_USERNAME','MIGRATION_PREFLIGHT_DB_PASSWORD'];
$allKeys=[...$profileKeys,'MIGRATION_TEST_DB_HOST','MIGRATION_TEST_DB_PORT','MIGRATION_TEST_DB_DATABASE','MIGRATION_TEST_DB_USERNAME','MIGRATION_TEST_DB_PASSWORD','DB_HOST','DB_PORT'];
try {
    foreach($allKeys as $key){$saved[$key]=getenv($key);putenv($key);}
    putenv('DB_HOST=127.0.0.1');putenv('DB_PORT=3306');
    putenv('MIGRATION_TEST_DB_HOST=127.0.0.1');putenv('MIGRATION_TEST_DB_PORT=3306');
    putenv('MIGRATION_TEST_DB_DATABASE=up_van_booking_test');putenv('MIGRATION_TEST_DB_USERNAME=up_van_test_migrator');putenv('MIGRATION_TEST_DB_PASSWORD=migrator-fixture');
    $check($rejected(static fn()=>ConnectionFactory::config('migration_preflight_test')),'missing read-only configuration fails closed and does not reuse migrator variables');

    $good=['127.0.0.1','3306','up_van_booking_test',ReadOnlyPlan::ACCOUNT,'offline-only-fixture'];
    foreach($profileKeys as $i=>$key)putenv($key.'='.$good[$i]);
    $config=ConnectionFactory::config('migration_preflight_test');
    $check($config['host']==='127.0.0.1' && $config['port']==='3306' && $config['database']==='up_van_booking_test'
        && $config['username']===ReadOnlyPlan::ACCOUNT && $config['password']==='offline-only-fixture','separate process-scoped profile resolves its pinned test account');
    foreach(['MIGRATION_PREFLIGHT_DB_HOST'=>'localhost','MIGRATION_PREFLIGHT_DB_PORT'=>'3307','MIGRATION_PREFLIGHT_DB_DATABASE'=>'up_van_booking','MIGRATION_PREFLIGHT_DB_USERNAME'=>'up_van_test_migrator'] as $key=>$value){
        $original=getenv($key);putenv($key.'='.$value);
        $check($rejected(static fn()=>ConnectionFactory::config('migration_preflight_test')),'read-only profile rejects unsafe override for '.$key);
        putenv($key.'='.$original);
    }
} finally {
    foreach($allKeys as $key){$value=$saved[$key]??false;if($value===false)putenv($key);else putenv($key.'='.$value);}
}

$identity=['host'=>'127.0.0.1','port'=>'3306','database'=>'up_van_booking_test','username'=>ReadOnlyPlan::ACCOUNT];
DriverDatabaseIdentity::assertConfig($identity,ReadOnlyPlan::ACCOUNT);
$check(true,'read-only account passes pinned host, port and database config identity');
$check($rejected(static fn()=>DriverDatabaseIdentity::assertConfig(array_replace($identity,['database'=>'up_van_booking']),ReadOnlyPlan::ACCOUNT)),'wrong database identity is rejected');

final class Migration010IdentityStatement extends PDOStatement
{
    public function __construct(private array $row){}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->row;}
}
final class Migration010IdentityPdo extends PDO
{
    public function __construct(public array $identity){}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{return new Migration010IdentityStatement($this->identity);}
}
$expectedIdentity=['port'=>3306,'uuid'=>DriverDatabaseIdentity::UUID,'database_name'=>'up_van_booking_test','account_name'=>ReadOnlyPlan::ACCOUNT.'@127.0.0.1'];
DriverDatabaseIdentity::inspect(new Migration010IdentityPdo($expectedIdentity),ReadOnlyPlan::ACCOUNT);
$check(true,'expected read-only server UUID and account identity are accepted');
$wrongUuid=$expectedIdentity;$wrongUuid['uuid']='wrong-uuid';
$check($rejected(static fn()=>DriverDatabaseIdentity::inspect(new Migration010IdentityPdo($wrongUuid),ReadOnlyPlan::ACCOUNT)),'wrong server UUID is rejected');

$grants=[
    "GRANT USAGE ON *.* TO 'up_van_migration_preflight'@'127.0.0.1'",
    "GRANT SELECT (`version`, `checksum`, `status`, `completed_statements`, `total_statements`) ON `up_van_booking_test`.`schema_migrations` TO 'up_van_migration_preflight'@'127.0.0.1'",
    "GRANT SELECT (`id`) ON `up_van_booking_test`.`users` TO 'up_van_migration_preflight'@'127.0.0.1'",
    "GRANT SELECT (`id`) ON `up_van_booking_test`.`drivers` TO 'up_van_migration_preflight'@'127.0.0.1'",
    "GRANT SELECT (`name`, `description`) ON `up_van_booking_test`.`roles` TO 'up_van_migration_preflight'@'127.0.0.1'",
    "GRANT SELECT (`bucket_scope`) ON `up_van_booking_test`.`auth_login_buckets` TO 'up_van_migration_preflight'@'127.0.0.1'",
    "GRANT SELECT (`id`) ON `up_van_booking_test`.`vehicles` TO 'up_van_migration_preflight'@'127.0.0.1'",
    "GRANT SELECT (`id`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_migration_preflight'@'127.0.0.1'",
];
$check(ReadOnlyPlan::evaluate($grants,false,true,'NONE')['status']==='PASS','exact column-scoped read-only profile passes');
$authBucketSchemaColumns=['bucket_scope','bucket_digest','window_started_at','attempt_count','created_at','updated_at'];
$authBucketGrantColumns=ReadOnlyPlan::rules()['tables']['auth_login_buckets']['SELECT'];
$check(!in_array('id',$authBucketSchemaColumns,true)
    && $authBucketGrantColumns===['bucket_scope']
    && array_diff($authBucketGrantColumns,$authBucketSchemaColumns)===[],
    'auth_login_buckets has no id and read-only plan requires only existing bucket_scope');
$check(ReadOnlyPlan::evaluate(array_slice($grants,0,-1),false,true,'NONE')['status']==='FAIL','missing schema visibility grant fails closed');
foreach([
    "GRANT INSERT ON `up_van_booking_test`.`schema_migrations` TO 'up_van_migration_preflight'@'127.0.0.1'",
    "GRANT ALTER ON `up_van_booking_test`.`users` TO 'up_van_migration_preflight'@'127.0.0.1'",
    "GRANT SELECT ON *.* TO 'up_van_migration_preflight'@'127.0.0.1'",
    "GRANT SELECT (`id`) ON `up_van_booking_test`.`users` TO 'up_van_migration_preflight'@'127.0.0.1' WITH GRANT OPTION",
] as $excess){
    $check(ReadOnlyPlan::evaluate([...$grants,$excess],false,true,'NONE')['status']==='FAIL','excessive privilege rejected');
}
$roleGrant="GRANT 'migration_writer'@'127.0.0.1' TO 'up_van_migration_preflight'@'127.0.0.1'";
$check(ReadOnlyPlan::evaluate([...$grants,$roleGrant],false,true,'NONE')['status']==='FAIL','dormant role assignment rejected');
$check(ReadOnlyPlan::evaluate($grants,false,true,"'migration_writer'@'127.0.0.1'")['status']==='FAIL','active or mandatory role rejected');
$check(ReadOnlyPlan::evaluate($grants,false,true,'NONE',"'mandatory_writer'@'%'")['status']==='FAIL','server mandatory roles are rejected');
$check(ReadOnlyPlan::evaluate($grants,false,false,'PRESENT')['status']==='FAIL','unresolved effective roles rejected');
$partial=ReadOnlyPlan::evaluate($grants,false,true,'NONE');
$check(GrantVerifier::evaluateProfiles($grants,'up_van_booking_test',[ReadOnlyPlan::rules()],false,true)['status']==='PASS'
    && $partial['role_privileges_resolved']===true,'grant verifier enforces exact test-only column rules');

$runner=file_get_contents(dirname(__DIR__,2).'/app/Database/MigrationRunner.php');
$method=substr($runner,(int)strpos($runner,'public function driver010Inspect'),(int)strpos($runner,'public function vehicle005Preflight')-(int)strpos($runner,'public function driver010Inspect'));
$check(str_contains($method,'Migration010ReadOnlyGrantPlan::ACCOUNT')
    && !preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|GRANT|REVOKE|COMMIT)\b/i',$method),
    'Migration 010 inspection is SELECT-only and pins read-only account');
$check(str_contains($method,'Migration010DriverValidator::metadataVisibility')
    && str_contains($method,'MIGRATION_010_METADATA_VISIBILITY'),
    'incomplete column metadata is classified before schema assertions and remains fail-closed');
$cli=file_get_contents(dirname(__DIR__,2).'/scripts/driver-auth-migration-preflight.php');
$check(str_contains($cli,"[['--preflight'], ['--postflight']]")
    && str_contains($cli,"ConnectionFactory::connect('migration_preflight_test')")
    && !str_contains($cli,'->apply('),'CLI accepts only read-only phases and exposes no apply path');
$draft=file_get_contents(dirname(__DIR__,2).'/database/proposals/010_migration_preflight_readonly_grants.proposed.sql');
$grantSql=preg_replace('/(?m)^\s*--.*$/','',$draft);
$normalizedDraft=strtoupper(preg_replace('/\s+/',' ',$grantSql));
$draftMatches=array_reduce(array_slice($grants,1),static function(bool $carry,string $grant)use($normalizedDraft):bool{
    return $carry && str_contains($normalizedDraft,strtoupper(preg_replace('/\s+/',' ',$grant)));
},true);
$check(!preg_match('/(?im)^\s*(?:CREATE|ALTER|INSERT|UPDATE|DELETE|DROP|REVOKE)\b/',$grantSql)
    && !str_contains(strtoupper($grantSql),'GRANT OPTION')
    && count(array_filter(preg_split('/;\s*(?:\R|$)/',$grantSql),static fn($s)=>preg_match('/^\s*GRANT SELECT\b/i',$s)))===7
    && str_contains($normalizedDraft,'GRANT SELECT (`BUCKET_SCOPE`) ON `UP_VAN_BOOKING_TEST`.`AUTH_LOGIN_BUCKETS`')
    && !str_contains($normalizedDraft,'GRANT SELECT (`ID`) ON `UP_VAN_BOOKING_TEST`.`AUTH_LOGIN_BUCKETS`')
    && $draftMatches,
    'grant draft exactly matches seven scoped SELECT grants and has no account or write provisioning');

echo 'PASS: '.$checks.' Migration 010 read-only preflight offline checks; no database was contacted.',PHP_EOL;
