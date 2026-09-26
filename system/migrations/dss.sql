-- system/migrations/dss.sql
-- Decision Support System (provider/listing recommendation) — Phase 1.
-- Run in phpMyAdmin or: mysql -u root pestify < system/migrations/dss.sql
--
-- Logs every DSS query + the ranked results shown, so click-through and
-- booking conversion can be measured later for weight tuning. Not used to
-- compute recommendations — includes/dss_helper.php reads live data for that.

CREATE TABLE IF NOT EXISTS dss_queries (
    id             INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id        INT DEFAULT NULL COMMENT 'NULL for guest (not logged in) queries',
    need_text      VARCHAR(500) DEFAULT NULL,
    category_id    INT DEFAULT NULL,
    budget_max     DECIMAL(10,2) DEFAULT NULL,
    urgency        ENUM('flexible','soon','emergency') NOT NULL DEFAULT 'flexible',
    priority       ENUM('balanced','best_rated','best_value') NOT NULL DEFAULT 'balanced',
    eco_only       TINYINT(1) NOT NULL DEFAULT 0,
    city           VARCHAR(100) DEFAULT NULL,
    parsed_by      ENUM('rules','groq','groq_cached') NOT NULL DEFAULT 'rules',
    candidate_count INT NOT NULL DEFAULT 0,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS dss_results (
    id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    query_id        INT NOT NULL,
    listing_id      INT NOT NULL,
    provider_id     INT NOT NULL,
    rank_pos        INT NOT NULL,
    total_score     DECIMAL(6,2) NOT NULL,
    breakdown_json  TEXT DEFAULT NULL,
    clicked_at      DATETIME DEFAULT NULL,
    booked_avail_id INT DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_query_id (query_id),
    KEY idx_listing_id (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
