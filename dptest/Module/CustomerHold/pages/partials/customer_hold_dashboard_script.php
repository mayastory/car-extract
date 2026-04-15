(function(){
  var dashboardSelectedKey = '';
  var dashboardLastSignature = '';
  var dashboardBound = false;

  function normalizeText(value){
    return String(value == null ? '' : value)
      .replace(/\u00a0/g, ' ')
      .replace(/\s+/g, ' ')
      .replace(/[▼▲▾▴]/g, '')
      .trim();
  }

  function hasOwn(obj, key){
    return Object.prototype.hasOwnProperty.call(obj, key);
  }

  function getRoot(id){
    return document.getElementById(id);
  }

  function getPanel(){
    return getRoot('customerHoldDashboardPanel');
  }

  function findStateCandidate(){
    var candidates = [
      window.state,
      window.customerHoldState,
      window.CH_STATE,
      window.__CH_STATE__,
      window.customerHoldBoot,
      window.__CUSTOMER_HOLD_BOOT__,
      window.boot
    ].filter(Boolean);

    for (var i = 0; i < candidates.length; i++) {
      var obj = candidates[i];
      if (obj && typeof obj === 'object') return obj;
    }
    return null;
  }

  function getRowsFromState(kind){
    var stateObj = findStateCandidate();
    if (!stateObj) return null;
    var keys = kind === 'tool' ? [
      'toolRows','tool_rows','toolStatusRows','toolStatus','tool_status','toolStatusData'
    ] : [
      'releaseRows','release_rows','releaseDetailRows','releaseDetails','release_details','releaseDetailData'
    ];

    for (var i = 0; i < keys.length; i++) {
      if (Array.isArray(stateObj[keys[i]])) return stateObj[keys[i]];
    }
    if (stateObj.boot && typeof stateObj.boot === 'object') {
      for (var j = 0; j < keys.length; j++) {
        if (Array.isArray(stateObj.boot[keys[j]])) return stateObj.boot[keys[j]];
      }
    }
    return null;
  }

  function tableHeaders(table){
    return Array.prototype.map.call(table.querySelectorAll('thead th'), function(th){
      return normalizeText(th.textContent);
    });
  }

  function findTableByHeaders(required){
    var tables = document.querySelectorAll('table');
    for (var i = 0; i < tables.length; i++) {
      var headers = tableHeaders(tables[i]);
      var ok = required.every(function(text){
        return headers.indexOf(text) !== -1;
      });
      if (ok) return tables[i];
    }
    return null;
  }

  function cellText(cell){
    if (!cell) return '';
    var editor = cell.querySelector('textarea, input, select');
    if (editor) {
      if (editor.tagName === 'SELECT') {
        var opt = editor.options[editor.selectedIndex];
        return normalizeText(opt ? opt.textContent : editor.value);
      }
      return normalizeText(editor.value);
    }
    return normalizeText(cell.textContent);
  }

  function parseTable(table){
    var headers = tableHeaders(table);
    var rows = [];
    var activeSpans = {};
    var bodyRows = table.querySelectorAll('tbody tr');

    bodyRows.forEach(function(tr){
      var logical = new Array(headers.length).fill('');
      Object.keys(activeSpans).forEach(function(key){
        var idx = parseInt(key, 10);
        if (!activeSpans[idx]) return;
        logical[idx] = activeSpans[idx].value;
        activeSpans[idx].remaining -= 1;
        if (activeSpans[idx].remaining <= 0) delete activeSpans[idx];
      });

      var cursor = 0;
      Array.prototype.forEach.call(tr.children, function(cell){
        while (cursor < headers.length && logical[cursor] !== '') cursor += 1;
        if (cursor >= headers.length) return;
        var value = cellText(cell);
        logical[cursor] = value;
        var span = parseInt(cell.getAttribute('rowspan') || '1', 10);
        if (span > 1) {
          activeSpans[cursor] = {
            value: value,
            remaining: span - 1
          };
        }
        cursor += 1;
      });

      rows.push(logical);
    });

    return { headers: headers, rows: rows };
  }

  function getRowsFromDom(){
    var toolTable = findTableByHeaders(['Item','Tool','Cavity','Affect Lot','Vendor','Type','Issue Description','Remark']);
    var releaseTable = findTableByHeaders(['Holding DATE','Vendor','Parts name','Tool','Cavity','Affect Lot','Type','Issue Description','Status','Release DATE','비고']);
    var result = { tool: [], release: [] };

    if (toolTable) {
      var parsedTool = parseTable(toolTable);
      var mapTool = {};
      parsedTool.headers.forEach(function(header, idx){ mapTool[header] = idx; });
      parsedTool.rows.forEach(function(row){
        result.tool.push({
          item_code: row[mapTool['Item']] || '',
          tool_text: row[mapTool['Tool']] || '',
          cavity_text: row[mapTool['Cavity']] || '',
          affect_lot_text: row[mapTool['Affect Lot']] || '',
          vendor_text: row[mapTool['Vendor']] || '',
          type_text: row[mapTool['Type']] || '',
          issue_description_text: row[mapTool['Issue Description']] || '',
          remark_text: row[mapTool['Remark']] || ''
        });
      });
    }

    if (releaseTable) {
      var parsedRelease = parseTable(releaseTable);
      var mapRelease = {};
      parsedRelease.headers.forEach(function(header, idx){ mapRelease[header] = idx; });
      parsedRelease.rows.forEach(function(row){
        result.release.push({
          holding_date_text: row[mapRelease['Holding DATE']] || '',
          vendor_text: row[mapRelease['Vendor']] || '',
          parts_name_text: row[mapRelease['Parts name']] || '',
          tool_text: row[mapRelease['Tool']] || '',
          cavity_text: row[mapRelease['Cavity']] || '',
          affect_lot_text: row[mapRelease['Affect Lot']] || '',
          type_text: row[mapRelease['Type']] || '',
          issue_description_text: row[mapRelease['Issue Description']] || '',
          status_text: row[mapRelease['Status']] || '',
          release_date_text: row[mapRelease['Release DATE']] || '',
          note_text: row[mapRelease['비고']] || ''
        });
      });
    }

    return result;
  }

  function getToolRows(){
    var stateRows = getRowsFromState('tool');
    if (Array.isArray(stateRows) && stateRows.length) return stateRows;
    return getRowsFromDom().tool;
  }

  function getReleaseRows(){
    var stateRows = getRowsFromState('release');
    if (Array.isArray(stateRows) && stateRows.length) return stateRows;
    return getRowsFromDom().release;
  }

  function vendorGroup(text){
    var value = normalizeText(text).toUpperCase();
    if (!value) return '';
    if (value.indexOf('LG') !== -1) return 'LG';
    if (normalizeText(text).indexOf('자화') !== -1) return '자화';
    return '';
  }

  function splitCavity(text){
    var value = normalizeText(text).toUpperCase();
    if (!value) return [];
    if (value === 'ALL') return ['ALL'];
    return value
      .replace(/,/g, '/')
      .split('/')
      .map(function(part){ return normalizeText(part); })
      .filter(Boolean);
  }

  function cavityMatch(toolValue, releaseValue){
    var toolParts = splitCavity(toolValue);
    var releaseParts = splitCavity(releaseValue);
    if (!toolParts.length || !releaseParts.length) return true;
    if (toolParts.indexOf('ALL') !== -1 || releaseParts.indexOf('ALL') !== -1) return true;
    return toolParts.some(function(part){ return releaseParts.indexOf(part) !== -1; });
  }

  function meaningfulToolHold(row){
    return [
      row.vendor_text,
      row.type_text,
      row.issue_description_text,
      row.remark_text,
      row.affect_lot_text
    ].some(function(value){ return normalizeText(value) !== ''; });
  }

  function entryModel(row){
    var item = normalizeText(row.item_code || row.parts_name_text || '');
    return item || '기타';
  }

  function entryKey(row){
    return [
      entryModel(row),
      normalizeText(row.tool_text),
      normalizeText(row.cavity_text),
      normalizeText(row.vendor_text),
      normalizeText(row.type_text),
      normalizeText(row.issue_description_text)
    ].join('||');
  }

  function entryTitle(row){
    var tool = normalizeText(row.tool_text || '-') || '-';
    var cavity = normalizeText(row.cavity_text || '');
    return cavity ? (tool + ' / ' + cavity) : tool;
  }

  function logsForRow(row, releaseRows){
    return releaseRows.filter(function(log){
      if (normalizeText(log.tool_text) !== normalizeText(row.tool_text)) return false;
      var modelA = entryModel(row);
      var modelB = entryModel({ item_code: log.parts_name_text });
      if (modelB && modelA && modelA !== modelB) return false;
      if (!cavityMatch(row.cavity_text, log.cavity_text)) return false;
      var groupA = vendorGroup(row.vendor_text);
      var groupB = vendorGroup(log.vendor_text);
      if (groupA && groupB && groupA !== groupB) return false;
      return true;
    });
  }

  function computeEntryStatus(row, logs){
    var latest = logs.length ? logs[logs.length - 1] : null;
    var latestStatus = normalizeText(latest && latest.status_text).toLowerCase();

    if (latestStatus === 'ongoing') return 'blocked';
    if (meaningfulToolHold(row)) return 'blocked';
    return 'available';
  }

  function create(tag, className, text){
    var el = document.createElement(tag);
    if (className) el.className = className;
    if (typeof text === 'string') el.textContent = text;
    return el;
  }

  function dashboardData(){
    var toolRows = getToolRows().filter(function(row){
      return normalizeText(row.tool_text) !== '';
    });
    var releaseRows = getReleaseRows();
    var entries = toolRows.map(function(row){
      var logs = logsForRow(row, releaseRows);
      var status = computeEntryStatus(row, logs);
      return {
        key: entryKey(row),
        model: entryModel(row),
        title: entryTitle(row),
        vendorGroup: vendorGroup(row.vendor_text),
        status: status,
        row: row,
        logs: logs
      };
    });
    return { entries: entries, releaseRows: releaseRows };
  }

  function renderSummary(entries, filtered){
    var root = getRoot('dashboardSummary');
    if (!root) return;
    root.innerHTML = '';

    var blocked = filtered.filter(function(entry){ return entry.status === 'blocked'; }).length;
    var available = filtered.filter(function(entry){ return entry.status === 'available'; }).length;
    var cards = [
      { title: '전체', value: String(entries.length), sub: '현황판 전체 건수' },
      { title: '표시', value: String(filtered.length), sub: '필터 적용 후 건수' },
      { title: '출하불가', value: String(blocked), sub: '현재 홀딩 또는 진행중' },
      { title: '출하가능', value: String(available), sub: '근거 없음 또는 해제 완료' }
    ];

    cards.forEach(function(card){
      var el = create('div', 'dashboard-summary-card');
      el.appendChild(create('h3', '', card.title));
      el.appendChild(create('div', 'dashboard-summary-value', card.value));
      el.appendChild(create('div', 'dashboard-summary-sub', card.sub));
      root.appendChild(el);
    });
  }

  function renderLogPanel(entry){
    var panel = getRoot('dashboardLogPanel');
    if (!panel) return;

    panel.innerHTML = '';
    var head = create('div', 'dashboard-log-head');
    head.appendChild(create('div', '', entry ? (entry.model + ' · ' + entry.title) : '관련 로그'));
    var pill = create('span', 'dashboard-pill status-' + (entry ? entry.status : 'available'), entry ? (entry.status === 'blocked' ? '출하불가' : '출하가능') : '-');
    head.appendChild(pill);
    panel.appendChild(head);

    var body = create('div', 'dashboard-log-body');
    if (!entry) {
      body.appendChild(create('div', 'dashboard-log-empty', '선택된 항목이 없습니다.'));
      panel.appendChild(body);
      return;
    }

    if (!entry.logs.length) {
      body.appendChild(create('div', 'dashboard-log-empty', '관련 홀딩 세부내역 로그가 없습니다.'));
      panel.appendChild(body);
      return;
    }

    var list = create('div', 'dashboard-log-list');
    entry.logs.forEach(function(log){
      var item = create('div', 'dashboard-log-item');
      var top = create('div', 'dashboard-log-item-top');
      top.appendChild(create('div', '', [
        normalizeText(log.holding_date_text || '-') || '-',
        normalizeText(log.release_date_text || '-') || '-'
      ].join(' → ')));
      var status = normalizeText(log.status_text || '') || '-';
      top.appendChild(create('div', '', status));
      item.appendChild(top);

      var note = normalizeText(log.note_text || '');
      var meta = [
        normalizeText(log.vendor_text || ''),
        normalizeText(log.type_text || ''),
        normalizeText(log.affect_lot_text || '')
      ].filter(Boolean).join(' · ');
      if (meta) item.appendChild(create('div', 'dashboard-log-note', meta));
      item.appendChild(create('div', 'dashboard-log-note', note || '비고 없음'));
      list.appendChild(item);
    });
    body.appendChild(list);
    panel.appendChild(body);
  }

  function renderBoard(entries, filtered){
    var root = getRoot('dashboardBoard');
    if (!root) return;
    root.innerHTML = '';

    if (!entries.length) {
      root.appendChild(create('div', 'dashboard-empty-state', 'Tool Status 기준 데이터가 없어 현황판을 표시할 수 없습니다.'));
      renderLogPanel(null);
      return;
    }

    if (!filtered.length) {
      root.appendChild(create('div', 'dashboard-empty-state', '현재 필터에 맞는 항목이 없습니다.'));
      renderLogPanel(null);
      return;
    }

    var groups = {};
    filtered.forEach(function(entry){
      if (!hasOwn(groups, entry.model)) groups[entry.model] = [];
      groups[entry.model].push(entry);
    });

    var selected = filtered.find(function(entry){ return entry.key === dashboardSelectedKey; }) || filtered[0];
    dashboardSelectedKey = selected ? selected.key : '';

    Object.keys(groups).forEach(function(model){
      var wrap = create('div', 'dashboard-model');
      var head = create('div', 'dashboard-model-head');
      head.appendChild(create('div', '', model));
      head.appendChild(create('div', 'dashboard-model-count', groups[model].length + '건'));
      wrap.appendChild(head);

      var grid = create('div', 'dashboard-grid');
      groups[model].forEach(function(entry){
        var card = create('div', 'dashboard-entry' + (entry.key === dashboardSelectedKey ? ' active' : ''));
        card.setAttribute('data-dashboard-key', entry.key);

        var top = create('div', 'dashboard-entry-top');
        var left = create('div');
        left.appendChild(create('h3', 'dashboard-entry-title', normalizeText(entry.row.tool_text || '-')));
        left.appendChild(create('div', 'dashboard-entry-meta', [
          normalizeText(entry.row.cavity_text || ''),
          normalizeText(entry.row.vendor_text || ''),
          normalizeText(entry.row.type_text || '')
        ].filter(Boolean).join(' · ')));
        top.appendChild(left);

        var btn = create('button', 'dashboard-status-btn status-' + entry.status, entry.status === 'blocked' ? '출하불가' : '출하가능');
        btn.type = 'button';
        btn.addEventListener('click', function(ev){
          ev.stopPropagation();
          dashboardSelectedKey = entry.key;
          renderDashboard();
        });
        top.appendChild(btn);
        card.appendChild(top);

        card.appendChild(create('div', 'dashboard-entry-issue', normalizeText(entry.row.issue_description_text || '') || '-'));
        card.addEventListener('click', function(){
          dashboardSelectedKey = entry.key;
          renderDashboard();
        });

        grid.appendChild(card);
      });

      wrap.appendChild(grid);
      root.appendChild(wrap);
    });

    renderLogPanel(selected || null);
  }

  function currentFilterValue(id, fallback){
    var el = getRoot(id);
    return el ? el.value : fallback;
  }

  function renderDashboard(){
    var panel = getPanel();
    if (!panel) return;

    var data = dashboardData();
    var vendorFilter = currentFilterValue('dashboardVendorFilter', 'ALL');
    var statusFilter = currentFilterValue('dashboardStatusFilter', 'ALL');

    var filtered = data.entries.filter(function(entry){
      if (vendorFilter !== 'ALL' && entry.vendorGroup !== vendorFilter) return false;
      if (statusFilter !== 'ALL' && entry.status !== statusFilter) return false;
      return true;
    });

    renderSummary(data.entries, filtered);
    renderBoard(data.entries, filtered);

    var badge = getRoot('dashboardCountBadge');
    if (badge) badge.textContent = '표시 ' + filtered.length + ' / 전체 ' + data.entries.length;

    dashboardLastSignature = JSON.stringify({
      vendorFilter: vendorFilter,
      statusFilter: statusFilter,
      keys: data.entries.map(function(entry){
        return [entry.key, entry.status, entry.logs.length].join('|');
      })
    });
  }

  function bindDashboard(){
    if (dashboardBound) return;
    dashboardBound = true;

    ['dashboardVendorFilter', 'dashboardStatusFilter'].forEach(function(id){
      var el = getRoot(id);
      if (!el) return;
      el.addEventListener('change', renderDashboard);
    });

    document.addEventListener('click', function(ev){
      var button = ev.target.closest('button, .tab-btn, [role="tab"]');
      if (!button) return;
      setTimeout(renderDashboard, 0);
    }, true);

    document.addEventListener('keydown', function(ev){
      if (ev.key === 'F5') setTimeout(renderDashboard, 0);
    }, true);

    window.addEventListener('resize', function(){
      setTimeout(renderDashboard, 0);
    });

    setInterval(function(){
      if (!getPanel()) return;
      if (!getRoot('dashboardBoard')) return;
      if (!getRoot('dashboardBoard').children.length) {
        renderDashboard();
        return;
      }
      var data = dashboardData();
      var vendorFilter = currentFilterValue('dashboardVendorFilter', 'ALL');
      var statusFilter = currentFilterValue('dashboardStatusFilter', 'ALL');
      var signature = JSON.stringify({
        vendorFilter: vendorFilter,
        statusFilter: statusFilter,
        keys: data.entries.map(function(entry){
          return [entry.key, entry.status, entry.logs.length].join('|');
        })
      });
      if (signature !== dashboardLastSignature) renderDashboard();
    }, 1200);
  }

  window.renderDashboard = renderDashboard;
  window.customerHoldRenderDashboard = renderDashboard;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){
      bindDashboard();
      renderDashboard();
    });
  } else {
    bindDashboard();
    renderDashboard();
  }
})();