CREATE TABLE IF NOT EXISTS qr_scan_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_no BIGINT UNSIGNED DEFAULT NULL,
  account_id VARCHAR(100) NOT NULL DEFAULT '',
  scanner_name VARCHAR(100) DEFAULT NULL,
  scan_source VARCHAR(50) NOT NULL DEFAULT '',
  raw_code VARCHAR(255) NOT NULL,
  label_code VARCHAR(100) DEFAULT NULL,
  barcode VARCHAR(100) DEFAULT NULL,
  dp_code VARCHAR(100) DEFAULT NULL,
  model_suffix CHAR(3) DEFAULT NULL,
  model_name VARCHAR(50) DEFAULT NULL,
  lot_date DATE DEFAULT NULL,
  cavity VARCHAR(10) DEFAULT NULL,
  tool VARCHAR(10) DEFAULT NULL,
  ea INT UNSIGNED DEFAULT NULL,
  remote_ip VARCHAR(45) DEFAULT NULL,
  user_agent TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_account_created (account_id, created_at),
  KEY idx_account_no_created (account_no, created_at),
  KEY idx_barcode (barcode),
  KEY idx_dp_code (dp_code),
  KEY idx_lot_model (lot_date, model_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 현재 로그인 계정별 최근 스캔 조회 예시
-- SELECT * FROM qr_scan_log
-- WHERE account_id = :account_id
-- ORDER BY created_at DESC, id DESC
-- LIMIT 100;
