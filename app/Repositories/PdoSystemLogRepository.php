<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Contracts\SystemLogRepository;
use App\Core\SystemLogUnavailableException;
use App\Database\BookingCapabilityGrantGuard;
use App\Database\GrantVerifier;
use App\Database\RuntimeColumnProbe;
use App\Database\SystemLogGrantPlan;
use App\Models\SystemLogFilter;
use PDO;

final class PdoSystemLogRepository implements SystemLogRepository
{
    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION || $db->getAttribute(PDO::ATTR_EMULATE_PREPARES)!==false) throw new SystemLogUnavailableException();
        $identity=$db->query('SELECT DATABASE() AS db_name, CURRENT_USER() AS account_name, @@GLOBAL.partial_revokes AS partial_revokes')->fetch(PDO::FETCH_ASSOC);
        if (($identity['db_name'] ?? null)!=='up_van_booking_test' || ($identity['account_name'] ?? null)!=='up_van_auth_test@127.0.0.1') throw new SystemLogUnavailableException();
        $resolved=GrantVerifier::currentUserGrants($db);
        $fuelType=RuntimeColumnProbe::inspect($db,'vehicles','fuel_type');
        if ($fuelType['status']==='ERROR') throw new SystemLogUnavailableException();
        $result=BookingCapabilityGrantGuard::evaluate(
            $resolved['grants'],SystemLogGrantPlan::capabilityRules(),(bool)$identity['partial_revokes'],
            $resolved['roles_resolved'],$fuelType['status']==='PRESENT'
        );
        if ($result['status']!=='PASS') throw new SystemLogUnavailableException();
    }
    public function search(SystemLogFilter $filter): array
    {
        $where=[]; $params=[];
        if ($filter->targetUserId!==null) {
            $where[]="entity_type='user' AND entity_id=:target_user AND action IN ('admin.user.created','admin.user.roles_changed','admin.user.status_changed','auth.test_admin.provisioned')";
            $params['target_user']=(string)$filter->targetUserId;
        }
        if ($filter->action!=='') {$where[]='action=:action';$params['action']=$filter->action;}
        if ($filter->actor!==null) {$where[]='user_id=:actor';$params['actor']=$filter->actor;}
        if ($filter->fromUtc!==null) {$where[]='created_at>=:from_utc';$params['from_utc']=$filter->fromUtc;}
        if ($filter->untilUtc!==null) {$where[]='created_at<:until_utc';$params['until_utc']=$filter->untilUtc;}
        $q=$this->db->prepare('SELECT id, user_id, action, entity_type, entity_id, metadata, created_at FROM audit_logs'.($where ? ' WHERE '.implode(' AND ',$where) : '').' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $key=>$value) $q->bindValue(':'.$key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        $q->bindValue(':limit',SystemLogFilter::PAGE_SIZE+1,PDO::PARAM_INT);
        $q->bindValue(':offset',($filter->page-1)*SystemLogFilter::PAGE_SIZE,PDO::PARAM_INT);
        $q->execute(); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
