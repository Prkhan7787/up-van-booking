<?php
declare(strict_types=1);
namespace App\Modules\Booking;
use App\Models\AuthenticatedPrincipal;
interface BookingWorkflow
{
    public function transition(AuthenticatedPrincipal $actor,int $id,int $expectedVersion,string $nextStatus): BookingState;
}
