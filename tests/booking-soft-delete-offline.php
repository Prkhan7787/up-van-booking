<?php
declare(strict_types=1);
ob_start();
require __DIR__.'/booking-submission-offline.php';
$submissionResult=ob_get_clean();
ob_start();

use App\Controllers\OfficerBookingController;
use App\Modules\Booking\OfficerBookingDeletionRepository;
use App\Modules\Booking\OfficerBookingDeletionService;

final class DeleteFixture implements OfficerBookingDeletionRepository
{
    public array $row=['id'=>7,'requested_vehicle_id'=>14,'workflow_status'=>'submitted','version'=>2,'deleted_at'=>null];
    public array $events=[];
    public bool $assigned=false;
    public bool $failAudit=false;

    public function transaction(callable $operation): mixed
    {
        $before=$this->row;
        $events=$this->events;
        try { return $operation(); }
        catch (Throwable $e) { $this->row=$before;$this->events=$events;throw $e; }
    }
    public function officerEditSnapshot(int $id): ?array
    {
        return $id===7 && $this->row['deleted_at']===null ? $this->row : null;
    }
    public function lockVehicle(int $vehicleId): ?array
    {
        $this->events[]='vehicle-lock';
        return ['id'=>$vehicleId];
    }
    public function lockOfficerEditableRequest(int $id): ?array
    {
        $this->events[]='request-lock';
        return $this->officerEditSnapshot($id);
    }
    public function hasBlockingBookingAssignment(int $id): bool { return $this->assigned; }
    public function markOfficerDeleted(int $id, int $version, int $actorId): bool
    {
        $this->row['deleted_at']='fixture-time';
        $this->row['deleted_by']=$actorId;
        $this->row['version']=$version+1;
        return true;
    }
    public function auditOfficerDeleted(int $id, int $actorId, string $status, int $version): void
    {
        if ($this->failAudit) throw new RuntimeException('fixture audit failure');
        $this->events[]=['audit',$id,$actorId,$status,$version];
    }
}

$officer=new App\Models\AuthenticatedPrincipal(52,1,['vehicle_officer']);
$admin=new App\Models\AuthenticatedPrincipal(53,1,['admin']);
$requester=new App\Models\AuthenticatedPrincipal(13,1,['requester']);
$director=new App\Models\AuthenticatedPrincipal(54,1,['approver']);
$fixture=new DeleteFixture();
$service=new OfficerBookingDeletionService($fixture);
$controller=new OfficerBookingController(null,null,$service);
$invoke=static function (OfficerBookingController $controller, App\Models\AuthenticatedPrincipal $actor, array $post): int {
    $_SESSION=['csrf_token'=>'fixture-token'];
    $_POST=$post;
    ob_start();
    try { $controller->delete($actor); return http_response_code(); }
    finally { ob_end_clean(); }
};
$input=['_token'=>'fixture-token','booking_id'=>'7','booking_version'=>'2'];
submitCheck($invoke($controller,$requester,$input)===403 && $invoke($controller,$director,$input)===403,'delete forbids requester and director');
submitCheck($invoke($controller,$officer,['_token'=>'wrong']+$input)===419,'delete requires CSRF');
submitCheck($invoke(new OfficerBookingController(),$admin,$input)===503,'closed delete gate fails before writes');
submitCheck($invoke($controller,$officer,['booking_id'=>'0']+$input)===422,'delete validates request identity');
submitCheck($invoke($controller,$officer,['booking_version'=>'1']+$input)===409,'delete rejects stale version');
$fixture->assigned=true;
submitCheck($invoke($controller,$officer,$input)===409 && $fixture->row['deleted_at']===null,'deletion cannot release an existing assignment');
$fixture->assigned=false;
foreach (['pending_approval','awaiting_assignment','assigned','completed'] as $status) {
    $fixture->row['workflow_status']=$status;
    submitCheck($invoke($controller,$officer,$input)===409 && $fixture->row['deleted_at']===null,'delete rejects workflow status '.$status);
}
$fixture->row['workflow_status']='submitted';
$fixture->failAudit=true;
submitCheck($invoke($controller,$officer,$input)===500 && $fixture->row['deleted_at']===null && $fixture->row['version']===2,'failed audit rolls back soft delete');
$fixture->failAudit=false;
submitCheck($invoke($controller,$officer,$input)===200 && $fixture->row['deleted_by']===52
    && $fixture->row['workflow_status']==='submitted' && $fixture->row['version']===3
    && $fixture->events===['vehicle-lock','request-lock',['audit',7,52,'submitted',2]],'soft delete locks vehicle before request and records audit without rewriting workflow');
submitCheck($invoke($controller,$officer,$input)===409 && count($fixture->events)===3,'duplicate delete does not write another audit');
$newFixture=new DeleteFixture();
$adminController=new OfficerBookingController(null,null,new OfficerBookingDeletionService($newFixture));
submitCheck($invoke($adminController,$admin,$input)===200 && $newFixture->row['deleted_by']===53,'admin can soft delete within the same policy');
ob_end_clean();
echo $submissionResult;
echo "PASS: soft delete controller, roles, CSRF, closed gate, locks, assignment protection, duplicate and rollback; memory only.\n";
