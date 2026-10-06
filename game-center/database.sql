-- ─────────────────────────────────────────────────────────────────────────────
-- Gaming Center — Full Schema (v2)
-- Run this for a FRESH install. For existing installs use database_update.sql
-- ─────────────────────────────────────────────────────────────────────────────

CREATE DATABASE IF NOT EXISTS gaming_center;
USE gaming_center;

-- ─── Stations ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS stations (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    station_name VARCHAR(50)                             NOT NULL,
    type         ENUM('pc', 'ps5', 'xbox')              NOT NULL,
    hourly_rate  DECIMAL(10, 2)                          NOT NULL,
    status       ENUM('available', 'occupied', 'paused') DEFAULT 'available'
);

-- ─── Sessions ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sessions (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    station_id           INT                                    NOT NULL,
    status               ENUM('running', 'paused', 'finished')  DEFAULT 'running',
    session_mode         ENUM('open', 'prepaid')                DEFAULT 'open',
    prepaid_seconds      INT                                    DEFAULT NULL,
    start_time           DATETIME                               NOT NULL,
    pause_start_time     DATETIME                               DEFAULT NULL,
    total_paused_seconds INT                                    DEFAULT 0,
    end_time             DATETIME                               DEFAULT NULL,
    total_cost           DECIMAL(10, 2)                         DEFAULT NULL,
    FOREIGN KEY (station_id) REFERENCES stations(id)
);

-- ─── Add-ons (Cafe / Concessions) ────────────────────────────────────────────
-- Linked to a session; summed at checkout for the final grand total.
CREATE TABLE IF NOT EXISTS add_ons (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT           NOT NULL,
    item_name  VARCHAR(100)  NOT NULL,
    item_price DECIMAL(10,2) NOT NULL,
    added_at   DATETIME      DEFAULT NOW(),
    FOREIGN KEY (session_id) REFERENCES sessions(id)
);

-- ─── Seed Data ────────────────────────────────────────────────────────────────
-- 10 PCs @ 4,000 IQD/hr | 5 PS5s @ 4,500 IQD/hr | 1 Xbox @ 5,000 IQD/hr
INSERT INTO stations (station_name, type, hourly_rate) VALUES
('PC 01',         'pc',   4000.00),
('PC 02',         'pc',   4000.00),
('PC 03',         'pc',   4000.00),
('PC 04',         'pc',   4000.00),
('PC 05',         'pc',   4000.00),
('PC 06',         'pc',   4000.00),
('PC 07',         'pc',   4000.00),
('PC 08',         'pc',   4000.00),
('PC 09',         'pc',   4000.00),
('PC 10',         'pc',   4000.00),
('PS5 01',        'ps5',  4500.00),
('PS5 02',        'ps5',  4500.00),
('PS5 03',        'ps5',  4500.00),
('PS5 04',        'ps5',  4500.00),
('PS5 05',        'ps5',  4500.00),
('Xbox Series X', 'xbox', 5000.00);
