<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

try {
    if(count($argv)!==2)throw new App\Database\SafetyException('SNAPSHOT_DIRECTORY_REQUIRED');
    $snapshot=App\Database\Migration010SchemaSnapshot::load($argv[1]);
    echo json_encode(['status'=>'PASS','scope'=>'schema_only_content_and_manifest','schema_preconditions_verified'=>false,
        'sensitive_literals'=>'NO_UNREVIEWED_LITERALS','sha256'=>$snapshot->sha256,
        'live_schema_verified'=>false,'next'=>'READ_ONLY_PREFLIGHT_WITH_SNAPSHOT']),PHP_EOL;
}catch(Throwable $error){
    $reason=$error instanceof App\Database\SafetyException && preg_match('/\A[A-Z0-9_]+\z/D',$error->getMessage())?$error->getMessage():'SCHEMA_SNAPSHOT_CONTENT_OR_MANIFEST_REJECTED';
    echo json_encode(['status'=>'FAIL','reason'=>$reason]),PHP_EOL;exit(1);
}
