<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
use App\Database\{MicrosoftIdentityGrantPlan,GrantVerifier,ConnectionFactory,SqlStatements};
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$root=dirname(__DIR__);
$sql=file_get_contents($root.'/database/proposals/011_microsoft_identities.proposed.sql');
$check(count(SqlStatements::split($sql))===1,'one isolated CREATE TABLE statement');
$check(str_contains($sql,'PRIMARY KEY (user_id)')&&str_contains($sql,'UNIQUE KEY uq_microsoft_identity (tenant_id, object_id)'),'one identity per user and unique tenant/object pair');
preg_match_all('/REFERENCES\s+([a-z_]+)\(id\)/',$sql,$references);
$check($references[1]===['users','users'],'both foreign keys reference existing users, no Driver dependency');
$check(str_contains($sql,'user_id BIGINT UNSIGNED NOT NULL')&&str_contains($sql,'created_by BIGINT UNSIGNED NOT NULL'),'FK types match users.id');
$check(str_contains($sql,'CHARACTER SET ascii COLLATE ascii_bin')&&str_contains($sql,'chk_microsoft_nonzero_uuid'),'canonical UUID storage and nonzero guard');
$check(count(App\Database\MigrationPlan::load(include009:true))===9 && count(App\Database\MigrationPlan::microsoft011()['dependencies'])===9,'011 is an explicit independent branch; ordered apply path remains unchanged');
$rules=MicrosoftIdentityGrantPlan::rules();
$check($rules['tables']['microsoft_identities']['SELECT']===['user_id','tenant_id','object_id'],'lookup SELECT includes only used identifiers');
$check(!isset($rules['tables']['microsoft_identities']['UPDATE'])&&$rules['database']===[],'no mapping UPDATE or database-level privileges');
$grants=[];
foreach($rules['tables'] as $table=>$privileges)foreach($privileges as $privilege=>$columns){
    $columnSql=$columns===null?'':' (`'.implode('`,`',$columns).'`)';
    $grants[]='GRANT '.$privilege.$columnSql.' ON `up_van_booking_test`.`'.$table.'` TO \'up_van_auth_test\'@\'127.0.0.1\'';
}
$check(ConnectionFactory::evaluateAuthRuntimeGrants($grants,false,true)['status']==='PASS','complete Admin mapping profile accepted');
$bad=[...$grants,"GRANT UPDATE ON `up_van_booking_test`.`microsoft_identities` TO 'up_van_auth_test'@'127.0.0.1'"];
$check(ConnectionFactory::evaluateAuthRuntimeGrants($bad,false,true)['status']==='FAIL','unreviewed mapping UPDATE rejected');
$missing=array_values(array_filter($grants,static fn(string $s):bool=>!str_starts_with($s,'GRANT INSERT')||!str_contains($s,'`microsoft_identities`')));
$check(GrantVerifier::evaluate($missing,'up_van_booking_test',$rules,false,true)['status']==='FAIL','missing Admin INSERT cannot enable mutations');
final class MissingMappingTablePdo extends PDO
{
    public function __construct(){}
    public function getAttribute(int $attribute):mixed{return match($attribute){PDO::ATTR_DRIVER_NAME=>'mysql',PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,default=>null};}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{throw new PDOException('offline missing mapping table');}
}
$thrown=false;
try{new App\Repositories\PdoMicrosoftIdentityRepository(new MissingMappingTablePdo());}catch(PDOException){$thrown=true;}
$check($thrown,'PDO adapter rejects missing table before exposing repository');
echo 'PASS: '.$checks.' Microsoft Identity schema/grant offline checks; no database'.PHP_EOL;
