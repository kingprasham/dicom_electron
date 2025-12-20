<?php
/**
 * Super Admin Billing Management
 * Generate and manage invoices for hospitals
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../includes/LicenseManager.php';
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

$licenseManager = new LicenseManager();
$billingManager = new BillingManager();

// Get all licenses for dropdown
$licenses = $licenseManager->getAllLicenses();

// Get recent invoices
$invoices = $billingManager->getAllInvoices(null, 50);

// Get billing summary
$billingSummary = $billingManager->getBillingSummary();

// Get unbilled amounts per license
$unbilledData = [];
foreach ($licenses as $lic) {
    $unbilled = $billingManager->getUnbilledAmount($lic['license_key']);
    if ($unbilled['amount'] > 0) {
        $unbilledData[$lic['id']] = $unbilled;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>Billing Management - Super Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
        .card-custom {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
        }
        .table-billing {
            --bs-table-bg: transparent;
            --bs-table-color: #fff;
            --bs-table-border-color: rgba(255, 255, 255, 0.1);
        }
        .invoice-number {
            font-family: monospace;
            background: rgba(13, 110, 253, 0.2);
            padding: 2px 8px;
            border-radius: 4px;
        }
        .currency {
            font-family: monospace;
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
                <a href="print-analytics.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-bar-chart"></i> Analytics
                </a>
                <a href="index.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-house"></i> Dashboard
                </a>
                <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="bi bi-receipt text-success me-2"></i>Billing & Invoices</h4>
                <p class="text-muted mb-0">Generate and manage invoices for hospital print billing</p>
            </div>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#generateInvoiceModal">
                <i class="bi bi-plus-lg me-2"></i>Generate Invoice
            </button>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="stat-value text-info"><?= $billingSummary['total_invoices'] ?? 0 ?></div>
                    <div class="text-muted">Total Invoices</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="stat-value text-warning currency">&#8377;<?= number_format($billingSummary['pending_amount'] ?? 0, 0) ?></div>
                    <div class="text-muted">Pending Amount</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="stat-value text-success currency">&#8377;<?= number_format($billingSummary['paid_amount'] ?? 0, 0) ?></div>
                    <div class="text-muted">Paid Amount</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?= $billingSummary['overdue_invoices'] ?? 0 ?></div>
                    <div class="text-muted">Overdue Invoices</div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Invoices List -->
            <div class="col-lg-8 mb-4">
                <div class="card-custom p-4">
                    <h5 class="mb-4"><i class="bi bi-file-earmark-text me-2"></i>Recent Invoices</h5>

                    <?php if (empty($invoices)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-receipt display-4 text-muted"></i>
                            <p class="text-muted mt-3">No invoices generated yet</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-billing table-hover">
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Customer</th>
                                        <th>Period</th>
                                        <th>Pages</th>
                                        <th class="text-end">Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($invoices as $inv): ?>
                                        <tr>
                                            <td><span class="invoice-number"><?= htmlspecialchars($inv['invoice_number']) ?></span></td>
                                            <td>
                                                <?= htmlspecialchars($inv['customer_hospital'] ?: $inv['customer_name']) ?>
                                            </td>
                                            <td>
                                                <small>
                                                    <?= date('M j', strtotime($inv['billing_period_start'])) ?> -
                                                    <?= date('M j, Y', strtotime($inv['billing_period_end'])) ?>
                                                </small>
                                            </td>
                                            <td><?= number_format($inv['total_pages']) ?></td>
                                            <td class="text-end currency">&#8377;<?= number_format($inv['total_amount'], 0) ?></td>
                                            <td>
                                                <?php
                                                $statusColors = [
                                                    'draft' => 'secondary',
                                                    'generated' => 'info',
                                                    'sent' => 'primary',
                                                    'paid' => 'success',
                                                    'overdue' => 'danger',
                                                    'cancelled' => 'dark'
                                                ];
                                                ?>
                                                <span class="badge bg-<?= $statusColors[$inv['status']] ?? 'secondary' ?>">
                                                    <?= strtoupper($inv['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-info" onclick="viewInvoice(<?= $inv['id'] ?>)" title="View">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <?php if ($inv['status'] !== 'paid' && $inv['status'] !== 'cancelled'): ?>
                                                        <button class="btn btn-outline-success" onclick="markPaid(<?= $inv['id'] ?>)" title="Mark Paid">
                                                            <i class="bi bi-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($inv['status'] !== 'cancelled'): ?>
                                                        <button class="btn btn-outline-danger" onclick="cancelInvoice(<?= $inv['id'] ?>)" title="Cancel">
                                                            <i class="bi bi-x"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Unbilled Summary -->
            <div class="col-lg-4 mb-4">
                <div class="card-custom p-4">
                    <h5 class="mb-4"><i class="bi bi-clock-history text-warning me-2"></i>Pending Billing</h5>

                    <?php if (empty($unbilledData)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-check-circle text-success display-4"></i>
                            <p class="text-muted mt-3">All prints are billed!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($licenses as $lic):
                            if (!isset($unbilledData[$lic['id']])) continue;
                            $unbilled = $unbilledData[$lic['id']];
                        ?>
                            <div class="d-flex justify-content-between align-items-center p-3 mb-2" style="background: rgba(255,193,7,0.1); border-radius: 8px;">
                                <div>
                                    <div class="fw-medium"><?= htmlspecialchars($lic['customer_hospital'] ?: $lic['customer_name'] ?: 'Unknown') ?></div>
                                    <small class="text-muted"><?= number_format($unbilled['pages']) ?> pages</small>
                                </div>
                                <div class="text-end">
                                    <div class="text-warning currency fw-bold">&#8377;<?= number_format($unbilled['amount'], 0) ?></div>
                                    <button class="btn btn-sm btn-warning mt-1" onclick="quickInvoice(<?= $lic['id'] ?>)">
                                        <i class="bi bi-receipt"></i> Invoice
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Pricing -->
                <div class="card-custom p-4 mt-4">
                    <h5 class="mb-3"><i class="bi bi-currency-rupee text-info me-2"></i>Current Pricing</h5>
                    <div class="small">
                        <div class="d-flex justify-content-between py-2 border-bottom border-secondary">
                            <span>A4 Grayscale</span>
                            <span class="currency">&#8377;5.00 /page</span>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom border-secondary">
                            <span>A4 Color</span>
                            <span class="currency">&#8377;10.00 /page</span>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom border-secondary">
                            <span>A3 Grayscale</span>
                            <span class="currency">&#8377;10.00 /page</span>
                        </div>
                        <div class="d-flex justify-content-between py-2">
                            <span>A3 Color</span>
                            <span class="currency">&#8377;20.00 /page</span>
                        </div>
                    </div>
                    <a href="pricing.php" class="btn btn-outline-info btn-sm w-100 mt-3">
                        <i class="bi bi-pencil me-2"></i>Edit Pricing
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Generate Invoice Modal -->
    <div class="modal fade" id="generateInvoiceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Generate Invoice</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select Hospital</label>
                        <select class="form-select" id="invoiceLicenseId">
                            <?php foreach ($licenses as $lic): ?>
                                <option value="<?= $lic['id'] ?>">
                                    <?= htmlspecialchars($lic['customer_hospital'] ?: $lic['customer_name'] ?: 'License ' . $lic['id']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Period Start</label>
                            <input type="date" class="form-control" id="periodStart" value="<?= date('Y-m-01', strtotime('last month')) ?>">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Period End</label>
                            <input type="date" class="form-control" id="periodEnd" value="<?= date('Y-m-t', strtotime('last month')) ?>">
                        </div>
                    </div>
                    <div class="alert alert-info py-2 small">
                        <i class="bi bi-info-circle me-1"></i>
                        Invoice will include all unbilled prints within the selected period.
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="generateInvoice()">
                        <i class="bi bi-check-lg me-2"></i>Generate Invoice
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Invoice Modal -->
    <div class="modal fade" id="viewInvoiceModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-receipt me-2"></i>Invoice Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="invoiceDetailsContent">
                    <div class="text-center py-4">
                        <div class="spinner-border"></div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="printInvoice()">
                        <i class="bi bi-printer me-2"></i>Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const basePath = document.querySelector('meta[name="base-path"]')?.content || '';

        async function generateInvoice() {
            const licenseId = document.getElementById('invoiceLicenseId').value;
            const periodStart = document.getElementById('periodStart').value;
            const periodEnd = document.getElementById('periodEnd').value;

            try {
                const response = await fetch(`${basePath}/api/billing/invoices.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        license_id: licenseId,
                        period_start: periodStart,
                        period_end: periodEnd
                    })
                });

                const result = await response.json();

                if (result.success) {
                    alert(`Invoice ${result.invoice.invoice_number} generated successfully!\nTotal: ₹${result.invoice.total.toLocaleString()}`);
                    location.reload();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error generating invoice: ' + error.message);
            }
        }

        function quickInvoice(licenseId) {
            document.getElementById('invoiceLicenseId').value = licenseId;
            new bootstrap.Modal(document.getElementById('generateInvoiceModal')).show();
        }

        async function viewInvoice(id) {
            const modal = new bootstrap.Modal(document.getElementById('viewInvoiceModal'));
            modal.show();

            try {
                const response = await fetch(`${basePath}/api/billing/invoices.php?id=${id}`);
                const result = await response.json();

                if (result.success) {
                    const inv = result.invoice;
                    document.getElementById('invoiceDetailsContent').innerHTML = `
                        <div class="row mb-4">
                            <div class="col-6">
                                <h6 class="text-muted">Invoice Number</h6>
                                <h4 class="invoice-number">${inv.invoice_number}</h4>
                            </div>
                            <div class="col-6 text-end">
                                <span class="badge bg-${getStatusColor(inv.status)} fs-6">${inv.status.toUpperCase()}</span>
                            </div>
                        </div>
                        <div class="row mb-4">
                            <div class="col-6">
                                <h6 class="text-muted">Customer</h6>
                                <p>${inv.customer_hospital || inv.customer_name || 'N/A'}</p>
                            </div>
                            <div class="col-6">
                                <h6 class="text-muted">Billing Period</h6>
                                <p>${inv.billing_period_start} to ${inv.billing_period_end}</p>
                            </div>
                        </div>
                        <hr class="border-secondary">
                        <div class="row mb-3">
                            <div class="col-6">Total Prints</div>
                            <div class="col-6 text-end">${inv.total_prints.toLocaleString()}</div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-6">Total Pages</div>
                            <div class="col-6 text-end">${inv.total_pages.toLocaleString()}</div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-6">Subtotal</div>
                            <div class="col-6 text-end currency">₹${parseFloat(inv.subtotal).toLocaleString()}</div>
                        </div>
                        ${inv.tax_amount > 0 ? `
                        <div class="row mb-3">
                            <div class="col-6">Tax (${inv.tax_percentage}%)</div>
                            <div class="col-6 text-end currency">₹${parseFloat(inv.tax_amount).toLocaleString()}</div>
                        </div>` : ''}
                        <hr class="border-secondary">
                        <div class="row">
                            <div class="col-6"><h5>Total Amount</h5></div>
                            <div class="col-6 text-end"><h5 class="currency text-success">₹${parseFloat(inv.total_amount).toLocaleString()}</h5></div>
                        </div>
                    `;
                } else {
                    document.getElementById('invoiceDetailsContent').innerHTML = `<p class="text-danger">Error: ${result.error}</p>`;
                }
            } catch (error) {
                document.getElementById('invoiceDetailsContent').innerHTML = `<p class="text-danger">Error loading invoice: ${error.message}</p>`;
            }
        }

        async function markPaid(id) {
            const ref = prompt('Enter payment reference (optional):');

            try {
                const response = await fetch(`${basePath}/api/billing/invoices.php`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: id,
                        status: 'paid',
                        payment_reference: ref
                    })
                });

                const result = await response.json();

                if (result.success) {
                    alert('Invoice marked as paid!');
                    location.reload();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        async function cancelInvoice(id) {
            if (!confirm('Are you sure you want to cancel this invoice? Prints will become unbilled again.')) return;

            try {
                const response = await fetch(`${basePath}/api/billing/invoices.php?id=${id}`, {
                    method: 'DELETE'
                });

                const result = await response.json();

                if (result.success) {
                    alert('Invoice cancelled');
                    location.reload();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        function printInvoice() {
            window.print();
        }

        function getStatusColor(status) {
            const colors = {
                'draft': 'secondary',
                'generated': 'info',
                'sent': 'primary',
                'paid': 'success',
                'overdue': 'danger',
                'cancelled': 'dark'
            };
            return colors[status] || 'secondary';
        }
    </script>
</body>
</html>
