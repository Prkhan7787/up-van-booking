<?php
declare(strict_types=1);
namespace App\Database;
use App\Core\Env;
use PDO;
use PDOException;

final class ConnectionFactory
{
    public static function config(string $purpose = 'runtime'): array
    {
        $connection = match ($purpose) {
            'runtime' => ['prefix' => 'DB_', 'database' => MigrationTarget::PRODUCTION, 'username' => 'up_van_app'],
            'migration' => ['prefix' => 'MIGRATION_DB_', 'database' => MigrationTarget::PRODUCTION, 'username' => 'up_van_migrator'],
            'test' => ['prefix' => 'TEST_DB_', 'database' => MigrationTarget::TEST, 'username' => 'up_van_test'],
            'auth_test' => ['prefix' => 'AUTH_TEST_DB_', 'database' => MigrationTarget::TEST, 'username' => 'up_van_auth_test'],
            'reset_maintenance' => ['prefix' => 'RESET_MAINTENANCE_DB_', 'database' => MigrationTarget::TEST, 'username' => 'up_van_reset_maintenance'],
            'test_provisioner' => ['prefix' => 'TEST_PROVISIONER_DB_', 'database' => MigrationTarget::TEST, 'username' => 'up_van_test_provisioner'],
            'migration_test' => ['prefix' => 'MIGRATION_TEST_DB_', 'database' => MigrationTarget::TEST, 'username' => 'up_van_test_migrator'],
            'migration_test_006' => ['prefix' => 'MIGRATION_TEST_DB_', 'database' => MigrationTarget::TEST, 'username' => 'up_van_test_migrator'],
            'migration_test_006_resume' => ['prefix' => 'MIGRATION_TEST_DB_', 'database' => MigrationTarget::TEST, 'username' => 'up_van_test_migrator'],
            'migration_test_007' => ['prefix' => 'MIGRATION_TEST_DB_', 'database' => MigrationTarget::TEST, 'username' => 'up_van_test_migrator'],
            'migration_test_008' => ['prefix' => 'MIGRATION_TEST_DB_', 'database' => MigrationTarget::TEST, 'username' => 'up_van_test_migrator'],
            'preflight' => ['prefix' => 'PREFLIGHT_DB_', 'database' => MigrationTarget::PRODUCTION, 'username' => 'up_van_preflight'],
            default => throw new SafetyException('Unknown connection purpose.'),
        };
        $prefix = $connection['prefix'];
        $config = [
            'host' => Env::get($prefix . 'HOST', Env::get('DB_HOST', '127.0.0.1')),
            'port' => Env::get($prefix . 'PORT', Env::get('DB_PORT', '3306')),
            'database' => Env::get($prefix . 'DATABASE', $connection['database']),
            'username' => Env::get($prefix . 'USERNAME'),
            'password' => Env::get($prefix . 'PASSWORD'),
        ];
        if(in_array($purpose,['migration_test_007','migration_test_008'],true))Migration007TargetIdentity::assertConfig($config);
        if($purpose==='reset_maintenance' && ($config['port']!=='3306' || $config['host']!=='127.0.0.1' || $config['database']!==MigrationTarget::TEST))throw new SafetyException('Reset maintenance target rejected.');
        if ($config['database'] !== $connection['database']) throw new SafetyException('Database target rejected.');
        if ($config['username'] !== $connection['username']) throw new SafetyException('MySQL account does not match connection purpose.');
        if ($config['host'] !== '127.0.0.1') throw new SafetyException('MySQL account host must match 127.0.0.1.');
        if (!ctype_digit($config['port']) || (int)$config['port'] < 1 || (int)$config['port'] > 65535) throw new SafetyException('Invalid database port.');
        if ($config['password'] === '') throw new SafetyException('Dedicated project credentials are not configured.');
        return $config;
    }

    public static function connect(string $purpose = 'runtime', bool $selectDatabase = true): PDO
    {
        $config = self::config($purpose);
        if($purpose==='migration_test_006_resume') Migration006RestoreIdentity::assertConfig($config);
        $pdo = self::openPdo($config, $selectDatabase);
        try {
            if(in_array($purpose,['migration_test_007','migration_test_008'],true))Migration007TargetIdentity::inspect($pdo);
            self::assertConnectionIdentity($pdo, $purpose, $config['database'], $config['username'], $config['host'], $selectDatabase);
            if($purpose==='reset_maintenance' && (string)$pdo->query('SELECT @@server_uuid')->fetchColumn()!=='77218fbd-d66d-11f1-9a0a-5c2886f057d5')throw new SafetyException('Reset maintenance server UUID rejected.');
            $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            if (!preg_match('/^8\.4\.\d+(?:[-.]|$)/D', $version)) throw new SafetyException('MySQL 8.4 server is required.');
            if ($selectDatabase && $pdo->query('SELECT DATABASE()')->fetchColumn() !== $config['database']) throw new SafetyException('Connected database target mismatch.');
            $pdo->exec("SET time_zone = '+00:00'");
            if($purpose==='migration_test_006_resume') self::assertMigration006ResumeGrants($pdo);
            elseif($purpose==='migration_test_006') self::assertMigration006Grants($pdo);
            elseif(in_array($purpose,['migration_test_007','migration_test_008'],true)) self::assertMigration007Grants($pdo);
            elseif($purpose==='reset_maintenance'){
                $resolved=GrantVerifier::currentUserGrants($pdo);
                $partialRevokes=(bool)$pdo->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn();
                $result=GrantVerifier::evaluate($resolved['grants'],MigrationTarget::TEST,BookingResetMaintenanceGrantPlan::rules(),$partialRevokes,$resolved['roles_resolved']);
                if($result['status']!=='PASS' || $result['missing_privileges']!==[] || $result['unexpected_privileges']!==[])throw new SafetyException('Reset maintenance grants do not match the reviewed scope.');
            }
            else self::assertScopedGrants($pdo, $config['database'], in_array($purpose,['migration','migration_test'],true));
            return $pdo;
        } catch (PDOException $error) {
            throw SafeDatabaseException::fromPdo($error, false);
        }
    }

    /** Staged SELECT/SHOW-only diagnostic; never returns an unguarded PDO handle. */
    public static function diagnoseMigrationTestConnection(): array
    {
        $result = [
            'mode'=>'read_only_diagnostic', 'target'=>MigrationTarget::TEST, 'status'=>'FAIL',
            'checks'=>['CONFIG'=>'PENDING','PDO_AUTH'=>'PENDING','CONNECTION_IDENTITY'=>'PENDING','GRANTS'=>'PENDING'],
        ];
        $fail = static function (string $step, string $code, ?string $sqlState = null, ?int $nativeCode = null) use (&$result): array {
            $result['step']=$step; $result['code']=$code;
            if ($sqlState!==null) $result['sqlstate']=$sqlState;
            if ($nativeCode!==null) $result['native_code']=$nativeCode;
            foreach (array_keys($result['checks']) as $name) {
                if ($name===$step) { $result['checks'][$name]='FAIL'; break; }
                if ($result['checks'][$name]==='PENDING') $result['checks'][$name]='NOT_RUN';
            }
            return $result;
        };

        try {
            $config=self::config('migration_test');
            $result['checks']['CONFIG']='PASS';
            $result['configuration']=['profile'=>'migration_test','host'=>$config['host'],'port'=>(int)$config['port'],
                'database'=>$config['database'],'username'=>$config['username'],'password'=>'SET'];
        } catch (SafetyException $error) {
            return $fail('CONFIG',MigrationTestConnectionDiagnostic::configFailureCode($error->getMessage()));
        } catch (Throwable) {
            return $fail('CONFIG','CONFIG_REJECTED');
        }

        try {
            $pdo=self::openPdo($config,true);
            $result['checks']['PDO_AUTH']='PASS';
        } catch (SafeDatabaseException $error) {
            return $fail('PDO_AUTH',MigrationTestConnectionDiagnostic::pdoFailureCode($error->sqlState,$error->nativeCode),$error->sqlState,$error->nativeCode);
        } catch (Throwable) {
            return $fail('PDO_AUTH','PDO_CONNECT_FAILED');
        }

        try {
            self::assertConnectionIdentity($pdo,'migration_test',$config['database'],$config['username'],$config['host'],true);
            $version=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
            if (!preg_match('/^8\\.4\\.\\d+(?:[-.]|$)/D',$version)) return $fail('CONNECTION_IDENTITY','MYSQL_VERSION_UNSUPPORTED');
            $result['checks']['CONNECTION_IDENTITY']='PASS';
        } catch (PDOException $error) {
            $safe=SafeDatabaseException::fromPdo($error);
            return $fail('CONNECTION_IDENTITY','IDENTITY_QUERY_FAILED',$safe->sqlState,$safe->nativeCode);
        } catch (SafetyException $error) {
            return $fail('CONNECTION_IDENTITY',MigrationTestConnectionDiagnostic::identityMessageCode($error->getMessage()));
        } catch (Throwable) {
            return $fail('CONNECTION_IDENTITY','IDENTITY_CHECK_FAILED');
        }

        try {
            self::assertScopedGrants($pdo,MigrationTarget::TEST,true);
            $result['checks']['GRANTS']='PASS';
        } catch (PDOException $error) {
            $safe=SafeDatabaseException::fromPdo($error);
            return $fail('GRANTS','GRANT_QUERY_FAILED',$safe->sqlState,$safe->nativeCode);
        } catch (SafetyException $error) {
            $grantDetails=$error instanceof GrantGuardException ? $error->diagnostics : [];
            $failed=$fail('GRANTS',MigrationTestConnectionDiagnostic::grantFailureCode($error->getMessage()));
            if ($grantDetails!==[]) $failed['grant_diagnostics']=$grantDetails;
            return $failed;
        } catch (Throwable) {
            return $fail('GRANTS','GRANT_CHECK_FAILED');
        }

        $result['status']='PASS'; $result['step']='COMPLETE'; $result['code']='MIGRATION_TEST_CONNECTION_AND_GRANTS_VALID';
        return $result;
    }

    private static function openPdo(array $config, bool $selectDatabase): PDO
    {
        if (!extension_loaded('pdo_mysql')) throw new SafetyException('PDO MySQL driver is unavailable.');
        $host = $config['host'] === '::1' ? '[::1]' : $config['host'];
        $dsn = "mysql:host={$host};port={$config['port']};charset=utf8mb4";
        if ($selectDatabase) $dsn .= ';dbname=' . $config['database'];
        try {
            return new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);
        } catch (PDOException $error) {
            throw SafeDatabaseException::fromPdo($error, true);
        }
    }

    public static function evaluateAuthRuntimeGrants(array $grants, bool $partialRevokes, bool $rolesResolved, bool $fuelEnabled=false): array
    {
        $profiles=[
            'auth'=>AuthRuntimeGrantPlan::rules(),
            'admin_users'=>AdminUserGrantPlan::rules(),
            'system_logs'=>SystemLogGrantPlan::rules(),
            'booking_read'=>RequesterBookingReadGrantPlan::rules(),
            'booking_read_logs'=>RequesterBookingReadGrantPlan::rules(true),
            'calendar'=>BookingCalendarGrantPlan::rules(),
            'calendar_logs'=>BookingCalendarGrantPlan::rules(true),
            'vehicle_calendar'=>VehicleCalendarGrantPlan::rules(),
            'vehicles'=>VehicleAdminGrantPlan::rules(),
            'vehicles_booking_read'=>VehicleAdminGrantPlan::rules(true),
            'vehicles_vehicle_calendar'=>VehicleAdminGrantPlan::rules(true,true),
              'vehicles_province'=>VehicleAdminGrantPlan::rules(false,false,true),
              'vehicles_booking_read_province'=>VehicleAdminGrantPlan::rules(true,false,true),
              'vehicles_vehicle_calendar_province'=>VehicleAdminGrantPlan::rules(true,true,true),
              'vehicles_requested_vehicle'=>VehicleAdminGrantPlan::rules(true,true,false,true),
              'vehicles_province_requested_vehicle'=>VehicleAdminGrantPlan::rules(true,true,true,true),
        ];
        if($fuelEnabled) {
            $profiles+=['vehicles_fuel'=>VehicleAdminGrantPlan::rules(false,false,false,false,true),
                'vehicles_booking_read_fuel'=>VehicleAdminGrantPlan::rules(true,false,false,false,true),
                'vehicles_vehicle_calendar_fuel'=>VehicleAdminGrantPlan::rules(true,true,false,false,true),
                'vehicles_province_fuel'=>VehicleAdminGrantPlan::rules(false,false,true,false,true),
                'vehicles_booking_read_province_fuel'=>VehicleAdminGrantPlan::rules(true,false,true,false,true),
                'vehicles_vehicle_calendar_province_fuel'=>VehicleAdminGrantPlan::rules(true,true,true,false,true),
                'vehicles_requested_vehicle_fuel'=>VehicleAdminGrantPlan::rules(true,true,false,true,true),
                'vehicles_province_requested_vehicle_fuel'=>VehicleAdminGrantPlan::rules(true,true,true,true,true)];
        }
        $baseProfiles=$profiles;
        foreach($baseProfiles as $name=>$base) {
            foreach(BookingCapabilityGrantGuard::additions() as $suffix=>$addition) {
                $profiles[$name.'_'.$suffix]=BookingCapabilityGrantGuard::merge($base,$addition);
            }
        }
        return GrantVerifier::evaluateProfiles($grants,MigrationTarget::TEST,$profiles,$partialRevokes,$rolesResolved);
    }

    public static function assertScopedGrants(PDO $pdo, string $database, bool $allowDdl = false): void
    {
        $purpose = (string)$pdo->query('SELECT CURRENT_USER()')->fetchColumn();
        $partialRevokes = (bool)$pdo->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn();
        if ($purpose === 'up_van_auth_test@127.0.0.1' && $database === MigrationTarget::TEST) {
            $resolved = GrantVerifier::currentUserGrants($pdo);
            $fuelType=RuntimeColumnProbe::inspect($pdo,'vehicles','fuel_type');
            if($fuelType['status']==='ERROR')throw new SafetyException('Runtime fuel schema is inconsistent or not visible.');
            $result = self::evaluateAuthRuntimeGrants($resolved['grants'], $partialRevokes, $resolved['roles_resolved'],$fuelType['status']==='PRESENT');
            if ($result['status'] !== 'PASS') throw new SafetyException('Auth runtime grants do not match the reviewed table/column scope.');
            return;
        }
        if ($purpose === 'up_van_test_provisioner@127.0.0.1' && $database === MigrationTarget::TEST) {
            $resolved = GrantVerifier::currentUserGrants($pdo);
            $result = GrantVerifier::evaluate($resolved['grants'], $database, TestProvisionerGrantPlan::rules(), $partialRevokes, $resolved['roles_resolved']);
            if ($result['status'] !== 'PASS') throw new SafetyException('Test provisioner grants do not match the reviewed table/column scope.');
            return;
        }
        if ($purpose === MigrationTarget::expectedMigrator(MigrationTarget::TEST) . '@127.0.0.1'
            && $database === MigrationTarget::TEST && $allowDdl) {
            $resolved = GrantVerifier::currentUserGrants($pdo);
            self::validateTestMigrationGrants($resolved['grants'], $partialRevokes, $resolved['roles_resolved']);
            return;
        }
        self::validateGrants($pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN), $database, $allowDdl, $partialRevokes);
    }

    /** Validate the reviewed test migrator capability set, including MySQL 8.4 column grants. */
    public static function validateTestMigrationGrants(array $grants, bool $partialRevokes = false, bool $rolesResolved = true): void
    {
        $result = GrantVerifier::evaluate($grants, MigrationTarget::TEST, MigrationTestGrantPlan::rules(), $partialRevokes, $rolesResolved);
        if ($result['status'] !== 'PASS') {
            throw new GrantGuardException(MigrationTestGrantPlan::safeDiagnostics($result));
        }
    }

    public static function assertMigration006Grants(PDO $pdo): void
    {
        if($pdo->query('SELECT DATABASE()')->fetchColumn()!==MigrationTarget::TEST
            || $pdo->query('SELECT CURRENT_USER()')->fetchColumn()!=='up_van_test_migrator@127.0.0.1')throw new SafetyException('MIGRATION_006_ACCOUNT_REJECTED');
        $resolved=GrantVerifier::currentUserGrants($pdo);
        $phase=Migration006GrantPlan::schemaPhase($pdo);
        self::validateMigration006Grants($resolved['grants'],(bool)$pdo->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn(),$resolved['roles_resolved'],$phase);
    }

    public static function validateMigration006Grants(array $grants,bool $partialRevokes=false,bool $rolesResolved=true,string $phase='post'): void
    {
        $result=GrantVerifier::evaluateProfiles($grants,MigrationTarget::TEST,Migration006GrantPlan::profiles($phase),$partialRevokes,$rolesResolved);
        if($result['status']!=='PASS')throw new GrantGuardException(MigrationTestGrantPlan::safeDiagnostics($result),'006');
    }

    public static function assertMigration007Grants(PDO $pdo): void
    {
        if($pdo->query('SELECT DATABASE()')->fetchColumn()!==MigrationTarget::TEST
            || $pdo->query('SELECT CURRENT_USER()')->fetchColumn()!=='up_van_test_migrator@127.0.0.1')throw new SafetyException('MIGRATION_007_ACCOUNT_REJECTED');
        $state=(new MigrationRunner($pdo,MigrationTarget::TEST))->status();
        $phase=Migration007GrantPlan::phaseFromLedger($state['migrations']);
        $resolved=GrantVerifier::currentUserGrants($pdo);
        $result=GrantVerifier::evaluateProfiles($resolved['grants'],MigrationTarget::TEST,Migration007GrantPlan::profiles($phase),(bool)$pdo->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn(),$resolved['roles_resolved']);
        if($result['status']!=='PASS')throw new GrantGuardException(MigrationTestGrantPlan::safeDiagnostics($result),'007');
    }

    /** Explicit restore-only entry point; partial schema is checked by Resume preflight. */
    public static function assertMigration006ResumeGrants(PDO $pdo): void
    {
        Migration006RestoreIdentity::inspect($pdo);
        $resolved=GrantVerifier::currentUserGrants($pdo);
        self::validateMigration006Grants($resolved['grants'],(bool)$pdo->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn(),$resolved['roles_resolved'],'pre');
    }

    public static function validateGrants(array $grants, string $database, bool $allowDdl = false, bool $partialRevokes = false): void
    {
        $hasUsersAlter = false; $hasUsersInsert = false; $hasCreateCoverage = false;
        foreach ($grants as $grant) {
            if (preg_match('/^GRANT USAGE ON \*\.\* TO /', $grant)) continue;
            if (!preg_match('/^GRANT (.+) ON `([^`]+)`\.(\*|`[a-z_]+`) TO /', $grant, $m) || str_contains($grant, 'WITH GRANT OPTION')) {
                throw new SafetyException('Account privileges exceed the allowed database scope.');
            }
            $expectedSchema = $m[3] === '*' && !$partialRevokes ? str_replace('_','\\_',$database) : $database;
            $privileges = array_map('trim', explode(',', $m[1]));
            $allowed = $allowDdl ? ['SELECT','INSERT','UPDATE','CREATE','CREATE VIEW','INDEX','REFERENCES','SHOW VIEW','ALTER'] : ['SELECT','INSERT','UPDATE','DELETE','SHOW VIEW'];
            if ($m[2] !== $expectedSchema || array_diff($privileges,$allowed) !== []) throw new SafetyException('Account privileges or schema wildcard scope are not allowed.');
            if ($allowDdl && in_array('CREATE',$privileges,true) && ($m[3] === '*' || $m[3] === '`users`')) $hasCreateCoverage = true;
            if ($m[3] === '`users`' && in_array('ALTER',$privileges,true)) $hasUsersAlter = true;
            if ($m[3] === '`users`' && in_array('INSERT',$privileges,true)) $hasUsersInsert = true;
        }
        if ($hasUsersAlter && (!$hasUsersInsert || !$hasCreateCoverage)) throw new SafetyException('ALTER on users requires effective INSERT and CREATE privilege coverage for a dedicated migration account.');
    }

    public static function requireMigrationApproval(string $target): void
    {
        MigrationTarget::assertKnown($target);
        $gate = $target === MigrationTarget::PRODUCTION
            ? Env::get('DB_PHASE_B_APPROVED')
            : Env::get('TEST_DB_EXECUTION_APPROVED');
        if ($gate !== 'true') {
            throw new SafetyException($target === MigrationTarget::PRODUCTION
                ? 'Production migration approval is not enabled.'
                : 'Test migration approval is not enabled.');
        }
    }

    public static function validateMigrationIdentity(string $target, string $database, string $currentUser): void
    {
        MigrationTarget::assertKnown($target);
        $expectedUser = MigrationTarget::expectedMigrator($target) . '@127.0.0.1';
        if ($database !== $target || $currentUser !== $expectedUser) {
            throw new SafetyException('Migration connection identity does not match the approved target.');
        }
    }

    public static function assertMigrationConnection(PDO $pdo, string $target): void
    {
        MigrationTarget::assertKnown($target);
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $currentUser = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
        self::validateMigrationIdentity($target, $database, $currentUser);
    }

    private static function assertConnectionIdentity(PDO $pdo, string $purpose, string $database, string $username, string $host, bool $selectDatabase): void
    {
        $targetPurpose = match ($purpose) {
            'runtime', 'preflight' => MigrationTarget::PRODUCTION,
            'migration' => MigrationTarget::PRODUCTION,
            'test', 'auth_test', 'reset_maintenance', 'test_provisioner', 'migration_test', 'migration_test_006', 'migration_test_006_resume', 'migration_test_007', 'migration_test_008' => MigrationTarget::TEST,
            default => throw new SafetyException('Unknown connection purpose.'),
        };
        if ($selectDatabase) {
            $actualDatabase = $pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($actualDatabase !== $database) {
                throw new SafetyException('Connected database target mismatch.');
            }
        }
        $currentUser = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
        if ($currentUser !== $username . '@' . $host || $database !== $targetPurpose) {
            throw new SafetyException('Authenticated MySQL account does not match connection purpose.');
        }
    }
}
