<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/bootstrap/cli.php';
use App\Database\ConnectionFactory;
use App\Database\MigrationPlan;
use App\Database\MigrationRunner;
use App\Database\MigrationTarget;
use App\Database\SafetyException;
use App\Database\SqlStatements;

$count=0;
function check(bool $ok,string $label):void {global $count; if(!$ok)throw new SafetyException('Offline assertion failed: '.$label);$count++;}
function rejected(callable $action):bool {try{$action();return false;}catch(SafetyException){return true;}}
function allowed(callable $action):bool {try{$action();return true;}catch(SafetyException){return false;}}
$plan=MigrationPlan::load();
check(count($plan)===5 && $plan[3]['version']==='004_auth_security' && $plan[4]['version']==='005_vehicle_registration_only','five pinned migrations including test-only vehicle migration');
check(count($plan[0]['statements'])===4 && count($plan[1]['statements'])===10 && count($plan[2]['statements'])===1,'DDL separated from seed');
check(count(SqlStatements::split("-- comment;\nSELECT 'a;b'; /* ; */ SELECT \"c;d\"; SELECT 'it''s;ok';"))===3,'quoted semicolons and comments');
check(rejected(fn()=>SqlStatements::split("SELECT 'broken")),'unterminated token');
check(rejected(fn()=>SqlStatements::split('/*! DROP TABLE x */')),'executable comment');
putenv('DB_PHASE_B_APPROVED=false');putenv('TEST_DB_EXECUTION_APPROVED=false');
check(rejected(fn()=>ConnectionFactory::requireMigrationApproval(MigrationTarget::PRODUCTION)),'both false rejects production migration');
check(rejected(fn()=>ConnectionFactory::requireMigrationApproval(MigrationTarget::TEST)),'both false rejects test migration');
putenv('TEST_DB_EXECUTION_APPROVED=true');
check(rejected(fn()=>ConnectionFactory::requireMigrationApproval(MigrationTarget::PRODUCTION)),'production false and test true rejects production only');
check(allowed(fn()=>ConnectionFactory::requireMigrationApproval(MigrationTarget::TEST)),'production false and test true allows test only');
putenv('DB_PHASE_B_APPROVED=true');putenv('TEST_DB_EXECUTION_APPROVED=false');
check(allowed(fn()=>ConnectionFactory::requireMigrationApproval(MigrationTarget::PRODUCTION)),'production true and test false allows production only');
check(rejected(fn()=>ConnectionFactory::requireMigrationApproval(MigrationTarget::TEST)),'production true and test false rejects test only');
putenv('TEST_DB_EXECUTION_APPROVED=true');
check(allowed(fn()=>ConnectionFactory::requireMigrationApproval(MigrationTarget::PRODUCTION)) && allowed(fn()=>ConnectionFactory::requireMigrationApproval(MigrationTarget::TEST)),'both true still use target-specific gates');
check(rejected(fn()=>ConnectionFactory::requireMigrationApproval('lost_found')),'unknown migration target denied');
check(MigrationTarget::fromApplyFlags(['--apply','--confirm=up_van_booking'])===MigrationTarget::PRODUCTION,'production needs exact confirmation');
check(MigrationTarget::fromApplyFlags(['--apply','--confirm=up_van_booking_test'])===MigrationTarget::TEST,'test needs exact confirmation');
check(rejected(fn()=>MigrationTarget::fromApplyFlags(['--apply','--confirm=lost_found'])),'unknown CLI target denied');
check(rejected(fn()=>MigrationTarget::fromApplyFlags(['--apply','--confirm=up_van_booking_test','--force'])),'extra CLI arguments denied');
$pdoStub=new class extends PDO { public function __construct() {} };
putenv('DB_PHASE_B_APPROVED=false');putenv('TEST_DB_EXECUTION_APPROVED=false');
check(rejected(fn()=>(new MigrationRunner($pdoStub,MigrationTarget::TEST))->apply()),'direct test runner call checks test gate before DB access');
check(rejected(fn()=>(new MigrationRunner($pdoStub,MigrationTarget::PRODUCTION))->apply()),'direct production runner call checks production gate before DB access');
check(rejected(fn()=>new MigrationRunner($pdoStub,'lost_found')),'direct runner rejects unknown target');
check((new ReflectionMethod(MigrationRunner::class,'__construct'))->getNumberOfRequiredParameters()===2,'runner requires explicit target');
check(allowed(fn()=>ConnectionFactory::validateMigrationIdentity(MigrationTarget::TEST,'up_van_booking_test','up_van_test_migrator@127.0.0.1')),'test migrator identity matches test target');
check(rejected(fn()=>ConnectionFactory::validateMigrationIdentity(MigrationTarget::TEST,'up_van_booking','up_van_test_migrator@127.0.0.1')),'test migrator cannot target production');
check(rejected(fn()=>ConnectionFactory::validateMigrationIdentity(MigrationTarget::TEST,'up_van_booking_test','up_van_migrator@127.0.0.1')),'production migrator cannot target test');
check(allowed(fn()=>ConnectionFactory::validateMigrationIdentity(MigrationTarget::PRODUCTION,'up_van_booking','up_van_migrator@127.0.0.1')),'production migrator identity matches production target');
putenv('DB_USERNAME=up_van_app');putenv('DB_PASSWORD='.bin2hex(random_bytes(24)));
check(ConnectionFactory::config()['database']==='up_van_booking','default target');
foreach(['lost_found','mysql','up_van_booking_test','other'] as $bad){putenv('DB_DATABASE='.$bad);check(rejected(fn()=>ConnectionFactory::config()),'reject alternate DB');}
putenv('DB_DATABASE=up_van_booking');putenv('DB_USERNAME=root');
check(rejected(fn()=>ConnectionFactory::config()),'root cannot be app connection');
putenv('DB_USERNAME=up_van_test');
check(rejected(fn()=>ConnectionFactory::config()),'test account cannot be used for production connection');
putenv('DB_USERNAME=up_van_app');putenv('DB_HOST=example.com');
check(rejected(fn()=>ConnectionFactory::config()),'nonlocal host blocked');
putenv('DB_HOST=127.0.0.1');putenv('DB_PORT=3306;dbname=other');
check(rejected(fn()=>ConnectionFactory::config()),'DSN port injection blocked');
putenv('DB_PORT=3306');
putenv('TEST_DB_USERNAME=up_van_test');putenv('TEST_DB_PASSWORD='.bin2hex(random_bytes(24)));
check(ConnectionFactory::config('test')['database']===MigrationTarget::TEST,'test purpose binds test database');
putenv('TEST_DB_USERNAME=up_van_app');
check(rejected(fn()=>ConnectionFactory::config('test')),'production app account cannot be used for test connection');
putenv('TEST_DB_USERNAME=up_van_test');putenv('TEST_DB_DATABASE=up_van_booking');
check(rejected(fn()=>ConnectionFactory::config('test')),'test account cannot target production database');
putenv('TEST_DB_DATABASE=up_van_booking_test');putenv('MIGRATION_TEST_DB_USERNAME=up_van_migrator');
putenv('MIGRATION_TEST_DB_PASSWORD='.bin2hex(random_bytes(24)));
check(rejected(fn()=>ConnectionFactory::config('migration_test')),'production migrator cannot be used for test connection');
putenv('MIGRATION_TEST_DB_USERNAME=up_van_test_migrator');
putenv('MIGRATION_TEST_DB_DATABASE=up_van_booking');
check(rejected(fn()=>ConnectionFactory::config('migration_test')),'test migrator cannot target production database');
foreach ([
    'runtime'=>['DB_','up_van_booking','up_van_app'],
    'preflight'=>['PREFLIGHT_DB_','up_van_booking','up_van_preflight'],
    'migration'=>['MIGRATION_DB_','up_van_booking','up_van_migrator'],
    'test'=>['TEST_DB_','up_van_booking_test','up_van_test'],
    'auth_test'=>['AUTH_TEST_DB_','up_van_booking_test','up_van_auth_test'],
    'test_provisioner'=>['TEST_PROVISIONER_DB_','up_van_booking_test','up_van_test_provisioner'],
    'migration_test'=>['MIGRATION_TEST_DB_','up_van_booking_test','up_van_test_migrator'],
] as $purpose=>[$prefix,$database,$username]) {
    putenv($prefix.'DATABASE='.$database);putenv($prefix.'USERNAME='.$username);putenv($prefix.'PASSWORD='.bin2hex(random_bytes(24)));
    check(ConnectionFactory::config($purpose)['database']===$database,'purpose selects its fixed database: '.$purpose);
    putenv($prefix.'USERNAME=wrong_account');
    check(rejected(fn()=>ConnectionFactory::config($purpose)),'purpose rejects wrong account: '.$purpose);
    putenv($prefix.'USERNAME='.$username);putenv($prefix.'DATABASE=wrong_database');
    check(rejected(fn()=>ConnectionFactory::config($purpose)),'purpose rejects wrong database: '.$purpose);
}
$sql=file_get_contents(dirname(__DIR__,2).'/database/migrations/002_booking_design.sql');
check(!str_contains($sql,'driver_personal_funds') && str_contains($sql,'driver_extra_pay'),'expense meaning corrected');
check(substr_count($sql,'COLLATE=utf8mb4_unicode_ci')===9,'all candidate tables unicode collation');
$ddl=implode("\n",SqlStatements::split($sql));
check(!str_contains($ddl,'ON DELETE') && !str_contains($ddl,'ON UPDATE RESTRICT'),'implicit restrictive FKs avoid CHECK action syntax');
check(str_contains($sql,'CREATE SQL SECURITY INVOKER VIEW'),'view respects invoker grants');
ConnectionFactory::validateGrants(['GRANT SELECT ON `up_van_booking`.`users` TO `up_van_app`@`localhost`'],'up_van_booking');check(true,'table scoped literal database grant');
ConnectionFactory::validateGrants(['GRANT SELECT ON `up\\_van\\_booking`.* TO `up_van_app`@`localhost`'],'up_van_booking');check(true,'escaped database scope');
ConnectionFactory::validateGrants(['GRANT SELECT ON `up_van_booking`.* TO `up_van_app`@`localhost`'],'up_van_booking',false,true);check(true,'partial revokes literal scope');
foreach(['GRANT SELECT ON *.* TO `up_van_app`@`localhost`','GRANT SELECT ON `lost_found`.* TO `up_van_app`@`localhost`','GRANT CREATE ON `up_van_booking`.`users` TO `up_van_app`@`localhost`','GRANT SELECT ON `up_van_booking`.* TO `up_van_app`@`localhost`','GRANT `some_role` TO `up_van_app`@`localhost`','GRANT SELECT ON `up_van_booking`.`users` TO `up_van_app`@`localhost` WITH GRANT OPTION'] as $badGrant){
    check(rejected(fn()=>ConnectionFactory::validateGrants([$badGrant],'up_van_booking')),'reject global, other DB, DDL, wildcard or role grants');
}
ConnectionFactory::validateGrants(['GRANT CREATE ON `up\\_van\\_booking`.* TO `up_van_migrator`@`127.0.0.1`','GRANT ALTER, INSERT ON `up_van_booking`.`users` TO `up_van_migrator`@`127.0.0.1`'],'up_van_booking',true);check(true,'migration-only ALTER with effective CREATE and table INSERT is accepted on users table');
ConnectionFactory::validateGrants(['GRANT ALTER, CREATE, INSERT ON `up_van_booking_test`.`users` TO `up_van_test_migrator`@`127.0.0.1`'],'up_van_booking_test',true);check(true,'ALTER prerequisite CREATE/INSERT bundle is allowed only on users for migration');
ConnectionFactory::validateGrants(['GRANT CREATE ON `up\\_van\\_booking\\_test`.* TO `up_van_test_migrator`@`127.0.0.1`','GRANT ALTER, INSERT ON `up_van_booking_test`.`users` TO `up_van_test_migrator`@`127.0.0.1`'],'up_van_booking_test',true);check(true,'database CREATE plus table ALTER/INSERT satisfies narrow migration grant plan');
check(rejected(fn()=>ConnectionFactory::validateGrants(['GRANT ALTER, INSERT ON `up_van_booking_test`.`users` TO `up_van_test_migrator`@`127.0.0.1`'],'up_van_booking_test',true)),'ALTER is rejected when effective CREATE prerequisite is absent');
foreach(['GRANT ALTER ON `up_van_booking`.* TO `up_van_migrator`@`127.0.0.1`','GRANT ALTER ON `up_van_booking`.`users` TO `up_van_app`@`127.0.0.1`','GRANT ALTER, SELECT ON `up_van_booking`.`users` TO `up_van_migrator`@`127.0.0.1`'] as $badAlter){
    check(rejected(fn()=>ConnectionFactory::validateGrants([$badAlter],'up_van_booking',str_contains($badAlter,'up_van_migrator'))),'reject broad, runtime, or combined ALTER grant');
}
check(rejected(fn()=>ConnectionFactory::validateGrants(['GRANT DROP ON `up_van_booking`.`users` TO `up_van_app`@`127.0.0.1`'],'up_van_booking')),'runtime account cannot have DROP on a table');
check(rejected(fn()=>ConnectionFactory::validateGrants(['GRANT DROP ON `up_van_booking_test`.* TO `up_van_test_migrator`@`127.0.0.1`'],'up_van_booking_test',true)),'test migrator cannot have DROP on its database');
$mysql84MigratorGrants = [
    "GRANT USAGE ON *.* TO `up_van_test_migrator`@`127.0.0.1`",
    "GRANT CREATE ON `up\\_van\\_booking\\_test`.* TO `up_van_test_migrator`@`127.0.0.1`",
    "GRANT SELECT, INSERT, UPDATE ON `up_van_booking_test`.`schema_migrations` TO `up_van_test_migrator`@`127.0.0.1`",
    "GRANT SELECT ON `up_van_booking_test`.`auth_login_buckets` TO `up_van_test_migrator`@`127.0.0.1`",
    "GRANT ALTER, INSERT ON `up_van_booking_test`.`users` TO `up_van_test_migrator`@`127.0.0.1`",
    "GRANT SELECT (`id`, `registration_number`, `registration_province`, `label`, `passenger_capacity`, `is_active`, `created_by`, `updated_by`, `created_at`, `updated_at`), INSERT, ALTER ON `up_van_booking_test`.`vehicles` TO `up_van_test_migrator`@`127.0.0.1`",
    "GRANT SELECT (`id`) ON `up_van_booking_test`.`booking_requests` TO `up_van_test_migrator`@`127.0.0.1`",
    "GRANT SELECT (`id`) ON `up_van_booking_test`.`vehicle_assignments` TO `up_van_test_migrator`@`127.0.0.1`",
];
ConnectionFactory::validateTestMigrationGrants($mysql84MigratorGrants); check(true,'MySQL 8.4 SHOW GRANTS with escaped schema CREATE and mixed column/table privileges is accepted for test migrator');
$missingAlter = array_values(array_filter($mysql84MigratorGrants, static fn(string $g): bool => !str_contains($g, 'INSERT, ALTER ON `up_van_booking_test`.`vehicles`')));
$missingAlter[] = "GRANT INSERT ON `up_van_booking_test`.`vehicles` TO `up_van_test_migrator`@`127.0.0.1`";
check(rejected(fn()=>ConnectionFactory::validateTestMigrationGrants($missingAlter)),'test migrator requires table-scoped ALTER for vehicles');
$missingInsert = array_values(array_filter($mysql84MigratorGrants, static fn(string $g): bool => !str_contains($g, 'INSERT, ALTER ON `up_van_booking_test`.`vehicles`')));
$missingInsert[] = "GRANT ALTER ON `up_van_booking_test`.`vehicles` TO `up_van_test_migrator`@`127.0.0.1`";
check(rejected(fn()=>ConnectionFactory::validateTestMigrationGrants($missingInsert)),'test migrator requires INSERT coverage for ALTER TABLE');
$missingCreate = array_values(array_filter($mysql84MigratorGrants, static fn(string $g): bool => !str_contains($g, 'GRANT CREATE ON')));
check(rejected(fn()=>ConnectionFactory::validateTestMigrationGrants($missingCreate)),'test migrator requires CREATE coverage for ALTER TABLE');
$wrongScope = $mysql84MigratorGrants; $wrongScope[1] = str_replace('up\\_van\\_booking\\_test', 'up\\_van\\_booking%', $wrongScope[1]);
check(rejected(fn()=>ConnectionFactory::validateTestMigrationGrants($wrongScope)),'test migrator rejects wildcard schema scope outside exact test database');
$extraColumn = $mysql84MigratorGrants; $extraColumn[] = "GRANT SELECT (`email`) ON `up_van_booking_test`.`users` TO `up_van_test_migrator`@`127.0.0.1`";
check(rejected(fn()=>ConnectionFactory::validateTestMigrationGrants($extraColumn)),'test migrator rejects unreviewed column-level SELECT');
$columnDiagnostic=null; try { ConnectionFactory::validateTestMigrationGrants($extraColumn); } catch (App\Database\GrantGuardException $e) { $columnDiagnostic=$e->diagnostics; }
check(($columnDiagnostic['mismatch_type']??null)==='rejected_privilege' && in_array('column.users.email:SELECT',$columnDiagnostic['rejected_privileges']??[],true),'unexpected column privilege is returned as safe diagnostic detail');
$globalPrivilege = $mysql84MigratorGrants; $globalPrivilege[] = "GRANT SELECT ON *.* TO `up_van_test_migrator`@`127.0.0.1`";
check(rejected(fn()=>ConnectionFactory::validateTestMigrationGrants($globalPrivilege)),'test migrator rejects global privileges');
$grantOption = $mysql84MigratorGrants; $grantOption[1] .= ' WITH GRANT OPTION';
check(rejected(fn()=>ConnectionFactory::validateTestMigrationGrants($grantOption)),'test migrator rejects GRANT OPTION');
check(rejected(fn()=>ConnectionFactory::validateTestMigrationGrants($mysql84MigratorGrants, false, false)),'unresolved roles fail closed for test migration account');
$withoutAuthMetadata = array_values(array_filter($mysql84MigratorGrants, static fn(string $g): bool => !str_contains($g, 'auth_login_buckets')));
$authMetadataDiagnostic=null; try { ConnectionFactory::validateTestMigrationGrants($withoutAuthMetadata); } catch (App\Database\GrantGuardException $e) { $authMetadataDiagnostic=$e->diagnostics; }
check(($authMetadataDiagnostic['mismatch_type']??null)==='missing_privilege' && in_array('table.auth_login_buckets:SELECT',$authMetadataDiagnostic['missing_privileges']??[],true),'missing auth metadata SELECT is reported with safe object/privilege labels');
$globalDiagnostic=null; try { ConnectionFactory::validateTestMigrationGrants($globalPrivilege); } catch (App\Database\GrantGuardException $e) { $globalDiagnostic=$e->diagnostics; }
check(($globalDiagnostic['mismatch_type']??null)==='rejected_scope' && in_array('global_privilege',$globalDiagnostic['rejected_scope']??[],true),'global grant rejection reports safe scope mismatch');
echo "PASS: $count offline database safety checks; no connection attempted.\n";
