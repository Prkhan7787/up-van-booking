<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
use App\Database\{Restore006MigratorPreparation as Prep,ConnectionFactory,SafetyException};
final class AccountFixtureStatement extends PDOStatement {
    public function __construct(private mixed $value){}
    public function execute(?array $params=null):bool{return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return $this->value;}
    public function fetchColumn(int $column=0):mixed{return $this->value;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->value;}
}
final class AccountFixturePdo extends PDO {
    public array $identity;public array $hosts=[];public array $writes=[];public array $reads=[];
    public int $failWrite=0;public int $lock=1;
    public function __construct(bool $root=true) {
        $this->identity=['port'=>3307,'uuid'=>'37e4d222-dda2-11f1-b503-5c2886f057d5','datadir'=>'C:/Users/User/mysql-restore-006-data/','database_name'=>'up_van_booking_test','account'=>$root?'root@localhost':'up_van_test_migrator@127.0.0.1','partial_revokes'=>0,'version'=>'8.4.9'];
    }
    public function query(string $sql,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        $this->reads[]=$sql;
        return new AccountFixtureStatement(str_contains($sql,'GET_LOCK')?$this->lock:(str_contains($sql,'RELEASE_LOCK')?1:$this->identity));
    }
    public function prepare(string $sql,array $options=[]):PDOStatement|false {
        if(!str_starts_with($sql,'SELECT Host FROM mysql.user')) throw new RuntimeException('UNEXPECTED_QUERY');
        return new AccountFixtureStatement($this->hosts);
    }
    public function quote(string $s,int $type=PDO::PARAM_STR):string|false {return "'".str_replace("'","''",$s)."'";}
    public function exec(string $sql):int|false {
        $this->writes[]=$sql;
        if($this->failWrite===count($this->writes)){$e=new PDOException('SECRET_MUST_NEVER_ESCAPE');$e->errorInfo=['42000',1142,'SECRET_MUST_NEVER_ESCAPE'];throw $e;}
        if(str_starts_with($sql,'CREATE USER'))$this->hosts=['127.0.0.1'];
        return 0;
    }
}
$n=0;$check=static function(bool $b,string $name)use(&$n):void{if(!$b)throw new RuntimeException('OFFLINE_FAIL '.$name);$n++;};
$grants=Prep::plan(dirname(__DIR__,2).'/scripts/migration-006-schema005-migrator-grants.proposed.sql');
$config=['host'=>'127.0.0.1','port'=>'3306','database'=>'up_van_booking_test','username'=>'up_van_test_migrator','password'=>"OFFLINE_ONLY_secret'\\value"];
$fixture=static function(AccountFixturePdo $root,?Closure $schema=null,?Closure $connect=null)use($grants):Prep {
    $migrator=new AccountFixturePdo(false);
    return new Prep($connect??static fn(string $u,string $p):PDO=>$u==='root'?$root:$migrator,$schema??static fn(PDO $p):string=>'pre',static function(PDO $p)use($grants):void{ConnectionFactory::validateMigration006Grants($grants,false,true,'pre');});
};
$root=new AccountFixturePdo();$r=$fixture($root)->run('preflight',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
$check($r['status']==='PASS' && $root->writes===[],'preflight read-only');
$check($r['credential_verified']===false,'presence is not authentication success');
$root=new AccountFixturePdo();$r=$fixture($root)->run('execute',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
$check($r['status']==='PASS' && $r['credential_verified'],'execute fake postflight verifies credentials');
$check(count($root->writes)===8 && $r['grants_completed']===7,'exactly one create and seven approved grants');
$check($r['creation_state']==='CONFIRMED' && $r['migration_applied']===false,'account only, no migration');
$check($root->writes[0]==="CREATE USER 'up_van_test_migrator'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY ".$root->quote($config['password']),'password is quoted with PDO, not interpolated raw');
$check(!str_contains(json_encode($r),'OFFLINE_ONLY') && !str_contains(json_encode($r),'ROOT_OFFLINE'),'secrets absent from success report');
$root=new AccountFixturePdo();$root->hosts=['127.0.0.1'];
$r=$fixture($root)->run('postflight',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
$check($r['status']==='PASS' && $root->writes===[],'postflight read-only');
$r=$fixture($root)->run('execute',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
$check($r['status']==='FAIL' && $root->writes===[],'existing account stops, no IF NOT EXISTS or password reset');
foreach(['port'=>3306,'uuid'=>'wrong','datadir'=>'C:/Users/User/mysql-data','account'=>'root@other','partial_revokes'=>1,'version'=>'8.0.0'] as $key=>$bad) {
    $root=new AccountFixturePdo();$root->identity[$key]=$bad;
    $r=$fixture($root)->run('execute',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
    $check($r['status']==='FAIL' && $root->writes===[],'identity rejection '.$key);
}
$root=new AccountFixturePdo();$root->lock=0;
$r=$fixture($root)->run('execute',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
$check($r['code']==='ACCOUNT_LOCK_UNAVAILABLE' && $root->writes===[],'lock contention no mutation');
$root=new AccountFixturePdo();$r=$fixture($root,static fn(PDO $p):string=>'post')->run('execute',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
$check($r['status']==='FAIL' && $root->writes===[],'schema 006 rejected for schema 005 preparation');
$calls=0;$root=new AccountFixturePdo();
$r=$fixture($root,static function(PDO $p)use(&$calls):string{$calls++;return $calls===1?'pre':'post';})->run('execute',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
$check($r['status']==='FAIL' && $root->writes===[],'schema race caught before create');
$root=new AccountFixturePdo();$root->failWrite=1;$r=$fixture($root)->run('execute',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
$check($r['creation_state']==='ATTEMPTED_RESULT_UNKNOWN' && count($root->writes)===1,'ambiguous create failure never claims unchanged');
$root=new AccountFixturePdo();$root->failWrite=3;$r=$fixture($root)->run('execute',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
$check($r['creation_state']==='CONFIRMED' && $r['grants_completed']===1 && count($root->writes)===3,'partial grants stop without rollback/drop/retry');
$check($r['sqlstate']==='42000' && $r['native_code']===1142 && !str_contains(json_encode($r),'SECRET_MUST_NEVER_ESCAPE'),'safe PDO diagnostics');
$root=new AccountFixturePdo();$r=$fixture($root,null,static function(string $u,string $p)use($root):PDO{if($u==='root')return $root;$e=new PDOException('SECRET_MUST_NEVER_ESCAPE');$e->errorInfo=['HY000',1045,'hidden'];throw $e;})->run('execute',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
$check($r['step']==='MIGRATOR_AUTH' && $r['native_code']===1045 && $r['grants_completed']===7,'credential mismatch after grant completion stays explicit');
$root=new AccountFixturePdo();$r=$fixture($root)->run('execute','wrong',$config,'ROOT_OFFLINE',$grants);
$check($r['status']==='FAIL' && $root->reads===[] && $root->writes===[],'confirmation rejected before connection');
$source=file_get_contents(dirname(__DIR__,2).'/scripts/prepare-3307-migrator-account.php');
$check(str_contains($source,'port=3307;dbname=up_van_booking_test') && !str_contains($source,'port=3306'),'CLI transport hard pinned to 3307');
$check(str_contains($source,'PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false'),'multiple statements disabled');
$check(str_contains($source,'stream_get_contents(STDIN') && !str_contains($source,'--password='),'password via stdin only');
$check(!str_contains(file_get_contents(dirname(__DIR__,2).'/app/Database/Restore006MigratorPreparation.php'),'->apply('),'no migration apply path');
Prep::assertRuntime();$check(true,'actual project PDO MySQL/mbstring runtime ready');
foreach([['HY000',1045,'AUTHENTICATION_FAILURE'],['28000',1045,'AUTHENTICATION_FAILURE'],['HY000',2002,'CONNECTION_FAILURE'],['HY000',2003,'CONNECTION_FAILURE'],['42000',1049,'DATABASE_SELECTION_FAILURE']] as [$state,$native,$type]) {
    $root=new AccountFixturePdo();
    $r=$fixture($root,null,static function(string $u,string $p)use($state,$native):PDO{$e=new PDOException('SECRET_MUST_NEVER_ESCAPE');$e->errorInfo=[$state,$native,'hidden'];throw $e;})->run('preflight',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
    $check($r['step']==='ROOT_CONNECT' && $r['failure_type']===$type && $r['native_code']===$native,'safe root connection classification');
    $check($r['creation_state']==='NOT_ATTEMPTED' && $r['grants_completed']===0 && !str_contains(json_encode($r),'SECRET_MUST_NEVER_ESCAPE'),'failed read-only root connect has no mutation or raw error');
}
$r=$fixture(new AccountFixturePdo(),null,static function(string $u,string $p):PDO{throw new Error('undefined dummy driver constant SECRET_MUST_NEVER_ESCAPE');})->run('preflight',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
$check($r['failure_type']==='PHP_RUNTIME_FAILURE' && $r['creation_state']==='NOT_ATTEMPTED','non-PDO runtime error no longer mistaken for authentication failure');
$root=new AccountFixturePdo();
$r=$fixture($root,static function(PDO $p):string{throw new App\Database\MigrationSchemaException('MIGRATION_006_LEDGER_CHECKSUM_MISMATCH','LEDGER','002_booking_design.checksum');})->run('preflight',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
$check($r['step']==='SCHEMA_LEDGER' && $r['code']==='MIGRATION_006_LEDGER_CHECKSUM_MISMATCH' && $r['schema_field']==='002_booking_design.checksum','schema error reaches service with safe field detail');
$check($root->writes===[] && $r['creation_state']==='NOT_ATTEMPTED','schema failure stops preflight before create/grant');
$root=new AccountFixturePdo();
$r=$fixture($root,static function(PDO $p):string{throw new TypeError('SECRET_RAW_METADATA');})->run('preflight',Prep::CONFIRM,$config,'ROOT_OFFLINE',$grants);
$check($r['step']==='SCHEMA_LEDGER' && $r['code']==='SCHEMA_UNCLASSIFIED_FAILURE' && $r['error_type']==='TypeError','last-resort schema diagnostic distinguishes previously hidden throwable');
$check($r['diagnostic_revision']==='SCHEMA006_V3' && !str_contains(json_encode($r),'SECRET_RAW_METADATA') && $root->writes===[],'revision and no-secret/no-mutation failure contract');
echo "PASS: $n restore migrator account offline checks; fake PDO only\n";
