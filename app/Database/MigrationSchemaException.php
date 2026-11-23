<?php
declare(strict_types=1);
namespace App\Database;

/** Metadata-only labels; never includes exception text, SQL, or row values. */
final class MigrationSchemaException extends SafetyException
{
    public function __construct(string $code,public readonly string $schemaCheck,public readonly string $schemaField='',public readonly array $origin=[])
    {
        parent::__construct($code);
    }

    public static function safeOrigin(\Throwable $error): array
    {
        $type=match(true) {
            $error instanceof \TypeError=>'TypeError', $error instanceof \ValueError=>'ValueError',
            $error instanceof \ErrorException=>'ErrorException', $error instanceof \Error=>'Error',
            $error instanceof SafetyException=>'SafetyException', $error instanceof \RuntimeException=>'RuntimeException',
            default=>'Exception',
        };
        $result=['error_type'=>$type];
        $name=basename($error->getFile());
        if(in_array($name,['MigrationRunner.php','Migration006GrantPlan.php','MigrationPlan.php','SqlStatements.php','Migration004PostconditionValidator.php','Migration005VehicleValidator.php','Migration006VehicleValidator.php'],true)) {
            $result['error_file']=$name;$result['error_line']=$error->getLine();
        }
        return $result;
    }

    public static function fromFailure(\Throwable $error,MigrationRunner $runner): self
    {
        $message=$error->getMessage();
        $code=preg_match('/^MIGRATION_006_[A-Z_]+$/D',$message)?$message:match($message) {
            'Migration connection mismatch.'=>'DATABASE_TARGET_MISMATCH',
            'Migration 004 users schema postcondition failed.'=>'MIGRATION_004_USERS_SCHEMA_DRIFT',
            'Migration 004 username unique index postcondition failed.'=>'MIGRATION_004_USERNAME_INDEX_DRIFT',
            'Migration 004 username format check postcondition failed.'=>'MIGRATION_004_USERNAME_CHECK_DRIFT',
            'Migration 004 rate-limit table postcondition failed.'=>'MIGRATION_004_RATE_TABLE_DRIFT',
            'Migration 004 bucket scope check postcondition failed.'=>'MIGRATION_004_BUCKET_CHECK_DRIFT',
            'Migration 005 ledger is partial or inconsistent; manual inspection required.'=>'MIGRATION_005_PARTIAL_OR_INVALID',
            'Migration 005 postcondition failed: province column remains.'=>'MIGRATION_005_PROVINCE_REMAINS',
            'Migration 005 postcondition failed: registration unique index mismatch.'=>'MIGRATION_005_REGISTRATION_INDEX_DRIFT',
            'Migration checksum mismatch; review before execution.'=>'MIGRATION_FILE_CHECKSUM_MISMATCH',
            'Migration statement count mismatch.'=>'MIGRATION_FILE_STATEMENT_COUNT_MISMATCH',
            default=>'SCHEMA_LEDGER_CHECK_FAILED',
        };
        $field='';
        if($error instanceof \TypeError)$code='SCHEMA_PHP_TYPE_ERROR';
        elseif($error instanceof \ValueError)$code='SCHEMA_PHP_VALUE_ERROR';
        elseif($error instanceof \Error)$code='SCHEMA_PHP_ERROR';
        if(preg_match('/^Migration 004 rate-limit column postcondition failed: ([a-z_]+)\.([A-Z_]+)\.$/D',$message,$m)) {
            $code='MIGRATION_004_RATE_COLUMN_DRIFT';$field='auth_login_buckets.'.$m[1].'.'.$m[2];
        }
        $check=str_contains($code,'LEDGER')?'LEDGER':$runner->lastReadCheck();
        return new self($code,$check,$field,self::safeOrigin($error));
    }
}
