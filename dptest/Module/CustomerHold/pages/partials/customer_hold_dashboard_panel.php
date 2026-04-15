<style>
  .dashboard-panel-headline{
    font-size:22px;
    font-weight:700;
    color:#f3f7ff;
    margin-bottom:14px;
  }
  .dashboard-toolbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    padding:12px 14px;
    margin-bottom:16px;
    border:1px solid rgba(146, 179, 255, 0.18);
    border-radius:14px;
    background:rgba(6, 18, 34, 0.62);
    backdrop-filter:blur(2px);
  }
  .dashboard-toolbar-left{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
  }
  .dashboard-filter-group{
    display:flex;
    align-items:center;
    gap:8px;
    color:#d9e7ff;
    font-size:13px;
    font-weight:600;
  }
  .dashboard-filter-select{
    min-width:116px;
    height:34px;
    padding:0 12px;
    border-radius:10px;
    border:1px solid rgba(146, 179, 255, 0.22);
    background:#111a2d;
    color:#f3f7ff;
    font-size:13px;
  }
  .dashboard-count-badge{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:7px 11px;
    border-radius:999px;
    border:1px solid rgba(146, 179, 255, 0.22);
    background:#111a2d;
    color:#d9e7ff;
    font-size:12px;
    font-weight:700;
  }
  .dashboard-summary{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(150px, 1fr));
    gap:12px;
    margin-bottom:16px;
  }
  .dashboard-summary-card{
    border:1px solid rgba(146, 179, 255, 0.18);
    border-radius:14px;
    background:rgba(8, 20, 38, 0.78);
    padding:14px 16px;
    color:#f3f7ff;
  }
  .dashboard-summary-card h3{
    margin:0 0 8px 0;
    font-size:13px;
    color:#b5c9f7;
    font-weight:700;
  }
  .dashboard-summary-value{
    font-size:24px;
    line-height:1.15;
    font-weight:800;
  }
  .dashboard-summary-sub{
    margin-top:6px;
    font-size:12px;
    color:#8fa8da;
  }
  .dashboard-board{
    display:flex;
    flex-direction:column;
    gap:18px;
  }
  .dashboard-model{
    border:1px solid rgba(146, 179, 255, 0.18);
    border-radius:16px;
    background:rgba(8, 20, 38, 0.76);
    overflow:hidden;
  }
  .dashboard-model-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:12px 16px;
    border-bottom:1px solid rgba(146, 179, 255, 0.15);
    background:rgba(17, 28, 48, 0.82);
    color:#f3f7ff;
    font-weight:800;
  }
  .dashboard-model-count{
    color:#9db7ea;
    font-size:12px;
    font-weight:700;
  }
  .dashboard-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));
    gap:12px;
    padding:14px;
  }
  .dashboard-entry{
    border:1px solid rgba(146, 179, 255, 0.18);
    border-radius:16px;
    background:linear-gradient(180deg, rgba(7, 19, 38, 0.95), rgba(4, 15, 31, 0.92));
    padding:14px;
    cursor:pointer;
    transition:border-color .16s ease, transform .16s ease, box-shadow .16s ease;
    min-height:128px;
  }
  .dashboard-entry:hover{
    border-color:rgba(108, 160, 255, 0.42);
    transform:translateY(-1px);
  }
  .dashboard-entry.active{
    border-color:#6ca0ff;
    box-shadow:0 0 0 2px rgba(108,160,255,.18) inset;
  }
  .dashboard-entry-top{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
  }
  .dashboard-entry-title{
    margin:0;
    font-size:24px;
    line-height:1;
    font-weight:800;
    color:#f3f7ff;
  }
  .dashboard-entry-meta{
    margin-top:8px;
    color:#9db7ea;
    font-size:12px;
    line-height:1.35;
    min-height:18px;
  }
  .dashboard-entry-issue{
    margin-top:18px;
    color:#eaf2ff;
    font-size:17px;
    font-weight:700;
    line-height:1.35;
    word-break:keep-all;
    white-space:pre-wrap;
  }
  .dashboard-status-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:88px;
    height:36px;
    padding:0 12px;
    border:none;
    border-radius:999px;
    color:#fff;
    font-size:13px;
    font-weight:800;
    cursor:pointer;
    box-shadow:inset 0 0 0 1px rgba(255,255,255,.18);
  }
  .dashboard-status-btn.status-blocked{ background:#d53333; }
  .dashboard-status-btn.status-available{ background:#1ea852; }
  .dashboard-log-panel{
    margin-top:18px;
    border:1px solid rgba(146, 179, 255, 0.18);
    border-radius:16px;
    background:rgba(8, 20, 38, 0.78);
    overflow:hidden;
  }
  .dashboard-log-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:12px 16px;
    border-bottom:1px solid rgba(146, 179, 255, 0.15);
    background:rgba(17, 28, 48, 0.82);
    color:#f3f7ff;
    font-weight:800;
  }
  .dashboard-log-body{
    padding:14px 16px;
  }
  .dashboard-log-empty{
    color:#9db7ea;
    font-size:13px;
  }
  .dashboard-log-list{
    display:flex;
    flex-direction:column;
    gap:10px;
  }
  .dashboard-log-item{
    padding:12px 14px;
    border:1px solid rgba(146, 179, 255, 0.15);
    border-radius:12px;
    background:rgba(14, 29, 55, 0.66);
  }
  .dashboard-log-item-top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:6px;
    color:#f3f7ff;
    font-size:13px;
    font-weight:700;
  }
  .dashboard-log-note{
    color:#c6d7f7;
    font-size:13px;
    line-height:1.45;
    white-space:pre-wrap;
    word-break:keep-all;
  }
  .dashboard-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:74px;
    height:28px;
    padding:0 10px;
    border-radius:999px;
    color:#fff;
    font-size:12px;
    font-weight:800;
  }
  .dashboard-pill.status-blocked{ background:#d53333; }
  .dashboard-pill.status-available{ background:#1ea852; }
  .dashboard-empty-state{
    padding:30px 16px;
    text-align:center;
    color:#9db7ea;
    font-size:14px;
    border:1px dashed rgba(146,179,255,.18);
    border-radius:16px;
    background:rgba(8,20,38,.42);
  }
</style>

<div id="customerHoldDashboardPanel" class="dashboard-panel">
  <div class="dashboard-panel-headline">홀딩 현황판</div>

  <div class="dashboard-toolbar">
    <div class="dashboard-toolbar-left">
      <div class="dashboard-filter-group">
        <span>구분</span>
        <select id="dashboardVendorFilter" class="dashboard-filter-select">
          <option value="ALL">전체</option>
          <option value="LG">LG</option>
          <option value="자화">자화</option>
        </select>
      </div>
      <div class="dashboard-filter-group">
        <span>상태</span>
        <select id="dashboardStatusFilter" class="dashboard-filter-select">
          <option value="ALL">전체</option>
          <option value="blocked">출하불가</option>
          <option value="available">출하가능</option>
        </select>
      </div>
    </div>
    <div id="dashboardCountBadge" class="dashboard-count-badge">표시 0 / 전체 0</div>
  </div>

  <div id="dashboardSummary" class="dashboard-summary"></div>
  <div id="dashboardBoard" class="dashboard-board"></div>
  <div id="dashboardLogPanel" class="dashboard-log-panel"></div>
</div>
