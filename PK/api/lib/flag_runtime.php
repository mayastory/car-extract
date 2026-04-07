<?php
// api/lib/flag_runtime.php
// rAthena-like per-player flags for one-time events (hidden items, item balls, NPC one-time rewards, etc.)
// Storage: player_flag(player_id, flag, value, updated_at)

require_once __DIR__ . '/../config.php';

function flag_table_exists(mysqli $conn, string $table): bool {
  $table = trim($table);
  if ($table === '') return false;

  $cfg = cfg();
  $dbName = (string)($cfg['db'] ?? '');
  if ($dbName === '') return false;

  $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name=? LIMIT 1');
  if (!$stmt) return false;
  $stmt->bind_param('ss', $dbName, $table);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_row() : null;
  $stmt->close();
  return $row ? true : false;
}

function flag_ensure_tables(mysqli $conn): void {
  if (flag_table_exists($conn, 'player_flag')) return;

  $sql = "CREATE TABLE `player_flag` (
    `player_id` INT NOT NULL,
    `flag` VARCHAR(96) NOT NULL,
    `value` INT NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`player_id`,`flag`),
    KEY `idx_flag_player` (`player_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  @$conn->query($sql);
}

function flag_normalize(string $flag): string {
  $flag = trim($flag);
  $flag = trim($flag, "\"'");
  $flag = trim($flag);
  // rAthena-style: keep underscores, upper-case for stability
  $flag = preg_replace('/\s+/', '_', $flag);
  return strtoupper((string)$flag);
}

function player_flag_get(mysqli $conn, int $player_id, string $flag): int {
  flag_ensure_tables($conn);
  $flag = flag_normalize($flag);
  if ($player_id <= 0 || $flag === '') return 0;

  $stmt = $conn->prepare('SELECT value FROM player_flag WHERE player_id=? AND flag=? LIMIT 1');
  if (!$stmt) return 0;
  $stmt->bind_param('is', $player_id, $flag);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return $row ? (int)($row['value'] ?? 0) : 0;
}

function player_flag_has(mysqli $conn, int $player_id, string $flag): bool {
  return player_flag_get($conn, $player_id, $flag) != 0;
}

function player_flag_set(mysqli $conn, int $player_id, string $flag, int $value=1): bool {
  flag_ensure_tables($conn);
  $flag = flag_normalize($flag);
  if ($player_id <= 0 || $flag === '') return false;

  $v = (int)$value;
  $stmt = $conn->prepare('INSERT INTO player_flag(player_id, flag, value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=CURRENT_TIMESTAMP');
  if (!$stmt) return false;
  $stmt->bind_param('isi', $player_id, $flag, $v);
  $ok = $stmt->execute();
  $stmt->close();
  return (bool)$ok;
}

function player_flag_clear(mysqli $conn, int $player_id, string $flag): bool {
  flag_ensure_tables($conn);
  $flag = flag_normalize($flag);
  if ($player_id <= 0 || $flag === '') return false;

  $stmt = $conn->prepare('DELETE FROM player_flag WHERE player_id=? AND flag=?');
  if (!$stmt) return false;
  $stmt->bind_param('is', $player_id, $flag);
  $ok = $stmt->execute();
  $stmt->close();
  return (bool)$ok;
}
