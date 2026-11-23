<?php
declare(strict_types=1);
// Reuse independent Schema 005/M004 metadata fixtures; this file opens no PDO.
require __DIR__.'/migration-006-schema-preflight-offline.php';
use App\Database\{MigrationPlan,MigrationRunner,Restore006MigratorPreparation,Migration006ResumeValidator};
final class ResumeMetaStatement extends PDOStatement {
    public function __construct(private array $rows,private mixed $value=null){}
    public function execute(?array $params=null):bool{return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return $this->rows[0]??false;}
    public function fetchColumn(int $column=0):mixed{return $this->value;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
}
final class ResumeMetaPdo extends PDO {
    public SchemaSixPdo $base;public array $grants;public array $ledger;
    public int $port=3307;public string $provinceType='varchar(100)';public bool $newIndex=false;public bool $wrongFkSchema=false;
    public int $dataReads=0;public int $writes=0;
    public function __construct(){
        $this->base=new SchemaSixPdo();$this->base->provinceRemains=true;
        $this->grants=Restore006MigratorPreparation::plan(dirname(__DIR__,2).'/scripts/migration-006-schema005-migrator-grants.proposed.sql');
        $this->ledger=$this->base->ledger;
        $this->ledger[]=['version'=>MigrationPlan::VEHICLE006['version'],'checksum'=>MigrationPlan::VEHICLE006['sha256'],'status'=>'failed','completed_statements'=>1,'total_statements'=>2,'failure_code'=>'statement_failed'];
    }
    public function query(string $sql,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        if(str_starts_with($sql,'SELECT id,registration_number')){$this->dataReads++;return new ResumeMetaStatement([['id'=>1],['id'=>2]]);}
        if(str_starts_with($sql,'SHOW GRANTS'))return new ResumeMetaStatement($this->grants);
        if(str_contains($sql,'CURRENT_ROLE'))return new ResumeMetaStatement([],'NONE');
        if(str_contains($sql,'@@GLOBAL.partial_revokes'))return new ResumeMetaStatement([],0);
        return new ResumeMetaStatement([['port'=>$this->port,'uuid'=>'37e4d222-dda2-11f1-b503-5c2886f057d5','datadir'=>'C:/Users/User/mysql-restore-006-data/','database_name'=>'up_van_booking_test','account'=>'up_van_test_migrator@127.0.0.1']]);
    }
    public function exec(string $sql):int|false{$this->writes++;throw new RuntimeException('WRITES_FORBIDDEN');}
    public function prepare(string $sql,array $options=[]):PDOStatement|false {
        if(str_starts_with($sql,'SELECT version,checksum'))return new ResumeMetaStatement($this->ledger);
        if(str_contains($sql,"COLUMN_NAME='registration_province'"))return new ResumeMetaStatement([['COLUMN_NAME'=>'registration_province','COLUMN_TYPE'=>$this->provinceType,'IS_NULLABLE'=>'YES']]);
        if(str_contains($sql,'COLUMN_KEY'))return new ResumeMetaStatement([['COLUMN_TYPE'=>'bigint unsigned','IS_NULLABLE'=>'NO','COLUMN_KEY'=>'PRI']]);
        if(str_contains($sql,"INDEX_NAME='idx_booking_requested_vehicle'"))return new ResumeMetaStatement($this->newIndex?[['INDEX_NAME'=>'idx_booking_requested_vehicle']]:[]);
        if(str_contains($sql,'CONSTRAINT_SCHEMA=:db'))return new ResumeMetaStatement([]);
        if(str_contains($sql,'KEY_COLUMN_USAGE')) {
            $names=[['fk_booking_cancel_actor','cancelled_by'],['fk_booking_cancel_requester','cancellation_requested_by'],['fk_booking_creator','created_by'],['fk_booking_editor','updated_by'],['fk_booking_requester','requester_id']];
            return new ResumeMetaStatement(array_map(fn(array $n):array=>['CONSTRAINT_NAME'=>$n[0],'COLUMN_NAME'=>$n[1],'REFERENCED_TABLE_SCHEMA'=>$this->wrongFkSchema?'up_van_booking':'up_van_booking_test','REFERENCED_TABLE_NAME'=>'users','REFERENCED_COLUMN_NAME'=>'id'],$names));
        }
        if(str_contains($sql,"TABLE_NAME IN ('vehicles','booking_requests')"))return new ResumeMetaStatement([['TABLE_NAME'=>'booking_requests','ENGINE'=>'InnoDB'],['TABLE_NAME'=>'vehicles','ENGINE'=>'InnoDB']]);
        if(str_starts_with($sql,'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES')) {
            $names=['schema_migrations'];foreach(MigrationPlan::load(true) as $m)$names=array_merge($names,$m['objects']);
            return new ResumeMetaStatement(array_map(static fn(string $name):array=>['TABLE_NAME'=>$name],$names));
        }
        if(str_contains($sql,'INFORMATION_SCHEMA.SCHEMATA'))return new ResumeMetaStatement([['DEFAULT_CHARACTER_SET_NAME'=>'utf8mb4','DEFAULT_COLLATION_NAME'=>'utf8mb4_unicode_ci']]);
        if(str_starts_with($sql,'SELECT id,registration_number')){$this->dataReads++;return new ResumeMetaStatement([['id'=>1],['id'=>2]]);}
        if(str_contains($sql,'AS bookings'))return new ResumeMetaStatement([['bookings'=>0,'assignments'=>0]]);
        return $this->base->prepare($sql,$options);
    }
}
$n=0;$check=static function(bool $ok,string $name)use(&$n):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$n++;};
$db=new ResumeMetaPdo();
try{(new MigrationRunner($db,'up_van_booking_test'))->vehicle006ResumePreflight();$check(false,'dummy vehicle data must fail fingerprint');}
catch(App\Database\SafetyException $e){$check($e->getMessage()==='MIGRATION_006_RESUME_DATA_CHANGED' && $db->dataReads===1,'complete failed 1/2 schema/ledger/grants guards reach exact fingerprint boundary');}
$check($db->writes===0,'resume preflight never writes');
foreach(['provinceType'=>'text','newIndex'=>true,'wrongFkSchema'=>true,'port'=>3306] as $property=>$bad){
    $db=new ResumeMetaPdo();$db->$property=$bad;
    try{(new MigrationRunner($db,'up_van_booking_test'))->vehicle006ResumePreflight();$check(false,'drift must fail');}catch(Throwable){$check($db->writes===0 && $db->dataReads===0,'identity/province/index/FK drift rejected before fingerprint/write');}
}
$db=new ResumeMetaPdo();$db->ledger[5]['status']='applied';$db->ledger[5]['completed_statements']=2;
try{(new MigrationRunner($db,'up_van_booking_test'))->vehicle006ResumePreflight();$check(false,'repeat must fail');}catch(App\Database\SafetyException $e){$check($e->getMessage()==='MIGRATION_006_RESUME_LEDGER_REJECTED' && $db->writes===0,'public preflight rejects already applied state');}
echo "PASS: $n resume metadata/preflight offline checks; fake PDO only\n";
