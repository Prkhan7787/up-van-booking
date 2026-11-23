<?php
declare(strict_types=1);
namespace App\Core;

use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AuthorizationMiddleware;
use App\Services\AuthService;

final class AuthRuntime
{
    public function __construct(
        public readonly AuthService $auth,
        public readonly AuthenticationMiddleware $authentication,
        public readonly AuthorizationMiddleware $authorization,
    ) {}
}
