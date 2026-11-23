-- TASK 03 REVIEWED CANDIDATE. NOT EXECUTED. MySQL 8.4 / InnoDB.
-- Explicit referential actions omitted: InnoDB default NO ACTION/RESTRICT retains history.
-- This avoids ON DELETE clauses on FK columns participating in CHECK expressions.
-- driver_extra_pay means เงินเพิ่มพิเศษพนักงานขับรถ, never personal funds.
-- Requires Task 01 schema. Review open questions before any future deployment.
-- No CREATE DATABASE, USE, ALTER of existing tables, accounts, or role changes.
CREATE TABLE vehicles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_number VARCHAR(30) NOT NULL,
    registration_province VARCHAR(100) NOT NULL,
    label VARCHAR(150) NOT NULL,
    passenger_capacity SMALLINT UNSIGNED NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_vehicle_registration (registration_number, registration_province),
    CONSTRAINT fk_vehicle_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_vehicle_editor FOREIGN KEY (updated_by) REFERENCES users(id),
    CONSTRAINT chk_vehicle_capacity CHECK (passenger_capacity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE drivers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_code VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(150) NOT NULL,
    contact_phone VARCHAR(30) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_driver_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_driver_editor FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_id BIGINT UNSIGNED NOT NULL,
    request_number VARCHAR(50) NULL UNIQUE COMMENT 'Generated at submission; numbering policy pending',
    application_date DATE NULL COMMENT 'Form date in Asia/Bangkok; not the server timestamp',
    travel_type VARCHAR(20) NULL,
    requester_name VARCHAR(150) NULL COMMENT 'Form snapshot; not a live profile lookup',
    requester_position VARCHAR(150) NULL,
    requester_affiliation VARCHAR(255) NULL,
    requester_phone VARCHAR(30) NULL,
    purpose TEXT NULL,
    destination VARCHAR(500) NULL,
    additional_details TEXT NULL,
    expense_source VARCHAR(32) NULL,
    expense_source_details TEXT NULL,
    pickup_person_name VARCHAR(150) NULL,
    pickup_person_phone VARCHAR(30) NULL,
    pickup_location VARCHAR(500) NULL,
    pickup_at DATETIME(6) NULL,
    dropoff_location VARCHAR(500) NULL,
    dropoff_at DATETIME(6) NULL,
    coordinator_name VARCHAR(150) NULL,
    coordinator_phone VARCHAR(30) NULL,
    requested_start_at DATETIME(6) NULL COMMENT 'UTC; display Asia/Bangkok',
    requested_end_at DATETIME(6) NULL COMMENT 'UTC; half-open interval',
    passenger_count SMALLINT UNSIGNED NULL,
    workflow_status VARCHAR(32) NOT NULL DEFAULT 'draft',
    submitted_at DATETIME(6) NULL,
    cancellation_requested_by BIGINT UNSIGNED NULL,
    cancellation_contacted_at DATETIME(6) NULL,
    cancelled_at DATETIME(6) NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    cancellation_reason TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    KEY idx_booking_owner (requester_id, created_at),
    KEY idx_booking_queue (workflow_status, submitted_at),
    CONSTRAINT fk_booking_requester FOREIGN KEY (requester_id) REFERENCES users(id),
    CONSTRAINT fk_booking_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_booking_editor FOREIGN KEY (updated_by) REFERENCES users(id),
    CONSTRAINT fk_booking_cancel_requester FOREIGN KEY (cancellation_requested_by) REFERENCES users(id),
    CONSTRAINT fk_booking_cancel_actor FOREIGN KEY (cancelled_by) REFERENCES users(id),
    CONSTRAINT chk_booking_status CHECK (workflow_status IN ('draft','submitted','under_review','pending_approval','awaiting_assignment','awaiting_rejection_notice','assigned','completed','closed_rejected','cancelled')),
    CONSTRAINT chk_booking_travel_type CHECK (travel_type IS NULL OR travel_type IN ('within_province','outside_province')),
    CONSTRAINT chk_booking_expense_source CHECK (expense_source IS NULL OR expense_source IN ('institute_budget','project_budget','other_expenses','driver_extra_pay')),
    CONSTRAINT chk_booking_window CHECK (
        (requested_start_at IS NULL AND requested_end_at IS NULL) OR
        (requested_start_at IS NOT NULL AND requested_end_at IS NOT NULL AND requested_start_at < requested_end_at)),
    CONSTRAINT chk_booking_count CHECK (passenger_count IS NULL OR passenger_count > 0),
    -- Form-field requiredness is pending confirmation; do not encode proposed fields as mandatory.
    CONSTRAINT chk_booking_submission CHECK (workflow_status = 'draft' OR submitted_at IS NOT NULL),
    CONSTRAINT chk_booking_cancel CHECK (
        (workflow_status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL
         AND cancellation_requested_by IS NOT NULL AND cancellation_contacted_at IS NOT NULL
         AND cancellation_reason IS NOT NULL AND CHAR_LENGTH(TRIM(cancellation_reason)) > 0)
        OR (workflow_status <> 'cancelled' AND cancelled_at IS NULL AND cancelled_by IS NULL
            AND cancellation_requested_by IS NULL AND cancellation_contacted_at IS NULL AND cancellation_reason IS NULL)),
    CONSTRAINT chk_booking_version CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_passengers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_request_id BIGINT UNSIGNED NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    affiliation VARCHAR(150) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_passenger_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_passenger_editor FOREIGN KEY (updated_by) REFERENCES users(id),
    CONSTRAINT fk_passenger_booking FOREIGN KEY (booking_request_id) REFERENCES booking_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One review record for the current one-submission workflow. No assignment fields here.
CREATE TABLE booking_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_request_id BIGINT UNSIGNED NOT NULL UNIQUE,
    reviewed_by BIGINT UNSIGNED NOT NULL,
    reviewed_at DATETIME(6) NOT NULL,
    receipt_result VARCHAR(24) NOT NULL,
    review_notes TEXT NULL,
    proposed_at DATETIME(6) NULL,
    proposed_by BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_review_booking FOREIGN KEY (booking_request_id) REFERENCES booking_requests(id),
    CONSTRAINT fk_review_actor FOREIGN KEY (reviewed_by) REFERENCES users(id),
    CONSTRAINT fk_review_proposer FOREIGN KEY (proposed_by) REFERENCES users(id),
    CONSTRAINT fk_review_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_review_editor FOREIGN KEY (updated_by) REFERENCES users(id),
    CONSTRAINT chk_review_result CHECK (receipt_result IN ('received','ready_for_approval')),
    CONSTRAINT chk_review_proposal CHECK (
        (receipt_result = 'received' AND proposed_at IS NULL AND proposed_by IS NULL)
        OR (receipt_result = 'ready_for_approval' AND proposed_at IS NOT NULL AND proposed_by IS NOT NULL AND proposed_at >= reviewed_at))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approval_decisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_request_id BIGINT UNSIGNED NOT NULL UNIQUE,
    decided_by BIGINT UNSIGNED NOT NULL,
    authority_kind VARCHAR(16) NOT NULL COMMENT 'director or delegate; not an RBAC role',
    authority_reference VARCHAR(255) NULL,
    outcome VARCHAR(16) NOT NULL,
    reason TEXT NULL,
    decided_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    returned_to_officer_at DATETIME(6) NOT NULL COMMENT 'Published to officer queue in decision transaction',
    CONSTRAINT fk_decision_booking FOREIGN KEY (booking_request_id) REFERENCES booking_requests(id),
    CONSTRAINT fk_decision_actor FOREIGN KEY (decided_by) REFERENCES users(id),
    CONSTRAINT chk_decision_outcome CHECK (outcome IN ('approved','rejected')),
    CONSTRAINT chk_decision_authority CHECK (authority_kind IN ('director','delegate')),
    CONSTRAINT chk_decision_delegate CHECK (authority_kind <> 'delegate' OR (authority_reference IS NOT NULL AND CHAR_LENGTH(TRIM(authority_reference)) > 0)),
    CONSTRAINT chk_decision_reason CHECK (outcome <> 'rejected' OR (reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0)),
    CONSTRAINT chk_decision_return CHECK (returned_to_officer_at >= decided_at),
    KEY idx_decision_actor (decided_by, decided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicle_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_request_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    driver_id BIGINT UNSIGNED NOT NULL,
    starts_at DATETIME(6) NOT NULL COMMENT 'UTC; departure time; no buffer',
    ends_at DATETIME(6) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    active_booking_request_id BIGINT UNSIGNED GENERATED ALWAYS AS
        (CASE WHEN status = 'active' THEN booking_request_id ELSE NULL END) VIRTUAL,
    UNIQUE KEY uq_assignment_one_active_booking (active_booking_request_id),
    assigned_by BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_by BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    driver_acknowledged_at DATETIME(6) NULL,
    driver_acknowledgement_recorded_by BIGINT UNSIGNED NULL,
    driver_acknowledgement_recorded_at DATETIME(6) NULL,
    driver_acknowledgement_reference VARCHAR(255) NULL,
    actual_return_at DATETIME(6) NULL COMMENT 'Actual arrival back at university; not scheduled end',
    actual_return_recorded_by BIGINT UNSIGNED NULL,
    actual_return_recorded_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    cancelled_at DATETIME(6) NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    cancellation_reason TEXT NULL,
    replaces_assignment_id BIGINT UNSIGNED NULL UNIQUE,
    KEY idx_assignment_vehicle_window (vehicle_id, status, starts_at, ends_at),
    KEY idx_assignment_driver_window (driver_id, status, starts_at, ends_at),
    KEY idx_assignment_booking (booking_request_id, status),
    CONSTRAINT fk_assignment_booking FOREIGN KEY (booking_request_id) REFERENCES booking_requests(id),
    CONSTRAINT fk_assignment_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
    CONSTRAINT fk_assignment_driver FOREIGN KEY (driver_id) REFERENCES drivers(id),
    CONSTRAINT fk_assignment_actor FOREIGN KEY (assigned_by) REFERENCES users(id),
    CONSTRAINT fk_assignment_editor FOREIGN KEY (updated_by) REFERENCES users(id),
    CONSTRAINT fk_assignment_ack_actor FOREIGN KEY (driver_acknowledgement_recorded_by) REFERENCES users(id),
    CONSTRAINT fk_assignment_return_actor FOREIGN KEY (actual_return_recorded_by) REFERENCES users(id),
    CONSTRAINT fk_assignment_cancel_actor FOREIGN KEY (cancelled_by) REFERENCES users(id),
    CONSTRAINT fk_assignment_previous FOREIGN KEY (replaces_assignment_id) REFERENCES vehicle_assignments(id),
    CONSTRAINT chk_assignment_window CHECK (starts_at < ends_at),
    CONSTRAINT chk_assignment_status CHECK (status IN ('active','completed','cancelled')),
    CONSTRAINT chk_assignment_ack CHECK (
        (driver_acknowledged_at IS NULL AND driver_acknowledgement_recorded_by IS NULL AND driver_acknowledgement_recorded_at IS NULL AND driver_acknowledgement_reference IS NULL)
        OR (driver_acknowledged_at IS NOT NULL AND driver_acknowledgement_recorded_by IS NOT NULL AND driver_acknowledgement_recorded_at IS NOT NULL)),
    CONSTRAINT chk_assignment_actual_return CHECK (
        (actual_return_at IS NULL AND actual_return_recorded_by IS NULL AND actual_return_recorded_at IS NULL)
        OR (actual_return_at IS NOT NULL AND actual_return_recorded_by IS NOT NULL AND actual_return_recorded_at IS NOT NULL AND actual_return_at >= starts_at)),
    CONSTRAINT chk_assignment_cancel CHECK (
        (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancellation_reason IS NOT NULL AND CHAR_LENGTH(TRIM(cancellation_reason)) > 0)
        OR (status <> 'cancelled' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Successful notifications only; retry/delivery transport is outside Task 02.
CREATE TABLE booking_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_request_id BIGINT UNSIGNED NOT NULL,
    outcome VARCHAR(16) NOT NULL,
    channel VARCHAR(50) NOT NULL,
    notification_reference VARCHAR(255) NULL,
    notified_at DATETIME(6) NOT NULL,
    notified_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_notification_booking (booking_request_id, outcome, notified_at),
    CONSTRAINT fk_notice_booking FOREIGN KEY (booking_request_id) REFERENCES booking_requests(id),
    CONSTRAINT fk_notice_actor FOREIGN KEY (notified_by) REFERENCES users(id),
    CONSTRAINT chk_notice_outcome CHECK (outcome IN ('approved','rejected')),
    CONSTRAINT chk_notice_channel CHECK (CHAR_LENGTH(TRIM(channel)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_request_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(32) NULL COMMENT 'NULL only on draft creation',
    to_status VARCHAR(32) NOT NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    booking_version INT UNSIGNED NOT NULL,
    reason TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_history_version (booking_request_id, booking_version),
    KEY idx_history_time (booking_request_id, created_at),
    CONSTRAINT fk_history_booking FOREIGN KEY (booking_request_id) REFERENCES booking_requests(id),
    CONSTRAINT fk_history_actor FOREIGN KEY (changed_by) REFERENCES users(id),
    CONSTRAINT chk_history_from CHECK (from_status IS NULL OR from_status IN ('draft','submitted','under_review','pending_approval','awaiting_assignment','awaiting_rejection_notice','assigned','completed','closed_rejected','cancelled')),
    CONSTRAINT chk_history_to CHECK (to_status IN ('draft','submitted','under_review','pending_approval','awaiting_assignment','awaiting_rejection_notice','assigned','completed','closed_rejected','cancelled')),
    CONSTRAINT chk_history_change CHECK ((from_status IS NULL AND to_status = 'draft') OR (from_status IS NOT NULL AND from_status <> to_status)),
    CONSTRAINT chk_history_version CHECK (booking_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calendar is a derived projection, not a second source of scheduling truth.
CREATE SQL SECURITY INVOKER VIEW calendar_events AS
SELECT a.id AS assignment_id, a.booking_request_id, a.vehicle_id, a.driver_id,
       a.starts_at, a.ends_at, a.status AS assignment_status, b.workflow_status
FROM vehicle_assignments a
JOIN booking_requests b ON b.id = a.booking_request_id
WHERE a.status IN ('active', 'completed') AND b.workflow_status IN ('assigned', 'completed');
