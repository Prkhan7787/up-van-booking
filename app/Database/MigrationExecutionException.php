<?php
declare(strict_types=1);
namespace App\Database;

/** Only static migration labels and numeric driver diagnostics leave the Runner. */
final class MigrationExecutionException extends SafetyException implements \JsonSerializable
{
    private function __construct(private readonly array $safe)
    {
        parent::__construct((string)($safe['code']??'MIGRATION_EXECUTION_FAILED'));
    }

    public static function fromFailure(\Throwable $error,int $statement,string $stage,string $migration='006_vehicle_province_and_requested_vehicle'): self
    {
        $stages=['DDL','LEDGER_PROGRESS','POSTCONDITIONS','LEDGER_APPLIED'];
        $code=match($migration){MigrationPlan::BOOKING008['version']=>'MIGRATION_008_EXECUTION_FAILED',MigrationPlan::VEHICLE007['version']=>'MIGRATION_007_EXECUTION_FAILED',default=>'MIGRATION_006_EXECUTION_FAILED'};
        $safe=['status'=>'FAIL','code'=>$code,
            'migration'=>$migration,
            'statement_number'=>in_array($statement,[1,2],true)?$statement:null,
            'execution_stage'=>in_array($stage,$stages,true)?$stage:'UNKNOWN',
            'partial_apply_possible'=>true,'automatic_retry'=>false];
        if($error instanceof \PDOException) {
            $db=SafeDatabaseException::fromPdo($error);
            $safe['sqlstate']=$db->sqlState;$safe['native_code']=$db->nativeCode;
            $safe['failure_type']='PDO_FAILURE';
        } else {
            $safe['failure_type']=$error instanceof \Error?'PHP_FAILURE':'SAFETY_OR_LEDGER_FAILURE';
            if($error instanceof SafetyException && preg_match('/^MIGRATION_00[678]_[A-Z_]+$/D',$error->getMessage()))$safe['guard_code']=$error->getMessage();
        }
        return new self($safe);
    }

    public function diagnostics(): array{return $this->safe;}
    public function jsonSerialize(): array{return $this->safe;}
}
