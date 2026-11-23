<?php
declare(strict_types=1);
namespace App\Database;

/** Explicit operator-only account preparation. Never applies migrations. */
final class Restore006MigratorPreparation
{
    public const CONFIRM='mysql-3307-37e4d222-dda2-11f1-b503-5c2886f057d5';
    public const DIAGNOSTIC_REVISION='SCHEMA006_V3';
    public const PLAN_HASH='6bbbd2780daf0723f1c70604e08e9462dd3c41e71ae87fee6f85a450f145a12d';
    private string $step='ARGUMENTS';
    private string $creation='NOT_ATTEMPTED';
    private int $grantsDone=0;

    public function __construct(private \Closure $connect, private \Closure $schemaCheck, private ?\Closure $verifyGrants=null) {}

    public static function assertRuntime(): void
    {
        if(PHP_VERSION_ID<80400 || PHP_VERSION_ID>=80500) throw new SafetyException('PHP_VERSION_UNSUPPORTED');
        if(!extension_loaded('pdo_mysql') || !in_array('mysql',\PDO::getAvailableDrivers(),true)) throw new SafetyException('PHP_PDO_MYSQL_MISSING');
        if(!extension_loaded('mbstring')) throw new SafetyException('PHP_MBSTRING_MISSING');
    }

    public static function plan(string $path): array
    {
        if(!is_file($path) || !hash_equals(self::PLAN_HASH,hash_file('sha256',$path))) throw new SafetyException('GRANT_PLAN_CHECKSUM_MISMATCH');
        $text=preg_replace('/^--.*$/m','',file_get_contents($path));
        $sql=array_values(array_filter(array_map(static fn(string $s):string=>trim(preg_replace('/\s+/',' ',$s)),explode(';',$text))));
        if(count($sql)!==7) throw new SafetyException('GRANT_PLAN_REJECTED');
        ConnectionFactory::validateMigration006Grants($sql,false,true,'pre');
        return $sql;
    }

    public static function assertRoot(array $i): void
    {
        if((int)($i['port']??0)!==3307 || ($i['uuid']??null)!=='37e4d222-dda2-11f1-b503-5c2886f057d5'
            || rtrim(str_replace('\\','/',(string)($i['datadir']??'')),'/')!=='C:/Users/User/mysql-restore-006-data'
            || ($i['database_name']??null)!==MigrationTarget::TEST || ($i['account']??null)!=='root@localhost'
            || !array_key_exists('partial_revokes',$i) || (int)$i['partial_revokes']!==0
            || !preg_match('/^8\.4\.9(?:[-.]|$)/D',(string)($i['version']??''))) throw new SafetyException('RESTORE_ROOT_IDENTITY_REJECTED');
    }

    private function identity(\PDO $db): void
    {
        $i=$db->query('SELECT @@port AS port, @@server_uuid AS uuid, @@datadir AS datadir, DATABASE() AS database_name, CURRENT_USER() AS account, @@GLOBAL.partial_revokes AS partial_revokes, VERSION() AS version')->fetch(\PDO::FETCH_ASSOC);
        if(!is_array($i)) throw new SafetyException('RESTORE_ROOT_IDENTITY_REJECTED');
        self::assertRoot($i);
    }

    private function accounts(\PDO $db, bool $exists): void
    {
        $q=$db->prepare('SELECT Host FROM mysql.user WHERE User=:username');
        $q->execute(['username'=>'up_van_test_migrator']);
        $hosts=$q->fetchAll(\PDO::FETCH_COLUMN);
        if($exists ? $hosts!==['127.0.0.1'] : $hosts!==[]) throw new SafetyException('TARGET_ACCOUNT_STATE_REJECTED');
    }

    public function run(string $mode,string $confirm,array $config,string $rootPassword,array $grants): array
    {
        $root=null;$locked=false;
        try {
            if(!in_array($mode,['preflight','execute','postflight'],true) || $confirm!==self::CONFIRM) throw new SafetyException('EXPLICIT_TARGET_REQUIRED');
            $this->step='CONFIG';
            // Only the port is locally overridden; credential bytes match the Runner's loader.
            $config['port']='3307';Migration006RestoreIdentity::assertConfig($config);
            if(!is_string($config['password']??null) || $config['password']==='' || $rootPassword==='') throw new SafetyException('PASSWORD_NOT_CONFIGURED');
            ConnectionFactory::validateMigration006Grants($grants,false,true,'pre');
            $this->step='ROOT_CONNECT';$root=($this->connect)('root',$rootPassword);
            $this->step='ROOT_IDENTITY';$this->identity($root);
            $this->step='SCHEMA_LEDGER';
            if(($this->schemaCheck)($root)!=='pre') throw new SafetyException('SCHEMA_005_REQUIRED');
            $this->step='TARGET_ACCOUNT';$this->accounts($root,$mode==='postflight');
            if($mode==='preflight') return $this->pass('ACCOUNT_ABSENT_READY',false);
            if($mode==='execute') {
                $this->step='ACCOUNT_LOCK';
                if((int)$root->query("SELECT GET_LOCK('restore3307:prepare-migrator006',0)")->fetchColumn()!==1) throw new SafetyException('ACCOUNT_LOCK_UNAVAILABLE');
                $locked=true;
                $this->step='FINAL_RECHECK';$this->identity($root);
                if(($this->schemaCheck)($root)!=='pre') throw new SafetyException('SCHEMA_005_REQUIRED');
                $this->accounts($root,false);
                $quoted=$root->quote($config['password']);
                if(!is_string($quoted)) throw new SafetyException('PASSWORD_QUOTING_FAILED');
                $this->step='CREATE_ACCOUNT';$this->creation='ATTEMPTED_RESULT_UNKNOWN';
                $root->exec("CREATE USER 'up_van_test_migrator'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY ".$quoted);
                unset($quoted);$this->creation='CONFIRMED';
                foreach($grants as $index=>$sql) {
                    $this->step='GRANT_'.($index+1);$this->identity($root);
                    $root->exec($sql);$this->grantsDone++;
                }
            }
            $this->step='POST_ACCOUNT';$this->identity($root);$this->accounts($root,true);
            $this->step='MIGRATOR_AUTH';$db=($this->connect)('up_van_test_migrator',$config['password']);
            $this->step='MIGRATOR_IDENTITY';Migration006RestoreIdentity::inspect($db);
            $this->step='MIGRATOR_GRANTS';
            if($this->verifyGrants===null) ConnectionFactory::assertMigration006Grants($db);
            else ($this->verifyGrants)($db);
            $this->step='POST_SCHEMA';
            if(($this->schemaCheck)($root)!=='pre') throw new SafetyException('SCHEMA_005_REQUIRED');
            return $this->pass('MIGRATOR_CREDENTIALS_AND_GRANTS_VALID',true);
        } catch(\Throwable $e) {
            $safe=['EXPLICIT_TARGET_REQUIRED','RESTORE_ROOT_IDENTITY_REJECTED','TARGET_ACCOUNT_STATE_REJECTED','SCHEMA_005_REQUIRED','PASSWORD_NOT_CONFIGURED','PASSWORD_QUOTING_FAILED','ACCOUNT_LOCK_UNAVAILABLE'];
            $r=['status'=>'FAIL','step'=>$this->step,'code'=>in_array($e->getMessage(),$safe,true)?$e->getMessage():'ACCOUNT_PREPARATION_STOP','creation_state'=>$this->creation,'grants_completed'=>$this->grantsDone,'automatic_retry'=>false];
            $r['diagnostic_revision']=self::DIAGNOSTIC_REVISION;
            if(in_array($this->step,['SCHEMA_LEDGER','FINAL_RECHECK','POST_SCHEMA'],true)) {
                if($r['code']==='ACCOUNT_PREPARATION_STOP')$r['code']='SCHEMA_UNCLASSIFIED_FAILURE';
                $r+=MigrationSchemaException::safeOrigin($e);
            }
            if($e instanceof \PDOException){
                $r['code']='PDO_ERROR';$r['sqlstate']=$e->errorInfo[0]??null;$r['native_code']=$e->errorInfo[1]??null;
                $r['failure_type']=match(true) {
                    MigrationTestConnectionDiagnostic::pdoFailureCode((string)$r['sqlstate'],$r['native_code']===null?null:(int)$r['native_code'])==='AUTHENTICATION_FAILED'=>'AUTHENTICATION_FAILURE',
                    in_array((int)$r['native_code'],[2002,2003,2006,2013],true)=>'CONNECTION_FAILURE',
                    (int)$r['native_code']===1049=>'DATABASE_SELECTION_FAILURE',
                    default=>'DATABASE_OPERATION_FAILURE',
                };
            }
            if($e instanceof \Error){$r['failure_type']='PHP_RUNTIME_FAILURE';}
            if($e instanceof MigrationSchemaException) {
                $r['code']=$e->getMessage();$r['schema_check']=$e->schemaCheck;
                if($e->schemaField!=='') $r['schema_field']=$e->schemaField;
                foreach($e->origin as $key=>$value)$r[$key]=$value;
            }
            if($e instanceof GrantGuardException){$r['code']='GRANT_GUARD_FAILED';}
            return $r;
        } finally {
            if($locked && $root!==null) {try{$root->query("SELECT RELEASE_LOCK('restore3307:prepare-migrator006')");}catch(\Throwable){}}
        }
    }

    private function pass(string $code,bool $verified): array
    {
        return ['status'=>'PASS','step'=>'COMPLETE','code'=>$code,'diagnostic_revision'=>self::DIAGNOSTIC_REVISION,'port'=>3307,'uuid'=>'37e4d222-dda2-11f1-b503-5c2886f057d5','creation_state'=>$this->creation,'grants_completed'=>$this->grantsDone,'credential_verified'=>$verified,'migration_applied'=>false];
    }
}
