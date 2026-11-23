<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
use App\Database\{MigrationPlan,MigrationRunner,Migration006ResumeValidator as V,MigrationExecutionException,ConnectionFactory};
$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$n++;};
$reject=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable){return true;}};
$plan=MigrationPlan::load(true);$ledger=array_map(static fn(array $m):array=>['version'=>$m['version'],'checksum'=>$m['sha256'],'status'=>'applied','completed_statements'=>count($m['statements']),'total_statements'=>count($m['statements']),'failure_code'=>null],$plan);
$ledger[5]['status']='failed';$ledger[5]['completed_statements']=1;$ledger[5]['failure_code']='statement_failed';
V::assertLedger($ledger);$check(true,'exact failed 1/2 accepted');
foreach(['status'=>'applied','completed_statements'=>2,'total_statements'=>3,'checksum'=>str_repeat('0',64),'failure_code'=>null,'version'=>'wrong'] as $key=>$bad){$badLedger=$ledger;$badLedger[5][$key]=$bad;$check($reject(fn()=>V::assertLedger($badLedger)),'dirty or complete recovery state rejected');}
$badLedger=$ledger;$badLedger[0]['checksum']=str_repeat('0',64);$check($reject(fn()=>V::assertLedger($badLedger)),'baseline checksum drift rejected');
$check($reject(fn()=>V::assertLedger(array_slice($ledger,0,5))),'missing 006 rejected');
V::assertFingerprint(2,V::VEHICLE_HASH,['bookings'=>0,'assignments'=>0]);$check(true,'exact preserved fingerprint accepted');
$check($reject(fn()=>V::assertFingerprint(2,str_repeat('0',64),['bookings'=>0,'assignments'=>0])),'vehicle change rejected');
$check($reject(fn()=>V::assertFingerprint(1,V::VEHICLE_HASH,['bookings'=>0,'assignments'=>0])),'vehicle count change rejected');
$check($reject(fn()=>V::assertFingerprint(2,V::VEHICLE_HASH,['bookings'=>1,'assignments'=>0])),'booking count change rejected');
$check($reject(fn()=>V::assertBackups('nonexistent-original','nonexistent-partial',str_repeat('0',64))),'missing preserved backup rejected');

final class ResumeOfflineStatement extends PDOStatement {
    private array $rows=[];private int $affected=0;
    public function __construct(private Closure $fixture){}
    public function execute(?array $params=null):bool{[$this->rows,$this->affected]=($this->fixture)($params??[]);return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return $this->rows[0]??false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
    public function rowCount():int{return $this->affected;}
}
final class ResumeOfflinePdo extends PDO {
    public array $writes=[];public array $updates=[];public array $ledger;
    public bool $ddlFailure=false;public bool $ledgerRace=false;public bool $ledgerFailure=false;public int $port=3307;
    public function __construct(array $ledger){$this->ledger=$ledger;}
    public function query(string $sql,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        $stmt=new ResumeOfflineStatement(fn(array $p):array=>[[['port'=>$this->port,'uuid'=>'37e4d222-dda2-11f1-b503-5c2886f057d5','datadir'=>'C:/Users/User/mysql-restore-006-data/','database_name'=>'up_van_booking_test','account'=>'up_van_test_migrator@127.0.0.1']],0]);$stmt->execute();return $stmt;
    }
    public function exec(string $sql):int|false{
        $this->writes[]=$sql;
        if($this->ddlFailure){$e=new PDOException('SECRET_RAW_SQL');$e->errorInfo=['42000',1142,'SECRET_RAW_SQL'];throw $e;}
        return 0;
    }
    public function prepare(string $sql,array $options=[]):PDOStatement|false {
        return new ResumeOfflineStatement(function(array $p)use($sql):array{
            if(str_starts_with($sql,'UPDATE schema_migrations')) {
                $this->updates[]=$sql;
                if($this->ledgerFailure){$e=new PDOException('SECRET_RAW_SQL');$e->errorInfo=['HY000',2013,'SECRET_RAW_SQL'];throw $e;}
                if($this->ledgerRace)return [[],0];
                $this->ledger[5]['status']='applied';$this->ledger[5]['completed_statements']=2;$this->ledger[5]['failure_code']=null;return [[],1];
            }
            if(str_starts_with($sql,'SELECT version,checksum'))return [[$this->ledger[5]],0];
            throw new RuntimeException('UNEXPECTED_OFFLINE_QUERY');
        });
    }
}
// Exercise the SAME private statement/ledger execution seam. Production always
// supplies real postconditions after the public locked preflight; only fake PDO
// and a fake postcondition callback are used here, never a live connection/gate.
$execute=new ReflectionMethod(MigrationRunner::class,'executeVehicle006Resume');
$db=new ResumeOfflinePdo($ledger);$runner=new MigrationRunner($db,'up_van_booking_test');$verified=0;
$result=$execute->invoke($runner,$plan[5],static function()use(&$verified):void{$verified++;});
$check($result['status']==='PASS' && $verified===1,'successful resume verifies postconditions');
$check($db->writes===[$plan[5]['statements'][1]],'only pinned statement 2 executes, never statement 1');
$check(count($db->updates)===1 && $db->ledger[5]['status']==='applied' && $db->ledger[5]['completed_statements']===2,'only verified success records applied 2/2');
$check($reject(fn()=>V::assertLedger($db->ledger)),'repeated resume rejects completed ledger before DDL');
foreach(['ddl','post','ledger_race','ledger_failure'] as $failure) {
    $db=new ResumeOfflinePdo($ledger);$db->ddlFailure=$failure==='ddl';$db->ledgerRace=$failure==='ledger_race';$db->ledgerFailure=$failure==='ledger_failure';
    try{$execute->invoke(new MigrationRunner($db,'up_van_booking_test'),$plan[5],static function()use($failure):void{if($failure==='post')throw new RuntimeException('SECRET_RAW_POSTCONDITION');});$check(false,'failure must stop');}
    catch(MigrationExecutionException $e){$d=$e->diagnostics();$check($d['statement_number']===2 && $d['automatic_retry']===false && !str_contains(json_encode($d),'SECRET_RAW'),'failure has safe stage/native diagnostics');}
    $check(count($db->writes)===1 && $db->ledger[5]['status']==='failed','failure preserves failed state and never retries');
    if(in_array($failure,['ddl','post'],true))$check($db->updates===[],'failed DDL/postconditions never mark ledger');
}
$db=new ResumeOfflinePdo($ledger);$db->port=3306;
$check($reject(fn()=>$execute->invoke(new MigrationRunner($db,'up_van_booking_test'),$plan[5],static function():void{})) && $db->writes===[],'wrong actual port rejected before write');
$source=file_get_contents(dirname(__DIR__,2).'/app/Database/MigrationRunner.php');
$begin=strpos($source,'public function resumeVehicle006');$end=strpos($source,'private function executeVehicle006Resume');$outer=substr($source,$begin,$end-$begin);
$check(substr_count($outer,'$this->vehicle006ResumePreflight()')===2 && str_contains($outer,'GET_LOCK'),'locked preflight repeated immediately before execution');
$check(str_contains($outer,'requireMigrationApproval') && str_contains($outer,'assertBackups'),'approval and preserved backups required');
$check(str_contains($source,'$entry[\'statements\'][1]') && !str_contains(substr($source,$end),'$entry[\'statements\'][0]'),'resume seam cannot execute statement 1');
$cli=file_get_contents(dirname(__DIR__,2).'/bin/db-migration-006.php');
$check(str_contains($cli,'MIGRATION_006_RESUME_RESTORE_FLAG_REQUIRED') && str_contains($cli,"connect('migration_test_006_resume')"),'CLI restore flag and isolated connection purpose required');
$saved=getenv('MIGRATION_TEST_DB_PORT');putenv('MIGRATION_TEST_DB_PORT=3306');
try{$check($reject(fn()=>App\Database\Migration006RestoreIdentity::assertConfig(ConnectionFactory::config('migration_test_006_resume'))),'default 3306 config rejected without opening PDO');}finally{if($saved===false)putenv('MIGRATION_TEST_DB_PORT');else putenv('MIGRATION_TEST_DB_PORT='.$saved);}
echo "PASS: $n controlled resume offline checks; fake PDO only, no gate or database changes\n";
