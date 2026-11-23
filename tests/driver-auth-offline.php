<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/cli.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path(sys_get_temp_dir());
    session_name('driver_auth_offline');
    session_start();
}
ob_start();

use App\Contracts\AuthEventLogger;
use App\Contracts\AuthRepository;
use App\Contracts\DriverAccountLinkRepository;
use App\Contracts\DriverRegistrationRepository;
use App\Contracts\DriverScheduleRepository;
use App\Contracts\LoginRateLimitStore;
use App\Core\AuthenticationException;
use App\Core\AuthorizationException;
use App\Core\DriverAssignmentNotFoundException;
use App\Core\DriverRegistrationInputException;
use App\Core\SessionManager;
use App\Database\AuthRuntimeGrantPlan;
use App\Database\ConnectionFactory;
use App\Database\DriverAuthGrantPlan;
use App\Database\GrantVerifier;
use App\Database\MigrationTarget;
use App\Models\AuthAccount;
use App\Models\AuthState;
use App\Models\AuthenticatedPrincipal;
use App\Services\AuthService;
use App\Services\AuthorizationPolicy;
use App\Services\DriverAccountLinkService;
use App\Services\DriverRegistrationService;
use App\Services\DriverScheduleService;
use App\Services\LoginRateLimiter;
use App\Services\PasswordHasher;
use App\Services\PhoneNumberPolicy;

$checks = 0;
function driverCheck(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
}

final class DriverTestRateStore implements LoginRateLimitStore
{
    public int $calls = 0;
    public function attempt(array $buckets, int $now, int $windowSeconds, callable $authenticate): App\Contracts\LoginRateLimitResult
    {
        $this->calls++;
        return App\Contracts\LoginRateLimitResult::completed($authenticate());
    }
}

final class DriverTestRegistrationRepository implements DriverRegistrationRepository
{
    public array $created = [];
    public bool $duplicate = false;
    public function createDriverAccount(string $phoneUsername, string $realName, string $nickname, string $passwordHash): void
    {
        if ($this->duplicate) {
            $error = new PDOException('synthetic duplicate');
            $error->errorInfo = ['23000', 1062, 'duplicate'];
            throw $error;
        }
        $this->created = ['username'=>$phoneUsername,'real_name'=>$realName,'nickname'=>$nickname,'hash'=>$passwordHash,'role'=>'driver','active'=>true];
    }
}

final class DriverTestAuthRepository implements AuthRepository
{
    public array $lookups = [];
    public ?AuthAccount $account = null;
    public ?AuthState $state = null;
    public function findLoginAccount(string $normalizedIdentifier): ?AuthAccount { $this->lookups[]=$normalizedIdentifier; return $this->account; }
    public function loadAuthState(int $userId): ?AuthState { return $this->state; }
    public function replacePasswordHash(int $userId, string $passwordHash): void {}
}

final class DriverTestEvents implements AuthEventLogger
{
    public array $events = [];
    public function record(string $event, ?int $actorId, array $metadata = []): void { $this->events[] = [$event,$actorId,$metadata]; }
}

final class DriverTestScheduleRepository implements DriverScheduleRepository
{
    public array $rows = [41=>['assignment_id'=>41,'user_id'=>7,'vehicle_label'=>'รถทดสอบ','passengers'=>[['display_name'=>'ผู้โดยสารที่เกี่ยวข้อง']]]];
    public array $requestedUsers = [];
    public function isLinked(int $userId): bool { return $userId === 7; }
    public function listOwnAssignments(int $userId): array { $this->requestedUsers[]=$userId; return array_values(array_filter($this->rows,static fn(array $row):bool=>$row['user_id']===$userId)); }
    public function findOwnAssignment(int $userId, int $assignmentId): ?array { $this->requestedUsers[]=$userId; $row=$this->rows[$assignmentId]??null; return is_array($row)&&$row['user_id']===$userId?$row:null; }
    public function listPassengersForOwnAssignment(int $userId, int $assignmentId): array { $this->requestedUsers[]=$userId; return $this->findOwnAssignment($userId,$assignmentId)['passengers']??[]; }
}

final class DriverTestLinkRepository implements DriverAccountLinkRepository
{
    public array $linked = [];
    public function listUnlinkedDrivers(): array { return [['id'=>8,'display_name'=>'Synthetic Driver','staff_code'=>'FIXTURE']]; }
    public function listUnlinkedDriverAccounts(): array { return [['id'=>7,'username'=>'0812345678','display_name'=>'Synthetic Account','nickname'=>'Test']]; }
    public function link(int $actorUserId, int $driverId, int $accountUserId, int $actorAuthVersion): void { $this->linked=[$actorUserId,$driverId,$accountUserId]; }
}

driverCheck(PhoneNumberPolicy::normalize('081-234-5678')==='0812345678' && PhoneNumberPolicy::normalize('+66 81 234 5678')==='0812345678','Thai local and +66 phone formats normalize to one unique username');
driverCheck(PhoneNumberPolicy::normalize('12345')===null && PhoneNumberPolicy::normalize('08123456789')===null,'invalid phone lengths are rejected');

$hasher = new PasswordHasher();
$registrationRepo = new DriverTestRegistrationRepository();
$registrationStore = new DriverTestRateStore();
$registration = new DriverRegistrationService($registrationRepo,$registrationStore,str_repeat('r',40),$hasher);
$input=['real_name'=>'ชื่อจริงทดสอบ','nickname'=>'ทดสอบ','phone'=>'+66 81 234 5678','password'=>'driver-test-pass','password_confirmation'=>'driver-test-pass'];
driverCheck($registration->register($input,'127.0.0.1') && $registrationRepo->created['username']==='0812345678','registration creates an active account using canonical phone as username');
driverCheck($registrationRepo->created['role']==='driver' && $registrationRepo->created['active']===true && password_get_info($registrationRepo->created['hash'])['algoName']==='argon2id','registration stores Argon2id hash and assigns Driver only');
driverCheck(!array_key_exists('password_confirmation',$registrationRepo->created) && $registrationStore->calls===1,'confirmation password is never persisted and rate limiting wraps creation');
$registrationRepo->duplicate=true;
driverCheck(!$registration->register($input,'127.0.0.1'),'duplicate phone is handled as a generic rejected registration');
$invalid=false;
try { $registration->register(array_replace($input,['password_confirmation'=>'different']),'127.0.0.1'); }
catch (DriverRegistrationInputException $error) { $invalid=isset($error->errors['password_confirmation']); }
driverCheck($invalid,'password confirmation mismatch is rejected server-side');

driverCheck((new App\Services\AuthorizationPolicy())->allows(['driver'],'driver.schedule.view'),'Driver has explicit own-schedule permission');
foreach (['requester','vehicle_officer','approver','admin','unrecognized'] as $role) driverCheck(!(new AuthorizationPolicy())->allows([$role],'driver.schedule.view'),$role.' is denied Driver schedule permission by default');
driverCheck((new AuthorizationPolicy())->allows(['admin'],'drivers.manage') && (new AuthorizationPolicy())->allows(['vehicle_officer'],'drivers.manage'),'Admin and Vehicle Officer may access staff linking controls');

$scheduleRepo = new DriverTestScheduleRepository();
$schedule = new DriverScheduleService($scheduleRepo);
$driver = new AuthenticatedPrincipal(7,1,['driver']);
driverCheck($schedule->listing($driver)['linked']===true && count($schedule->listing($driver)['assignments'])===1,'linked Driver sees only own schedule');
driverCheck($schedule->detail($driver,41)['passengers'][0]['display_name']==='ผู้โดยสารที่เกี่ยวข้อง','assigned-job detail can show only passengers tied to that own assignment');
$notFound=false;
try { $schedule->detail(new AuthenticatedPrincipal(9,1,['driver']),41); }
catch (DriverAssignmentNotFoundException) { $notFound=true; }
driverCheck($notFound && end($scheduleRepo->requestedUsers)===9,'another Driver assignment ID returns not-found and is queried with authenticated user ID');
$denied=false;
try { $schedule->listing(new AuthenticatedPrincipal(8,1,['requester'])); }
catch (AuthorizationException) { $denied=true; }
driverCheck($denied,'non-Driver cannot invoke Driver schedule service');

$linkRepo = new DriverTestLinkRepository();
$linkService = new DriverAccountLinkService($linkRepo);
$officer = new AuthenticatedPrincipal(11,1,['vehicle_officer']);
$options = $linkService->options($officer);
driverCheck($options['accounts'][0]['phone_masked']==='081****678','staff link list masks account phone and requires explicit records');
$linkService->link($officer,8,7,true);
driverCheck($linkRepo->linked===[11,8,7],'account linking uses explicit IDs and staff verification, not a phone/name match');
$denied=false;
try { $linkService->link(new AuthenticatedPrincipal(12,1,['driver']),8,7,true); }
catch (AuthorizationException) { $denied=true; }
driverCheck($denied,'Driver cannot link or alter own account mapping');

$scheduleSource=file_get_contents(dirname(__DIR__).'/app/Repositories/PdoDriverScheduleRepository.php');
driverCheck(is_string($scheduleSource) && substr_count($scheduleSource,'d.user_id = :user_id')===3 && str_contains($scheduleSource,'WHERE user_id = :user_id'),'schedule list, detail, linkage and passenger reads are scoped to session User ID');
driverCheck(is_string($scheduleSource) && !str_contains($scheduleSource,'driver_id = :driver_id'),'schedule queries never trust a Driver ID supplied by the client');

$grantSql=DriverAuthGrantPlan::grantSql();
$grantResult=GrantVerifier::evaluate($grantSql,MigrationTarget::TEST,DriverAuthGrantPlan::rules(),false,true);
driverCheck($grantResult['status']==='PASS' && $grantResult['unexpected_privileges']===[],'Driver capability grant plan matches exact least-privilege column grants');
driverCheck(!preg_match('/\b(DELETE|DROP|ALTER|CREATE|GRANT OPTION)\b/i',implode(' ',$grantSql)) && !str_contains(implode(' ',$grantSql),'lost_found'),'Driver grant plan excludes destructive/database-wide privileges');
$runtimeResult=ConnectionFactory::evaluateAuthRuntimeGrants($grantSql,false,true);
driverCheck($runtimeResult['status']==='PASS' && str_starts_with((string)$runtimeResult['profile'],'driver_'),'standard Auth connection accepts only an explicitly reviewed Driver grant profile');
$missingDriverGrant=array_values(array_filter($grantSql,static fn(string $sql):bool=>!str_contains($sql,'`drivers`')));
$missingResult=ConnectionFactory::evaluateAuthRuntimeGrants($missingDriverGrant,false,true);
driverCheck($missingResult['status']!=='PASS' || !str_starts_with((string)($missingResult['profile']??''),'driver_'),'missing Driver data privileges cannot satisfy Driver connection profile');

$migration=file_get_contents(dirname(__DIR__).'/database/drafts/010_driver_auth.sql');
driverCheck(is_string($migration) && str_contains($migration,'ADD COLUMN user_id BIGINT UNSIGNED NULL') && str_contains($migration,'UNIQUE KEY uq_drivers_user_id'),'migration drafts a unique nullable explicit Driver-to-User link');
driverCheck(is_string($migration) && str_contains($migration,"VALUES ('driver'") && str_contains($migration,'nickname VARCHAR(80)') && str_contains($migration,'MODIFY COLUMN email VARCHAR(254) NULL'),'migration adds Driver role and registration fields without requiring university email');
driverCheck(is_string($migration) && !preg_match('/UPDATE\s+drivers|JOIN\s+users.*(phone|name)/is',$migration),'migration contains no automatic account linking or personal-data matching');

// Test the existing AuthService role-constrained login path using an in-memory repository and session handler.
$passwordHash=$hasher->hash('driver-login-fixture');
$authRepo=new DriverTestAuthRepository();$authRepo->account=new AuthAccount(41,true,$passwordHash);$authRepo->state=new AuthState(41,true,1,['driver']);
$authLimiter=new LoginRateLimiter(new DriverTestRateStore(),str_repeat('l',40));$authSessions=new SessionManager();$auth=new AuthService($authRepo,$authLimiter,$hasher,$authSessions,new DriverTestEvents());
try {
    $loggedIn=$auth->login('0812345678','driver-login-fixture','127.0.0.1','driver');
    driverCheck($loggedIn->roles===['driver'] && $authRepo->lookups===['0812345678'] && $authSessions->claims()!==null,'Driver login reuses AuthService username lookup, rate limiter and secure session establishment');
} catch (Throwable $error) {
    driverCheck(false,'Driver AuthService offline path failed: '.get_class($error).' '.$error->getMessage());
}
$authSessions->invalidateAuthentication();$authRepo->state=new AuthState(41,true,1,['approver','driver']);$blocked=false;
try {$auth->login('0812345678','driver-login-fixture','127.0.0.1','driver');} catch (AuthenticationException) {$blocked=true;}
driverCheck($blocked && $authSessions->claims()===null,'Driver endpoint rejects accounts with extra roles before creating a session');
if (session_status()===PHP_SESSION_ACTIVE) session_destroy();

echo 'PASS: Driver Authentication offline regression suite completed (' . $checks . ' checks).' . PHP_EOL;
ob_end_flush();
