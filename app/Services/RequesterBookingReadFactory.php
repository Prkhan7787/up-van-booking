<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\AuthFeature;
use App\Core\RequesterBookingUnavailableException;
use App\Database\ConnectionFactory;
use App\Modules\Booking\RequesterDraftService;
use App\Repositories\PdoRequesterDraftRepository;

/** GET-only test runtime. Exact read grant plan is checked by ConnectionFactory and repository. */
final class RequesterBookingReadFactory
{
    public static function create(): RequesterDraftService
    {
        if (!AuthFeature::testExecutionEnabled()) throw new RequesterBookingUnavailableException();
        try {return new RequesterDraftService(new PdoRequesterDraftRepository(ConnectionFactory::connect('auth_test')));}
        catch (\Throwable) {throw new RequesterBookingUnavailableException();}
    }
}
