<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;
use App\Services\AuthorizationPolicy;

final class RequesterDraftService
{
    public function __construct(private readonly RequesterDraftRepository $repo) {}
    public function listOwn(AuthenticatedPrincipal $actor,int $page): array
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'booking.view_own') || $page<1 || $page>10000) throw new AuthorizationException();
        return $this->repo->listOwn($actor->userId,26,($page-1)*25);
    }
    public function listAll(AuthenticatedPrincipal $actor,int $page): array
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'booking.view_all') || $page<1 || $page>10000) throw new AuthorizationException();
        return $this->repo->listAll(26,($page-1)*25);
    }
    public function detail(AuthenticatedPrincipal $actor,int $id): array
    {
        if ($id<1) throw new AuthorizationException();
        $policy=new AuthorizationPolicy();
        $adminRead=$policy->allows($actor->roles,'booking.view_all');
        if (!$adminRead && !$policy->allows($actor->roles,'booking.view_own')) throw new AuthorizationException();
        $row=$adminRead ? $this->repo->find($id) : $this->repo->findOwned($id,$actor->userId);
        if ($row===null) throw new AuthorizationException();
        $state=new BookingState((int)$row['id'],(int)$row['requester_id'],(string)$row['workflow_status'],(int)$row['version']);
        if (!$adminRead && $state->ownerId!==$actor->userId) throw new AuthorizationException();
        return $row;
    }
    public function create(AuthenticatedPrincipal $actor,RequesterDraftInput $input): int
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'booking.draft.create')) throw new AuthorizationException();
        return $this->repo->transaction(function()use($actor,$input):int {
            $id=$this->repo->insert($actor->userId,$input->fields);
            $this->repo->appendDraftHistory($id,$actor->userId,1);
            $this->repo->auditDraft('booking.draft.created',$id,$actor->userId,1);
            return $id;
        });
    }
    public function update(AuthenticatedPrincipal $actor,int $id,int $version,RequesterDraftInput $input): void
    {
        if ($id<1 || $version<1) throw new \InvalidArgumentException('BOOKING_ID_INVALID');
        $this->repo->transaction(function()use($actor,$id,$version,$input):void {
            $row=$this->repo->lock($id);
            if ($row===null) throw new AuthorizationException();
            $state=new BookingState((int)$row['id'],(int)$row['requester_id'],(string)$row['workflow_status'],(int)$row['version']);
            if ($state->ownerId!==$actor->userId || !(new AuthorizationPolicy())->allows($actor->roles,'booking.draft.update_own') || $state->status!=='draft') throw new AuthorizationException();
            if ($state->version!==$version || !$this->repo->update($id,$actor->userId,$version,$input->fields)) throw new \DomainException('BOOKING_VERSION_CONFLICT');
            $this->repo->auditDraft('booking.draft.updated',$id,$actor->userId,$version+1);
        });
    }
}
