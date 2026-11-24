<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
use App\Database\{DriverDatabaseIdentity,Migration010SchemaSnapshot,MigrationPlan,MigrationRunner,MigrationTarget};
$checks=0;
function snapshotCheck(bool $ok,string $label):void { global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS: '.$label,PHP_EOL; }
function snapshotReject(callable $fn):bool {try{$fn();return false;}catch(Throwable){return true;}}
require __DIR__.'/fixtures/migration010-schema.php';
final class SnapshotLiveStatement extends PDOStatement {
 public function __construct(private array $data,private bool $scalar=false){}
 public function execute(?array $params=null):bool{return true;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->data;}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->data[0];}
 public function fetchColumn(int $column=0):mixed{return MigrationTarget::TEST;}
}
final class SnapshotLivePdo extends PDO {
 public array $queries=[];
 public function __construct(){}
 public function prepare(string $sql,array $options=[]):PDOStatement|false {
  $this->queries[]=$sql;
  if(str_contains($sql,'FROM schema_migrations'))return new SnapshotLiveStatement(array_map(static fn($e)=>['version'=>$e['version'],'checksum'=>$e['sha256'],'status'=>'applied','completed_statements'=>$e['statement_count'],'total_statements'=>$e['statement_count']],array_slice(MigrationPlan::driver010LedgerEntries(),0,9)));
  if(str_contains($sql,'FROM roles'))return new SnapshotLiveStatement(array_map(static fn($r)=>['name'=>$r,'description'=>$r],['admin','approver','requester','vehicle_officer']));
  throw new RuntimeException('unexpected live read');
 }
 public function query(string $sql,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
  $this->queries[]=$sql;
  if($sql==='SELECT DATABASE()')return new SnapshotLiveStatement([]);
  if(str_contains($sql,'@@server_uuid'))return new SnapshotLiveStatement([['port'=>3306,'uuid'=>DriverDatabaseIdentity::UUID,'database_name'=>MigrationTarget::TEST,'account_name'=>'up_van_migration_preflight@127.0.0.1']]);
  throw new RuntimeException('unexpected live read');
 }
}
$sql=snapshotSql();$snapshot=new Migration010SchemaSnapshot($sql);$live=new SnapshotLivePdo;
$result=(new MigrationRunner($snapshot->connection($live),MigrationTarget::TEST))->driver010Inspect();
snapshotCheck($result['status']==='PASS','snapshot metadata passes unchanged 010 and baseline 004-008 validators with live ledger/roles fakes');
snapshotCheck(count(array_filter($live->queries,static fn($q)=>preg_match('/FROM (?:users|drivers|booking_requests|auth_login_buckets|vehicles)\b/i',$q)))===0,'no application rows or password hashes are requested');
foreach([
 'nullable email'=>str_replace('`email` varchar(254) NOT NULL','`email` varchar(254) DEFAULT NULL',$sql),
 'missing unique'=>str_replace("  UNIQUE KEY `uq_users_username` (`username`),\n",'',$sql),
 'wrong baseline type'=>str_replace('`registration_province` varchar(100)','`registration_province` varchar(90)',$sql),
 'missing requested FK'=>str_replace('`fk_booking_requested_vehicle`','`unexpected_fk`',$sql),
 'wrong A4 type'=>str_replace('`a4_request_data` json','`a4_request_data` text',$sql),
 'missing auth metadata'=>str_replace('`attempt_count` smallint unsigned','`attempt_count` int unsigned',$sql),
 'missing username check'=>str_replace('`chk_users_username_format`','`renamed_users_check`',$sql),
 'missing rate scope check'=>str_replace('`chk_auth_login_bucket_scope`','`renamed_scope_check`',$sql),
 'partial driver link'=>str_replace('`contact_phone` varchar(30) DEFAULT NULL,','`contact_phone` varchar(30) DEFAULT NULL,' . "\n  `user_id` bigint unsigned DEFAULT NULL,",$sql),
] as $label=>$drift){
 snapshotCheck(snapshotReject(static fn()=>(new MigrationRunner((new Migration010SchemaSnapshot($drift))->connection(new SnapshotLivePdo),MigrationTarget::TEST))->driver010Inspect()),'schema drift fails closed: '.$label);
}
foreach(['INSERT INTO users VALUES (1);','CREATE USER test;','/*!80000 INSERT INTO users VALUES (1) */;','CREATE VIEW v AS SELECT 1;'] as $unsafe){
 snapshotCheck(snapshotReject(static fn()=>new Migration010SchemaSnapshot($sql.$unsafe)),'non-schema SQL is rejected without execution');
}
snapshotCheck(snapshotReject(static fn()=>new Migration010SchemaSnapshot(str_replace('`password_hash` varchar(255) DEFAULT NULL',"`password_hash` varchar(255) DEFAULT 'offline-unreviewed-example'",$sql))),'unreviewed sensitive default is rejected without revealing its value');
snapshotCheck(snapshotReject(static fn()=>new Migration010SchemaSnapshot(str_replace('CREATE TABLE `roles`','CREATE TABLE `other_roles`',$sql))),'missing or unexpected table rejected');
snapshotCheck(snapshotReject(static fn()=>(new MigrationRunner($snapshot->connection(new SnapshotLivePdo),MigrationTarget::TEST))->driver010Inspect(true)),'baseline snapshot and ledger cannot pass postflight');
$tmp=sys_get_temp_dir().'/upvan-schema-fixture-'.bin2hex(random_bytes(6));mkdir($tmp);
try {
 $manifest=['format'=>'upvan-schema-only-v1','tables'=>Migration010SchemaSnapshot::TABLES,'host'=>'127.0.0.1','port'=>3306,'database'=>MigrationTarget::TEST,'uuid'=>DriverDatabaseIdentity::UUID,'version'=>'8.4.9','export_started_utc'=>'2026-11-24T00:00:00Z','export_finished_utc'=>'2026-11-24T00:00:01Z','sha256'=>hash('sha256',$sql)];
 file_put_contents($tmp.'/schema.sql',$sql);file_put_contents($tmp.'/manifest.json',json_encode($manifest));
 snapshotCheck(Migration010SchemaSnapshot::load($tmp) instanceof Migration010SchemaSnapshot,'external schema and identity manifest SHA-256 accepted');
 $evidence=Migration010SchemaSnapshot::load($tmp)->evidence();
 snapshotCheck($evidence['live_schema_verified']===false && $evidence['apply_ready']===false && $evidence['snapshot_sha256']===hash('sha256',$sql),'snapshot evidence is tied to parsed bytes and never claims live schema or Apply readiness');
 foreach(['uuid'=>'wrong','database'=>'up_van_booking','sha256'=>str_repeat('0',64),'export_finished_utc'=>'invalid'] as $key=>$value){
  file_put_contents($tmp.'/manifest.json',json_encode(array_replace($manifest,[$key=>$value])));
  snapshotCheck(snapshotReject(static fn()=>Migration010SchemaSnapshot::load($tmp)),'manifest mismatch fails closed: '.$key);
 }
}finally{unlink($tmp.'/schema.sql');unlink($tmp.'/manifest.json');rmdir($tmp);}
echo "PASS: $checks schema snapshot offline checks; no database contacted.",PHP_EOL;
