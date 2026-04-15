<section id="dashboardTab">
    <div class="sheet-panel">
        <div class="sheet-head">
            <div class="sheet-title">홀딩 현황판</div>
            <div class="dashboard-filter-bar">
                <label class="dashboard-filter">
                    <span>구분</span>
                    <div class="select-wrap">
                        <select id="dashboardVendorFilter" class="status-select">
                            <option value="all">전체</option>
                            <option value="LGIT">LG</option>
                            <option value="자화">자화</option>
                        </select>
                    </div>
                </label>
                <label class="dashboard-filter">
                    <span>상태</span>
                    <div class="select-wrap">
                        <select id="dashboardStatusFilter" class="status-select">
                            <option value="blocked">출하불가</option>
                            <option value="available">출하가능</option>
                        </select>
                    </div>
                </label>
            </div>
        </div>
        <div id="dashboardRoot"></div>
    </div>
</section>
