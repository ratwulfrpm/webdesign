-- =============================================================
-- migrate_audit_log.sql
-- Creates the audit_log table for the product-assignments module.
--
-- Events written by this module:
--   assignment_created    — admin/owner created a new assignment
--   quote_accessed        — client successfully viewed a quote
--   quote_invalid_token   — bad-format or not-found token attempt
--   quote_not_active      — valid token but status != active
--   quote_expired         — valid token but past expires_at
--   quote_product_inactive — valid token but linked product deactivated
--   quote_rate_limited    — IP exceeded invalid-token threshold
-- =============================================================

CREATE TABLE IF NOT EXISTS audit_log (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    event         VARCHAR(80)      NOT NULL,
    severity      ENUM('info','warning','error') NOT NULL DEFAULT 'info',
    assignment_id INT UNSIGNED     NULL DEFAULT NULL,   -- relates to product_assignments.id (no FK — log survives deletions)
    user_id       INT UNSIGNED     NULL DEFAULT NULL,   -- NULL for anonymous/public events
    ip_address    VARCHAR(45)      NOT NULL,            -- IPv4 or IPv6
    user_agent    VARCHAR(500)     NULL DEFAULT NULL,
    detail        TEXT             NULL DEFAULT NULL,   -- JSON-encoded extra context
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_al_event       (event),
    KEY idx_al_assignment  (assignment_id),
    KEY idx_al_user        (user_id),
    KEY idx_al_ip          (ip_address(20)),
    KEY idx_al_created_at  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
