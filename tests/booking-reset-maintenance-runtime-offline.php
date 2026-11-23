<?php
declare(strict_types=1);
define('BOOKING_RESET_RUNNER_TEST',true);
require dirname(__DIR__).'/scripts/booking-reset-runner.php';

use App\Database\ConnectionFactory;
use App\Database\SafetyException;

set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);});
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$vars=['HOST'=>'127.0.0.1','PORT'=>'3306','DATABASE'=>RESET_DB,'USERNAME'=>'up_van_reset_maintenance','PASSWORD'=>'offline-only'];
foreach($vars as $key=>$value)putenv('RESET_MAINTENANCE_DB_'.$key.'='.$value);
$config=ConnectionFactory::config('reset_maintenance');
$check($config['host']==='127.0.0.1' && $config['port']==='3306' && $config['database']===RESET_DB && $config['username']==='up_van_reset_maintenance','Maintenance purpose is pinned');
foreach(['HOST'=>'localhost','PORT'=>'3307','DATABASE'=>'another_db','USERNAME'=>'up_van_auth_test'] as $key=>$wrong){
    putenv('RESET_MAINTENANCE_DB_'.$key.'='.$wrong);
    try{ConnectionFactory::config('reset_maintenance');throw new LogicException('Bad config accepted: '.$key);}
    catch(SafetyException){$check(true,'Bad config rejected: '.$key);}
    putenv('RESET_MAINTENANCE_DB_'.$key.'='.$vars[$key]);
}
foreach(array_keys($vars) as $key)putenv('RESET_MAINTENANCE_DB_'.$key);

final class ResetIdentityStatement extends PDOStatement {
    public function __construct(private array $row){}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed{return $this->row;}
}
final class ResetIdentityPdo extends PDO {
    public function __construct(private array $row){}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {
        if($query!=='SELECT @@hostname host,@@port port,@@server_uuid uuid,DATABASE() db,VERSION() version,CURRENT_USER() account')throw new LogicException('Unexpected identity query');
        return new ResetIdentityStatement($this->row);
    }
}
$identity=['host'=>'test-host','port'=>3306,'uuid'=>RESET_UUID,'db'=>RESET_DB,'version'=>'8.4.9','account'=>'up_van_reset_maintenance@127.0.0.1'];
$check(resetIdentity(new ResetIdentityPdo($identity))===$identity,'Expected maintenance identity accepted');
foreach(['port'=>3307,'uuid'=>'wrong','db'=>'wrong','account'=>'up_van_auth_test@127.0.0.1'] as $key=>$wrong){
    $bad=$identity;$bad[$key]=$wrong;
    try{resetIdentity(new ResetIdentityPdo($bad));throw new LogicException('Bad identity accepted: '.$key);}
    catch(RuntimeException $e){$check($e->getMessage()==='DATABASE_IDENTITY_REJECTED','Bad identity rejected: '.$key);}
}
$absent=['exit_code'=>0,'stdout'=>"LISTENER_ABSENT\r\n",'stderr'=>'','timed_out'=>false];
resetMaintenanceWindow(static fn():array=>$absent);$check(true,'Successful OS table query proves no listener');
$present=$absent;$present['stdout']="LISTENER_PRESENT\r\n";
$failed=$absent;$failed['exit_code']=1;
$denied=$absent;$denied['stderr']='Access denied';
$invalid=$absent;$invalid['stdout']='unknown';
$timeout=$absent;$timeout['timed_out']=true;
$empty=$absent;$empty['stdout']='';
$extra=$absent;$extra['stdout']="LISTENER_ABSENT\nextra\n";
foreach([[$present,'MAINTENANCE_WEB_LISTENER_ACTIVE'],...array_map(static fn($r)=>[$r,'MAINTENANCE_LISTENER_CHECK_UNVERIFIED'],[$failed,$denied,$invalid,$timeout,$empty,$extra,[false,10060],[false,10013],[false,10061]])] as [$result,$reason]){
    try{resetMaintenanceWindow(static fn():array=>$result);throw new LogicException('Bad maintenance window accepted');}
    catch(RuntimeException $e){$check($e->getMessage()===$reason,'Maintenance window fails closed');}
}
final class ResetNoTransactionPdo extends PDO {
    public function __construct(){}
    public function inTransaction(): bool{return false;}
}
try{resetViewReadInsideTransaction(new ResetNoTransactionPdo(),'hash');throw new LogicException('View read outside transaction accepted');}
catch(RuntimeException $e){$check($e->getMessage()==='CALENDAR_VIEW_TRANSACTION_REQUIRED','View MDL requires transaction');}
$source=file_get_contents(dirname(__DIR__).'/scripts/booking-reset-runner.php');
$check(is_string($source) && str_contains($source,'resetViewReadInsideTransaction($db,$viewDefinition)') && str_contains($source,'$db->rollBack()'),'View read and rollback stay in rehearsal');
$check(is_string($source) && str_contains($source,"if(\$mode==='apply')") && substr_count($source,'$db->commit()')===1,'Commit exists only for the explicit Apply branch');
echo "PASS: booking reset maintenance runtime offline ($checks checks); no MySQL connection made.\n";
