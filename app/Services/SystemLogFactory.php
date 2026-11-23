<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\AuthFeature;
use App\Core\SystemLogUnavailableException;
use App\Database\ConnectionFactory;
use App\Repositories\PdoSystemLogRepository;
final class SystemLogFactory
{
    public static function create(): SystemLogService
    {
        if (!AuthFeature::testExecutionEnabled()) throw new SystemLogUnavailableException();
        try {return new SystemLogService(new PdoSystemLogRepository(ConnectionFactory::connect('auth_test')));}
        catch (\Throwable) {throw new SystemLogUnavailableException();}
    }
}
