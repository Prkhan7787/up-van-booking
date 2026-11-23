<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/cli.php';

$created = [];
$admin = null;
try {
    if (($argv[1] ?? '') !== 'up_van_booking_test') {
        throw new RuntimeException('target_mismatch');
    }
    foreach (['DB_PHASE_B_APPROVED','TEST_DB_EXECUTION_APPROVED','TEST_AUTH_EXECUTION_APPROVED','TEST_ADMIN_PROVISION_APPROVED'] as $gate) {
        if (App\Core\Env::get($gate) !== 'false') {
            throw new RuntimeException('gate_not_closed');
        }
    }
    $rootPassword = getenv('UP_VAN_PROVISION_ROOT_PASSWORD');
    $authPassword = getenv('AUTH_TEST_DB_PASSWORD');
    $provisionerPassword = getenv('TEST_PROVISIONER_DB_PASSWORD');
    if (!is_string($rootPassword) || !is_string($authPassword) || !is_string($provisionerPassword)
        || !preg_match('/\A[a-f0-9]{64}\z/i', $authPassword)
        || !preg_match('/\A[a-f0-9]{64}\z/i', $provisionerPassword)) {
        throw new RuntimeException('credential_configuration_invalid');
    }

    $admin = new PDO(
        'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
        'root',
        $rootPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]
    );
    $identity = (string) $admin->query('SELECT CURRENT_USER()')->fetchColumn();
    if (!str_starts_with($identity, 'root@')) {
        throw new RuntimeException('admin_identity_mismatch');
    }
    $schema = $admin->query("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='up_van_booking_test'")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($schema)
        || $schema['DEFAULT_CHARACTER_SET_NAME'] !== 'utf8mb4'
        || $schema['DEFAULT_COLLATION_NAME'] !== 'utf8mb4_unicode_ci') {
        throw new RuntimeException('test_schema_precondition_failed');
    }
    $existing = (int) $admin->query("SELECT COUNT(*) FROM mysql.user WHERE (User='up_van_auth_test' AND Host='127.0.0.1') OR (User='up_van_test_provisioner' AND Host='127.0.0.1')")->fetchColumn();
    if ($existing !== 0) {
        throw new RuntimeException('account_exists');
    }

    $admin->exec("CREATE USER 'up_van_auth_test'@'127.0.0.1' IDENTIFIED BY '$authPassword'");
    $created[] = 'up_van_auth_test';
    foreach (App\Database\AuthRuntimeGrantPlan::grantSql(false) as $grant) {
        $admin->exec($grant);
    }
    $admin->exec("CREATE USER 'up_van_test_provisioner'@'127.0.0.1' IDENTIFIED BY '$provisionerPassword'");
    $created[] = 'up_van_test_provisioner';
    foreach (App\Database\TestProvisionerGrantPlan::grantSql(false) as $grant) {
        $admin->exec($grant);
    }

    $auth = App\Database\ConnectionFactory::connect('auth_test');
    $provisioner = App\Database\ConnectionFactory::connect('test_provisioner');
    if ($auth->query('SELECT DATABASE()')->fetchColumn() !== 'up_van_booking_test'
        || $auth->query('SELECT CURRENT_USER()')->fetchColumn() !== 'up_van_auth_test@127.0.0.1'
        || $provisioner->query('SELECT DATABASE()')->fetchColumn() !== 'up_van_booking_test'
        || $provisioner->query('SELECT CURRENT_USER()')->fetchColumn() !== 'up_van_test_provisioner@127.0.0.1') {
        throw new RuntimeException('postcondition_failed');
    }
    echo "PROVISION_AND_GRANT_VERIFICATION_PASS\n";
} catch (Throwable) {
    $rollbackOk = true;
    if ($admin instanceof PDO) {
        foreach (array_reverse($created) as $user) {
            try {
                $admin->exec("DROP USER '$user'@'127.0.0.1'");
            } catch (Throwable) {
                $rollbackOk = false;
            }
        }
    }
    fwrite(STDERR, $rollbackOk
        ? "Provisioning failed. Accounts created by this run were rolled back; verify read-only before retry.\n"
        : "Provisioning failed and automatic rollback was incomplete. Stop and inspect account state before retry.\n");
    exit(1);
}
