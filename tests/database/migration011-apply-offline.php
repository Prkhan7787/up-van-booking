<?php
declare(strict_types=1);
namespace App\Database {
    // Fake approval values only; never opens a real process gate.
    function getenv(string $name):string|false{return $GLOBALS['fixture011Env'][$name]??false;}
}
namespace {
require dirname(__DIR__,2).'/bootstrap/cli.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e::class.': '.$e->getMessage().' line '.$e->getLine().PHP_EOL);exit(1);});
use App\Database\{MigrationPlan,MigrationRunner,MigrationTarget,DriverDatabaseIdentity,Migration011ApplyGuard,Migration011Validator,Migration011LedgerReconciliation,Migration011ReconciliationBackup,MigrationExecutionException};

function fixture011Metadata(bool $after):array{
    $m=['tables'=>[],'columns'=>[],'indexes'=>[],'foreign_keys'=>[],'checks'=>[],'triggers'=>[]];
    foreach(['users','schema_migrations'] as $t)$m['tables'][]=['TABLE_NAME'=>$t,'TABLE_TYPE'=>'BASE TABLE','ENGINE'=>'InnoDB','TABLE_COLLATION'=>'utf8mb4_unicode_ci'];
    foreach(['id','auth_version'] as $name)$m['columns'][]=['TABLE_NAME'=>'users','COLUMN_NAME'=>$name,'COLUMN_TYPE'=>'bigint unsigned','IS_NULLABLE'=>'NO'];
    foreach(['users'=>'id','schema_migrations'=>'version'] as $table=>$col)$m['indexes'][]=['TABLE_NAME'=>$table,'INDEX_NAME'=>'PRIMARY','COLUMN_NAME'=>$col,'SEQ_IN_INDEX'=>1,'NON_UNIQUE'=>0,'SUB_PART'=>null,'INDEX_TYPE'=>'BTREE'];
    if(!$after)return $m;
    $t='microsoft_identities';$m['tables'][]=['TABLE_NAME'=>$t,'TABLE_TYPE'=>'BASE TABLE','ENGINE'=>'InnoDB','TABLE_COLLATION'=>'utf8mb4_unicode_ci'];
    foreach(['user_id'=>'bigint unsigned','tenant_id'=>'char(36)','object_id'=>'char(36)','created_by'=>'bigint unsigned','created_at'=>'timestamp'] as $name=>$type){
        $text=str_starts_with($type,'char');
        $m['columns'][]=['TABLE_NAME'=>$t,'COLUMN_NAME'=>$name,'COLUMN_TYPE'=>$type,'IS_NULLABLE'=>'NO',
            'COLUMN_DEFAULT'=>$name==='created_at'?'CURRENT_TIMESTAMP':null,'EXTRA'=>$name==='created_at'?'DEFAULT_GENERATED':'',
            'CHARACTER_SET_NAME'=>$text?'ascii':null,'COLLATION_NAME'=>$text?'ascii_bin':null];
    }
    foreach([['PRIMARY','user_id',1,0],['idx_microsoft_identity_creator','created_by',1,1],['uq_microsoft_identity','tenant_id',1,0],['uq_microsoft_identity','object_id',2,0]] as [$key,$col,$seq,$non]){
        $m['indexes'][]=['TABLE_NAME'=>$t,'INDEX_NAME'=>$key,'COLUMN_NAME'=>$col,'SEQ_IN_INDEX'=>$seq,'NON_UNIQUE'=>$non,'SUB_PART'=>null,'INDEX_TYPE'=>'BTREE'];
    }
    foreach(['fk_microsoft_identity_creator'=>'created_by','fk_microsoft_identity_user'=>'user_id'] as $key=>$col)$m['foreign_keys'][]=[
        'CONSTRAINT_NAME'=>$key,'COLUMN_NAME'=>$col,'REFERENCED_TABLE_SCHEMA'=>MigrationTarget::TEST,'REFERENCED_TABLE_NAME'=>'users','REFERENCED_COLUMN_NAME'=>'id','DELETE_RULE'=>'RESTRICT','UPDATE_RULE'=>'NO ACTION'];
    $pattern="'^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$','c'";$zero="'00000000-0000-0000-0000-000000000000'";
    foreach(['chk_microsoft_tenant_uuid'=>"regexp_like(tenant_id,$pattern)",'chk_microsoft_object_uuid'=>"regexp_like(object_id,$pattern)",
        'chk_microsoft_nonzero_uuid'=>"((tenant_id<>$zero) and (object_id<>$zero))"] as $key=>$expr)$m['checks'][]=['CONSTRAINT_NAME'=>$key,'CHECK_CLAUSE'=>$expr,'ENFORCED'=>'YES'];
    return $m;
}
if(in_array('--diagnostic-post-fixture',$argv,true)){
    $packet=fixture011Metadata(true);$positions=[];
    foreach($packet['columns'] as &$column){$table=$column['TABLE_NAME'];$positions[$table]=($positions[$table]??0)+1;$column['ORDINAL_POSITION']=$positions[$table];}unset($column);
    $packet['ledger']=[];
    foreach(MigrationPlan::microsoft011()['dependencies'] as $entry)$packet['ledger'][]=['version'=>$entry['version'],'checksum'=>$entry['sha256'],'status'=>'applied','completed_statements'=>count($entry['statements']),'total_statements'=>count($entry['statements'])];
    $own=MigrationPlan::MICROSOFT011;
    $packet['ledger'][]=['version'=>$own['version'],'checksum'=>$own['sha256'],'status'=>'failed','completed_statements'=>1,'total_statements'=>1];
    $packet['account']=[['account_locked'=>'Y']];$packet['sessions']=[];
    $inventory=['approval_decisions','audit_logs','auth_login_buckets','booking_notifications','booking_passengers','booking_requests',
        'booking_reviews','booking_status_history','drivers','microsoft_identities','roles','schema_migrations','user_roles','users','vehicle_assignments','vehicles'];
    $packet['inventory']=array_map(static fn(string $name):array=>['TABLE_NAME'=>$name,'TABLE_TYPE'=>'BASE TABLE'], $inventory);
    $packet['inventory'][]=['TABLE_NAME'=>'calendar_events','TABLE_TYPE'=>'VIEW'];
    $packet['show_create_table']=MigrationPlan::microsoft011()['migration']['statements'][0];
    echo json_encode($packet,JSON_THROW_ON_ERROR);exit(0);
}
final class Statement011 extends PDOStatement{
    private array $rows=[];private int $count=0;
    public function __construct(private Closure $handler){}
    public function execute(?array $params=null):bool{$r=($this->handler)($params??[]);if(is_array($r))$this->rows=$r;else$this->count=$r;return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $mode===PDO::FETCH_COLUMN?array_map(static fn($r)=>array_values($r)[0],$this->rows):$this->rows;}
    public function fetchColumn(int $column=0):mixed{return array_values($this->rows[0]??[])[$column]??false;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->rows[0]??false;}
    public function rowCount():int{return $this->count;}
}
final class Pdo011 extends PDO{
    public array $ledger=[];public array $log=[];public array $grants=[];public array $before;public array $after;
    public string $uuid=DriverDatabaseIdentity::UUID;public string $account=Migration011ApplyGuard::ACCOUNT.'@127.0.0.1';public int $port=3306;
    public string $version='8.4.9';public bool $acquire=true;public bool $release=true;public bool $held=true;public bool $created=false;
    public bool $transaction=false;public array $ledgerSnapshot=[];public string $accountLocked='Y';public int $activeSessions=0;
    public function beginTransaction():bool{$this->transaction=true;$this->ledgerSnapshot=$this->ledger;return true;}
    public function commit():bool{$this->transaction=false;$this->ledgerSnapshot=[];return true;}
    public function rollBack():bool{$this->ledger=$this->ledgerSnapshot;$this->transaction=false;$this->ledgerSnapshot=[];return true;}
    public ?string $failure=null;public string $roles='NONE';public string $mandatory='';
    public function __construct(){
        foreach(MigrationPlan::microsoft011()['dependencies'] as $e)$this->ledger[]=['version'=>$e['version'],'checksum'=>$e['sha256'],'status'=>'applied','completed_statements'=>count($e['statements']),'total_statements'=>count($e['statements'])];
        $this->before=fixture011Metadata(false);$this->after=fixture011Metadata(true);
        foreach(Migration011ApplyGuard::rules()['tables'] as $table=>$privileges)foreach($privileges as $privilege=>$columns){
            $this->grants[]='GRANT '.$privilege.($columns===null?'':' (`'.implode('`,`',$columns).'`)').' ON `up_van_booking_test`.`'.$table.'` TO \'up_van_migration011_apply\'@\'127.0.0.1\'';
        }
    }
    public function inTransaction():bool{return $this->transaction;}
    public function query(string $sql,?int $fetchMode=null,mixed ...$args):PDOStatement|false{$q=$this->prepare($sql);$q->execute();return $q;}
    public function prepare(string $sql,array $options=[]):PDOStatement|false{return new Statement011(fn($p)=>$this->run($sql,$p));}
    private function run(string $sql,array $p):array|int{
        if(str_contains($sql,'@@port AS port'))return [['port'=>$this->port,'uuid'=>$this->uuid,'database_name'=>MigrationTarget::TEST,'account_name'=>$this->account,'version'=>$this->version]];
        if($sql==='SELECT VERSION()')return [[$this->version]];
        if(str_starts_with($sql,'SHOW GRANTS'))return array_map(static fn($g)=>[$g],$this->grants);
        if($sql==='SELECT CURRENT_ROLE()')return [[$this->roles]];
        if($sql==='SELECT @@GLOBAL.mandatory_roles')return [[$this->mandatory]];
        if($sql==='SELECT @@GLOBAL.partial_revokes')return [[0]];
        if(str_contains($sql,'@@SESSION.autocommit'))return [['autocommit'=>1,'foreign_key_checks'=>1]];
        if(str_contains($sql,'GET_LOCK')){$this->log[]='acquire';return [['acquired'=>$this->acquire?1:0]];}
        if(str_contains($sql,'IS_USED_LOCK'))return [['held'=>$this->held?1:0]];
        if(str_contains($sql,'RELEASE_LOCK')){$this->log[]='release';return [['released'=>$this->release?1:0]];}
        if(str_contains($sql,'FROM schema_migrations WHERE version=:version FOR UPDATE'))return [end($this->ledger)];
        if(str_starts_with($sql,'SELECT version, checksum')||str_starts_with($sql,'SELECT version,checksum'))return $this->ledger;
        if(str_contains($sql,'FROM mysql.user'))return [['account_locked'=>$this->accountLocked]];
        if(str_contains($sql,'FROM information_schema.PROCESSLIST'))return [[$this->activeSessions]];
        if(str_contains($sql,'SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES')){
            $names=['approval_decisions','audit_logs','auth_login_buckets','booking_notifications','booking_passengers','booking_requests','booking_reviews','booking_status_history','drivers','microsoft_identities','roles','schema_migrations','user_roles','users','vehicle_assignments','vehicles'];
            return array_map(static fn($name)=>['TABLE_NAME'=>$name,'TABLE_TYPE'=>$name==='calendar_events'?'VIEW':'BASE TABLE'],[...$names,'calendar_events']);
        }
        $metadata=$this->created?$this->after:$this->before;
        foreach(['TABLES'=>'tables','COLUMNS'=>'columns','STATISTICS'=>'indexes','KEY_COLUMN_USAGE'=>'foreign_keys','TABLE_CONSTRAINTS'=>'checks','TRIGGERS'=>'triggers'] as $token=>$key)if(str_contains($sql,'information_schema.'.$token))return $metadata[$key];
        if(str_starts_with($sql,'INSERT INTO schema_migrations')){
            $this->log[]='started';if($this->failure==='started')throw new PDOException('synthetic private failure');
            $this->ledger[]=['version'=>$p['version'],'checksum'=>$p['checksum'],'status'=>'started','completed_statements'=>0,'total_statements'=>1];return 1;
        }
        if(str_starts_with($sql,'UPDATE schema_migrations')){
            $i=array_key_last($this->ledger);
            if(str_contains($sql,'completed_statements=1 WHERE')){
                $this->log[]='progress';if($this->failure==='progress')throw new PDOException('synthetic private failure');$this->ledger[$i]['completed_statements']=1;
            }elseif(str_contains($sql,"failure_code IN ('statement_failed','postcondition_failed')")){
                $this->log[]='reconcile';if($this->failure==='reconcile_update')return 0;
                $this->ledger[$i]['status']='applied';$this->ledger[$i]['failure_code']=null;
            }elseif(str_contains($sql,"status='applied'")){
                $this->log[]='applied';if($this->failure==='applied')throw new PDOException('synthetic private failure');$this->ledger[$i]['status']='applied';
            }else{$this->log[]='failed';$this->ledger[$i]['status']='failed';$this->ledger[$i]['failure_code']=$p['failure_code']??null;}
            return 1;
        }
        throw new LogicException('unhandled fake query');
    }
    public function exec(string $sql):int|false{
        if($sql!==MigrationPlan::microsoft011()['migration']['statements'][0])throw new LogicException('only pinned 011 may execute');
        $this->log[]='DDL';$this->created=true;
        if($this->failure==='DDL')throw new PDOException('synthetic private failure');
        return 0;
    }
}
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;};
$reject=static function(callable $fn,?string $code=null)use($check):Throwable{
    $caught=null;try{$fn();}catch(Throwable $e){$caught=$e;}
    $check($caught!==null&&($code===null||$caught->getMessage()===$code),'expected rejection '.($code??'').' got '.($caught?->getMessage()??'none'));return $caught;
};
$dir=sys_get_temp_dir().'/upvan-011-offline-'.bin2hex(random_bytes(8));mkdir($dir);
$files=['up_van_booking_test_base_tables.sql'=>'synthetic base tables','calendar_events_view_restore.sql'=>'synthetic calendar definition'];
$manifest=['format'=>'upvan-pre010-logical-backup-v1','host'=>'127.0.0.1','port'=>3306,'database'=>MigrationTarget::TEST,'uuid'=>DriverDatabaseIdentity::UUID,'version'=>'8.4.9',
    'ledger'=>'001-009 applied; 010 absent','calendar_view'=>'calendar_events','calendar_dependencies'=>['booking_requests','vehicle_assignments'],'calendar_security'=>'INVOKER',
    'started_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'finished_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()-30),
    'tables'=>['approval_decisions','audit_logs','auth_login_buckets','booking_notifications','booking_passengers','booking_requests','booking_reviews','booking_status_history','drivers','roles','schema_migrations','user_roles','users','vehicle_assignments','vehicles']];
foreach($files as $name=>$value){file_put_contents($dir.'/'.$name,$value);$key=str_starts_with($name,'up_van')?'base_tables':'calendar_view';$manifest[$key.'_sha256']=hash('sha256',$value);$manifest[$key.'_bytes']=strlen($value);}
$save=static function(array $m)use($dir):string{$json=json_encode($m,JSON_THROW_ON_ERROR);file_put_contents($dir.'/backup-manifest.json',$json);return hash('sha256',$json);};
$pin=$save($manifest);
$apply=static fn(Pdo011 $db,?string $hash=null)=>(new MigrationRunner($db,MigrationTarget::TEST))->microsoft011Apply($dir,$hash??$pin);
try{
    $reject(fn()=>App\Database\ConnectionFactory::config(Migration011ApplyGuard::PURPOSE),'MIGRATION_011_APPLY_PROCESS_CONFIG_REQUIRED');
    $profile=['HOST'=>'127.0.0.1','PORT'=>'3306','DATABASE'=>MigrationTarget::TEST,'USERNAME'=>Migration011ApplyGuard::ACCOUNT,'PASSWORD'=>'offline-placeholder-only'];
    foreach($profile as $name=>$value)$GLOBALS['fixture011Env']['MIGRATION_011_APPLY_DB_'.$name]=$value;
    $check(App\Database\ConnectionFactory::config(Migration011ApplyGuard::PURPOSE)['username']===Migration011ApplyGuard::ACCOUNT,'dedicated process-only account profile');
    foreach(['HOST'=>'localhost','PORT'=>'3307','DATABASE'=>MigrationTarget::PRODUCTION,'USERNAME'=>'root'] as $name=>$bad){
        $GLOBALS['fixture011Env']['MIGRATION_011_APPLY_DB_'.$name]=$bad;
        $reject(fn()=>App\Database\ConnectionFactory::config(Migration011ApplyGuard::PURPOSE),'DRIVER_DATABASE_CONFIG_REJECTED');
        $GLOBALS['fixture011Env']['MIGRATION_011_APPLY_DB_'.$name]=$profile[$name];
    }
    $draft=App\Database\SqlStatements::split(file_get_contents(dirname(__DIR__,2).'/database/proposals/011_migration_apply_grants.proposed.sql'));
    $check(count($draft)===4 && str_contains($draft[0],'ACCOUNT LOCK'),'DBA provisioning proposal starts dedicated account locked');
    $check(!preg_match('/\b(?:ALTER|DROP|INDEX|WITH GRANT OPTION)\b/i',implode(' ',array_slice($draft,1))),'apply grants exclude unrelated schema, row and delegation permissions');
    $db=new Pdo011();$reject(fn()=>$apply($db),'MIGRATION_011_APPROVAL_REQUIRED');$check($db->log===[],'no query before approval');
    $db=new Pdo011();$reject(fn()=>(new MigrationRunner($db,MigrationTarget::PRODUCTION))->microsoft011Apply($dir,$pin),'MIGRATION_011_TEST_ONLY');$check($db->log===[],'production target cannot acquire or write');
    $GLOBALS['fixture011Env']=['TEST_DB_EXECUTION_APPROVED'=>'true'];$reject(fn()=>$apply(new Pdo011()),'MIGRATION_011_APPROVAL_REQUIRED');
    $GLOBALS['fixture011Env']['TEST_MIGRATION_011_APPLY_APPROVED']='true';
    foreach(['uuid'=>'wrong','account'=>'root@localhost','version'=>'8.4.8','port'=>3307] as $field=>$value){$db=new Pdo011();$db->$field=$value;$reject(fn()=>$apply($db));$check($db->log===[],'wrong identity never acquires or writes');}
    $db=new Pdo011();$db->acquire=false;$reject(fn()=>$apply($db),'MIGRATION_011_LOCK_UNAVAILABLE');$check($db->log===['acquire'],'cannot release another session lock');
    $db=new Pdo011();array_pop($db->grants);$reject(fn()=>$apply($db),'MIGRATION_011_GRANTS_REJECTED');$check($db->log===['acquire','release'],'missing grants release without writes');
    $db=new Pdo011();$db->grants[]="GRANT SELECT ON *.* TO 'up_van_migration011_apply'@'127.0.0.1'";$reject(fn()=>$apply($db),'MIGRATION_011_GRANTS_REJECTED');
    $db=new Pdo011();$db->grants[]="GRANT 'extra'@'localhost' TO 'up_van_migration011_apply'@'127.0.0.1'";$reject(fn()=>$apply($db),'MIGRATION_011_GRANTS_REJECTED');
    $db=new Pdo011();$db->roles="'extra'@'localhost'";$reject(fn()=>$apply($db),'MIGRATION_011_GRANTS_REJECTED');
    $db=new Pdo011();$db->mandatory='extra';$reject(fn()=>$apply($db),'MIGRATION_011_GRANTS_REJECTED');
    $db=new Pdo011();$reject(fn()=>$apply($db,str_repeat('0',64)));$check($db->log===['acquire','release'],'backup hash failure precedes writes');
    $stale=$manifest;$stale['started_utc']=gmdate('Y-m-d\TH:i:s\Z',time()-90000);$reject(fn()=>$apply(new Pdo011(),$save($stale)),'MIGRATION_011_BACKUP_STALE_OR_STATE_REJECTED');$save($manifest);
    $future=$manifest;$future['finished_utc']=gmdate('Y-m-d\TH:i:s\Z',time()+60);$reject(fn()=>$apply(new Pdo011(),$save($future)),'MIGRATION_011_BACKUP_STALE_OR_STATE_REJECTED');$save($manifest);
    $missingTime=$manifest;unset($missingTime['started_utc']);$reject(fn()=>$apply(new Pdo011(),$save($missingTime)),'MIGRATION_011_BACKUP_TIMESTAMP_REQUIRED');$save($manifest);
    file_put_contents($dir.'/calendar_events_view_restore.sql','corrupted fixture');$reject(fn()=>$apply(new Pdo011()),'MIGRATION_010_BACKUP_FILE_HASH_REJECTED');file_put_contents($dir.'/calendar_events_view_restore.sql',$files['calendar_events_view_restore.sql']);
    $db=new Pdo011();$db->ledger[0]['checksum']=str_repeat('0',64);$reject(fn()=>$apply($db),'MIGRATION_011_DEPENDENCY_CHECKSUM_OR_STATE');$check($db->log===['acquire','release'],'ledger failure before DDL');
    foreach(['started','failed','applied'] as $status){$db=new Pdo011();$db->ledger[]=['version'=>MigrationPlan::MICROSOFT011['version'],'status'=>$status];$reject(fn()=>$apply($db),'MIGRATION_011_DEPENDENCY_SET_REJECTED');}
    $db=new Pdo011();$db->ledger[]=['version'=>'010_driver_auth','status'=>'started'];$reject(fn()=>$apply($db),'MIGRATION_011_DEPENDENCY_SET_REJECTED');
    $db=new Pdo011();$db->before=$db->after;$reject(fn()=>$apply($db),'MIGRATION_011_EXISTING_OR_PARTIAL_TABLE');
    $db=new Pdo011();$db->before['columns'][0]['COLUMN_TYPE']='int unsigned';$reject(fn()=>$apply($db),'MIGRATION_011_PARENT_SCHEMA_DRIFT');
    $db=new Pdo011();array_pop($db->before['columns']);$reject(fn()=>$apply($db),'MIGRATION_011_PARENT_METADATA_INCOMPLETE');
    $db=new Pdo011();$db->held=false;$reject(fn()=>$apply($db),'MIGRATION_011_LOCK_LOST');$check(!in_array('started',$db->log,true),'lost lock prevents ledger/DDL');
    foreach(['started','DDL','progress','applied'] as $failure){
        $db=new Pdo011();$db->failure=$failure;$error=$reject(fn()=>$apply($db),'MIGRATION_011_EXECUTION_FAILED');$safe=$error->diagnostics();
        $check($safe['automatic_retry']===false&&!str_contains(json_encode($safe),'synthetic private'),'sanitized failure without retry');
        $check(end($db->log)==='release'&&count(array_filter($db->log,static fn($s)=>$s==='DDL'))<=1,'failure releases lock and never retries DDL');
        if($failure==='DDL')$check($db->created&&$safe['confirmed_completed_statements']===0&&$safe['partial_apply_possible'],'DDL outcome uncertain despite zero acknowledgements');
        if($failure==='DDL')$check(end($db->ledger)['failure_code']==='statement_failed','DDL failure is classified as statement failure');
        if(in_array($failure,['progress','applied'],true))$check(end($db->ledger)['failure_code']==='ledger_failed','ledger write failure is distinguished from SQL statement failure');
    }
    foreach(['indexes','foreign_keys','checks'] as $part){$db=new Pdo011();array_pop($db->after[$part]);$e=$reject(fn()=>$apply($db),'MIGRATION_011_EXECUTION_FAILED');$check($e->diagnostics()['execution_stage']==='POSTCONDITIONS'&&end($db->ledger)['status']==='failed','postcondition failure cannot mark applied');$check(end($db->ledger)['failure_code']==='postcondition_failed','postcondition failure is not mislabeled as SQL statement failure');}
    $db=new Pdo011();
    $db->after['checks']=[
        ['CONSTRAINT_NAME'=>'chk_microsoft_tenant_uuid','ENFORCED'=>'YES','CHECK_CLAUSE'=>"regexp_like(`tenant_id`,_utf8mb4\\'^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$\\',_utf8mb4\\'c\\')"],
        ['CONSTRAINT_NAME'=>'chk_microsoft_object_uuid','ENFORCED'=>'YES','CHECK_CLAUSE'=>"regexp_like(`object_id`,_utf8mb4\\'^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$\\',_utf8mb4\\'c\\')"],
        ['CONSTRAINT_NAME'=>'chk_microsoft_nonzero_uuid','ENFORCED'=>'YES','CHECK_CLAUSE'=>"((`tenant_id` <> _utf8mb4\\'00000000-0000-0000-0000-000000000000\\') and (`object_id` <> _utf8mb4\\'00000000-0000-0000-0000-000000000000\\'))"],
    ];
    $r=$apply($db);$check($r['status']==='PASS','MySQL 8.4.9 JSON metadata escaped quote form matches pinned CHECK constraints');
    $db=new Pdo011();$db->after['checks'][0]['CHECK_CLAUSE']="regexp_like(`tenant_id`,_utf8mb4\\'^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$\\',_utf8mb4\\'i\\')";
    $e=$reject(fn()=>$apply($db),'MIGRATION_011_EXECUTION_FAILED');$check($e->diagnostics()['execution_stage']==='POSTCONDITIONS','escaped but semantically weaker CHECK remains rejected');
    $drifts=[
        static function(array &$m):void{$m['columns'][3]['COLLATION_NAME']='ascii_general_ci';},
        static function(array &$m):void{$m['foreign_keys'][0]['DELETE_RULE']='CASCADE';},
        static function(array &$m):void{$m['checks'][0]['ENFORCED']='NO';},
        static function(array &$m):void{$m['checks'][0]['CHECK_CLAUSE']='1';},
        static function(array &$m):void{$m['tables'][2]['ENGINE']='MyISAM';},
        static function(array &$m):void{$m['indexes'][4]['NON_UNIQUE']=1;},
    ];
    foreach($drifts as $mutate){$db=new Pdo011();$mutate($db->after);$e=$reject(fn()=>$apply($db),'MIGRATION_011_EXECUTION_FAILED');$check($e->diagnostics()['execution_stage']==='POSTCONDITIONS'&&!$e->diagnostics()['ledger_applied_acknowledged'],'postflight detects definition drift before marking applied');}
    $db=new Pdo011();$db->release=false;$e=$reject(fn()=>$apply($db),'MIGRATION_011_EXECUTION_FAILED');$check($e->diagnostics()['ledger_applied_acknowledged']&&$e->diagnostics()['migration_lock_released']===false,'successful DDL with failed release does not report PASS');
    $db=new Pdo011();$r=$apply($db);$check($r['status']==='PASS'&&$r['ledger']==='applied 1/1'&&$r['010']==='absent_pending','011 succeeds with 010 absent');
    $check(count($db->ledger)===10&&end($db->ledger)['version']===MigrationPlan::MICROSOFT011['version'],'ledger records actual 001-009 plus 011 only');
    $check($db->log===['acquire','started','DDL','progress','applied','release'],'lock, execution, postflight and ledger sequence');
    $reject(fn()=>$apply($db),'MIGRATION_011_DEPENDENCY_SET_REJECTED');

    $reconcileDir=$dir.'/reconciliation';mkdir($reconcileDir);
    $reconcileFiles=['up_van_booking_test_base_tables.sql'=>'offline full 16-table recovery dump','calendar_events_view_restore.sql'=>'offline calendar view'];
    foreach($reconcileFiles as $name=>$bytes)file_put_contents($reconcileDir.'/'.$name,$bytes);
    $reconcileManifest=$manifest;$reconcileManifest['tables']=[...$manifest['tables'],'microsoft_identities'];
    sort($reconcileManifest['tables']);$reconcileManifest['ledger']=Migration011ReconciliationBackup::LEDGER_STATE;
    foreach($reconcileFiles as $name=>$bytes){$key=str_starts_with($name,'up_van')?'base_tables':'calendar_view';$reconcileManifest[$key.'_sha256']=hash('sha256',$bytes);$reconcileManifest[$key.'_bytes']=strlen($bytes);}
    $reconcileManifest['started_utc']=gmdate('Y-m-d\\TH:i:s\\Z',time()-90);$reconcileManifest['finished_utc']=gmdate('Y-m-d\\TH:i:s\\Z',time()-60);
    $reconcileManifest['calendar_view']='calendar_events';$reconcileManifest['calendar_dependencies']=['booking_requests','vehicle_assignments'];$reconcileManifest['calendar_security']='INVOKER';
    $reconcileManifestPath=$reconcileDir.'/backup-manifest.json';file_put_contents($reconcileManifestPath,json_encode($reconcileManifest,JSON_THROW_ON_ERROR));$reconcilePin=hash_file('sha256',$reconcileManifestPath);
    $backupResult=Migration011ReconciliationBackup::verify($reconcileDir,$reconcilePin);
    $check($backupResult['base_table_count']===16&&$backupResult['ledger_state']===Migration011ReconciliationBackup::LEDGER_STATE,'current-state reconciliation backup requires exact 16-table partial-011 ledger snapshot');
    $reject(fn()=>Migration011ReconciliationBackup::verify($reconcileDir,str_repeat('0',64)),'MIGRATION_010_BACKUP_MANIFEST_HASH_REJECTED');
    $beforeReconcile=$reconcileManifest;$reconcileManifest['tables']=array_values(array_filter($reconcileManifest['tables'],static fn($table)=>$table!=='microsoft_identities'));
    file_put_contents($reconcileManifestPath,json_encode($reconcileManifest,JSON_THROW_ON_ERROR));$badPin=hash_file('sha256',$reconcileManifestPath);
    $reject(fn()=>Migration011ReconciliationBackup::verify($reconcileDir,$badPin),'MIGRATION_010_BACKUP_BRANCH_STATE_REJECTED');
    file_put_contents($reconcileManifestPath,json_encode($beforeReconcile,JSON_THROW_ON_ERROR));$reconcilePin=hash_file('sha256',$reconcileManifestPath);
    $marker=['run_id'=>str_repeat('a',32),'phase'=>'RUNNER_EXITED','child_finished'=>true,'automatic_retry'=>false,
        'account'=>Migration011ApplyGuard::ACCOUNT.'@127.0.0.1','target_uuid'=>DriverDatabaseIdentity::UUID,'child_pid'=>8832,'child_started_ticks'=>639311728309442570];
    $GLOBALS['fixture011Env']=['TEST_DB_EXECUTION_APPROVED'=>'true','TEST_MIGRATION_011_LEDGER_RECONCILIATION_APPROVED'=>'true'];
    $newReconcileDb=static function():Pdo011{$db=new Pdo011();$db->account='root@localhost';$db->created=true;$db->ledger[]=['version'=>MigrationPlan::MICROSOFT011['version'],
        'checksum'=>MigrationPlan::MICROSOFT011['sha256'],'status'=>'failed','completed_statements'=>1,'total_statements'=>1,'failure_code'=>'statement_failed'];return $db;};
    $db=$newReconcileDb();$reconciled=Migration011LedgerReconciliation::reconcile($db,$reconcileDir,$reconcilePin,$marker);
    $check($reconciled['status']==='PASS'&&$reconciled['ddl_executed']===false&&$reconciled['ledger']==='applied 1/1','ledger-only reconciliation succeeds under mock DBA transaction');
    $check(end($db->ledger)['status']==='applied'&&end($db->ledger)['checksum']===MigrationPlan::MICROSOFT011['sha256']&&$db->log===['acquire','reconcile','release'],'only exact 011 row changes while advisory lock is held; no DDL');
    $db=$newReconcileDb();$db->acquire=false;$reject(fn()=>Migration011LedgerReconciliation::reconcile($db,$reconcileDir,$reconcilePin,$marker),'MIGRATION_011_RECONCILIATION_LOCK_UNAVAILABLE');
    $db=$newReconcileDb();$db->ledger[array_key_last($db->ledger)]['completed_statements']=0;
    $reject(fn()=>Migration011LedgerReconciliation::reconcile($db,$reconcileDir,$reconcilePin,$marker),'MIGRATION_011_RECONCILIATION_LEDGER_STATE_REJECTED');
    $db=$newReconcileDb();$db->ledger[array_key_last($db->ledger)]['checksum']=str_repeat('0',64);
    $reject(fn()=>Migration011LedgerReconciliation::reconcile($db,$reconcileDir,$reconcilePin,$marker),'MIGRATION_011_RECONCILIATION_LEDGER_STATE_REJECTED');
    $db=$newReconcileDb();$db->after['checks'][0]['CHECK_CLAUSE']='1';
    $reject(fn()=>Migration011LedgerReconciliation::reconcile($db,$reconcileDir,$reconcilePin,$marker),'MIGRATION_011_POST_CHECK_REJECTED');
    $check(end($db->ledger)['status']==='failed'&&$db->transaction===false,'schema drift rolls ledger update back atomically');
    $db=$newReconcileDb();$db->ledger[]=['version'=>MigrationPlan::DRIVER010['version'],'checksum'=>MigrationPlan::DRIVER010['sha256'],'status'=>'failed','completed_statements'=>1,'total_statements'=>3];
    $reject(fn()=>Migration011LedgerReconciliation::reconcile($db,$reconcileDir,$reconcilePin,$marker),'MIGRATION_011_RECONCILIATION_LEDGER_STATE_REJECTED');
    $db=$newReconcileDb();$db->activeSessions=1;
    $reject(fn()=>Migration011LedgerReconciliation::reconcile($db,$reconcileDir,$reconcilePin,$marker),'MIGRATION_011_RECONCILIATION_ACCOUNT_OR_SESSION_REJECTED');
    $db=$newReconcileDb();$db->accountLocked='N';
    $reject(fn()=>Migration011LedgerReconciliation::reconcile($db,$reconcileDir,$reconcilePin,$marker),'MIGRATION_011_RECONCILIATION_ACCOUNT_OR_SESSION_REJECTED');
    $db=$newReconcileDb();$badMarker=$marker;$badMarker['phase']='RUNNER_STARTED';
    $reject(fn()=>Migration011LedgerReconciliation::reconcile($db,$reconcileDir,$reconcilePin,$badMarker),'MIGRATION_011_RECONCILIATION_MARKER_REJECTED');
    $db=$newReconcileDb();$db->failure='reconcile_update';
    $reject(fn()=>Migration011LedgerReconciliation::reconcile($db,$reconcileDir,$reconcilePin,$marker),'MIGRATION_011_RECONCILIATION_UPDATE_UNCONFIRMED');
    $db=$newReconcileDb();$db->release=false;$reject(fn()=>Migration011LedgerReconciliation::reconcile($db,$reconcileDir,$reconcilePin,$marker),'MIGRATION_011_RECONCILIATION_LOCK_RELEASE_UNVERIFIED');
    $db=$newReconcileDb();unset($GLOBALS['fixture011Env']['TEST_MIGRATION_011_LEDGER_RECONCILIATION_APPROVED']);
    $reject(fn()=>Migration011LedgerReconciliation::reconcile($db,$reconcileDir,$reconcilePin,$marker),'MIGRATION_011_RECONCILIATION_APPROVAL_REQUIRED');
    echo 'PASS: '.$checks.' Migration 011 apply offline checks; fake PDO only'.PHP_EOL;
}finally{
    if(isset($reconcileDir)&&is_dir($reconcileDir)){foreach(glob($reconcileDir.'/*')?:[] as $file)unlink($file);rmdir($reconcileDir);}
    foreach(array_keys($files) as $file)unlink($dir.'/'.$file);unlink($dir.'/backup-manifest.json');rmdir($dir);
}
}
