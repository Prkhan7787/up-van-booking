<?php
declare(strict_types=1);
namespace App\Services;

use App\Contracts\AdminUserRepository;
use App\Core\AdminUserInputException;
use App\Core\AdminUsersUnavailableException;
use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;

final class AdminUserManagementService
{
    public const ROLES=['requester','vehicle_officer','approver','admin'];
    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly PasswordHasher $hasher,
        public readonly bool $driverCreationEnabled = false,
    ) {}

    private function admin(AuthenticatedPrincipal $actor): void
    {
        if (!in_array('admin',$actor->roles,true)) throw new AuthorizationException();
        $live=$this->users->findUser($actor->userId);
        if ($live===null || !(bool)$live['is_active'] || (int)$live['auth_version']!==$actor->authVersion || !in_array('admin',$live['roles'],true)) throw new AuthorizationException();
    }

    public function listing(AuthenticatedPrincipal $actor, string $search='', string $role='', int $page=1): array
    {
        $this->admin($actor);
        if (mb_strlen($search,'UTF-8')>100 || ($role!=='' && !in_array($role,[...self::ROLES,'driver'],true)) || $page<1 || $page>10000) throw new AdminUserInputException('เงื่อนไขค้นหาไม่ถูกต้อง');
        return $this->users->listUsers(trim($search),$role,$page);
    }
    public function resetPassword(AuthenticatedPrincipal $actor,int $targetId,int $version,string $password,string $confirmation): void
    {
        $this->admin($actor);
        if ($targetId<1 || $version<1 || $targetId===$actor->userId) throw new AdminUserInputException('บัญชีที่เลือกไม่สามารถรีเซ็ตรหัสผ่านจากหน้านี้');
        if (!PasswordPolicy::isValidForSetting($password) || !hash_equals($password,$confirmation)) throw new AdminUserInputException('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษรและยืนยันตรงกัน');
        $hash=$this->hasher->hash($password);
        $this->users->transaction(function()use($actor,$targetId,$version,$hash):void {
            $this->users->lockUsers([$actor->userId,$targetId]);
            $this->admin($actor);
            $target=$this->users->findUser($targetId);
            if($target===null || (int)$target['auth_version']!==$version) throw new AdminUserInputException('ข้อมูลบัญชีเปลี่ยนไปแล้ว กรุณาโหลดหน้าใหม่');
            if(UsernamePolicy::normalize((string)$target['username'])==='admin1') throw new AdminUserInputException('บัญชี admin1 ได้รับการคุ้มครองในขั้นตอนนี้');
            $this->users->setPasswordHash($targetId,$hash);
            $this->users->bumpAuthVersion($targetId);
            $this->users->audit($actor->userId,'admin.user.password_reset',$targetId,['auth_version_before'=>$version,'auth_version_after'=>$version+1]);
        });
    }

    public function create(AuthenticatedPrincipal $actor, array $data): int
    {
        $this->admin($actor);
        foreach (['username','email','display_name','password','password_confirmation'] as $key) if (!isset($data[$key]) || !is_string($data[$key])) throw new AdminUserInputException('กรุณากรอกข้อมูลบัญชีให้ครบถ้วน');
        // Admin-only creation may now assign one of the four server-owned roles.
        // Missing role retains the Requester default; arrays and unknown roles fail closed.
        $role=$data['role'] ?? 'requester';
        if (!is_string($role) || !in_array($role,self::ROLES,true) || array_key_exists('roles',$data)) throw new AdminUserInputException('กรุณาเลือกบทบาทที่ระบบรองรับ');
        $username=UsernamePolicy::normalize($data['username']); $email=trim($data['email']); $name=trim($data['display_name']);
        if (!UsernamePolicy::isValid($username)) throw new AdminUserInputException('Username ต้องมี 3–50 ตัวอักษร ใช้ตัวอักษรอังกฤษ ตัวเลข จุด ขีดกลาง หรือ underscore');
        if (filter_var($email,FILTER_VALIDATE_EMAIL)===false || strlen($email)>254 || $name==='' || mb_strlen($name,'UTF-8')>150) throw new AdminUserInputException('Email หรือ Display Name ไม่ถูกต้อง');
        if (!PasswordPolicy::isValidForSetting($data['password']) || !hash_equals($data['password'],$data['password_confirmation'])) throw new AdminUserInputException('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษรและยืนยันตรงกัน');
        return $this->persistNewAccount($actor,$username,$email,$name,$this->hasher->hash($data['password']),$role);
    }

    public function createDriver(AuthenticatedPrincipal $actor, array $data): int
    {
        $this->admin($actor);
        if (!$this->driverCreationEnabled) throw new AdminUsersUnavailableException();
        if (array_key_exists('role',$data) || array_key_exists('roles',$data)) {
            throw new AdminUserInputException('ระบบกำหนดบทบาทพนักงานขับรถให้อัตโนมัติ');
        }
        foreach (['real_name','nickname','phone','password','password_confirmation'] as $field) {
            if (!is_string($data[$field] ?? null)) {
                throw new AdminUserInputException('กรุณากรอกข้อมูลบัญชีพนักงานขับรถให้ครบถ้วน');
            }
        }
        $name = trim($data['real_name']);
        $nickname = trim($data['nickname']);
        if ($name === '' || $nickname === '' || mb_strlen($name,'UTF-8') > 150 || mb_strlen($nickname,'UTF-8') > 80
            || preg_match('/[\x00-\x1F\x7F]/', $name.$nickname) === 1) {
            throw new AdminUserInputException('กรุณากรอกชื่อจริงและชื่อเล่นให้ถูกต้อง');
        }
        $phone = PhoneNumberPolicy::normalize($data['phone']);
        if ($phone === null) throw new AdminUserInputException('กรุณากรอกเบอร์โทรศัพท์มือถือให้ถูกต้อง');
        $password = $data['password'];
        if (strlen($password) > 1024 || !PasswordPolicy::isValidForSetting($password) || !hash_equals($password,$data['password_confirmation'])) {
            throw new AdminUserInputException('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร ไม่เกิน 1,024 ไบต์ และยืนยันตรงกัน');
        }
        return $this->persistNewAccount($actor,$phone,null,$name,$this->hasher->hash($password),'driver',$nickname);
    }

    private function persistNewAccount(AuthenticatedPrincipal $actor, string $username, ?string $email, string $name, string $hash, string $role, ?string $nickname = null): int
    {
        try {
            return $this->users->transaction(function () use ($actor,$username,$email,$name,$hash,$role,$nickname): int {
                $this->users->lockUsers([$actor->userId]); $this->admin($actor);
                $id=$this->users->createUser($username,$email,$name,$hash,$nickname);
                $this->users->setRoles($id,[$role]);
                $this->users->audit($actor->userId,'admin.user.created',$id,['roles'=>[$role],'source'=>'admin_user_management']);
                return $id;
            });
        } catch (\PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0)===1062) {
                throw new AdminUserInputException($role==='driver' ? 'เบอร์โทรศัพท์นี้ถูกใช้งานแล้ว' : 'Username หรือ Email ถูกใช้งานแล้ว');
            }
            throw $error;
        }
    }

    public function changeRoles(AuthenticatedPrincipal $actor, int $targetId, int $version, array $roles): void
    {
        $this->admin($actor);
        if ($roles===[] || count($roles)>4 || count(array_unique($roles,SORT_REGULAR))!==count($roles)) throw new AdminUserInputException('กรุณาเลือกบทบาทที่ถูกต้องอย่างน้อยหนึ่งบทบาท');
        foreach ($roles as $role) if (!is_string($role) || !in_array($role,self::ROLES,true)) throw new AdminUserInputException('บทบาทไม่ถูกต้อง');
        sort($roles,SORT_STRING);
        $this->change($actor,$targetId,$version,$roles,null);
    }

    public function changeStatus(AuthenticatedPrincipal $actor, int $targetId, int $version, bool $active): void
    {
        $this->admin($actor);
        $this->change($actor,$targetId,$version,null,$active);
    }

    /** Replace a single stored role only; multi-role accounts require separate review. */
    public function changePrimaryRole(AuthenticatedPrincipal $actor,int $targetId,int $version,string $previous,string $next): void
    {
        $this->admin($actor);
        if (!in_array($previous,self::ROLES,true) || !in_array($next,self::ROLES,true)) throw new AdminUserInputException('บทบาทไม่ถูกต้อง');
        $this->change($actor,$targetId,$version,null,null,[$previous,$next]);
    }

    public function historyTarget(AuthenticatedPrincipal $actor,int $targetId): array
    {
        $this->admin($actor);
        $row=$targetId>0 ? $this->users->findUser($targetId) : null;
        if ($row===null) throw new AdminUserInputException('ไม่พบบัญชีที่ต้องการดูประวัติ');
        return array_intersect_key($row,array_flip(['id','username','display_name','created_at']));
    }

    private function change(AuthenticatedPrincipal $actor, int $targetId, int $version, ?array $roles, ?bool $active, ?array $primaryChange=null): void
    {
        $this->admin($actor);
        if ($targetId<1 || $version<1) throw new AdminUserInputException('บัญชีหรือเวอร์ชันไม่ถูกต้อง');
        $this->users->transaction(function () use ($actor,$targetId,$version,$roles,$active,$primaryChange): void {
            $this->users->lockUsers([$actor->userId,$targetId]); $this->admin($actor);
            $target=$this->users->findUser($targetId);
            if ($target===null || (int)$target['auth_version']!==$version) throw new AdminUserInputException('ข้อมูลบัญชีเปลี่ยนไปแล้ว กรุณาโหลดหน้าใหม่');
            if (UsernamePolicy::normalize((string)$target['username'])==='admin1') throw new AdminUserInputException('บัญชี admin1 ได้รับการคุ้มครองในขั้นตอนนี้');
            if (($roles!==null || $primaryChange!==null) && in_array('driver',$target['roles'],true)) {
                throw new AdminUserInputException('บัญชีพนักงานขับรถใช้บทบาทพนักงานขับรถเท่านั้น');
            }
            if ($primaryChange!==null) {
                if (array_diff($target['roles'],self::ROLES)!==[]) throw new AdminUserInputException('พบบทบาทที่ระบบไม่รู้จัก กรุณาตรวจสอบก่อนเปลี่ยนสิทธิ์');
                if (count($target['roles'])!==1) throw new AdminUserInputException('บัญชีมีหลายบทบาทหรือไม่มีบทบาท กรุณาตรวจสอบก่อนเปลี่ยนสิทธิ์');
                if (!in_array($primaryChange[0],$target['roles'],true)) throw new AdminUserInputException('บทบาทเดิมเปลี่ยนไปแล้ว กรุณาโหลดหน้าใหม่');
                $roles=array_values(array_unique([...array_diff($target['roles'],[$primaryChange[0]]),$primaryChange[1]]));
            }
            if ($actor->userId===$targetId && $roles!==null) throw new AdminUserInputException('ไม่สามารถแก้บทบาทของตนเอง');
            if ($actor->userId===$targetId && $active===false) throw new AdminUserInputException('ไม่สามารถระงับบัญชีของตนเอง');
            $nextRoles=$roles ?? $target['roles']; $nextActive=$active ?? (bool)$target['is_active'];
            if ((bool)$target['is_active'] && in_array('admin',$target['roles'],true) && (!$nextActive || !in_array('admin',$nextRoles,true)) && $this->users->activeAdminCount()<=1) throw new AdminUserInputException('ไม่สามารถระงับหรือลดสิทธิ์ Admin คนสุดท้าย');
            $oldRoles=$target['roles']; sort($oldRoles,SORT_STRING); sort($nextRoles,SORT_STRING);
            if ($oldRoles===$nextRoles && (bool)$target['is_active']===$nextActive) return;
            if ($roles!==null) $this->users->setRoles($targetId,$nextRoles);
            if ($active!==null) $this->users->setStatus($targetId,$nextActive);
            $this->users->bumpAuthVersion($targetId);
            $this->users->audit($actor->userId,$roles!==null?'admin.user.roles_changed':'admin.user.status_changed',$targetId,[
                'before'=>['roles'=>$oldRoles,'active'=>(bool)$target['is_active']],
                'after'=>['roles'=>$nextRoles,'active'=>$nextActive],
            ]);
        });
    }
}
