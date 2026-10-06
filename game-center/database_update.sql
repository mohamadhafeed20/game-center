-- ─────────────────────────────────────────────────────────────────────────────
-- Gaming Center — Migration Script (v1 → v2)
-- Run this ONLY if you already have the original database installed.
-- ─────────────────────────────────────────────────────────────────────────────

USE gaming_center;

-- Add prepaid session support to sessions table
ALTER TABLE sessions
    ADD COLUMN session_mode     ENUM('open', 'prepaid') DEFAULT 'open'  AFTER status,
    ADD COLUMN prepaid_seconds  INT                     DEFAULT NULL     AFTER session_mode;

-- Cafe & concessions add-ons table
CREATE TABLE IF NOT EXISTS add_ons (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT           NOT NULL,
    item_name  VARCHAR(100)  NOT NULL,
    item_price DECIMAL(10,2) NOT NULL,
    added_at   DATETIME      DEFAULT NOW(),
    FOREIGN KEY (session_id) REFERENCES sessions(id)
);
