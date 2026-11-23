<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
require dirname(__DIR__).'/app/helpers.php';
use App\Database\{AdminUserGrantPlan,AuthRuntimeGrantPlan,SystemLogGrantPlan,GrantVerifier,ConnectionFactory};
use App\Models\{AuthenticatedPrincipal,SystemLogFilter};
use App\Services\{SystemLogService,SafeAuditDetails,AuthorizationPolicy,RoleLabels};
use App\Repositories\PdoSystemLogRepository;
$checks=0;
function logCheck(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function logDenied(callable $fn):bool {try{$fn();return false;}catch(Throwable){return true;}}
final class LogStmt extends PDOStatement
{
    public array $bound=[];
    public function __construct(public string $sql,private array $rows=[],private mixed $scalar=null){}
    public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR):bool {$this->bound[$param]=[$value,$type];return true;}
    public function execute(?array $params=null):bool{return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->rows[0]??false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
    public function fetchColumn(int $column=0):mixed{return $this->scalar;}
}
final class LogPdo extends PDO
{
    public array $prepared=[];
    public function __construct(public array $grants,public string $database='up_van_booking_test',public string $account='up_van_auth_test@127.0.0.1',private bool $fuelPresent=false){}
    public function getAttribute(int $attribute):mixed{return match($attribute){PDO::ATTR_DRIVER_NAME=>'mysql',PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,default=>null};}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        if(str_starts_with($query,'SELECT DATABASE()'))return new LogStmt($query,[['db_name'=>$this->database,'account_name'=>$this->account,'partial_revokes'=>0]]);
        if($query==='SELECT `fuel_type` FROM `vehicles` LIMIT 0'){
            if($this->fuelPresent)return new LogStmt($query);
            $error=new PDOException();
            $error->errorInfo=['42S22',1054];
            throw $error;
        }
        return new LogStmt($query,str_starts_with($query,'SHOW')?$this->grants:[],match($query){'SELECT CURRENT_USER()'=>$this->account,'SELECT @@GLOBAL.partial_revokes'=>0,default=>'NONE'});
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        if(str_contains($query,'INFORMATION_SCHEMA.COLUMNS'))return new LogStmt($query,[],$this->fuelPresent?'fuel_type':false);
        $s=new LogStmt($query);$this->prepared[]=$s;return $s;
    }
}
$base=array_merge(AuthRuntimeGrantPlan::grantSql(false),AdminUserGrantPlan::supplementalSql());
$extended=[...$base,SystemLogGrantPlan::supplementalSql()];
logCheck(GrantVerifier::evaluate($base,'up_van_booking_test',SystemLogGrantPlan::rules(),false,true)['status']==='FAIL','Baseline lacks log SELECT');
logCheck(GrantVerifier::evaluate($extended,'up_van_booking_test',SystemLogGrantPlan::rules(),false,true)['status']==='PASS','Exact log extension accepted');
$combined=array_values(array_filter($base,static fn(string $s):bool=>!str_contains($s,'`audit_logs`')));
$combined[]="GRANT SELECT (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `metadata`, `created_at`), INSERT (`user_id`, `action`, `entity_type`, `entity_id`, `metadata`) ON `up_van_booking_test`.`audit_logs` TO 'up_van_auth_test'@'127.0.0.1'";
logCheck(GrantVerifier::evaluate($combined,'up_van_booking_test',SystemLogGrantPlan::rules(),false,true)['status']==='PASS','Consolidated MySQL SHOW GRANTS column clauses accepted');
logCheck(logDenied(fn()=>new SystemLogFilter(['from'=>"2026-11-10\0"])),'Malformed date rejected');
foreach (['DELETE','UPDATE','SELECT'] as $privilege) {
    $scope=$privilege==='SELECT'?'*.*':'`up_van_booking_test`.`audit_logs`';
    logCheck(GrantVerifier::evaluate([...$extended,"GRANT $privilege ON $scope TO 'up_van_auth_test'@'127.0.0.1'"],'up_van_booking_test',SystemLogGrantPlan::rules(),false,true)['status']==='FAIL','Unexpected privilege denied');
}
logCheck(GrantVerifier::evaluate($extended,'up_van_booking_test',SystemLogGrantPlan::rules(),false,false)['status']==='UNVERIFIED','Unresolved roles unverified');
ConnectionFactory::assertScopedGrants(new LogPdo($extended),'up_van_booking_test');
new App\Repositories\PdoAdminUserRepository(new LogPdo($extended));
logCheck(true,'New exact extension preserves existing auth and Admin repository acceptance');
foreach([new LogPdo($base),new LogPdo($extended,'up_van_booking'),new LogPdo($extended,'up_van_booking_test','root@localhost')] as $db)logCheck(logDenied(fn()=>new PdoSystemLogRepository($db)) && $db->prepared===[],'Fail before log reads for missing grant or wrong identity');
$db=new LogPdo($extended);$repo=new PdoSystemLogRepository($db);
$f=new SystemLogFilter(['action'=>'auth.login.success','actor'=>'12','from'=>'2026-11-10','to'=>'2026-11-10','page'=>'2']);
$repo->search($f);$q=$db->prepared[0];
logCheck($f->fromUtc==='2026-11-09 17:00:00' && $f->untilUtc==='2026-11-10 17:00:00','Date range converted from Bangkok with exclusive upper bound');
logCheck(str_contains($q->sql,'ORDER BY created_at DESC, id DESC') && $q->bound[':limit']===[26,PDO::PARAM_INT] && $q->bound[':offset']===[25,PDO::PARAM_INT],'Stable latest-first bounded pagination');
logCheck($q->bound[':actor']===[12,PDO::PARAM_INT] && $q->bound[':action']===['auth.login.success',PDO::PARAM_STR] && !str_contains($q->sql,'auth.login.success'),'Filters bound natively');
logCheck(!preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|GRANT|REVOKE)\b/',$q->sql),'Repository SELECT only');
$repo->search(new SystemLogFilter(['page'=>'2'],20));$historyQuery=$db->prepared[1];
logCheck(str_contains($historyQuery->sql,"entity_type='user' AND entity_id=:target_user") && $historyQuery->bound[':target_user']===['20',PDO::PARAM_STR] && $historyQuery->bound[':offset']===[25,PDO::PARAM_INT],'Account history target and page bound independently from actor');
logCheck(str_contains($historyQuery->sql,'auth.test_admin.provisioned') && str_contains($historyQuery->sql,'admin.user.status_changed'),'Account history includes only reviewed account event types');
foreach([['action'=>"' OR 1=1"],['action'=>[]],['actor'=>'-1'],['actor'=>'1 OR 1=1'],['from'=>'2026-02-30'],['from'=>'2026-12-01','to'=>'2026-11-01'],['page'=>'0'],['page'=>'10001']] as $bad)logCheck(logDenied(fn()=>new SystemLogFilter($bad)),'Invalid filter denied');
$memory=new class implements App\Contracts\SystemLogRepository {
    public int $calls=0;
    public function search(SystemLogFilter $filter):array {$this->calls++;return array_fill(0,26,['id'=>1,'user_id'=>2,'action'=>'admin.user.roles_changed','entity_type'=>'user','entity_id'=>'3','created_at'=>'2026-11-09 21:05:00','metadata'=>'{"before":{"roles":["requester"],"active":true,"password":"DO_NOT_RENDER"},"after":{"roles":["admin","DO_NOT_RENDER"],"active":false},"token":"DO_NOT_RENDER"}']);}
};
$service=new SystemLogService($memory);$admin=new AuthenticatedPrincipal(1,1,['admin']);
foreach([null,new AuthenticatedPrincipal(2,1,['requester']),new AuthenticatedPrincipal(3,1,['approver'])] as $actor)logCheck(logDenied(fn()=>$service->listing($actor,new SystemLogFilter([]))) && $memory->calls===0,'Unauthorized log access denied before repository');
$result=$service->listing($admin,new SystemLogFilter([]));
logCheck(count($result['rows'])===25 && $result['has_next'],'Lookahead row not exposed');
logCheck(!str_contains(json_encode($result),'DO_NOT_RENDER') && !isset($result['rows'][0]['metadata']),'Unknown fields, roles and raw metadata withheld');
foreach(['{"password":"secret"}','not json','{"before":{"roles":"secret"}}'] as $bad)logCheck(SafeAuditDetails::extract('unknown.event',$bad)===[],'Unknown event details withheld');
logCheck(SafeAuditDetails::extract('auth.login.success',['token'=>'secret'])===[],'Auth metadata hidden');
$policy=new AuthorizationPolicy();
logCheck($policy->allows(['admin'],'booking.view_all') && $policy->allows(['admin'],'booking.decision') && !$policy->allows(['admin'],'assignment.create'),'Admin can read and decide without assignment permission');
logCheck($policy->allows(['admin','approver'],'booking.decision') && $policy->allows(['admin','vehicle_officer'],'assignment.create'),'Multi-role union allowed');
logCheck(!$policy->allows(['unknown'],'logs.view') && !$policy->allows(['admin'],'unknown'),'Default deny');
logCheck(RoleLabels::ALL['approver']==='ผู้อำนวยการ','Director display label');
$_SESSION=[];$_GET=[];
$controller=new App\Controllers\SystemLogController($service);
logCheck(logDenied(fn()=>$controller->index(new AuthenticatedPrincipal(2,1,['requester']))),'Controller independently denies non-Admin');
$rows=$result['rows'];$rows[0]['action']='<img src=x onerror=alert(1)>';$rows[0]['entity_type']='<script>alert(1)</script>';
$filter=new SystemLogFilter([]);$hasNext=true;$error=null;
ob_start();require dirname(__DIR__).'/app/Views/admin/system-logs.php';$html=ob_get_clean();
logCheck(!str_contains($html,'<img') && !str_contains($html,'<script>') && str_contains($html,'&lt;img'),'Stored output escaped');
logCheck(!str_contains($html,'DO_NOT_RENDER') && !str_contains($html,'method="post"'),'Safe details and no mutation forms');
foreach(['admin','requester','approver','vehicle_officer'] as $role){$workspaceAuthenticated=true;$workspaceRoles=[$role];ob_start();require dirname(__DIR__).'/app/Views/partials/navigation.php';$nav=ob_get_clean();logCheck(str_contains($nav,'/admin/system-logs')===($role==='admin'),'Navigation follows permissions');}
$routes=require dirname(__DIR__).'/routes/web.php';
logCheck($routes['GET /admin/system-logs'][2]==='logs.view' && !isset($routes['POST /admin/system-logs']),'GET-only protected route');
echo 'PASS: ',$checks,' Task 05B System Log checks; fake PDO only.',PHP_EOL;
