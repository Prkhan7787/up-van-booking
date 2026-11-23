<?php
declare(strict_types=1);
$source=file_get_contents(dirname(__DIR__).'/scripts/task04b-5-readonly-preflight.php');
if($source===false)throw new RuntimeException('Preflight script missing.');
$checks=[
    'reserved CURRENT_USER alias replaced'=>str_contains($source,'CURRENT_USER() AS account_identity')&&!str_contains($source,'CURRENT_USER() AS current_user'),
    'identity query reads the safe alias'=>str_contains($source,"\$identity['account_identity']"),
    'failure report includes named query step'=>str_contains($source,"'failed_query_step'")&&str_contains($source,"'test_migrator_show_grants'"),
    'raw exception message is not reported'=>!preg_match('/\$report[^\n]*getMessage\(\)/',$source)&&!str_contains($source,"'message'=>\$error->getMessage()"),
    'fixed test database target'=>str_contains($source,'MigrationTarget::TEST')&&str_contains($source,"ConnectionFactory::config('test')"),
    'read-only transaction is required'=>str_contains($source,'SET TRANSACTION READ ONLY')&&str_contains($source,'beginTransaction()')&&str_contains($source,'rollBack()'),
    'no data-changing SQL is present'=>!preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+[a-z_]+|DELETE\s+FROM|ALTER\s+TABLE|CREATE\s+TABLE|DROP\s+TABLE|TRUNCATE)\b/i',$source),
    'SQLSTATE and native code are the only PDO error details'=>str_contains($source,"\$error->errorInfo[1]")&&!str_contains($source,'errorInfo[2]'),
];
$count=0;
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL: '.$label);$count++;echo 'PASS: '.$label.PHP_EOL;}
echo "PASS: $count preflight offline safety checks; no database connection attempted.".PHP_EOL;
