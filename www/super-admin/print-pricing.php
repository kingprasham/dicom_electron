<?php
/**
 * Super Admin - Print Pricing Management
 * Configure print pricing for all hospitals
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../auth/session.php';

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

$_SESSION['is_super_admin'] = true;
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure table exists
    $db->query("
        CREATE TABLE IF NOT EXISTS print_pricing (
            id INT AUTO_INCREMENT PRIMARY KEY,
            paper_size VARCHAR(20) NOT NULL UNIQUE,
            grayscale_price DECIMAL(10,2) DEFAULT 0.00,
            color_price DECIMAL(10,2) DEFAULT 0.00,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    $sizes = ['A4', 'A3', '8x10', '10x12', '11x14', '14x17'];
    $updated = 0;
    
    foreach ($sizes as $size) {
        $grayscale = floatval($_POST["grayscale_$size"] ?? 0);
        $color = floatval($_POST["color_$size"] ?? 0);
        
        $stmt = $db->prepare("INSERT INTO print_pricing (paper_size, grayscale_price, color_price, is_active) 
            VALUES (?, ?, ?, 1) 
            ON DUPLICATE KEY UPDATE grayscale_price = ?, color_price = ?");
        $stmt->bind_param("sdddd", $size, $grayscale, $color, $grayscale, $color);
        if ($stmt->execute()) {
            $updated++;
        }
        $stmt->close();
    }
    
    $message = "Print pricing updated successfully! ($updated sizes configured)";
}

// Get current pricing
$pricing = [];
$result = $db->query("SELECT * FROM print_pricing ORDER BY paper_size");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pricing[$row['paper_size']] = $row;
    }
}

// Default sizes if table is empty
$defaultSizes = ['A4', 'A3', '8x10', '10x12', '11x14', '14x17'];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>Print Pricing - Super Admin</title>
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
        .pricing-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 30px;
        }
        .price-input-group {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
        }
        .paper-size-label {
            font-size: 1.2rem;
            font-weight: 600;
            color: #8b5cf6;
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
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="bi bi-currency-rupee me-2"></i>Print Pricing</h4>
                <p class="text-muted mb-0">Configure print prices per paper size</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="pricing-card">
            <form method="POST">
                <div class="row">
                    <?php foreach ($defaultSizes as $size): 
                        $current = $pricing[$size] ?? ['grayscale_price' => 0, 'color_price' => 0];
                    ?>
                        <div class="col-lg-4 col-md-6">
                            <div class="price-input-group">
                                <div class="paper-size-label mb-3">
                                    <i class="bi bi-file-earmark me-1"></i><?= $size ?>
                                </div>
                                <div class="row g-3">
                                    <div class="col-6">
                                        <label class="form-label text-muted small">Grayscale</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-dark border-secondary">₹</span>
                                            <input type="number" name="grayscale_<?= $size ?>" 
                                                class="form-control bg-dark border-secondary text-white" 
                                                value="<?= $current['grayscale_price'] ?>" step="0.01" min="0">
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label text-muted small">Color</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-dark border-secondary">₹</span>
                                            <input type="number" name="color_<?= $size ?>" 
                                                class="form-control bg-dark border-secondary text-white" 
                                                value="<?= $current['color_price'] ?>" step="0.01" min="0">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="d-flex justify-content-end mt-4">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-lg me-2"></i>Save Pricing
                    </button>
                </div>
            </form>
        </div>
        
        <div class="mt-4 p-3 bg-dark bg-opacity-50 rounded">
            <h6 class="text-info"><i class="bi bi-info-circle me-2"></i>Note</h6>
            <p class="text-muted small mb-0">
                These prices will be used to calculate billing for all print jobs across all hospitals.
                Grayscale is for standard medical image prints, Color is for reports or color images.
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
