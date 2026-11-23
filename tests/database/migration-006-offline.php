<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
use App\Database\{MigrationPlan,MigrationRunner,SafetyException};

final class SixStatement extends PDOStatement
{
    public function __construct(private array $rows=[]){}
    public function execute(?array $params=null):bool{return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
    public function fetchColumn(int $column=0):mixed{return 'up_van_booking_test';}
}
final class SixPdo extends PDO
{
    public array $ledger=[];public bool $drift=false;
    public function __construct(){}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{return new SixStatement();}
    public function prepare(string $sql,array $options=[]):PDOStatement|false
    {
        $rows=match(true) {
            str_contains($sql,'SELECT version, checksum')=>$this->ledger,
            str_contains($sql,'INFORMATION_SCHEMA.TABLES')=>[['TABLE_NAME'=>'schema_migrations']],
            str_contains($sql,"COLUMN_NAME='registration_province'")=>[['COLUMN_NAME'=>'registration_province','COLUMN_TYPE'=>$this->drift?'text':'varchar(100)','IS_NULLABLE'=>'YES']],
            str_contains($sql,"COLUMN_NAME='requested_vehicle_id'")=>[['COLUMN_NAME'=>'requested_vehicle_id','COLUMN_TYPE'=>'bigint unsigned','IS_NULLABLE'=>'YES']],
            str_contains($sql,"INDEX_NAME='idx_booking_requested_vehicle'")=>[['INDEX_NAME'=>'idx_booking_requested_vehicle','COLUMN_NAME'=>'requested_vehicle_id','SEQ_IN_INDEX'=>1,'NON_UNIQUE'=>1]],
            str_contains($sql,'KEY_COLUMN_USAGE')=>[['CONSTRAINT_NAME'=>'fk_booking_requested_vehicle','COLUMN_NAME'=>'requested_vehicle_id','REFERENCED_TABLE_NAME'=>'vehicles','REFERENCED_COLUMN_NAME'=>'id']],
            str_contains($sql,'INFORMATION_SCHEMA.STATISTICS')=>[['INDEX_NAME'=>'uq_vehicle_registration_number','COLUMN_NAME'=>'registration_number','SEQ_IN_INDEX'=>1,'NON_UNIQUE'=>0]],
            default=>throw new RuntimeException('UNEXPECTED_OFFLINE_QUERY'),
        };
        return new SixStatement($rows);
    }
    public function exec(string $statement):int|false{throw new RuntimeException('WRITES_FORBIDDEN_IN_OFFLINE_TEST');}
}
$n=0;
$check=static function(bool $ok)use(&$n):void{if(!$ok){fwrite(STDERR,'FAIL Migration 006 offline check'.PHP_EOL);exit(1);}$n++;};
$reject=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable){return true;}};
$plan=MigrationPlan::load(true);$db=new SixPdo();
foreach(array_slice($plan,4) as $entry)$db->ledger[]=['version'=>$entry['version'],'checksum'=>$entry['sha256'],'status'=>'applied','completed_statements'=>count($entry['statements']),'total_statements'=>count($entry['statements'])];
$runner=new MigrationRunner($db,'up_van_booking_test');
$check(count($runner->status()['migrations'])===2); // 006 supersedes only the removed-column postcondition of 005.
$db->ledger[1]['status']='started';$check($reject(fn()=>$runner->status()));
$db->ledger[1]['status']='applied';$db->ledger[1]['completed_statements']=1;$check($reject(fn()=>$runner->status()));
$db->ledger[1]['completed_statements']=2;$db->drift=true;$check($reject(fn()=>$runner->status()));
$db->drift=false;$db->ledger[1]['checksum']=str_repeat('0',64);$check($reject(fn()=>$runner->status()));
$check($reject(fn()=>$runner->apply(true,null)));
$check($reject(fn()=>(new MigrationRunner($db,'up_van_booking'))->vehicle006Preflight()));
echo 'PASS: '.$n.' Migration 006 runner offline checks; no database connection or DDL.'.PHP_EOL;
