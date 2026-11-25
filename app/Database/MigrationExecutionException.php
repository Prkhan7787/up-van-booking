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

    public static function fromFailure(\Throwable $error,int $statement,string $stage,string $migration='006_vehicle_province_and_requested_vehicle',
        ?int $completed = null, ?int $ledgerCompleted = null, ?bool $ledgerApplied = null): self
    {
        $stages=['DDL','LEDGER_PROGRESS','POSTCONDITIONS','LEDGER_APPLIED'];
        $is010=$migration===MigrationPlan::DRIVER010['version'];
        $is011=$migration===MigrationPlan::MICROSOFT011['version'];
        if ($is010||$is011) $stages = [...$stages, 'LEDGER_STARTED', 'STATEMENT', 'LOCK_RELEASE', 'LOCK_CHECK'];
        $code=match($migration){MigrationPlan::MICROSOFT011['version']=>'MIGRATION_011_EXECUTION_FAILED',MigrationPlan::DRIVER010['version']=>'MIGRATION_010_EXECUTION_FAILED',MigrationPlan::BOOKING008['version']=>'MIGRATION_008_EXECUTION_FAILED',MigrationPlan::VEHICLE007['version']=>'MIGRATION_007_EXECUTION_FAILED',default=>'MIGRATION_006_EXECUTION_FAILED'};
        $safe=['status'=>'FAIL','code'=>$code,
            'migration'=>$migration,
            'statement_number'=>in_array($statement,$is011?[1]:($is010?[1,2,3]:[1,2]),true)?$statement:null,
            'execution_stage'=>in_array($stage,$stages,true)?$stage:'UNKNOWN',
            'partial_apply_possible'=>true,'automatic_retry'=>false];
        if ($is010||$is011) {
            $safe['confirmed_completed_statements']=$completed;
            $safe['confirmed_ledger_completed_statements']=$ledgerCompleted;
            $safe['ledger_applied_acknowledged']=$ledgerApplied;
            $safe['partial_apply_possible']=$stage!=='LEDGER_STARTED';
        }
        if($error instanceof \PDOException) {
            $db=SafeDatabaseException::fromPdo($error);
            $safe['sqlstate']=$db->sqlState;$safe['native_code']=$db->nativeCode;
            $safe['failure_type']='PDO_FAILURE';
        } else {
            $safe['failure_type']=$error instanceof \Error?'PHP_FAILURE':'SAFETY_OR_LEDGER_FAILURE';
            if($error instanceof SafetyException && preg_match('/^MIGRATION_(?:00[678]|010|011)_[A-Z0-9_]+$/D',$error->getMessage()))$safe['guard_code']=$error->getMessage();
        }
        return new self($safe);
    }

    public function diagnostics(): array{return $this->safe;}
    public function withLockRelease(bool $confirmed): self{return new self($this->safe+['migration_lock_released'=>$confirmed]);}
    public function jsonSerialize(): array{return $this->safe;}
}
