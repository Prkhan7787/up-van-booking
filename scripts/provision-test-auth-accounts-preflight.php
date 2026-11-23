<?php
declare(strict_types=1);

try {
    require dirname(__DIR__) . '/bootstrap/cli.php';
    require __DIR__ . '/lib/ProvisionPreflight.php';
    $gates = [];
    foreach (['DB_PHASE_B_APPROVED','TEST_DB_EXECUTION_APPROVED','TEST_AUTH_EXECUTION_APPROVED','TEST_ADMIN_PROVISION_APPROVED'] as $gate) {
        $gates[$gate] = App\Core\Env::get($gate);
    }
    $password = getenv('UP_VAN_PROVISION_ROOT_PASSWORD');
    $result = UpVanProvisionPreflight::run(
        $argv[1] ?? null,
        $gates,
        $password,
        static function () use ($password): UpVanProvisionReadOnlyConnection {
            $pdo = new PDO(
                'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
                'root',
                (string) $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
                ]
            );
            return new UpVanPdoProvisionReadOnlyConnection($pdo);
        }
    );
} catch (Throwable) {
    $result = ['status' => 'FAIL', 'step' => 'CONFIG', 'code' => 'BOOTSTRAP_OR_CONFIG_FAILED'];
}

echo json_encode($result, JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(($result['status'] ?? 'FAIL') === 'PASS' ? 0 : 1);
