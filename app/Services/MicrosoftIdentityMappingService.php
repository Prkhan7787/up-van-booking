<?php
declare(strict_types=1);
namespace App\Services;

use App\Contracts\AdminUserRepository;
use App\Contracts\MicrosoftIdentityRepository;
use App\Core\AdminUserInputException;
use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;

final class MicrosoftIdentityMappingService
{
    public function __construct(private readonly AdminUserRepository $users, private readonly MicrosoftIdentityRepository $identities) {}

    private function admin(AuthenticatedPrincipal $actor): void
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'users.manage')) throw new AuthorizationException();
        $live=$this->users->findUser($actor->userId);
        if ($live===null || !$live['is_active'] || (int)$live['auth_version']!==$actor->authVersion
            || !in_array('admin',$live['roles'],true)) throw new AuthorizationException();
    }

    public static function uuid(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}\z/D',$value)
            || $value==='00000000-0000-0000-0000-000000000000') throw new AdminUserInputException('Tenant ID หรือ Object ID ไม่ถูกต้อง');
        return strtolower($value);
    }

    public function view(AuthenticatedPrincipal $actor,int $userId): array
    {
        $this->admin($actor);
        $user=$this->users->findUser($userId);
        if ($user===null) throw new AdminUserInputException('ไม่พบบัญชีผู้ใช้');
        return ['user'=>$user,'mapping'=>$this->identities->forUser($userId)];
    }

    /** Call only with tid/oid from an already signature- and claim-validated ID token. */
    public function resolveIdentity(string $tid,string $oid): ?\App\Models\AuthState
    {
        $tid=self::uuid($tid);$oid=self::uuid($oid);
        return $this->users->transaction(function()use($tid,$oid):?\App\Models\AuthState{
            $mapping=$this->identities->forIdentity($tid,$oid);
            if ($mapping===null) return null;
            $id=(int)$mapping['user_id'];$this->users->lockUsers([$id]);
            $current=$this->identities->forIdentity($tid,$oid);
            if ($current===null || (int)$current['user_id']!==$id) return null;
            $user=$this->users->findUser($id);
            if ($user===null || !$user['is_active'] || $user['roles']===[]
                || array_diff($user['roles'],['requester','vehicle_officer','approver','admin'])!==[]) return null;
            $this->users->audit($id,'login.microsoft.success',$id,[]);
            return new \App\Models\AuthState($id,true,(int)$user['auth_version'],$user['roles']);
        });
    }

    public function change(AuthenticatedPrincipal $actor,int $userId,int $version,string $action,mixed $tid,mixed $oid): void
    {
        $this->admin($actor);
        if ($userId<1 || $version<1 || !in_array($action,['link','unlink'],true)) throw new AdminUserInputException('คำขอไม่ถูกต้อง');
        $tid=self::uuid($tid);$oid=self::uuid($oid);
        try {
            $this->users->transaction(function()use($actor,$userId,$version,$action,$tid,$oid):void{
                // Reuse the Admin advisory lock and ascending user-row order.
                $this->users->lockUsers([$actor->userId,$userId]);
                $this->admin($actor);
                $user=$this->users->findUser($userId);
                if ($user===null || (int)$user['auth_version']!==$version) throw new AdminUserInputException('ข้อมูลเปลี่ยนไป กรุณาโหลดหน้าใหม่');
                $existing=$this->identities->forUser($userId);
                if ($action==='link') {
                    if (!$user['is_active'] || $user['roles']===[] || array_diff($user['roles'],['requester','vehicle_officer','approver','admin'])!==[])
                        throw new AdminUserInputException('ผูกได้เฉพาะบัญชีบุคลากรที่เปิดใช้งาน');
                    if ($existing!==null || $this->identities->forIdentity($tid,$oid)!==null) throw new AdminUserInputException('บัญชีหรือ Microsoft Identity นี้ถูกผูกแล้ว');
                    $this->identities->insert($userId,$tid,$oid,$actor->userId);
                } else {
                    if ($existing===null || $existing['tenant_id']!==$tid || $existing['object_id']!==$oid) throw new AdminUserInputException('ข้อมูลการผูกเปลี่ยนไป กรุณาโหลดหน้าใหม่');
                    $this->identities->remove($userId,$tid,$oid);
                }
                $this->users->bumpAuthVersion($userId);
                $this->users->audit($actor->userId,'admin.microsoft_identity.'.$action,$userId,['source'=>'admin_user_management']);
            });
        } catch (\PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) throw new AdminUserInputException('Microsoft Identity นี้ถูกผูกแล้ว');
            throw $e;
        }
    }
}
