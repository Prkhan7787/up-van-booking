<?php
declare(strict_types=1);
namespace App\Database;

use PDO;

final class Migration010ApplyGuard
{
    public const ACCOUNT = 'up_van_migration010_apply';
    public const PURPOSE = 'migration_010_apply_test';
    public const MYSQL_VERSION = '8.4.9';

    public static function requireApproval(): void
    {
        // Both approvals are explicit for this process. A saved .env cannot authorize 010.
        if (getenv('TEST_DB_EXECUTION_APPROVED') !== 'true' || getenv('TEST_MIGRATION_010_APPLY_APPROVED') !== 'true') {
            throw new SafetyException('MIGRATION_010_APPLY_APPROVAL_REQUIRED');
        }
    }

    public static function assertIdentity(PDO $db): void
    {
        DriverDatabaseIdentity::inspect($db, self::ACCOUNT);
        if ($db->query('SELECT VERSION()')->fetchColumn() !== self::MYSQL_VERSION) {
            throw new SafetyException('MIGRATION_010_MYSQL_VERSION_REJECTED');
        }
    }

    public static function rules(): array
    {
        return ['database'=>[], 'tables'=>[
            // MySQL requires ALTER, CREATE and INSERT for ALTER TABLE, and
            // REFERENCES on the parent table for the new foreign key.
            'users'=>['ALTER'=>null, 'CREATE'=>null, 'INSERT'=>null, 'REFERENCES'=>null],
            // ADD UNIQUE KEY is part of the pinned ALTER TABLE, not a separate CREATE INDEX.
            'drivers'=>['ALTER'=>null, 'CREATE'=>null, 'INSERT'=>null],
            'roles'=>['SELECT'=>['name','description'], 'INSERT'=>['name','description']],
            'schema_migrations'=>[
                'SELECT'=>['version','checksum','status','completed_statements','total_statements'],
                'INSERT'=>['version','checksum','status','total_statements'],
                'UPDATE'=>['status','completed_statements','finished_at','failure_code'],
            ],
            // REFERENCES gives visibility for existing schema checks without granting SELECT on application rows.
            'auth_login_buckets'=>['REFERENCES'=>null],
            'vehicles'=>['REFERENCES'=>null],
            'booking_requests'=>['REFERENCES'=>null],
        ]];
    }

    public static function assertGrants(PDO $db): void
    {
        $grants = GrantVerifier::currentUserGrants($db);
        $mandatory = $db->query('SELECT @@GLOBAL.mandatory_roles')->fetchColumn();
        $revokes = $db->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn();
        if (!$grants['roles_resolved'] || $grants['active_roles'] !== 'NONE' || $mandatory !== ''
            || !in_array($revokes, [0,1,'0','1'], true)) {
            throw new SafetyException('MIGRATION_010_APPLY_GRANTS_REJECTED');
        }
        foreach ($grants['grants'] as $grant) {
            // MySQL emits privilege grants using keyword clauses. Quoted role
            // assignments are rejected even if the role is currently inactive.
            if (!is_string($grant) || !preg_match('/\AGRANT [A-Z]/D', $grant)
                || stripos($grant, 'WITH ADMIN OPTION') !== false) {
                throw new SafetyException('MIGRATION_010_APPLY_GRANTS_REJECTED');
            }
        }
        if (GrantVerifier::evaluate($grants['grants'], MigrationTarget::TEST, self::rules(), (bool)$revokes, true)['status'] !== 'PASS') {
            throw new SafetyException('MIGRATION_010_APPLY_GRANTS_REJECTED');
        }
    }
}
