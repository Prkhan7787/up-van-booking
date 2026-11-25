<?php
declare(strict_types=1);
namespace App\Database;

/** Proposed capability only; this class never executes GRANT. */
final class MicrosoftIdentityGrantPlan
{
    public static function rules(): array
    {
        return BookingCapabilityGrantGuard::merge(AdminUserGrantPlan::rules(),['tables'=>[
            'microsoft_identities'=>[
                'SELECT'=>['user_id','tenant_id','object_id'],
                'INSERT'=>['user_id','tenant_id','object_id','created_by'],
                'DELETE'=>null,
            ],
        ]]);
    }
}
