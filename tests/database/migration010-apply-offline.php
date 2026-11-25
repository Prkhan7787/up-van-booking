<?php
declare(strict_types=1);

namespace App\Database {
    // This isolated test process simulates process settings without changing any real gates.
    function getenv(string $name): string|false { return $GLOBALS['migration010FixtureEnvironment'][$name] ?? false; }
}

namespace {
    require dirname(__DIR__,2).'/bootstrap/cli.php';
    require __DIR__.'/fixtures/migration010-schema.php';
    set_exception_handler(static function (Throwable $error): void {
        fwrite(STDERR, $error::class.': '.$error->getMessage().' (line '.$error->getLine().')'.PHP_EOL);
        exit(1);
    });

    use App\Database\{ConnectionFactory,DriverDatabaseIdentity,Migration010ApplyGuard,Migration010SchemaSnapshot,
        MigrationExecutionException,MigrationPlan,MigrationRunner,MigrationTarget,SafetyException,SqlStatements};

    $checks = 0;
    $provisioning = SqlStatements::split(file_get_contents(dirname(__DIR__,2).'/database/proposals/010_migration_apply_grants.proposed.sql'));
    $create = array_shift($provisioning);
    $account = "'up_van_migration010_apply'@'127.0.0.1'";
    apply010Check(preg_replace('/\s+/', ' ', $create)==="CREATE USER $account IDENTIFIED BY RANDOM PASSWORD ACCOUNT LOCK",
        'DBA draft creates only the dedicated account and starts locked');
    apply010Check(count($provisioning)===7 && array_all($provisioning, static fn(string $sql):bool =>
        str_starts_with($sql,'GRANT ') && str_ends_with($sql," TO $account")
        && !preg_match('/\b(?:INDEX|WITH GRANT OPTION|WITH ADMIN OPTION)\b/i',$sql)),
        'DBA draft grants only scoped privileges with no INDEX, role or delegation');

    final class Apply010Statement extends PDOStatement
    {
        private array $rows = [];
        private int $affected = 0;
        public function __construct(private Closure $action) {}
        public function execute(?array $params=null): bool
        {
            $result=($this->action)($params ?? []);
            if (is_array($result)) $this->rows=$result;
            else $this->affected=$result;
            return true;
        }
        public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args): array
        { return $mode===PDO::FETCH_COLUMN ? array_map(static fn($row)=>array_values($row)[$args[0]??0],$this->rows) : $this->rows; }
        public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed
        { return $this->rows[0] ?? false; }
        public function fetchColumn(int $column=0): mixed { return array_values($this->rows[0] ?? [])[$column] ?? false; }
        public function rowCount(): int { return $this->affected; }
    }

    final class Apply010Pdo extends PDO
    {
        public array $log=[];
        public array $identity=['port'=>3306,'uuid'=>DriverDatabaseIdentity::UUID,'database_name'=>MigrationTarget::TEST,
            'account_name'=>Migration010ApplyGuard::ACCOUNT.'@127.0.0.1'];
        public string $version='8.4.9';
        public bool $canLock=true;
        public bool $locked=false;
        public bool $canRelease=true;
        public bool $base009=true;
        public bool $postconditionFails=false;
        public bool $preconditionFails=false;
        public bool $duplicateRole=false;
        public bool $baselineChecksumMismatch=false;
        public bool $failAfterEffect=false;
        public ?int $failStatement=null;
        public ?int $failProgress=null;
        public string $currentRole='NONE';
        public string $mandatoryRoles='';
        public ?array $ledger010=null;
        public bool $ledger011Applied=false;
        public string $ledger011Status='applied';
        public string $ledger011Checksum=MigrationPlan::MICROSOFT011['sha256'];
        public array $extraGrants=[];
        public array $grantSubstitutions=[];
        public array $executed=[];
        private array $ledger;
        private array $statements;
        private array $schemas;
        private int $applied=0;

        public function __construct()
        {
            static $fixtures=null;
            if ($fixtures===null) {
                $before=snapshotSql();
                $postUsers=str_replace(
                    ['`email` varchar(254) NOT NULL', '`display_name` varchar(150) NOT NULL,' . "\n  `password_hash`"],
                    ['`email` varchar(254) DEFAULT NULL', '`display_name` varchar(150) NOT NULL,' . "\n  `nickname` varchar(80) DEFAULT NULL,\n  `password_hash`"],
                    $before
                );
                $postDrivers=str_replace(
                    ['`contact_phone` varchar(30) DEFAULT NULL,','CONSTRAINT `fk_driver_creator`'],
                    ["`contact_phone` varchar(30) DEFAULT NULL,\n  `user_id` bigint unsigned DEFAULT NULL,",
                        "UNIQUE KEY `uq_drivers_user_id` (`user_id`),\n  CONSTRAINT `fk_drivers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,\n  CONSTRAINT `fk_driver_creator`"],
                    $postUsers
                );
                $drift=str_replace('`email` varchar(254) NOT NULL','`email` varchar(240) NOT NULL',$before);
                $fixtures=array_map(static fn($sql)=>new Migration010SchemaSnapshot($sql), [$before,$postUsers,$postDrivers,$drift]);
            }
            $this->schemas=$fixtures;
            $this->ledger=array_map(static fn($row)=>['version'=>$row['version'],'checksum'=>$row['sha256'],
                'status'=>'applied','completed_statements'=>$row['statement_count'],'total_statements'=>$row['statement_count']],
                array_slice(MigrationPlan::driver010LedgerEntries(),0,9));
            $plan=MigrationPlan::load(include010:true);
            $this->statements=end($plan)['statements'];
        }

        private function grants(): array
        {
            global $provisioning;
            $rows=["GRANT USAGE ON *.* TO 'up_van_migration010_apply'@'127.0.0.1'"];
            foreach ($provisioning as $grant) {
                $grant = preg_replace('/\s+/', ' ', $grant);
                $grant = strtr($grant, $this->grantSubstitutions);
                if ($grant!=='') $rows[]=$grant;
            }
            return array_merge($rows,$this->extraGrants);
        }

        public function query(string $sql,?int $fetchMode=null,mixed ...$args): PDOStatement|false
        {
            $result=$this->prepare($sql);
            $result->execute();
            return $result;
        }

        public function prepare(string $sql,array $options=[]): PDOStatement|false
        {
            return new Apply010Statement(fn(array $params)=>$this->run($sql,$params));
        }

        public function inTransaction(): bool { return false; }

        public function exec(string $statement): int|false
        {
            $number=count($this->executed)+1;
            if (!$this->locked || $statement!==$this->statements[$number-1]) throw new LogicException('Unpinned or unlocked execution');
            $this->executed[]=$statement;
            $this->log[]='statement-'.$number;
            if ($this->failStatement===$number && !$this->failAfterEffect) throw self::failure();
            $this->applied=$number;
            if ($this->failStatement===$number) throw self::failure();
            return $number===3?1:0;
        }

        private static function failure(): PDOException
        {
            $error=new PDOException('fixture raw SQL and credentials must not escape');
            $error->errorInfo=['HY000',2013,'fixture raw diagnostic'];
            return $error;
        }

        private function run(string $sql,array $params): array|int
        {
            if (str_contains($sql,'@@server_uuid')) return [$this->identity];
            if ($sql==='SELECT VERSION()') return [[$this->version]];
            if ($sql==='SELECT DATABASE()') return [[MigrationTarget::TEST]];
            if (str_starts_with($sql,'SHOW GRANTS')) return array_map(static fn($row)=>[$row],$this->grants());
            if ($sql==='SELECT CURRENT_ROLE()') return [[$this->currentRole]];
            if ($sql==='SELECT @@GLOBAL.mandatory_roles') return [[$this->mandatoryRoles]];
            if ($sql==='SELECT @@GLOBAL.partial_revokes') return [[0]];
            if (str_contains($sql,'@@SESSION.autocommit')) return [['autocommit'=>1,'foreign_key_checks'=>1]];
            if (str_contains($sql,'GET_LOCK')) {
                $this->log[]='acquire';
                if ($params['name']!==MigrationTarget::TEST.':migrations') throw new LogicException('Wrong lock scope');
                return [['acquired'=>($this->locked=$this->canLock)?1:0]];
            }
            if (str_contains($sql,'IS_USED_LOCK')) return [['held'=>$this->locked?1:0]];
            if (str_contains($sql,'RELEASE_LOCK')) {
                $this->log[]='release';
                if (!$this->canRelease) return [['released'=>null]];
                $this->locked=false;
                return [['released'=>1]];
            }
            if (str_starts_with($sql,'SELECT version, checksum')) {
                $ledger=$this->ledger;
                if ($this->baselineChecksumMismatch) $ledger[0]['checksum']=str_repeat('0',64);
                if ($this->ledger010!==null) $ledger[]=$this->ledger010;
                if ($this->ledger011Applied) $ledger[]=['version'=>MigrationPlan::MICROSOFT011['version'],'checksum'=>$this->ledger011Checksum,
                    'status'=>$this->ledger011Status,'completed_statements'=>1,'total_statements'=>1];
                usort($ledger,static fn(array $a,array $b):int=>strcmp($a['version'],$b['version']));
                return $ledger;
            }
            if (str_contains($sql,'chk_booking_deleted_fields')) {
                $this->log[]=$this->applied===3?'postconditions':'preconditions';
                return [[$this->base009 && !($this->applied===3 && $this->postconditionFails)?1:0]];
            }
            if (str_starts_with($sql,'SELECT name, description')) {
                $roles=['admin','approver','requester','vehicle_officer'];
                if ($this->duplicateRole) $roles[]='requester';
                if ($this->applied===3) $roles[]='driver';
                return array_map(static fn($role)=>['name'=>$role,'description'=>$role==='driver'?'พนักงานขับรถ':'fixture'],$roles);
            }
            if (stripos($sql,'INFORMATION_SCHEMA.')!==false) {
                return $this->schemas[$this->preconditionFails ? 3 : min(2,$this->applied)]->rows($sql,$params);
            }
            if (!$this->locked) throw new LogicException('Ledger write without lock');
            if (str_starts_with($sql,'INSERT INTO schema_migrations')) {
                $this->log[]='started';
                $this->ledger010=['version'=>$params['version'],'checksum'=>$params['checksum'],'status'=>'started',
                    'completed_statements'=>0,'total_statements'=>3];
                return 1;
            }
            if (str_starts_with($sql,'UPDATE schema_migrations SET completed_statements')) {
                $this->log[]='progress-'.$params['count'];
                if ($this->failProgress===$params['count']) throw self::failure();
                if ($this->ledger010['status']!=='started' || $this->ledger010['completed_statements']!==$params['previous']) return 0;
                $this->ledger010['completed_statements']=$params['count'];
                return 1;
            }
            if (str_contains($sql,"SET status='applied'")) {
                $this->log[]='applied';
                if ($this->ledger010['status']!=='started' || $this->ledger010['completed_statements']!==3) return 0;
                $this->ledger010['status']='applied';
                return 1;
            }
            if (str_contains($sql,"SET status='failed'")) {
                $this->log[]='failed';
                if ($this->ledger010['status']!=='started') return 0;
                $this->ledger010['status']='failed';
                return 1;
            }
            throw new LogicException('Unexpected fake PDO query');
        }
    }

    function apply010Check(bool $condition,string $label): void
    {
        global $checks;
        if (!$condition) throw new RuntimeException('FAIL '.$label);
        $checks++;
    }
    function apply010Reject(callable $call,string $expected): SafetyException
    {
        try { $call(); } catch (SafetyException $error) {
            apply010Check($error->getMessage()===$expected, 'expected rejection '.$expected.', got '.$error->getMessage());
            return $error;
        }
        throw new RuntimeException('Expected rejection '.$expected);
    }

    $dir=sys_get_temp_dir().'/upvan-010-apply-fixture-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $files=['up_van_booking_test_base_tables.sql'=>'verified fixture tables', 'calendar_events_view_restore.sql'=>'verified fixture view'];
    $manifest=['format'=>'upvan-pre010-logical-backup-v1','host'=>'127.0.0.1','port'=>3306,'database'=>MigrationTarget::TEST,
        'uuid'=>DriverDatabaseIdentity::UUID,'version'=>'8.4.9','ledger'=>'001-009 applied; 010 absent','calendar_view'=>'calendar_events',
        'started_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()-30),'finished_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()-10),
        'calendar_dependencies'=>['booking_requests','vehicle_assignments'],'calendar_security'=>'INVOKER',
        'tables'=>['approval_decisions','audit_logs','auth_login_buckets','booking_notifications','booking_passengers',
            'booking_requests','booking_reviews','booking_status_history','drivers','roles','schema_migrations','user_roles',
            'users','vehicle_assignments','vehicles']];
    foreach (array_values($files) as $index=>$data) {
        $role=$index===0?'base_tables':'calendar_view';
        $manifest[$role.'_sha256']=hash('sha256',$data);
        $manifest[$role.'_bytes']=strlen($data);
    }
    $files['backup-manifest.json']=json_encode($manifest,JSON_THROW_ON_ERROR);
    $hash=hash('sha256',$files['backup-manifest.json']);
    foreach ($files as $name=>$data) file_put_contents($dir.'/'.$name,$data);
    $environment=&$GLOBALS['migration010FixtureEnvironment'];
    $apply=static fn(Apply010Pdo $pdo,?string $pin=null)=>(new MigrationRunner($pdo,MigrationTarget::TEST))->driver010Apply($dir,$pin??$hash);
    try {
        foreach (["fixture-stdin-password\0"=>true,"fixture-stdin-password"=>false,"fixture-stdin-password\r\n\0"=>false,"short\0"=>false,(str_repeat('x',256)."\0")=>false] as $input=>$valid) {
            $pipe=fopen('php://memory','w+b');
            fwrite($pipe,$input);
            rewind($pipe);
            try {
                if ($valid) apply010Check(Migration010ApplyGuard::readPassword($pipe)==='fixture-stdin-password','valid stdin frame yields the exact credential');
                else apply010Reject(fn()=>Migration010ApplyGuard::readPassword($pipe),'MIGRATION_010_STDIN_CREDENTIAL_REJECTED');
            } finally {fclose($pipe);}
        }
        foreach ([[],['TEST_DB_EXECUTION_APPROVED'=>'true'],['TEST_MIGRATION_010_APPLY_APPROVED'=>'true']] as $gates) {
            $environment=$gates;
            $db=new Apply010Pdo;
            apply010Reject(fn()=>$apply($db),'MIGRATION_010_APPLY_APPROVAL_REQUIRED');
            apply010Check($db->log===[],'gate failure precedes acquisition and writes');
        }
        $environment=['TEST_DB_EXECUTION_APPROVED'=>'true','TEST_MIGRATION_010_APPLY_APPROVED'=>'true'];
        apply010Reject(fn()=>(new MigrationRunner(new Apply010Pdo,MigrationTarget::PRODUCTION))->driver010Apply($dir,$hash),'MIGRATION_010_TEST_ONLY');
        apply010Reject(fn()=>ConnectionFactory::config(Migration010ApplyGuard::PURPOSE),'MIGRATION_010_APPLY_PROCESS_CONFIG_REQUIRED');
        foreach (['HOST'=>'127.0.0.1','PORT'=>'3306','DATABASE'=>MigrationTarget::TEST,
            'USERNAME'=>Migration010ApplyGuard::ACCOUNT,'PASSWORD'=>'offline-fixture'] as $Name=>$value) {
            $environment['MIGRATION_010_APPLY_DB_'.$Name]=$value;
        }
        apply010Check(ConnectionFactory::config(Migration010ApplyGuard::PURPOSE)['port']==='3306','dedicated process profile accepts pinned config');
        $environment['MIGRATION_010_APPLY_DB_HOST']='localhost';
        apply010Reject(fn()=>ConnectionFactory::config(Migration010ApplyGuard::PURPOSE),'DRIVER_DATABASE_CONFIG_REJECTED');
        foreach (['port'=>3307,'uuid'=>'wrong','database_name'=>'wrong','account_name'=>'root@localhost'] as $field=>$value) {
            $db=new Apply010Pdo;
            $db->identity[$field]=$value;
            apply010Reject(fn()=>$apply($db),'DRIVER_DATABASE_IDENTITY_REJECTED');
            apply010Check($db->log===[],'wrong live identity precedes lock and writes');
        }
        $db=new Apply010Pdo;
        $db->version='8.4.8';
        apply010Reject(fn()=>$apply($db),'MIGRATION_010_MYSQL_VERSION_REJECTED');
        $db=new Apply010Pdo;
        $db->canLock=false;
        apply010Reject(fn()=>$apply($db),'MIGRATION_010_APPLY_LOCK_UNAVAILABLE');
        apply010Check($db->log===['acquire'],'no writes and no release of another session lock');
        foreach (["GRANT SELECT ON `other`.`users` TO 'fixture'@'127.0.0.1'",
            "GRANT 'dormant'@'127.0.0.1' TO 'fixture'@'127.0.0.1'",
            "GRANT INDEX ON `up_van_booking_test`.`drivers` TO $account",
            "GRANT DROP ON `up_van_booking_test`.`users` TO 'fixture'@'127.0.0.1'"] as $grant) {
            $db=new Apply010Pdo;
            $db->extraGrants=[$grant];
            apply010Reject(fn()=>$apply($db),'MIGRATION_010_APPLY_GRANTS_REJECTED');
            apply010Check($db->log===['acquire','release'],'excess grant stops before writes and releases lock');
        }
        foreach ([
            ['ALTER, CREATE, INSERT ON `up_van_booking_test`.`drivers`','CREATE, INSERT ON `up_van_booking_test`.`drivers`'],
            ['ALTER, CREATE, INSERT ON `up_van_booking_test`.`drivers`','ALTER, INSERT ON `up_van_booking_test`.`drivers`'],
            ['ALTER, CREATE, INSERT ON `up_van_booking_test`.`drivers`','ALTER, CREATE ON `up_van_booking_test`.`drivers`'],
            ['INSERT, REFERENCES ON `up_van_booking_test`.`users`','INSERT ON `up_van_booking_test`.`users`'],
            ['SELECT (name, description), INSERT (name, description)','SELECT (name, description)'],
            ['UPDATE (status, completed_statements, finished_at, failure_code)','UPDATE (status, completed_statements, finished_at)'],
            ["GRANT REFERENCES ON `up_van_booking_test`.`booking_requests` TO $account",''],
        ] as [$required,$replacement]) {
            $db=new Apply010Pdo;
            $db->grantSubstitutions=[$required=>$replacement];
            apply010Reject(fn()=>$apply($db),'MIGRATION_010_APPLY_GRANTS_REJECTED');
            apply010Check($db->ledger010===null && $db->log===['acquire','release'],
                'missing required privilege fails before writes and releases lock');
        }
        $db=new Apply010Pdo;
        apply010Reject(fn()=>$apply($db,str_repeat('0',64)),'MIGRATION_010_BACKUP_MANIFEST_HASH_REJECTED');
        apply010Check($db->log===['acquire','release'],'manifest rejection releases lock');
        $view='calendar_events_view_restore.sql';
        file_put_contents($dir.'/'.$view,str_repeat('x',strlen($files[$view])));
        apply010Reject(fn()=>$apply(new Apply010Pdo),'MIGRATION_010_BACKUP_FILE_HASH_REJECTED');
        file_put_contents($dir.'/'.$view,$files[$view]);
        $incomplete = $manifest;
        $incomplete['tables']=array_values(array_diff($manifest['tables'],['audit_logs']));
        $incompleteJson=json_encode($incomplete,JSON_THROW_ON_ERROR);
        file_put_contents($dir.'/backup-manifest.json',$incompleteJson);
        apply010Reject(fn()=>$apply(new Apply010Pdo,hash('sha256',$incompleteJson)),'MIGRATION_010_BACKUP_INVENTORY_REJECTED');
        file_put_contents($dir.'/backup-manifest.json',$files['backup-manifest.json']);
        $db=new Apply010Pdo;
        $db->preconditionFails=true;
        apply010Reject(fn()=>$apply($db),'MIGRATION_010_COLUMN_TYPE_OR_NULLABILITY');
        apply010Check($db->ledger010===null && $db->log===['acquire','release'],'schema drift cannot create a ledger row');
        $db=new Apply010Pdo;
        $db->base009=false;
        apply010Reject(fn()=>$apply($db),'MIGRATION_010_BASELINE_SCHEMA_REJECTED');
        apply010Check($db->ledger010===null && $db->executed===[],'ledger alone cannot satisfy 009 baseline');
        $db=new Apply010Pdo;
        $db->duplicateRole=true;
        apply010Reject(fn()=>$apply($db),'MIGRATION_010_ROLES_DRIFT');
        $db=new Apply010Pdo;
        $db->baselineChecksumMismatch=true;
        apply010Reject(fn()=>$apply($db),'MIGRATION_010_LEDGER_DIRTY_OR_CHECKSUM');
        apply010Check($db->log===['acquire','release'],'wrong prior checksum releases lock without writes');
        foreach (['applied','failed','started'] as $status) {
            $db=new Apply010Pdo;
            $db->ledger010=['version'=>'010_driver_auth','status'=>$status];
            apply010Reject(fn()=>$apply($db),'MIGRATION_010_LEDGER_COUNT_OR_PARTIAL');
            apply010Check($db->log===['acquire','release'],'existing 010 row cannot enter apply or retry');
        }
        // Independent branch: an already applied 011 row remains untouched while 010 applies.
        $branchManifest=$manifest;
        $branchManifest['ledger']='001-009 applied; 011 applied; 010 absent';
        $branchManifest['tables'][]='microsoft_identities';
        $branchJson=json_encode($branchManifest,JSON_THROW_ON_ERROR);
        file_put_contents($dir.'/backup-manifest.json',$branchJson);
        $branchPin=hash('sha256',$branchJson);
        $db=new Apply010Pdo;
        $db->ledger011Applied=true;
        $result=$apply($db,$branchPin);
        apply010Check($result['status']==='PASS' && $result['completed_statements']===3
            && $db->ledger011Applied && $db->ledger011Status==='applied',
            '010 applies with exact applied 011 branch while preserving 011 state');
        apply010Check($db->ledger010['status']==='applied'
            && $db->ledger010['completed_statements']===3 && end($db->log)==='release',
            '011 branch keeps separate checksums and both migration ledger rows');
        $db=new Apply010Pdo;
        apply010Reject(fn()=>$apply($db,$branchPin),'MIGRATION_010_BACKUP_LIVE_BRANCH_MISMATCH');
        apply010Check($db->log===['acquire','release'],'backup for a different live branch cannot enter the write path');
        $stale=$branchManifest;
        $stale['started_utc']=gmdate('Y-m-d\TH:i:s\Z',time()-3601);
        $staleJson=json_encode($stale,JSON_THROW_ON_ERROR);
        file_put_contents($dir.'/backup-manifest.json',$staleJson);
        $db=new Apply010Pdo;
        $db->ledger011Applied=true;
        apply010Reject(fn()=>$apply($db,hash('sha256',$staleJson)),'MIGRATION_010_BACKUP_STALE_OR_STATE_REJECTED');
        apply010Check($db->log===['acquire','release'],'stale backup cannot enter the write path');
        file_put_contents($dir.'/backup-manifest.json',$branchJson);
        $db=new Apply010Pdo;
        $db->ledger011Applied=true;$db->ledger011Status='started';
        apply010Reject(fn()=>$apply($db,$branchPin),'MIGRATION_010_LEDGER_DIRTY_OR_CHECKSUM');
        apply010Check($db->ledger010===null && $db->executed===[] && $db->log===['acquire','release'],
            'partial 011 ledger blocks 010 before any write');
        $db=new Apply010Pdo;
        $db->ledger011Applied=true;$db->ledger011Checksum=str_repeat('0',64);
        apply010Reject(fn()=>$apply($db,$branchPin),'MIGRATION_010_LEDGER_DIRTY_OR_CHECKSUM');
        apply010Check($db->ledger010===null && $db->executed===[] && $db->log===['acquire','release'],
            '011 checksum mismatch blocks 010 before any write');
        $branchWithoutTable=$branchManifest;$branchWithoutTable['tables']=array_values(array_diff($branchWithoutTable['tables'],['microsoft_identities']));
        $branchWithoutTableJson=json_encode($branchWithoutTable,JSON_THROW_ON_ERROR);
        file_put_contents($dir.'/backup-manifest.json',$branchWithoutTableJson);
        apply010Reject(fn()=>$apply(new Apply010Pdo,hash('sha256',$branchWithoutTableJson)),'MIGRATION_010_BACKUP_BRANCH_STATE_REJECTED');
        $manifestJson=json_encode($manifest,JSON_THROW_ON_ERROR);
        file_put_contents($dir.'/backup-manifest.json',$manifestJson);
        $hash=hash('sha256',$manifestJson);
        for ($number=1;$number<=3;$number++) {
            $db=new Apply010Pdo;
            $db->failStatement=$number;
            $db->failAfterEffect=$number===2;
            $error=apply010Reject(fn()=>$apply($db),'MIGRATION_010_EXECUTION_FAILED');
            $d=$error->diagnostics();
            apply010Check($d['statement_number']===$number && $d['confirmed_completed_statements']===$number-1
                && $d['confirmed_ledger_completed_statements']===$number-1 && $d['partial_apply_possible']
                && $d['automatic_retry']===false && $d['native_code']===2013 && !$d['ledger_applied_acknowledged']
                && !str_contains(json_encode($d),'fixture raw'),'safe failure and acknowledgements for statement '.$number);
            apply010Check(count($db->executed)===$number && $db->ledger010['status']==='failed'
                && end($db->log)==='release','failure stops before next statement and releases lock');
        }
        $db=new Apply010Pdo;
        $db->failProgress=2;
        $d=apply010Reject(fn()=>$apply($db),'MIGRATION_010_EXECUTION_FAILED')->diagnostics();
        apply010Check($d['confirmed_completed_statements']===2 && $d['confirmed_ledger_completed_statements']===1
            && $d['execution_stage']==='LEDGER_PROGRESS' && $db->ledger010['status']==='failed','partial DDL and lagging ledger reported separately');
        $db=new Apply010Pdo;
        $db->postconditionFails=true;
        $d=apply010Reject(fn()=>$apply($db),'MIGRATION_010_EXECUTION_FAILED')->diagnostics();
        apply010Check($d['execution_stage']==='POSTCONDITIONS' && $d['confirmed_completed_statements']===3
            && $db->ledger010['status']==='failed' && !in_array('applied',$db->log,true),'postconditions failure cannot mark applied');
        $db=new Apply010Pdo;
        $result=$apply($db);
        apply010Check($result['status']==='PASS' && $result['live_schema_verified']===true && $result['completed_statements']===3
            && $db->ledger010['total_statements']===3 && $db->ledger010['status']==='applied',
            'DBA draft grants without INDEX pass the guard and simulated apply records applied 3/3');
        apply010Check($db->log===['acquire','preconditions','started','statement-1','progress-1','statement-2','progress-2',
            'statement-3','progress-3','postconditions','applied','release'],'exact order and lock release on success');
        $db=new Apply010Pdo;
        $db->canRelease=false;
        $d=apply010Reject(fn()=>$apply($db),'MIGRATION_010_EXECUTION_FAILED')->diagnostics();
        apply010Check($d['execution_stage']==='LOCK_RELEASE' && $d['ledger_applied_acknowledged']===true,'unconfirmed lock release is not reported as success');
    } finally {
        foreach (array_keys($files) as $name) unlink($dir.'/'.$name);
        rmdir($dir);
    }
    echo "PASS: $checks Migration 010 apply offline checks; fake PDO only.", PHP_EOL;
}
