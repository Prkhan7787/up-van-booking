<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\View;
use App\Services\UiFoundationService;
use App\Services\AdminUserManagementService;
use App\Services\AdminUserManagementFactory;
use App\Services\AuthorizationPolicy;
use App\Core\AdminUserInputException;
use App\Core\AdminUsersUnavailableException;
use App\Core\AuthorizationException;
use App\Core\Csrf;
use App\Models\AuthenticatedPrincipal;
use App\Services\SystemLogService;
use App\Services\SystemLogFactory;
use App\Core\SystemLogUnavailableException;

final class AdminUserManagementController
{
    public function __construct(private ?UiFoundationService $ui = null, private ?AdminUserManagementService $accounts = null,private ?SystemLogService $logs=null)
    {
        $this->ui ??= new UiFoundationService();
    }

    private function authorize(?AuthenticatedPrincipal $actor): AuthenticatedPrincipal
    {
        if ($actor===null || !(new AuthorizationPolicy())->allows($actor->roles,'users.manage')) throw new AuthorizationException();
        return $actor;
    }

    public function index(?AuthenticatedPrincipal $actor): void
    {
        $actor=$this->authorize($actor);
        $this->renderAccounts($actor);
    }

    private function renderAccounts(AuthenticatedPrincipal $actor, ?string $error=null): void
    {
        $model=$this->ui->adminUserManagementOptions();
        $search=is_string($_GET['q'] ?? '') ? ($_GET['q'] ?? '') : '';
        $filter=is_string($_GET['role'] ?? '') ? ($_GET['role'] ?? '') : '';
        $rawPage=$_GET['page'] ?? '1';
        $page=is_string($rawPage) && ctype_digit($rawPage) && strlen($rawPage)<=5 ? (int)$rawPage : 0;
        $listing=['rows'=>[],'page'=>1,'has_next'=>false]; $enabled=false;
        try {
            $this->accounts ??= AdminUserManagementFactory::createTestService();
            $listing=$this->accounts->listing($actor,$search,$filter,$page); $enabled=true;
        } catch (AdminUsersUnavailableException) {
            http_response_code(503);
            $error='สิทธิ์ฐานข้อมูลสำหรับจัดการผู้ใช้ยังไม่พร้อม ต้องตรวจ Grant Plan และผ่าน Integration ก่อนใช้งาน';
        } catch (AdminUserInputException $failure) {
            http_response_code(422); $error=$failure->getMessage();
        }
        $notice=$_SESSION['admin_users_notice'] ?? null; unset($_SESSION['admin_users_notice']);
        $historyUser=null; $historyRows=[]; $historyError=null; $historyPage=1; $historyHasNext=false;
        if (array_key_exists('history',$_GET)) {
            try {
                $historyId=$this->positiveInteger($_GET['history']);
                $historyPage=$this->positiveInteger($_GET['history_page'] ?? '1');
                if ($historyPage>10000) throw new AdminUserInputException('เลขหน้าประวัติไม่ถูกต้อง');
                if (!$enabled) throw new AdminUsersUnavailableException();
                $historyUser=$this->accounts->historyTarget($actor,$historyId);
                $this->logs ??= SystemLogFactory::create();
                $history=$this->logs->userHistory($actor,$historyId,$historyPage);
                $historyRows=$history['rows']; $historyHasNext=$history['has_next'];
            } catch (AdminUserInputException $failure) {
                http_response_code(422); $historyError=$failure->getMessage();
            } catch (SystemLogUnavailableException|AdminUsersUnavailableException) {
                $historyError='ยังอ่านประวัติการเปลี่ยนแปลงไม่ได้ ต้องตรวจสิทธิ์ SELECT ของ audit_logs ก่อน';
            }
        }
        View::renderWithWorkspaceLayout('admin/users',[
            'pageTitle'=>'จัดการบัญชีผู้ใช้','activeNav'=>'admin-users','workspaceAuthenticated'=>true,'workspaceRoles'=>$actor->roles,
            'roles'=>$model['roles'],'actionsEnabled'=>$enabled,'accountRows'=>$listing['rows'],
            'actorId'=>$actor->userId,'search'=>$search,'selectedRole'=>$filter,
            'page'=>$listing['page'],'hasNext'=>$listing['has_next'],'error'=>$error,'notice'=>is_string($notice)?$notice:null,
            'historyUser'=>$historyUser,'historyRows'=>$historyRows,'historyError'=>$historyError,'historyPage'=>$historyPage,'historyHasNext'=>$historyHasNext,
        ]);
    }

    public function submit(?AuthenticatedPrincipal $actor): void
    {
        $actor=$this->authorize($actor);
        if (!Csrf::valid($_POST['_token'] ?? null)) { http_response_code(419); echo 'คำขอหมดอายุ กรุณาโหลดหน้าใหม่'; return; }
        try {
            $this->accounts ??= AdminUserManagementFactory::createTestService();
            $action=$_POST['action'] ?? null;
            if ($action==='create') $this->accounts->create($actor,$_POST);
            elseif ($action==='reset_password') {
                $id=$this->positiveInteger($_POST['user_id'] ?? null);
                $version=$this->positiveInteger($_POST['auth_version'] ?? null);
                $password=$_POST['password'] ?? null;$confirmation=$_POST['password_confirmation'] ?? null;
                if (!is_string($password) || !is_string($confirmation)) throw new AdminUserInputException('กรุณากรอกรหัสผ่านให้ครบถ้วน');
                $this->accounts->resetPassword($actor,$id,$version,$password,$confirmation);
            }
            elseif ($action==='status' || $action==='primary_role') {
                $id=$this->positiveInteger($_POST['user_id'] ?? null);
                $version=$this->positiveInteger($_POST['auth_version'] ?? null);
                if ($action==='primary_role') {
                    $previous=$_POST['previous_role'] ?? null; $next=$_POST['primary_role'] ?? null;
                    if (!is_string($previous) || !is_string($next)) throw new AdminUserInputException('บทบาทไม่ถูกต้อง');
                    $this->accounts->changePrimaryRole($actor,$id,$version,$previous,$next);
                } else {
                    $active=$_POST['active'] ?? null;
                    if (!is_string($active) || !in_array($active,['0','1'],true)) throw new AdminUserInputException('สถานะบัญชีไม่ถูกต้อง');
                    $this->accounts->changeStatus($actor,$id,$version,$active==='1');
                }
            } else throw new AdminUserInputException('การดำเนินการไม่ถูกต้อง');
            $_SESSION['admin_users_notice']='บันทึกการจัดการบัญชีสำเร็จ';
            header('Location: /admin/users',true,303);
        } catch (AdminUserInputException $error) {
            http_response_code(422); $this->renderAccounts($actor,$error->getMessage());
        } catch (AdminUsersUnavailableException) {
            $this->renderAccounts($actor);
        }
    }

    private function positiveInteger(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value) || strlen($value)>18 || (int)$value<1) throw new AdminUserInputException('รหัสบัญชีหรือเวอร์ชันไม่ถูกต้อง');
        return (int)$value;
    }

}
