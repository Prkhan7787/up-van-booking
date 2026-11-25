<?php
declare(strict_types=1);
namespace App\Database;
use PDO;
use Throwable;

final class MigrationRunner
{
    private string $lastReadCheck='RUNNER_STATUS';
    private string $lastSafetyCheck='PREFLIGHT_SAFETY_CHECK';
    private array $lastSafetyDetail=[];
    public function __construct(private PDO $db, private string $target)
    {
        MigrationTarget::assertKnown($target);
    }
    private function rows(string $sql, array $params = [], string $check='METADATA_QUERY'): array
    {
        $this->lastReadCheck=$check;
        $query = $this->db->prepare($sql); $query->execute($params); return $query->fetchAll(PDO::FETCH_ASSOC);
    }
    public function lastReadCheck(): string { return $this->lastReadCheck; }
    public function lastSafetyCheck(): string { return $this->lastSafetyCheck; }
    public function lastSafetyDetail(): array { return $this->lastSafetyDetail; }
    public function status(): array
    {
        if ($this->db->query('SELECT DATABASE()')->fetchColumn() !== $this->target) throw new SafetyException('Migration connection mismatch.');
        $objects = $this->rows('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :db', ['db'=>$this->target], 'OBJECT_INVENTORY');
        $names = array_column($objects, 'TABLE_NAME');
        if (!in_array('schema_migrations', $names, true)) return ['ledger'=>'absent','objects'=>$names,'migrations'=>[]];
        $migrations = $this->rows('SELECT version, checksum, status, completed_statements, total_statements FROM schema_migrations ORDER BY version', [], 'LEDGER_SELECT');
        $has006=in_array(MigrationPlan::VEHICLE006['version'],array_column($migrations,'version'),true);
        foreach ($migrations as $migration) {
            if ($migration['version'] === '004_auth_security' && $migration['status'] === 'applied') {
                $this->lastSafetyCheck='MIGRATION_004_SCHEMA_POSTCONDITION';$this->assertAuthSecurity004Schema();
            }
            if ($migration['version'] === Migration005VehicleValidator::VERSION) {
                $this->lastSafetyCheck='MIGRATION_005_LEDGER_STATE';
                Migration005VehicleValidator::assertLedgerApplied($migration);
                $this->lastSafetyCheck='MIGRATION_005_SCHEMA_POSTCONDITION';
                if(!$has006)$this->assertVehicle005Schema();
            }
            if($migration['version']===MigrationPlan::VEHICLE006['version']) {
                if($migration['status']!=='applied' || (int)$migration['completed_statements']!==2 || (int)$migration['total_statements']!==2 || !hash_equals(MigrationPlan::VEHICLE006['sha256'],(string)$migration['checksum']))throw new SafetyException('MIGRATION_006_PARTIAL_LEDGER');
                $this->assertVehicle006Schema();
            }
            if($migration['version']===MigrationPlan::VEHICLE007['version']) {
                if($migration['status']!=='applied' || (int)$migration['completed_statements']!==1 || (int)$migration['total_statements']!==1 || !hash_equals(MigrationPlan::VEHICLE007['sha256'],(string)$migration['checksum']))throw new SafetyException('MIGRATION_007_PARTIAL_LEDGER');
                $this->assertVehicle007Schema();
            }
            if($migration['version']===MigrationPlan::BOOKING008['version']) {
                if($migration['status']!=='applied' || (int)$migration['completed_statements']!==1 || (int)$migration['total_statements']!==1 || !hash_equals(MigrationPlan::BOOKING008['sha256'],(string)$migration['checksum']))throw new SafetyException('MIGRATION_008_PARTIAL_LEDGER');
                $this->assertBooking008Schema();
            }
        }
        return ['ledger'=>'present','objects'=>$names,'migrations'=>$migrations];
    }

    /** SELECT-only 010 pre/postflight. No execution/resume path is enabled here. */
    public function driver010Inspect(bool $postflight = false): array
    {
        if ($this->target !== MigrationTarget::TEST) throw new SafetyException('MIGRATION_010_TEST_ONLY');
        DriverDatabaseIdentity::inspect($this->db, Migration010ReadOnlyGrantPlan::ACCOUNT);
        $this->lastSafetyCheck = 'MIGRATION_010_BASELINE';
        $ledger = $this->rows('SELECT version, checksum, status, completed_statements, total_statements FROM schema_migrations ORDER BY version');
        $metadata = $this->driver010Metadata();
        $this->lastSafetyDetail = Migration010DriverValidator::partialState($metadata,$postflight);
        Migration010DriverValidator::assertLedger($ledger, $postflight);
        $this->lastSafetyCheck = 'MIGRATION_010_SQL_CHECKSUM';
        MigrationPlan::load(include010: true);
        $this->lastSafetyCheck = 'MIGRATION_010_SCHEMA';
        $visibility=Migration010DriverValidator::metadataVisibility($metadata,$postflight);
        if($visibility['status']!=='COMPLETE'){
            $this->lastSafetyCheck='MIGRATION_010_METADATA_VISIBILITY';
            $this->lastSafetyDetail=Migration010DriverValidator::partialState($metadata,$postflight);
            throw new SafetyException((string)$visibility['reason_code']);
        }
        Migration010DriverValidator::assertSchema($metadata, $postflight);
        // Reuse previous schema postconditions, including username format and unique index.
        $this->status();
        return ['status'=>'PASS','mode'=>$postflight ? 'postflight' : 'preflight','database'=>$this->target,
            'uuid'=>DriverDatabaseIdentity::UUID,'checksum'=>MigrationPlan::DRIVER010['sha256'],
            'statement_count'=>3,'automatic_retry_allowed'=>false];
    }

    private function driver010Metadata(): array
    {
        $metadata = ['columns'=>[], 'engines'=>[]];
        foreach (['users','drivers'] as $table) {
            $metadata['columns'][$table] = $this->rows('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:table ORDER BY ORDINAL_POSITION', ['db'=>$this->target,'table'=>$table]);
            $metadata['engines'][$table] = $this->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:table', ['db'=>$this->target,'table'=>$table])[0]['ENGINE'] ?? null;
        }
        $metadata['indexes'] = $this->rows("SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=:db AND TABLE_NAME IN ('users','drivers') ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX", ['db'=>$this->target]);
        $metadata['link_foreign_keys'] = $this->rows("SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=:db AND k.TABLE_NAME='drivers' AND (k.COLUMN_NAME='user_id' OR k.CONSTRAINT_NAME='fk_drivers_user')", ['db'=>$this->target]);
        $metadata['roles'] = $this->rows('SELECT name, description FROM roles ORDER BY name');
        return $metadata;
    }

    /** SELECT-only inspection used by the Task 05D-7C CLI. */
    public function vehicle005Preflight(): array
    {
        Migration005VehicleValidator::assertTarget($this->target);
        if ($this->db->query('SELECT DATABASE()')->fetchColumn() !== MigrationTarget::TEST) throw new SafetyException('Migration connection mismatch.');
        $this->lastSafetyCheck='PREFLIGHT_TARGET';$plan=MigrationPlan::load();$state=$this->status();
        if ($state['ledger']!=='present') throw new SafetyException('Migration ledger is absent.');
        $this->lastSafetyCheck='BASELINE_LEDGER_001_004';
        $ledgerError=Migration005VehicleValidator::baselineLedgerError($plan,$state['migrations']);
        if ($ledgerError!==null) {$this->lastSafetyCheck=$ledgerError;throw new SafetyException($ledgerError);}
        $this->lastSafetyCheck='VEHICLE_005_SCHEMA_PREFLIGHT';
        $columnRows=$this->rows("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='vehicles' ORDER BY ORDINAL_POSITION",['db'=>$this->target],'VEHICLE_COLUMNS');
        $columns=array_column($columnRows,'COLUMN_NAME');$indexes=$this->vehicle005Indexes();
        $columnDiff=Migration005VehicleValidator::vehicleColumnDiff($columns);
        if ($columnDiff['missing']!==[] || $columnDiff['unexpected']!==[] || !$columnDiff['order_matches']) {
            $this->lastSafetyCheck='VEHICLE_METADATA_VISIBILITY';
            $this->lastSafetyDetail=['missing_columns'=>$columnDiff['missing'],'unexpected_columns'=>$columnDiff['unexpected'],'order_matches'=>$columnDiff['order_matches']];
            throw new SafetyException('Migration 005 vehicle column metadata is incomplete or differs from expected schema.');
        }
        $old=array_values(array_filter($indexes,static fn(array $i):bool=>$i[0]==='uq_vehicle_registration'));
        $dupes=$this->rows('SELECT registration_number, COUNT(*) AS copies FROM vehicles GROUP BY registration_number HAVING COUNT(*)>1 LIMIT 1',[],'DUPLICATE_REGISTRATION');
        $this->lastSafetyCheck='VEHICLE_005_PRECONDITIONS';
        Migration005VehicleValidator::assertPreconditions($columns,$old,$dupes);
        $province=$this->rows("SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='vehicles' AND COLUMN_NAME='registration_province'",['db'=>$this->target],'PROVINCE_COLUMN');
        $newIndex=$this->rows("SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='vehicles' AND INDEX_NAME='uq_vehicle_registration_number'",['db'=>$this->target],'NEW_REGISTRATION_INDEX');
        if ((int)$province[0]['n']!==1 || (int)$newIndex[0]['n']!==0) throw new SafetyException('Migration 005 schema drift detected.');
        $this->lastSafetyCheck='REFERENCE_COUNTS';
        $counts=$this->rows('SELECT (SELECT COUNT(id) FROM vehicles) AS vehicles, (SELECT COUNT(id) FROM booking_requests) AS booking_requests, (SELECT COUNT(id) FROM vehicle_assignments) AS vehicle_assignments',[],'REFERENCE_COUNTS');
        return ['target'=>$this->target,'ledger'=>'001-004_applied_005_absent','checks'=>['target'=>'PASS','ledger'=>'PASS','schema'=>'PASS','duplicates'=>'PASS'],'counts'=>$counts[0]??[]];
    }
    public function apply(bool $include006=false,?string $backupPath=null,bool $include007=false,?string $backupSha256=null,bool $include008=false): array
    {
        if(($include006 || $include007 || $include008) && ($backupPath===null || !is_file($backupPath) || filesize($backupPath)===false || filesize($backupPath)<1))throw new SafetyException($include008?'MIGRATION_008_BACKUP_REQUIRED':($include007?'MIGRATION_007_BACKUP_REQUIRED':'MIGRATION_006_BACKUP_REQUIRED'));
        if(($include007 || $include008) && $this->target!==MigrationTarget::TEST)throw new SafetyException($include008?'MIGRATION_008_TEST_TARGET_ONLY':'MIGRATION_007_TEST_TARGET_ONLY');
        if($include007 || $include008)Migration007BackupValidator::inspect((string)$backupPath,(string)$backupSha256);
        ConnectionFactory::requireMigrationApproval($this->target);
        $plan = MigrationPlan::load($include006 || $include007 || $include008,$include007 || $include008,$include008);
        if ($this->target !== MigrationTarget::TEST) {
            foreach ($plan as $entry) if ($entry['version'] === Migration005VehicleValidator::VERSION) throw new SafetyException('Migration 005 is test-database only.');
        }
        ConnectionFactory::assertMigrationConnection($this->db, $this->target);
        if($include006) {
            Migration005VehicleValidator::assertTarget($this->target);
            ConnectionFactory::assertMigration006Grants($this->db);
        }
        if($include007 || $include008)ConnectionFactory::assertMigration007Grants($this->db);
        if($include007 || $include008)Migration007BackupValidator::inspect((string)$backupPath,(string)$backupSha256);
        if ($this->target !== MigrationTarget::TEST) {
            foreach ($plan as $entry) if ($entry['version'] === Migration005VehicleValidator::VERSION) throw new SafetyException('Migration 005 is test-database only.');
        }
        $lockName = $this->target . ':migrations';
        if ((int)$this->rows('SELECT GET_LOCK(:name, 0) AS acquired',['name'=>$lockName])[0]['acquired'] !== 1) throw new SafetyException('Another migration process holds the lock.');
        $vehicle007Before=null;$vehicle007After=null;$booking008Before=null;$booking008After=null;
        try {
            $state = $this->status();
            $schema = $this->rows('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :db',['db'=>$this->target]);
            if (!$schema || $schema[0]['DEFAULT_CHARACTER_SET_NAME'] !== 'utf8mb4' || $schema[0]['DEFAULT_COLLATION_NAME'] !== 'utf8mb4_unicode_ci') throw new SafetyException('Target database charset/collation mismatch.');
            if ($state['ledger'] === 'absent' && $state['objects'] !== []) throw new SafetyException('Existing database objects without ledger: no automatic adoption or overwrite.');
            $applied = []; $expected = ['schema_migrations'];
            foreach ($state['migrations'] as $row) {
                $match = array_values(array_filter($plan, static fn(array $m): bool => $m['version'] === $row['version']));
                if (!$match || !hash_equals($match[0]['sha256'], $row['checksum']) || $row['status'] !== 'applied'
                    || (int)$row['completed_statements'] !== count($match[0]['statements']) || (int)$row['total_statements'] !== count($match[0]['statements'])) throw new SafetyException('Dirty or changed migration ledger; manual review required.');
                $applied[$row['version']] = true; $expected = array_merge($expected,$match[0]['objects']);
            }
            if ($state['ledger'] === 'present') {
                $actual = $state['objects']; sort($actual); sort($expected);
                if ($actual !== $expected) throw new SafetyException('Database object inventory differs from ledger; manual review required.');
                $pendingSeen = false;
                foreach ($plan as $entry) {
                    if (!isset($applied[$entry['version']])) $pendingSeen = true;
                    elseif ($pendingSeen) throw new SafetyException('Migration ledger order is invalid.');
                }
            } else {
                $this->db->exec("CREATE TABLE schema_migrations (
                    version VARCHAR(64) PRIMARY KEY, checksum CHAR(64) NOT NULL,
                    status VARCHAR(16) NOT NULL, completed_statements INT UNSIGNED NOT NULL DEFAULT 0,
                    total_statements INT UNSIGNED NOT NULL, started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                    finished_at DATETIME(6) NULL, failure_code VARCHAR(32) NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            foreach ($plan as $entry) {
                if (isset($applied[$entry['version']])) continue;
                if ($entry['version'] === '004_auth_security') $this->assertAuthSecurity004Preconditions();
                if ($entry['version'] === Migration005VehicleValidator::VERSION) {
                    Migration005VehicleValidator::assertTarget($this->target);
                    $this->assertVehicle005Preconditions();
                }
                if($entry['version']===MigrationPlan::VEHICLE006['version'])$this->vehicle006Preflight();
                if($entry['version']===MigrationPlan::VEHICLE007['version']) {
                    $preflight=$this->vehicle007Preflight();
                    $vehicle007Before=$preflight['existing_vehicle_fields_sha256'];
                }
                if($entry['version']===MigrationPlan::BOOKING008['version']) {
                    $preflight=$this->booking008Preflight();
                    $booking008Before=$preflight['existing_booking_ids_sha256'];
                }
                $insert = $this->db->prepare("INSERT INTO schema_migrations (version,checksum,status,total_statements) VALUES (:v,:c,'started',:n)");
                $insert->execute(['v'=>$entry['version'],'c'=>$entry['sha256'],'n'=>count($entry['statements'])]);
                $executionStage='DDL';$statementNumber=1;
                try {
                    foreach ($entry['statements'] as $i=>$sql) {
                        // Reviewed, checksum-pinned static SQL. No external data is interpolated.
                        $executionStage='DDL';$statementNumber=$i+1;
                        $this->db->exec($sql);
                        if ($entry['version'] === Migration005VehicleValidator::VERSION) $this->assertVehicle005Schema();
                        if($entry['version']===MigrationPlan::VEHICLE007['version']) {
                            $this->assertVehicle007Schema();
                            $vehicle007After=$this->vehicle007DataFingerprint();
                        }
                        if($entry['version']===MigrationPlan::BOOKING008['version']) {
                            $this->assertBooking008Schema();
                            $booking008After=$this->booking008IdsFingerprint();
                        }
                        $executionStage='LEDGER_PROGRESS';
                        $step = $this->db->prepare('UPDATE schema_migrations SET completed_statements=:n WHERE version=:v');
                        $step->execute(['n'=>$i+1,'v'=>$entry['version']]);
                    }
                    $executionStage='POSTCONDITIONS';
                    if($entry['version']===MigrationPlan::VEHICLE006['version'])$this->assertVehicle006Schema();
                    $executionStage='LEDGER_APPLIED';
                    $done = $this->db->prepare("UPDATE schema_migrations SET status='applied', finished_at=CURRENT_TIMESTAMP(6) WHERE version=:v");
                    $done->execute(['v'=>$entry['version']]);
                } catch (Throwable $originalError) {
                    // DDL may already be committed; never DROP, rollback-all, or auto-retry it.
                    try {
                        $failed = $this->db->prepare("UPDATE schema_migrations SET status='failed', failure_code='statement_failed' WHERE version=:v");
                        $failed->execute(['v'=>$entry['version']]);
                    } catch (Throwable) { /* Leave started entry as dirty evidence if connection lost. */ }
                    if(in_array($entry['version'],[MigrationPlan::VEHICLE006['version'],MigrationPlan::VEHICLE007['version'],MigrationPlan::BOOKING008['version']],true)) {
                        throw MigrationExecutionException::fromFailure($originalError,$statementNumber,$executionStage,$entry['version']);
                    }
                    throw new SafetyException('Migration stopped. Partial DDL may exist; inspect ledger and object inventory before recovery.');
                }
            }
            $result=$this->status();
            if($include007)$result['migration_007_vehicle_data_fingerprints']=['before'=>$vehicle007Before,'after'=>$vehicle007After];
            if($include008)$result['migration_008_booking_id_fingerprints']=['before'=>$booking008Before,'after'=>$booking008After];
            return $result;
        } finally {
            try { $this->rows('SELECT RELEASE_LOCK(:name)',['name'=>$lockName]); } catch (Throwable) {}
        }
    }

    /** Explicit independent branch; canonical sequential apply() remains unchanged. */
    public function microsoft011Apply(string $backupDirectory,string $manifestSha256): array
    {
        if($this->target!==MigrationTarget::TEST)throw new SafetyException('MIGRATION_011_TEST_ONLY');
        Migration011ApplyGuard::requireApproval();
        $this->lastSafetyCheck='MIGRATION_011_IDENTITY';Migration011ApplyGuard::assertIdentity($this->db);
        $lock=$this->target.':migrations';
        $this->lastSafetyCheck='MIGRATION_011_LOCK';
        if((string)($this->rows('SELECT GET_LOCK(:name, 0) AS acquired',['name'=>$lock])[0]['acquired']??'')!=='1')throw new SafetyException('MIGRATION_011_LOCK_UNAVAILABLE');
        $started=false;$completed=0;$ledgerCompleted=null;$applied=false;$stage='PRECONDITIONS';$failure=null;$released=false;$result=null;
        $write=function(string $sql,array $params):void{
            $q=$this->db->prepare($sql);
            if(!$q->execute($params)||$q->rowCount()!==1)throw new SafetyException('MIGRATION_011_LEDGER_WRITE_UNCONFIRMED');
        };
        $ledger=fn():array=>$this->rows('SELECT version, checksum, status, completed_statements, total_statements FROM schema_migrations ORDER BY version');
        $held=function()use($lock):void{
            if((string)($this->rows('SELECT IS_USED_LOCK(:name) = CONNECTION_ID() AS held',['name'=>$lock])[0]['held']??'')!=='1')throw new SafetyException('MIGRATION_011_LOCK_LOST');
        };
        try {
            Migration011ApplyGuard::assertIdentity($this->db);
            $this->lastSafetyCheck='MIGRATION_011_GRANTS';Migration011ApplyGuard::assertGrants($this->db);
            $session=$this->db->query('SELECT @@SESSION.autocommit AS autocommit, @@SESSION.foreign_key_checks AS foreign_key_checks')->fetch(PDO::FETCH_ASSOC);
            if($this->db->inTransaction()||(string)($session['autocommit']??'')!=='1'||(string)($session['foreign_key_checks']??'')!=='1')throw new SafetyException('MIGRATION_011_SESSION_REJECTED');
            $this->lastSafetyCheck='MIGRATION_011_BACKUP';$backup=Migration011ApplyBackup::verify($backupDirectory,$manifestSha256);
            $this->lastSafetyCheck='MIGRATION_011_SQL_AND_LEDGER';$branch=MigrationPlan::microsoft011();$entry=$branch['migration'];
            Migration011Validator::assertLedger($ledger());
            $this->lastSafetyCheck='MIGRATION_011_LIVE_PRECONDITIONS';
            Migration011Validator::assertSchema(Migration011Validator::metadata($this->db),false);
            $held();
            $stage='LEDGER_STARTED';$this->lastSafetyCheck='MIGRATION_011_EXECUTION';
            $write("INSERT INTO schema_migrations (version,checksum,status,total_statements) VALUES (:version,:checksum,'started',1)",['version'=>$entry['version'],'checksum'=>$entry['sha256']]);
            $started=true;$ledgerCompleted=0;
            $stage='LOCK_CHECK';$held();
            $stage='DDL';
            if($this->db->exec($entry['statements'][0])===false)throw new SafetyException('MIGRATION_011_DDL_UNCONFIRMED');
            $completed=1;$stage='LEDGER_PROGRESS';
            $write("UPDATE schema_migrations SET completed_statements=1 WHERE version=:version AND status='started' AND completed_statements=0",['version'=>$entry['version']]);
            $ledgerCompleted=1;$stage='POSTCONDITIONS';
            Migration011ApplyGuard::assertIdentity($this->db);$held();
            Migration011Validator::assertSchema(Migration011Validator::metadata($this->db),true);
            Migration011Validator::assertLedger($ledger(),'progress');
            $stage='LEDGER_APPLIED';$held();
            $write("UPDATE schema_migrations SET status='applied',finished_at=UTC_TIMESTAMP(6) WHERE version=:version AND status='started' AND completed_statements=1 AND total_statements=1",['version'=>$entry['version']]);
            $applied=true;Migration011Validator::assertLedger($ledger(),'after');
            $result=['status'=>'PASS','migration'=>$entry['version'],'checksum'=>$entry['sha256'],'confirmed_completed_statements'=>1,
                'ledger'=>'applied 1/1','schema_postconditions'=>'PASS','010'=>'absent_pending','backup'=>$backup,'automatic_retry'=>false];
        } catch(Throwable $e) {
            if($started&&!$applied){
                $failureCode=match($stage){
                    'DDL'=>'statement_failed',
                    'POSTCONDITIONS'=>'postcondition_failed',
                    'LOCK_CHECK'=>'execution_guard_failed',
                    default=>'ledger_failed',
                };
                try{$held();$write("UPDATE schema_migrations SET status='failed',failure_code=:failure_code WHERE version=:version AND status='started'",[
                    'failure_code'=>$failureCode,'version'=>MigrationPlan::MICROSOFT011['version']]);}catch(Throwable){}
            }
            $failure=$stage==='PRECONDITIONS'?$e:MigrationExecutionException::fromFailure($e,$stage==='DDL'?1:0,$stage,MigrationPlan::MICROSOFT011['version'],$completed,$ledgerCompleted,$applied);
        } finally {
            try{$released=(string)($this->rows('SELECT RELEASE_LOCK(:name) AS released',['name'=>$lock])[0]['released']??'')==='1';}catch(Throwable){}
        }
        // An advisory lock only serializes cooperating runners. It cannot stop external DDL.
        if($failure instanceof MigrationExecutionException)throw $failure->withLockRelease($released);
        if(!$released)throw MigrationExecutionException::fromFailure(new SafetyException('MIGRATION_011_LOCK_RELEASE_UNVERIFIED'),0,'LOCK_RELEASE',MigrationPlan::MICROSOFT011['version'],$completed,$ledgerCompleted,$applied)->withLockRelease(false);
        if($failure!==null)throw $failure;
        return $result+['migration_lock_released'=>true];
    }

    /** Apply only the pinned 010 plan on the dedicated live test connection. */
    public function driver010Apply(string $backupDirectory, string $manifestSha256): array
    {
        if ($this->target !== MigrationTarget::TEST) throw new SafetyException('MIGRATION_010_TEST_ONLY');
        Migration010ApplyGuard::requireApproval();
        $this->lastSafetyCheck = 'MIGRATION_010_APPLY_IDENTITY';
        Migration010ApplyGuard::assertIdentity($this->db);
        $lock = $this->target . ':migrations';
        $this->lastSafetyCheck = 'MIGRATION_010_APPLY_LOCK';
        if ((string)($this->rows('SELECT GET_LOCK(:name, 0) AS acquired', ['name'=>$lock])[0]['acquired'] ?? '') !== '1') {
            throw new SafetyException('MIGRATION_010_APPLY_LOCK_UNAVAILABLE');
        }
        $result = null;
        try {
            Migration010ApplyGuard::assertIdentity($this->db);
            $this->lastSafetyCheck = 'MIGRATION_010_APPLY_GRANTS';
            Migration010ApplyGuard::assertGrants($this->db);
            $session = $this->db->query('SELECT @@SESSION.autocommit AS autocommit, @@SESSION.foreign_key_checks AS foreign_key_checks')->fetch(PDO::FETCH_ASSOC);
            if ($this->db->inTransaction() || (string)($session['autocommit'] ?? '') !== '1'
                || (string)($session['foreign_key_checks'] ?? '') !== '1') {
                throw new SafetyException('MIGRATION_010_APPLY_SESSION_REJECTED');
            }
            $this->lastSafetyCheck = 'MIGRATION_010_APPLY_BACKUP';
            $backup = Migration010ApplyBackup::verify($backupDirectory, $manifestSha256, requireFresh: true);
            $this->lastSafetyCheck = 'MIGRATION_010_SQL_CHECKSUM';
            $plan = MigrationPlan::load(include010: true);
            $entry = end($plan);
            if ($entry['version'] !== MigrationPlan::DRIVER010['version'] || count($entry['statements']) !== 3) {
                throw new SafetyException('MIGRATION_010_PLAN_REJECTED');
            }
            $this->lastSafetyCheck = 'MIGRATION_010_APPLY_LIVE_SCHEMA';
            $ledger = $this->driver010Ledger();
            Migration010DriverValidator::assertLedger($ledger, false);
            if ($backup['ledger_state'] !== Migration010ApplyGuard::backupLedgerState($ledger)) {
                throw new SafetyException('MIGRATION_010_BACKUP_LIVE_BRANCH_MISMATCH');
            }
            $this->assertDriver010ApplySchema(false);
            // The named lock serializes cooperating migration runners only.
            $this->assertDriver010Lock($lock);
            $result = $this->executeDriver010($entry, $lock);
            $result['backup'] = $backup;
        } finally {
            try {
                if ((string)($this->rows('SELECT RELEASE_LOCK(:name) AS released', ['name'=>$lock])[0]['released'] ?? '') !== '1') {
                    throw new SafetyException('MIGRATION_010_APPLY_LOCK_RELEASE_UNVERIFIED');
                }
            } catch (Throwable $error) {
                // Preserve the original failure. MySQL drops session locks if the connection is lost.
                if ($result !== null) {
                    throw MigrationExecutionException::fromFailure($error,0,'LOCK_RELEASE',MigrationPlan::DRIVER010['version'],3,3,true);
                }
            }
        }
        return $result;
    }

    private function driver010Ledger(): array
    {
        return $this->rows('SELECT version, checksum, status, completed_statements, total_statements FROM schema_migrations ORDER BY version');
    }

    private function assertDriver010Lock(string $lock): void
    {
        if ((string)($this->rows('SELECT IS_USED_LOCK(:name) = CONNECTION_ID() AS held', ['name'=>$lock])[0]['held'] ?? '') !== '1') {
            throw new SafetyException('MIGRATION_010_APPLY_LOCK_LOST');
        }
    }

    private function assertDriver010ApplySchema(bool $postflight): void
    {
        $metadata = $this->driver010Metadata();
        $this->lastSafetyDetail = Migration010DriverValidator::partialState($metadata,$postflight);
        Migration010DriverValidator::assertSchema($metadata,$postflight);
        $baseline = $this->status();
        if ($baseline['ledger'] !== 'present') throw new SafetyException('MIGRATION_010_LIVE_LEDGER_HIDDEN');

        // Mirror 009's live postconditions. Ledger alone cannot prove its soft-delete schema.
        if ((string)$this->db->query(self::driver010BaselineSchemaQuery())->fetchColumn() !== '1') {
            throw new SafetyException('MIGRATION_010_BASELINE_SCHEMA_REJECTED');
        }
    }

    public static function driver010BaselineSchemaQuery(): string
    {
        return <<<'SQL'
SELECT (
    (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('roles','schema_migrations','booking_requests')
        AND TABLE_TYPE='BASE TABLE' AND ENGINE='InnoDB')=3
    AND EXISTS (SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='roles' AND NON_UNIQUE=0
        GROUP BY INDEX_NAME HAVING COUNT(*)=1 AND MAX(COLUMN_NAME='name' AND SUB_PART IS NULL)=1)
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND INDEX_NAME='PRIMARY')=1
    AND EXISTS (SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND INDEX_NAME='PRIMARY'
        AND COLUMN_NAME='version' AND SUB_PART IS NULL)
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_requests' AND IS_NULLABLE='YES' AND COLUMN_DEFAULT IS NULL
        AND ((COLUMN_NAME='deleted_at' AND COLUMN_TYPE='datetime(6)')
          OR (COLUMN_NAME='deleted_by' AND COLUMN_TYPE='bigint unsigned')
          OR (COLUMN_NAME='deletion_reason' AND COLUMN_TYPE='varchar(100)')))=3
    AND EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_requests' AND CONSTRAINT_NAME='fk_booking_deleted_by'
        AND COLUMN_NAME='deleted_by' AND REFERENCED_TABLE_SCHEMA=DATABASE()
        AND REFERENCED_TABLE_NAME='users' AND REFERENCED_COLUMN_NAME='id')
    AND EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_requests' AND CONSTRAINT_NAME='chk_booking_deleted_fields'
        AND CONSTRAINT_TYPE='CHECK' AND ENFORCED='YES')
) AS ready
SQL;
    }

    private function driver010LedgerWrite(string $sql, array $params): void
    {
        $command = $this->db->prepare($sql);
        if (!$command->execute($params) || $command->rowCount() !== 1) {
            throw new SafetyException('MIGRATION_010_LEDGER_WRITE_NOT_CONFIRMED');
        }
    }

    private function executeDriver010(array $entry, string $lock): array
    {
        $version = $entry['version'];
        $completed = 0;
        $ledgerCompleted = null;
        $ledgerApplied = false;
        $statement = 0;
        $stage = 'LEDGER_STARTED';
        $this->lastSafetyCheck = 'MIGRATION_010_EXECUTION';
        try {
            $this->driver010LedgerWrite(
                "INSERT INTO schema_migrations (version,checksum,status,total_statements) VALUES (:version,:checksum,'started',3)",
                ['version'=>$version,'checksum'=>$entry['sha256']]
            );
            $ledgerCompleted = 0;
            foreach ($entry['statements'] as $index=>$sql) {
                $statement = $index + 1;
                $stage = 'LOCK_CHECK';
                $this->assertDriver010Lock($lock);
                $stage = $statement < 3 ? 'DDL' : 'STATEMENT';
                $affected = $this->db->exec($sql);
                if ($affected === false || ($statement === 3 && $affected !== 1)) {
                    throw new SafetyException('MIGRATION_010_STATEMENT_NOT_CONFIRMED');
                }
                $completed = $statement;
                $stage = 'LEDGER_PROGRESS';
                $this->driver010LedgerWrite(
                    "UPDATE schema_migrations SET completed_statements=:count WHERE version=:version AND status='started' AND completed_statements=:previous",
                    ['count'=>$completed,'version'=>$version,'previous'=>$index]
                );
                $ledgerCompleted = $completed;
            }
            $statement = 0;
            $stage = 'POSTCONDITIONS';
            Migration010ApplyGuard::assertIdentity($this->db);
            $this->assertDriver010ApplySchema(true);
            Migration010DriverValidator::assertLedgerProgress($this->driver010Ledger(),3);
            $this->assertDriver010Lock($lock);
            $stage = 'LEDGER_APPLIED';
            $this->driver010LedgerWrite(
                "UPDATE schema_migrations SET status='applied', finished_at=UTC_TIMESTAMP(6) WHERE version=:version AND status='started' AND completed_statements=3 AND total_statements=3",
                ['version'=>$version]
            );
            $ledgerApplied = true;
            Migration010DriverValidator::assertLedger($this->driver010Ledger(),true);
        } catch (Throwable $error) {
            if ($ledgerCompleted !== null && !$ledgerApplied) {
                try {
                    $this->assertDriver010Lock($lock);
                    $this->driver010LedgerWrite(
                        "UPDATE schema_migrations SET status='failed', failure_code='statement_failed' WHERE version=:version AND status='started'",
                        ['version'=>$version]
                    );
                } catch (Throwable) {
                    // A started row or uncertain final write is also a stop state for manual inspection.
                }
            }
            throw MigrationExecutionException::fromFailure($error,$statement,$stage,$version,$completed,$ledgerCompleted,$ledgerApplied);
        }
        return ['status'=>'PASS','mode'=>'apply','database'=>$this->target,'uuid'=>DriverDatabaseIdentity::UUID,
            'version'=>$version,'checksum'=>$entry['sha256'],'ledger'=>'applied','completed_statements'=>3,
            'total_statements'=>3,'live_schema_verified'=>true,'automatic_retry'=>false];
    }

    /** SELECT-only 007 readiness check; requires a clean Schema 006 and ledger 001–006. */
    public function vehicle007Preflight(): array
    {
        Migration005VehicleValidator::assertTarget($this->target);
        if($this->db->query('SELECT DATABASE()')->fetchColumn()!==MigrationTarget::TEST)throw new SafetyException('MIGRATION_007_TARGET_REJECTED');
        $this->lastSafetyCheck='MIGRATION_007_BASELINE';
        $plan=MigrationPlan::load(true,true);$state=$this->status();
        if($state['ledger']!=='present' || count($state['migrations'])!==6)throw new SafetyException('MIGRATION_007_BASELINE_LEDGER_REJECTED');
        foreach(array_slice($plan,0,6) as $i=>$entry) {
            $row=$state['migrations'][$i]??[];
            if(($row['version']??null)!==$entry['version'] || ($row['status']??null)!=='applied'
                || !hash_equals($entry['sha256'],(string)($row['checksum']??''))
                || (int)($row['completed_statements']??-1)!==count($entry['statements'])
                || (int)($row['total_statements']??-1)!==count($entry['statements']))throw new SafetyException('MIGRATION_007_BASELINE_LEDGER_REJECTED');
        }
        $this->lastSafetyCheck='MIGRATION_007_SCHEMA_PREFLIGHT';
        $columns=$this->rows("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='vehicles' ORDER BY ORDINAL_POSITION",['db'=>$this->target],'MIGRATION_007_VEHICLE_COLUMNS');
        $fuel=$this->rows("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='vehicles' AND COLUMN_NAME IN ('fuel_type','fuel_efficiency_km_per_l') ORDER BY FIELD(COLUMN_NAME,'fuel_type','fuel_efficiency_km_per_l')",['db'=>$this->target],'MIGRATION_007_FUEL_COLUMNS');
        $checks=$this->rows("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=:db AND TABLE_NAME='vehicles' AND CONSTRAINT_TYPE='CHECK' AND CONSTRAINT_NAME=:name",['db'=>$this->target,'name'=>Migration007VehicleValidator::CHECK],'MIGRATION_007_CHECK_CONSTRAINT');
        Migration007VehicleValidator::assertPreconditions(array_column($columns,'COLUMN_NAME'),array_map('array_values',$fuel),$checks);
        $fingerprint=$this->vehicle007DataFingerprint();
        return ['status'=>'PASS','target'=>$this->target,'step'=>'MIGRATION_007_PREFLIGHT','checksum'=>MigrationPlan::VEHICLE007['sha256'],'statement_count'=>1,'ledger'=>'001-006_applied_007_absent','existing_vehicle_count'=>$fingerprint['count'],'existing_vehicle_fields_sha256'=>$fingerprint['sha256']];
    }

    public function vehicle007Postflight(): array
    {
        $state=$this->status();
        if($state['ledger']!=='present' || count($state['migrations'])!==7)throw new SafetyException('MIGRATION_007_POSTFLIGHT_LEDGER_REJECTED');
        $last=$state['migrations'][6];
        if(($last['version']??null)!==MigrationPlan::VEHICLE007['version'] || ($last['status']??null)!=='applied'
            || !hash_equals(MigrationPlan::VEHICLE007['sha256'],(string)($last['checksum']??''))
            || (int)($last['completed_statements']??0)!==1 || (int)($last['total_statements']??0)!==1)throw new SafetyException('MIGRATION_007_POSTFLIGHT_LEDGER_REJECTED');
        $fingerprint=$this->vehicle007DataFingerprint();
        return ['status'=>'PASS','target'=>$this->target,'step'=>'MIGRATION_007_POSTFLIGHT','ledger'=>'applied 1/1','checksum'=>MigrationPlan::VEHICLE007['sha256'],'existing_vehicle_count'=>$fingerprint['count'],'existing_vehicle_fields_sha256'=>$fingerprint['sha256']];
    }

    private function vehicle007DataFingerprint(): array
    {
        $rows=$this->db->query('SELECT id,registration_number,registration_province,label,passenger_capacity,is_active,created_by,updated_by,created_at,updated_at FROM vehicles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return ['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
    }

    /** Read-only 008 readiness check; requires the exact, fully applied 001–007 baseline. */
    public function booking008Preflight(): array
    {
        Migration005VehicleValidator::assertTarget($this->target);
        if($this->db->query('SELECT DATABASE()')->fetchColumn()!==MigrationTarget::TEST)throw new SafetyException('MIGRATION_008_TARGET_REJECTED');
        $this->lastSafetyCheck='MIGRATION_008_BASELINE';
        $plan=MigrationPlan::load(true,true,true);$state=$this->status();
        if($state['ledger']!=='present' || count($state['migrations'])!==7)throw new SafetyException('MIGRATION_008_BASELINE_LEDGER_REJECTED');
        foreach(array_slice($plan,0,7) as $i=>$entry) {
            $row=$state['migrations'][$i]??[];
            if(($row['version']??null)!==$entry['version'] || ($row['status']??null)!=='applied'
                || !hash_equals($entry['sha256'],(string)($row['checksum']??''))
                || (int)($row['completed_statements']??-1)!==count($entry['statements'])
                || (int)($row['total_statements']??-1)!==count($entry['statements']))throw new SafetyException('MIGRATION_008_BASELINE_LEDGER_REJECTED');
        }
        $this->lastSafetyCheck='MIGRATION_008_SCHEMA_PREFLIGHT';
        $engine=$this->booking008StorageEngine();
        if($engine!=='InnoDB')throw new SafetyException('MIGRATION_008_ENGINE_REJECTED');
        Migration008SnapshotValidator::assertPreconditions($this->booking008SnapshotColumn());
        $fingerprint=$this->booking008IdsFingerprint();
        return ['status'=>'PASS','target'=>$this->target,'step'=>'MIGRATION_008_PREFLIGHT','checksum'=>MigrationPlan::BOOKING008['sha256'],'statement_count'=>1,'ledger'=>'001-007_applied_008_absent','table_engine'=>$engine,'snapshot_column'=>'absent','existing_booking_count'=>$fingerprint['count'],'existing_booking_ids_sha256'=>$fingerprint['sha256']];
    }

    public function booking008Postflight(): array
    {
        $state=$this->status();
        if($state['ledger']!=='present' || count($state['migrations'])!==8)throw new SafetyException('MIGRATION_008_POSTFLIGHT_LEDGER_REJECTED');
        $last=$state['migrations'][7];
        if(($last['version']??null)!==MigrationPlan::BOOKING008['version'] || ($last['status']??null)!=='applied'
            || !hash_equals(MigrationPlan::BOOKING008['sha256'],(string)($last['checksum']??''))
            || (int)($last['completed_statements']??0)!==1 || (int)($last['total_statements']??0)!==1)throw new SafetyException('MIGRATION_008_POSTFLIGHT_LEDGER_REJECTED');
        $this->assertBooking008Schema();
        $engine=$this->booking008StorageEngine();
        if($engine!=='InnoDB')throw new SafetyException('MIGRATION_008_ENGINE_REJECTED');
        $fingerprint=$this->booking008IdsFingerprint();
        return ['status'=>'PASS','target'=>$this->target,'step'=>'MIGRATION_008_POSTFLIGHT','ledger'=>'applied 1/1','checksum'=>MigrationPlan::BOOKING008['sha256'],'table_engine'=>$engine,'snapshot_column'=>'JSON NULL','existing_booking_count'=>$fingerprint['count'],'existing_booking_ids_sha256'=>$fingerprint['sha256']];
    }

    private function booking008SnapshotColumn(): array
    {
        $rows=$this->rows("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='booking_requests' AND COLUMN_NAME='a4_request_data'",['db'=>$this->target],'MIGRATION_008_SNAPSHOT_COLUMN');
        return array_map('array_values',$rows);
    }

    private function assertBooking008Schema(): void
    {
        Migration008SnapshotValidator::assertPostconditions($this->booking008SnapshotColumn());
    }

    private function booking008StorageEngine(): string
    {
        $rows=$this->rows("SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME='booking_requests' AND TABLE_TYPE='BASE TABLE'",['db'=>$this->target],'MIGRATION_008_TABLE_ENGINE');
        return count($rows)===1?(string)$rows[0]['ENGINE']:'';
    }

    private function booking008IdsFingerprint(): array
    {
        $rows=$this->db->query('SELECT id FROM booking_requests ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        return ['count'=>count($rows),'sha256'=>hash('sha256',json_encode(array_map('strval',$rows),JSON_THROW_ON_ERROR))];
    }

    private function assertVehicle007Schema(): void
    {
        $fuel=$this->rows("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='vehicles' AND COLUMN_NAME IN ('fuel_type','fuel_efficiency_km_per_l') ORDER BY FIELD(COLUMN_NAME,'fuel_type','fuel_efficiency_km_per_l')",['db'=>$this->target],'MIGRATION_007_FUEL_POSTCONDITION');
        $checks=$this->rows("SELECT tc.CONSTRAINT_NAME,cc.CHECK_CLAUSE,tc.ENFORCED FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc JOIN INFORMATION_SCHEMA.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA=:db AND tc.TABLE_NAME='vehicles' AND tc.CONSTRAINT_TYPE='CHECK' AND tc.CONSTRAINT_NAME=:name",['db'=>$this->target,'name'=>Migration007VehicleValidator::CHECK],'MIGRATION_007_CHECK_POSTCONDITION');
        Migration007VehicleValidator::assertPostconditions(array_map('array_values',$fuel),$checks);
    }

    private function assertAuthSecurity004Schema(): void
    {
        $columns = $this->rows('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME, CHARACTER_MAXIMUM_LENGTH, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:table', ['db'=>$this->target,'table'=>'users']);
        $byName = [];
        foreach ($columns as $column) $byName[$column['COLUMN_NAME']] = $column;
        $username = $byName['username'] ?? null;
        $authVersion = $byName['auth_version'] ?? null;
        if (!$username || $username['COLUMN_TYPE'] !== 'varchar(50)' || $username['IS_NULLABLE'] !== 'YES'
            || $username['CHARACTER_SET_NAME'] !== 'ascii' || $username['COLLATION_NAME'] !== 'ascii_general_ci'
            || (int)$username['CHARACTER_MAXIMUM_LENGTH'] !== 50
            || !$authVersion || $authVersion['COLUMN_TYPE'] !== 'bigint unsigned' || $authVersion['IS_NULLABLE'] !== 'NO'
            || (string)$authVersion['COLUMN_DEFAULT'] !== '1') throw new SafetyException('Migration 004 users schema postcondition failed.');
        $indexes = $this->rows('SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:table AND INDEX_NAME=:index', ['db'=>$this->target,'table'=>'users','index'=>'uq_users_username']);
        if (count($indexes) !== 1 || (int)$indexes[0]['NON_UNIQUE'] !== 0 || $indexes[0]['COLUMN_NAME'] !== 'username') throw new SafetyException('Migration 004 username unique index postcondition failed.');
        $userChecks = $this->rows("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='users' AND CONSTRAINT_TYPE='CHECK'", ['db'=>$this->target]);
        if (!in_array('chk_users_username_format', array_column($userChecks, 'CONSTRAINT_NAME'), true)) throw new SafetyException('Migration 004 username format check postcondition failed.');
        $rateTable = $this->rows('SELECT ENGINE, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:table', ['db'=>$this->target,'table'=>'auth_login_buckets']);
        if (!$rateTable || $rateTable[0]['ENGINE'] !== 'InnoDB' || $rateTable[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') throw new SafetyException('Migration 004 rate-limit table postcondition failed.');
        $rateColumns = $this->rows('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_DEFAULT, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:table', ['db'=>$this->target,'table'=>'auth_login_buckets']);
        Migration004PostconditionValidator::assertRateLimitColumns($rateColumns);
        $rateChecks = $this->rows("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='auth_login_buckets' AND CONSTRAINT_TYPE='CHECK'", ['db'=>$this->target]);
        if (!in_array('chk_auth_login_bucket_scope', array_column($rateChecks, 'CONSTRAINT_NAME'), true)) throw new SafetyException('Migration 004 bucket scope check postcondition failed.');
    }

    private function assertAuthSecurity004Preconditions(): void
    {
        $columns = $this->rows("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='users' AND COLUMN_NAME IN ('username','auth_version')", ['db'=>$this->target]);
        $table = $this->rows("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME='auth_login_buckets'", ['db'=>$this->target]);
        if ($columns !== [] || $table !== []) throw new SafetyException('Migration 004 pre-existing authentication objects detected; inspect for drift before applying.');
    }

    private function vehicle005Columns(): array
    {
        $rows=$this->rows("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='vehicles' ORDER BY ORDINAL_POSITION",['db'=>$this->target]);
        return array_column($rows,'COLUMN_NAME');
    }
    private function vehicle005Indexes(): array
    {
        $rows=$this->rows("SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='vehicles' AND INDEX_NAME IN ('uq_vehicle_registration','uq_vehicle_registration_number') ORDER BY INDEX_NAME, SEQ_IN_INDEX",['db'=>$this->target],'VEHICLE_INDEXES');
        return array_map(static fn(array $r):array=>[(string)$r['INDEX_NAME'],(string)$r['COLUMN_NAME'],(int)$r['SEQ_IN_INDEX'],(int)$r['NON_UNIQUE']],$rows);
    }
    private function assertVehicle005Preconditions(): void
    {
        $indexes=$this->vehicle005Indexes();
        $duplicates=$this->rows('SELECT registration_number, COUNT(*) AS copies FROM vehicles GROUP BY registration_number HAVING COUNT(*)>1 LIMIT 1');
        $old=array_values(array_filter($indexes,static fn(array $i):bool=>$i[0]==='uq_vehicle_registration'));
        Migration005VehicleValidator::assertPreconditions($this->vehicle005Columns(),$old,$duplicates);
    }
    private function assertVehicle005Schema(): void
    {
        Migration005VehicleValidator::assertPostconditions($this->vehicle005Columns(),$this->vehicle005Indexes());
    }

    /** Opt-in read-only inspection; never creates a ledger row or executes DDL. */
    public function vehicle006Preflight(): array
    {
        Migration005VehicleValidator::assertTarget($this->target);
        $plan=MigrationPlan::load(true);$state=$this->status();
        if(count($state['migrations'])!==5)throw new SafetyException('MIGRATION_006_BASELINE_OR_PARTIAL_LEDGER');
        foreach($state['migrations'] as $i=>$row) {
            $entry=$plan[$i];
            if($row['version']!==$entry['version'] || $row['status']!=='applied' || !hash_equals($entry['sha256'],(string)$row['checksum']) || (int)$row['completed_statements']!==count($entry['statements']) || (int)$row['total_statements']!==count($entry['statements']))throw new SafetyException('MIGRATION_006_BASELINE_LEDGER_MISMATCH');
        }
        $booking=$this->rows("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='booking_requests' ORDER BY ORDINAL_POSITION",['db'=>$this->target],'BOOKING_COLUMNS');
        $expectedBooking=$this->vehicle006BookingColumns($plan);
        if($expectedBooking===[] || array_column($booking,'COLUMN_NAME')!==$expectedBooking)throw new SafetyException('MIGRATION_006_BOOKING_METADATA_INCOMPLETE_OR_DRIFT');
        $existingIndex=$this->rows("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='booking_requests' AND INDEX_NAME='idx_booking_requested_vehicle'",['db'=>$this->target]);
        if($existingIndex!==[])throw new SafetyException('MIGRATION_006_INDEX_ALREADY_EXISTS');
        $fk=$this->rows("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=:db AND TABLE_NAME='booking_requests' AND CONSTRAINT_NAME='fk_booking_requested_vehicle'",['db'=>$this->target]);
        $duplicates=$this->rows('SELECT registration_number FROM vehicles GROUP BY registration_number HAVING COUNT(*)>1 LIMIT 1');
        Migration006VehicleValidator::assertPreconditions($this->vehicle005Columns(),$this->vehicle005Indexes(),array_column($booking,'COLUMN_NAME'),array_column($fk,'CONSTRAINT_NAME'),$duplicates);
        return ['status'=>'PASS','target'=>$this->target,'step'=>'MIGRATION_006_PREFLIGHT','checksum'=>MigrationPlan::VEHICLE006['sha256']];
    }

    private function vehicle006BookingColumns(array $plan): array
    {
        foreach($plan[1]['statements'] as $sql) {
            if(str_starts_with($sql,'CREATE TABLE booking_requests (')) {
                preg_match_all('/^    ([a-z_]+) (?:BIGINT|VARCHAR|TEXT|SMALLINT|DATETIME|DATE|INT)\b/m',$sql,$matches);
                return $matches[1];
            }
        }
        throw new SafetyException('MIGRATION_006_BOOKING_SCHEMA_NOT_FOUND');
    }

    /** SELECT/SHOW only. This is the sole explicit partial-state inspection path. */
    public function vehicle006ResumePreflight(): array
    {
        Migration005VehicleValidator::assertTarget($this->target);
        Migration006RestoreIdentity::inspect($this->db);
        ConnectionFactory::assertMigration006ResumeGrants($this->db);
        $plan=MigrationPlan::load(true);
        $ledger=$this->rows('SELECT version,checksum,status,completed_statements,total_statements,failure_code FROM schema_migrations ORDER BY version',[],'RESUME_LEDGER');
        Migration006ResumeValidator::assertLedger($ledger);
        $this->assertAuthSecurity004Schema();
        if($this->vehicle005Columns()!==Migration005VehicleValidator::expectedVehicleColumns()
            || $this->vehicle005Indexes()!==[['uq_vehicle_registration_number','registration_number',1,0]])throw new SafetyException('MIGRATION_006_RESUME_VEHICLE_SCHEMA_REJECTED');
        $province=$this->rows("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='vehicles' AND COLUMN_NAME='registration_province'",['db'=>$this->target]);
        if(count($province)!==1 || array_values($province[0])!==['registration_province','varchar(100)','YES'])throw new SafetyException('MIGRATION_006_RESUME_PROVINCE_REJECTED');
        $parent=$this->rows("SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_KEY FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='vehicles' AND COLUMN_NAME='id'",['db'=>$this->target]);
        if(count($parent)!==1 || array_values($parent[0])!==['bigint unsigned','NO','PRI'])throw new SafetyException('MIGRATION_006_RESUME_PARENT_REJECTED');
        $booking=$this->rows("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='booking_requests' ORDER BY ORDINAL_POSITION",['db'=>$this->target]);
        if(array_column($booking,'COLUMN_NAME')!==$this->vehicle006BookingColumns($plan))throw new SafetyException('MIGRATION_006_RESUME_BOOKING_SCHEMA_REJECTED');
        $newIndex=$this->rows("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='booking_requests' AND INDEX_NAME='idx_booking_requested_vehicle'",['db'=>$this->target]);
        $newFk=$this->rows("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=:db AND CONSTRAINT_NAME='fk_booking_requested_vehicle'",['db'=>$this->target]);
        if($newIndex!==[] || $newFk!==[])throw new SafetyException('MIGRATION_006_RESUME_SECOND_STATEMENT_NOT_ABSENT');
        $existing=$this->rows("SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=:db AND TABLE_NAME='booking_requests' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME",['db'=>$this->target]);
        $expected=[];
        foreach($plan[1]['statements'] as $sql)if(str_starts_with($sql,'CREATE TABLE booking_requests (')) {
            preg_match_all('/CONSTRAINT (fk_[a-z_]+) FOREIGN KEY \(([a-z_]+)\) REFERENCES ([a-z_]+)\(([a-z_]+)\)/',$sql,$matches,PREG_SET_ORDER);
            foreach($matches as $m)$expected[]=[$m[1],$m[2],$this->target,$m[3],$m[4]];
        }
        usort($expected,static fn(array $a,array $b):int=>strcmp($a[0],$b[0]));
        if($expected===[] || array_map('array_values',$existing)!==$expected)throw new SafetyException('MIGRATION_006_RESUME_EXISTING_FK_DRIFT');
        $engines=$this->rows("SELECT TABLE_NAME,ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME IN ('vehicles','booking_requests') ORDER BY TABLE_NAME",['db'=>$this->target]);
        if(array_map('array_values',$engines)!==[['booking_requests','InnoDB'],['vehicles','InnoDB']])throw new SafetyException('MIGRATION_006_RESUME_ENGINE_REJECTED');
        $objects=array_column($this->rows('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=:db',['db'=>$this->target]),'TABLE_NAME');
        $expectedObjects=['schema_migrations'];foreach($plan as $entry)$expectedObjects=array_merge($expectedObjects,$entry['objects']);sort($objects);sort($expectedObjects);
        if($objects!==$expectedObjects)throw new SafetyException('MIGRATION_006_RESUME_OBJECT_DRIFT');
        $schema=$this->rows('SELECT DEFAULT_CHARACTER_SET_NAME,DEFAULT_COLLATION_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=:db',['db'=>$this->target]);
        if(count($schema)!==1 || array_values($schema[0])!==['utf8mb4','utf8mb4_unicode_ci'])throw new SafetyException('MIGRATION_006_RESUME_COLLATION_REJECTED');
        $hash=$this->vehicle006ResumeData();
        return ['status'=>'PASS','step'=>'RESUME_STATEMENT_2_READY','statement_number'=>2,'vehicle_count'=>2,'vehicle_old_fields_sha256'=>$hash];
    }

    private function vehicle006ResumeData(): string
    {
        // Same static query/fetch mode as the recorded pre-apply fingerprint.
        $rows=$this->db->query('SELECT id,registration_number,label,passenger_capacity,is_active,created_by,updated_by,created_at,updated_at FROM vehicles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $counts=$this->rows('SELECT (SELECT COUNT(id) FROM booking_requests) AS bookings,(SELECT COUNT(id) FROM vehicle_assignments) AS assignments');
        return Migration006ResumeValidator::assertVehicleBaseline($rows,$counts[0]??[]);
    }

    public function resumeVehicle006(string $backup,string $partialBackup,string $partialHash): array
    {
        Migration005VehicleValidator::assertTarget($this->target);
        Migration006RestoreIdentity::inspect($this->db);
        Migration006ResumeValidator::assertBackups($backup,$partialBackup,$partialHash);
        ConnectionFactory::requireMigrationApproval($this->target);
        $this->vehicle006ResumePreflight();
        $lock=$this->target.':migrations';
        if((int)$this->rows('SELECT GET_LOCK(:name, 0) AS acquired',['name'=>$lock])[0]['acquired']!==1)throw new SafetyException('MIGRATION_006_RESUME_LOCK_BUSY');
        try {
            $this->vehicle006ResumePreflight();
            $entry=MigrationPlan::load(true)[5];
            return $this->executeVehicle006Resume($entry,function():void{
                $this->assertVehicle006Schema();
                $fk=$this->rows("SELECT REFERENCED_TABLE_SCHEMA FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=:db AND TABLE_NAME='booking_requests' AND CONSTRAINT_NAME='fk_booking_requested_vehicle'",['db'=>$this->target]);
                if(count($fk)!==1 || $fk[0]['REFERENCED_TABLE_SCHEMA']!==$this->target)throw new SafetyException('MIGRATION_006_RESUME_FK_SCHEMA_REJECTED');
                $this->vehicle006ResumeData();
            });
        } finally {try{$this->rows('SELECT RELEASE_LOCK(:name)',['name'=>$lock]);}catch(Throwable){}}
    }

    /** Private execution seam; the public entry point always performs locked guards. */
    private function executeVehicle006Resume(array $entry,\Closure $verifyPostconditions): array
    {
        $stage='DDL';
        try {
            Migration006RestoreIdentity::inspect($this->db);
            // Deliberately no loop: statement zero (province) cannot run here.
            $this->db->exec($entry['statements'][1]);
            $stage='POSTCONDITIONS';$verifyPostconditions();
            $stage='LEDGER_APPLIED';Migration006RestoreIdentity::inspect($this->db);
            $q=$this->db->prepare("UPDATE schema_migrations SET completed_statements=2,status='applied',finished_at=CURRENT_TIMESTAMP(6),failure_code=NULL WHERE version=:version AND checksum=:checksum AND status='failed' AND completed_statements=1 AND total_statements=2 AND failure_code='statement_failed'");
            $q->execute(['version'=>$entry['version'],'checksum'=>$entry['sha256']]);
            if($q->rowCount()!==1)throw new SafetyException('MIGRATION_006_RESUME_LEDGER_RACE');
            $row=$this->rows('SELECT version,checksum,status,completed_statements,total_statements FROM schema_migrations WHERE version=:version',['version'=>$entry['version']]);
            if(count($row)!==1 || $row[0]['status']!=='applied' || (int)$row[0]['completed_statements']!==2 || (int)$row[0]['total_statements']!==2 || $row[0]['checksum']!==$entry['sha256'])throw new SafetyException('MIGRATION_006_RESUME_LEDGER_POSTCONDITION');
            return ['status'=>'PASS','step'=>'RESUME_COMPLETE','migration'=>$entry['version'],'executed_statements'=>[2],'ledger'=>'applied','completed_statements'=>2,'total_statements'=>2];
        } catch(Throwable $error) {throw MigrationExecutionException::fromFailure($error,2,$stage);}
    }

    private function assertVehicle006Schema(): void
    {
        Migration005VehicleValidator::assertTarget($this->target);
        $province=$this->rows("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='vehicles' AND COLUMN_NAME='registration_province'",['db'=>$this->target]);
        $requested=$this->rows("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='booking_requests' AND COLUMN_NAME='requested_vehicle_id'",['db'=>$this->target]);
        $indexes=$this->rows("SELECT INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='booking_requests' AND INDEX_NAME='idx_booking_requested_vehicle'",['db'=>$this->target]);
        $fk=$this->rows("SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=:db AND TABLE_NAME='booking_requests' AND CONSTRAINT_NAME='fk_booking_requested_vehicle'",['db'=>$this->target]);
        $shape=static fn(array $rows):array=>$rows===[]?[]:array_values($rows[0]);
        Migration006VehicleValidator::assertPostconditions($shape($province),$shape($requested),array_map(static fn(array $r):array=>[$r['INDEX_NAME'],$r['COLUMN_NAME'],(int)$r['SEQ_IN_INDEX'],(int)$r['NON_UNIQUE']],$indexes),array_map('array_values',$fk));
        if($this->vehicle005Indexes()!==[['uq_vehicle_registration_number','registration_number',1,0]])throw new SafetyException('MIGRATION_006_REGISTRATION_UNIQUE_DRIFT');
    }
}
