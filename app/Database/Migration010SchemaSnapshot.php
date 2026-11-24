<?php
declare(strict_types=1);
namespace App\Database;

use PDO;
use PDOStatement;

/** Narrow reader for the schema-only mysqldump format produced by our exporter. Never executes SQL. */
final class Migration010SchemaSnapshot
{
    public const TABLES=['users','drivers','roles','auth_login_buckets','vehicles','booking_requests','schema_migrations'];
    private array $tables=[];
    private array $manifest=[];
    public readonly string $sha256;
    public function __construct(string $sql)
    {
        $this->sha256=hash('sha256',$sql);
        if(strlen($sql)>4*1024*1024 || str_contains($sql,"\0") || str_contains($sql,'"')) throw new SafetyException('SNAPSHOT_SIZE_OR_ENCODING_REJECTED');
        // Only these mysqldump session directives are permitted; none are executed.
        $sql=preg_replace('/\/\*!40101 SET (?:@saved_cs_client\s*=\s*@@character_set_client|character_set_client\s*=\s*(?:utf8mb4|@saved_cs_client)) \*\/;?/', '', $sql);
        $sql=preg_replace('/\/\*!50503 SET character_set_client\s*=\s*utf8mb4 \*\/;?/', '', $sql);
        if(str_contains($sql,'/*') || preg_match('/^\s*(?:--|#)/m',$sql)) throw new SafetyException('SNAPSHOT_COMMENTS_REJECTED');
        // Literals in definitions must already exist in reviewed migration SQL. This rejects
        // unexpected defaults/comments (including pasted credentials) without printing them.
        $allowed=['0'=>true,'1'=>true];
        foreach(MigrationPlan::load(include010:true) as $entry) {
            $source=file_get_contents(dirname(__DIR__,2).'/'.$entry['file']);
            preg_match_all("/'(?:[^'\\\\]|\\\\.|'')*'/s",$source,$matches);
            foreach($matches[0] as $literal)$allowed[self::literal($literal)]=true;
        }
        preg_match_all("/'(?:[^'\\\\]|\\\\.|'')*'/s",$sql,$matches);
        foreach($matches[0] as $literal)if(!isset($allowed[self::literal($literal)]))throw new SafetyException('SNAPSHOT_UNREVIEWED_LITERAL');
        foreach(SqlStatements::split($sql) as $statement){
            if(!preg_match('/\ACREATE TABLE `([a-z_][a-z0-9_]*)` \(\R(.*)\R\) ENGINE=(\w+)(?: AUTO_INCREMENT=\d+)? DEFAULT CHARSET=(\w+) COLLATE=(\w+)\z/sD',$statement,$m))throw new SafetyException('SNAPSHOT_NON_SCHEMA_OR_UNSUPPORTED_SQL');
            [$all,$table,$body,$engine,$charset,$collation]=$m;
            if(!in_array($table,self::TABLES,true) || isset($this->tables[$table]))throw new SafetyException('SNAPSHOT_TABLE_SET_REJECTED');
            $data=['columns'=>[],'indexes'=>[],'foreign_keys'=>[],'checks'=>[], 'engine'=>$engine,'collation'=>$collation];
            foreach(explode("\n",str_replace("\r",'',$body)) as $lineNumber=>$line){
                $line=rtrim(trim($line),',');
                if(preg_match('/\A`([a-z_][a-z0-9_]*)` ([a-z]+(?:\(\d+(?:,\d+)?\))?(?: unsigned)?)(.*)\z/sD',$line,$c)){
                    $attrs=$c[3];$name=$c[1];
                    if(isset($data['columns'][$name]))throw new SafetyException('SNAPSHOT_DUPLICATE_COLUMN');
                    preg_match('/ CHARACTER SET (\w+)/',$attrs,$cs);preg_match('/ COLLATE (\w+)/',$attrs,$co);
                    preg_match("/ DEFAULT (NULL|'(?:[^'\\\\]|\\\\.|'')*'|CURRENT_TIMESTAMP(?:\\(\\d*\\))?|-?\\d+)/i",$attrs,$def);
                    preg_match('/ ON UPDATE (CURRENT_TIMESTAMP(?:\(\d*\))?)/i',$attrs,$update);
                    $type=strtolower($c[2]);$isText=(bool)preg_match('/^(?:var)?char|text/',$type);
                    $default=$def[1]??null;
                    if($default!==null)$default=strtoupper($default)==='NULL'?null:(str_starts_with($default,"'")?self::literal($default):strtolower($default));
                    $data['columns'][$name]=['COLUMN_NAME'=>$name,'COLUMN_TYPE'=>$type,'IS_NULLABLE'=>str_contains($attrs,' NOT NULL')?'NO':'YES',
                        'CHARACTER_SET_NAME'=>$isText?($cs[1]??$charset):null,'COLLATION_NAME'=>$isText?($co[1]??$collation):null,
                        'CHARACTER_MAXIMUM_LENGTH'=>preg_match('/^(?:var)?char\((\d+)\)/',$type,$len)?(int)$len[1]:null,
                        'COLUMN_DEFAULT'=>$default,'EXTRA'=>isset($update[1])?'on update '.strtolower($update[1]):(str_contains($attrs,'AUTO_INCREMENT')?'auto_increment':'')];
                }elseif(preg_match('/\A(PRIMARY KEY|UNIQUE KEY `([a-z_][a-z0-9_]*)`|KEY `([a-z_][a-z0-9_]*)`) \((`[a-z_][a-z0-9_]*`(?:,`[a-z_][a-z0-9_]*`)*)\)\z/D',$line,$idx)){
                    $index=$idx[1]==='PRIMARY KEY'?'PRIMARY':($idx[2]?:$idx[3]);
                    foreach(explode(',',$idx[4]) as $i=>$column)$data['indexes'][]=['TABLE_NAME'=>$table,'INDEX_NAME'=>$index,'COLUMN_NAME'=>trim($column,'`'),'NON_UNIQUE'=>str_starts_with($idx[1],'KEY ')?1:0,'SEQ_IN_INDEX'=>$i+1];
                }elseif(preg_match('/\ACONSTRAINT `([a-z_][a-z0-9_]*)` FOREIGN KEY \(`([a-z_][a-z0-9_]*)`\) REFERENCES `([a-z_][a-z0-9_]*)` \(`([a-z_][a-z0-9_]*)`\)((?: ON (?:DELETE|UPDATE) (?:RESTRICT|NO ACTION|CASCADE|SET NULL))*)\z/D',$line,$fk)){
                    preg_match('/ON DELETE (RESTRICT|NO ACTION|CASCADE|SET NULL)/',$fk[5],$del);preg_match('/ON UPDATE (RESTRICT|NO ACTION|CASCADE|SET NULL)/',$fk[5],$up);
                    $data['foreign_keys'][]=['CONSTRAINT_NAME'=>$fk[1],'COLUMN_NAME'=>$fk[2],'REFERENCED_TABLE_SCHEMA'=>MigrationTarget::TEST,'REFERENCED_TABLE_NAME'=>$fk[3],'REFERENCED_COLUMN_NAME'=>$fk[4],'DELETE_RULE'=>$del[1]??'RESTRICT','UPDATE_RULE'=>$up[1]??'NO ACTION'];
                }elseif(preg_match('/\ACONSTRAINT `([a-z_][a-z0-9_]*)` CHECK \((.+)\)\z/sD',$line,$check)){
                    $data['checks'][]=['CONSTRAINT_NAME'=>$check[1],'CHECK_CLAUSE'=>$check[2],'ENFORCED'=>'YES'];
                }else throw new SafetyException('SNAPSHOT_DEFINITION_UNSUPPORTED_'.strtoupper($table).'_'.($lineNumber+1));
            }
            if($data['columns']===[])throw new SafetyException('SNAPSHOT_COLUMNS_EMPTY');
            $this->tables[$table]=$data;
        }
        $actual=array_keys($this->tables);$expected=self::TABLES;sort($actual);sort($expected);
        if($actual!==$expected)throw new SafetyException('SNAPSHOT_TABLE_SET_INCOMPLETE');
    }

    private static function literal(string $value): string { return str_replace("''","'",stripcslashes(substr($value,1,-1))); }

    public static function load(string $directory): self
    {
        $root=realpath($directory);$repo=realpath(dirname(__DIR__,2));
        if($root===false || str_starts_with(strtolower($root).DIRECTORY_SEPARATOR,strtolower($repo).DIRECTORY_SEPARATOR))throw new SafetyException('SNAPSHOT_OUTSIDE_REPOSITORY_REQUIRED');
        if(filesize($root.'/schema.sql')>4*1024*1024 || filesize($root.'/manifest.json')>16384)throw new SafetyException('SNAPSHOT_SIZE_REJECTED');
        $manifest=json_decode((string)file_get_contents($root.'/manifest.json'),true,flags:JSON_THROW_ON_ERROR);
        $sql=(string)file_get_contents($root.'/schema.sql');
        if(($manifest['format']??null)!=='upvan-schema-only-v1' || ($manifest['tables']??null)!==self::TABLES
            || ($manifest['host']??null)!=='127.0.0.1' || ($manifest['port']??null)!==3306 || ($manifest['database']??null)!==MigrationTarget::TEST
            || ($manifest['uuid']??null)!==DriverDatabaseIdentity::UUID || ($manifest['version']??null)!=='8.4.9'
            || !hash_equals((string)($manifest['sha256']??''),hash('sha256',$sql))
            || !isset($manifest['export_started_utc'],$manifest['export_finished_utc'])
            || strtotime($manifest['export_started_utc'])===false || strtotime($manifest['export_finished_utc'])===false
            || strtotime($manifest['export_finished_utc'])<strtotime($manifest['export_started_utc']))throw new SafetyException('SNAPSHOT_MANIFEST_REJECTED');
        $snapshot=new self($sql);$snapshot->manifest=$manifest;
        return $snapshot;
    }

    public function evidence(): array { return ['schema_source'=>'offline_snapshot','live_schema_verified'=>false,'snapshot_sha256'=>$this->sha256,
        'export_started_utc'=>$this->manifest['export_started_utc']??null,'export_finished_utc'=>$this->manifest['export_finished_utc']??null,'apply_ready'=>false]; }

    /** Metadata adapter only. Unrecognised query shapes stop; they never fall back to guessed metadata. */
    public function rows(string $sql,array $params): array
    {
        if(($params['db']??null)!==MigrationTarget::TEST)throw new SafetyException('SNAPSHOT_METADATA_TARGET_REJECTED');
        $table=$params['table']??null;
        if(preg_match("/(?:TABLE_NAME|tc.TABLE_NAME|k.TABLE_NAME)='([a-z_][a-z0-9_]*)'/",$sql,$m))$table=$m[1];
        $tables=$table!==null?[$table]:(str_contains($sql,"TABLE_NAME IN ('users','drivers')")?['users','drivers']:self::TABLES);
        $rows=[];
        foreach($tables as $name){
            $t=$this->tables[$name]??throw new SafetyException('SNAPSHOT_UNKNOWN_TABLE');
            if(str_contains(strtoupper($sql),'INFORMATION_SCHEMA.COLUMNS'))$part=array_values($t['columns']);
            elseif(str_contains(strtoupper($sql),'INFORMATION_SCHEMA.STATISTICS'))$part=$t['indexes'];
            elseif(str_contains(strtoupper($sql),'INFORMATION_SCHEMA.KEY_COLUMN_USAGE'))$part=$t['foreign_keys'];
            elseif(str_contains(strtoupper($sql),'INFORMATION_SCHEMA.TABLE_CONSTRAINTS'))$part=$t['checks'];
            elseif(str_contains(strtoupper($sql),'INFORMATION_SCHEMA.TABLES'))$part=[['TABLE_NAME'=>$name,'ENGINE'=>$t['engine'],'TABLE_COLLATION'=>$t['collation']]];
            else throw new SafetyException('SNAPSHOT_QUERY_UNSUPPORTED');
            $rows=[...$rows,...$part];
        }
        $linkPredicate=str_contains($sql,"k.COLUMN_NAME='user_id' OR k.CONSTRAINT_NAME='fk_drivers_user'");
        foreach($linkPredicate?[]:['COLUMN_NAME','INDEX_NAME','CONSTRAINT_NAME'] as $key){
            if(preg_match('/(?<![A-Z_])'.$key."='([a-z_][a-z0-9_]*)'/",$sql,$m))$rows=array_values(array_filter($rows,static fn($r)=>($r[$key]??null)===$m[1]));
            if(preg_match('/(?<![A-Z_])'.$key." IN \((('[a-z_][a-z0-9_]*',?)+)\)/",$sql,$m)){
                preg_match_all("/'([a-z_][a-z0-9_]*)'/",$m[1],$names);$rows=array_values(array_filter($rows,static fn($r)=>in_array($r[$key]??null,$names[1],true)));
            }
        }
        if(isset($params['index']))$rows=array_values(array_filter($rows,static fn($r)=>$r['INDEX_NAME']===$params['index']));
        if(isset($params['name']))$rows=array_values(array_filter($rows,static fn($r)=>$r['CONSTRAINT_NAME']===$params['name']));
        if($linkPredicate)$rows=array_values(array_filter($rows,static fn($r)=>$r['COLUMN_NAME']==='user_id'||$r['CONSTRAINT_NAME']==='fk_drivers_user'));
        if(str_contains($sql,'ORDER BY INDEX_NAME'))usort($rows,static fn($a,$b)=>[$a['INDEX_NAME'],$a['SEQ_IN_INDEX']??0]<=>[$b['INDEX_NAME'],$b['SEQ_IN_INDEX']??0]);
        if(!preg_match('/\ASELECT (.*?) FROM /s',$sql,$m))throw new SafetyException('SNAPSHOT_PROJECTION_REJECTED');
        $fields=explode(',',$m[1]);$result=[];
        foreach($rows as $row){$selected=[];foreach($fields as $field){$field=trim(preg_replace('/\b[a-z]+\./','',$field));if(!array_key_exists($field,$row))throw new SafetyException('SNAPSHOT_PROJECTION_REJECTED');$selected[$field]=$row[$field];}$result[]=$selected;}
        return $result;
    }

    public function connection(PDO $live): PDO { return new Migration010SnapshotPdo($live,$this); }
}

/** Uses live identity/ledger/role reads, and snapshot metadata; no SQL from a file reaches MySQL. */
final class Migration010SnapshotPdo extends PDO
{
    public function __construct(private PDO $live,private Migration010SchemaSnapshot $snapshot){}
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if(str_contains(strtoupper($query),'INFORMATION_SCHEMA.'))return new Migration010SnapshotStatement($this->snapshot,$query);
        if(!in_array($query,['SELECT version, checksum, status, completed_statements, total_statements FROM schema_migrations ORDER BY version','SELECT name, description FROM roles ORDER BY name'],true))throw new SafetyException('SNAPSHOT_LIVE_QUERY_REJECTED');
        return $this->live->prepare($query,$options);
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {
        if(!in_array($query,['SELECT DATABASE()','SELECT @@port AS port, @@server_uuid AS uuid, DATABASE() AS database_name, CURRENT_USER() AS account_name'],true))throw new SafetyException('SNAPSHOT_LIVE_QUERY_REJECTED');
        return $this->live->query($query,$fetchMode,...$fetchModeArgs);
    }
}
final class Migration010SnapshotStatement extends PDOStatement
{
    private array $rows=[];
    public function __construct(private Migration010SchemaSnapshot $snapshot,private string $sql){}
    public function execute(?array $params=null): bool { $this->rows=$this->snapshot->rows($this->sql,$params??[]);return true; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array { if($mode!==PDO::FETCH_ASSOC)throw new SafetyException('SNAPSHOT_FETCH_MODE_REJECTED');return $this->rows; }
}
