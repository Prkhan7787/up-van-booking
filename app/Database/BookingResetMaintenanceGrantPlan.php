<?php
declare(strict_types=1);
namespace App\Database;

/** Reviewed proposal for the isolated test reset account; no grants are executed here. */
final class BookingResetMaintenanceGrantPlan
{
    public const ACCOUNT = "'up_van_reset_maintenance'@'127.0.0.1'";

    public static function rules(): array
    {
        return ['tables'=>[
            'booking_requests'=>[
                'SELECT'=>['id','workflow_status','version','deleted_at','deleted_by','deletion_reason','updated_by'],
                'UPDATE'=>['deleted_at','deleted_by','deletion_reason','updated_by','version'],
            ],
            // The INVOKER view projects these four fields even though the reset
            // only counts its rows. Grant only the columns named by that view.
            'vehicle_assignments'=>['SELECT'=>['id','booking_request_id','vehicle_id','driver_id','starts_at','ends_at','status']],
            'users'=>['SELECT'=>['id','is_active']],
            'roles'=>['SELECT'=>['id','name']],
            'user_roles'=>['SELECT'=>['user_id','role_id']],
            'approval_decisions'=>['SELECT'=>['booking_request_id']],
            'booking_notifications'=>['SELECT'=>['booking_request_id']],
            'booking_status_history'=>['SELECT'=>['booking_request_id']],
            'audit_logs'=>[
                'SELECT'=>['id'],
                'INSERT'=>['user_id','action','entity_type','entity_id','metadata'],
            ],
            // SELECT one output column permits COUNT(*) and holds a view metadata lock
            // when read in the transaction. SHOW VIEW permits definition inspection.
            'calendar_events'=>['SELECT'=>['assignment_id'],'SHOW VIEW'=>null],
        ]];
    }
}
