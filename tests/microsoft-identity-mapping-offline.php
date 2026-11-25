<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/app.php';

use App\Contracts\{AdminUserRepository,MicrosoftIdentityRepository};
use App\Models\AuthenticatedPrincipal;
use App\Services\MicrosoftIdentityMappingService;
use App\Core\{AdminUserInputException,AuthorizationException};

final class MemoryMicrosoftIdentities implements MicrosoftIdentityRepository
{
    public array $rows=[];public bool $missingTable=false;
    public function forUser(int $id):?array{if($this->missingTable)throw new PDOException('offline missing table');return $this->rows[$id]??null;}
    public function forIdentity(string $tid,string $oid):?array{if($this->missingTable)throw new PDOException('offline missing table');foreach($this->rows as $row)if($row['tenant_id']===$tid&&$row['object_id']===$oid)return $row;return null;}
    public function insert(int $id,string $tid,string $oid,int $actor):void{
        if($this->forIdentity($tid,$oid)!==null)throw new RuntimeException('duplicate fixture identity');
        $this->rows[$id]=['user_id'=>$id,'tenant_id'=>$tid,'object_id'=>$oid];
    }
    public function remove(int $id,string $tid,string $oid):void{unset($this->rows[$id]);}
}
final class MemoryMicrosoftUsers implements AdminUserRepository
{
    public array $rows=[];public array $audits=[];public array $locks=[];public bool $failAudit=false;public ?Closure $before=null;
    public function __construct(private MemoryMicrosoftIdentities $identities){
        foreach([1=>'admin',2=>'requester',3=>'vehicle_officer',4=>'driver'] as $id=>$role)$this->rows[$id]=[
            'id'=>$id,'display_name'=>'Offline Fixture','username'=>'offline'.$id,'is_active'=>true,'auth_version'=>1,'roles'=>[$role]];
    }
    public function findUser(int $id):?array{return $this->rows[$id]??null;}
    public function transaction(callable $op):mixed{
        if($this->before!==null){($this->before)();$this->before=null;}
        $saved=[$this->rows,$this->audits,$this->identities->rows];
        try{return $op();}catch(Throwable $e){[$this->rows,$this->audits,$this->identities->rows]=$saved;throw $e;}
    }
    public function lockUsers(array $ids):void{$this->locks[]=$ids;}
    public function bumpAuthVersion(int $id):void{$this->rows[$id]['auth_version']++;}
    public function audit(int $actor,string $action,int $target,array $data):void{if($this->failAudit)throw new RuntimeException('fixture audit failure');$this->audits[]=[$actor,$action,$target,$data];}
    public function listUsers(string $s,string $r,int $p):array{throw new LogicException('not used');}
    public function activeAdminCount():int{throw new LogicException('not used');}
    public function createUser(string $u,?string $e,string $n,string $h,?string $nickname=null):int{throw new LogicException('must never create accounts');}
    public function setRoles(int $id,array $roles):void{throw new LogicException('must never assign roles');}
    public function setStatus(int $id,bool $active):void{throw new LogicException('not used');}
    public function setPasswordHash(int $id,string $hash):void{throw new LogicException('not used');}
}
$checks=0;
$check=static function(bool $condition,string $label)use(&$checks):void{if(!$condition)throw new RuntimeException('FAIL '.$label);$checks++;};
$reject=static function(callable $fn,string $type)use($check):void{
    $caught=null;try{$fn();}catch(Throwable $e){$caught=$e;}$check($caught instanceof $type,'expected rejection '.$type);
};
$ids=new MemoryMicrosoftIdentities();$users=new MemoryMicrosoftUsers($ids);$service=new MicrosoftIdentityMappingService($users,$ids);
$admin=new AuthenticatedPrincipal(1,1,['admin']);$tid='11111111-1111-4111-8111-111111111111';$oid='AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA';
foreach(['requester','vehicle_officer','approver','driver','staff','user'] as $role){
    $actor=new AuthenticatedPrincipal(2,1,[$role]);
    $reject(fn()=>$service->view($actor,2),AuthorizationException::class);
    $reject(fn()=>$service->change($actor,2,1,'link',$tid,$oid),AuthorizationException::class);
}
$check($service->view($admin,2)['mapping']===null,'Admin can inspect unmapped status');
$check($service->resolveIdentity($tid,$oid)===null,'unknown identity rejected without email fallback');
foreach(['bad','',[], '00000000-0000-0000-0000-000000000000'] as $invalid)$reject(fn()=>$service->change($admin,2,1,'link',$tid,$invalid),AdminUserInputException::class);
$service->change($admin,2,1,'link',$tid,$oid);
$check($ids->rows[2]['object_id']===strtolower($oid),'UUID normalized and stored');
$check($users->rows[2]['auth_version']===2,'link revokes previous sessions');
$check(count($users->audits)===1,'link audited');
$check($service->view($admin,2)['mapping']!==null,'Admin sees linked status');
$state=$service->resolveIdentity($tid,$oid);
$check($state?->userId===2&&$state->roles===['requester']&&$state->authVersion===2,'known identity resolves current existing user roles');
$check(!isset($_SESSION['auth']),'resolver does not itself create an Auth Session');
$reject(fn()=>$service->change($admin,3,1,'link',$tid,$oid),AdminUserInputException::class);
$reject(fn()=>$service->change($admin,2,1,'unlink',$tid,$oid),AdminUserInputException::class);
$reject(fn()=>$service->change($admin,4,1,'link',$tid,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'),AdminUserInputException::class);
$users->rows[2]['is_active']=false;$check($service->resolveIdentity($tid,$oid)===null,'inactive account rejected');$users->rows[2]['is_active']=true;
$users->before=function()use($users):void{$users->rows[1]['roles']=['requester'];};
$reject(fn()=>$service->change($admin,2,2,'unlink',$tid,$oid),AuthorizationException::class);
$check(isset($ids->rows[2]),'role revocation inside transaction prevents unlink');$users->rows[1]['roles']=['admin'];
$users->failAudit=true;$reject(fn()=>$service->change($admin,2,2,'unlink',$tid,$oid),RuntimeException::class);
$check(isset($ids->rows[2])&&$users->rows[2]['auth_version']===2,'audit failure rolls back mapping and auth version');$users->failAudit=false;
$controller=new App\Controllers\AdminMicrosoftIdentityController($service);
$_POST=['_token'=>'invalid','user_id'=>'2','auth_version'=>'2','action'=>'unlink','tenant_id'=>$tid,'object_id'=>$oid];
ob_start();try{$controller->submit($admin);}finally{ob_end_clean();}
$check(http_response_code()===419&&isset($ids->rows[2]),'controller rejects missing CSRF before writes');
$_POST['_token']=App\Core\Csrf::token();
ob_start();try{$controller->submit($admin);}finally{ob_end_clean();}
$check(http_response_code()===303&&!isset($ids->rows[2]),'CSRF-valid Admin can unlink through controller');
$check($users->rows[2]['auth_version']===3,'unlink revokes existing sessions');
$check($service->resolveIdentity($tid,$oid)===null,'unlinked identity denied');
$check($users->rows[2]['roles']===['requester']&&count($users->rows)===4,'roles and account inventory unchanged');
$routes=require dirname(__DIR__).'/routes/web.php';
$check(($routes['GET /admin/users/microsoft'][2]??null)==='users.manage'&&($routes['POST /admin/users/microsoft'][2]??null)==='users.manage','both routes require Admin RBAC');
$check(!isset($routes['GET /driver/register'])&&!isset($routes['POST /driver/register']),'public registration stays closed');
$ids->missingTable=true;$_GET=['user_id'=>'2'];
ob_start();try{$controller->index($admin);$body=ob_get_contents();}finally{ob_end_clean();}
$check(http_response_code()===503&&!str_contains($body,'action="/admin/users/microsoft"')&&!str_contains($body,'offline missing table'),'missing table disables mapping forms without leaking database errors');
$_POST['auth_version']='3';
ob_start();try{$controller->submit($admin);}finally{ob_end_clean();}
$check(http_response_code()===503&&$users->rows[2]['auth_version']===3,'missing table blocks Admin writes and rolls back');
$reject(fn()=>$service->resolveIdentity($tid,$oid),PDOException::class);
$check(!isset($_SESSION['auth']),'missing table cannot return a resolved identity or create an Auth Session');
echo 'PASS: '.$checks.' Microsoft Identity Mapping offline checks; no database'.PHP_EOL;
