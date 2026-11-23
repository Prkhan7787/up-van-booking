<?php
declare(strict_types=1);
namespace App\Database;

/** Complete reviewed unions, not a bypass for unrelated privileges. No SQL is executed. */
final class BookingCapabilityGrantGuard
{
    public static function merge(array ...$plans):array
    {
        $result=['database'=>[],'tables'=>[]];
        foreach($plans as $plan){
            $result['database']=array_values(array_unique([...$result['database'],...($plan['database']??[])]));
            foreach($plan['tables'] as $table=>$privileges)foreach($privileges as $privilege=>$columns){
                if($columns===null){$result['tables'][$table][$privilege]=null;continue;}
                if(!is_array($columns))throw new \InvalidArgumentException('BOOKING_UNION_REQUIRES_COLUMN_RULES');
                $result['tables'][$table][$privilege]=array_values(array_unique([...($result['tables'][$table][$privilege]??[]),...$columns]));
            }
        }
        return $result;
    }
    public static function additions():array
    {
        $read=BookingOfficerReadGrantPlan::rules();$submit=BookingSubmissionGrantPlan::rules();$edit=BookingOfficerEditGrantPlan::rules();
        $snapshotRead=BookingOfficerReadGrantPlan::rules(true);$snapshotSubmit=BookingSubmissionGrantPlan::rules(true);$snapshotEdit=BookingOfficerEditGrantPlan::rules(true);
        $plans = ['intake_snapshot_read'=>$snapshotRead,'intake_snapshot_submit'=>self::merge($snapshotRead,$snapshotSubmit),'intake_snapshot_edit'=>$snapshotEdit,'intake_snapshot_all'=>self::merge($snapshotRead,$snapshotSubmit,$snapshotEdit),'intake_read'=>$read,'intake_submit'=>$submit,'intake_edit'=>$edit,
            'intake_read_submit'=>self::merge($read,$submit),'intake_submit_edit'=>self::merge($submit,$edit)];
        foreach (['intake_snapshot_submit','intake_snapshot_all'] as $profile) {
            $plans[$profile.'_delete_read'] = self::merge($plans[$profile], BookingSoftDelete::READ_GRANTS);
            $plans[$profile.'_delete'] = self::merge($plans[$profile.'_delete_read'], BookingSoftDelete::WRITE_GRANTS);
        }
        // Reviewed prefixes of the proposed delta; existing capabilities still use their own minimum plans.
        $base = $plans['intake_snapshot_all_delete'];
        $plans['intake_officer_proposal_insert'] = self::merge($base, ['tables'=>[
            'booking_reviews'=>['INSERT'=>BookingApprovalGrantPlan::PROPOSAL['tables']['booking_reviews']['INSERT']],
        ]]);
        $plans['intake_officer_proposal'] = self::merge($base, BookingApprovalGrantPlan::PROPOSAL);
        $plans['intake_approval'] = self::merge($plans['intake_officer_proposal'], BookingApprovalGrantPlan::DECISION);
        $plans['intake_approval_delivery_read'] = self::merge($plans['intake_approval'], BookingApprovalGrantPlan::DELIVERY_READ);
        $plans['intake_approval_delivery_decision_read'] = self::merge($plans['intake_approval_delivery_read'],
            ['tables'=>['approval_decisions'=>BookingApprovalGrantPlan::DELIVERY_WRITE['tables']['approval_decisions']]]);
        $plans['intake_approval_delivery'] = self::merge($plans['intake_approval_delivery_read'],BookingApprovalGrantPlan::DELIVERY_WRITE);
        return $plans;
    }
    public static function evaluate(array $grants,array $requirements,bool $partialRevokes,bool $rolesResolved,bool $fuelEnabled):array
    {
        $union=ConnectionFactory::evaluateAuthRuntimeGrants($grants,$partialRevokes,$rolesResolved,$fuelEnabled);
        $capability=GrantVerifier::evaluate($grants,MigrationTarget::TEST,$requirements,$partialRevokes,$rolesResolved);
        // Other module privileges are accepted ONLY after the entire account matches a reviewed union.
        return ['status'=>$union['status']==='PASS' && $capability['missing_privileges']===[]?'PASS':'FAIL',
            'missing_privileges'=>$capability['missing_privileges'],
            'unexpected_privileges'=>$union['unexpected_privileges'],
            'account_guard'=>$union];
    }
}
