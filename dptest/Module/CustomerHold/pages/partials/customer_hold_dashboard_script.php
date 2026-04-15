function dashboardEntryKey(row) {
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

function dashboardFilterEls() {
  return {
    vendor: document.getElementById('dashboardVendorFilter'),
    status: document.getElementById('dashboardStatusFilter')
  };
}

function dashboardRemoveAllOption(selectEl) {
  if (!selectEl) return;
  Array.from(selectEl.options || []).forEach(function (opt) {
    if (String(opt.value || '').toLowerCase() === 'all') {
      opt.remove();
    }
  });
}

function dashboardEnsureFilterDefaults() {
  const controls = dashboardFilterEls();
  dashboardRemoveAllOption(controls.vendor);
  dashboardRemoveAllOption(controls.status);

  if (controls.vendor && (!controls.vendor.value || String(controls.vendor.value).toLowerCase() === 'all')) {
    const hasLg = Array.from(controls.vendor.options || []).some(function (opt) {
      return String(opt.value || '').toLowerCase() === 'lg';
    });
    controls.vendor.value = hasLg ? 'lg' : ((controls.vendor.options[0] && controls.vendor.options[0].value) || '');
  }

  if (controls.status && (!controls.status.value || String(controls.status.value).toLowerCase() === 'all')) {
    const hasBlocked = Array.from(controls.status.options || []).some(function (opt) {
      return String(opt.value || '').toLowerCase() === 'blocked';
    });
    controls.status.value = hasBlocked ? 'blocked' : ((controls.status.options[0] && controls.status.options[0].value) || '');
  }
}

function getDashboardVendorFilter() {
  const el = dashboardFilterEls().vendor;
  return el ? String(el.value || 'lg') : 'lg';
}

function getDashboardStatusFilter() {
  const el = dashboardFilterEls().status;
  return el ? String(el.value || 'blocked') : 'blocked';
}

function ensureDashboardFilterBindings() {
  const controls = dashboardFilterEls();
  dashboardEnsureFilterDefaults();
  [controls.vendor, controls.status].forEach(function (el) {
    if (!el || el.dataset.bound === '1') return;
    el.dataset.bound = '1';
    el.addEventListener('change', function () {
      state.dashboardSelectedKey = '';
      renderDashboard();
    });
  });
}

function dashboardEntryTitle(row) {
  const tool = normalizeText(row.tool_text || '') || '-';
  const cavity = normalizeText(row.cavity_text || '');
  return cavity ? (tool + ' / ' + cavity) : tool;
}

function findMatchingReleaseLogs(entry) {
  if (!entry) return [];
  return (state.releaseDetails || []).filter(function (row) {
    const parts = normalizeText(row.parts_name_text || '');
    const partOk = !parts || parts === normalizeText(entry.item_code || '') || parts === normalizeText(entry.part_name || '');
    return partOk &&
      normalizeText(row.tool_text || '') === normalizeText(entry.tool_text || '') &&
      normalizeText(row.cavity_text || '') === normalizeText(entry.cavity_text || '') &&
      normalizeText(row.vendor_text || '') === normalizeText(entry.vendor_text || '') &&
      normalizeText(row.affect_lot_text || '') === normalizeText(entry.affect_lot_text || '') &&
      normalizeText(row.type_text || '') === normalizeText(entry.type_text || '') &&
      normalizeText(row.issue_description_text || '') === normalizeText(entry.issue_description_text || '');
  }).sort(function (a, b) {
    return ((Number(b.sort_order) || 0) - (Number(a.sort_order) || 0));
  });
}

function dashboardHasHoldContent(row) {
  return anyFilled(row, ['cavity_text', 'affect_lot_text', 'vendor_text', 'type_text', 'issue_description_text', 'remark_text']);
}

function getDashboardStatusInfo(row, logs) {
  const latest = logs && logs.length ? normalizeText(logs[0].status_text || '').toLowerCase() : '';
  const hasHold = dashboardHasHoldContent(row);
  if (latest === 'ongoing') {
    return { code: 'blocked', label: '출하불가' };
  }
  if (latest === 'close' && !hasHold) {
    return { code: 'available', label: '출하가능' };
  }
  if (hasHold) {
    return { code: 'blocked', label: '출하불가' };
  }
  return { code: 'available', label: '출하가능' };
}

function dashboardVendorMatches(row, selected) {
  if (!selected) return true;
  if (selected === 'all') return true;
  const vendor = normalizeText(row.vendor_text || '');
  if (!vendor) return true;
  return vendor === selected;
}

function renderDashboardLogPanel(entry, logs) {
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
    sub.textContent = '카드를 누르면 해당 건의 로그를 볼 수 있습니다.';
  }

  left.appendChild(title);
  left.appendChild(sub);
  head.appendChild(left);
  panel.appendChild(head);

  if (!entry) {
    const empty = document.createElement('div');
    empty.className = 'dashboard-log-empty';
    empty.textContent = '선택된 항목이 없습니다.';
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
  logs.forEach(function (row) {
    const item = document.createElement('div');
    item.className = 'dashboard-log-item';

    const top = document.createElement('div');
    top.className = 'dashboard-log-item-top';

    const status = document.createElement('div');
    status.className = 'dashboard-log-badge' + (normalizeText(row.status_text || '').toLowerCase() === 'close' ? ' close' : '');
    status.textContent = normalizeText(row.status_text || '') || '기록';

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

function renderDashboard() {
  if (!els.dashboardRoot) return;

  ensureDashboardFilterBindings();
  dashboardEnsureFilterDefaults();

  const root = els.dashboardRoot;
  root.innerHTML = '';

  const vendorFilter = getDashboardVendorFilter();
  const statusFilter = getDashboardStatusFilter();

  const allRows = (state.toolStatusRows || []).filter(function (row) {
    return normalizeText(row.tool_text || '') !== '' || dashboardHasHoldContent(row);
  });

  const blockedRows = [];
  const availableRows = [];
  const visibleRows = [];

  allRows.forEach(function (row) {
    const logs = findMatchingReleaseLogs(row);
    const status = getDashboardStatusInfo(row, logs);
    if (status.code === 'blocked') blockedRows.push(row);
    else availableRows.push(row);

    if (!dashboardVendorMatches(row, vendorFilter)) return;
    if (statusFilter && statusFilter !== 'all' && status.code !== statusFilter) return;
    visibleRows.push(row);
  });

  const ongoingCount = (state.releaseDetails || []).filter(function (row) {
    return normalizeText(row.status_text || '').toLowerCase() === 'ongoing';
  }).length;

  const closeCount = (state.releaseDetails || []).filter(function (row) {
    return normalizeText(row.status_text || '').toLowerCase() === 'close';
  }).length;

  const summary = document.createElement('div');
  summary.className = 'dashboard-summary';

  [
    { title: '현재 출하불가', value: String(blockedRows.length), sub: 'Tool Status 기준 현재 활성 홀딩' },
    { title: '진행중 로그', value: String(ongoingCount), sub: '홀딩 세부내역의 Ongoing' },
    { title: '해제완료 로그', value: String(closeCount), sub: '홀딩 세부내역의 Close' }
  ].forEach(function (card) {
    const el = document.createElement('div');
    el.className = 'dashboard-card';

    const title = document.createElement('h3');
    title.textContent = card.title;

    const value = document.createElement('div');
    value.className = 'dashboard-value';
    value.textContent = card.value;

    const sub = document.createElement('div');
    sub.className = 'dashboard-sub';
    sub.textContent = card.sub;

    el.appendChild(title);
    el.appendChild(value);
    el.appendChild(sub);
    summary.appendChild(el);
  });

  root.appendChild(summary);

  const board = document.createElement('div');
  board.className = 'dashboard-board';
  const grouped = buildToolGroups(visibleRows);
  let selectedEntry = null;

  state.models.forEach(function (model) {
    const rows = grouped[model] || [];
    if (!rows.length) return;

    const modelWrap = document.createElement('div');
    modelWrap.className = 'dashboard-model';

    const head = document.createElement('div');
    head.className = 'dashboard-model-head';

    const title = document.createElement('div');
    title.className = 'dashboard-model-title';
    title.textContent = model;

    const count = document.createElement('div');
    count.className = 'dashboard-model-count';
    count.textContent = rows.length + '건';

    head.appendChild(title);
    head.appendChild(count);
    modelWrap.appendChild(head);

    const grid = document.createElement('div');
    grid.className = 'dashboard-grid';

    rows.forEach(function (row) {
      const key = dashboardEntryKey(row);
      const logs = findMatchingReleaseLogs(row);
      const status = getDashboardStatusInfo(row, logs);
      if (!selectedEntry && state.dashboardSelectedKey && state.dashboardSelectedKey === key) {
        selectedEntry = row;
      }

      const entry = document.createElement('div');
      entry.className = 'dashboard-entry' + (state.dashboardSelectedKey === key ? ' active' : '');

      const top = document.createElement('div');
      top.className = 'dashboard-entry-top';

      const left = document.createElement('div');
      const entryTitle = document.createElement('div');
      entryTitle.className = 'dashboard-entry-title';
      entryTitle.textContent = dashboardEntryTitle(row);

      const meta = document.createElement('div');
      meta.className = 'dashboard-entry-meta';
      meta.textContent = [
        normalizeText(row.vendor_text || ''),
        normalizeText(row.type_text || ''),
        normalizeText(row.affect_lot_text || '')
      ].filter(Boolean).join(' · ');

      left.appendChild(entryTitle);
      left.appendChild(meta);

      const statusBtn = document.createElement('button');
      statusBtn.type = 'button';
      statusBtn.className = 'dashboard-status-btn' + (status.code === 'available' ? ' available' : '');
      statusBtn.textContent = status.label;
      statusBtn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        state.dashboardSelectedKey = key;
        renderDashboard();
      });

      top.appendChild(left);
      top.appendChild(statusBtn);
      entry.appendChild(top);

      const issue = document.createElement('div');
      issue.className = 'dashboard-entry-issue';
      issue.textContent = normalizeText(row.issue_description_text || '') || '-';
      entry.appendChild(issue);

      entry.addEventListener('click', function () {
        state.dashboardSelectedKey = key;
        renderDashboard();
      });

      grid.appendChild(entry);
    });

    modelWrap.appendChild(grid);
    board.appendChild(modelWrap);
  });

  if (!board.children.length) {
    const empty = document.createElement('div');
    empty.className = 'dashboard-empty';
    empty.textContent = '선택한 조건에 맞는 항목이 없습니다.';
    board.appendChild(empty);
  }

  root.appendChild(board);

  if (!selectedEntry && visibleRows.length) {
    if (!state.dashboardSelectedKey) state.dashboardSelectedKey = dashboardEntryKey(visibleRows[0]);
    selectedEntry = visibleRows.find(function (row) {
      return dashboardEntryKey(row) === state.dashboardSelectedKey;
    }) || visibleRows[0];
  }

  const logs = selectedEntry ? findMatchingReleaseLogs(selectedEntry) : [];
  root.appendChild(renderDashboardLogPanel(selectedEntry, logs));
}
