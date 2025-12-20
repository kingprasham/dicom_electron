<?php
/**
 * Super Admin Print Analytics Dashboard
 * Comprehensive print tracking and billing analytics
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../includes/LicenseManager.php';
require_once __DIR__ . '/../includes/PrintTracker.php';
require_once __DIR__ . '/../includes/BillingManager.php';

requireLogin('../login.php');

// Check if super admin
$db = getDbConnection();
$stmt = $db->prepare("SELECT is_super_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$result || !$result['is_super_admin']) {
    header('Location: ../pages/patients.html');
    exit;
}

// Date range
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get license manager data
$licenseManager = new LicenseManager();
$licenses = $licenseManager->getAllLicenses();
$billingManager = new BillingManager();

// Get overall stats
$stmt = $db->prepare("
    SELECT
        COUNT(*) as total_prints,
        SUM(total_pages) as total_pages,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_prints,
        SUM(COALESCE(total_cost, 0)) as total_cost,
        SUM(CASE WHEN billed = 0 THEN COALESCE(total_cost, 0) ELSE 0 END) as unbilled_cost,
        COUNT(DISTINCT license_key) as active_hospitals,
        COUNT(DISTINCT machine_id) as active_machines,
        COUNT(DISTINCT location_id) as active_locations
    FROM print_logs
    WHERE DATE(queued_at) BETWEEN ? AND ?
");
$stmt->bind_param("ss", $dateFrom, $dateTo);
$stmt->execute();
$overallStats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Today's stats
$today = date('Y-m-d');
$stmt = $db->prepare("
    SELECT COUNT(*) as prints_today, SUM(total_pages) as pages_today, SUM(COALESCE(total_cost, 0)) as cost_today
    FROM print_logs WHERE DATE(queued_at) = ?
");
$stmt->bind_param("s", $today);
$stmt->execute();
$todayStats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get stats by hospital
$hospitalStats = [];
$stmt = $db->prepare("
    SELECT
        l.id as license_id,
        l.customer_name,
        l.customer_hospital,
        COUNT(pl.id) as total_prints,
        SUM(pl.total_pages) as total_pages,
        SUM(COALESCE(pl.total_cost, 0)) as total_cost,
        SUM(CASE WHEN pl.billed = 0 THEN COALESCE(pl.total_cost, 0) ELSE 0 END) as unbilled_amount,
        MAX(pl.queued_at) as last_print_at
    FROM licenses l
    LEFT JOIN print_logs pl ON l.license_key = pl.license_key
        AND DATE(pl.queued_at) BETWEEN ? AND ?
    WHERE l.is_active = 1
    GROUP BY l.id
    ORDER BY total_pages DESC
");
$stmt->bind_param("ss", $dateFrom, $dateTo);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $hospitalStats[] = $row;
}
$stmt->close();

// Get stats by location
$locationStats = [];
$stmt = $db->prepare("
    SELECT
        loc.id,
        loc.location_code,
        loc.location_name,
        loc.department,
        l.customer_hospital,
        COUNT(pl.id) as total_prints,
        SUM(pl.total_pages) as total_pages,
        SUM(COALESCE(pl.total_cost, 0)) as total_cost
    FROM locations loc
    LEFT JOIN print_logs pl ON loc.id = pl.location_id
        AND DATE(pl.queued_at) BETWEEN ? AND ?
    LEFT JOIN licenses l ON pl.license_key = l.license_key
    GROUP BY loc.id
    HAVING total_prints > 0
    ORDER BY total_pages DESC
    LIMIT 20
");
$stmt->bind_param("ss", $dateFrom, $dateTo);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $locationStats[] = $row;
}
$stmt->close();

// Get daily trend
$trendData = [];
$stmt = $db->prepare("
    SELECT DATE(queued_at) as date, COUNT(*) as prints, SUM(total_pages) as pages, SUM(COALESCE(total_cost, 0)) as cost
    FROM print_logs
    WHERE DATE(queued_at) BETWEEN ? AND ?
    GROUP BY DATE(queued_at)
    ORDER BY date ASC
");
$stmt->bind_param("ss", $dateFrom, $dateTo);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $trendData[] = $row;
}
$stmt->close();

// Get billing summary
$billingSummary = $billingManager->getBillingSummary();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>Print Analytics - Super Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: linear-gradient(135deg, #1a0a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            color: #fff;
        }
        .navbar-super {
            background: rgba(138, 43, 226, 0.2);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(138, 43, 226, 0.3);
        }
        .super-badge {
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
        }
        .stat-card:hover {
            border-color: rgba(138, 43, 226, 0.5);
            transform: translateY(-2px);
        }
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: bold;
            background: linear-gradient(135deg, #8b5cf6, #06b6d4);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .stat-card .stat-label {
            color: #9ca3af;
            font-size: 0.9rem;
        }
        .card-custom {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
        }
        .table-analytics {
            --bs-table-bg: transparent;
            --bs-table-color: #fff;
            --bs-table-border-color: rgba(255, 255, 255, 0.1);
        }
        .progress-bar-custom {
            height: 8px;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.1);
        }
        .progress-bar-fill {
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(90deg, #8b5cf6, #06b6d4);
        }
        .hospital-row {
            background: rgba(255, 255, 255, 0.02);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .hospital-row:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(138, 43, 226, 0.3);
        }
        .currency {
            font-family: monospace;
        }
        .filter-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 15px;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-super mb-4">
        <div class="container-fluid">
            <a class="navbar-brand text-white d-flex align-items-center gap-2" href="index.php">
                <i class="bi bi-shield-fill-check" style="color: #8b5cf6;"></i>
                <span>DICOM Viewer</span>
                <span class="super-badge">SUPER ADMIN</span>
            </a>
            <div class="d-flex align-items-center gap-3">
                <a href="index.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-house"></i> Dashboard
                </a>
                <span class="text-light">
                    <i class="bi bi-person-fill-gear"></i>
                    <?= htmlspecialchars($_SESSION['username']) ?>
                </span>
                <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <!-- Header with Date Filter -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="bi bi-printer-fill text-info me-2"></i>Print Analytics & Billing</h4>
                <p class="text-muted mb-0">Track print usage across all hospitals for billing</p>
            </div>
            <div class="filter-card d-flex align-items-center gap-3">
                <div class="d-flex align-items-center gap-2">
                    <label class="text-muted small">From:</label>
                    <input type="date" class="form-control form-control-sm" id="dateFrom" value="<?= $dateFrom ?>">
                </div>
                <div class="d-flex align-items-center gap-2">
                    <label class="text-muted small">To:</label>
                    <input type="date" class="form-control form-control-sm" id="dateTo" value="<?= $dateTo ?>">
                </div>
                <button class="btn btn-primary btn-sm" onclick="applyFilter()">
                    <i class="bi bi-funnel"></i> Apply
                </button>
                <div class="btn-group">
                    <button class="btn btn-outline-light btn-sm" onclick="setQuickDate(7)">7D</button>
                    <button class="btn btn-outline-light btn-sm" onclick="setQuickDate(30)">30D</button>
                    <button class="btn btn-outline-light btn-sm" onclick="setQuickDate(90)">90D</button>
                </div>
            </div>
        </div>

        <!-- Top Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-2 mb-3">
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($todayStats['prints_today'] ?? 0) ?></div>
                    <div class="stat-label">Today's Prints</div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($overallStats['total_prints'] ?? 0) ?></div>
                    <div class="stat-label">Total Prints</div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($overallStats['total_pages'] ?? 0) ?></div>
                    <div class="stat-label">Total Pages</div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="stat-card">
                    <div class="stat-value currency">&#8377;<?= number_format($overallStats['total_cost'] ?? 0, 0) ?></div>
                    <div class="stat-label">Total Revenue</div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="stat-card">
                    <div class="stat-value text-warning currency">&#8377;<?= number_format($overallStats['unbilled_cost'] ?? 0, 0) ?></div>
                    <div class="stat-label">Pending Billing</div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?= $overallStats['active_hospitals'] ?? 0 ?></div>
                    <div class="stat-label">Active Hospitals</div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Print Trend Chart -->
            <div class="col-lg-8 mb-4">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="mb-0"><i class="bi bi-graph-up text-primary me-2"></i>Print Trend</h5>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-light active" onclick="setChartType('pages')">Pages</button>
                            <button class="btn btn-outline-light" onclick="setChartType('cost')">Revenue</button>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Billing Summary -->
            <div class="col-lg-4 mb-4">
                <div class="card-custom p-4">
                    <h5 class="mb-4"><i class="bi bi-receipt text-success me-2"></i>Billing Summary</h5>
                    <div class="mb-4">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Total Invoices</span>
                            <span class="fw-bold"><?= $billingSummary['total_invoices'] ?? 0 ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Pending</span>
                            <span class="text-warning"><?= $billingSummary['pending_invoices'] ?? 0 ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Paid</span>
                            <span class="text-success"><?= $billingSummary['paid_invoices'] ?? 0 ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Overdue</span>
                            <span class="text-danger"><?= $billingSummary['overdue_invoices'] ?? 0 ?></span>
                        </div>
                    </div>
                    <hr class="border-secondary">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Pending Amount</span>
                            <span class="text-warning currency">&#8377;<?= number_format($billingSummary['pending_amount'] ?? 0, 0) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Paid Amount</span>
                            <span class="text-success currency">&#8377;<?= number_format($billingSummary['paid_amount'] ?? 0, 0) ?></span>
                        </div>
                    </div>
                    <a href="billing.php" class="btn btn-outline-primary w-100">
                        <i class="bi bi-file-earmark-text me-2"></i>Manage Invoices
                    </a>
                </div>
            </div>
        </div>

        <!-- Hospital-wise Stats -->
        <div class="row">
            <div class="col-lg-7 mb-4">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="mb-0"><i class="bi bi-hospital text-info me-2"></i>Prints by Hospital</h5>
                        <button class="btn btn-outline-success btn-sm" onclick="exportHospitalData()">
                            <i class="bi bi-download me-1"></i>Export CSV
                        </button>
                    </div>

                    <?php if (empty($hospitalStats)): ?>
                        <p class="text-muted text-center py-4">No print data for selected period</p>
                    <?php else: ?>
                        <?php
                        $maxPages = max(array_column($hospitalStats, 'total_pages') ?: [1]);
                        foreach ($hospitalStats as $hospital):
                            $percentage = $maxPages > 0 ? (($hospital['total_pages'] ?? 0) / $maxPages * 100) : 0;
                        ?>
                            <div class="hospital-row">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-0"><?= htmlspecialchars($hospital['customer_hospital'] ?: $hospital['customer_name'] ?: 'Unknown') ?></h6>
                                        <small class="text-muted"><?= htmlspecialchars($hospital['customer_name'] ?? '') ?></small>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold text-info"><?= number_format($hospital['total_pages'] ?? 0) ?> pages</div>
                                        <small class="text-success currency">&#8377;<?= number_format($hospital['total_cost'] ?? 0, 0) ?></small>
                                    </div>
                                </div>
                                <div class="progress-bar-custom">
                                    <div class="progress-bar-fill" style="width: <?= $percentage ?>%"></div>
                                </div>
                                <div class="d-flex justify-content-between mt-2">
                                    <small class="text-muted"><?= number_format($hospital['total_prints'] ?? 0) ?> print jobs</small>
                                    <?php if (($hospital['unbilled_amount'] ?? 0) > 0): ?>
                                        <small class="text-warning">
                                            <i class="bi bi-exclamation-circle"></i>
                                            Unbilled: &#8377;<?= number_format($hospital['unbilled_amount'], 0) ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Location-wise Stats -->
            <div class="col-lg-5 mb-4">
                <div class="card-custom p-4">
                    <h5 class="mb-4"><i class="bi bi-geo-alt text-warning me-2"></i>Top Locations by Prints</h5>

                    <?php if (empty($locationStats)): ?>
                        <p class="text-muted text-center py-4">No location data for selected period</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-analytics table-sm">
                                <thead>
                                    <tr>
                                        <th>Location</th>
                                        <th>Hospital</th>
                                        <th class="text-end">Pages</th>
                                        <th class="text-end">Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($locationStats as $loc): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-secondary"><?= htmlspecialchars($loc['location_code']) ?></span>
                                                <br><small><?= htmlspecialchars($loc['location_name']) ?></small>
                                            </td>
                                            <td><small class="text-muted"><?= htmlspecialchars($loc['customer_hospital'] ?? 'N/A') ?></small></td>
                                            <td class="text-end"><?= number_format($loc['total_pages'] ?? 0) ?></td>
                                            <td class="text-end currency">&#8377;<?= number_format($loc['total_cost'] ?? 0, 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="card-custom p-4 mt-4">
                    <h5 class="mb-3"><i class="bi bi-lightning text-warning me-2"></i>Quick Actions</h5>
                    <div class="d-grid gap-2">
                        <a href="billing.php" class="btn btn-outline-success">
                            <i class="bi bi-receipt me-2"></i>Generate Invoice
                        </a>
                        <a href="pricing.php" class="btn btn-outline-info">
                            <i class="bi bi-currency-rupee me-2"></i>Manage Pricing
                        </a>
                        <a href="machines.php" class="btn btn-outline-primary">
                            <i class="bi bi-pc-display me-2"></i>View All Machines
                        </a>
                        <button class="btn btn-outline-warning" onclick="exportFullReport()">
                            <i class="bi bi-file-earmark-spreadsheet me-2"></i>Export Full Report
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
        const trendData = <?= json_encode($trendData) ?>;
        let trendChart = null;
        let chartType = 'pages';

        // Initialize chart
        document.addEventListener('DOMContentLoaded', () => {
            initTrendChart();
        });

        function initTrendChart() {
            const ctx = document.getElementById('trendChart').getContext('2d');

            const labels = trendData.map(d => {
                const date = new Date(d.date);
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            });

            const data = chartType === 'pages'
                ? trendData.map(d => d.pages || 0)
                : trendData.map(d => d.cost || 0);

            if (trendChart) {
                trendChart.destroy();
            }

            trendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: chartType === 'pages' ? 'Pages Printed' : 'Revenue (INR)',
                        data: data,
                        borderColor: '#8b5cf6',
                        backgroundColor: 'rgba(139, 92, 246, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 3,
                        pointBackgroundColor: '#8b5cf6'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            callbacks: {
                                label: function(context) {
                                    const value = context.raw;
                                    return chartType === 'pages'
                                        ? `${value.toLocaleString()} pages`
                                        : `₹${value.toLocaleString()}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: 'rgba(255, 255, 255, 0.05)'
                            },
                            ticks: {
                                color: '#9ca3af'
                            }
                        },
                        y: {
                            grid: {
                                color: 'rgba(255, 255, 255, 0.05)'
                            },
                            ticks: {
                                color: '#9ca3af',
                                callback: function(value) {
                                    return chartType === 'pages'
                                        ? value.toLocaleString()
                                        : '₹' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }

        function setChartType(type) {
            chartType = type;
            document.querySelectorAll('.btn-group .btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            initTrendChart();
        }

        function applyFilter() {
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            window.location.href = `?date_from=${dateFrom}&date_to=${dateTo}`;
        }

        function setQuickDate(days) {
            const today = new Date();
            const fromDate = new Date(today);
            fromDate.setDate(fromDate.getDate() - days);

            document.getElementById('dateFrom').value = fromDate.toISOString().split('T')[0];
            document.getElementById('dateTo').value = today.toISOString().split('T')[0];
            applyFilter();
        }

        function exportHospitalData() {
            const data = <?= json_encode($hospitalStats) ?>;

            let csv = 'Hospital,Customer,Total Prints,Total Pages,Total Cost,Unbilled Amount\n';
            data.forEach(h => {
                csv += `"${h.customer_hospital || ''}","${h.customer_name || ''}",${h.total_prints || 0},${h.total_pages || 0},${h.total_cost || 0},${h.unbilled_amount || 0}\n`;
            });

            downloadCSV(csv, 'hospital_print_stats.csv');
        }

        function exportFullReport() {
            window.location.href = `${basePath}/api/super-admin/export-report.php?date_from=${document.getElementById('dateFrom').value}&date_to=${document.getElementById('dateTo').value}`;
        }

        function downloadCSV(csv, filename) {
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            a.click();
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>
