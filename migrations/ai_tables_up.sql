-- ==============================================================================
-- Migration: UP - AI Chatbot Agent Tables (Additive Only)
-- Application: SETTribe AMS
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `ai_pending_actions` (
    `confirmation_id` VARCHAR(64) NOT NULL PRIMARY KEY,
    `user_id` VARCHAR(225) NOT NULL,
    `action_name` VARCHAR(100) NOT NULL,
    `params_hash` VARCHAR(64) NOT NULL,
    `params_json` TEXT NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `is_used` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_pending` (`user_id`, `expires_at`, `is_used`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_audit_log` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `request_id` VARCHAR(64) NOT NULL,
    `user_id` VARCHAR(225) NOT NULL,
    `user_role` VARCHAR(50) NOT NULL,
    `intent` VARCHAR(100) NOT NULL,
    `tool_name` VARCHAR(100) NULL,
    `params_hash` VARCHAR(64) NULL,
    `status` VARCHAR(20) NOT NULL,
    `execution_time_ms` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_user` (`user_id`, `created_at`),
    INDEX `idx_audit_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
