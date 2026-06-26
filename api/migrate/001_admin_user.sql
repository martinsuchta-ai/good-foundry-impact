-- 001_admin_user.sql — admin login table.
--
-- Phase 0: own-login per platform (locked decision Q3). SSO comes
-- later. Same pattern as the Affiliate platform's admin_user table.
--
-- password_hash uses PHP's password_hash() with PASSWORD_BCRYPT
-- (62-char bcrypt output, $2y$ algorithm). Salt is embedded.

CREATE TABLE IF NOT EXISTS `admin_user` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`         VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `display_name`  VARCHAR(120) NULL,
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_at` DATETIME     NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admin_user_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
