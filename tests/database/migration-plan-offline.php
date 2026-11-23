<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap/cli.php';

use App\Database\MigrationPlan;
use App\Database\SafetyException;
use App\Database\Migration005VehicleValidator;
use App\Database\MigrationTarget;

$checks = 0;
function migrationCheck(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
}
function migrationRejected(callable $fn): bool
{
    try { $fn(); } catch (SafetyException) { return true; }
    return false;
}

$plan = MigrationPlan::load();
migrationCheck(count($plan) === 5 && $plan[3]['version'] === '004_auth_security' && $plan[4]['version'] === '005_vehicle_registration_only', 'manifest sequence pins 001–005 in order');
migrationCheck(count($plan[3]['statements']) === 2, '004 statement count matches pinned expectation');
$m005=$plan[4];
migrationCheck($m005['sha256']===Migration005VehicleValidator::SHA256 && hash('sha256',file_get_contents(dirname(__DIR__,2).'/database/drafts/005_vehicle_registration_only.sql'))===$m005['sha256'],'005 checksum matches pinned draft bytes');
migrationCheck(migrationRejected(fn()=>Migration005VehicleValidator::assertTarget(MigrationTarget::PRODUCTION)),'005 rejects production target');
Migration005VehicleValidator::assertTarget(MigrationTarget::TEST);migrationCheck(true,'005 accepts only the exact test target');
Migration005VehicleValidator::assertPreconditions(['id','registration_number','registration_province','label','passenger_capacity','is_active','created_by','updated_by','created_at','updated_at'],[['uq_vehicle_registration','registration_number',1,0],['uq_vehicle_registration','registration_province',2,0]],[]);migrationCheck(true,'expected old schema and no duplicates pass preflight');
migrationCheck(migrationRejected(fn()=>Migration005VehicleValidator::assertPreconditions(['id','registration_number','registration_province','label','passenger_capacity','is_active','created_by','updated_by','created_at','updated_at'],[['uq_vehicle_registration','registration_number',1,0],['uq_vehicle_registration','registration_province',2,0]],[['registration_number'=>'DUP']])), 'duplicate registration blocks migration');
migrationCheck(migrationRejected(fn()=>Migration005VehicleValidator::assertPreconditions(['registration_province','extra'],[['uq_vehicle_registration','registration_number',1,0],['uq_vehicle_registration','registration_province',2,0]],[])), 'schema drift blocks migration');
Migration005VehicleValidator::assertPostconditions(['id','registration_number','label'],[['uq_vehicle_registration_number','registration_number',1,0]]);migrationCheck(true,'new schema postcondition passes');
migrationCheck(migrationRejected(fn()=>Migration005VehicleValidator::assertPostconditions(['registration_province'],[['uq_vehicle_registration_number','registration_number',1,0]])), 'remaining province column fails postflight');
$ledger=['version'=>Migration005VehicleValidator::VERSION,'checksum'=>Migration005VehicleValidator::SHA256,'status'=>'applied','completed_statements'=>1,'total_statements'=>1];
Migration005VehicleValidator::assertLedgerApplied($ledger);migrationCheck(true,'complete 005 ledger is accepted');
$ledger['status']='started';migrationCheck(migrationRejected(fn()=>Migration005VehicleValidator::assertLedgerApplied($ledger)),'partial DDL ledger blocks re-run');
$manifest = require dirname(__DIR__, 2) . '/database/manifest.php';
foreach (['kind','objects','file','sha256'] as $field) {
    $tampered = $manifest[0];
    $tampered[$field] = $field === 'objects' ? ['unexpected'] : 'tampered';
    migrationCheck(migrationRejected(fn()=>MigrationPlan::assertManifestEntry($tampered)), 'manifest metadata tampering is rejected: ' . $field);
}
MigrationPlan::assertStatementAllowed('004_auth_security', 'forward', $plan[3]['statements'][0]);
MigrationPlan::assertStatementAllowed('004_auth_security', 'forward', $plan[3]['statements'][1]);
migrationCheck(true, 'exact reviewed ALTER and table creation pass forward allowlist');
migrationCheck(migrationRejected(fn()=>MigrationPlan::assertStatementAllowed('004_auth_security','forward','ALTER TABLE users DROP COLUMN password_hash')), 'DROP inside ALTER is denied');
migrationCheck(migrationRejected(fn()=>MigrationPlan::assertStatementAllowed('004_auth_security','forward','ALTER TABLE users ADD COLUMN is_admin BOOLEAN DEFAULT TRUE')), 'unreviewed ALTER is denied');
migrationCheck(migrationRejected(fn()=>MigrationPlan::assertStatementAllowed('004_auth_security','forward','CREATE TABLE other_schema.auth_login_buckets (id INT)')), 'qualified cross-schema object is denied');
migrationCheck(migrationRejected(fn()=>MigrationPlan::assertStatementAllowed('004_auth_security','forward','CREATE TABLE auth_login_buckets (id INT)')), 'unreviewed forward CREATE is denied');
migrationCheck(migrationRejected(fn()=>MigrationPlan::assertStatementAllowed('001_identity','ddl','ALTER TABLE users ADD COLUMN x INT')), 'ALTER is denied outside the pinned forward migration');
migrationCheck(migrationRejected(fn()=>MigrationPlan::assertStatementAllowed('004_auth_security','forward','TRUNCATE TABLE users')), 'TRUNCATE is denied');
migrationCheck(migrationRejected(fn()=>MigrationPlan::assertStatementAllowed('004_auth_security','forward','CREATE DATABASE lost_found')), 'database creation is denied');
echo "PASS: $checks migration plan offline safety checks; no database was contacted." . PHP_EOL;
