<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/cli.php';

use App\Core\Env;
use App\Database\ConnectionFactory;
use App\Services\PasswordHasher;
use App\Services\UsernamePolicy;

function stopProvision(string $message, int $status = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($status);
}

if (Env::get('TEST_ADMIN_PROVISION_APPROVED') !== 'true') stopProvision('Test Admin provisioning gate is closed.');
if (($argv[1] ?? '') !== '--confirm=up_van_booking_test') stopProvision('Explicit test database confirmation is required.');
if (PHP_SAPI !== 'cli') stopProvision('CLI only.');

function readLinePrompt(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);
    return is_string($value) ? rtrim($value, "\r\n") : '';
}

$username = trim(readLinePrompt('Admin username: '));
$email = trim(readLinePrompt('Admin email: '));
$displayName = trim(readLinePrompt('Admin display name: '));
if (!UsernamePolicy::isValid($username)) stopProvision('Username must use 3–50 ASCII letters, digits, dot, underscore or hyphen.');
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254 || $displayName === '' || mb_strlen($displayName, 'UTF-8') > 150) stopProvision('Account details are invalid.');

function readHiddenSecret(string $label): string
{
    if (PHP_OS_FAMILY !== 'Windows') stopProvision('Run this provisioning CLI from Windows PowerShell.');
    $script = '$s = Read-Host -AsSecureString "' . $label . '"; $b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s); try { [Console]::Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }';
    $process = proc_open(['powershell.exe','-NoProfile','-Command',$script], [0=>STDIN,1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) stopProvision('Secure password prompt could not start.');
    $secret = stream_get_contents($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || !is_string($secret)) stopProvision('Secure password prompt failed.');
    return rtrim($secret, "\r\n");
}

$password = readHiddenSecret('Password');
$confirmation = readHiddenSecret('Confirm password');
if ($password !== $confirmation || strlen($password) < 6) stopProvision('Password confirmation or minimum length check failed.');

try {
    $db = ConnectionFactory::connect('test_provisioner');
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== 'up_van_booking_test'
        || (string)$db->query('SELECT CURRENT_USER()')->fetchColumn() !== 'up_van_test_provisioner@127.0.0.1') {
        throw new RuntimeException('Test database identity mismatch.');
    }
    // Named lock serializes first-admin creation without broad UPDATE/DELETE grants.
    $lock = $db->prepare('SELECT GET_LOCK(:lock_name, :wait_seconds)');
    $lock->execute(['lock_name'=>'up_van_booking_test:first_admin_provision','wait_seconds'=>10]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Provisioning lock could not be acquired.');
    $lockHeld = true;
    $db->beginTransaction();
    $role = $db->prepare("SELECT id FROM roles WHERE name = 'admin' LIMIT 1");
    $role->execute();
    $roleId = $role->fetchColumn();
    if ($roleId === false) throw new RuntimeException('Admin role reference is unavailable.');
    $existingAdmin = $db->prepare("SELECT COUNT(*) FROM user_roles AS ur INNER JOIN roles AS r ON r.id=ur.role_id WHERE r.name='admin'");
    $existingAdmin->execute();
    if ((int)$existingAdmin->fetchColumn() > 0) throw new RuntimeException('An Admin account already exists.');
    $find = $db->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
    $find->execute(['username'=>UsernamePolicy::normalize($username),'email'=>$email]);
    if ($find->fetchColumn() !== false) throw new RuntimeException('Username or email already exists.');

    $hash = (new PasswordHasher())->hash($password);
    $insert = $db->prepare('INSERT INTO users (email, username, display_name, password_hash, is_active) VALUES (:email, :username, :display_name, :password_hash, 1)');
    $insert->execute(['email'=>$email,'username'=>UsernamePolicy::normalize($username),'display_name'=>$displayName,'password_hash'=>$hash]);
    $userId = (int)$db->lastInsertId();
    $assign = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
    $assign->execute(['user_id'=>$userId,'role_id'=>(int)$roleId]);
    $audit = $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata) VALUES (NULL, :action, :entity_type, :entity_id, :metadata)');
    $audit->execute(['action'=>'auth.test_admin.provisioned','entity_type'=>'user','entity_id'=>(string)$userId,'metadata'=>json_encode(['source'=>'test_cli'],JSON_THROW_ON_ERROR)]);
    $db->commit();
    $password = $confirmation = '';
    $provisioned = true;
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    $password = $confirmation = '';
    $provisioned = false;
} finally {
    if (!empty($lockHeld) && isset($db) && $db instanceof PDO) {
        try {
            $release = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name'=>'up_van_booking_test:first_admin_provision']);
        } catch (Throwable) {
            // Closing the PDO session releases the advisory lock if explicit release fails.
        }
    }
}
if (!empty($provisioned)) fwrite(STDOUT, 'Test Admin provisioned. No password was logged.' . PHP_EOL);
else stopProvision('Provisioning failed without sensitive details. Verify the test database state before retry.');
