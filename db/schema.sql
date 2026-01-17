-- Single Source of Truth for Beyblade X Database
-- Can be reimported safely at any time.

CREATE DATABASE IF NOT EXISTS blader_db;
USE blader_db;

SET FOREIGN_KEY_CHECKS = 0;

-- Drop all tables to ensure a clean state
DROP TABLE IF EXISTS `match_assignments`;
DROP TABLE IF EXISTS `match_finishes`;
DROP TABLE IF EXISTS `tournament_matches`;
DROP TABLE IF EXISTS `tournament_byes`;
DROP TABLE IF EXISTS `tournament_rounds`;
DROP TABLE IF EXISTS `tournament_stadiums`;
DROP TABLE IF EXISTS `tournament_roles`;
DROP TABLE IF EXISTS `tournament_participants`;
DROP TABLE IF EXISTS `tournament_judges`;
DROP TABLE IF EXISTS `tournaments`;
DROP TABLE IF EXISTS `user_profiles`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `teams`;

-- Teams table
CREATE TABLE `teams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_teams_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Users table
CREATE TABLE `users` (
  `id` varchar(36) NOT NULL,
  `email` varchar(255) NOT NULL,
  `blader_name` varchar(255) DEFAULT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `avatar_url` text DEFAULT NULL,
  `team_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_email` (`email`),
  KEY `idx_users_team_id` (`team_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- User profiles table
CREATE TABLE `user_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` varchar(36) DEFAULT NULL,
  `location` text DEFAULT NULL,
  `preferred_beyblade_type` varchar(100) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `email_notifications` tinyint(1) DEFAULT 1,
  `public_profile` tinyint(1) DEFAULT 1,
  `show_tournament_results` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_profiles_user_id` (`user_id`),
  CONSTRAINT `user_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tournaments table
CREATE TABLE `tournaments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `date` date DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `max_participants` int(11) DEFAULT 50,
  `status` enum('upcoming','ongoing','completed') DEFAULT 'upcoming',
  `tournament_type` enum('single_elimination','double_elimination','swiss') DEFAULT 'single_elimination',
  `visibility` enum('team_only','open_for_all') DEFAULT 'team_only',
  `number_of_stadiums` int(11) DEFAULT 1,
  `swiss_rounds` int(11) DEFAULT 5,
  `top_cut` int(11) DEFAULT 0,
  `rank_to` int(11) DEFAULT 3,
  `current_stage` int(11) DEFAULT 1,
  `rules` text DEFAULT NULL,
  `created_by` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_tournaments_status` (`status`),
  KEY `idx_tournaments_date` (`date`),
  CONSTRAINT `tournaments_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tournament Stadiums
CREATE TABLE `tournament_stadiums` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `tournament_id` (`tournament_id`),
  CONSTRAINT `tournament_stadiums_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tournament Rounds
CREATE TABLE `tournament_rounds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_id` int(11) NOT NULL,
  `round_number` int(11) NOT NULL,
  `stage` int(11) DEFAULT 1,
  `status` enum('scheduled','active','completed') DEFAULT 'scheduled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_round` (`tournament_id`,`round_number`,`stage`),
  CONSTRAINT `tournament_rounds_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tournament Roles (unified system)
CREATE TABLE `tournament_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_id` int(11) DEFAULT NULL,
  `user_id` varchar(36) DEFAULT NULL,
  `role` varchar(50) NOT NULL,
  `status` enum('pending','accepted','declined') DEFAULT 'accepted',
  `seed` int(11) DEFAULT 0,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_tournament_user` (`tournament_id`,`user_id`),
  KEY `idx_tournament_roles_tournament` (`tournament_id`),
  KEY `idx_tournament_roles_user` (`user_id`),
  KEY `idx_tournament_roles_role` (`role`),
  CONSTRAINT `tournament_roles_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tournament_roles_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tournament Byes
CREATE TABLE `tournament_byes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_id` int(11) NOT NULL,
  `round_id` int(11) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_bye` (`round_id`,`user_id`),
  KEY `tournament_id` (`tournament_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `tournament_byes_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tournament_byes_ibfk_2` FOREIGN KEY (`round_id`) REFERENCES `tournament_rounds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tournament_byes_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tournament Matches
CREATE TABLE `tournament_matches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_id` int(11) NOT NULL,
  `round_id` int(11) NOT NULL,
  `match_number` int(11) NOT NULL,
  `player1_id` varchar(36) DEFAULT NULL,
  `player2_id` varchar(36) DEFAULT NULL,
  `winner_id` varchar(36) DEFAULT NULL,
  `player1_score` int(11) DEFAULT 0,
  `player2_score` int(11) DEFAULT 0,
  `status` enum('scheduled','assigned','in_progress','completed','blocked','cancelled') DEFAULT 'scheduled',
  `blocked_reason` text DEFAULT NULL,
  `next_match_id` int(11) DEFAULT NULL,
  `next_match_slot` enum('1','2') DEFAULT '1',
  `loser_next_match_id` int(11) DEFAULT NULL,
  `loser_next_match_slot` enum('1','2') DEFAULT '1',
  `judge_id` varchar(36) DEFAULT NULL,
  `stadium_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tournament_id` (`tournament_id`),
  KEY `round_id` (`round_id`),
  KEY `player1_id` (`player1_id`),
  KEY `player2_id` (`player2_id`),
  KEY `winner_id` (`winner_id`),
  KEY `judge_id` (`judge_id`),
  KEY `stadium_id` (`stadium_id`),
  KEY `next_match_id` (`next_match_id`),
  CONSTRAINT `tournament_matches_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tournament_matches_ibfk_2` FOREIGN KEY (`round_id`) REFERENCES `tournament_rounds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tournament_matches_ibfk_3` FOREIGN KEY (`player1_id`) REFERENCES `users` (`id`),
  CONSTRAINT `tournament_matches_ibfk_4` FOREIGN KEY (`player2_id`) REFERENCES `users` (`id`),
  CONSTRAINT `tournament_matches_ibfk_5` FOREIGN KEY (`winner_id`) REFERENCES `users` (`id`),
  CONSTRAINT `tournament_matches_ibfk_6` FOREIGN KEY (`judge_id`) REFERENCES `users` (`id`),
  CONSTRAINT `tournament_matches_ibfk_7` FOREIGN KEY (`stadium_id`) REFERENCES `tournament_stadiums` (`id`),
  CONSTRAINT `tournament_matches_ibfk_8` FOREIGN KEY (`next_match_id`) REFERENCES `tournament_matches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Match Finishes (Detailed Scoring)
CREATE TABLE `match_finishes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `match_id` int(11) NOT NULL,
  `player_id` varchar(36) NOT NULL,
  `finish_type` enum('Spin Finish','Burst Finish','Over Finish','Xtreme Finish') NOT NULL,
  `points` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `match_id` (`match_id`),
  KEY `player_id` (`player_id`),
  CONSTRAINT `match_finishes_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `tournament_matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `match_finishes_ibfk_2` FOREIGN KEY (`player_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Match Assignments
CREATE TABLE `match_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `match_id` int(11) NOT NULL,
  `judge_id` varchar(36) NOT NULL,
  `stadium_id` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_match` (`match_id`),
  UNIQUE KEY `unique_judge_assignment` (`judge_id`),
  UNIQUE KEY `unique_stadium_assignment` (`stadium_id`),
  KEY `stadium_id` (`stadium_id`),
  CONSTRAINT `match_assignments_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `tournament_matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `match_assignments_ibfk_2` FOREIGN KEY (`judge_id`) REFERENCES `users` (`id`),
  CONSTRAINT `match_assignments_ibfk_3` FOREIGN KEY (`stadium_id`) REFERENCES `tournament_stadiums` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Legacy/Compatibility Tables
CREATE TABLE `tournament_participants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_id` int(11) DEFAULT NULL,
  `user_id` varchar(36) DEFAULT NULL,
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_participant` (`tournament_id`,`user_id`),
  KEY `idx_tournament_participants_tournament` (`tournament_id`),
  KEY `idx_tournament_participants_user` (`user_id`),
  CONSTRAINT `tournament_participants_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tournament_participants_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tournament_judges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_id` int(11) DEFAULT NULL,
  `user_id` varchar(36) DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_judge` (`tournament_id`,`user_id`),
  KEY `idx_tournament_judges_tournament` (`tournament_id`),
  KEY `idx_tournament_judges_user` (`user_id`),
  CONSTRAINT `tournament_judges_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tournament_judges_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Insert Beyblade users (password: beyblade123)
INSERT INTO users (id, email, blader_name, display_name, password, created_at, updated_at) VALUES 
('fd55ab22-377e-404c-bab8-54a229940352', 'test@bbx.com', 'Test User', 'Blader Master', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW(), NOW())
ON DUPLICATE KEY UPDATE email = VALUES(email);
INSERT INTO users (id, email, blader_name, display_name, password, created_at, updated_at) VALUES 
(UUID(), 'takao.kinomiya@beyblade.test', 'Takao Kinomiya', 'Takao', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'max.mizuhara@beyblade.test', 'Max Mizuhara', 'Max', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'kai.hiwatari@beyblade.test', 'Kai Hiwatari', 'Kai', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'rei.kon@beyblade.test', 'Rei Kon', 'Rei', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'daichi.saien@beyblade.test', 'Daichi Saien', 'Daichi', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'ginka.hagane@beyblade.test', 'Ginka Hagane', 'Ginka', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'kyoya.tategami@beyblade.test', 'Kyoya Tategami', 'Kyoya', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'kenta.yumiya@beyblade.test', 'Kenta Yumiya', 'Kenta', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'benkei.hanawa@beyblade.test', 'Benkei Hanawa', 'Benkei', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'masamune.kadoya@beyblade.test', 'Masamune Kadoya', 'Masamune', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'toby@beyblade.test', 'Toby', 'Toby', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'zeo@beyblade.test', 'Zeo', 'Zeo', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'zero.kurogane@beyblade.test', 'Zero Kurogane', 'Zero', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'valt.aoi@beyblade.test', 'Valt Aoi', 'Valt', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'shu.kurenai@beyblade.test', 'Shu Kurenai', 'Shu', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'rantaro.kiyama@beyblade.test', 'Rantaro Kiyama', 'Rantaro', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'ken.midori@beyblade.test', 'Ken Midori', 'Ken', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'aiga.akaba@beyblade.test', 'Aiga Akaba', 'Aiga', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'fubuki.sumeragi@beyblade.test', 'Fubuki Sumeragi', 'Fubuki', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'dante.koryu@beyblade.test', 'Dante Koryu', 'Dante', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'delta.akane@beyblade.test', 'Delta Akane', 'Delta', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'hyuga.hizashi@beyblade.test', 'Hyuga Hizashi', 'Hyuga', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'hikaru.hizashi@beyblade.test', 'Hikaru Hizashi', 'Hikaru', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'bell.daikokuten@beyblade.test', 'Bell Daikokuten', 'Bell', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'bird.kazami@beyblade.test', 'Bird Kazami', 'Bird', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'ekusu.kurosu@beyblade.test', 'Ekusu Kurosu', 'Ekusu', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'multi.nanairo@beyblade.test', 'Multi Nanairo', 'Multi', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW()),
(UUID(), 'chrome.ryugu@beyblade.test', 'Chrome Ryugu', 'Chrome', '$2y$10$AbCdEfGhIjKlMnOpQrStUvWxYz1234567890abcdef', NOW(), NOW())
ON DUPLICATE KEY UPDATE email = VALUES(email);

-- Insert sample tournaments
INSERT INTO tournaments (name, date, location, max_participants, status, tournament_type, number_of_stadiums, rules, created_by, created_at, updated_at) VALUES 
('Practice Tournament', '2025-01-10', 'Local Stadium', 16, 'upcoming', 'swiss', 6, 'Swiss format, 5 rounds. Casual tournament for testing.', 'fd55ab22-377e-404c-bab8-54a229940352', NOW(), NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name);
SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Database consolidated successfully!' as status;
