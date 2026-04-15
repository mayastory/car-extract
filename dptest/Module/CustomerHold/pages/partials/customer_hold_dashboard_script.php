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

function findMatchingReleaseLogs(entry){
    if (!entry) return [];
    return (state.releaseDetails || []).filter(function(row){
        const parts = normalizeText(row.parts_name_text || '');
        const partOk = !parts || parts === normalizeText(entry.item_code || '') || parts === normalizeText(entry.part_name || '');
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
        sub.textContent = '출하불가 배지를 누르면 해당 건의 로그를 볼 수 있습니다.';
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

function renderDashboard(){
    if (!els.dashboardRoot) return;
    const root = els.dashboardRoot;
    root.innerHTML = '';
    const currentRows = (state.toolStatusRows || []).filter(function(row){
        return anyFilled(row, ['tool_text','cavity_text','affect_lot_text','vendor_text','type_text','issue_description_text','remark_text']);
    });
    const ongoingCount = (state.releaseDetails || []).filter(function(row){ return normalizeText(row.status_text || '').toLowerCase() === 'ongoing'; }).length;
    const closeCount = (state.releaseDetails || []).filter(function(row){ return normalizeText(row.status_text || '').toLowerCase() === 'close'; }).length;

    const summary = document.createElement('div');
    summary.className = 'dashboard-summary';
    [
        {title:'현재 출하불가', value:String(currentRows.length), sub:'Tool Status 기준 현재 활성 홀딩'},
        {title:'진행중 로그', value:String(ongoingCount), sub:'홀딩 세부내역의 Ongoing'},
        {title:'해제완료 로그', value:String(closeCount), sub:'홀딩 세부내역의 Close'}
    ].forEach(function(card){
        const el = document.createElement('div');
        el.className = 'dashboard-card';
        el.innerHTML = '<h3>' + card.title + '</h3><div class="dashboard-value">' + card.value + '</div><div class="dashboard-sub">' + card.sub + '</div>';
        summary.appendChild(el);
    });
    root.appendChild(summary);

    const board = document.createElement('div');
    board.className = 'dashboard-board';
    const grouped = buildToolGroups(currentRows);
    let selectedEntry = null;

    state.models.forEach(function(model){
        const rows = grouped[model] || [];
        if (!rows.length) return;
        const modelWrap = document.createElement('div');
        modelWrap.className = 'dashboard-model';
        const head = document.createElement('div');
        head.className = 'dashboard-model-head';
        head.innerHTML = '<div class="dashboard-model-title">' + model + '</div><div class="dashboard-model-count">' + rows.length + '건</div>';
        modelWrap.appendChild(head);
        const grid = document.createElement('div');
        grid.className = 'dashboard-grid';
        rows.forEach(function(row){
            const key = dashboardEntryKey(row);
            if (!selectedEntry && state.dashboardSelectedKey && state.dashboardSelectedKey === key) selectedEntry = row;
            const entry = document.createElement('div');
            entry.className = 'dashboard-entry' + (state.dashboardSelectedKey === key ? ' active' : '');
            const top = document.createElement('div');
            top.className = 'dashboard-entry-top';
            const left = document.createElement('div');
            left.innerHTML = '<div class="dashboard-entry-title">' + dashboardEntryTitle(row) + '</div><div class="dashboard-entry-meta">' + [normalizeText(row.vendor_text || ''), normalizeText(row.type_text || ''), normalizeText(row.affect_lot_text || '')].filter(Boolean).join(' · ') + '</div>';
            const statusBtn = document.createElement('button');
            statusBtn.type = 'button';
            statusBtn.className = 'dashboard-status-btn';
            statusBtn.textContent = '출하불가';
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
            issue.textContent = normalizeText(row.issue_description_text || '') || '-';
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

    if (!selectedEntry && currentRows.length) {
        if (!state.dashboardSelectedKey) state.dashboardSelectedKey = dashboardEntryKey(currentRows[0]);
        selectedEntry = currentRows.find(function(row){ return dashboardEntryKey(row) === state.dashboardSelectedKey; }) || currentRows[0];
    }
    const logs = selectedEntry ? findMatchingReleaseLogs(selectedEntry) : [];
    root.appendChild(renderDashboardLogPanel(selectedEntry, logs));
}

