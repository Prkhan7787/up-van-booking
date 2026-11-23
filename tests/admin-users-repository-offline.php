<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
use App\Database\AuthRuntimeGrantPlan;
use App\Database\AdminUserGrantPlan;
use App\Database\GrantVerifier;
use App\Repositories\PdoAdminUserRepository;

$checks=0;
function repoCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException('Admin repository assertion failed: '.$label);$checks++;echo 'PASS: '.$label,PHP_EOL;}
final class AdminStatementFake extends PDOStatement
{
    public array $bound=[];public ?array $params=null;
    public int $affectedRows=1;
    public function __construct(public string $sql,private array $rows=[],private mixed $scalar=1){}
    public function execute(?array $params=null): bool {$this->params=$params;return true;}
    public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR): bool {$this->bound[$param]=[$value,$type];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {return $this->rows[0]??false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {return $this->rows;}
    public function fetchColumn(int $column=0): mixed {return $this->scalar;}
    public function rowCount(): int {return $this->affectedRows;}
}
final class AdminPdoFake extends PDO
{
    public array $statements=[];public bool $transactionActive=false;public int $commits=0;public int $rollbacks=0;
    public bool $missingRoleReference=false;
    public function __construct(public array $grants,public string $database='up_van_booking_test',public string $account='up_van_auth_test@127.0.0.1'){}
    public function getAttribute(int $attribute): mixed {return match($attribute){PDO::ATTR_DRIVER_NAME=>'mysql',PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,default=>null};}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {
        if(str_starts_with($query,'SELECT DATABASE()'))return new AdminStatementFake($query,[['db_name'=>$this->database,'account_name'=>$this->account,'partial_revokes'=>0]]);
        if($query==='SELECT CURRENT_USER()')return new AdminStatementFake($query,[],$this->account);
        if($query==='SELECT @@GLOBAL.partial_revokes')return new AdminStatementFake($query,[],0);
        return new AdminStatementFake($query,str_starts_with($query,'SHOW')?$this->grants:[],'NONE');
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false {$stmt=new AdminStatementFake($query);if($this->missingRoleReference && str_starts_with($query,'INSERT INTO user_roles'))$stmt->affectedRows=0;$this->statements[]=$stmt;return $stmt;}
    public function inTransaction(): bool {return $this->transactionActive;}
    public function beginTransaction(): bool {$this->transactionActive=true;return true;}
    public function commit(): bool {$this->transactionActive=false;$this->commits++;return true;}
    public function rollBack(): bool {$this->transactionActive=false;$this->rollbacks++;return true;}
    public function lastInsertId(?string $name=null): string|false {return '99';}
}
$base=AuthRuntimeGrantPlan::grantSql(false);$extended=array_merge($base,AdminUserGrantPlan::supplementalSql());
App\Database\ConnectionFactory::assertScopedGrants(new AdminPdoFake($base),'up_van_booking_test');
repoCheck(true,'Existing authentication grants still accepted by ConnectionFactory');
App\Database\ConnectionFactory::assertScopedGrants(new AdminPdoFake($extended),'up_van_booking_test');
repoCheck(true,'Only reviewed complete Admin extension is accepted by ConnectionFactory');
$evaluation=GrantVerifier::evaluate($extended,'up_van_booking_test',AdminUserGrantPlan::rules(),false,true);
repoCheck($evaluation['status']==='PASS','Reviewed mixed column grants and table DELETE satisfy Admin SQL requirements');
$missing=GrantVerifier::evaluate($base,'up_van_booking_test',AdminUserGrantPlan::rules(),false,true);
repoCheck($missing['status']==='FAIL' && $missing['missing_privileges']!==[],'Current authentication-only grants cannot enable Admin actions');
$withoutVersion=array_map(static fn(string $s):string=>str_replace('UPDATE (`is_active`, `auth_version`)','UPDATE (`is_active`)',$s),$extended);
$versionMissing=GrantVerifier::evaluate($withoutVersion,'up_van_booking_test',AdminUserGrantPlan::rules(),false,true);
repoCheck(in_array('column.users.auth_version:UPDATE',$versionMissing['missing_privileges'],true),'Session-revocation UPDATE privilege is mandatory');
$withoutDelete=array_map(static fn(string $s):string=>str_replace(', DELETE ON',' ON',$s),$extended);
$deleteMissing=GrantVerifier::evaluate($withoutDelete,'up_van_booking_test',AdminUserGrantPlan::rules(),false,true);
repoCheck(in_array('table.user_roles:DELETE',$deleteMissing['missing_privileges'],true),'Role replacement requires DELETE limited to user_roles');
$withoutAudit=array_values(array_filter($extended,static fn(string $s):bool=>!str_contains($s,'`audit_logs`')));
$auditMissing=GrantVerifier::evaluate($withoutAudit,'up_van_booking_test',AdminUserGrantPlan::rules(),false,true);
repoCheck($auditMissing['status']==='FAIL' && in_array('column.audit_logs.metadata:INSERT',$auditMissing['missing_privileges'],true),'Audit INSERT is required before Admin actions are enabled');
$extra=GrantVerifier::evaluate(array_merge($extended,["GRANT UPDATE ON `up_van_booking_test`.`users` TO 'up_van_auth_test'@'127.0.0.1'"]),'up_van_booking_test',AdminUserGrantPlan::rules(),false,true);
repoCheck($extra['status']==='FAIL','Table-wide users UPDATE remains rejected');
$global=GrantVerifier::evaluate(array_merge($extended,["GRANT SELECT ON *.* TO 'up_van_auth_test'@'127.0.0.1'"]),'up_van_booking_test',AdminUserGrantPlan::rules(),false,true);
repoCheck($global['status']==='FAIL','Global privileges remain rejected');
$unverified=GrantVerifier::evaluate($extended,'up_van_booking_test',AdminUserGrantPlan::rules(),false,false);
repoCheck($unverified['status']==='UNVERIFIED','Unresolved roles cannot pass Admin verification');
foreach([[new AdminPdoFake($base),'Baseline grants'],[new AdminPdoFake($extended,'wrong_database'),'Wrong database'],[new AdminPdoFake($extended,'up_van_booking_test','wrong_account@127.0.0.1'),'Wrong account']] as [$db,$label]) {
    $denied=false;try{new PdoAdminUserRepository($db);}catch(App\Core\AdminUsersUnavailableException){$denied=true;}
    repoCheck($denied && $db->statements===[],$label.' rejected before account repository SQL');
}
$db=new AdminPdoFake($extended);$repo=new PdoAdminUserRepository($db);
$repo->listUsers("100%_! ' OR 1=1",'admin',2);$stmt=$db->statements[0];
repoCheck(!str_contains($stmt->sql,'OR 1=1') && $stmt->bound[':username'][0]==="%100!%!_!! ' OR 1=1%",'Search is bound and LIKE wildcard characters escaped');
repoCheck($stmt->bound[':limit']===[51,PDO::PARAM_INT] && $stmt->bound[':offset']===[50,PDO::PARAM_INT],'Pagination uses integer native bindings');
repoCheck($stmt->bound[':role']===['admin',PDO::PARAM_STR] && str_contains($stmt->sql,'EXISTS'),'Role filter is bound and preserves multi-role account listing');
$repo->transaction(function()use($repo):void{$repo->lockUsers([20,10,10]);$id=$repo->createUser('dummy','dummy@example.test','Dummy','dummy-hash');$repo->setRoles($id,['requester']);$repo->bumpAuthVersion($id);$repo->audit(10,'admin.user.created',$id,['before'=>['roles'=>[],'active'=>false],'after'=>['roles'=>['requester'],'active'=>true]]);});
repoCheck($db->commits===1 && $db->rollbacks===0,'Mutation operations and audit share one transaction');
$auditInsert=array_values(array_filter($db->statements,static fn(AdminStatementFake $statement):bool=>str_starts_with($statement->sql,'INSERT INTO audit_logs')));
$auditSchema=file_get_contents(dirname(__DIR__).'/database/migrations/001_identity.sql');
repoCheck(count($auditInsert)===1 && !str_contains($auditInsert[0]->sql,'created_at') && str_contains($auditSchema,'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'),'Audit actor/action/target/metadata are transactional and timestamp comes from schema default');
$locks=array_values(array_filter($db->statements,static fn(AdminStatementFake $s):bool=>str_contains($s->sql,'FOR UPDATE')));
repoCheck(count($locks)===1 && $locks[0]->params===[10,20],'Actor and target rows locked in deterministic order');
$named=array_values(array_filter($db->statements,static fn(AdminStatementFake $s):bool=>str_contains($s->sql,'GET_LOCK')));
repoCheck($named[0]->params['name']==='up_van_booking_test:first_admin_provision','Role/status mutations serialize with existing initial-admin CLI');
try{$repo->transaction(static function():void{throw new RuntimeException('synthetic deadlock or audit failure');});}catch(RuntimeException){}
repoCheck($db->rollbacks===1 && !$db->transactionActive,'Failures roll back without blind retry');
$releases=array_filter($db->statements,static fn(AdminStatementFake $s):bool=>str_contains($s->sql,'RELEASE_LOCK'));
repoCheck(count($releases)===2,'Advisory lock released after success and failure');
$deletes=array_values(array_filter($db->statements,static fn(AdminStatementFake $s):bool=>str_starts_with($s->sql,'DELETE')));
repoCheck(count($deletes)===1 && $deletes[0]->sql==='DELETE FROM user_roles WHERE user_id=:id' && $deletes[0]->params===['id'=>99],'DELETE cannot target other tables or omit the bound target user');
$db->missingRoleReference=true;$roleFailure=false;
try{$repo->transaction(fn()=>$repo->setRoles(20,['requester']));}catch(RuntimeException){$roleFailure=true;}
repoCheck($roleFailure && $db->rollbacks===2 && $db->commits===1,'Missing role reference rolls back after DELETE without committing role removal');
foreach($db->statements as $s){repoCheck(!preg_match('/\b(?:DROP|ALTER|TRUNCATE|GRANT|REVOKE)\b/i',$s->sql),'Repository SQL excludes schema/account administration');}
$repo->setPasswordHash(20,'offline-hash-only');
$passwordSql=$db->statements[array_key_last($db->statements)];
repoCheck($passwordSql->sql==='UPDATE users SET password_hash=:hash WHERE id=:id' && $passwordSql->params===['hash'=>'offline-hash-only','id'=>20],'Password reset binds hash and target ID to approved column UPDATE');
echo 'PASS: ',$checks,' Admin repository/grant offline checks; PDO fakes only.',PHP_EOL;
