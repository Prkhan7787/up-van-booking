<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/cli.php';
use App\Database\ConnectionFactory;
use App\Database\MigrationPlan;
use App\Database\SafetyException;
$result = ['mode'=>'offline','target'=>'up_van_booking','target_exists'=>'unknown','plan'=>MigrationPlan::summary()];
if (array_slice($argv,1) === ['--connect']) {
    $db = ConnectionFactory::connect('preflight',false);
    $query = $db->prepare('SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :db');
    $query->execute(['db'=>'up_van_booking']);
    $row = $query->fetch();
    $result['mode'] = 'read-only-preflight';
    $result['target_exists'] = $row ? true : 'not_visible; DBA must certify absence';
    if ($row) $result['schema'] = $row;
    $result['server_version'] = $db->query('SELECT VERSION()')->fetchColumn();
} elseif (count($argv)>1 && array_slice($argv,1)!==['--offline']) throw new SafetyException('Usage: --offline OR --connect with dedicated preflight credentials.');
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
