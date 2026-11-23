<?php
declare(strict_types=1);
define('BOOKING_RESET_ACCESS_TEST',true);
require dirname(__DIR__).'/scripts/booking-reset-access-readonly.php';

final class AccessStatement extends PDOStatement {
    public function __construct(private array $rows){}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {return $this->rows[0]??false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {return $this->rows;}
}
final class AccessPdo extends PDO {
    public array $seen=[];
    public function __construct(private array $identity,private ?int $unexpectedCode=null){}
    public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false {
        $this->seen[]=$query;
        if(str_starts_with($query,'SELECT @@hostname'))return new AccessStatement([$this->identity]);
        if(str_contains($query,'information_schema.VIEW_TABLE_USAGE'))return new AccessStatement([]);
        if(preg_match('/^SELECT `(deleted_by|deletion_reason|updated_by)` FROM booking_requests WHERE 1=0$/D',$query,$m)){
            if($this->unexpectedCode!==null || $m[1]!=='updated_by'){
                $e=new PDOException('not exported');$e->errorInfo=['42000',$this->unexpectedCode??1143,'not exported'];throw $e;
            }
            return new AccessStatement([]);
        }
        throw new LogicException('Unexpected or non-read-only query');
    }
}
$n=0;$check=static function(bool $ok,string $label)use(&$n){if(!$ok)throw new RuntimeException($label);$n++;};
$identity=['host'=>'test-host','port'=>3306,'uuid'=>RESET_UUID,'db'=>RESET_DB,'version'=>'8.4.9','account'=>'up_van_auth_test@127.0.0.1'];
$db=new AccessPdo($identity);$runtime=resetAccessProbe($db);
$check(!$runtime['dependencies']['pass'] && $runtime['dependencies']['actual_count']===0,'Invisible runtime metadata is not accepted');
$check($runtime['rollback_select']['deleted_by']['status']==='DENIED','deleted_by permission is individually checked');
$check($runtime['rollback_select']['deletion_reason']['status']==='DENIED','deletion_reason permission is individually checked after earlier denial');
$check($runtime['rollback_select']['updated_by']['status']==='READABLE','updated_by probe runs even when other columns are denied');
$check(count($db->seen)===5,'Only identity, metadata and three no-row SELECT probes run');
$metaIdentity=$identity;$metaIdentity['account']='root@localhost';
$metadata=['identity'=>$metaIdentity,'observed_at_epoch'=>1000,'dependencies'=>[[RESET_DB,'vehicle_assignments'],[RESET_DB,'booking_requests']],'view_definition_sha256'=>str_repeat('a',64),'view_definition_sha256_after'=>str_repeat('a',64)];
$result=resetCompareMetadataReport($runtime,$metadata,1020);
$check($result['evidence_matches'],'Separate backup account on same database can explain invisible metadata');
$check(!$result['reset_authorized'] && $result['ddl_change_protection']==='NOT_ESTABLISHED_BY_REPORT','Diagnostic evidence cannot authorize reset or claim DDL protection');
foreach([
    ['identity',array_replace($metaIdentity,['uuid'=>'wrong']),'metadata_identity_mismatch'],
    ['identity',array_replace($metaIdentity,['port'=>3307]),'metadata_identity_mismatch'],
    ['identity',$identity,'metadata_account_not_separate'],
    ['observed_at_epoch',900,'metadata_report_not_fresh'],
    ['observed_at_epoch',1100,'metadata_report_not_fresh'],
    ['dependencies',[],'metadata_dependencies_incomplete'],
    ['view_definition_sha256_after',str_repeat('b',64),'metadata_definition_changed_or_unavailable'],
] as [$key,$value,$reason]){
    $changed=$metadata;$changed[$key]=$value;$r=resetCompareMetadataReport($runtime,$changed,1020);
    $check(!$r['evidence_matches'] && in_array($reason,$r['reasons'],true),'Reject '.$reason);
}
$badRuntime=$runtime;$badRuntime['identity']=$metaIdentity;
$check(in_array('runtime_account_mismatch',resetCompareMetadataReport($badRuntime,$metadata,1020)['reasons'],true),'Backup/root account cannot substitute for runtime');
try{resetAccessProbe(new AccessPdo($identity,1054));throw new LogicException('SQL error incorrectly hidden');}catch(PDOException $e){$check(($e->errorInfo[1]??null)===1054,'SQL error propagates instead of being treated as permission filtering');}
echo "PASS: reset access diagnostic ($n assertions); no MySQL connection made.\n";
