<?php
declare(strict_types=1);
namespace App\Database;

use PDO;
use Throwable;

/** Ledger-only adoption of an already-created, independently verified 011 table. */
final class Migration011LedgerReconciliation
{
    public const PURPOSE='migration_011_ledger_reconciliation';
    private const LOCK='up_van_booking_test:migrations';
    private const ACCOUNT='up_van_migration011_apply';

    public static function requireApproval():void
    {
        if(getenv('TEST_DB_EXECUTION_APPROVED')!=='true'||getenv('TEST_MIGRATION_011_LEDGER_RECONCILIATION_APPROVED')!=='true')
            throw new SafetyException('MIGRATION_011_RECONCILIATION_APPROVAL_REQUIRED');
    }

    public static function reconcile(PDO $db,string $backupDirectory,string $manifestSha256,array $marker):array
    {
        self::requireApproval();
        if($db->inTransaction())throw new SafetyException('MIGRATION_011_RECONCILIATION_TRANSACTION_STATE_REJECTED');
        Migration011ReconciliationBackup::verify($backupDirectory,$manifestSha256);
        self::assertIdentity($db);
        if((string)($db->query("SELECT GET_LOCK('".self::LOCK."',0) AS acquired")->fetch(PDO::FETCH_ASSOC)['acquired']??'')!=='1')
            throw new SafetyException('MIGRATION_011_RECONCILIATION_LOCK_UNAVAILABLE');
        $released=false;$transaction=false;
        try{
            if(!$db->beginTransaction())throw new SafetyException('MIGRATION_011_RECONCILIATION_TRANSACTION_FAILED');
            $transaction=true;
            self::assertIdentity($db);
            Migration011ReconciliationBackup::verify($backupDirectory,$manifestSha256);
            self::assertAccountSafe($db);
            self::assertMarker($marker);
            self::assertLedgerAndSchema($db);
            $row=$db->prepare('SELECT version,checksum,status,completed_statements,total_statements,failure_code FROM schema_migrations WHERE version=:version FOR UPDATE');
            $row->execute(['version'=>MigrationPlan::MICROSOFT011['version']]);
            $locked=$row->fetch(PDO::FETCH_ASSOC);
            if(!is_array($locked)||!self::eligibleLedgerRow($locked))throw new SafetyException('MIGRATION_011_RECONCILIATION_LEDGER_ROW_REJECTED');
            $update=$db->prepare("UPDATE schema_migrations SET status='applied',finished_at=UTC_TIMESTAMP(6),failure_code=NULL WHERE version=:version AND checksum=:checksum AND status='failed' AND completed_statements=1 AND total_statements=1 AND failure_code IN ('statement_failed','postcondition_failed')");
            if(!$update->execute(['version'=>MigrationPlan::MICROSOFT011['version'],'checksum'=>MigrationPlan::MICROSOFT011['sha256']])||$update->rowCount()!==1)
                throw new SafetyException('MIGRATION_011_RECONCILIATION_UPDATE_UNCONFIRMED');
            self::assertIdentity($db);
            self::assertAccountSafe($db);
            self::assertLedgerAndSchema($db,true);
            if(!$db->commit())throw new SafetyException('MIGRATION_011_RECONCILIATION_COMMIT_UNCONFIRMED');
            $transaction=false;
        }catch(Throwable $error){
            if($transaction&&$db->inTransaction())try{$db->rollBack();}catch(Throwable){}
            throw $error;
        }finally{
            try{$released=(string)($db->query("SELECT RELEASE_LOCK('".self::LOCK."') AS released")->fetch(PDO::FETCH_ASSOC)['released']??'')==='1';}catch(Throwable){}
        }
        if(!$released)throw new SafetyException('MIGRATION_011_RECONCILIATION_LOCK_RELEASE_UNVERIFIED');
        return ['status'=>'PASS','mode'=>'LEDGER_ONLY','migration'=>MigrationPlan::MICROSOFT011['version'],
            'checksum'=>MigrationPlan::MICROSOFT011['sha256'],'ledger'=>'applied 1/1','schema_postconditions'=>'PASS',
            'ddl_executed'=>false,'account_locked'=>'Y','automatic_retry'=>false,'migration_lock_released'=>true];
    }

    public static function assertMarker(array $marker):void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',(string)($marker['run_id']??''))
            ||($marker['phase']??null)!=='RUNNER_EXITED'||($marker['child_finished']??null)!==true
            ||($marker['automatic_retry']??null)!==false||($marker['account']??null)!==self::ACCOUNT.'@127.0.0.1'
            ||($marker['target_uuid']??null)!==DriverDatabaseIdentity::UUID
            ||!is_numeric($marker['child_pid']??null)||(int)$marker['child_pid']<1
            ||!is_numeric($marker['child_started_ticks']??null)||(int)$marker['child_started_ticks']<1)
            throw new SafetyException('MIGRATION_011_RECONCILIATION_MARKER_REJECTED');
    }

    private static function assertIdentity(PDO $db):void
    {
        $row=$db->query('SELECT @@port AS port,@@server_uuid AS uuid,DATABASE() AS database_name,VERSION() AS version,CURRENT_USER() AS account_name')->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)||(string)($row['port']??'')!=='3306'||($row['uuid']??null)!==DriverDatabaseIdentity::UUID
            ||($row['database_name']??null)!==MigrationTarget::TEST||($row['version']??null)!=='8.4.9'
            ||!in_array($row['account_name']??null,['root@localhost','root@127.0.0.1'],true))
            throw new SafetyException('MIGRATION_011_RECONCILIATION_IDENTITY_REJECTED');
    }

    private static function assertAccountSafe(PDO $db):void
    {
        $account=$db->prepare("SELECT account_locked FROM mysql.user WHERE User=:user AND Host='127.0.0.1'");
        $account->execute(['user'=>self::ACCOUNT]);$rows=$account->fetchAll(PDO::FETCH_ASSOC);
        $sessions=$db->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER=:user");
        $sessions->execute(['user'=>self::ACCOUNT]);
        if(count($rows)!==1||($rows[0]['account_locked']??null)!=='Y'||(int)$sessions->fetchColumn()!==0)
            throw new SafetyException('MIGRATION_011_RECONCILIATION_ACCOUNT_OR_SESSION_REJECTED');
    }

    private static function assertLedgerAndSchema(PDO $db,bool $after=false):void
    {
        $rows=$db->query('SELECT version,checksum,status,completed_statements,total_statements,failure_code FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
        $own=array_values(array_filter($rows,static fn(array $row):bool=>($row['version']??null)===MigrationPlan::MICROSOFT011['version']));
        $driver=array_values(array_filter($rows,static fn(array $row):bool=>str_starts_with((string)($row['version']??''),'010_')));
        $baseline=array_values(array_filter($rows,static fn(array $row):bool=>!str_starts_with((string)($row['version']??''),'010_')&&!str_starts_with((string)($row['version']??''),'011_')));
        usort($baseline,static fn($a,$b)=>strcmp($a['version'],$b['version']));
        Migration011Validator::assertLedger($baseline);
        if($driver!==[]||count($own)!==1||(!$after&&!self::eligibleLedgerRow($own[0])))
            throw new SafetyException('MIGRATION_011_RECONCILIATION_LEDGER_STATE_REJECTED');
        if($after){usort($rows,static fn($a,$b)=>strcmp($a['version'],$b['version']));Migration011Validator::assertLedger($rows,'after');}
        $expected=['approval_decisions','audit_logs','auth_login_buckets','booking_notifications','booking_passengers','booking_requests',
            'booking_reviews','booking_status_history','calendar_events','drivers','microsoft_identities','roles','schema_migrations',
            'user_roles','users','vehicle_assignments','vehicles'];
        $inventory=$db->query('SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
        $actual=[];foreach($inventory as $item){$actual[]=$item['TABLE_NAME'];if($item['TABLE_NAME']==='calendar_events'&&$item['TABLE_TYPE']!=='VIEW')throw new SafetyException('MIGRATION_011_RECONCILIATION_INVENTORY_REJECTED');}
        sort($actual);sort($expected);if($actual!==$expected)throw new SafetyException('MIGRATION_011_RECONCILIATION_INVENTORY_REJECTED');
        Migration011Validator::assertSchema(Migration011Validator::metadata($db),true);
    }

    private static function eligibleLedgerRow(array $row):bool
    {
        return ($row['version']??null)===MigrationPlan::MICROSOFT011['version']
            &&is_string($row['checksum']??null)&&hash_equals(MigrationPlan::MICROSOFT011['sha256'],$row['checksum'])
            &&($row['status']??null)==='failed'&&(int)($row['completed_statements']??-1)===1&&(int)($row['total_statements']??-1)===1
            &&in_array($row['failure_code']??null,['statement_failed','postcondition_failed'],true);
    }
}
