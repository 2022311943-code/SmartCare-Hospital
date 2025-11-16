<?php
session_start();
require_once "config/database.php";

// Only pharmacists can access this page UI
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'pharmacist') {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// AJAX endpoint to return rows only (fixes UI issues)
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $query = "SELECT * FROM medicines";
    $params = [];
    if ($search !== '') {
        $query .= " WHERE name LIKE :search OR generic_name LIKE :search OR category LIKE :search";
        $params[':search'] = "%" . $search . "%";
    }
    $query .= " ORDER BY name ASC";
    $stmt = $db->prepare($query);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->execute();
    $medicines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $html = '';
    foreach ($medicines as $medicine) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($medicine['name']) . '</td>';
        $html .= '<td>' . htmlspecialchars($medicine['generic_name']) . '</td>';
        $html .= '<td>' . htmlspecialchars($medicine['category']) . '</td>';
        $html .= '<td>' . htmlspecialchars($medicine['quantity']) . ' ' . htmlspecialchars($medicine['unit']);
        if ((int)$medicine['quantity'] <= (int)$medicine['reorder_level']) {
            $html .= ' <span class="badge bg-danger">Low Stock</span>';
        }
        $html .= '</td>';
        $html .= '<td>₱' . number_format((float)$medicine['unit_price'], 2) . '</td>';
        $html .= '<td>₱' . number_format(((float)$medicine['quantity'] * (float)$medicine['unit_price']), 2) . '</td>';
        // removed action button
        $html .= '</tr>';
    }
    echo $html;
    exit();
}

// Render basic page container and client-side search
$page_title = 'Search Medicines';
define('INCLUDED_IN_PAGE', true);
require_once 'includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Medicines</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="fas fa-search me-2"></i>Search Medicines</h3>
        <a href="dashboard.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="row g-3 align-items-center mb-3">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" id="searchBox" class="form-control" placeholder="Search by name, generic, or category...">
                        <button class="btn btn-outline-secondary" type="button" id="clearBtn" style="display:none;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Generic</th>
                            <th>Category</th>
                            <th>Stock</th>
                            <th>Unit Price</th>
                            <th>Total Value</th>
                        </tr>
                    </thead>
                    <tbody id="resultsBody">
                        <tr><td colspan="6" class="text-center text-muted py-4">Start typing to search medicines...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
    const box = document.getElementById('searchBox');
    const clearBtn = document.getElementById('clearBtn');
    const body = document.getElementById('resultsBody');
    let timer;

    function renderLoading(){
        body.innerHTML = '<tr><td colspan="6" class="text-center py-4"><div class="spinner-border text-danger"></div></td></tr>';
    }

    function search(){
        const q = (box.value || '').trim();
        clearBtn.style.display = q ? 'block' : 'none';
        renderLoading();
        fetch('search_medicines.php?ajax=1&search=' + encodeURIComponent(q))
            .then(r => r.text())
            .then(html => { body.innerHTML = html || '<tr><td colspan="6" class="text-center text-muted py-4">No medicines found</td></tr>'; })
            .catch(() => { body.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Failed to load results</td></tr>'; });
    }

    box.addEventListener('input', function(){ clearTimeout(timer); timer = setTimeout(search, 300); });
    clearBtn.addEventListener('click', function(){ box.value=''; search(); });
    search();
})();
</script>
</body>
</html>