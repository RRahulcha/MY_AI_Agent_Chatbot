-- ==============================================================================
-- Migration: DOWN - Revert AI Chatbot Agent Tables
-- Application: SETTribe AMS
-- ==============================================================================

DROP TABLE IF EXISTS `ai_pending_actions`;
DROP TABLE IF EXISTS `ai_audit_log`;
