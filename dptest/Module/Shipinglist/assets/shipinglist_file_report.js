(function () {
  'use strict';

  function text(el) {
    return (el && el.textContent ? el.textContent : '').trim();
  }

  function detectShipTo(filename) {
    var s = String(filename || '').toLowerCase();
    var hasLgit = s.indexOf('lgit') !== -1;
    var hasJahwa = s.indexOf('jahwa') !== -1;
    if (hasLgit === hasJahwa) return '';
    return hasLgit ? '엘지이노텍(주)' : '자화전자(주)';
  }

  function fmt(n) {
    return Number(n || 0).toLocaleString('ko-KR');
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function getBuildForm() {
    return document.getElementById('buildForm');
  }

  function getCustomerSelect() {
    var form = getBuildForm();
    return form ? form.querySelector('select[name="ship_to"]') : null;
  }

  function getDates() {
    var form = getBuildForm();
    if (!form) return {fromDate:'', toDate:''};
    var from = form.querySelector('input[name="from_date"]');
    var to = form.querySelector('input[name="to_date"]');
    return {
      fromDate: from ? String(from.value || '').trim() : '',
      toDate: to ? String(to.value || '').trim() : ''
    };
  }

  function selectCustomer(label) {
    if (!label) return false;
    var sel = getCustomerSelect();
    if (!sel) return false;
    var options = Array.prototype.slice.call(sel.options || []);
    var opt = options.find(function (o) { return String(o.value || '').trim() === label; });
    if (!opt) {
      var key = label.replace('(주)', '');
      opt = options.find(function (o) { return text(o).indexOf(key) !== -1; });
    }
    if (!opt) return false;
    sel.value = opt.value;
    sel.dispatchEvent(new Event('change', {bubbles:true}));
    return true;
  }

  function modalHostDocument() {
    try {
      if (window.parent && window.parent !== window && window.parent.document) {
        return window.parent.document;
      }
    } catch (e) {}
    return document;
  }

  function closePreview() {
    var d = modalHostDocument();
    var m = d.getElementById('sfrPreviewModal');
    if (m && m.parentNode) m.parentNode.removeChild(m);
  }

  function canConfirm(preview) {
    if (!preview || !Array.isArray(preview.rows) || preview.rows.length === 0) return false;
    if ((preview.unmatched_pack_nos || []).length) return false;
    if ((preview.ambiguous_pack_nos || []).length) return false;
    if (Number((preview.totals || {}).diff || 0) !== 0) return false;
    return preview.rows.every(function (r) { return !!r && r.match === true; });
  }

  function setHidden(form, name, value) {
    var el = form.querySelector('input[type="hidden"][name="' + name + '"]');
    if (!el) {
      el = document.createElement('input');
      el.type = 'hidden';
      el.name = name;
      form.appendChild(el);
    }
    el.value = value;
  }

  function buildToken() {
    try {
      if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID().replace(/-/g, '');
      }
    } catch (e) {}
    return String(Date.now()) + String(Math.random()).replace(/\D/g, '');
  }

  // 확인 버튼은 별도 생성 엔진을 호출하지 않고 기존 buildForm을 그대로 제출한다.
  function startExistingReportBuild(payload) {
    var form = getBuildForm();
    if (!form) {
      alert('기존 성적서 생성 폼을 찾지 못했습니다.');
      return;
    }
    if (payload.ship_to) selectCustomer(payload.ship_to);
    setHidden(form, 'file_selection_token', String(payload.selection_token || ''));
    setHidden(form, 'build_token', buildToken());
    closePreview();
    HTMLFormElement.prototype.submit.call(form);
  }

  function renderPreview(payload, file) {
    closePreview();

    var d = modalHostDocument();
    var preview = payload.preview || {};
    var ok = !!payload.selection_token && payload.can_confirm === true && canConfirm(preview);
    var rows = (preview.rows || []).map(function (r) {
      var diff = Number(r.diff || 0);
      return '<tr>' +
        '<td>' + esc(r.part) + '</td>' +
        '<td class="sfr-num">' + fmt(r.file_qty) + '</td>' +
        '<td class="sfr-num">' + fmt(r.db_qty) + '</td>' +
        '<td class="sfr-center ' + (r.match ? 'sfr-ok' : 'sfr-bad') + '">' +
          (r.match ? '일치' : ('차이 ' + (diff > 0 ? '+' : '') + fmt(diff))) +
        '</td>' +
      '</tr>';
    }).join('');

    var warnings = [];
    if ((preview.unmatched_pack_nos || []).length) {
      warnings.push('DB 미매칭 포장번호 ' + preview.unmatched_pack_nos.length + '건: ' + preview.unmatched_pack_nos.join(', '));
    }
    if ((preview.ambiguous_pack_nos || []).length) {
      warnings.push('포장번호 매칭이 모호한 항목 ' + preview.ambiguous_pack_nos.length + '건: ' + preview.ambiguous_pack_nos.join(', '));
    }
    if (!ok && warnings.length === 0) {
      warnings.push('파일 수량과 DB 수량이 일치하지 않는 품번이 있습니다. 확인 후 다시 시도해 주세요.');
    }

    var modal = d.createElement('div');
    modal.id = 'sfrPreviewModal';
    modal.innerHTML =
      '<div class="sfr-backdrop"></div>' +
      '<div class="sfr-dialog" role="dialog" aria-modal="true">' +
        '<div class="sfr-head">' +
          '<div class="sfr-title">파일선택 출하성적서 확인</div>' +
          '<button type="button" class="sfr-close">닫기</button>' +
        '</div>' +
        '<div class="sfr-body">' +
          '<div class="sfr-meta">' +
            '<div><span>파일</span><strong>' + esc(payload.filename || (file ? file.name : '')) + '</strong></div>' +
            '<div><span>납품처</span><strong>' + esc(payload.ship_to || '') + '</strong></div>' +
            '<div><span>포장번호</span><strong>' + fmt(preview.file_pack_count || 0) + '건</strong></div>' +
          '</div>' +
          '<div class="sfr-table-wrap">' +
            '<table class="sfr-table">' +
              '<thead><tr><th>품번</th><th>파일 수량</th><th>DB 수량</th><th>대조</th></tr></thead>' +
              '<tbody>' + rows + '</tbody>' +
              '<tfoot><tr>' +
                '<th>합계</th>' +
                '<th class="sfr-num">' + fmt((preview.totals || {}).file_qty) + '</th>' +
                '<th class="sfr-num">' + fmt((preview.totals || {}).db_qty) + '</th>' +
                '<th class="sfr-center ' + (Number((preview.totals || {}).diff || 0) === 0 ? 'sfr-ok' : 'sfr-bad') + '">' +
                  (Number((preview.totals || {}).diff || 0) === 0 ? '일치' : ('차이 ' + fmt((preview.totals || {}).diff))) +
                '</th>' +
              '</tr></tfoot>' +
            '</table>' +
          '</div>' +
          (warnings.length ? '<div class="sfr-warning">' + warnings.map(esc).join('<br>') + '</div>' : '') +
          '<div class="sfr-question">위 출하내역으로 출하성적서를 작성하시겠습니까?</div>' +
        '</div>' +
        '<div class="sfr-actions">' +
          '<button type="button" class="sfr-cancel">취소</button>' +
          '<button type="button" class="sfr-confirm"' + (ok ? '' : ' disabled') + '>확인</button>' +
        '</div>' +
      '</div>';

    var style = d.createElement('style');
    style.textContent =
      '#sfrPreviewModal{position:fixed;inset:0;z-index:2147483000;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#e8eaed}' +
      '#sfrPreviewModal .sfr-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.62)}' +
      '#sfrPreviewModal .sfr-dialog{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:min(760px,92vw);max-height:86vh;overflow:hidden;background:#2b2b2b;border:1px solid rgba(255,255,255,.12);border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.65);display:flex;flex-direction:column}' +
      '#sfrPreviewModal .sfr-head{display:flex;align-items:center;justify-content:space-between;padding:11px 14px;border-bottom:1px solid rgba(255,255,255,.10)}' +
      '#sfrPreviewModal .sfr-title{font-size:15px;font-weight:800}' +
      '#sfrPreviewModal .sfr-close{height:30px;padding:0 10px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.08);color:#e8eaed;cursor:pointer}' +
      '#sfrPreviewModal .sfr-body{padding:16px 18px;overflow:auto}' +
      '#sfrPreviewModal .sfr-meta{display:flex;gap:18px;flex-wrap:wrap;margin-bottom:12px;font-size:12px}' +
      '#sfrPreviewModal .sfr-meta div{display:flex;gap:6px;align-items:center}' +
      '#sfrPreviewModal .sfr-meta span{color:#9aa0a6}' +
      '#sfrPreviewModal .sfr-table-wrap{overflow:auto;border-radius:12px;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.08)}' +
      '#sfrPreviewModal .sfr-table{width:100%;border-collapse:separate;border-spacing:0;min-width:520px}' +
      '#sfrPreviewModal .sfr-table th,#sfrPreviewModal .sfr-table td{padding:10px;border-bottom:1px solid rgba(255,255,255,.07);font-size:12.5px}' +
      '#sfrPreviewModal .sfr-table thead th{background:rgba(255,255,255,.06);color:#cbd5e1;text-align:center}' +
      '#sfrPreviewModal .sfr-table tbody td:first-child,#sfrPreviewModal .sfr-table tfoot th:first-child{text-align:left}' +
      '#sfrPreviewModal .sfr-table tfoot th{background:rgba(255,255,255,.04)}' +
      '#sfrPreviewModal .sfr-num{text-align:right!important;font-variant-numeric:tabular-nums}' +
      '#sfrPreviewModal .sfr-center{text-align:center!important}' +
      '#sfrPreviewModal .sfr-ok{color:#81c995;font-weight:800}' +
      '#sfrPreviewModal .sfr-bad{color:#f28b82;font-weight:800}' +
      '#sfrPreviewModal .sfr-warning{margin-top:12px;padding:10px 12px;border-radius:10px;background:rgba(232,93,93,.12);border:1px solid rgba(232,93,93,.24);color:#ffd1d1;font-size:12px;line-height:1.5;word-break:break-all}' +
      '#sfrPreviewModal .sfr-question{text-align:center;margin-top:16px;font-size:13px;font-weight:800}' +
      '#sfrPreviewModal .sfr-actions{display:flex;justify-content:flex-end;gap:8px;padding:0 18px 16px}' +
      '#sfrPreviewModal .sfr-actions button{height:34px;padding:0 14px;border-radius:11px;border:1px solid rgba(255,255,255,.16);font-weight:700;cursor:pointer}' +
      '#sfrPreviewModal .sfr-cancel{background:rgba(255,255,255,.06);color:#e8eaed}' +
      '#sfrPreviewModal .sfr-confirm{background:#4f8cff;border-color:#4f8cff!important;color:#fff}' +
      '#sfrPreviewModal .sfr-confirm:disabled{opacity:.38;cursor:not-allowed}';

    modal.appendChild(style);
    (d.body || d.documentElement).appendChild(modal);

    modal.querySelector('.sfr-close').addEventListener('click', closePreview);
    modal.querySelector('.sfr-cancel').addEventListener('click', closePreview);
    modal.querySelector('.sfr-backdrop').addEventListener('click', closePreview);
    modal.querySelector('.sfr-confirm').addEventListener('click', function () {
      if (!ok) return;
      startExistingReportBuild(payload);
    });
  }

  async function analyzeFile(file) {
    var detected = detectShipTo(file.name);
    if (detected) selectCustomer(detected);

    var dates = getDates();
    var customer = getCustomerSelect();
    var shipTo = customer ? String(customer.value || '').trim() : '';

    var fd = new FormData();
    fd.append('shipping_file', file);
    fd.append('from_date', dates.fromDate);
    fd.append('to_date', dates.toDate || dates.fromDate);
    fd.append('ship_to', shipTo);

    var res = await fetch('shipinglist_file_report_preview.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: {'Accept':'application/json'}
    });

    var raw = await res.text();
    var data;
    try {
      data = JSON.parse(raw);
    } catch (e) {
      throw new Error(raw || '서버 응답을 해석할 수 없습니다.');
    }
    if (!res.ok || !data || data.ok === false) {
      throw new Error((data && data.message) ? data.message : '파일 분석에 실패했습니다.');
    }

    if (data.detected_ship_to) selectCustomer(data.detected_ship_to);
    renderPreview(data, file);
  }

  function openFileReportBuild() {
    var input = document.getElementById('sfrFileReportInput');
    if (!input) {
      alert('파일선택 입력을 찾지 못했습니다.');
      return;
    }
    input.value = '';
    input.click();
  }

  function init() {
    var button = document.getElementById('sfrFileReportButton');
    var input = document.getElementById('sfrFileReportInput');
    if (!button || !input) return;

    input.addEventListener('change', async function () {
      var file = input.files && input.files[0] ? input.files[0] : null;
      if (!file) return;
      button.disabled = true;
      try {
        await analyzeFile(file);
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
      } finally {
        button.disabled = false;
      }
    });
  }

  window.openFileReportBuild = openFileReportBuild;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
