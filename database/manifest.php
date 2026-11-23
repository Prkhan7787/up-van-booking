<?php
declare(strict_types=1);
// Pinned after Phase A review. Never regenerate to bypass an applied checksum mismatch.
return [
    ['version'=>'001_identity', 'file'=>'database/migrations/001_identity.sql', 'kind'=>'ddl', 'sha256'=>'a8afc40e4e422b640caf40289dc92734f71f2bcb26f3141ccd8f7b81257464d8', 'objects'=>['roles', 'users', 'user_roles', 'audit_logs']],
    ['version'=>'002_booking_design', 'file'=>'database/migrations/002_booking_design.sql', 'kind'=>'ddl', 'sha256'=>'298e8702b4c0480797bb3fd926e80374491abba7768560f8002cbc858be946aa', 'objects'=>['vehicles', 'drivers', 'booking_requests', 'booking_passengers', 'booking_reviews', 'approval_decisions', 'vehicle_assignments', 'booking_notifications', 'booking_status_history', 'calendar_events']],
    ['version'=>'003_roles', 'file'=>'database/seeds/003_roles.sql', 'kind'=>'seed', 'sha256'=>'43e89c65e36df2aa401182f2fefb6fa13289f7a3e9bbde32062057cb5b4495f2', 'objects'=>[]],
    ['version'=>'004_auth_security', 'file'=>'database/drafts/004_auth_security.sql', 'kind'=>'forward', 'sha256'=>'d63494ce561d8e8de7eb6c1d793404a7b6c5179f3168719be37c844238966dd1', 'objects'=>['auth_login_buckets']],
    ['version'=>'005_vehicle_registration_only', 'file'=>'database/drafts/005_vehicle_registration_only.sql', 'kind'=>'forward_test', 'sha256'=>'4a6cf9274da4b60543b67004b35b4a46cd816195c9dfb6f5aa361b503d578ae6', 'objects'=>[]],
];
