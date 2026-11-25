<?php
declare(strict_types=1);
namespace App\Database;
use PDO;

final class Migration011ApplyGuard
{
    public const ACCOUNT='up_van_migration011_apply';
    public const PURPOSE='migration_011_apply_test';
    public static function requireApproval(): void
    {
        if (getenv('TEST_DB_EXECUTION_APPROVED')!=='true'||getenv('TEST_MIGRATION_011_APPLY_APPROVED')!=='true') throw new SafetyException('MIGRATION_011_APPROVAL_REQUIRED');
    }
    public static function assertIdentity(PDO $db): void
    {
        DriverDatabaseIdentity::inspect($db,self::ACCOUNT);
        if ($db->query('SELECT VERSION()')->fetchColumn()!=='8.4.9') throw new SafetyException('MIGRATION_011_VERSION_REJECTED');
    }
    public static function rules(): array
    {
        return ['database'=>[],'tables'=>[
            'microsoft_identities'=>['CREATE'=>null],
            'users'=>['REFERENCES'=>null],
            'schema_migrations'=>[
                'SELECT'=>['version','checksum','status','completed_statements','total_statements'],
                'INSERT'=>['version','checksum','status','total_statements'],
                'UPDATE'=>['status','completed_statements','finished_at','failure_code'],
            ],
        ]];
    }
    public static function assertGrants(PDO $db): void
    {
        $resolved=GrantVerifier::currentUserGrants($db);
        $revokes=$db->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn();
        if (!$resolved['roles_resolved']||$resolved['active_roles']!=='NONE'||$db->query('SELECT @@GLOBAL.mandatory_roles')->fetchColumn()!==''
            ||!in_array($revokes,[0,1,'0','1'],true)) throw new SafetyException('MIGRATION_011_GRANTS_REJECTED');
        foreach($resolved['grants'] as $g)if(!is_string($g)||!preg_match('/\AGRANT [A-Z]/D',$g)||stripos($g,'WITH ADMIN OPTION')!==false)throw new SafetyException('MIGRATION_011_GRANTS_REJECTED');
        if (GrantVerifier::evaluate($resolved['grants'],MigrationTarget::TEST,self::rules(),(bool)$revokes,true)['status']!=='PASS') throw new SafetyException('MIGRATION_011_GRANTS_REJECTED');
    }
}
