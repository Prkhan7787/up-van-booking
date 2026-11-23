<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';

use App\Database\Migration004PostconditionValidator;
use App\Database\SafetyException;

$fixture=[
    ['COLUMN_NAME'=>'bucket_scope','COLUMN_TYPE'=>'varchar(16)','IS_NULLABLE'=>'NO','CHARACTER_SET_NAME'=>'ascii','COLLATION_NAME'=>'ascii_bin','COLUMN_DEFAULT'=>null,'EXTRA'=>''],
    ['COLUMN_NAME'=>'bucket_digest','COLUMN_TYPE'=>'binary(32)','IS_NULLABLE'=>'NO','CHARACTER_SET_NAME'=>null,'COLLATION_NAME'=>null,'COLUMN_DEFAULT'=>null,'EXTRA'=>''],
    ['COLUMN_NAME'=>'window_started_at','COLUMN_TYPE'=>'bigint unsigned','IS_NULLABLE'=>'NO','CHARACTER_SET_NAME'=>null,'COLLATION_NAME'=>null,'COLUMN_DEFAULT'=>null,'EXTRA'=>''],
    ['COLUMN_NAME'=>'attempt_count','COLUMN_TYPE'=>'smallint unsigned','IS_NULLABLE'=>'NO','CHARACTER_SET_NAME'=>null,'COLLATION_NAME'=>null,'COLUMN_DEFAULT'=>'0','EXTRA'=>''],
    ['COLUMN_NAME'=>'created_at','COLUMN_TYPE'=>'timestamp','IS_NULLABLE'=>'NO','COLUMN_DEFAULT'=>'CURRENT_TIMESTAMP','EXTRA'=>'DEFAULT_GENERATED'],
    ['COLUMN_NAME'=>'updated_at','COLUMN_TYPE'=>'timestamp','IS_NULLABLE'=>'NO','COLUMN_DEFAULT'=>'current_timestamp()','EXTRA'=>'DEFAULT_GENERATED on update CURRENT_TIMESTAMP()'],
];
$checks=0;
function postconditionCheck(bool $ok,string $label):void {global $checks;if(!$ok)throw new SafetyException('Offline regression failed: '.$label);$checks++;echo 'PASS: '.$label.PHP_EOL;}

try { Migration004PostconditionValidator::assertRateLimitColumns($fixture); postconditionCheck(true,'reported MySQL 8.4 column types, nullability, defaults, and timestamp metadata pass'); }
catch (SafetyException) { postconditionCheck(false,'reported MySQL 8.4 column metadata passes'); }

$lowercaseKeys=[];
foreach ($fixture as $column) {
    $lowercaseKeys[]=array_combine(array_map('strtolower',array_keys($column)),array_values($column));
}
Migration004PostconditionValidator::assertRateLimitColumns($lowercaseKeys);
postconditionCheck(true,'lowercase PDO associative metadata keys are normalized before grouping');

$mixedKeys=[];
foreach ($fixture as $column) {
    $mixedKeys[]=array_combine(array_map(static fn(string $key):string=>strtolower($key)==='column_name'?'column_name':$key,array_keys($column)),array_values($column));
}
Migration004PostconditionValidator::assertRateLimitColumns($mixedKeys);
postconditionCheck(true,'mixed-case metadata keys retain all expected fields');

$numericDefault=$fixture;
foreach ($numericDefault as &$column) if ($column['COLUMN_NAME']==='attempt_count') $column['COLUMN_DEFAULT']=0;
unset($column);
Migration004PostconditionValidator::assertRateLimitColumns($numericDefault);
postconditionCheck(true,'numeric and string INFORMATION_SCHEMA default representations normalize equally');

$timestampPrecision=$fixture;
foreach ($timestampPrecision as &$column) if ($column['COLUMN_NAME']==='created_at') $column['COLUMN_DEFAULT']='CURRENT_TIMESTAMP(6)';
unset($column);
Migration004PostconditionValidator::assertRateLimitColumns($timestampPrecision);
postconditionCheck(true,'CURRENT_TIMESTAMP precision formatting is accepted');

$badType=$fixture;
foreach ($badType as &$column) if ($column['COLUMN_NAME']==='attempt_count') $column['COLUMN_TYPE']='smallint';
unset($column);
$typeRejected=false;
try { Migration004PostconditionValidator::assertRateLimitColumns($badType); }
catch (SafetyException $error) { $typeRejected=str_contains($error->getMessage(),'attempt_count.COLUMN_TYPE'); }
postconditionCheck($typeRejected,'incorrect unsigned type is rejected with safe column/attribute detail');

$badDefault=$fixture;
foreach ($badDefault as &$column) if ($column['COLUMN_NAME']==='attempt_count') $column['COLUMN_DEFAULT']='1';
unset($column);
$defaultRejected=false;
try { Migration004PostconditionValidator::assertRateLimitColumns($badDefault); }
catch (SafetyException $error) { $defaultRejected=str_contains($error->getMessage(),'attempt_count.COLUMN_DEFAULT'); }
postconditionCheck($defaultRejected,'incorrect default is rejected with safe column/attribute detail');

$missing=$fixture;
array_pop($missing);
$missingRejected=false;
try { Migration004PostconditionValidator::assertRateLimitColumns($missing); }
catch (SafetyException $error) { $missingRejected=str_contains($error->getMessage(),'updated_at.COLUMN_NAME'); }
postconditionCheck($missingRejected,'missing column is rejected');

echo "PASS: $checks postcondition regression checks; no database contacted.".PHP_EOL;
