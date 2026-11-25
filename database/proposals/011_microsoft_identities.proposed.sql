-- DRAFT ONLY. Requires separate schema, grant, backup and migration approval.
-- Registered only in the explicit 011 dependency branch of MigrationRunner.
-- Schema dependency: users.id from 001 and existing auth_version/RBAC from 004.
-- Driver 010 must remain absent; 011 records its own version/checksum and never marks 010 applied.
CREATE TABLE microsoft_identities (
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    object_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_microsoft_identity (tenant_id, object_id),
    KEY idx_microsoft_identity_creator (created_by),
    CONSTRAINT fk_microsoft_identity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_microsoft_identity_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_microsoft_tenant_uuid CHECK (REGEXP_LIKE(tenant_id, '^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$', 'c')),
    CONSTRAINT chk_microsoft_object_uuid CHECK (REGEXP_LIKE(object_id, '^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$', 'c')),
    CONSTRAINT chk_microsoft_nonzero_uuid CHECK (tenant_id <> '00000000-0000-0000-0000-000000000000' AND object_id <> '00000000-0000-0000-0000-000000000000')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
