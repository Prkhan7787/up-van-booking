<?php
declare(strict_types=1);
namespace App\Services;

use App\Contracts\DriverAccountLinkRepository;
use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;

final class DriverAccountLinkService
{
    public function __construct(private readonly DriverAccountLinkRepository $links, private readonly AuthorizationPolicy $policy = new AuthorizationPolicy()) {}

    public function options(AuthenticatedPrincipal $actor): array
    {
        $this->authorize($actor);
        $accounts = $this->links->listUnlinkedDriverAccounts();
        foreach ($accounts as &$account) $account['phone_masked'] = self::maskPhone((string)$account['username']);
        unset($account);
        return ['drivers'=>$this->links->listUnlinkedDrivers(),'accounts'=>$accounts];
    }

    public function link(AuthenticatedPrincipal $actor, int $driverId, int $accountUserId, bool $staffVerified): void
    {
        $this->authorize($actor);
        if ($driverId < 1 || $accountUserId < 1 || !$staffVerified) {
            throw new \InvalidArgumentException('DRIVER_LINK_INPUT_INVALID');
        }
        $this->links->link($actor->userId, $driverId, $accountUserId, $actor->authVersion);
    }

    private function authorize(AuthenticatedPrincipal $actor): void
    {
        if (!$this->policy->allows($actor->roles, 'drivers.manage')) throw new AuthorizationException();
    }

    private static function maskPhone(string $phone): string
    {
        return preg_match('/\A[0-9]{10}\z/D', $phone) === 1 ? substr($phone, 0, 3) . '****' . substr($phone, -3) : 'หมายเลขไม่พร้อมแสดง';
    }
}
