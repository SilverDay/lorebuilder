-- Migration 006: Add email change columns for user profile feature
-- Supports the "change email" flow where a verification token is sent to the new address.

ALTER TABLE users
    ADD COLUMN email_change_pending VARCHAR(254) NULL DEFAULT NULL AFTER email_verify_token,
    ADD COLUMN email_change_token   VARCHAR(128) NULL DEFAULT NULL AFTER email_change_pending,
    ADD COLUMN email_change_expires DATETIME     NULL DEFAULT NULL AFTER email_change_token;
