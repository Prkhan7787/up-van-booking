<?php
declare(strict_types=1);
namespace App\Services;

use App\Contracts\DriverScheduleRepository;
use App\Core\AuthorizationException;
use App\Core\DriverAssignmentNotFoundException;
use App\Models\AuthenticatedPrincipal;

final class DriverScheduleService
{
    public function __construct(private readonly DriverScheduleRepository $schedule, private readonly AuthorizationPolicy $policy = new AuthorizationPolicy()) {}

    public function listing(AuthenticatedPrincipal $actor): array
    {
        $this->authorize($actor);
        return ['linked'=>$this->schedule->isLinked($actor->userId),'assignments'=>$this->schedule->listOwnAssignments($actor->userId)];
    }

    public function detail(AuthenticatedPrincipal $actor, int $assignmentId): array
    {
        $this->authorize($actor);
        if ($assignmentId < 1) throw new DriverAssignmentNotFoundException();
        $assignment = $this->schedule->findOwnAssignment($actor->userId, $assignmentId);
        if ($assignment === null) throw new DriverAssignmentNotFoundException();
        $assignment['passengers'] = $this->schedule->listPassengersForOwnAssignment($actor->userId, $assignmentId);
        return $assignment;
    }

    private function authorize(AuthenticatedPrincipal $actor): void
    {
        if (!$this->policy->allows($actor->roles, 'driver.schedule.view')) throw new AuthorizationException();
    }
}
