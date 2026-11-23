<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Env;
use App\Core\View;
use App\Services\ModuleCatalog;
use App\Models\AuthenticatedPrincipal;
final class HomeController
{
    public function index(?AuthenticatedPrincipal $principal = null): void
    {
        View::render('home', [
            'appName' => Env::get('APP_NAME', 'UP Van Booking'),
            'modules' => ModuleCatalog::all(),
            'workspaceAuthenticated' => $principal !== null,
            'workspaceRoles' => $principal?->roles ?? [],
            'activeNav' => 'home',
        ]);
    }
}
