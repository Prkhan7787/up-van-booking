<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
require dirname(__DIR__).'/app/helpers.php';
ob_start();
set_exception_handler(static function(Throwable $error): never {fwrite(STDERR,'Offline test error type='.get_class($error).' line='.$error->getLine().PHP_EOL);exit(1);});

use App\Contracts\AdminUserRepository;
use App\Core\AdminUserInputException;
use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;
use App\Services\AdminUserManagementService;
use App\Services\PasswordHasher;

$checks=0;
function checkAdmin(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException('Offline Admin assertion failed: '.$label);$checks++;echo 'PASS: '.$label,PHP_EOL;}
function adminReject(callable $action,string $class=AdminUserInputException::class): bool {try{$action();}catch(Throwable $e){return $e instanceof $class;}return false;}
final class MemoryAdminUsers implements AdminUserRepository
{
    public array $rows=[]; public array $hashes=[]; public array $audits=[]; public array $locks=[];
    public bool $failAudit=false; public ?Closure $beforeOperation=null;
    public function listUsers(string $search,string $role,int $page): array {
        $rows=array_values(array_filter($this->rows,static fn(array $r):bool=>($search==='' || str_contains(strtolower($r['username'].' '.$r['email'].' '.$r['display_name']),strtolower($search))) && ($role==='' || in_array($role,$r['roles'],true))));
        return ['rows'=>array_slice($rows,($page-1)*50,50),'page'=>$page,'has_next'=>count($rows)>$page*50];
    }
    public function findUser(int $id): ?array {return $this->rows[$id]??null;}
    public function transaction(callable $operation): mixed {
        $snapshot=[$this->rows,$this->hashes,$this->audits];
        try {if($this->beforeOperation!==null)($this->beforeOperation)();return $operation();}
        catch(Throwable $error){[$this->rows,$this->hashes,$this->audits]=$snapshot;throw $error;}
    }
    public function lockUsers(array $ids): void {$this->locks[]=$ids;}
    public function activeAdminCount(): int {return count(array_filter($this->rows,static fn(array $r):bool=>(bool)$r['is_active'] && in_array('admin',$r['roles'],true)));}
    public function createUser(string $username,?string $email,string $displayName,string $hash,?string $nickname=null): int {
        foreach($this->rows as $row)if(strcasecmp($row['username'],$username)===0 || ($email!==null && $row['email']!==null && strcasecmp($row['email'],$email)===0)){$e=new PDOException('synthetic duplicate');$e->errorInfo=['23000',1062,'synthetic'];throw $e;}
        $id=max(array_keys($this->rows))+1;
        $this->rows[$id]=['id'=>$id,'username'=>$username,'email'=>$email,'display_name'=>$displayName,'nickname'=>$nickname,'is_active'=>1,'auth_version'=>1,'created_at'=>'2026-11-10 12:00:00','roles'=>[]];$this->hashes[$id]=$hash;return $id;
    }
    public function setRoles(int $id,array $roles): void {$this->rows[$id]['roles']=$roles;}
    public function setStatus(int $id,bool $active): void {$this->rows[$id]['is_active']=(int)$active;}
    public function bumpAuthVersion(int $id): void {$this->rows[$id]['auth_version']++;}
    public function setPasswordHash(int $id,string $hash): void {$this->hashes[$id]=$hash;}
    public function audit(int $actor,string $action,int $target,array $metadata): void {if($this->failAudit)throw new RuntimeException('Synthetic audit unavailable');$this->audits[]=compact('actor','action','target','metadata');}
}
function adminFixture(): array {
    $repo=new MemoryAdminUsers();
    foreach([10=>['admin.second',['admin']],11=>['admin1',['admin']],20=>['request.user',['requester']]] as $id=>[$name,$roles])$repo->rows[$id]=['id'=>$id,'username'=>$name,'email'=>$name.'@example.test','display_name'=>'Dummy User','is_active'=>1,'auth_version'=>1,'created_at'=>'2026-11-10 12:00:00','roles'=>$roles];
    return [$repo,new AdminUserManagementService($repo,new PasswordHasher()),new AuthenticatedPrincipal(10,1,['admin'])];
}
[$repo,$service,$actor]=adminFixture();
[$resetRepo,$resetService,$resetAdmin]=adminFixture();
checkAdmin(adminReject(fn()=>$resetService->resetPassword(new AuthenticatedPrincipal(20,1,['requester']),20,1,'new-pass-123','new-pass-123'),AuthorizationException::class),'Requester cannot reset passwords');
checkAdmin(adminReject(fn()=>$resetService->resetPassword($resetAdmin,11,1,'new-pass-123','new-pass-123')),'admin1 password is protected');
checkAdmin(adminReject(fn()=>$resetService->resetPassword($resetAdmin,10,1,'new-pass-123','new-pass-123')),'Admin cannot reset own password from account management');
checkAdmin(adminReject(fn()=>$resetService->resetPassword($resetAdmin,20,1,'short','short')) && adminReject(fn()=>$resetService->resetPassword($resetAdmin,20,1,'new-pass-123','different')),'Password reset validates length and confirmation');
checkAdmin(adminReject(fn()=>$resetService->resetPassword($resetAdmin,20,2,'new-pass-123','new-pass-123')),'Stale password reset version is rejected');
$resetRepo->failAudit=true;
checkAdmin(adminReject(fn()=>$resetService->resetPassword($resetAdmin,20,1,'new-pass-123','new-pass-123'),RuntimeException::class) && !isset($resetRepo->hashes[20]) && $resetRepo->rows[20]['auth_version']===1,'Audit failure rolls back password and session version');
$resetRepo->failAudit=false;$resetService->resetPassword($resetAdmin,20,1,'new-pass-123','new-pass-123');
checkAdmin(password_verify('new-pass-123',$resetRepo->hashes[20]) && password_get_info($resetRepo->hashes[20])['algoName']==='argon2id' && $resetRepo->rows[20]['auth_version']===2,'Reset stores Argon2id and revokes old sessions');
$resetAudit=$resetRepo->audits[array_key_last($resetRepo->audits)];
checkAdmin($resetAudit['action']==='admin.user.password_reset' && !str_contains(json_encode($resetAudit),'new-pass-123') && !str_contains(json_encode($resetAudit),'argon2id'),'Password reset audit contains no password or hash');
[$httpRepo,$httpService,$httpAdmin]=adminFixture();$resetController=new App\Controllers\AdminUserManagementController(null,$httpService);
$_POST=['action'=>'reset_password','user_id'=>'20','auth_version'=>'1','password'=>'offline-pass-456','password_confirmation'=>'offline-pass-456'];
ob_start();$resetController->submit($httpAdmin);ob_end_clean();
checkAdmin(http_response_code()===419 && !isset($httpRepo->hashes[20]),'Password reset POST without CSRF is rejected');
$_POST['_token']=App\Core\Csrf::token();$resetController->submit($httpAdmin);
checkAdmin(http_response_code()===303 && $httpRepo->rows[20]['auth_version']===2 && password_verify('offline-pass-456',$httpRepo->hashes[20]),'Admin CSRF password reset revokes target sessions and redirects');
$input=['username'=>' New.User ','email'=>'new@example.test','display_name'=>'New User','password'=>'dummy-password-123','password_confirmation'=>'dummy-password-123'];
$requester=new AuthenticatedPrincipal(20,1,['requester']);
checkAdmin(adminReject(fn()=>$service->listing($requester),AuthorizationException::class),'Requester cannot list accounts');
checkAdmin(adminReject(fn()=>$service->create($requester,$input),AuthorizationException::class),'Requester cannot create accounts');
checkAdmin(adminReject(fn()=>$service->changeRoles($requester,20,1,['admin']),AuthorizationException::class),'Requester cannot promote self');
checkAdmin(adminReject(fn()=>$service->changeStatus($requester,10,1,false),AuthorizationException::class),'Requester cannot change another account status');
checkAdmin(adminReject(fn()=>$service->listing(new AuthenticatedPrincipal(10,99,['admin'])),AuthorizationException::class),'Stale actor auth_version denied');
$repo->rows[10]['is_active']=0;
checkAdmin(adminReject(fn()=>$service->listing($actor),AuthorizationException::class),'Suspended Admin denied');$repo->rows[10]['is_active']=1;
$repo->rows[10]['roles']=['requester'];
checkAdmin(adminReject(fn()=>$service->listing($actor),AuthorizationException::class),'Roles reloaded from server storage');$repo->rows[10]['roles']=['admin'];
checkAdmin(adminReject(fn()=>$service->create($requester,$input+['role'=>'admin']),AuthorizationException::class),'Non-Admin cannot select privileged initial role');
checkAdmin(adminReject(fn()=>$service->create($actor,$input+['role'=>'super_admin'])),'Unknown initial role denied');
checkAdmin(adminReject(fn()=>$service->create($actor,$input+['role'=>['admin']])),'Array initial role denied');
checkAdmin(adminReject(fn()=>$service->create($actor,$input+['roles'=>['admin']])),'Forged initial multiple roles denied');
foreach([['password'=>'12345','password_confirmation'=>'12345'],['password_confirmation'=>'mismatch'],['username'=>'ab'],['email'=>'invalid'],['display_name'=>'']] as $invalid){checkAdmin(adminReject(fn()=>$service->create($actor,array_replace($input,$invalid))),'Invalid create fields rejected');}
$id=$service->create($actor,$input);
checkAdmin($repo->rows[$id]['username']==='new.user' && $repo->rows[$id]['roles']===['requester'],'Create normalizes username and assigns only Requester');
checkAdmin(password_get_info($repo->hashes[$id])['algoName']==='argon2id' && password_verify($input['password'],$repo->hashes[$id]),'New password uses working Argon2id hash');
checkAdmin(!str_contains(json_encode($repo->audits,JSON_THROW_ON_ERROR),$input['password']) && !str_contains(json_encode($repo->audits,JSON_THROW_ON_ERROR),$repo->hashes[$id]),'Audit never contains submitted password or hash');
checkAdmin($repo->audits[0]['actor']===10 && $repo->audits[0]['metadata']===['roles'=>['requester'],'source'=>'admin_user_management'],'Create audit is automatic, without a user-supplied reason');
foreach(AdminUserManagementService::ROLES as $initialRole){
    [$roleRepo,$roleService,$roleActor]=adminFixture();
    $newId=$roleService->create($roleActor,$input+['role'=>$initialRole,'reason'=>'DO_NOT_LOG_CLIENT_REASON']);
    checkAdmin($roleRepo->rows[$newId]['roles']===[$initialRole] && $roleRepo->audits[0]['metadata']['roles']===[$initialRole] && !str_contains(json_encode($roleRepo->audits,JSON_THROW_ON_ERROR),'DO_NOT_LOG_CLIENT_REASON'),'Admin-authorized create assigns and audits '.$initialRole.' only');
}
checkAdmin(adminReject(fn()=>$service->create($actor,array_replace($input,['username'=>'NEW.USER']))),'Case-insensitive duplicate username denied');
checkAdmin(adminReject(fn()=>$service->create($actor,array_replace($input,['username'=>'other.user']))),'Duplicate email denied');
checkAdmin(count($service->listing($actor,'new','requester')['rows'])===1,'Search and role filter reach repository');
checkAdmin(adminReject(fn()=>$service->listing($actor,'','super_admin')),'Unknown role filter denied');
checkAdmin(adminReject(fn()=>$service->listing($actor,'','',0)),'Invalid page denied');
$service->changeRoles($actor,20,1,['requester','vehicle_officer']);
checkAdmin($repo->rows[20]['roles']===['requester','vehicle_officer'] && $repo->rows[20]['auth_version']===2,'Multiple roles saved and existing sessions revoked');
$roleAudit=$repo->audits[array_key_last($repo->audits)];
checkAdmin($roleAudit['actor']===10 && $roleAudit['target']===20 && $roleAudit['action']==='admin.user.roles_changed' && $roleAudit['metadata']===['before'=>['roles'=>['requester'],'active'=>true],'after'=>['roles'=>['requester','vehicle_officer'],'active'=>true]],'Role audit records actor, target, action, and old/new values without a user reason');
$service->changeRoles($actor,20,2,['requester','vehicle_officer']);
checkAdmin($repo->rows[20]['auth_version']===2,'Unchanged multi-role submission preserves all roles and auth_version');
checkAdmin(adminReject(fn()=>$service->changeRoles($actor,20,2,['requester','requester'])),'Duplicate dropdown role values denied');
checkAdmin(adminReject(fn()=>$service->changeRoles($actor,20,2,['invalid_role'])),'Invalid role assignment is denied without a reason parameter');
$service->changeStatus($actor,20,2,false);
checkAdmin(!$repo->rows[20]['is_active'] && $repo->rows[20]['auth_version']===3,'Suspension bumps auth_version');
$statusAudit=$repo->audits[array_key_last($repo->audits)];
checkAdmin($statusAudit['actor']===10 && $statusAudit['target']===20 && $statusAudit['action']==='admin.user.status_changed' && $statusAudit['metadata']===['before'=>['roles'=>['requester','vehicle_officer'],'active'=>true],'after'=>['roles'=>['requester','vehicle_officer'],'active'=>false]],'Status audit records actor, target, action, and old/new values without a user reason');
$service->changeStatus($actor,20,3,true);
checkAdmin((bool)$repo->rows[20]['is_active'] && $repo->rows[20]['auth_version']===4,'Account can be reactivated');
checkAdmin(adminReject(fn()=>$service->changeStatus($actor,10,1,false)),'Admin cannot suspend self');
checkAdmin(adminReject(fn()=>$service->changeRoles($actor,11,1,['requester'])),'admin1 role changes blocked');
checkAdmin(adminReject(fn()=>$service->changeStatus($actor,11,1,false)),'admin1 status changes blocked');
checkAdmin(adminReject(fn()=>$service->changeRoles($actor,20,1,['admin'])),'Stale target version denied');
checkAdmin(adminReject(fn()=>$service->changeStatus($actor,999,1,false)),'Unknown target denied');
checkAdmin(adminReject(fn()=>$service->changeRoles($actor,20,4,['super_admin'])),'Unknown assignment role denied');
checkAdmin(adminReject(fn()=>$service->changeRoles($actor,20,4,[])),'Empty roles denied');
$repo->rows[11]['is_active']=0;
checkAdmin(adminReject(fn()=>$service->changeRoles($actor,10,1,['requester'])),'Last active Admin cannot be demoted');
checkAdmin($repo->rows[10]['roles']===['admin'],'Rejected last-admin change preserves roles');
[$repo,$service,$actor]=adminFixture();$before=$repo->rows;$repo->failAudit=true;
checkAdmin(adminReject(fn()=>$service->create($actor,$input),RuntimeException::class) && $repo->rows===$before && $repo->hashes===[],'Audit failure rolls back complete create');
checkAdmin(adminReject(fn()=>$service->changeRoles($actor,20,1,['approver']),RuntimeException::class) && $repo->rows===$before,'Audit failure rolls back roles and auth_version');
checkAdmin(adminReject(fn()=>$service->changeStatus($actor,20,1,false),RuntimeException::class) && $repo->rows===$before,'Audit failure rolls back status and auth_version');
[$repo,$service,$actor]=adminFixture();$repo->beforeOperation=function()use($repo):void{$repo->rows[10]['roles']=['requester'];};
checkAdmin(adminReject(fn()=>$service->create($actor,$input),AuthorizationException::class),'Actor authorization rechecked inside locked transaction');
checkAdmin(adminReject(fn()=>$service->create($actor,$input+['role'=>'admin']),AuthorizationException::class) && count($repo->rows)===3,'Privileged create rechecks Admin under lock and rolls back when revoked');

$_SESSION=[];
$controller=new App\Controllers\AdminUserManagementController(null,$service);
checkAdmin(adminReject(fn()=>$controller->index(null),AuthorizationException::class),'Controller denies anonymous request');
checkAdmin(adminReject(fn()=>$controller->index($requester),AuthorizationException::class),'Controller denies non-Admin request');
$_POST=['action'=>'create'];ob_start();$controller->submit($actor);$body=ob_get_clean();
checkAdmin(http_response_code()===419 && str_contains($body,'คำขอหมดอายุ'),'POST without CSRF denied before service');

[$controllerRepo,$controllerService,$controllerActor]=adminFixture();
$controller=new App\Controllers\AdminUserManagementController(null,$controllerService);
http_response_code(200);
$_POST=['_token'=>App\Core\Csrf::token(),'action'=>'primary_role','user_id'=>'20','auth_version'=>'1','previous_role'=>'requester','primary_role'=>'approver'];
$controller->submit($controllerActor);
checkAdmin($controllerRepo->rows[20]['roles']===['approver'] && $controllerRepo->rows[20]['auth_version']===2,'Controller accepts CSRF-protected single-role change without a reason field');
$_POST=['_token'=>App\Core\Csrf::token(),'action'=>'roles','user_id'=>'20','auth_version'=>'2','roles'=>['requester','admin']];
ob_start();$controller->submit($controllerActor);ob_end_clean();
checkAdmin(http_response_code()===422 && $controllerRepo->rows[20]['roles']===['approver'],'Removed multi-role POST action is denied by controller');
$_POST=['_token'=>App\Core\Csrf::token(),'action'=>'status','user_id'=>'20','auth_version'=>'2','active'=>'0'];
$controller->submit($controllerActor);
checkAdmin((bool)$controllerRepo->rows[20]['is_active']===false && $controllerRepo->rows[20]['auth_version']===3,'Controller accepts CSRF-protected status changes without a reason field');

$roles=(new App\Services\UiFoundationService())->adminUserManagementOptions()['roles'];
$accountRows=[['id'=>20,'username'=>'<script>alert(1)</script>','email'=>'<img src=x>','display_name'=>'<b>unsafe</b>','roles'=>['requester'],'is_active'=>1,'auth_version'=>1,'created_at'=>'2026-11-09 21:05:00']];
$actionsEnabled=true;$actorId=10;$page=1;$hasNext=false;
ob_start();require dirname(__DIR__).'/app/Views/admin/users.php';$html=ob_get_clean();
checkAdmin(!str_contains($html,'<script>alert(1)</script>') && str_contains($html,'&lt;script&gt;') && !str_contains($html,'<img src=x>'),'Shared original view escapes account data');
checkAdmin(substr_count($html,'name="_token"')===5 && str_contains($html,'data-admin-confirm="เปลี่ยนบทบาทหลัก"') && str_contains($html,'data-admin-confirm="ยืนยันรีเซ็ตรหัสผ่านบัญชีนี้"'),'Create, role, status and password reset forms have CSRF and confirmations');
checkAdmin(!str_contains($html,'จัดการหลายบทบาท') && !str_contains($html,'<dialog') && !str_contains($html,'data-role-editor'),'Removed multi-role modal and action are absent');
$roleLabels=array_column($roles,'label','value');
checkAdmin(!str_contains($html,'วันที่สร้าง') && !str_contains($html,'name="reason"') && !str_contains($html,'data-add-role'),'Main table hides created date and removes retired actions without reason fields');
checkAdmin(format_system_timestamp('2026-11-09 21:05:00')==='10/11/2026 04:05' && format_system_timestamp('invalid')==='—' && format_system_timestamp(null)==='—','Timestamp helper converts UTC to the system timezone and handles invalid or empty values');
checkAdmin($roleLabels===['requester'=>'requester — ผู้ขอใช้รถ','vehicle_officer'=>'vehicle_officer — เจ้าหน้าที่รถ','approver'=>'approver — ผู้อำนวยการ','admin'=>'admin — ผู้ดูแลระบบ'],'All four role labels match the requested Admin UI wording');
$routes=require dirname(__DIR__).'/routes/web.php';
checkAdmin(isset($routes['GET /admin/users'],$routes['POST /admin/users']) && $routes['GET /admin/users'][2]==='users.manage' && $routes['POST /admin/users'][2]==='users.manage','Live Admin route remains protected by server-side RBAC');
checkAdmin(!isset($routes['GET /ui-preview/admin/users']) && !method_exists(App\Controllers\AdminUserManagementController::class,'preview'),'Admin users Preview route and public controller action are removed');
checkAdmin(str_contains($html,'action="/admin/users"') && !str_contains($html,'/ui-preview/admin/users'),'Live Admin view submits only to the protected route');
checkAdmin(!str_contains(file_get_contents(dirname(__DIR__).'/app/Views/layouts/workspace.php'),'/ui-preview/admin/users'),'Workspace has no link to removed Admin Preview');
[$repo,$service,$actor]=adminFixture();$repo->rows[20]['roles']=['requester','vehicle_officer'];
checkAdmin(adminReject(fn()=>$service->changePrimaryRole($actor,20,1,'requester','approver')) && $repo->rows[20]['roles']===['requester','vehicle_officer'] && $repo->audits===[],'Existing multi-role account cannot be changed or lose roles');
$repo->rows[20]['roles']=['requester'];
$service->changePrimaryRole($actor,20,1,'requester','requester');
checkAdmin($repo->rows[20]['roles']===['requester'] && $repo->rows[20]['auth_version']===1 && $repo->audits===[],'Saving unchanged role preserves version without audit');
$service->changePrimaryRole($actor,20,1,'requester','approver');
checkAdmin($repo->rows[20]['roles']===['approver'] && $repo->rows[20]['auth_version']===2,'Single role is replaced and session version bumps');
checkAdmin($repo->audits[0]['metadata']['before']['roles']===['requester'] && $repo->audits[0]['metadata']['after']['roles']===['approver'],'Single-role change audits old and new role values');
checkAdmin(adminReject(fn()=>$service->changePrimaryRole($actor,20,2,'requester','admin')),'Previous role absent from locked row is rejected');
checkAdmin(adminReject(fn()=>$service->changePrimaryRole($actor,20,1,'approver','admin')),'Stale primary-role version rejected');
checkAdmin(adminReject(fn()=>$service->changePrimaryRole($actor,20,2,'approver','super_admin')),'Unknown primary role rejected');
checkAdmin(adminReject(fn()=>$service->changeRoles($actor,10,1,['requester'])) && adminReject(fn()=>$service->changePrimaryRole($actor,10,1,'admin','requester')),'Self role changes denied even with another active Admin');
checkAdmin(adminReject(fn()=>$service->changePrimaryRole($actor,11,1,'admin','requester')),'Primary changes cannot alter admin1');
$before=$repo->rows;$repo->failAudit=true;
checkAdmin(adminReject(fn()=>$service->changePrimaryRole($actor,20,2,'approver','requester'),RuntimeException::class) && $repo->rows===$before,'Primary role audit failure rolls back all changes');
[$repo,$service,$actor]=adminFixture();
$controller=new App\Controllers\AdminUserManagementController(null,$service);
$_POST=['_token'=>App\Core\Csrf::token(),'action'=>'primary_role','user_id'=>'20','auth_version'=>'1','previous_role'=>'requester','primary_role'=>'approver','roles'=>['admin']];
$controller->submit($actor);
checkAdmin($repo->rows[20]['roles']===['approver'],'Controller never trusts posted retained roles for primary changes');
$safeTarget=$service->historyTarget($actor,20);
checkAdmin(array_keys($safeTarget)===['id','username','display_name','created_at'],'History target exposes only required account fields');
checkAdmin(adminReject(fn()=>$service->historyTarget($requester,20),AuthorizationException::class),'Non-Admin cannot retrieve history target');
$historyRepo=new class implements App\Contracts\SystemLogRepository {
    public array $filters=[]; public bool $unavailable=false;
    public function search(App\Models\SystemLogFilter $filter):array {
        $this->filters[]=$filter;
        if($this->unavailable)throw new App\Core\SystemLogUnavailableException();
        return [['id'=>99,'user_id'=>42,'entity_type'=>'user','entity_id'=>'20','action'=>'admin.user.roles_changed','created_at'=>'2026-11-09 21:05:00','metadata'=>['before'=>['roles'=>['requester'],'active'=>true],'after'=>['roles'=>['approver','vehicle_officer'],'active'=>false],'password'=>'SECRET_MUST_NOT_RENDER']]];
    }
};
$logs=new App\Services\SystemLogService($historyRepo);
$controller=new App\Controllers\AdminUserManagementController(null,$service,$logs);
$_GET=['history'=>'20','history_page'=>'2'];ob_start();$controller->index($actor);$historyHtml=ob_get_clean();
$historyPanel=substr($historyHtml,(int)strpos($historyHtml,'<section id="user-history"'));
checkAdmin($historyRepo->filters[0]->targetUserId===20 && $historyRepo->filters[0]->page===2,'History uses target identity separately from actor and list pagination');
checkAdmin(str_contains($historyPanel,'10/11/2026 19:00') && str_contains($historyPanel,'10/11/2026 04:05') && str_contains($historyPanel,'#42'),'History shows persisted account creation date, event timestamp and actor ID');
checkAdmin(str_contains($historyPanel,'ผู้ขอใช้รถ') && str_contains($historyPanel,'ผู้อำนวยการ') && str_contains($historyPanel,'ระงับบัญชี') && !str_contains($historyPanel,'SECRET_MUST_NOT_RENDER'),'History renders safe old/new roles and status only');
checkAdmin(adminReject(fn()=>$logs->userHistory($requester,20),AuthorizationException::class),'Non-Admin cannot read account audit events');
$historyRepo->unavailable=true;ob_start();$controller->index($actor);$unavailableHtml=ob_get_clean();
checkAdmin(str_contains($unavailableHtml,'ต้องตรวจสิทธิ์ SELECT') && str_contains($unavailableHtml,'10/11/2026 19:00') && !str_contains($unavailableHtml,'10/11/2026 04:05'),'Missing log grant preserves account table and creation date without inventing audit history');
$calls=count($historyRepo->filters);$_GET=['history'=>'20 OR 1=1'];ob_start();$controller->index($actor);ob_end_clean();
checkAdmin(count($historyRepo->filters)===$calls && http_response_code()===422,'Invalid history identifier rejected before log access');
$_GET=[];ob_start();$controller->index($actor);ob_end_clean();
checkAdmin(count($historyRepo->filters)===$calls,'Normal account listing performs no audit reads');

[$driverRepo,$disabledService,$driverAdmin]=adminFixture();
$driverService=new AdminUserManagementService($driverRepo,new PasswordHasher(),true);
$driverController=new App\Controllers\AdminUserManagementController(null,$driverService);
$input=['real_name'=>'ชื่อสำหรับทดสอบ','nickname'=>'ทดสอบ','phone'=>'+66 81-000-0000','password'=>'offline-only-driver','password_confirmation'=>'offline-only-driver'];
checkAdmin(adminReject(fn()=>$disabledService->createDriver($driverAdmin,$input),App\Core\AdminUsersUnavailableException::class),'Driver creation fails closed without the grant capability');
foreach (['requester','driver','vehicle_officer','approver'] as $role) {
    checkAdmin(adminReject(fn()=>$driverService->createDriver(new AuthenticatedPrincipal(20,1,[$role]),$input),AuthorizationException::class),$role.' cannot create a Driver account');
}
checkAdmin(adminReject(fn()=>$driverService->createDriver($driverAdmin,$input+['roles'=>['admin']])),'Driver creation rejects forged roles');
checkAdmin(adminReject(fn()=>$driverService->createDriver($driverAdmin,array_replace($input,['phone'=>'invalid']))) && adminReject(fn()=>$driverService->createDriver($driverAdmin,array_replace($input,['nickname'=>' ']))),'Driver creation validates phone and nickname');
checkAdmin(adminReject(fn()=>$driverService->createDriver($driverAdmin,array_replace($input,['password_confirmation'=>'mismatch']))),'Driver creation validates password confirmation');
$_POST=$input+['action'=>'create_driver','_token'=>'invalid'];
ob_start();$driverController->submit($driverAdmin);ob_end_clean();
checkAdmin(http_response_code()===419 && count($driverRepo->rows)===3,'Driver creation POST requires CSRF before writing');
$driverRepo->beforeOperation=function()use($driverRepo):void{$driverRepo->rows[10]['auth_version']=2;};
checkAdmin(adminReject(fn()=>$driverService->createDriver($driverAdmin,$input),AuthorizationException::class) && $driverRepo->audits===[],'Admin is revalidated under the account transaction lock');
$driverRepo->beforeOperation=null;$driverRepo->failAudit=true;
checkAdmin(adminReject(fn()=>$driverService->createDriver($driverAdmin,$input),RuntimeException::class) && count($driverRepo->rows)===3 && $driverRepo->hashes===[],'Failed audit rolls back Driver account and assigned role');
$driverRepo->failAudit=false;
$_POST=$input+['action'=>'create_driver','_token'=>App\Core\Csrf::token()];
$driverController->submit($driverAdmin);
$id=max(array_keys($driverRepo->rows));
$driver=$driverRepo->rows[$id];
checkAdmin(http_response_code()===303 && $driver['username']==='0810000000' && $driver['display_name']===$input['real_name'] && $driver['nickname']===$input['nickname'],'Admin POST creates a Driver with normalized phone and names');
checkAdmin($driver['roles']===['driver'] && $driver['email']===null && $driver['is_active']===1,'Driver is active with only the Driver role and no email');
checkAdmin(password_verify($input['password'],$driverRepo->hashes[$id]) && password_get_info($driverRepo->hashes[$id])['algoName']==='argon2id','Driver password is stored as Argon2id');
checkAdmin($driverRepo->audits[0]===['actor'=>10,'action'=>'admin.user.created','target'=>$id,'metadata'=>['roles'=>['driver'],'source'=>'admin_user_management']],'Driver creation audit records Admin, target and role without personal data or credentials');
checkAdmin(adminReject(fn()=>$driverService->createDriver($driverAdmin,array_replace($input,['phone'=>'081 000 0000']))),'Equivalent phone numbers cannot create duplicate accounts');
checkAdmin(adminReject(fn()=>$driverService->changeRoles($driverAdmin,$id,1,['admin'])) && adminReject(fn()=>$driverService->changePrimaryRole($driverAdmin,$id,1,'driver','admin')),'A Driver account cannot be converted to a privileged role');
$driverService->changeStatus($driverAdmin,$id,1,false);
checkAdmin($driverRepo->rows[$id]['is_active']===0 && $driverRepo->rows[$id]['auth_version']===2,'Admin can deactivate Driver account and revoke prior sessions');
$_GET=['role'=>'driver'];ob_start();$driverController->index($driverAdmin);$driverHtml=ob_get_clean();
checkAdmin(str_contains($driverHtml,'value="create_driver"') && str_contains($driverHtml,'name="nickname"') && str_contains($driverHtml,'name="phone" type="tel"') && str_contains($driverHtml,'0810000000'),'Admin view renders Driver form and filters Driver rows');
unset($_POST,$_GET);
echo 'PASS: ',$checks,' Admin user offline checks; no database connected.',PHP_EOL;
ob_end_flush();
