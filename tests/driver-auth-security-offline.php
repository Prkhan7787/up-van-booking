<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\DriverDatabaseIdentity;
use App\Database\Migration010DriverValidator;
use App\Database\MigrationPlan;
use App\Database\SafetyException;
use App\Repositories\PdoDriverAccountLinkRepository;
use App\Repositories\PdoDriverRegistrationRepository;
use App\Repositories\PdoLoginRateLimitStore;
use App\Services\DriverRegistrationService;
use App\Services\PasswordHasher;
use App\Services\LoginRateLimiter;

$checks = 0;
function securityCheck(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
    $checks++; echo 'PASS: '.$label, PHP_EOL;
}
function rejected(callable $operation): bool { try { $operation(); return false; } catch (Throwable) { return true; } }

final class DriverSecurityStatement extends PDOStatement
{
    private array $rows = [];
    private mixed $scalar = false;
    public function __construct(private DriverSecurityPdo $db, private string $sql) {}
    public function execute(?array $params = null): bool {
        [$this->rows, $this->scalar] = $this->db->run($this->sql, $params ?? []); return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed { return $this->rows[0] ?? false; }
    public function fetchColumn(int $column=0): mixed { return $this->scalar; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array { return $this->rows; }
    public function rowCount(): int { return 1; }
}
final class DriverSecurityPdo extends PDO
{
    public array $events = [], $buckets = [], $users = [], $identity;
    public array $actor = ['is_active'=>1,'auth_version'=>7];
    public array $actorRoles = ['vehicle_officer'];
    public bool $active = false, $failAudit = false;
    public int $commits = 0, $rollbacks = 0, $audits = 0, $linked = 0;
    private array $snapshot = [];
    public function __construct() { $this->identity=['port'=>3306,'uuid'=>DriverDatabaseIdentity::UUID,'database_name'=>'up_van_booking_test','account_name'=>'up_van_auth_test@127.0.0.1']; }
    public function getAttribute(int $attribute): mixed { return match($attribute) { PDO::ATTR_DRIVER_NAME=>'mysql',PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,default=>null }; }
    public function prepare(string $query,array $options=[]): PDOStatement|false { return new DriverSecurityStatement($this,$query); }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false { $s=$this->prepare($query); $s->execute(); return $s; }
    public function beginTransaction(): bool { if($this->active)throw new RuntimeException('nested'); $this->events[]='BEGIN'; $this->active=true; $this->snapshot=[$this->buckets,$this->users,$this->linked,$this->audits]; return true; }
    public function inTransaction(): bool { return $this->active; }
    public function commit(): bool { $this->events[]='COMMIT'; $this->active=false; $this->commits++; return true; }
    public function rollBack(): bool { [$this->buckets,$this->users,$this->linked,$this->audits]=$this->snapshot; $this->events[]='ROLLBACK'; $this->active=false; $this->rollbacks++; return true; }
    public function lastInsertId(?string $name=null): string|false { return (string)count($this->users); }
    public function run(string $sql,array $p): array {
        $this->events[]=[$sql,$p];
        if(str_starts_with($sql,'SELECT @@port'))return [[$this->identity],false];
        if(str_contains($sql,'GET_LOCK') || str_contains($sql,'RELEASE_LOCK'))return [[],1];
        if(str_starts_with($sql,'INSERT IGNORE INTO auth_login_buckets')) {
            $key=$p['scope'].bin2hex($p['digest']); $this->buckets[$key]??=['window_started_at'=>$p['started'],'attempt_count'=>0]; return [[],false];
        }
        if(str_starts_with($sql,'SELECT window_started_at'))return [[$this->buckets[$p['scope'].bin2hex($p['digest'])]],false];
        if(str_starts_with($sql,'UPDATE auth_login_buckets')) { $this->buckets[$p['scope'].bin2hex($p['digest'])]=['window_started_at'=>$p['started'],'attempt_count'=>$p['attempts']]; return [[],false]; }
        if(str_starts_with($sql,'INSERT INTO users')) {
            if(isset($this->users[$p['username']])) { $e=new PDOException('synthetic duplicate'); $e->errorInfo=['23000',1062,'synthetic']; throw $e; }
            $this->users[$p['username']]=$p; return [[],false];
        }
        if(str_starts_with($sql,'INSERT INTO user_roles'))return [[],false];
        if(str_starts_with($sql,'SELECT id FROM users WHERE id IN'))return [array_map(static fn($id)=>['id'=>$id],$p),false];
        if(str_starts_with($sql,'SELECT is_active, auth_version'))return [[$this->actor],false];
        if(str_starts_with($sql,'SELECT id, is_active'))return [[['id'=>20,'is_active'=>1]],false];
        if(str_starts_with($sql,'SELECT r.name'))return [$p['id']===10 ? $this->actorRoles : ['driver'],false];
        if(str_starts_with($sql,'SELECT id FROM drivers'))return [[],3];
        if(str_starts_with($sql,'UPDATE drivers')) { $this->linked++; return [[],false]; }
        if(str_starts_with($sql,'INSERT INTO audit_logs')) { if($this->failAudit)throw new RuntimeException('synthetic audit failure'); $this->audits++; return [[],false]; }
        throw new RuntimeException('Unexpected fake SQL');
    }
}

// Exercise the actual PDO adapter, not a replacement limiter with different counting semantics.
$db=new DriverSecurityPdo(); $limiter=new LoginRateLimiter(new PdoLoginRateLimitStore($db,true),str_repeat('x',40),sourceLimit:2,namespace:'driver-registration');
$calls=0;
$callback=function()use(&$calls):bool{$calls++;return true;};
securityCheck($limiter->attempt('0811111111','127.0.0.1',$callback,100)->authenticated,'first successful registration is counted');
securityCheck($limiter->attempt('0822222222','127.0.0.1',$callback,100)->authenticated,'second successful registration uses the same source bucket');
securityCheck(!$limiter->attempt('0833333333','127.0.0.1',$callback,100)->allowed && $calls===2,'third different phone is blocked before account creation');
securityCheck($limiter->attempt('0833333333','127.0.0.1',$callback,1000)->allowed,'registration window resets at its boundary');
$legacy=new LoginRateLimiter(new PdoLoginRateLimitStore($db),str_repeat('x',40),sourceLimit:1);
securityCheck($legacy->attempt('legacy','127.0.0.1',$callback,1000)->allowed && $legacy->attempt('legacy','127.0.0.1',$callback,1000)->allowed,'successful Login remains uncounted and registration buckets are separate');
$bad=new DriverSecurityPdo(); $badLimiter=new LoginRateLimiter(new PdoLoginRateLimitStore($bad,true),str_repeat('x',40),sourceLimit:1);
$badLimiter->attempt('invalid','127.0.0.1',static fn()=>false,100);
securityCheck(!$badLimiter->attempt('another','127.0.0.1',$callback,100)->allowed,'failed registration consumes source quota');
$db=new DriverSecurityPdo(); $service=new DriverRegistrationService(new PdoDriverRegistrationRepository($db),new PdoLoginRateLimitStore($db,true),str_repeat('x',40),new PasswordHasher());
securityCheck(rejected(fn()=>$service->register([], '127.0.0.1')) && $db->commits===1 && count($db->buckets)===2,'invalid registration input is counted before validation error returns');
$input=['real_name'=>'Fixture','nickname'=>'Fixture','phone'=>'0812345678','password'=>'fixture-password','password_confirmation'=>'fixture-password'];
securityCheck($service->register($input,'127.0.0.1') && !$service->register($input,'127.0.0.1') && count($db->users)===1,'duplicate phone leaves exactly one account and is counted');
$db->failAudit=true; $input['phone']='0822345678';
securityCheck(rejected(fn()=>$service->register($input,'127.0.0.1')) && count($db->users)===1 && $db->rollbacks===1,'audit failure rolls back registration account, role and rate state together');

foreach (['suspended','version_changed','role_revoked','driver_self'] as $scenario) {
    $db=new DriverSecurityPdo();
    if($scenario==='suspended')$db->actor['is_active']=0;
    if($scenario==='version_changed')$db->actor['auth_version']=8;
    if($scenario==='role_revoked')$db->actorRoles=['requester'];
    if($scenario==='driver_self')$db->actorRoles=['driver'];
    securityCheck(rejected(fn()=>(new PdoDriverAccountLinkRepository($db))->link(10,3,20,7)) && $db->rollbacks===1 && $db->linked===0 && $db->audits===0,'live authorization change denied and rolled back: '.$scenario);
    $events=json_encode($db->events);
    securityCheck(str_contains($events,'RELEASE_LOCK') && !str_contains($events,'UPDATE drivers'),'lock released without linking after '.$scenario);
}
$db=new DriverSecurityPdo(); (new PdoDriverAccountLinkRepository($db))->link(10,3,20,7);
securityCheck($db->commits===1 && $db->linked===1 && $db->audits===1,'authorized staff link and audit commit together');
$sqlEvents=array_values(array_filter($db->events,'is_array'));
securityCheck($sqlEvents[1][1]===[10,20] && str_contains($sqlEvents[1][0],'ORDER BY id FOR UPDATE'),'Admin-compatible sorted user locks precede live authorization reads');
$roleReads=array_values(array_filter($sqlEvents,static fn($event)=>str_starts_with($event[0],'SELECT r.name')));
securityCheck(count($roleReads)===2 && str_ends_with($roleReads[0][0],'FOR SHARE') && str_ends_with($roleReads[1][0],'FOR SHARE'),'actor and target role reads retain shared row locks until the transaction ends');
$db=new DriverSecurityPdo(); $db->failAudit=true;
securityCheck(rejected(fn()=>(new PdoDriverAccountLinkRepository($db))->link(10,3,20,7)) && $db->linked===0 && $db->rollbacks===1,'link is rolled back on audit failure');

$config=['host'=>'127.0.0.1','port'=>'3306','database'=>'up_van_booking_test','username'=>'up_van_auth_test'];
DriverDatabaseIdentity::assertConfig($config); DriverDatabaseIdentity::inspect(new DriverSecurityPdo());
securityCheck(true,'exact Driver database config and server identity accepted');
foreach(['host'=>'localhost','port'=>'3307','database'=>'other','username'=>'root'] as $key=>$value) {
    securityCheck(rejected(fn()=>DriverDatabaseIdentity::assertConfig(array_replace($config,[$key=>$value]))),'Driver configuration rejects '.$key.' mismatch');
}
foreach(['port'=>3307,'uuid'=>'different','database_name'=>'other','account_name'=>'root@localhost'] as $key=>$value) {
    $db=new DriverSecurityPdo();$db->identity[$key]=$value;
    securityCheck(rejected(fn()=>DriverDatabaseIdentity::inspect($db)),'live identity rejects '.$key.' mismatch before DML');
}

$plan=MigrationPlan::driver010LedgerEntries();
$ledger=array_map(static fn($m)=>['version'=>$m['version'],'checksum'=>$m['sha256'],'status'=>'applied','completed_statements'=>$m['statement_count'],'total_statements'=>$m['statement_count']],$plan);
Migration010DriverValidator::assertLedger(array_slice($ledger,0,9),false);
Migration010DriverValidator::assertLedger($ledger,true);
$sql=file_get_contents(dirname(__DIR__).'/'.MigrationPlan::DRIVER010['file']);
$statements=App\Database\SqlStatements::split($sql);
foreach($statements as $statement)MigrationPlan::assertStatementAllowed('010_driver_auth','forward_test',$statement);
securityCheck(count($plan)===10 && count($statements)===3 && hash_equals(MigrationPlan::DRIVER010['sha256'],hash('sha256',$sql)),'010 checksum, exact SQL allowlist and pinned ledger follow 001-009');
foreach(['status'=>'started','checksum'=>str_repeat('0',64),'completed_statements'=>1] as $key=>$value) {
    $broken=$ledger;$broken[9][$key]=$value;
    securityCheck(rejected(fn()=>Migration010DriverValidator::assertLedger($broken,true)),'partial/dirty 010 ledger rejected: '.$key);
}
securityCheck(rejected(fn()=>Migration010DriverValidator::assertLedger(array_slice($ledger,1,8),false)),'missing baseline ledger cannot pass 010 preflight');
securityCheck(rejected(fn()=>MigrationPlan::assertStatementAllowed('010_driver_auth','forward_test','ALTER TABLE users DROP COLUMN email')),'010 cannot execute SQL outside pinned allowlist');

function schema010(bool $post): array {
    $m=['columns'=>[],'engines'=>['users'=>'InnoDB','drivers'=>'InnoDB'],'indexes'=>[],'link_foreign_keys'=>[],'roles'=>[]];
    $users=['id','email','username','display_name','password_hash','is_active','auth_version','created_at','updated_at'];
    $drivers=['id','staff_code','display_name','contact_phone','is_active','created_by','updated_by','created_at','updated_at'];
    if($post){array_splice($users,4,0,['nickname']);array_splice($drivers,4,0,['user_id']);}
    foreach(['users'=>$users,'drivers'=>$drivers] as $table=>$names)foreach($names as $name){
        $type=match($name){'id','user_id','auth_version'=>'bigint unsigned','email'=>'varchar(254)','username'=>'varchar(50)','nickname'=>'varchar(80)',default=>'varchar(150)'};
        $nullable=in_array($name,['username','nickname','user_id'],true)||($name==='email'&&$post)?'YES':'NO';
        $m['columns'][$table][]=['COLUMN_NAME'=>$name,'COLUMN_TYPE'=>$type,'IS_NULLABLE'=>$nullable];
    }
    foreach([['users','PRIMARY','id'],['users','email','email'],['users','uq_users_username','username'],['drivers','PRIMARY','id'],...($post?[['drivers','uq_drivers_user_id','user_id']]:[])] as [$table,$index,$column])$m['indexes'][]=['TABLE_NAME'=>$table,'INDEX_NAME'=>$index,'COLUMN_NAME'=>$column,'NON_UNIQUE'=>0,'SEQ_IN_INDEX'=>1];
    foreach(['admin','approver','requester','vehicle_officer',...($post?['driver']:[])] as $role)$m['roles'][]=['name'=>$role,'description'=>$role==='driver'?'พนักงานขับรถ':'Existing role'];
    if($post)$m['link_foreign_keys'][]=['CONSTRAINT_NAME'=>'fk_drivers_user','COLUMN_NAME'=>'user_id','REFERENCED_TABLE_SCHEMA'=>'up_van_booking_test','REFERENCED_TABLE_NAME'=>'users','REFERENCED_COLUMN_NAME'=>'id','DELETE_RULE'=>'RESTRICT','UPDATE_RULE'=>'NO ACTION'];
    return $m;
}
Migration010DriverValidator::assertSchema(schema010(false),false); Migration010DriverValidator::assertSchema(schema010(true),true);
securityCheck(true,'expected 010 schema precondition and postcondition accepted');
foreach(['partial','missing_unique','wrong_fk','nullable_email','extra_role'] as $case){
    $m=schema010(true);
    if($case==='partial')array_pop($m['roles']);
    if($case==='missing_unique')array_pop($m['indexes']);
    if($case==='wrong_fk')$m['link_foreign_keys'][0]['DELETE_RULE']='CASCADE';
    if($case==='nullable_email')$m['columns']['users'][1]['IS_NULLABLE']='NO';
    if($case==='extra_role')$m['roles'][]=['name'=>'unexpected','description'=>'Fixture'];
    securityCheck(rejected(fn()=>Migration010DriverValidator::assertSchema($m,true)),'010 schema drift rejected: '.$case);
}
securityCheck(rejected(fn()=>Migration010DriverValidator::assertSchema(schema010(true),false)) && !Migration010DriverValidator::partialState(schema010(true))['automatic_retry_allowed'],'partially/already applied schema cannot pass a new preflight or enable auto retry');
echo "PASS: Driver security regression completed ($checks checks); PDO fakes only, no database.\n";
