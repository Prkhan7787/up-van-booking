<?php
declare(strict_types=1);
namespace App\Database;
use PDO;

final class Migration011Validator
{
    public static function assertReadOnlyPreflight(array $packet): void
    {
        // JSON aggregation has no guaranteed row order. Restore the ledger contract order explicitly.
        $ledger=$packet['ledger'];usort($ledger,static fn($a,$b)=>strcmp($a['version'],$b['version']));
        MigrationPlan::microsoft011();
        self::assertLedger($ledger);
        self::assertSchema($packet,false);
        $expected=['schema_migrations'];
        foreach(MigrationPlan::microsoft011()['dependencies'] as $entry)foreach($entry['objects'] as $name)if($name!=='calendar_events')$expected[]=$name;
        $expected=array_values(array_unique($expected));sort($expected);
        $base=[];$views=[];
        foreach($packet['inventory'] as $table){if($table['TABLE_TYPE']==='BASE TABLE')$base[]=$table['TABLE_NAME'];else $views[]=$table['TABLE_NAME'];}
        sort($base);sort($views);
        if($base!==$expected||count($base)!==15||$views!==['calendar_events'])throw new SafetyException('MIGRATION_011_BACKUP_INVENTORY_REJECTED');
    }
    public static function assertLedger(array $rows,string $phase='before'): void
    {
        $branch=MigrationPlan::microsoft011();$expected=$branch['dependencies'];
        $own=null;
        if ($phase!=='before') {
            $own=array_pop($rows);
            if (($own['version']??null)!==MigrationPlan::MICROSOFT011['version']||($own['checksum']??null)!==MigrationPlan::MICROSOFT011['sha256']
                ||($own['status']??null)!==($phase==='after'?'applied':'started')
                ||(string)($own['completed_statements']??'')!=='1'||(string)($own['total_statements']??'')!=='1') throw new SafetyException('MIGRATION_011_LEDGER_STATE_REJECTED');
        }
        if (count($rows)!==count($expected)) throw new SafetyException('MIGRATION_011_DEPENDENCY_SET_REJECTED');
        foreach($expected as $i=>$entry){
            $row=$rows[$i];
            if (($row['version']??null)!==$entry['version']||($row['checksum']??null)!==$entry['sha256']||($row['status']??null)!=='applied'
                ||(int)($row['completed_statements']??-1)!==count($entry['statements'])||(int)($row['total_statements']??-1)!==count($entry['statements'])) throw new SafetyException('MIGRATION_011_DEPENDENCY_CHECKSUM_OR_STATE');
        }
    }
    public static function metadata(PDO $db): array
    {
        $result=[];foreach(self::metadataQueries() as $key=>$sql)$result[$key]=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);return $result;
    }
    public static function metadataQueries(): array
    {
        $queries=[
            'tables'=>"SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','schema_migrations','microsoft_identities') ORDER BY TABLE_NAME",
            'columns'=>"SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME='microsoft_identities' OR (TABLE_NAME='users' AND COLUMN_NAME IN ('id','auth_version'))) ORDER BY TABLE_NAME,ORDINAL_POSITION",
            'indexes'=>"SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME='microsoft_identities' OR (TABLE_NAME IN ('users','schema_migrations') AND INDEX_NAME='PRIMARY')) ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX",
            'foreign_keys'=>"SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON k.CONSTRAINT_SCHEMA=r.CONSTRAINT_SCHEMA AND k.TABLE_NAME=r.TABLE_NAME AND k.CONSTRAINT_NAME=r.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='microsoft_identities' ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION",
            'checks'=>"SELECT t.CONSTRAINT_NAME,c.CHECK_CLAUSE,t.ENFORCED FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME='microsoft_identities' AND t.CONSTRAINT_TYPE='CHECK' ORDER BY t.CONSTRAINT_NAME",
            'triggers'=>"SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='microsoft_identities'",
        ];
        return $queries;
    }
    public static function assertSchema(array $m,bool $after): void
    {
        $tables=array_column($m['tables'],'ENGINE','TABLE_NAME');
        foreach(['users','schema_migrations'] as $table)if(($tables[$table]??null)!=='InnoDB')throw new SafetyException('MIGRATION_011_PARENT_OR_LEDGER_HIDDEN');
        foreach($m['tables'] as $row)if($row['TABLE_TYPE']!=='BASE TABLE')throw new SafetyException('MIGRATION_011_TABLE_TYPE_REJECTED');
        $parent=array_values(array_filter($m['columns'],static fn($r)=>$r['TABLE_NAME']==='users'));
        if (count($parent)!==2) throw new SafetyException('MIGRATION_011_PARENT_METADATA_INCOMPLETE');
        foreach($parent as $column)if(!in_array($column['COLUMN_NAME'],['id','auth_version'],true)||$column['COLUMN_TYPE']!=='bigint unsigned'||$column['IS_NULLABLE']!=='NO')throw new SafetyException('MIGRATION_011_PARENT_SCHEMA_DRIFT');
        $parentIndexes=array_values(array_filter($m['indexes'],static fn($r)=>$r['TABLE_NAME']!=='microsoft_identities'));
        $actual=[];foreach($parentIndexes as $r){if($r['SUB_PART']!==null||(int)$r['NON_UNIQUE']!==0||(int)$r['SEQ_IN_INDEX']!==1)throw new SafetyException('MIGRATION_011_PARENT_KEY_DRIFT');$actual[$r['TABLE_NAME']]=$r['COLUMN_NAME'];}
        ksort($actual);if($actual!==['schema_migrations'=>'version','users'=>'id']||count($parentIndexes)!==2)throw new SafetyException('MIGRATION_011_PARENT_KEY_DRIFT');
        $columns=array_values(array_filter($m['columns'],static fn($r)=>$r['TABLE_NAME']==='microsoft_identities'));
        $indexes=array_values(array_filter($m['indexes'],static fn($r)=>$r['TABLE_NAME']==='microsoft_identities'));
        if (!$after) {
            if (isset($tables['microsoft_identities'])||$columns!==[]||$indexes!==[]||$m['foreign_keys']!==[]||$m['checks']!==[])throw new SafetyException('MIGRATION_011_EXISTING_OR_PARTIAL_TABLE');
            return;
        }
        if (($tables['microsoft_identities']??null)!=='InnoDB'||count($columns)!==5||$m['triggers']!==[])throw new SafetyException('MIGRATION_011_POST_SCHEMA_REJECTED');
        foreach($m['tables'] as $r)if($r['TABLE_NAME']==='microsoft_identities'&&$r['TABLE_COLLATION']!=='utf8mb4_unicode_ci')throw new SafetyException('MIGRATION_011_POST_COLLATION_REJECTED');
        $expected=['user_id'=>'bigint unsigned','tenant_id'=>'char(36)','object_id'=>'char(36)','created_by'=>'bigint unsigned','created_at'=>'timestamp'];
        if (array_column($columns,'COLUMN_NAME')!==array_keys($expected))throw new SafetyException('MIGRATION_011_POST_COLUMNS_REJECTED');
        foreach($columns as $c){
            $name=$c['COLUMN_NAME'];
            if($c['COLUMN_TYPE']!==$expected[$name]||$c['IS_NULLABLE']!=='NO')throw new SafetyException('MIGRATION_011_POST_COLUMNS_REJECTED');
            if (in_array($name,['tenant_id','object_id'],true)&&($c['CHARACTER_SET_NAME']!=='ascii'||$c['COLLATION_NAME']!=='ascii_bin'))throw new SafetyException('MIGRATION_011_POST_UUID_COLLATION_REJECTED');
            if($name==='created_at'){
                if(!in_array(strtolower((string)$c['COLUMN_DEFAULT']),['current_timestamp','current_timestamp()'],true)||!in_array(strtolower($c['EXTRA']),['','default_generated'],true))throw new SafetyException('MIGRATION_011_POST_DEFAULT_REJECTED');
            }elseif($c['COLUMN_DEFAULT']!==null||$c['EXTRA']!=='')throw new SafetyException('MIGRATION_011_POST_DEFAULT_REJECTED');
        }
        $keys=[];foreach($indexes as $r){if($r['SUB_PART']!==null||$r['INDEX_TYPE']!=='BTREE')throw new SafetyException('MIGRATION_011_POST_INDEX_REJECTED');$keys[]=[$r['INDEX_NAME'],$r['COLUMN_NAME'],(int)$r['SEQ_IN_INDEX'],(int)$r['NON_UNIQUE']];}
        sort($keys);$expectedKeys=[['PRIMARY','user_id',1,0],['idx_microsoft_identity_creator','created_by',1,1],['uq_microsoft_identity','tenant_id',1,0],['uq_microsoft_identity','object_id',2,0]];sort($expectedKeys);
        if($keys!==$expectedKeys)throw new SafetyException('MIGRATION_011_POST_INDEX_REJECTED');
        if(count($m['foreign_keys'])!==2)throw new SafetyException('MIGRATION_011_POST_FK_REJECTED');
        $fks=['fk_microsoft_identity_creator'=>'created_by','fk_microsoft_identity_user'=>'user_id'];
        foreach($m['foreign_keys'] as $r){if(($fks[$r['CONSTRAINT_NAME']]??null)!==$r['COLUMN_NAME']||$r['REFERENCED_TABLE_SCHEMA']!==MigrationTarget::TEST||$r['REFERENCED_TABLE_NAME']!=='users'||$r['REFERENCED_COLUMN_NAME']!=='id'||$r['DELETE_RULE']!=='RESTRICT'||!in_array($r['UPDATE_RULE'],['RESTRICT','NO ACTION'],true))throw new SafetyException('MIGRATION_011_POST_FK_REJECTED');unset($fks[$r['CONSTRAINT_NAME']]);}
        if ($fks!==[])throw new SafetyException('MIGRATION_011_POST_FK_REJECTED');
        $pattern="'^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$','c'";
        $zero="'00000000-0000-0000-0000-000000000000'";
        $checks=['chk_microsoft_tenant_uuid'=>["regexp_like(tenant_id,$pattern)"],
            'chk_microsoft_object_uuid'=>["regexp_like(object_id,$pattern)"],
            'chk_microsoft_nonzero_uuid'=>["tenant_id<>$zero and object_id<>$zero","(tenant_id<>$zero)and(object_id<>$zero)"]];
        foreach($m['checks'] as $r){
            $name=$r['CONSTRAINT_NAME'];$clause=self::normalizeCheck($r['CHECK_CLAUSE']);
            if($r['ENFORCED']!=='YES'||!isset($checks[$name])||!in_array($clause,array_map(self::normalizeCheck(...),$checks[$name]),true))throw new SafetyException('MIGRATION_011_POST_CHECK_REJECTED');unset($checks[$name]);
        }
        if($checks!==[])throw new SafetyException('MIGRATION_011_POST_CHECK_REJECTED');
    }
    private static function normalizeCheck(string $value): string
    {
        // MySQL 8.4.9 metadata aggregation may escape only CHECK string delimiters
        // as \\' (SHOW CREATE TABLE renders the same clauses without these escapes).
        // Normalize the charset-prefixed opening delimiter and SQL closing delimiters;
        // do not rewrite backslash-escaped apostrophes inside literal contents.
        $value=str_replace("_utf8mb4\\'", "_utf8mb4'", $value);
        $value=preg_replace("/\\\\'(?=\\s*[,)]|\\s+(?:and|or)\\b)/i", "'", $value);
        $parts=preg_split("/('(?:[^']|'')*')/",$value,-1,PREG_SPLIT_DELIM_CAPTURE);
        foreach($parts as $i=>$part){
            if($i%2===1)continue; // Never rewrite regex or UUID string literals.
            $part=preg_replace('/\s+/','',strtolower(str_replace('`','',$part)));
            $parts[$i]=preg_replace('/_[a-z0-9]+$/','',$part);
        }
        $value=implode('',$parts);
        // Strip enclosing parentheses only. Preserve operator grouping inside expressions.
        while(str_starts_with($value,'(')&&str_ends_with($value,')')){
            $depth=0;$quoted=false;$outer=true;
            for($i=0,$length=strlen($value);$i<$length;$i++){
                if($value[$i]==="'")$quoted=!$quoted;
                if($quoted)continue;
                if($value[$i]==='(')$depth++;
                if($value[$i]===')')$depth--;
                if($depth===0&&$i<$length-1){$outer=false;break;}
            }
            if(!$outer)break;$value=substr($value,1,-1);
        }
        return $value;
    }
}
