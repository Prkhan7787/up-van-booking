<?php
declare(strict_types=1);
namespace App\Services;

final class AuthorizationPolicy
{
    /** Server-owned permission map. Unknown permissions and unknown roles are denied. */
    private const PERMISSIONS = [
        'booking.view_all' => ['admin'],
        'form.preview.view' => ['requester', 'vehicle_officer', 'approver', 'admin'],
        'form.preview.fields.edit' => ['requester', 'vehicle_officer', 'approver', 'admin'],
        'form.vehicle_fields.edit' => ['requester', 'vehicle_officer'],
        'form.section3.edit' => ['approver'],
        'workspace.view' => ['requester', 'vehicle_officer', 'approver', 'admin'],
        'travel_estimate.view' => ['requester', 'admin'],
        'booking.draft.create' => ['requester'],
        'booking.draft.update_own' => ['requester'],
        'booking.submit_own' => ['requester', 'vehicle_officer', 'admin', 'approver'],
        'booking.view_own' => ['requester'],
        'booking.officer_queue.view' => ['vehicle_officer', 'admin'],
        'booking.officer_request.edit' => ['vehicle_officer', 'admin'],
        'booking.officer_request.delete' => ['vehicle_officer', 'admin'],
        'booking.review' => ['vehicle_officer'],
        'booking.propose' => ['vehicle_officer'],
        'booking.officer_request.forward' => ['vehicle_officer', 'admin'],
        'booking.officer_request.notify' => ['vehicle_officer', 'admin'],
        'booking.officer_request.recall' => ['vehicle_officer', 'admin'],
        'booking.director_queue.view' => ['approver', 'admin'],
        'booking.section3_draft.edit' => ['admin'],
        'booking.receive_decision' => ['vehicle_officer'],
        'assignment.create' => ['vehicle_officer'],
        'vehicles.manage' => ['vehicle_officer'],
        'vehicles.admin' => ['admin'],
        'drivers.manage' => ['vehicle_officer'],
        'calendar.view' => ['requester', 'vehicle_officer', 'approver', 'admin'],
        'booking.pending.view' => ['approver'],
        'booking.decision' => ['approver', 'admin'],
        'users.manage' => ['admin'],
        'system.manage' => ['admin'],
        'reports.view' => ['admin'],
        'logs.view' => ['admin'],
    ];

    /** @param list<string> $roles */
    public function allows(array $roles, string $permission): bool
    {
        $allowedRoles = self::PERMISSIONS[$permission] ?? null;
        if ($allowedRoles === null) return false;
        foreach ($roles as $role) {
            if (!in_array($role, ['requester', 'vehicle_officer', 'approver', 'admin'], true)) continue;
            if (in_array($role, $allowedRoles, true)) return true;
        }
        return false;
    }
}
