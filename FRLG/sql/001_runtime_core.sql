-- Minimal FRLG runtime starter schema.
-- Keep reference data extracted from Packege separately later.

CREATE TABLE IF NOT EXISTS account (
  account_id INT NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL,
  password_hash VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (account_id),
  UNIQUE KEY uq_account_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS player (
  player_id INT NOT NULL AUTO_INCREMENT,
  account_id INT NOT NULL,
  slot TINYINT UNSIGNED NOT NULL DEFAULT 0,
  display_name VARCHAR(24) NOT NULL,
  map_id VARCHAR(64) NOT NULL DEFAULT 'PalletTown',
  x INT NOT NULL DEFAULT 10,
  y INT NOT NULL DEFAULT 10,
  dir TINYINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id),
  UNIQUE KEY uq_player_account_slot (account_id, slot),
  CONSTRAINT fk_player_account FOREIGN KEY (account_id) REFERENCES account(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS player_flag (
  player_id INT NOT NULL,
  flag VARCHAR(64) NOT NULL,
  value INT NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id, flag),
  CONSTRAINT fk_player_flag_player FOREIGN KEY (player_id) REFERENCES player(player_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
