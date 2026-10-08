CREATE TABLE IF NOT EXISTS `quizzes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `questions_per_attempt` INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_minutes` INT UNSIGNED NULL,
  `shuffle_answers` TINYINT(1) NOT NULL DEFAULT 1,
  `timer_warning_seconds` VARCHAR(255) NULL,
  `allow_mark_for_review` TINYINT(1) NOT NULL DEFAULT 0,
  `result_visibility` ENUM('hidden','summary','categories') NOT NULL DEFAULT 'hidden',
  `question_pool_size` INT UNSIGNED NULL,
  `start_at` DATETIME NULL,
  `end_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quiz_collaborators` (
  `quiz_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `assigned_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`quiz_id`, `user_id`),
  KEY `idx_quiz_collaborators_user` (`user_id`, `quiz_id`),
  KEY `idx_quiz_collaborators_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_quiz_collaborators_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quiz_collaborators_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quiz_collaborators_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `question_categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `quiz_id` INT UNSIGNED NOT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `shuffle_passages` TINYINT(1) NOT NULL DEFAULT 0,
  `shuffle_questions` TINYINT(1) NOT NULL DEFAULT 0,
  `min_questions` INT UNSIGNED NULL,
  `max_questions` INT UNSIGNED NULL,
  `sort_order` INT UNSIGNED NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_question_categories_owner` (`quiz_id`, `created_by`),
  FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `question_passages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `quiz_id` INT UNSIGNED NOT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `title` VARCHAR(255) NOT NULL,
  `passage_text` LONGTEXT NULL,
  `image_media_id` INT UNSIGNED NULL,
  `file_id` INT UNSIGNED NULL,
  `youtube_url` VARCHAR(500) NULL,
  `shuffle_questions` TINYINT(1) NOT NULL DEFAULT 0,
  `min_questions` INT UNSIGNED NULL,
  `max_questions` INT UNSIGNED NULL,
  `sort_order` INT UNSIGNED NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_question_passages_owner` (`quiz_id`, `created_by`),
  FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `question_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `questions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `quiz_id` INT UNSIGNED NOT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `question_text` LONGTEXT NOT NULL,
  `question_type` VARCHAR(50) NOT NULL,
  `sort_order` INT UNSIGNED NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `image_media_id` INT UNSIGNED NULL,
  `file_id` INT UNSIGNED NULL,
  `youtube_url` VARCHAR(500) NULL,
  `passage_id` INT UNSIGNED NULL,
  `passage_seq` INT UNSIGNED NULL,
  `category_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_questions_owner` (`quiz_id`, `created_by`),
  FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`passage_id`) REFERENCES `question_passages`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`category_id`) REFERENCES `question_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `question_answers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `question_id` INT UNSIGNED NOT NULL,
  `answer_text` LONGTEXT NOT NULL,
  `score` INT NOT NULL DEFAULT 0,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`question_id`) REFERENCES `questions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `students` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `cookie_id` VARCHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NULL,
  `phone` VARCHAR(50) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_cookie_id` (`cookie_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quiz_attempts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attempt_alias` VARCHAR(100) NOT NULL,
  `nomor_test` VARCHAR(100) NULL,
  `quiz_id` INT UNSIGNED NOT NULL,
  `student_id` INT UNSIGNED NOT NULL,
  `identity_locked` TINYINT(1) NOT NULL DEFAULT 0,
  `contact_name` VARCHAR(255) NULL,
  `contact_email` VARCHAR(255) NULL,
  `contact_phone` VARCHAR(50) NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `user_agent_hash` VARCHAR(255) NOT NULL,
  `email_verification_code` VARCHAR(255) NULL,
  `email_verified_at` DATETIME NULL,
  `verified_via` VARCHAR(50) NULL,
  `verified_by` INT UNSIGNED NULL,
  `verified_note` TEXT NULL,
  `delivery_mode` VARCHAR(20) NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'registered',
  `started_at` DATETIME NULL,
  `submitted_at` DATETIME NULL,
  `elapsed_seconds` INT UNSIGNED NULL,
  `last_activity_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(45) NULL,
  `total_score` INT NOT NULL DEFAULT 0,
  `otp_email_sent_at` DATETIME NULL,
  `otp_wa_sent_at` DATETIME NULL,
  `otp_try_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `otp_locked_at` DATETIME NULL,
  `reset_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `reset_at` DATETIME NULL,
  `reset_by` INT UNSIGNED NULL,
  `reset_reason` VARCHAR(255) NULL,
  `proctor_right_clicks` INT UNSIGNED NOT NULL DEFAULT 0,
  `proctor_exit_fullscreen` INT UNSIGNED NOT NULL DEFAULT 0,
  `proctor_tab_hidden` INT UNSIGNED NOT NULL DEFAULT 0,
  `proctor_window_blur` INT UNSIGNED NOT NULL DEFAULT 0,
  `proctor_shortcut_blocked` INT UNSIGNED NOT NULL DEFAULT 0,
  `proctor_copy_blocked` INT UNSIGNED NOT NULL DEFAULT 0,
  `proctor_paste_blocked` INT UNSIGNED NOT NULL DEFAULT 0,
  `proctor_devtools_flag` INT UNSIGNED NOT NULL DEFAULT 0,
  `proctor_printscreen_flag` INT UNSIGNED NOT NULL DEFAULT 0,
  `proctor_last_event_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_attempt_alias` (`attempt_alias`),
  UNIQUE KEY `uq_quiz_student` (`quiz_id`, `student_id`),
  FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attempt_questions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `seq` INT UNSIGNED NOT NULL,
  `marked_for_review` TINYINT(1) NOT NULL DEFAULT 0,
  `marked_at` DATETIME NULL,
  `available_score_snapshot` INT UNSIGNED NULL,
  `category_key_snapshot` VARCHAR(100) NULL,
  `category_id_snapshot` INT UNSIGNED NULL,
  `category_name_snapshot` VARCHAR(255) NULL,
  `options_snapshotted_at` DATETIME NULL,
  `delivery_snapshot_json` LONGTEXT NULL,
  `delivery_snapshotted_at` DATETIME NULL,
  `snapshot_at` DATETIME NULL,
  UNIQUE KEY `uq_attempt_seq` (`attempt_id`, `seq`),
  FOREIGN KEY (`attempt_id`) REFERENCES `quiz_attempts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`question_id`) REFERENCES `questions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attempt_question_options` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attempt_question_id` INT UNSIGNED NOT NULL,
  `attempt_id` INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `source_answer_id` INT UNSIGNED NOT NULL,
  `answer_text` LONGTEXT NOT NULL,
  `score` INT NOT NULL DEFAULT 0,
  `delivery_seq` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_attempt_question_option_source` (`attempt_question_id`, `source_answer_id`),
  UNIQUE KEY `uq_attempt_question_option_seq` (`attempt_question_id`, `delivery_seq`),
  KEY `idx_attempt_question_options_attempt` (`attempt_id`, `question_id`),
  KEY `idx_attempt_question_options_source` (`source_answer_id`),
  CONSTRAINT `fk_attempt_question_options_question` FOREIGN KEY (`attempt_question_id`) REFERENCES `attempt_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attempt_question_options_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `quiz_attempts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attempt_answers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `selected_answer_id` INT UNSIGNED NULL,
  `answer_text` LONGTEXT NULL,
  `computed_score` INT NOT NULL DEFAULT 0,
  `revision_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `answered_at` DATETIME NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_attempt_question` (`attempt_id`, `question_id`),
  FOREIGN KEY (`attempt_id`) REFERENCES `quiz_attempts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`question_id`) REFERENCES `questions`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`selected_answer_id`) REFERENCES `question_answers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attempt_answer_revisions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attempt_answer_id` INT UNSIGNED NOT NULL,
  `attempt_id` INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `revision_number` INT UNSIGNED NOT NULL,
  `selected_answer_id` INT UNSIGNED NULL,
  `answer_text` LONGTEXT NULL,
  `computed_score` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_attempt_answer_revision` (`attempt_answer_id`, `revision_number`),
  KEY `idx_attempt_answer_revisions_attempt` (`attempt_id`, `question_id`),
  KEY `idx_attempt_answer_revisions_selected` (`selected_answer_id`),
  CONSTRAINT `fk_attempt_answer_revisions_answer` FOREIGN KEY (`attempt_answer_id`) REFERENCES `attempt_answers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attempt_answer_revisions_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `quiz_attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attempt_answer_revisions_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attempt_answer_revisions_selected` FOREIGN KEY (`selected_answer_id`) REFERENCES `question_answers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attempt_category_scores` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` INT UNSIGNED NOT NULL,
  `category_key` VARCHAR(100) NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `category_name` VARCHAR(255) NOT NULL,
  `question_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `answered_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `correct_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `earned_score` INT NOT NULL DEFAULT 0,
  `available_score` INT UNSIGNED NOT NULL DEFAULT 0,
  `percent` DECIMAL(7,2) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_attempt_category_score` (`attempt_id`, `category_key`),
  KEY `idx_attempt_category_scores_category` (`category_id`),
  CONSTRAINT `fk_attempt_category_scores_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `quiz_attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attempt_category_scores_category` FOREIGN KEY (`category_id`) REFERENCES `question_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quiz_attempt_proctor_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` INT UNSIGNED NOT NULL,
  `quiz_id` INT UNSIGNED NOT NULL,
  `student_id` INT UNSIGNED NOT NULL,
  `event_type` VARCHAR(100) NOT NULL,
  `event_meta` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent_hash` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`attempt_id`) REFERENCES `quiz_attempts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
