<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/cli.php';

use App\Database\ConnectionFactory;
use App\Database\SafetyException;

/**
 * Local, read-only PDO diagnostic. Never prints credentials, DSNs, or exception messages.
 * Run only from the project PowerShell wrapper after an administrator configures .env.
 */
$checks = [
    'runtime' => ['database' => 'up_van_booking', 'ddl' => false],
    'preflight' => ['database' => 'up_van_booking', 'ddl' => false],
    'migration' => ['database' => 'up_van_booking', 'ddl' => true],
    'test' => ['database' => 'up_van_booking_test', 'ddl' => false],
    'migration_test' => ['database' => 'up_van_booking_test', 'ddl' => true],
];

$results = [];

foreach ($checks as $purpose => $expected) {
    $phase = 'configuration';

    try {
        // Config validates the .env-backed target, local host, username and password without displaying them.
        $config = ConnectionFactory::config($purpose);
        $host = $config['host'] === '::1' ? '[::1]' : $config['host'];
        $dsn = "mysql:host={$host};port={$config['port']};dbname={$config['database']};charset=utf8mb4";

        $phase = 'pdo_connect';
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);

        $phase = 'metadata';
        $metadata = $pdo->query(
            'SELECT VERSION() AS server_version,
                    DATABASE() AS database_name,
                    @@character_set_client AS client_charset,
                    @@character_set_connection AS connection_charset,
                    @@collation_connection AS connection_collation'
        )->fetch();

        $schemaQuery = $pdo->prepare(
            'SELECT DEFAULT_CHARACTER_SET_NAME AS database_charset,
                    DEFAULT_COLLATION_NAME AS database_collation
             FROM INFORMATION_SCHEMA.SCHEMATA
             WHERE SCHEMA_NAME = :database_name'
        );
        $schemaQuery->execute(['database_name' => $expected['database']]);
        $schema = $schemaQuery->fetch();

        $phase = 'grant_scope';
        // Performs read-only SHOW GRANTS and scope validation for the current account.
        ConnectionFactory::assertScopedGrants(
            $pdo,
            $expected['database'],
            $expected['ddl']
        );

        $matches = $metadata !== false
            && $metadata['database_name'] === $expected['database']
            && $config['database'] === $expected['database'];

        $results[$purpose] = [
            'connection_status' => $matches && $schema !== false ? 'connected' : 'connected_but_target_check_failed',
            'database_name' => $metadata['database_name'] ?? null,
            'server_version' => $metadata['server_version'] ?? null,
            'connection_charset' => [
                'client' => $metadata['client_charset'] ?? null,
                'connection' => $metadata['connection_charset'] ?? null,
            ],
            'connection_collation' => $metadata['connection_collation'] ?? null,
            'database_charset' => $schema['database_charset'] ?? null,
            'database_collation' => $schema['database_collation'] ?? null,
            'error_code' => null,
        ];
    } catch (PDOException $error) {
        $errorInfo = $error->errorInfo ?? [];
        $results[$purpose] = [
            'connection_status' => 'failed',
            'database_name' => null,
            'server_version' => null,
            'connection_charset' => null,
            'connection_collation' => null,
            'database_charset' => null,
            'database_collation' => null,
            'error_code' => [
                'phase' => $phase,
                'sqlstate' => $errorInfo[0] ?? $error->getCode(),
                'native_code' => isset($errorInfo[1]) && is_numeric($errorInfo[1])
                    ? (int) $errorInfo[1]
                    : null,
            ],
        ];
    } catch (SafetyException) {
        $results[$purpose] = [
            'connection_status' => 'failed_safety_validation',
            'database_name' => null,
            'server_version' => null,
            'connection_charset' => null,
            'connection_collation' => null,
            'database_charset' => null,
            'database_collation' => null,
            'error_code' => ['phase' => $phase, 'category' => 'scope_or_configuration_check'],
        ];
    } catch (Throwable) {
        $results[$purpose] = [
            'connection_status' => 'failed',
            'database_name' => null,
            'server_version' => null,
            'connection_charset' => null,
            'connection_collation' => null,
            'database_charset' => null,
            'database_collation' => null,
            'error_code' => ['phase' => $phase, 'category' => 'verification_failed_details_withheld'],
        ];
    }
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
