-- system/migrations/crm_outreach.sql
-- Run in phpMyAdmin or: mysql -u root pestify < system/migrations/crm_outreach.sql
--
-- Log of promotional / outreach emails sent by CRM staff to past customers
-- from provider-portal/crm-outreach.php.
--
-- sent_by_name/sent_by_role are stored as plain text rather than a staff FK
-- on purpose: portal_staff_id can be a non-integer string for owner sessions
-- (e.g. "owner_5", set by direct-entry.php), which would corrupt an INT column.

CREATE TABLE IF NOT EXISTS crm_outreach_log (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    provider_id            INT NOT NULL,
    seeker_user_id         INT NOT NULL,
    featured_service_id    INT DEFAULT NULL,
    featured_service_name  VARCHAR(255) DEFAULT NULL,
    sent_by_name           VARCHAR(150) DEFAULT NULL,
    sent_by_role           VARCHAR(20)  DEFAULT NULL,
    subject                VARCHAR(255) NOT NULL,
    message                TEXT NOT NULL,
    status                 ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    error_message          VARCHAR(255) DEFAULT NULL,
    created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_provider (provider_id),
    INDEX idx_seeker (seeker_user_id)
);

-- Added after the initial release, once outreach messages could feature a
-- specific service with a clickable booking link.
ALTER TABLE crm_outreach_log
    ADD COLUMN IF NOT EXISTS featured_service_id   INT DEFAULT NULL AFTER seeker_user_id,
    ADD COLUMN IF NOT EXISTS featured_service_name VARCHAR(255) DEFAULT NULL AFTER featured_service_id;
