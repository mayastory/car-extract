-- JTMES / JMP Assist 계정별 조회 옵션 저장 테이블
-- dp_get_pdo()가 사용하는 JTMES DB에서 1회 실행하세요.
-- user_key는 로그인 세션의 ship_user_no를 최우선으로 사용하므로 계정별로 옵션이 분리됩니다.

CREATE TABLE IF NOT EXISTS `ipqc_jmp_saved_filter` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_key` VARCHAR(191) NOT NULL COMMENT '계정 식별키 (기본: user_no:<account.No>)',
  `option_name` VARCHAR(80) NOT NULL,
  `payload_json` LONGTEXT NOT NULL COMMENT 'JMP Assist 전체 콤보 선택값 JSON',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ipqc_jmp_saved_filter_user_name` (`user_key`, `option_name`),
  KEY `idx_ipqc_jmp_saved_filter_user` (`user_key`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
