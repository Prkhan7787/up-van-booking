<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Database\GrantVerifier;
use App\Database\RuntimeColumnProbe;
use App\Database\VehicleAdminGrantPlan;
use App\Modules\Booking\VehicleInput;
use App\Modules\Booking\VehicleRepository;
use App\Modules\Booking\VehicleSchemaValidator;
use App\Services\AuditLogger;
use PDO;

/** Test-only repository supports applied Migration 005 and optional Migration 006. */
final class PdoVehicleRepository implements VehicleRepository
{
    private bool $provinceEnabled=false;
    private bool $fuelEnabled=false;

    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION || $db->getAttribute(PDO::ATTR_EMULATE_PREPARES)!==false) throw new \RuntimeException('VEHICLE_PDO_REJECTED');
        $identity=$db->query('SELECT DATABASE() AS db_name, CURRENT_USER() AS account_name, @@GLOBAL.partial_revokes AS partial_revokes')->fetch(PDO::FETCH_ASSOC);
        if (($identity['db_name'] ?? '')!=='up_van_booking_test' || ($identity['account_name'] ?? '')!=='up_van_auth_test@127.0.0.1') throw new \RuntimeException('VEHICLE_TARGET_REJECTED');
        $provinceProbe=RuntimeColumnProbe::inspect($db,'vehicles','registration_province');
        // Vehicle CRUD's Migration 005 projection is the stable baseline. Use
        // the optional field only when its existence and SELECT access are proven.
        $this->provinceEnabled=$provinceProbe['status']==='PRESENT' && $provinceProbe['readable']===true;
        $provinceColumnCount=$this->provinceEnabled?1:0;
        if($this->provinceEnabled){$shape=self::columnShape($db,'vehicles','registration_province');if($shape!==['varchar(100)','YES'])throw new \RuntimeException('VEHICLE_PROVINCE_SCHEMA_DRIFT');}
        $fuelType=RuntimeColumnProbe::inspect($db,'vehicles','fuel_type');
        if($fuelType['status']==='ERROR')throw new \RuntimeException('VEHICLE_FUEL_SCHEMA_DRIFT');
        $fuelSchema=$fuelType['status']==='PRESENT';
        if($fuelSchema && $fuelType['readable']===true) {
            if(self::columnShape($db,'vehicles','fuel_type')!==['varchar(100)','YES'])throw new \RuntimeException('VEHICLE_FUEL_SCHEMA_DRIFT');
            $this->fuelEnabled=true;
        }
        $resolved=GrantVerifier::currentUserGrants($db);
        $result=VehicleAdminGrantPlan::evaluateCapability(
            $resolved['grants'],(bool)$identity['partial_revokes'],$resolved['roles_resolved'],
            $provinceProbe['status']==='PRESENT',$fuelSchema
        );
        if ($result['status']!=='PASS') throw new \RuntimeException('VEHICLE_GRANTS_REJECTED');
        $q=$db->prepare("SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=:schema AND TABLE_NAME='vehicles' AND INDEX_NAME IN ('uq_vehicle_registration','uq_vehicle_registration_number') ORDER BY INDEX_NAME, SEQ_IN_INDEX");
        $q->execute(['schema'=>'up_van_booking_test']);
        $indexes=array_map(static fn(array $r):array=>[(string)$r['INDEX_NAME'],(string)$r['COLUMN_NAME'],(int)$r['SEQ_IN_INDEX'],(int)$r['NON_UNIQUE']],$q->fetchAll(PDO::FETCH_ASSOC));
        VehicleSchemaValidator::assertRegistrationOnly($provinceColumnCount,$indexes);
    }

    public function supportsRegistrationProvince(): bool { return $this->provinceEnabled; }
    public function supportsFuelDetails(): bool { return $this->fuelEnabled; }

    private static function columnShape(PDO $db,string $table,string $column): array
    {
        $q=$db->prepare('SELECT COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:schema AND TABLE_NAME=:table AND COLUMN_NAME=:column');
        $q->execute(['schema'=>'up_van_booking_test','table'=>$table,'column'=>$column]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new \RuntimeException('VEHICLE_COLUMN_METADATA_UNAVAILABLE');
        return [(string)$row['COLUMN_TYPE'],(string)$row['IS_NULLABLE']];
    }

    private function fields(): string { return 'id, registration_number, '.($this->provinceEnabled?'registration_province':'NULL AS registration_province').', '.($this->fuelEnabled?'fuel_type':'NULL AS fuel_type').', label, passenger_capacity, is_active, created_at, updated_at'; }

    public function list(int $limit,int $offset): array
    {
        $q=$this->db->prepare('SELECT '.$this->fields().' FROM vehicles WHERE is_active=1 ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $q->bindValue(':limit',$limit,PDO::PARAM_INT);$q->bindValue(':offset',$offset,PDO::PARAM_INT);$q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function find(int $id,bool $forUpdate=false): ?array
    {
        $q=$this->db->prepare('SELECT '.$this->fields().' FROM vehicles WHERE id=:id'.($forUpdate?' FOR UPDATE':''));
        $q->execute(['id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }
    public function registrationExists(string $registration,int $exceptId=0): bool
    {
        $q=$this->db->prepare('SELECT id FROM vehicles WHERE registration_number=:registration AND id<>:except_id LIMIT 1');
        $q->execute(['registration'=>$registration,'except_id'=>$exceptId]);return $q->fetchColumn()!==false;
    }
    public function hasAssignments(int $id): bool
    {
        $q=$this->db->prepare('SELECT 1 FROM vehicle_assignments WHERE vehicle_id=:id LIMIT 1');
        $q->execute(['id'=>$id]);return $q->fetchColumn()!==false;
    }
    public function transaction(callable $operation): mixed
    {
        if ($this->db->inTransaction()) throw new \RuntimeException('VEHICLE_NESTED_TRANSACTION');
        $this->db->beginTransaction();
        try {$result=$operation();$this->db->commit();return $result;}
        catch (\Throwable $error) {if ($this->db->inTransaction()) $this->db->rollBack();throw $error;}
    }
    public function insert(VehicleInput $input,int $actorId): int
    {
        $columns='registration_number'.($this->provinceEnabled?',registration_province':'').($this->fuelEnabled?',fuel_type':'').',label,passenger_capacity,is_active,created_by,updated_by';
        $values=':registration'.($this->provinceEnabled?',:province':'').($this->fuelEnabled?',:fuel_type':'').',:label,:capacity,1,:created_by,:updated_by';
        $params=['registration'=>$input->registrationNumber,'label'=>$input->label,'capacity'=>$input->passengerCapacity,'created_by'=>$actorId,'updated_by'=>$actorId];
        if($this->provinceEnabled)$params['province']=$input->registrationProvince;
        if($this->fuelEnabled){$params['fuel_type']=$input->fuelType;}
        $q=$this->db->prepare('INSERT INTO vehicles ('.$columns.') VALUES ('.$values.')');$q->execute($params);
        return (int)$this->db->lastInsertId();
    }
    public function update(int $id,VehicleInput $input,int $actorId): void
    {
        $provinceSet=$this->provinceEnabled?',registration_province=:province':'';
        $fuelSet=$this->fuelEnabled?',fuel_type=:fuel_type':'';
        $params=['registration'=>$input->registrationNumber,'label'=>$input->label,'capacity'=>$input->passengerCapacity,'actor'=>$actorId,'id'=>$id];
        if($this->provinceEnabled)$params['province']=$input->registrationProvince;
        if($this->fuelEnabled){$params['fuel_type']=$input->fuelType;}
        $q=$this->db->prepare('UPDATE vehicles SET registration_number=:registration'.$provinceSet.$fuelSet.',label=:label,passenger_capacity=:capacity,updated_by=:actor WHERE id=:id');$q->execute($params);
    }
    public function deactivate(int $id,int $actorId): void
    {
        $q=$this->db->prepare('UPDATE vehicles SET is_active=0,updated_by=:actor WHERE id=:id AND is_active=1');
        $q->execute(['actor'=>$actorId,'id'=>$id]);
    }
    public function audit(int $actorId,string $action,int $vehicleId,array $before,array $after): void
    {
        (new AuditLogger($this->db))->record($actorId,$action,'vehicle',(string)$vehicleId,['before'=>$before,'after'=>$after]);
    }
}
