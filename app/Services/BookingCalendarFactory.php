<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\AuthFeature;
use App\Core\RequesterBookingUnavailableException;
use App\Database\ConnectionFactory;
use App\Modules\Booking\BookingCalendarService;
use App\Repositories\PdoBookingCalendarRepository;

final class BookingCalendarFactory
{
    public static function create(): BookingCalendarService
    {
        if (!AuthFeature::testExecutionEnabled()) throw new RequesterBookingUnavailableException();
        try {return new BookingCalendarService(new PdoBookingCalendarRepository(ConnectionFactory::connect('auth_test')));}
        catch (\Throwable) {throw new RequesterBookingUnavailableException();}
    }
}
