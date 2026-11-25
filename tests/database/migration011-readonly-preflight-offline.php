<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().' line '.$e->getLine().PHP_EOL);exit(1);});
use App\Database\MigrationPlan;
$plan=MigrationPlan::microsoft011();
$packet=['tables'=>[],'columns'=>[],'indexes'=>[],'foreign_keys'=>[],'checks'=>[],'triggers'=>[],'ledger'=>[],'inventory'=>[]];
foreach(['users','schema_migrations'] as $table){
    $packet['tables'][]=['TABLE_NAME'=>$table,'TABLE_TYPE'=>'BASE TABLE','ENGINE'=>'InnoDB'];
    $packet['indexes'][]=['TABLE_NAME'=>$table,'COLUMN_NAME'=>$table==='users'?'id':'version','NON_UNIQUE'=>0,'SEQ_IN_INDEX'=>1,'SUB_PART'=>null];
}
foreach(['id','auth_version'] as $column)$packet['columns'][]=['TABLE_NAME'=>'users','COLUMN_NAME'=>$column,'COLUMN_TYPE'=>'bigint unsigned','IS_NULLABLE'=>'NO','ORDINAL_POSITION'=>count($packet['columns'])+1];
$names=['schema_migrations'];
foreach($plan['dependencies'] as $entry){
    $packet['ledger'][]=['version'=>$entry['version'],'checksum'=>$entry['sha256'],'status'=>'applied','completed_statements'=>count($entry['statements']),'total_statements'=>count($entry['statements'])];
    foreach($entry['objects'] as $name)$names[]=$name;
}
foreach(array_unique($names) as $name)$packet['inventory'][]=['TABLE_NAME'=>$name,'TABLE_TYPE'=>$name==='calendar_events'?'VIEW':'BASE TABLE'];
if(in_array('--diagnostic-fixture',$argv,true)){
    $packet['account']=[['account_locked'=>'Y']];$packet['sessions']=[];
    echo json_encode($packet,JSON_THROW_ON_ERROR);exit(0);
}
$run=static function(array $data):bool{
    try{App\Database\Migration011Validator::assertReadOnlyPreflight($data);return true;}
    catch(Throwable){return false;}
};
$checks=0;
$assert=static function(bool $ok)use(&$checks):void{if(!$ok)throw new RuntimeException('Read-only preflight assertion failed');$checks++;};
$assert($run($packet));
$bad=$packet;$bad['ledger'][0]['checksum']=str_repeat('0',64);$assert(!$run($bad));
$bad=$packet;$bad['ledger'][]=['version'=>'010_driver_auth'];$assert(!$run($bad));
$bad=$packet;$bad['tables'][]=['TABLE_NAME'=>'microsoft_identities','TABLE_TYPE'=>'BASE TABLE','ENGINE'=>'InnoDB'];$assert(!$run($bad));
$bad=$packet;array_pop($bad['columns']);$assert(!$run($bad));
$bad=$packet;array_pop($bad['inventory']);$assert(!$run($bad));
$bad=$packet;$bad['ledger']=array_reverse($bad['ledger']);$assert($run($bad));
echo "PASS: $checks Migration 011 read-only preflight offline checks; no MySQL connection.\n";
