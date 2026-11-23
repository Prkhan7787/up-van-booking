<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\RuntimeColumnProbe;
final class RuntimeProbeStatement extends PDOStatement
{
    public function __construct(private readonly mixed $value=null) {}
    public function execute(?array $params=null): bool { return true; }
    public function fetchColumn(int $column=0): mixed { return $this->value; }
}

final class RuntimeProbePdo extends PDO
{
    public function __construct(private readonly mixed $metadataValue,private readonly ?PDOException $runtimeFailure=null) {}
    public function prepare(string $query,array $options=[]): PDOStatement|false { return new RuntimeProbeStatement($this->metadataValue); }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {
        if($this->runtimeFailure!==null)throw $this->runtimeFailure;
        return new RuntimeProbeStatement();
    }
}

$checks=0;
$check=static function(bool $condition,string $name)use(&$checks):void {
    if(!$condition){fwrite(STDERR,'FAIL: '.$name.PHP_EOL);exit(1);}
    $checks++;
};
$pdoError=static function(int $native):PDOException {
    $error=new PDOException('redacted');
    $error->errorInfo=['42000',$native,'redacted'];
    return $error;
};

$hiddenDenied=RuntimeColumnProbe::inspect(new RuntimeProbePdo(false,$pdoError(1143)),'vehicles','registration_province');
$check($hiddenDenied['status']==='NOT_VISIBLE' && $hiddenDenied['readable']===false,'column-level SELECT denial without metadata is NOT_VISIBLE, never PRESENT');
$check($hiddenDenied['reason_code']==='COLUMN_PRIVILEGE_DENIED' && $hiddenDenied['native_code']===1143,'safe diagnostic preserves only denial code');

$confirmedDenied=RuntimeColumnProbe::inspect(new RuntimeProbePdo('registration_province',$pdoError(1143)),'vehicles','registration_province');
$check($confirmedDenied['status']==='PRESENT' && $confirmedDenied['readable']===false,'metadata-confirmed column remains PRESENT while readability is denied');

$absent=RuntimeColumnProbe::inspect(new RuntimeProbePdo(false,$pdoError(1054)),'vehicles','registration_province');
$check($absent['status']==='ABSENT' && $absent['reason_code']==='COLUMN_ABSENT','MySQL unknown-column response proves absence');

$visible=RuntimeColumnProbe::inspect(new RuntimeProbePdo(false),'vehicles','registration_province');
$check($visible['status']==='PRESENT' && $visible['readable']===true,'successful runtime probe proves presence despite hidden metadata');

$unknownError=RuntimeColumnProbe::inspect(new RuntimeProbePdo(false,$pdoError(1205)),'vehicles','registration_province');
$check($unknownError['status']==='ERROR' && $unknownError['reason_code']==='SCHEMA_RUNTIME_PROBE_FAILED','unrelated SQL failure remains ERROR, not schema evidence');

$invalidTarget=RuntimeColumnProbe::inspect(new RuntimeProbePdo(false),'booking_requests','registration_province');
$check($invalidTarget['status']==='ERROR' && $invalidTarget['reason_code']==='SCHEMA_PROBE_TARGET_REJECTED','probe rejects non-allowlisted table-column pair');

echo 'PASS: '.$checks.' runtime column probe offline checks; no database connection or mutation.'.PHP_EOL;
