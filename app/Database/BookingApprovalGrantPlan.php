<?php
declare(strict_types=1);
namespace App\Database;

final class BookingApprovalGrantPlan
{
    public const PROPOSAL = ['tables'=>['booking_reviews'=>[
        'INSERT'=>['booking_request_id','reviewed_by','reviewed_at','receipt_result','proposed_at','proposed_by','created_by','updated_by'],
        'UPDATE'=>['receipt_result','proposed_at','proposed_by','updated_by'],
    ]]];
    public const DECISION = ['tables'=>['approval_decisions'=>[
        'INSERT'=>['booking_request_id','decided_by','authority_kind','authority_reference','outcome','reason','decided_at','returned_to_officer_at'],
    ]]];
    public const DELIVERY_READ = ['tables'=>['booking_notifications'=>[
        'SELECT'=>['booking_request_id','outcome','channel'],
    ]]];
    public const DELIVERY_WRITE = ['tables'=>[
        'approval_decisions'=>['SELECT'=>['booking_request_id','outcome']],
        'booking_notifications'=>['INSERT'=>['booking_request_id','outcome','channel','notified_at','notified_by']],
    ]];
    public const DRAFT = ['tables'=>['booking_requests'=>['UPDATE'=>['a4_request_data','version','updated_by']],
        'audit_logs'=>['INSERT'=>['user_id','action','entity_type','entity_id','metadata']]]];

    public static function rules(string $capability): array
    {
        if ($capability === 'section3_draft') {
            return BookingCapabilityGrantGuard::merge(BookingOfficerReadGrantPlan::rules(true), self::DRAFT, BookingSoftDelete::READ_GRANTS);
        }
        $plan = match ($capability) {
            'forward'=>BookingCapabilityGrantGuard::merge(self::PROPOSAL, BookingOfficerEditGrantPlan::rules(true)),
            'decision'=>BookingCapabilityGrantGuard::merge(self::DECISION, self::DRAFT),
            'notify'=>BookingCapabilityGrantGuard::merge(self::DELIVERY_READ,self::DELIVERY_WRITE),
            default=>throw new \InvalidArgumentException('BOOKING_CAPABILITY_REJECTED'),
        };
        $transition = ['tables'=>[
            'booking_requests'=>['UPDATE'=>['workflow_status','version','updated_by']],
            'booking_status_history'=>['INSERT'=>['booking_request_id','from_status','to_status','changed_by','booking_version']],
            'audit_logs'=>['INSERT'=>['user_id','action','entity_type','entity_id','metadata']],
            'vehicles'=>['UPDATE'=>['updated_by'],'SELECT'=>['id','is_active','passenger_capacity']],
            'vehicle_assignments'=>['SELECT'=>['id','vehicle_id','status','starts_at','ends_at']],
        ]];
        return BookingCapabilityGrantGuard::merge(BookingOfficerReadGrantPlan::rules(true), BookingSoftDelete::READ_GRANTS, $transition, $plan);
    }
}
