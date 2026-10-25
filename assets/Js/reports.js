// assets/js/reports.js
// Reports page JavaScript

let modalInstance = null;

// ========================================
// INITIALIZATION
// ========================================
function initReports() {
    console.log('📊 Reports initialized');
    
    // Initialize charts
    initCharts();
    
    // Initialize sidebar toggle
    initSidebar();
    
    // Initialize sticky header shrink
    initStickyHeader();
    
    // Initialize modal
    initModal();
}

// ========================================
// SIDEBAR
// ========================================
function initSidebar() {
    const sidebar = document.getElementById('sidebar-wrapper');
    const menuToggle = document.getElementById('menuToggle');
    const mobileToggle = document.getElementById('mobileMenuToggle');
    const overlay = document.getElementById('sidebarOverlay');
    
    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        });
    }
    
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
        });
    }
    
    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
        });
    }
    
    if (localStorage.getItem('sidebarCollapsed') === 'true' && window.innerWidth > 768) {
        sidebar.classList.add('collapsed');
    }
}

// ========================================
// STICKY HEADER SHRINK
// ========================================
function initStickyHeader() {
    const wrapper = document.getElementById('page-content-wrapper');
    if (!wrapper) return;
    
    wrapper.addEventListener('scroll', function() {
        const scrollY = this.scrollTop;
        const maxShrink = 120;
        const progress = Math.min(scrollY / maxShrink, 1);
        
        const statsGrid = document.getElementById('statsGrid');
        const toolbarRow = document.getElementById('toolbarRow');
        const periodSelector = document.getElementById('periodSelector');
        const reportTabs = document.getElementById('reportTabs');
        
        if (progress > 0.05) {
            statsGrid?.classList.add('shrunk');
            document.querySelectorAll('.stat-card').forEach(card => card.classList.add('shrunk'));
            toolbarRow?.classList.add('shrunk');
            periodSelector?.classList.add('shrunk');
            reportTabs?.classList.add('shrunk');
        } else {
            statsGrid?.classList.remove('shrunk');
            document.querySelectorAll('.stat-card').forEach(card => card.classList.remove('shrunk'));
            toolbarRow?.classList.remove('shrunk');
            periodSelector?.classList.remove('shrunk');
            reportTabs?.classList.remove('shrunk');
        }
    });
    
    // Initial check
    wrapper.dispatchEvent(new Event('scroll'));
}

// ========================================
// MODAL
// ========================================
function initModal() {
    const modalElement = document.getElementById('detailModal');
    if (!modalElement) return;
    
    modalInstance = new bootstrap.Modal(modalElement);
}

function openModal(type, title, data, chartType) {
    if (!modalInstance) {
        initModal();
    }
    
    document.getElementById('modalTitle').textContent = title;
    
    let html = '';
    
    if (chartType === 'table' || ['top_payers', 'aging_report', 'new_members', 'inactive_members'].includes(type)) {
        html = buildTableHtml(data);
    } else if (type === 'payment_methods') {
        html = buildPaymentMethodsHtml(data);
    } else {
        html = buildChartHtml(type, title, data, chartType);
    }
    
    document.getElementById('modalBody').innerHTML = html;
    modalInstance.show();
    
    // Render chart after modal is shown
    setTimeout(() => {
        if (type === 'payment_methods') {
            renderModalPieChart(data);
        } else if (['collections', 'member_growth', 'chapter_performance'].includes(type)) {
            renderModalChart(data, chartType, type);
        }
    }, 300);
}

function buildTableHtml(data) {
    if (!data || data.length === 0) {
        return '<div class="text-center text-muted py-4">No data available</div>';
    }
    
    let html = '<div class="table-responsive"><table class="table table-striped table-hover"><thead><tr>';
    Object.keys(data[0]).forEach(key => {
        const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        html += `<th>${label}</th>`;
    });
    html += '</tr></thead><tbody>';
    
    data.forEach(item => {
        html += '<tr>';
        Object.values(item).forEach(value => {
            if (typeof value === 'number' && value > 1000) {
                html += `<td>₱${Number(value).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>`;
            } else {
                html += `<td>${value || '-'}</td>`;
            }
        });
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    html += `<div class="text-muted small mt-2">Showing ${data.length} records</div>`;
    return html;
}

function buildPaymentMethodsHtml(data) {
    if (!data || data.length === 0) {
        return '<div class="text-center text-muted py-4">No data available</div>';
    }
    
    let html = '<div class="chart-container-modal"><canvas id="modalChart"></canvas></div>';
    html += '<div class="row mt-3">';
    data.forEach(item => {
        const method = item.payment_method || 'Other';
        const total = Number(item.total).toLocaleString('en-US', {minimumFractionDigits: 2});
        html += `<div class="col-6 col-md-4 mb-2">
            <div class="d-flex justify-content-between border-bottom py-2">
                <span class="fw-bold">${method.toUpperCase()}</span>
                <span class="text-success">₱${total}</span>
            </div>
        </div>`;
    });
    html += '</div>';
    return html;
}

function buildChartHtml(type, title, data, chartType) {
    return `<div class="chart-container-modal"><canvas id="modalChart"></canvas></div>
        <div class="text-muted small mt-2">Chart: ${title}</div>`;
}

// ========================================
// MODAL CHARTS
// ========================================
function renderModalChart(data, chartType, dataType) {
    const canvas = document.getElementById('modalChart');
    if (!canvas) return;
    
    let labels = [], values = [], label = '';
    
    if (dataType === 'collections' || dataType === 'monthly') {
        labels = data.map(d => d.month || '');
        values = data.map(d => d.total || 0);
        label = 'Collections (₱)';
    } else if (dataType === 'member_growth') {
        labels = data.map(d => d.month || '');
        values = data.map(d => d.count || 0);
        label = 'Total Members';
    } else if (dataType === 'chapter_performance') {
        labels = data.map(d => d.chapter || '');
        values = data.map(d => parseFloat(d.collection_rate) || 0);
        label = 'Collection Rate (%)';
    } else {
        return;
    }
    
    try {
        new Chart(canvas, {
            type: chartType === 'line' ? 'line' : 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: label,
                    data: values,
                    backgroundColor: chartType === 'line' ? 'rgba(40,167,69,0.1)' : 'rgba(55,90,127,0.6)',
                    borderColor: chartType === 'line' ? '#28a745' : '#375a7f',
                    borderWidth: 2,
                    fill: chartType === 'line',
                    tension: 0.4,
                    pointRadius: chartType === 'line' ? 3 : 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    } catch(e) {
        console.error('Chart error:', e);
    }
}

function renderModalPieChart(data) {
    const canvas = document.getElementById('modalChart');
    if (!canvas) return;
    
    try {
        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.map(d => d.payment_method ? d.payment_method.toUpperCase() : 'Other'),
                datasets: [{
                    data: data.map(d => d.total || 0),
                    backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545', '#6c757d'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
                }
            }
        });
    } catch(e) {
        console.error('Pie chart error:', e);
    }
}

// ========================================
// CHARTS
// ========================================
function initCharts() {
    const data = window.reportData || {};
    
    // Collections Chart
    if (document.getElementById('collectionsChart')) {
        new Chart(document.getElementById('collectionsChart'), {
            type: 'bar',
            data: {
                labels: data.monthly?.map(d => d.month) || [],
                datasets: [{
                    label: 'Collections (₱)',
                    data: data.monthly?.map(d => d.total) || [],
                    backgroundColor: 'rgba(55,90,127,0.65)',
                    borderColor: '#375a7f',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: v => '₱' + (v/1000).toFixed(0) + 'k' } }
                }
            }
        });
    }
    
    // Payment Methods Chart
    if (document.getElementById('paymentMethodsChart')) {
        const pm = data.paymentMethods || [];
        new Chart(document.getElementById('paymentMethodsChart'), {
            type: 'doughnut',
            data: {
                labels: pm.map(m => m.payment_method ? m.payment_method.toUpperCase() : 'Other'),
                datasets: [{
                    data: pm.map(m => m.total || 0),
                    backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545', '#6c757d'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 } } }
                }
            }
        });
    }
    
    // Member Growth Chart
    if (document.getElementById('memberGrowthChart')) {
        const mg = data.memberGrowth || [];
        new Chart(document.getElementById('memberGrowthChart'), {
            type: 'line',
            data: {
                labels: mg.map(d => d.month) || [],
                datasets: [{
                    label: 'Total Members',
                    data: mg.map(d => d.count) || [],
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40,167,69,0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#28a745',
                    pointRadius: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }
    
    // Chapter Performance Chart
    if (document.getElementById('chapterPerformanceChart')) {
        const cp = data.chapterPerformance || [];
        new Chart(document.getElementById('chapterPerformanceChart'), {
            type: 'bar',
            data: {
                labels: cp.map(c => c.chapter) || [],
                datasets: [{
                    label: 'Collection Rate (%)',
                    data: cp.map(c => Math.min(c.collection_rate || 0, 100)),
                    backgroundColor: 'rgba(23,162,184,0.65)',
                    borderColor: '#17a2b8',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
                }
            }
        });
    }
}

// ========================================
// FILTERS
// ========================================
function applyFilters() {
    const chapter = document.getElementById('filterChapter').value;
    const status = document.getElementById('filterStatus').value;
    const url = new URL(window.location.href);
    if (chapter) url.searchParams.set('filter_chapter', chapter);
    else url.searchParams.delete('filter_chapter');
    if (status) url.searchParams.set('filter_status', status);
    else url.searchParams.delete('filter_status');
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}

function clearFilters() {
    const url = new URL(window.location.href);
    url.searchParams.delete('filter_chapter');
    url.searchParams.delete('filter_status');
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}

// ========================================
// EXPORT
// ========================================
function exportData(format) {
    const url = new URL(window.location.href);
    url.searchParams.set('export', format);
    window.location.href = url.toString();
}

// ========================================
// VIEW MEMBER
// ========================================
function viewMember(memberCode) {
    window.location.href = `../members.php?view_code=${memberCode}`;
}

console.log('✅ Reports JS loaded');