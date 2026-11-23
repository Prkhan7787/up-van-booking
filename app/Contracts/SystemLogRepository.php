<?php
declare(strict_types=1);
namespace App\Contracts;
use App\Models\SystemLogFilter;
interface SystemLogRepository
{
    /** @return list<array<string,mixed>> */
    public function search(SystemLogFilter $filter): array;
}
