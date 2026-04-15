function ensureDashboardStyles(){
  if (document.getElementById('customer-hold-dashboard-filter-style')) return;
  const style = document.createElement('style');
  style.id = 'customer-hold-dashboard-filter-style';
  style.textContent = `
    .dashboard-filter-bar{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin:0 0 14px;
      padding:10px 12px;
      border:1px solid rgba(255,255,255,.12);
      border-radius:10px;
      background:rgba(255,255,255,.04);
    }
    .dashboard-filter-left{
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
    }
    .dashboard-filter-title{
      font-size:13px;
      font-weight:700;
      color:#d7f7d1;
      letter-spacing:.2px;
      margin-right:4px;
    }
    .dashboard-filter-group{
      display:flex;
      align-items:center;
      gap:6px;
    }
    .dashboard-filter-label{
      font-size:12px;
      color:#cfd6dc;
      white-space:nowrap;
    }
    .dashboard-filter-select{
      min-width:120px;
      height:30px;
      padding:0 10px;
      border-radius:7px;
      border:1px solid rgba(255,255,255,.18);
      background:#1f232a;
      color:#f2f5f7;
      outline:none;
    }
    .dashboard-filter-summary{
      font-size:12px;
      color:#aeb7bf;
      white-space:nowrap;
    }
    .dashboard-entry.available{
      border-color:rgba(125, 211, 252, .18);
      background:rgba(125, 211, 252, .04);
    }
    .dashboard-status-btn.available{
      background:#1f4d2f;
      color:#dcffe5;
      border-color:#2f8a4f;
    }
    .dashboard-empty{
      margin-top:10px;
      padding:18px 16px;
      border:1px dashed rgba(255,255,255,.14);
      border-radius:10px;
      color:#b8c1c9;
      background:rgba(255,255,255,.03);
      text-align:center;
    }
  `;
  document.head.appendChild(style);
}

function ensureDashboardFilterDefaults(){
  if (!state.dashboardVendorFilter) state.dashboardVendorFilter = 'all';
  if (!state.dashboardAvailabilityFilter) state.dashboardAvailabilityFilter = 'all';
}

function dashboardEntryKey(row){
  return [
    normalizeText(row.part_name || ''),
    normalizeText(row.item_code || ''),
    normalizeText(row.tool_text || ''),
    normalizeText(row.cavity_text || ''),
    normalizeText(row.affect_lot_text || ''),
    normalizeText(row.vendor_text || ''),
    normalizeText(row.type_text || ''),
    normalizeText(row.issue_description_text || '')
  ].join('||');
}

function dashboardEntryTitle(row){
  const tool = normalizeText(row.tool_text || '') || '-';
  const cavity = normalizeText(row.cavity_text || '');
  return cavity ? (tool + ' / ' + cavity) : tool;
}

function dashboardVendorBucket(raw){
  const v = normalizeText(raw || '').toLowerCase();
  if (!v) return '';
  if (v.includes('lg') || v.includes('엘지')) return 'lg';
  if (v.includes('자화') || v.includes('jawha') || v.includes('jmeas')) return 'jawha';
  return '';
}

function dashboardVendorMatches(row, filterValue){
  if (!filterValue || filterValue === 'all') return true;
  return dashboardVendorBucket(row.vendor_text || '') === filterValue;
}

function findMatchingReleaseLogs(entry){
  if (!entry) return [];
  return (state.releaseDetails || []).filter(function(row){
    const parts = normalizeText(row.parts_name_text || '');
    const partOk = !parts
      || parts === normalizeText(entry.item_code || '')
      || parts === normalizeText(entry.part_name || '');
    return partOk
      && normalizeText(row.tool_text || '') === normalizeText(entry.tool_text || '')
      && normalizeText(row.cavity_text || '') === normalizeText(entry.cavity_text || '')
      && normalizeText(row.vendor_text || '') === normalizeText(entry.vendor_text || '')
      && normalizeText(row.affect_lot_text || '') === normalizeText(entry.affect_lot_text || '')
      && normalizeText(row.type_text || '') === normalizeText(entry.type_text || '')
      && normalizeText(row.issue_description_text || '') === normalizeText(entry.issue_description_text || '');
  }).sort(function(a,b){
    return ((Number(b.sort_order) || 0) - (Number(a.sort_order) || 0));
  });
}

function dashboardHasHoldEvidence(entry){
  return anyFilled(entry, [
    'vendor_text',
    'affect_lot_text',
    'type_text',
    'issue_description_text',
    'remark_text'
  ]);
}

function dashboardEntryStatus(entry){
  const logs = findMatchingReleaseLogs(entry);
  const latest = logs.length ? logs[0] : null;
  const latestStatus = normalizeText(latest ? latest.status_text : '').toLowerCase();
  if (latestStatus === 'close') {
    return 'available';
  }
  if (latestStatus === 'ongoing') {
    return 'blocked';
  }
  return dashboardHasHoldEvidence(entry) ? 'blocked' : 'available';
}

function dashboardAvailabilityMatches(entry, filterValue){
  if (!filterValue || filterValue === 'all') return true;
  const status = dashboardEntryStatus(entry);
  return filterValue === status;
}

function renderDashboardLogPanel(entry, logs){
  const panel = document.createElement('div');
  panel.className = 'dashboard-log-panel';

  const head = document.createElement('div');
  head.className = 'dashboard-log-head';

  const left = document.createElement('div');
  const title = document.createElement('div');
  title.className = 'dashboard-log-title';
  title.textContent = '관련 로그';

  const sub = document.createElement('div');
  sub.className = 'dashboard-log-sub';

  if (entry) {
    sub.textContent = [
      (normalizeText(entry.part_name || '') || '-') + ' · ' + dashboardEntryTitle(entry),
      [normalizeText(entry.vendor_text || ''), normalizeText(entry.type_text || ''), normalizeText(entry.affect_lot_text || '')].filter(Boolean).join(' · '),
      normalizeText(entry.issue_description_text || '') || '-'
    ].filter(Boolean).join('\n');
  } else {
    sub.textContent = '카드를 누르면 해당 건의 홀딩 세부내역 로그를 볼 수 있습니다.';
  }

  left.appendChild(title);
  left.appendChild(sub);
  head.appendChild(left);
  panel.appendChild(head);

  if (!entry) {
    const empty = document.createElement('div');
    empty.className = 'dashboard-log-empty';
    empty.textContent = '선택된 홀딩 건이 없습니다.';
    panel.appendChild(empty);
    return panel;
  }

  if (!logs.length) {
    const empty = document.createElement('div');
    empty.className = 'dashboard-log-empty';
    empty.textContent = '연결된 홀딩 세부내역 로그가 없습니다.';
    panel.appendChild(empty);
    return panel;
  }

  const list = document.createElement('div');
  list.className = 'dashboard-log-list';

  logs.forEach(function(row){
    const item = document.createElement('div');
    item.className = 'dashboard-log-item';

    const top = document.createElement('div');
    top.className = 'dashboard-log-item-top';

    const statusValue = normalizeText(row.status_text || '');
    const status = document.createElement('div');
    status.className = 'dashboard-log-badge' + (statusValue.toLowerCase() === 'close' ? ' close' : '');
    status.textContent = statusValue || '기록';

    const kv = document.createElement('div');
    kv.className = 'dashboard-log-kv';
    kv.textContent = [
      'Holding DATE: ' + (normalizeText(row.holding_date_text || '') || '-'),
      'Release DATE: ' + (normalizeText(row.release_date_text || '') || '-'),
      'Parts name: ' + (normalizeText(row.parts_name_text || '') || '-')
    ].join('\n');

    top.appendChild(status);
    top.appendChild(kv);
    item.appendChild(top);

    const note = document.createElement('div');
    note.className = 'dashboard-log-note';
    note.textContent = normalizeText(row.note_text || '') || '비고 없음';
    item.appendChild(note);

    list.appendChild(item);
  });

  panel.appendChild(list);
  return panel;
}

function renderDashboard(){
  if (!els.dashboardRoot) return;

  ensureDashboardStyles();
  ensureDashboardFilterDefaults();

  const root = els.dashboardRoot;
  root.innerHTML = '';

  const allRows = (state.toolStatusRows || []).filter(function(row){
    return anyFilled(row, [
      'tool_text',
      'cavity_text',
      'affect_lot_text',
      'vendor_text',
      'type_text',
      'issue_description_text',
      'remark_text'
    ]);
  });

  const filteredRows = allRows.filter(function(row){
    return dashboardVendorMatches(row, state.dashboardVendorFilter)
      && dashboardAvailabilityMatches(row, state.dashboardAvailabilityFilter);
  });

  const filterBar = document.createElement('div');
  filterBar.className = 'dashboard-filter-bar';

  const filterLeft = document.createElement('div');
  filterLeft.className = 'dashboard-filter-left';

  const title = document.createElement('div');
  title.className = 'dashboard-filter-title';
  title.textContent = '홀딩 현황판';
  filterLeft.appendChild(title);

  const vendorGroup = document.createElement('div');
  vendorGroup.className = 'dashboard-filter-group';
  const vendorLabel = document.createElement('label');
  vendorLabel.className = 'dashboard-filter-label';
  vendorLabel.textContent = '구분';
  const vendorSelect = document.createElement('select');
  vendorSelect.className = 'dashboard-filter-select';
  [
    ['all', '전체'],
    ['lg', 'LG'],
    ['jawha', '자화']
  ].forEach(function(item){
    const option = document.createElement('option');
    option.value = item[0];
    option.textContent = item[1];
    if (state.dashboardVendorFilter === item[0]) option.selected = true;
    vendorSelect.appendChild(option);
  });
  vendorSelect.addEventListener('change', function(){
    state.dashboardVendorFilter = this.value || 'all';
    renderDashboard();
  });
  vendorGroup.appendChild(vendorLabel);
  vendorGroup.appendChild(vendorSelect);
  filterLeft.appendChild(vendorGroup);

  const availabilityGroup = document.createElement('div');
  availabilityGroup.className = 'dashboard-filter-group';
  const availabilityLabel = document.createElement('label');
  availabilityLabel.className = 'dashboard-filter-label';
  availabilityLabel.textContent = '상태';
  const availabilitySelect = document.createElement('select');
  availabilitySelect.className = 'dashboard-filter-select';
  [
    ['all', '전체'],
    ['available', '출하가능'],
    ['blocked', '출하불가']
  ].forEach(function(item){
    const option = document.createElement('option');
    option.value = item[0];
    option.textContent = item[1];
    if (state.dashboardAvailabilityFilter === item[0]) option.selected = true;
    availabilitySelect.appendChild(option);
  });
  availabilitySelect.addEventListener('change', function(){
    state.dashboardAvailabilityFilter = this.value || 'all';
    renderDashboard();
  });
  availabilityGroup.appendChild(availabilityLabel);
  availabilityGroup.appendChild(availabilitySelect);
  filterLeft.appendChild(availabilityGroup);

  const filterSummary = document.createElement('div');
  filterSummary.className = 'dashboard-filter-summary';
  filterSummary.textContent = '표시 ' + filteredRows.length + ' / 전체 ' + allRows.length;

  filterBar.appendChild(filterLeft);
  filterBar.appendChild(filterSummary);
  root.appendChild(filterBar);

  const ongoingCount = (state.releaseDetails || []).filter(function(row){
    return normalizeText(row.status_text || '').toLowerCase() === 'ongoing';
  }).length;

  const closeCount = (state.releaseDetails || []).filter(function(row){
    return normalizeText(row.status_text || '').toLowerCase() === 'close';
  }).length;

  const blockedCount = filteredRows.filter(function(row){
    return dashboardEntryStatus(row) === 'blocked';
  }).length;

  const availableCount = filteredRows.filter(function(row){
    return dashboardEntryStatus(row) === 'available';
  }).length;

  const summary = document.createElement('div');
  summary.className = 'dashboard-summary';

  [
    {title:'현재 출하불가', value:String(blockedCount), sub:'선택 필터 기준 출하불가'},
    {title:'현재 출하가능', value:String(availableCount), sub:'선택 필터 기준 출하가능'},
    {title:'진행중 로그', value:String(ongoingCount), sub:'홀딩 세부내역의 Ongoing'},
    {title:'해제완료 로그', value:String(closeCount), sub:'홀딩 세부내역의 Close'}
  ].forEach(function(card){
    const el = document.createElement('div');
    el.className = 'dashboard-card';
    el.innerHTML = '<h3>' + card.title + '</h3><strong>' + card.value + '</strong><span>' + card.sub + '</span>';
    summary.appendChild(el);
  });

  root.appendChild(summary);

  const board = document.createElement('div');
  board.className = 'dashboard-board';

  const grouped = buildToolGroups(filteredRows);
  let selectedEntry = null;

  state.models.forEach(function(model){
    const rows = grouped[model] || [];
    if (!rows.length) return;

    const modelWrap = document.createElement('div');
    modelWrap.className = 'dashboard-model';

    const head = document.createElement('div');
    head.className = 'dashboard-model-head';
    head.innerHTML = '<strong>' + model + '</strong><span>' + rows.length + '건</span>';
    modelWrap.appendChild(head);

    const grid = document.createElement('div');
    grid.className = 'dashboard-grid';

    rows.forEach(function(row){
      const key = dashboardEntryKey(row);
      if (!selectedEntry && state.dashboardSelectedKey && state.dashboardSelectedKey === key) {
        selectedEntry = row;
      }

      const status = dashboardEntryStatus(row);
      const entry = document.createElement('div');
      entry.className = 'dashboard-entry' + (state.dashboardSelectedKey === key ? ' active' : '') + (status === 'available' ? ' available' : '');

      const top = document.createElement('div');
      top.className = 'dashboard-entry-top';

      const left = document.createElement('div');
      left.innerHTML = '<strong>' + dashboardEntryTitle(row) + '</strong><span>' + ([normalizeText(row.vendor_text || ''), normalizeText(row.type_text || ''), normalizeText(row.affect_lot_text || '')].filter(Boolean).join(' · ')) + '</span>';

      const statusBtn = document.createElement('button');
      statusBtn.type = 'button';
      statusBtn.className = 'dashboard-status-btn' + (status === 'available' ? ' available' : '');
      statusBtn.textContent = status === 'available' ? '출하가능' : '출하불가';
      statusBtn.addEventListener('click', function(ev){
        ev.stopPropagation();
        state.dashboardSelectedKey = key;
        renderDashboard();
      });

      top.appendChild(left);
      top.appendChild(statusBtn);
      entry.appendChild(top);

      const issue = document.createElement('div');
      issue.className = 'dashboard-entry-issue';
      issue.textContent = normalizeText(row.issue_description_text || '') || (status === 'available' ? '연결된 홀딩 근거 없음' : '-');
      entry.appendChild(issue);

      entry.addEventListener('click', function(){
        state.dashboardSelectedKey = key;
        renderDashboard();
      });

      grid.appendChild(entry);
    });

    modelWrap.appendChild(grid);
    board.appendChild(modelWrap);
  });

  root.appendChild(board);

  if (!filteredRows.length) {
    const empty = document.createElement('div');
    empty.className = 'dashboard-empty';
    empty.textContent = '현재 필터 조건에 맞는 항목이 없습니다.';
    root.appendChild(empty);
    root.appendChild(renderDashboardLogPanel(null, []));
    return;
  }

  if (!selectedEntry) {
    if (!state.dashboardSelectedKey) {
      state.dashboardSelectedKey = dashboardEntryKey(filteredRows[0]);
    }
    selectedEntry = filteredRows.find(function(row){
      return dashboardEntryKey(row) === state.dashboardSelectedKey;
    }) || filteredRows[0];
  }

  const logs = selectedEntry ? findMatchingReleaseLogs(selectedEntry) : [];
  root.appendChild(renderDashboardLogPanel(selectedEntry, logs));
}
