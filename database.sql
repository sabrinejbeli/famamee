-- Fama Mee - Schema MySQL
CREATE DATABASE IF NOT EXISTS famamee CHARACTER SET utf8mb4;
USE famamee;
CREATE TABLE IF NOT EXISTS votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_name VARCHAR(255) NOT NULL,
    vote_type ENUM('famma','mafamech') NOT NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_ip_zone (zone_name(100), ip_address),
    INDEX idx_zone (zone_name(100)),
    INDEX idx_vote_type (vote_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE OR REPLACE VIEW v_zone_stats AS SELECT zone_name, SUM(vote_type='famma') AS famma_count, SUM(vote_type='mafamech') AS mafamech_count, COUNT(*) AS total_count FROM votes GROUP BY zone_name;
CREATE OR REPLACE VIEW v_global_stats AS SELECT SUM(vote_type='famma') AS total_famma, SUM(vote_type='mafamech') AS total_mafamech, COUNT(*) AS total_votes, COUNT(DISTINCT zone_name) AS total_zones FROM votes;
