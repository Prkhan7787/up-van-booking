<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,'Offline schema test failed: '.get_class($e).' line '.$e->getLine().PHP_EOL);exit(1);});
use App\Database\{MigrationPlan,MigrationRunner,Migration006GrantPlan,MigrationSchemaException};

final class SchemaSixStatement extends PDOStatement {
    private array $rows=[];
    public function __construct(private Closure $fixture){}
    public function execute(?array $params=null):bool{$this->rows=($this->fixture)($params??[]);return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
    public function fetchColumn(int $column=0):mixed{return 'up_van_booking_test';}
}
final class SchemaSixPdo extends PDO {
    public array $ledger;public bool $missingDate=false;public bool $extraRequested=false;public bool $provinceRemains=false;public bool $metadataDenied=false;public bool $indexDrift=false;public bool $typeError=false;public int $writes=0;
    public function __construct(){
        $this->ledger=array_map(static fn(array $m):array=>['version'=>$m['version'],'checksum'=>$m['sha256'],'status'=>'applied','completed_statements'=>count($m['statements']),'total_statements'=>count($m['statements'])],MigrationPlan::load());
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false {return new SchemaSixStatement(static fn(array $p):array=>[]);}
    public function exec(string $sql):int|false{$this->writes++;throw new RuntimeException('WRITES_FORBIDDEN');}
    public function prepare(string $sql,array $options=[]):PDOStatement|false {return new SchemaSixStatement(fn(array $p):array=>$this->fixture($sql,$p));}
    private function fixture(string $sql,array $p):array {
        if(str_contains($sql,'SELECT version, checksum'))return $this->ledger;
        if(str_contains($sql,'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES'))return [['TABLE_NAME'=>'schema_migrations']];
        if(($p['table']??'')==='users' && str_contains($sql,'COLUMNS'))return [
            ['COLUMN_NAME'=>'username','COLUMN_TYPE'=>'varchar(50)','IS_NULLABLE'=>'YES','CHARACTER_SET_NAME'=>'ascii','COLLATION_NAME'=>'ascii_general_ci','CHARACTER_MAXIMUM_LENGTH'=>50],
            ['COLUMN_NAME'=>'auth_version','COLUMN_TYPE'=>'bigint unsigned','IS_NULLABLE'=>'NO','COLUMN_DEFAULT'=>'1'],
        ];
        if(($p['table']??'')==='users' && str_contains($sql,'STATISTICS'))return [['INDEX_NAME'=>'uq_users_username','NON_UNIQUE'=>0,'COLUMN_NAME'=>'username']];
        if(str_contains($sql,'TABLE_CONSTRAINTS'))return [['CONSTRAINT_NAME'=>str_contains($sql,"TABLE_NAME='users'")?'chk_users_username_format':'chk_auth_login_bucket_scope']];
        if(($p['table']??'')==='auth_login_buckets' && str_contains($sql,'TABLES'))return [['ENGINE'=>'InnoDB','TABLE_COLLATION'=>'utf8mb4_unicode_ci']];
        if(($p['table']??'')==='auth_login_buckets' && str_contains($sql,'COLUMNS'))return [
            ['COLUMN_NAME'=>'bucket_scope','COLUMN_TYPE'=>'varchar(16)','IS_NULLABLE'=>'NO','CHARACTER_SET_NAME'=>'ascii','COLLATION_NAME'=>'ascii_bin','COLUMN_DEFAULT'=>null],
            ['COLUMN_NAME'=>'bucket_digest','COLUMN_TYPE'=>'binary(32)','IS_NULLABLE'=>'NO','COLUMN_DEFAULT'=>null],
            ['COLUMN_NAME'=>'window_started_at','COLUMN_TYPE'=>'bigint unsigned','IS_NULLABLE'=>'NO','COLUMN_DEFAULT'=>null],
            ['COLUMN_NAME'=>'attempt_count','COLUMN_TYPE'=>'smallint unsigned','IS_NULLABLE'=>'NO','COLUMN_DEFAULT'=>'0'],
            ['COLUMN_NAME'=>'created_at','COLUMN_TYPE'=>'timestamp','IS_NULLABLE'=>'NO','COLUMN_DEFAULT'=>'CURRENT_TIMESTAMP','EXTRA'=>'DEFAULT_GENERATED'],
            ['COLUMN_NAME'=>'updated_at','COLUMN_TYPE'=>'timestamp','IS_NULLABLE'=>'NO','COLUMN_DEFAULT'=>'CURRENT_TIMESTAMP','EXTRA'=>'DEFAULT_GENERATED on update CURRENT_TIMESTAMP'],
        ];
        if(str_contains($sql,"TABLE_NAME='vehicles'") && str_contains($sql,'COLUMNS')) {
            $names=['id','registration_number','label','passenger_capacity','is_active','created_by','updated_by','created_at','updated_at'];
            if($this->provinceRemains)array_splice($names,2,0,['registration_province']);
            return array_map(static fn(string $n):array=>['COLUMN_NAME'=>$n],$names);
        }
        if(str_contains($sql,"TABLE_NAME='vehicles'") && str_contains($sql,'STATISTICS'))return $this->indexDrift?[]:[['INDEX_NAME'=>'uq_vehicle_registration_number','COLUMN_NAME'=>'registration_number','SEQ_IN_INDEX'=>1,'NON_UNIQUE'=>0]];
        if(str_contains($sql,"TABLE_NAME='booking_requests'") && str_contains($sql,'COLUMNS')) {
            if($this->typeError)throw new TypeError('SECRET_RAW_EXCEPTION_MUST_NOT_ESCAPE');
            if($this->metadataDenied){$e=new PDOException('DO_NOT_LEAK_RAW_METADATA_EXCEPTION');$e->errorInfo=['42000',1142,'hidden'];throw $e;}
            // Independent Schema 002 column fixture, including DATE application_date.
            $names=['id','requester_id','request_number','application_date','travel_type','requester_name','requester_position','requester_affiliation','requester_phone','purpose','destination','additional_details','expense_source','expense_source_details','pickup_person_name','pickup_person_phone','pickup_location','pickup_at','dropoff_location','dropoff_at','coordinator_name','coordinator_phone','requested_start_at','requested_end_at','passenger_count','workflow_status','submitted_at','cancellation_requested_by','cancellation_contacted_at','cancelled_at','cancelled_by','cancellation_reason','created_by','updated_by','version','created_at','updated_at'];
            if($this->missingDate)$names=array_values(array_diff($names,['application_date']));
            if($this->extraRequested)array_splice($names,2,0,['requested_vehicle_id']);
            return array_map(static fn(string $n):array=>['COLUMN_NAME'=>$n],$names);
        }
        if(str_contains($sql,'STATISTICS') || str_contains($sql,'KEY_COLUMN_USAGE') || str_contains($sql,'GROUP BY registration_number'))return [];
        throw new RuntimeException('UNEXPECTED_FIXTURE_QUERY');
    }
}
$n=0;$check=static function(bool $b,string $label)use(&$n):void{if(!$b)throw new RuntimeException('FAIL '.$label);$n++;};
$db=new SchemaSixPdo();$result=(new MigrationRunner($db,'up_van_booking_test'))->vehicle006Preflight();
$check($result['status']==='PASS','full schema 005 with application_date DATE passes real runner');
$check(Migration006GrantPlan::schemaPhase($db)==='pre','full phase detection passes through actual Runner/Validators');
foreach(['missingDate'=>'MIGRATION_006_BOOKING_METADATA_INCOMPLETE_OR_DRIFT','extraRequested'=>'MIGRATION_006_BOOKING_METADATA_INCOMPLETE_OR_DRIFT','provinceRemains'=>'MIGRATION_005_PROVINCE_REMAINS','indexDrift'=>'MIGRATION_005_REGISTRATION_INDEX_DRIFT'] as $flag=>$code) {
    $db=new SchemaSixPdo();$db->$flag=true;
    try {Migration006GrantPlan::schemaPhase($db);$check(false,'schema drift must fail');}
    catch(MigrationSchemaException $e){$check($e->getMessage()===$code,'specific schema failure code');}
    $check($db->writes===0,'schema drift never writes');
}
$db=new SchemaSixPdo();$db->metadataDenied=true;
try{Migration006GrantPlan::schemaPhase($db);$check(false,'PDO must fail');}catch(PDOException $e){$check($e->errorInfo[1]===1142,'PDO error preserves safe SQLSTATE/native classification');}
foreach(['checksum'=>str_repeat('0',64),'total_statements'=>99,'status'=>'failed','version'=>'wrong'] as $key=>$bad) {
    $db=new SchemaSixPdo();$db->ledger[0][$key]=$bad;
    try{Migration006GrantPlan::schemaPhase($db);$check(false,'ledger mismatch must fail');}catch(MigrationSchemaException $e){$check($e->schemaCheck==='LEDGER','ledger mismatch has safe ledger label');}
}
$sql=file_get_contents(dirname(__DIR__,2).'/scripts/migration-006-schema-ledger-readonly.sql');
$statements=App\Database\SqlStatements::split($sql);
$check(count($statements)===8 && array_all($statements,static fn(string $s):bool=>str_starts_with($s,'SELECT ')),'operator SQL file contains SELECT statements only');
$db=new SchemaSixPdo();$db->typeError=true;
try{Migration006GrantPlan::schemaPhase($db);$check(false,'type error must fail');}catch(MigrationSchemaException $e){
    $check($e->getMessage()==='SCHEMA_PHP_TYPE_ERROR' && $e->schemaCheck==='BOOKING_COLUMNS','non-RuntimeException carries precise schema query label');
    $check($e->origin['error_type']==='TypeError' && !str_contains(json_encode($e->origin),'SECRET_RAW'),'type diagnostics exclude raw exception');
}
$runner=new MigrationRunner(new SchemaSixPdo(),'up_van_booking_test');
foreach([new Error('SECRET_RAW'),new ValueError('SECRET_RAW'),new ErrorException('SECRET_RAW')] as $err){
    $wrapped=MigrationSchemaException::fromFailure($err,$runner);
    $check(!str_contains($wrapped->getMessage(),'SECRET_RAW') && in_array($wrapped->schemaCheck,['RUNNER_STATUS','LEDGER'],true),'all throwable kinds safely wrapped without permitting schema');
}
echo "PASS: $n schema 005/006 preflight regression checks; fake PDO only\n";
