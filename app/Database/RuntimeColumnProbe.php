<?php
declare(strict_types=1);
namespace App\Database;

use PDO;
use PDOException;

/** Read-only, privilege-aware check for the two optional post-005 columns. */
final class RuntimeColumnProbe
{
    private const COLUMNS=[
        'vehicles'=>['registration_province','fuel_type'],
        'booking_requests'=>['requested_vehicle_id','a4_request_data','deleted_at'],
    ];

    /** @return array{status:string,readable:?bool,source:string,reason_code:string,sqlstate:?string,native_code:?int} */
    public static function inspect(PDO $db,string $table,string $column): array
    {
        if(!in_array($column,self::COLUMNS[$table]??[],true))return self::result('ERROR',null,'allowlist','SCHEMA_PROBE_TARGET_REJECTED',null,null);

        $metadataStatus='UNKNOWN';$metadataCode=null;
        try {
            $metadata=$db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:schema AND TABLE_NAME=:table AND COLUMN_NAME=:column');
            $metadata->execute(['schema'=>'up_van_booking_test','table'=>$table,'column'=>$column]);
            $found=$metadata->fetchColumn();
            if(is_string($found) && $found===$column)$metadataStatus='PRESENT';
            else $metadataStatus='NOT_VISIBLE';
        } catch(PDOException $error) {
            $metadataCode=(int)($error->errorInfo[1]??0);
        } catch(\Throwable) {
            return self::result('ERROR',null,'information_schema','SCHEMA_METADATA_QUERY_FAILED',null,null);
        }

        $sql='SELECT `'.$column.'` FROM `'.$table.'` LIMIT 0';
        try {
            $db->query($sql);
            return self::result('PRESENT',true,$metadataStatus==='PRESENT'?'metadata_and_runtime':'runtime_probe','COLUMN_READABLE',null,null);
        } catch(PDOException $error) {
            $native=(int)($error->errorInfo[1]??0);
            $state=is_string($error->errorInfo[0]??null)?(string)$error->errorInfo[0]:null;
            if($native===1054) {
                if($metadataStatus==='PRESENT')return self::result('ERROR',null,'conflicting_probes','SCHEMA_PROBE_CONFLICT',$state,$native);
                return self::result('ABSENT',null,'runtime_probe','COLUMN_ABSENT',$state,$native);
            }
            if(in_array($native,[1142,1143],true)) {
                if($metadataStatus==='PRESENT')return self::result('PRESENT',false,'information_schema','COLUMN_SELECT_DENIED',$state,$native);
                return self::result('NOT_VISIBLE',false,'runtime_probe','COLUMN_PRIVILEGE_DENIED',$state,$native);
            }
            return self::result('ERROR',null,'runtime_probe','SCHEMA_RUNTIME_PROBE_FAILED',$state,$native>0?$native:null);
        } catch(\Throwable) {
            return self::result('ERROR',null,'runtime_probe','SCHEMA_RUNTIME_PROBE_FAILED',null,null);
        }
    }

    private static function result(string $status,?bool $readable,string $source,string $reason,?string $sqlstate,?int $native): array
    {
        return ['status'=>$status,'readable'=>$readable,'source'=>$source,'reason_code'=>$reason,'sqlstate'=>$sqlstate,'native_code'=>$native];
    }
}
