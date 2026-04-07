<?php
// api/lib/item_runtime.php
// Item helpers (rAthena-like commands for scripts)
// - Item master is DB(ref_item). Script uses ITEM_* const_name tokens or numeric IDs.
// - Player inventory stored in player_item(player_id,item_id,qty)

require_once __DIR__ . '/../config.php';

function item_table_exists(mysqli $conn, string $table): bool {
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

function item_ensure_tables(mysqli $conn): void {
  // Create player_item if missing (users may not have run full_reset.sql)
  if (!item_table_exists($conn, 'player_item')) {
    $sql = "CREATE TABLE `player_item` (
      `player_id` INT NOT NULL,
      `item_id` INT NOT NULL,
      `qty` INT NOT NULL DEFAULT 0,
      PRIMARY KEY (`player_id`,`item_id`),
      CONSTRAINT `fk_player_item_player` FOREIGN KEY (`player_id`) REFERENCES `player`(`player_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sql);
  }
}

function item_normalize_token(string $tok): string {
  $tok = trim($tok);
  $tok = trim($tok, "\"'");
  $tok = trim($tok);
  // Convert spaces to underscore (people sometimes type 'ITEM POTION')
  $tok = preg_replace('/\s+/', '_', $tok);
  return strtoupper((string)$tok);
}

function item_resolve_id(mysqli $conn, $token): int {
  // numeric id
  if (is_int($token)) return $token;
  if (is_string($token) && preg_match('/^\d+$/', trim($token))) return (int)trim($token);

  $tok = item_normalize_token((string)$token);
  if ($tok === '') return 0;

  static $cache = [];
  if (isset($cache[$tok])) return (int)$cache[$tok];

  // Prefer ref_item.const_name (ITEM_POTION, ITEM_MASTER_BALL, ...)
  $id = 0;

  if (item_table_exists($conn, 'ref_item')) {
    $stmt = $conn->prepare('SELECT item_id FROM ref_item WHERE const_name=? LIMIT 1');
    if ($stmt) {
      $stmt->bind_param('s', $tok);
      $stmt->execute();
      $res = $stmt->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $stmt->close();
      if ($row && isset($row['item_id'])) $id = (int)$row['item_id'];
    }

    // Convenience: allow POTION -> ITEM_POTION
    if ($id <= 0 && strpos($tok, 'ITEM_') !== 0) {
      $tok2 = 'ITEM_' . $tok;
      $stmt = $conn->prepare('SELECT item_id FROM ref_item WHERE const_name=? LIMIT 1');
      if ($stmt) {
        $stmt->bind_param('s', $tok2);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row && isset($row['item_id'])) {
          $id = (int)$row['item_id'];
          // also cache under the original token
          $cache[$tok2] = $id;
        }
      }
    }
  }

  $cache[$tok] = $id;
  return $id;
}

function player_item_count(mysqli $conn, int $player_id, $itemToken): int {
  item_ensure_tables($conn);
  $item_id = item_resolve_id($conn, $itemToken);
  if ($player_id <= 0 || $item_id <= 0) return 0;

  $stmt = $conn->prepare('SELECT qty FROM player_item WHERE player_id=? AND item_id=? LIMIT 1');
  if (!$stmt) return 0;
  $stmt->bind_param('ii', $player_id, $item_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return $row ? (int)$row['qty'] : 0;
}

function player_item_has(mysqli $conn, int $player_id, $itemToken, int $needQty): bool {
  if ($needQty <= 0) return true;
  return player_item_count($conn, $player_id, $itemToken) >= $needQty;
}

function player_item_add(mysqli $conn, int $player_id, $itemToken, int $qty): array {
  // returns ['ok'=>bool,'item_id'=>int,'qty'=>int,'error'?...]
  item_ensure_tables($conn);
  $item_id = item_resolve_id($conn, $itemToken);
  if ($player_id <= 0) return ['ok'=>false,'error'=>'BAD_PLAYER'];
  if ($item_id <= 0) return ['ok'=>false,'error'=>'BAD_ITEM','token'=>(string)$itemToken];
  if ($qty <= 0) return ['ok'=>true,'item_id'=>$item_id,'qty'=>player_item_count($conn,$player_id,$item_id)];

  $stmt = $conn->prepare('INSERT INTO player_item(player_id,item_id,qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)');
  if (!$stmt) return ['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error];
  $stmt->bind_param('iii', $player_id, $item_id, $qty);
  $ok = $stmt->execute();
  $err = $stmt->error;
  $stmt->close();
  if (!$ok) return ['ok'=>false,'error'=>'DB_EXEC_FAIL','detail'=>$err];

  return ['ok'=>true,'item_id'=>$item_id,'qty'=>player_item_count($conn,$player_id,$item_id)];
}

function player_item_remove(mysqli $conn, int $player_id, $itemToken, int $qty): array {
  // returns ['ok'=>bool,'item_id'=>int,'qty'=>int,'error'?...]
  item_ensure_tables($conn);
  $item_id = item_resolve_id($conn, $itemToken);
  if ($player_id <= 0) return ['ok'=>false,'error'=>'BAD_PLAYER'];
  if ($item_id <= 0) return ['ok'=>false,'error'=>'BAD_ITEM','token'=>(string)$itemToken];
  if ($qty <= 0) return ['ok'=>true,'item_id'=>$item_id,'qty'=>player_item_count($conn,$player_id,$item_id)];

  try {
    $conn->begin_transaction();

    $cur = 0;
    $stmt = $conn->prepare('SELECT qty FROM player_item WHERE player_id=? AND item_id=? FOR UPDATE');
    if (!$stmt) {
      $conn->rollback();
      return ['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error];
    }
    $stmt->bind_param('ii', $player_id, $item_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) $cur = (int)$row['qty'];

    if ($cur < $qty) {
      $conn->rollback();
      return ['ok'=>false,'error'=>'NOT_ENOUGH','item_id'=>$item_id,'qty'=>$cur];
    }

    $newQty = $cur - $qty;
    if ($newQty <= 0) {
      $stmt = $conn->prepare('DELETE FROM player_item WHERE player_id=? AND item_id=?');
      if (!$stmt) {
        $conn->rollback();
        return ['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error];
      }
      $stmt->bind_param('ii', $player_id, $item_id);
      $ok = $stmt->execute();
      $err = $stmt->error;
      $stmt->close();
      if (!$ok) {
        $conn->rollback();
        return ['ok'=>false,'error'=>'DB_EXEC_FAIL','detail'=>$err];
      }
      $conn->commit();
      return ['ok'=>true,'item_id'=>$item_id,'qty'=>0];
    }

    $stmt = $conn->prepare('UPDATE player_item SET qty=? WHERE player_id=? AND item_id=?');
    if (!$stmt) {
      $conn->rollback();
      return ['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error];
    }
    $stmt->bind_param('iii', $newQty, $player_id, $item_id);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();
    if (!$ok) {
      $conn->rollback();
      return ['ok'=>false,'error'=>'DB_EXEC_FAIL','detail'=>$err];
    }

    $conn->commit();
    return ['ok'=>true,'item_id'=>$item_id,'qty'=>$newQty];
  } catch (Throwable $e) {
    @ $conn->rollback();
    return ['ok'=>false,'error'=>'EXCEPTION','detail'=>$e->getMessage()];
  }
}
