<?php
if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function oqc_holding_parse_rows(string $raw): array {
    $rows = [];
    $lines = preg_split('/\r\n|\r|\n/', trim($raw));
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $cols = array_map('trim', explode('|', $line));
        $cols = array_pad($cols, 10, '');
        [$lot, $tool, $cav, $frontFai, $frontLimit, $frontMeasured, $backFai, $backLimit, $backMeasured, $status] = $cols;

        $rows[] = [
            'lot' => $lot,
            'tool' => $tool,
            'cav' => $cav,
            'front_fai' => $frontFai,
            'front_limit' => $frontLimit,
            'front_measured' => $frontMeasured,
            'back_fai' => $backFai,
            'back_limit' => $backLimit,
            'back_measured' => $backMeasured,
            'status' => $status,
        ];
    }
    return $rows;
}

$holdingTabs = [
    'MEM-IR-BASE' => [
        'label' => 'MEM-IR-BASE',
        'product_name' => 'IR BASE',
        'rows' => oqc_holding_parse_rows(<<<'TXT'
01월 02일||||||||OK
01월 03일||||||||OK
01월 04일|E|4|117-V2|0.0080|0.0086||||
01월 05일||||||||OK
01월 06일|F|3|82-C2|4.5910|4.5971||||
01월 06일|M|3|117-V2|0.0080|0.0112||||
01월 06일|M|4|117-V2|0.0080|0.0096||||
01월 07일||||||||OK
01월 08일||||||||OK
01월 09일||||||||OK
01월 10일||||||||OK
01월 11일||||||||OK
01월 12일|D|3||||20-P1|3.9080|3.9031|
01월 12일|H|3||||20-P1|3.9080|3.9062|
01월 13일||||||||OK
01월 14일||||||||OK
01월 15일||||||||OK
01월 16일||||||||OK
01월 17일||||||||OK
01월 18일||||||||OK
01월 19일||||||||OK
01월 20일||||||||OK
01월 21일||||||||OK
01월 22일||||||||OK
01월 23일||||||||OK
01월 24일||||||||OK
01월 25일||||||||OK
01월 26일||||||||OK
01월 27일|C|1||||69-P1|3.2480|3.2407|
01월 28일||||||||OK
01월 29일||||||||OK
01월 30일||||||||OK
01월 31일|C|1||||117-V1|-0.0080|-0.0115|
01월 31일|E|1||||158|18.573|18.571|
02월 01일||||||||OK
02월 02일||||||||OK
02월 03일||||||||OK
02월 04일||||||||OK
02월 05일||||||||OK
02월 06일||||||||OK
02월 07일||||||||OK
02월 08일|J|1|1|0.0400|0.0432||||
02월 08일|K|1|1|0.0400|0.0439||||
02월 08일|J|2||||117-V2|-0.0080|-0.0088|
02월 09일||||||||OK
02월 10일||||||||OK
02월 11일|F|4|73-P1|19.9820|19.9937||||
02월 11일|F|3|73-P1|19.9820|19.9899||||
02월 12일|K|1|123|0.0060|0.0084||||
02월 12일|K|2|123|0.0060|0.0082||||
02월 13일||||||||OK
02월 14일||||||||OK
02월 15일||||||||비가동
02월 16일|M|4|71-P2|15.4640|15.4670||||
02월 17일|F|1|123|0.0060|0.0074||||
02월 18일||||||||OK
02월 19일||||||||OK
02월 20일||||||||OK
02월 21일||||||||OK
02월 22일||||||||OK
02월 23일|K|1|1|0.0400|0.0425||||
02월 23일|L|1|1|0.0400|0.0421||||
02월 24일||||||||OK
02월 25일|A|1|123|0.0060|0.0078||||
02월 25일|L|1|1|0.0400|0.0427||||
02월 25일|M|2|1|0.0400|0.0603||||
02월 26일||||||||OK
02월 27일||||||||OK
02월 28일||||||||OK
03월 01일||||||||OK
03월 02일||||||||OK
03월 03일||||||||OK
03월 04일|M|3|117-V2|0.0080|0.0093||||
03월 05일|M|2|117-V2|0.0080|0.0095||||
03월 05일|F|2|117-V2|0.0080|0.0081||||
03월 06일||||||||OK
03월 07일|L|1|1|0.0400|0.0461||||
03월 07일|L|2|1|0.0400|0.0427||||
03월 07일|M|2|117-V2|0.0080|0.0093||||
03월 08일|F|3|117-V2|0.0080|0.0105||||
03월 09일||||||||OK
03월 10일||||||||OK
03월 11일||||||||OK
03월 12일|D|3||||123|-0.0100|-0.0107|
03월 13일||||||||OK
03월 14일|F|3|1|0.0400|0.0440||||
03월 14일|G|3||||117-V1|-0.0080|-0.0097|
03월 15일||||||||OK
03월 16일|G|3||||117-V1|-0.0080|-0.0090|
03월 17일||||||||OK
03월 18일||||||||OK
03월 19일|M|1|1|0.0400|0.0476||||
03월 20일||||||||OK
03월 21일|C|1||||117-V2|-0.0080|-0.0097|
03월 21일|H|2||||117-V2|-0.0080|-0.0091|
03월 22일||||||||OK
03월 23일|K|2|137|0.0500|0.0561||||
03월 23일|B|2||||117-V2|-0.0080|-0.0102|
03월 23일|H|2||||117-V2|-0.0080|-0.0088|
03월 24일||||||||OK
03월 25일|J|2|1|0.0400|0.0400||||
TXT
        ),
    ],
    'MEM-X-CARRIER' => [
        'label' => 'MEM-X-CARRIER',
        'product_name' => 'X CARRIER',
        'rows' => oqc_holding_parse_rows(<<<'TXT'
01월 02일||||||||OK
01월 03일||||||||OK
01월 04일||||||||비가동
01월 05일||||||||OK
01월 06일||||||||OK
01월 07일||||||||OK
01월 08일||||||||OK
01월 09일||||||||OK
01월 10일||||||||OK
01월 11일||||||||OK
01월 12일||||||||OK
01월 13일||||||||OK
01월 14일||||||||OK
01월 15일||||||||OK
01월 16일||||||||OK
01월 17일||||||||OK
01월 18일||||||||비가동
01월 19일||||||||OK
01월 20일||||||||OK
01월 21일||||||||OK
01월 22일||||||||OK
01월 23일||||||||OK
01월 24일||||||||OK
01월 25일||||||||비가동
01월 26일||||||||OK
TXT
        ),
    ],
    'MEM-Y-CARRIER' => [
        'label' => 'MEM-Y-CARRIER',
        'product_name' => 'Y CARRIER',
        'rows' => oqc_holding_parse_rows(<<<'TXT'
01월 02일||||||||OK
01월 03일||||||||OK
01월 04일||||||||OK
01월 05일||||||||OK
01월 06일||||||||OK
01월 07일||||||||OK
01월 08일||||||||OK
01월 09일||||||||OK
01월 10일||||||||OK
01월 11일||||||||OK
01월 12일||||||||OK
01월 13일||||||||OK
01월 14일||||||||OK
01월 15일||||||||OK
01월 16일||||||||OK
01월 17일||||||||OK
01월 18일||||||||비가동
01월 19일||||||||OK
01월 20일||||||||OK
01월 21일||||||||OK
01월 22일||||||||OK
01월 23일||||||||OK
01월 24일||||||||OK
01월 25일||||||||OK
01월 26일||||||||OK
TXT
        ),
    ],
    'MEM-Z-CARRIER' => [
        'label' => 'MEM-Z-CARRIER',
        'product_name' => 'Z CARRIER',
        'rows' => oqc_holding_parse_rows(<<<'TXT'
01월 02일||||||||OK
01월 03일||||||||OK
01월 04일||||||||OK
01월 05일||||||||OK
01월 06일||||||||OK
01월 07일|B|3||||103-V2|-0.0050|-0.0066|
01월 07일|E|4|109-V4|0.0050|0.0074||||
01월 08일||||||||OK
01월 09일|B|3||||103-V2|-0.0050|-0.0062|
01월 10일||||||||OK
01월 11일||||||||OK
01월 12일||||||||OK
01월 13일||||||||OK
01월 14일||||||||OK
01월 15일||||||||OK
01월 16일||||||||OK
01월 17일||||||||OK
01월 18일||||||||OK
01월 19일||||||||OK
01월 20일||||||||OK
01월 21일|Q|4||||114-1|15.2820|15.2789|
01월 22일||||||||OK
01월 23일|H|4|114-1|15.3220|15.3230||||
01월 24일||||||||OK
01월 25일|H|4|114-1|15.3220|15.3235||||
TXT
        ),
    ],
    'MEM-Z-STOPPER' => [
        'label' => 'MEM-Z-STOPPER',
        'product_name' => 'Z STOPPER',
        'rows' => oqc_holding_parse_rows(<<<'TXT'
01월 02일||||||||OK
01월 03일||||||||OK
01월 04일||||||||비가동
01월 05일||||||||OK
01월 06일||||||||OK
01월 07일||||||||OK
01월 08일||||||||OK
01월 09일||||||||OK
01월 10일||||||||OK
01월 11일||||||||OK
01월 12일||||||||OK
01월 13일||||||||OK
01월 14일||||||||OK
01월 15일||||||||OK
01월 16일||||||||OK
01월 17일||||||||OK
01월 18일||||||||비가동
01월 19일||||||||OK
01월 20일||||||||OK
01월 21일||||||||OK
01월 22일||||||||OK
01월 23일||||||||OK
01월 24일||||||||OK
01월 25일||||||||비가동
01월 26일||||||||OK
TXT
        ),
    ],
];

$defaultTab = 'MEM-IR-BASE';
$currentTab = isset($_GET['tab'], $holdingTabs[$_GET['tab']]) ? $_GET['tab'] : $defaultTab;
?>
<style>
.oqc-holding-page {
    max-width: 1080px;
    margin: 14px auto 42px;
    padding: 0 10px;
    color: #f3f3f3;
}
.oqc-holding-title {
    margin: 0 0 8px;
    font-size: 31px;
    line-height: 1.15;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: #f4f4f4;
    text-shadow: 0 1px 0 rgba(0,0,0,.35);
}
.oqc-holding-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    align-items: flex-end;
    margin: 0 0 -1px;
    padding: 0 10px;
    position: relative;
    z-index: 3;
}
.oqc-holding-tab {
    appearance: none;
    border: 1px solid rgba(119, 135, 164, .56);
    border-bottom: 0;
    border-radius: 8px 8px 0 0;
    padding: 8px 15px 7px;
    min-height: 35px;
    background: linear-gradient(180deg, rgba(79, 88, 103, .92) 0%, rgba(40, 47, 60, .94) 100%);
    color: #eff3f8;
    font-size: 13px;
    font-weight: 800;
    line-height: 1.1;
    letter-spacing: -.01em;
    cursor: pointer;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.12);
    position: relative;
    top: 0;
    transition: filter .12s ease, transform .12s ease;
}
.oqc-holding-tab:hover {
    filter: brightness(1.08);
}
.oqc-holding-tab.is-active {
    background: linear-gradient(180deg, #34a85d 0%, #1f7a3b 100%);
    border-color: #5ad281;
    color: #f6fff8;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.2), 0 0 0 1px rgba(13, 61, 28, .22);
}
.oqc-holding-window {
    position: relative;
    background: #2b2b2b;
    border: 1px solid rgba(91, 102, 117, .55);
    border-radius: 14px;
    padding: 10px;
    box-shadow: 0 18px 40px rgba(0,0,0,.34);
}
.oqc-holding-sheet-shell {
    border-radius: 10px;
    border: 1px solid rgba(110, 120, 134, .38);
    background: linear-gradient(180deg, rgba(255,255,255,.035), rgba(255,255,255,.015));
    padding: 12px;
}
.oqc-holding-sheet-wrap {
    overflow: auto;
    border-radius: 8px;
    border: 1px solid #a9a9a9;
    background: #ffffff;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.85);
}
.oqc-holding-sheet {
    width: 100%;
    min-width: 910px;
    border-collapse: collapse;
    table-layout: fixed;
    background: #fff;
    color: #111;
    font-size: 14px;
}
.oqc-holding-sheet col.col-product { width: 92px; }
.oqc-holding-sheet col.col-lot { width: 102px; }
.oqc-holding-sheet col.col-tool { width: 56px; }
.oqc-holding-sheet col.col-cav { width: 48px; }
.oqc-holding-sheet col.col-oqc { width: 96px; }
.oqc-holding-sheet col.col-isolation { width: 72px; }
.oqc-holding-sheet th,
.oqc-holding-sheet td {
    border: 1px solid #333;
    padding: 0 6px;
    height: 27px;
    text-align: center;
    vertical-align: middle;
    font-weight: 500;
}
.oqc-holding-sheet thead th {
    background: #dfdfdf;
    color: #111;
    font-weight: 700;
}
.oqc-holding-sheet thead th.oqc-group {
    font-size: 15px;
    letter-spacing: -.01em;
}
.oqc-holding-sheet tbody td.product,
.oqc-holding-sheet tbody td.lot,
.oqc-holding-sheet tbody td.tool,
.oqc-holding-sheet tbody td.cav,
.oqc-holding-sheet tbody td.isolation {
    background: #fff;
}
.oqc-holding-sheet tbody td.merged-ok {
    background: #c7e7c8;
    color: #22862f;
    font-weight: 700;
}
.oqc-holding-sheet tbody td.merged-off {
    background: #f1efef;
    color: #222;
    font-weight: 600;
}
.oqc-holding-sheet tbody td.ng-front,
.oqc-holding-sheet tbody td.ng-back {
    background: #f6edeb;
    color: #1d1d1d;
}
.oqc-holding-sheet tbody td.ng-empty {
    background: #fff;
}
.oqc-holding-sheet tbody td.blank {
    color: transparent;
}
.oqc-holding-panel {
    display: none;
}
.oqc-holding-panel.is-active {
    display: block;
}
@media (max-width: 980px) {
    .oqc-holding-page {
        padding: 0 8px;
    }
    .oqc-holding-title {
        font-size: 28px;
    }
    .oqc-holding-tab {
        font-size: 12px;
        padding: 7px 12px 6px;
        min-height: 33px;
    }
}
</style>

<div class="oqc-holding-page">
    <h1 class="oqc-holding-title">OQC 홀딩리스트</h1>

    <div class="oqc-holding-tabs" role="tablist" aria-label="OQC 홀딩리스트 모델 탭">
        <?php foreach ($holdingTabs as $tabKey => $tab): ?>
            <button
                type="button"
                class="oqc-holding-tab<?= $tabKey === $currentTab ? ' is-active' : '' ?>"
                data-tab="<?= h($tabKey) ?>"
                role="tab"
                aria-selected="<?= $tabKey === $currentTab ? 'true' : 'false' ?>"
            ><?= h($tab['label']) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="oqc-holding-window">
        <div class="oqc-holding-sheet-shell">
            <?php foreach ($holdingTabs as $tabKey => $tab): ?>
                <div class="oqc-holding-panel<?= $tabKey === $currentTab ? ' is-active' : '' ?>" data-panel="<?= h($tabKey) ?>">
                    <div class="oqc-holding-sheet-wrap">
                        <table class="oqc-holding-sheet" aria-label="<?= h($tab['label']) ?> 엑셀형 홀딩리스트 뷰어">
                            <colgroup>
                                <col class="col-product">
                                <col class="col-lot">
                                <col class="col-tool">
                                <col class="col-cav">
                                <col class="col-oqc">
                                <col class="col-oqc">
                                <col class="col-oqc">
                                <col class="col-oqc">
                                <col class="col-oqc">
                                <col class="col-oqc">
                                <col class="col-isolation">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th rowspan="2">제품명</th>
                                    <th rowspan="2">Lot</th>
                                    <th rowspan="2">Tool</th>
                                    <th rowspan="2">Cav</th>
                                    <th colspan="6" class="oqc-group">OQC</th>
                                    <th rowspan="2">격리<br>수량</th>
                                </tr>
                                <tr>
                                    <th>FAI</th>
                                    <th>USL</th>
                                    <th>측정값</th>
                                    <th>FAI</th>
                                    <th>LSL</th>
                                    <th>측정값</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tab['rows'] as $row): ?>
                                    <tr>
                                        <td class="product"><?= h($tab['product_name']) ?></td>
                                        <td class="lot"><?= h($row['lot']) ?></td>
                                        <td class="tool"><?= h($row['tool']) ?></td>
                                        <td class="cav"><?= h($row['cav']) ?></td>

                                        <?php if ($row['status'] === 'OK'): ?>
                                            <td colspan="6" class="merged-ok">OK</td>
                                        <?php elseif ($row['status'] === '비가동'): ?>
                                            <td colspan="6" class="merged-off">비가동</td>
                                        <?php else: ?>
                                            <?php
                                                $hasFront = ($row['front_fai'] !== '' || $row['front_limit'] !== '' || $row['front_measured'] !== '');
                                                $hasBack  = ($row['back_fai'] !== '' || $row['back_limit'] !== '' || $row['back_measured'] !== '');
                                            ?>
                                            <td class="<?= $hasFront ? 'ng-front' : 'ng-empty' ?>"><?= h($row['front_fai']) ?></td>
                                            <td class="<?= $hasFront ? 'ng-front' : 'ng-empty' ?>"><?= h($row['front_limit']) ?></td>
                                            <td class="<?= $hasFront ? 'ng-front' : 'ng-empty' ?>"><?= h($row['front_measured']) ?></td>
                                            <td class="<?= $hasBack ? 'ng-back' : 'ng-empty' ?>"><?= h($row['back_fai']) ?></td>
                                            <td class="<?= $hasBack ? 'ng-back' : 'ng-empty' ?>"><?= h($row['back_limit']) ?></td>
                                            <td class="<?= $hasBack ? 'ng-back' : 'ng-empty' ?>"><?= h($row['back_measured']) ?></td>
                                        <?php endif; ?>

                                        <td class="isolation"></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
(function () {
    const tabs = Array.from(document.querySelectorAll('.oqc-holding-tab'));
    const panels = Array.from(document.querySelectorAll('.oqc-holding-panel'));
    if (!tabs.length || !panels.length) return;

    function activate(tabKey) {
        tabs.forEach((btn) => {
            const active = btn.dataset.tab === tabKey;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach((panel) => {
            panel.classList.toggle('is-active', panel.dataset.panel === tabKey);
        });
    }

    tabs.forEach((btn) => {
        btn.addEventListener('click', () => activate(btn.dataset.tab));
    });
})();
</script>
