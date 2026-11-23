<?php
declare(strict_types=1);
namespace App\Services;
use App\Contracts\SystemLogRepository;
use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;
use App\Models\SystemLogFilter;

final class SystemLogService
{
    public function __construct(private readonly SystemLogRepository $logs) {}
    public function userHistory(?AuthenticatedPrincipal $actor,int $targetId,int $page=1): array
    {
        return $this->listing($actor,new SystemLogFilter(['page'=>(string)$page],$targetId));
    }
    public function listing(?AuthenticatedPrincipal $actor,SystemLogFilter $filter): array
    {
        if ($actor===null || !(new AuthorizationPolicy())->allows($actor->roles,'logs.view')) throw new AuthorizationException();
        $rows=$this->logs->search($filter); $hasNext=$filter->page<10000 && count($rows)>SystemLogFilter::PAGE_SIZE;
        $rows=array_slice($rows,0,SystemLogFilter::PAGE_SIZE);
        foreach ($rows as &$row) {
            $row['details']=SafeAuditDetails::extract((string)($row['action'] ?? ''),$row['metadata'] ?? null);
            unset($row['metadata']);
        }
        return ['rows'=>$rows,'has_next'=>$hasNext];
    }
}
