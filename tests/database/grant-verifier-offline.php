<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';

use App\Database\GrantVerifier;
use App\Database\Migration004GrantPlan;

$checks=0;
function grantCheck(bool $ok,string $label):void {global $checks;if(!$ok)throw new \App\Database\SafetyException('Offline assertion failed: '.$label);$checks++;echo 'PASS: '.$label.PHP_EOL;}
$target='up_van_booking_test';$rules=Migration004GrantPlan::rules();$grantee='`up_van_test_migrator`@`127.0.0.1`';
$expected=[
    'GRANT USAGE ON *.* TO '.$grantee,
    'GRANT CREATE ON `up\\_van\\_booking\\_test`.* TO '.$grantee,
    'GRANT SELECT ON `up_van_booking_test`.`schema_migrations` TO '.$grantee,
    'GRANT INSERT, UPDATE ON `up_van_booking_test`.`schema_migrations` TO '.$grantee,
    'GRANT ALTER, INSERT ON `up_van_booking_test`.`users` TO '.$grantee,
];
$pass=GrantVerifier::evaluate($expected,$target,$rules,false,true);
grantCheck($pass['status']==='PASS' && $pass['effective_privileges']['users']===['ALTER','CREATE','INSERT'],'database and table grants combine into correct effective permissions');
$sqlOff=Migration004GrantPlan::sql(false);$sqlOn=Migration004GrantPlan::sql(true);
grantCheck(count($sqlOff)===3 && str_contains($sqlOff[0],'`up\\_van\\_booking\\_test`.*') && str_contains($sqlOn[0],'`up_van_booking_test`.*'),'SQL grant draft adapts database wildcard escaping to partial_revokes');
grantCheck(!str_contains(implode(' ',$sqlOff),'up_van_booking`')&&!str_contains(implode(' ',$sqlOff),'lost_found')&&!str_contains(strtoupper(implode(' ',$sqlOff)),'DROP'),'SQL grant draft is test-only and omits DROP');
$literal=GrantVerifier::evaluate([
    'GRANT CREATE ON `up_van_booking_test`.* TO '.$grantee,
    'GRANT SELECT, INSERT, UPDATE ON `up_van_booking_test`.`schema_migrations` TO '.$grantee,
    'GRANT ALTER, INSERT ON `up_van_booking_test`.`users` TO '.$grantee,
],$target,$rules,true,true);
grantCheck($literal['status']==='PASS','partial_revokes enabled accepts literal database scope');
$missing=GrantVerifier::evaluate(array_slice($expected,0,4),$target,$rules,false,true);
grantCheck($missing['status']==='FAIL' && in_array('table.users:ALTER',$missing['missing_privileges'],true),'missing table-scoped ALTER is reported explicitly');
$broadAlter=GrantVerifier::evaluate([
    'GRANT CREATE ON `up\\_van\\_booking\\_test`.* TO '.$grantee,
    'GRANT SELECT, INSERT, UPDATE ON `up_van_booking_test`.`schema_migrations` TO '.$grantee,
    'GRANT ALTER, CREATE, INSERT ON `up\\_van\\_booking\\_test`.* TO '.$grantee,
],$target,$rules,false,true);
grantCheck($broadAlter['status']==='FAIL' && $broadAlter['unexpected_privileges']!==[],'database-wide ALTER is rejected as overbroad');
$wrongDb=GrantVerifier::evaluate(['GRANT CREATE ON `up\\_van\\_booking`.* TO '.$grantee],$target,$rules,false);
grantCheck($wrongDb['status']==='FAIL' && in_array('other_or_wildcard_database',$wrongDb['reason_codes'],true),'grant to production database is rejected');
$global=GrantVerifier::evaluate(['GRANT SELECT ON *.* TO '.$grantee],$target,$rules,false);
grantCheck($global['status']==='FAIL' && in_array('global_privilege',$global['reason_codes'],true),'global privileges are rejected');
$role=GrantVerifier::evaluate(array_merge($expected,['GRANT `test_migration_role`@`%` TO '.$grantee]),$target,$rules,false,false);
grantCheck($role['status']==='UNVERIFIED' && !$role['role_privileges_resolved'],'role-derived privileges are never assumed PASS');
$columnRules=['database'=>[],'tables'=>['users'=>['SELECT'=>['id','username'],'UPDATE'=>['password_hash']]]];
$columnPass=GrantVerifier::evaluate([
    'GRANT SELECT (`id`, `username`) ON `up_van_booking_test`.`users` TO '.$grantee,
    'GRANT UPDATE (`password_hash`) ON `up_van_booking_test`.`users` TO '.$grantee,
],$target,$columnRules,false,true);
grantCheck($columnPass['status']==='PASS' && $columnPass['missing_privileges']===[],'column-scoped SELECT and UPDATE satisfy exact required columns');
$columnExtra=GrantVerifier::evaluate([
    'GRANT SELECT (`id`, `username`, `email`) ON `up_van_booking_test`.`users` TO '.$grantee,
    'GRANT UPDATE (`password_hash`) ON `up_van_booking_test`.`users` TO '.$grantee,
],$target,$columnRules,false,true);
grantCheck($columnExtra['status']==='FAIL' && in_array('column.users.email:SELECT',$columnExtra['unexpected_privileges'],true),'unneeded column privilege is rejected');
$tableWide=GrantVerifier::evaluate(['GRANT SELECT ON `up_van_booking_test`.`users` TO '.$grantee],$target,$columnRules,false,true);
grantCheck($tableWide['status']==='FAIL','table-wide privilege is rejected when rules require column scope');
$expandedRole=GrantVerifier::evaluate(array_merge($expected,['GRANT `test_migration_role`@`%` TO '.$grantee]),$target,$rules,false,true);
grantCheck($expandedRole['status']==='PASS' && $expandedRole['role_privileges_resolved'],'expanded role grants can be evaluated without counting assignment lines as privileges');
$grantOption=GrantVerifier::evaluate(['GRANT CREATE ON `up\\_van\\_booking\\_test`.* TO '.$grantee.' WITH GRANT OPTION'],$target,$rules,false,true);
grantCheck($grantOption['status']==='FAIL','GRANT OPTION is rejected');
$wildcard=GrantVerifier::evaluate(['GRANT CREATE ON `up_van_booking_test`.* TO '.$grantee],$target,$rules,false,true);
grantCheck($wildcard['status']==='FAIL','unescaped underscore wildcard is rejected when partial_revokes is off');
grantCheck(!str_contains(json_encode($pass,JSON_THROW_ON_ERROR),$grantee),'verifier output withholds grant statements and account names');
$runtime=GrantVerifier::evaluate(['GRANT SELECT ON `up\\_van\\_booking`.* TO `up_van_app`@`127.0.0.1`'],'up_van_booking',['database'=>['SELECT'],'tables'=>[]],false,true);
grantCheck($runtime['status']==='PASS' && !in_array('ALTER',$runtime['effective_privileges']['database'],true) && !in_array('DROP',$runtime['effective_privileges']['database'],true),'runtime grant baseline has no ALTER or DROP');
$authRules=App\Database\AuthRuntimeGrantPlan::rules();
$authLines=[];foreach(App\Database\AuthRuntimeGrantPlan::grantSql(false) as $line)$authLines[]=$line.';';
$granteeAuth='`up_van_auth_test`@`127.0.0.1`';
$authGrants=[
    'GRANT SELECT (`id`, `username`, `is_active`, `password_hash`, `auth_version`) ON `up_van_booking_test`.`users` TO '.$granteeAuth,
    'GRANT UPDATE (`password_hash`) ON `up_van_booking_test`.`users` TO '.$granteeAuth,
    'GRANT SELECT (`id`, `name`) ON `up_van_booking_test`.`roles` TO '.$granteeAuth,
    'GRANT SELECT (`user_id`, `role_id`) ON `up_van_booking_test`.`user_roles` TO '.$granteeAuth,
    'GRANT SELECT (`bucket_scope`, `bucket_digest`, `window_started_at`, `attempt_count`), INSERT (`bucket_scope`, `bucket_digest`, `window_started_at`, `attempt_count`), UPDATE (`window_started_at`, `attempt_count`) ON `up_van_booking_test`.`auth_login_buckets` TO '.$granteeAuth,
    'GRANT INSERT (`user_id`, `action`, `entity_type`, `entity_id`, `metadata`) ON `up_van_booking_test`.`audit_logs` TO '.$granteeAuth,
];
$authEvaluation=GrantVerifier::evaluate($authGrants,$target,$authRules,false,true);
grantCheck($authEvaluation['status']==='PASS','Auth Runtime matrix exactly verifies with no database-wide privileges');
grantCheck(count(App\Database\AuthRuntimeGrantPlan::grantSql(false))===6 && str_contains(App\Database\AuthRuntimeGrantPlan::grantSql(false)[0],'`auth_version`'),'Auth Runtime plan includes the auth_version column selected on every request');
$authWide=GrantVerifier::evaluate(array_merge($authGrants,['GRANT DELETE ON `up\\_van\\_booking\\_test`.* TO '.$granteeAuth]),$target,$authRules,false,true);
grantCheck($authWide['status']==='FAIL','Auth Runtime database-wide DML is rejected as unexpected');
$provisionRules=App\Database\TestProvisionerGrantPlan::rules();
$provisionLines=[
    'GRANT SELECT (`id`, `email`, `username`), INSERT (`email`, `username`, `display_name`, `password_hash`, `is_active`) ON `up_van_booking_test`.`users` TO `up_van_test_provisioner`@`127.0.0.1`',
    'GRANT SELECT (`id`, `name`) ON `up_van_booking_test`.`roles` TO `up_van_test_provisioner`@`127.0.0.1`',
    'GRANT SELECT (`user_id`, `role_id`), INSERT (`user_id`, `role_id`) ON `up_van_booking_test`.`user_roles` TO `up_van_test_provisioner`@`127.0.0.1`',
    'GRANT INSERT (`user_id`, `action`, `entity_type`, `entity_id`, `metadata`) ON `up_van_booking_test`.`audit_logs` TO `up_van_test_provisioner`@`127.0.0.1`',
];
$provisionEvaluation=GrantVerifier::evaluate($provisionLines,$target,$provisionRules,false,true);
grantCheck($provisionEvaluation['status']==='PASS','Provisioner matrix verifies without UPDATE, DELETE or rate-limit access');
grantCheck(count($authLines)===6 && count(App\Database\TestProvisionerGrantPlan::grantSql(false))===4,'grant plans emit the reviewed table/column statement counts');
$resolverSource=file_get_contents(dirname(__DIR__,2).'/app/Database/GrantVerifier.php');
grantCheck(str_contains($resolverSource,'SHOW GRANTS FOR CURRENT_USER USING') && str_contains($resolverSource,'SELECT CURRENT_ROLE()'),'role resolver reads active roles and requests expanded SHOW GRANTS');
echo "PASS: $checks Grant Verifier offline checks; no database contacted.".PHP_EOL;
