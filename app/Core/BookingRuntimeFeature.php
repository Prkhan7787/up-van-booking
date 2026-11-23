<?php
declare(strict_types=1);
namespace App\Core;

use App\Database\ConnectionFactory;
use App\Modules\Booking\BookingSubmissionService;
use App\Modules\Booking\BookingApprovalService;
use App\Modules\Booking\OfficerBookingDeletionService;
use App\Repositories\PdoBookingSubmissionRepository;

/** Two test-only switches keep this unfinished module disconnected until separately approved. */
final class BookingRuntimeFeature
{
    public static function submissionEnabled(): bool
    {
        return AuthFeature::testExecutionEnabled() && Env::get('TEST_DB_EXECUTION_APPROVED')==='true' && Env::get('TEST_BOOKING_SUBMISSION_APPROVED')==='true';
    }
    public static function officerReadEnabled(): bool
    {
        return AuthFeature::testExecutionEnabled() && Env::get('TEST_BOOKING_OFFICER_READ_APPROVED')==='true';
    }
    public static function submissionService(): ?BookingSubmissionService
    {
        if(!self::submissionEnabled())return null;
        return new BookingSubmissionService(new PdoBookingSubmissionRepository(ConnectionFactory::connect('auth_test'),'submit'));
    }
    public static function officerService(): ?BookingSubmissionService
    {
        if(!self::officerReadEnabled())return null;
        return new BookingSubmissionService(new PdoBookingSubmissionRepository(ConnectionFactory::connect('auth_test'),'read'));
    }
    public static function officerEditEnabled():bool
    {
        return AuthFeature::testExecutionEnabled() && Env::get('TEST_DB_EXECUTION_APPROVED')==='true' && Env::get('TEST_BOOKING_OFFICER_EDIT_APPROVED')==='true';
    }
    public static function officerEditService():?BookingSubmissionService
    {
        if(!self::officerEditEnabled())return null;
        return new BookingSubmissionService(new PdoBookingSubmissionRepository(ConnectionFactory::connect('auth_test'),'officer_edit'));
    }

    public static function approvalEnabled(): bool
    {
        return self::officerReadEnabled() && Env::get('TEST_DB_EXECUTION_APPROVED') === 'true'
            && Env::get('TEST_BOOKING_APPROVAL_APPROVED') === 'true';
    }

    /** Explicit, read-only diagnostic switch restricted to a loopback test request. */
    public static function decisionDiagnosticsEnabled(): bool
    {
        if (!AuthFeature::testExecutionEnabled() || Env::get('TEST_BOOKING_DECISION_DIAGNOSTICS') !== 'true') return false;
        $loopback = static function (string $ip): bool {
            $ip = strtolower(trim($ip, '[] '));
            if (str_starts_with($ip, '::ffff:')) $ip = substr($ip, 7);
            return in_array($ip, ['127.0.0.1', '::1'], true);
        };
        $httpHost = 'http://' . (string)($_SERVER['HTTP_HOST'] ?? '');
        $host = strtolower((string)parse_url($httpHost, PHP_URL_HOST));
        $port = parse_url($httpHost, PHP_URL_PORT);
        return $loopback((string)($_SERVER['REMOTE_ADDR'] ?? ''))
            && $loopback((string)($_SERVER['SERVER_ADDR'] ?? ''))
            && in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            && $port === 8088;
    }

    public static function approvalService(string $capability): ?BookingApprovalService
    {
        $enabled = match ($capability) {
            'read'=>self::officerReadEnabled(),
            'forward','section3_draft','notify'=>self::officerReadEnabled() && self::officerEditEnabled(),
            'decision'=>self::approvalEnabled(),
            default=>false,
        };
        if (!$enabled) return null;
        $config = ConnectionFactory::config('auth_test');
        if ($config['host'] !== '127.0.0.1' || (int)$config['port'] !== 3306) {
            throw new \RuntimeException('BOOKING_APPROVAL_TARGET_REJECTED');
        }
        $db = ConnectionFactory::connect('auth_test');
        if ($db->query('SELECT @@server_uuid')->fetchColumn() !== \App\Database\Migration007TargetIdentity::UUID) {
            throw new \RuntimeException('BOOKING_APPROVAL_TARGET_REJECTED');
        }
        $authorities = json_decode(Env::get('TEST_BOOKING_APPROVAL_AUTHORITIES', '{}'), true, 8);
        return new BookingApprovalService(new PdoBookingSubmissionRepository($db, $capability), is_array($authorities) ? $authorities : []);
    }

    public static function officerDeleteService(): ?OfficerBookingDeletionService
    {
        if (!self::officerReadEnabled() || Env::get('TEST_DB_EXECUTION_APPROVED') !== 'true'
            || Env::get('TEST_BOOKING_OFFICER_DELETE_APPROVED') !== 'true') return null;

        if (!\App\Database\BookingSoftDelete::expected()) return null;
        $config = ConnectionFactory::config('auth_test');
        if ((int)$config['port'] !== 3306) throw new \RuntimeException('BOOKING_DELETE_TARGET_REJECTED');
        $db = ConnectionFactory::connect('auth_test');
        if ($db->query('SELECT @@server_uuid')->fetchColumn() !== \App\Database\Migration007TargetIdentity::UUID) {
            throw new \RuntimeException('BOOKING_DELETE_TARGET_REJECTED');
        }
        return new OfficerBookingDeletionService(
            new PdoBookingSubmissionRepository($db, 'officer_delete')
        );
    }
}
