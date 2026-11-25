<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Csrf,AuthorizationException,AdminUserInputException,View};
use App\Models\AuthenticatedPrincipal;
use App\Services\{AuthorizationPolicy,MicrosoftIdentityMappingService,MicrosoftIdentityMappingFactory};

final class AdminMicrosoftIdentityController
{
    public function __construct(private ?MicrosoftIdentityMappingService $service=null) {}
    private function authorize(?AuthenticatedPrincipal $actor): AuthenticatedPrincipal
    {
        if ($actor===null || !(new AuthorizationPolicy())->allows($actor->roles,'users.manage')) throw new AuthorizationException();
        return $actor;
    }
    private function id(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value) || strlen($value)>18 || (int)$value<1) throw new AdminUserInputException('รหัสบัญชีหรือเวอร์ชันไม่ถูกต้อง');
        return (int)$value;
    }
    public function index(?AuthenticatedPrincipal $actor): void
    {
        $actor=$this->authorize($actor);$model=['user'=>null,'mapping'=>null];$error=null;
        try {
            $id=$this->id($_GET['user_id']??null);
            $this->service ??= MicrosoftIdentityMappingFactory::create();
            $model=$this->service->view($actor,$id);
        } catch (AuthorizationException $e) { throw $e; }
        catch (AdminUserInputException $e) {http_response_code(422);$error=$e->getMessage();}
        catch (\Throwable) {http_response_code(503);$error='ระบบผูกบัญชี Microsoft ยังไม่พร้อม กรุณาติดต่อผู้ดูแล';}
        View::renderWithWorkspaceLayout('admin/microsoft-identity',$model+['error'=>$error,'pageTitle'=>'ผูกบัญชี Microsoft 365',
            'activeNav'=>'admin-users','workspaceAuthenticated'=>true,'workspaceRoles'=>$actor->roles]);
    }
    public function submit(?AuthenticatedPrincipal $actor): void
    {
        $actor=$this->authorize($actor);
        if (!Csrf::valid($_POST['_token']??null)) {http_response_code(419);echo 'คำขอหมดอายุ กรุณาโหลดหน้าใหม่';return;}
        try {
            $id=$this->id($_POST['user_id']??null);$version=$this->id($_POST['auth_version']??null);
            $this->service ??= MicrosoftIdentityMappingFactory::create();
            $action=$_POST['action']??null;
            if (!is_string($action)) throw new AdminUserInputException('คำขอไม่ถูกต้อง');
            $this->service->change($actor,$id,$version,$action,$_POST['tenant_id']??null,$_POST['object_id']??null);
            header('Location: /admin/users/microsoft?user_id='.$id,true,303);
        } catch (AuthorizationException $e) {throw $e;}
        catch (AdminUserInputException $e) {http_response_code(422);echo e($e->getMessage());}
        catch (\Throwable) {http_response_code(503);echo 'ระบบผูกบัญชี Microsoft ยังไม่พร้อม กรุณาติดต่อผู้ดูแล';}
    }
}
