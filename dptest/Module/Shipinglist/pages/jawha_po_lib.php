<?php
declare(strict_types=1);

/**
 * JAWHA PO state/history helper.
 * PO SN: JPO-YYYYMMDD-NNN
 */

if (!function_exists('jawha_po_make_sn')) {
    function jawha_po_make_sn(string $ymd, int $seq): string {
        $digits = preg_replace('/[^0-9]/', '', $ymd);
        if (strlen($digits) !== 8) {
            throw new InvalidArgumentException('invalid PO date');
        }
        return 'JPO-' . $digits . '-' . str_pad((string)max(1, $seq), 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('jawha_po_parse_tool_cavity')) {
    function jawha_po_parse_tool_cavity(string $tc): ?array {
        $tc = strtoupper(trim($tc));
        if (!preg_match('/^([A-Z]+)#(\d+)$/', $tc, $m)) return null;
        return ['tool' => $m[1], 'cavity' => (int)$m[2], 'tool_cavity' => $m[1] . '#' . (int)$m[2]];
    }
}

if (!function_exists('jawha_po_flatten_oqc_agg')) {
    function jawha_po_flatten_oqc_agg(array $oqcAgg): array {
        $out = [];
        $seen = [];
        foreach ($oqcAgg as $part => $agg) {
            $part = trim((string)$part);
            if ($part === '' || !is_array($agg)) continue;
            $byDate = $agg['by_date'] ?? [];
            if (!is_array($byDate)) continue;
            ksort($byDate, SORT_STRING);
            foreach ($byDate as $prodDate => $dateInfo) {
                $prodDate = trim((string)$prodDate);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prodDate)) continue;
                $pairs = is_array($dateInfo) ? ($dateInfo['pairs'] ?? []) : [];
                if (!is_array($pairs)) continue;
                $tcList = array_keys($pairs);
                sort($tcList, SORT_STRING);
                foreach ($tcList as $tcRaw) {
                    $parsed = jawha_po_parse_tool_cavity((string)$tcRaw);
                    if (!$parsed) continue;
                    $key = $part . '|' . $prodDate . '|' . $parsed['tool_cavity'];
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $out[] = [
                        'part_name' => $part,
                        'prod_date' => $prodDate,
                        'tool' => $parsed['tool'],
                        'cavity' => $parsed['cavity'],
                        'tool_cavity' => $parsed['tool_cavity'],
                    ];
                }
            }
        }
        return $out;
    }
}

if (!function_exists('jawha_po_filter_history_candidates')) {
    function jawha_po_filter_history_candidates(array $historyRows, array $currentByDate): array {
        $current = [];
        foreach ($currentByDate as $prodDate => $dateInfo) {
            $prodDate = trim((string)$prodDate);
            $pairs = is_array($dateInfo) ? ($dateInfo['pairs'] ?? []) : [];
            if (!is_array($pairs)) continue;
            foreach (array_keys($pairs) as $tcRaw) {
                $parsed = jawha_po_parse_tool_cavity((string)$tcRaw);
                if (!$parsed) continue;
                $current[$prodDate . '|' . $parsed['tool_cavity']] = true;
            }
        }

        $out = [];
        $seen = [];
        foreach ($historyRows as $row) {
            if (!is_array($row)) continue;
            $prodDate = trim((string)($row['prod_date'] ?? ''));
            $parsed = jawha_po_parse_tool_cavity((string)($row['tool_cavity'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prodDate) || !$parsed) continue;
            $key = $prodDate . '|' . $parsed['tool_cavity'];
            if (isset($current[$key]) || isset($seen[$key])) continue;
            $seen[$key] = true;
            $row['prod_date'] = $prodDate;
            $row['tool'] = $parsed['tool'];
            $row['cavity'] = $parsed['cavity'];
            $row['tool_cavity'] = $parsed['tool_cavity'];
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('jawha_po_ensure_tables')) {
    function jawha_po_ensure_tables(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `jawha_po` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `po_sn` VARCHAR(32) NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'active',
            `active_key` TINYINT NULL DEFAULT 1,
            `started_at` DATETIME NOT NULL,
            `started_by` VARCHAR(100) NULL,
            `ended_at` DATETIME NULL,
            `ended_by` VARCHAR(100) NULL,
            `start_report_finish_id` BIGINT UNSIGNED NULL,
            `last_report_finish_id` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_jawha_po_sn` (`po_sn`),
            UNIQUE KEY `uq_jawha_po_active` (`active_key`),
            KEY `idx_jawha_po_status_started` (`status`,`started_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `jawha_po_item` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `po_id` BIGINT UNSIGNED NOT NULL,
            `part_name` VARCHAR(100) NOT NULL,
            `prod_date` DATE NOT NULL,
            `tool` VARCHAR(16) NOT NULL,
            `cavity` INT NOT NULL,
            `tool_cavity` VARCHAR(32) NOT NULL,
            `first_report_finish_id` BIGINT UNSIGNED NULL,
            `last_report_finish_id` BIGINT UNSIGNED NULL,
            `first_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `seen_count` INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_jawha_po_item` (`po_id`,`part_name`,`prod_date`,`tool`,`cavity`),
            KEY `idx_jawha_po_item_part` (`po_id`,`part_name`,`prod_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `jawha_po_report` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `po_id` BIGINT UNSIGNED NOT NULL,
            `report_finish_id` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_jawha_po_report` (`po_id`,`report_finish_id`),
            UNIQUE KEY `uq_jawha_po_report_finish` (`report_finish_id`),
            KEY `idx_jawha_po_report_po` (`po_id`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 발행 건별 PO 기여분. 성적서 취소 시 해당 발행분만 정확히 제거하기 위한 정본 연결표.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `jawha_po_item_report` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `po_id` BIGINT UNSIGNED NOT NULL,
            `report_finish_id` BIGINT UNSIGNED NOT NULL,
            `part_name` VARCHAR(100) NOT NULL,
            `prod_date` DATE NOT NULL,
            `tool` VARCHAR(16) NOT NULL,
            `cavity` INT NOT NULL,
            `tool_cavity` VARCHAR(32) NOT NULL,
            `seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_jawha_po_item_report` (`po_id`,`report_finish_id`,`part_name`,`prod_date`,`tool`,`cavity`),
            KEY `idx_jawha_po_item_report_rf` (`report_finish_id`),
            KEY `idx_jawha_po_item_report_tuple` (`po_id`,`part_name`,`prod_date`,`tool`,`cavity`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('jawha_po_get_active')) {
    function jawha_po_get_active(PDO $pdo): ?array {
        jawha_po_ensure_tables($pdo);
        $st = $pdo->query("SELECT * FROM `jawha_po` WHERE `active_key` = 1 AND `status` = 'active' ORDER BY `id` DESC LIMIT 1");
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('jawha_po_start')) {
    function jawha_po_start(PDO $pdo, string $startedBy): array {
        jawha_po_ensure_tables($pdo);
        $existing = jawha_po_get_active($pdo);
        if ($existing) return $existing;

        $ymd = date('Y-m-d');
        $prefix = 'JPO-' . date('Ymd') . '-';
        $st = $pdo->prepare("SELECT `po_sn` FROM `jawha_po` WHERE `po_sn` LIKE :p ORDER BY `po_sn` DESC LIMIT 1");
        $st->execute([':p' => $prefix . '%']);
        $last = (string)($st->fetchColumn() ?: '');
        $seq = 1;
        if (preg_match('/-(\d{3,})$/', $last, $m)) $seq = ((int)$m[1]) + 1;
        $sn = jawha_po_make_sn($ymd, $seq);

        try {
            $ins = $pdo->prepare("INSERT INTO `jawha_po` (`po_sn`,`status`,`active_key`,`started_at`,`started_by`) VALUES (:sn,'active',1,NOW(),:by)");
            $ins->execute([':sn' => $sn, ':by' => mb_substr(trim($startedBy), 0, 100)]);
        } catch (Throwable $e) {
            $existing = jawha_po_get_active($pdo);
            if ($existing) return $existing;
            throw $e;
        }
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare("SELECT * FROM `jawha_po` WHERE `id`=:id LIMIT 1");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('PO start failed');
        return $row;
    }
}

if (!function_exists('jawha_po_close')) {
    function jawha_po_close(PDO $pdo, int $poId, string $endedBy): array {
        jawha_po_ensure_tables($pdo);
        $st = $pdo->prepare("UPDATE `jawha_po` SET `status`='closed', `active_key`=NULL, `ended_at`=NOW(), `ended_by`=:by WHERE `id`=:id AND `status`='active' AND `active_key`=1 LIMIT 1");
        $st->execute([':id' => $poId, ':by' => mb_substr(trim($endedBy), 0, 100)]);
        if ($st->rowCount() <= 0) return ['ok' => false, 'msg' => '이미 종료되었거나 진행 중인 PO가 아닙니다.'];
        return ['ok' => true, 'msg' => 'PO가 종료되었습니다.'];
    }
}

if (!function_exists('jawha_po_attach_report')) {
    function jawha_po_attach_report(PDO $pdo, int $poId, int $reportFinishId): void {
        if ($poId <= 0 || $reportFinishId <= 0) return;
        jawha_po_ensure_tables($pdo);
        $st = $pdo->prepare("INSERT IGNORE INTO `jawha_po_report` (`po_id`,`report_finish_id`) VALUES (:po,:rf)");
        $st->execute([':po' => $poId, ':rf' => $reportFinishId]);
        $st = $pdo->prepare("UPDATE `jawha_po` SET `start_report_finish_id`=COALESCE(`start_report_finish_id`,:rf), `last_report_finish_id`=:rf WHERE `id`=:po LIMIT 1");
        $st->execute([':po' => $poId, ':rf' => $reportFinishId]);
    }
}

if (!function_exists('jawha_po_accumulate_items')) {
    function jawha_po_accumulate_items(PDO $pdo, int $poId, array $items, int $reportFinishId = 0): int {
        if ($poId <= 0 || !$items) return 0;
        jawha_po_ensure_tables($pdo);

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $contrib = null;
            if ($reportFinishId > 0) {
                $contrib = $pdo->prepare("INSERT IGNORE INTO `jawha_po_item_report`
                    (`po_id`,`report_finish_id`,`part_name`,`prod_date`,`tool`,`cavity`,`tool_cavity`,`seen_at`)
                    VALUES (:po,:rf,:part,:pd,:tool,:cav,:tc,NOW())");
            }

            $sql = "INSERT INTO `jawha_po_item`
                (`po_id`,`part_name`,`prod_date`,`tool`,`cavity`,`tool_cavity`,`first_report_finish_id`,`last_report_finish_id`,`first_seen_at`,`last_seen_at`,`seen_count`)
                VALUES (:po,:part,:pd,:tool,:cav,:tc,:rf1,:rf2,NOW(),NOW(),1)
                ON DUPLICATE KEY UPDATE
                  `last_report_finish_id`=VALUES(`last_report_finish_id`),
                  `last_seen_at`=NOW(),
                  `seen_count`=`seen_count`+1";
            $st = $pdo->prepare($sql);
            $cnt = 0;

            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $part = trim((string)($item['part_name'] ?? ''));
                $pd = trim((string)($item['prod_date'] ?? ''));
                $parsed = jawha_po_parse_tool_cavity((string)($item['tool_cavity'] ?? ''));
                if ($part === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd) || !$parsed) continue;

                // report_finish_id가 있으면 같은 발행 건의 재호출은 누적 카운트를 중복 증가시키지 않는다.
                if ($contrib !== null) {
                    $contrib->execute([
                        ':po' => $poId,
                        ':rf' => $reportFinishId,
                        ':part' => $part,
                        ':pd' => $pd,
                        ':tool' => $parsed['tool'],
                        ':cav' => $parsed['cavity'],
                        ':tc' => $parsed['tool_cavity'],
                    ]);
                    if ($contrib->rowCount() <= 0) continue;
                }

                $rf = $reportFinishId > 0 ? $reportFinishId : null;
                $st->execute([
                    ':po' => $poId,
                    ':part' => $part,
                    ':pd' => $pd,
                    ':tool' => $parsed['tool'],
                    ':cav' => $parsed['cavity'],
                    ':tc' => $parsed['tool_cavity'],
                    ':rf1' => $rf,
                    ':rf2' => $rf,
                ]);
                $cnt++;
            }

            if ($ownTx) $pdo->commit();
            return $cnt;
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('jawha_po_detach_report')) {
    /**
     * 취소된 출하성적서 한 건의 PO 기여분만 제거한다.
     * 같은 생산일/Tool#Cavity가 다른 정상 발행 건에도 있으면 jawha_po_item에는 계속 남는다.
     */
    function jawha_po_detach_report(PDO $pdo, int $reportFinishId): array {
        if ($reportFinishId <= 0) return ['ok' => true, 'detached' => 0, 'po_id' => 0];
        jawha_po_ensure_tables($pdo);

        $find = $pdo->prepare("SELECT `po_id` FROM `jawha_po_report` WHERE `report_finish_id`=:rf LIMIT 1");
        $find->execute([':rf' => $reportFinishId]);
        $poId = (int)($find->fetchColumn() ?: 0);
        if ($poId <= 0) return ['ok' => true, 'detached' => 0, 'po_id' => 0];

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare("SELECT `part_name`,`prod_date`,`tool`,`cavity`,`tool_cavity`
                FROM `jawha_po_item_report`
                WHERE `po_id`=:po AND `report_finish_id`=:rf");
            $sel->execute([':po' => $poId, ':rf' => $reportFinishId]);
            $affected = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $delContrib = $pdo->prepare("DELETE FROM `jawha_po_item_report` WHERE `po_id`=:po AND `report_finish_id`=:rf");
            $delContrib->execute([':po' => $poId, ':rf' => $reportFinishId]);

            $remain = $pdo->prepare("SELECT COUNT(*) AS c, MIN(`report_finish_id`) AS first_rf, MAX(`report_finish_id`) AS last_rf,
                    MIN(`seen_at`) AS first_seen, MAX(`seen_at`) AS last_seen
                FROM `jawha_po_item_report`
                WHERE `po_id`=:po AND `part_name`=:part AND `prod_date`=:pd AND `tool`=:tool AND `cavity`=:cav");
            $upd = $pdo->prepare("UPDATE `jawha_po_item`
                SET `first_report_finish_id`=:first_rf, `last_report_finish_id`=:last_rf,
                    `first_seen_at`=:first_seen, `last_seen_at`=:last_seen, `seen_count`=:cnt
                WHERE `po_id`=:po AND `part_name`=:part AND `prod_date`=:pd AND `tool`=:tool AND `cavity`=:cav LIMIT 1");
            $delItem = $pdo->prepare("DELETE FROM `jawha_po_item`
                WHERE `po_id`=:po AND `part_name`=:part AND `prod_date`=:pd AND `tool`=:tool AND `cavity`=:cav LIMIT 1");

            $detached = 0;
            foreach ($affected as $row) {
                $key = [
                    ':po' => $poId,
                    ':part' => trim((string)$row['part_name']),
                    ':pd' => (string)$row['prod_date'],
                    ':tool' => trim((string)$row['tool']),
                    ':cav' => (int)$row['cavity'],
                ];
                $remain->execute($key);
                $agg = $remain->fetch(PDO::FETCH_ASSOC) ?: [];
                $cnt = (int)($agg['c'] ?? 0);
                if ($cnt <= 0) {
                    $delItem->execute($key);
                } else {
                    $upd->execute(array_merge($key, [
                        ':first_rf' => (int)($agg['first_rf'] ?? 0),
                        ':last_rf' => (int)($agg['last_rf'] ?? 0),
                        ':first_seen' => (string)($agg['first_seen'] ?? date('Y-m-d H:i:s')),
                        ':last_seen' => (string)($agg['last_seen'] ?? date('Y-m-d H:i:s')),
                        ':cnt' => $cnt,
                    ]));
                }
                $detached++;
            }

            // v2에서 이미 생성된 PO 데이터처럼 발행별 연결표가 없는 단일 이력은 안전하게 제거한다.
            if (!$affected) {
                $legacy = $pdo->prepare("DELETE FROM `jawha_po_item`
                    WHERE `po_id`=:po AND `seen_count`<=1
                      AND `first_report_finish_id`=:rf AND `last_report_finish_id`=:rf");
                $legacy->execute([':po' => $poId, ':rf' => $reportFinishId]);
                $detached += $legacy->rowCount();
            }

            $delReport = $pdo->prepare("DELETE FROM `jawha_po_report` WHERE `po_id`=:po AND `report_finish_id`=:rf");
            $delReport->execute([':po' => $poId, ':rf' => $reportFinishId]);

            $rfAgg = $pdo->prepare("SELECT MIN(`report_finish_id`) AS first_rf, MAX(`report_finish_id`) AS last_rf FROM `jawha_po_report` WHERE `po_id`=:po");
            $rfAgg->execute([':po' => $poId]);
            $rr = $rfAgg->fetch(PDO::FETCH_ASSOC) ?: [];
            $firstRf = !empty($rr['first_rf']) ? (int)$rr['first_rf'] : null;
            $lastRf = !empty($rr['last_rf']) ? (int)$rr['last_rf'] : null;
            $poUpd = $pdo->prepare("UPDATE `jawha_po` SET `start_report_finish_id`=:first_rf, `last_report_finish_id`=:last_rf WHERE `id`=:po LIMIT 1");
            $poUpd->bindValue(':first_rf', $firstRf, $firstRf === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $poUpd->bindValue(':last_rf', $lastRf, $lastRf === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $poUpd->bindValue(':po', $poId, PDO::PARAM_INT);
            $poUpd->execute();

            if ($ownTx) $pdo->commit();
            return ['ok' => true, 'detached' => $detached, 'po_id' => $poId];
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'detached' => 0, 'po_id' => $poId, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('jawha_po_history_for_part')) {
    function jawha_po_history_for_part(PDO $pdo, int $poId, string $partName): array {
        if ($poId <= 0 || trim($partName) === '') return [];
        jawha_po_ensure_tables($pdo);
        $st = $pdo->prepare("SELECT `part_name`,`prod_date`,`tool`,`cavity`,`tool_cavity`,`first_seen_at`,`last_seen_at`,`seen_count` FROM `jawha_po_item` WHERE `po_id`=:po AND `part_name`=:part ORDER BY `prod_date` DESC, `id` DESC");
        $st->execute([':po' => $poId, ':part' => trim($partName)]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('jawha_po_report_state_map')) {
    function jawha_po_report_state_map(PDO $pdo, array $reportIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $reportIds), fn($v) => $v > 0)));
        if (!$ids) return [];
        jawha_po_ensure_tables($pdo);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT r.`report_finish_id`, p.`id` AS `po_id`, p.`po_sn`, p.`status`, p.`last_report_finish_id`
                FROM `jawha_po_report` r
                INNER JOIN `jawha_po` p ON p.`id`=r.`po_id`
                WHERE r.`report_finish_id` IN ({$ph})";
        $st = $pdo->prepare($sql);
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['report_finish_id']] = $row;
        }
        return $out;
    }
}
