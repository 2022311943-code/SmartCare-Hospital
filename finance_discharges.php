<?php
session_start();
require_once "config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'finance') {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$success_message = $error_message = '';

// Ensure any admissions with a completed discharge order have a billing record
try {
    $backfill = $db->prepare(
        "INSERT INTO admission_billing (admission_id, subtotal, discount_amount, discount_label, total_due, payment_status, created_by)
         SELECT DISTINCT do.admission_id, 0, 0, NULL, 0, 'unpaid', :uid
         FROM doctors_orders do
         LEFT JOIN admission_billing ab ON ab.admission_id = do.admission_id
        WHERE (do.order_type = 'discharge' OR do.order_type = '') AND do.status = 'completed' AND ab.id IS NULL"
    );
    $backfill->execute(['uid' => $_SESSION['user_id']]);
} catch (Exception $e) {
    // ignore backfill errors
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['billing_id'])) {
    try {
        $db->beginTransaction();

        $billing_id = intval($_POST['billing_id']);
        // Save items if provided
        if (!empty($_POST['items_json'])) {
            $items = json_decode($_POST['items_json'], true);
            if (is_array($items)) {
                // Overwrite strategy: clear then insert
                $db->prepare("DELETE FROM admission_billing_items WHERE billing_id = :bid")->execute(['bid' => $billing_id]);
                $ins = $db->prepare("INSERT INTO admission_billing_items (billing_id, description, amount) VALUES (:bid, :desc, :amt)");
                foreach ($items as $it) {
                    $desc = isset($it['description']) ? trim($it['description']) : '';
                    $amt = isset($it['amount']) ? floatval($it['amount']) : 0.0;
                    if ($desc !== '') {
                        $ins->execute(['bid' => $billing_id, 'desc' => $desc, 'amt' => $amt]);
                    }
                }
            }
        }

        // Save suppressed lab refs (unique insert)
        if (!empty($_POST['suppressed_labs_json'])) {
            $supp = json_decode($_POST['suppressed_labs_json'], true);
            if (is_array($supp)) {
                $insSup = $db->prepare("INSERT IGNORE INTO admission_billing_suppressed (billing_id, type, ref_id) VALUES (:bid, 'lab', :rid)");
                foreach ($supp as $rid) {
                    $rid = intval($rid);
                    if ($rid > 0) { $insSup->execute(['bid' => $billing_id, 'rid' => $rid]); }
                }
            }
        }

        // Recompute subtotal on the server to ensure dashboard totals are accurate
        // 1) Sum of manual/saved items
        $sum_items_stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM admission_billing_items WHERE billing_id = :bid");
        $sum_items_stmt->execute(['bid' => $billing_id]);
        $sum_items = (float)$sum_items_stmt->fetchColumn();

        // 2) Sum of completed lab requests tied to the admission (not suppressed)
        $sum_labs_stmt = $db->prepare(
            "SELECT COALESCE(SUM(lt.cost),0)
             FROM lab_requests lr
             JOIN lab_tests lt ON lr.test_id = lt.id
             JOIN admission_billing ab ON ab.admission_id = lr.admission_id
             LEFT JOIN admission_billing_suppressed s ON s.billing_id = ab.id AND s.type = 'lab' AND s.ref_id = lr.id
             WHERE ab.id = :bid AND lr.status = 'completed' AND s.id IS NULL"
        );
        $sum_labs_stmt->execute(['bid' => $billing_id]);
        $sum_labs = (float)$sum_labs_stmt->fetchColumn();

        $subtotal = max(0, $sum_items + $sum_labs);

        // Prefer posted discount percentage (total of rows) when available; fallback to amount
        $pctTotal = isset($_POST['discount_pct_total']) ? (float)$_POST['discount_pct_total'] : null;
        if ($pctTotal !== null && $pctTotal >= 0 && $pctTotal <= 100) {
            $discount = round(($pctTotal / 100.0) * $subtotal, 2);
        } else {
            $discount = isset($_POST['discount_amount']) ? (float)$_POST['discount_amount'] : 0.0;
            if ($discount < 0) { $discount = 0; }
            if ($discount > $subtotal) { $discount = $subtotal; }
        }
        $label = !empty($_POST['discount_label']) ? $_POST['discount_label'] : null;
        $total_due = max(0, $subtotal - $discount);
        // Determine payment status based on cash intended; preserve existing status during autosave
        $cashTendered = isset($_POST['cash_tendered']) ? (float)$_POST['cash_tendered'] : null;
        $curStmt = $db->prepare("SELECT payment_status FROM admission_billing WHERE id = :id");
        $curStmt->execute(['id' => $billing_id]);
        $currentStatus = $curStmt->fetchColumn() ?: 'unpaid';
        $status = $currentStatus;
        if ($cashTendered !== null) {
            $status = ($cashTendered >= $total_due) ? 'paid' : $currentStatus;
        }

        $upd = $db->prepare("UPDATE admission_billing 
                              SET subtotal = :sub, discount_amount = :disc, discount_label = :label, total_due = :total, payment_status = :status, updated_at = CURRENT_TIMESTAMP
                              WHERE id = :id");
        $upd->execute(['sub' => $subtotal, 'disc' => $discount, 'label' => $label, 'total' => $total_due, 'status' => $status, 'id' => $billing_id]);

        if ($status === 'paid') {
            $adq = $db->prepare("SELECT admission_id FROM admission_billing WHERE id = :id");
            $adq->execute(['id' => $billing_id]);
            $admission_id = $adq->fetchColumn();
            if ($admission_id) {
                $bedStmt = $db->prepare("SELECT bed_id FROM patient_admissions WHERE id = :aid");
                $bedStmt->execute(['aid' => $admission_id]);
                $bed_id = $bedStmt->fetchColumn();
                if ($bed_id) {
                    $db->prepare("UPDATE beds SET status = 'available' WHERE id = :bid")->execute(['bid' => $bed_id]);
                }
                $db->prepare("UPDATE patient_admissions 
                               SET admission_status = 'discharged', actual_discharge_date = CURRENT_TIMESTAMP
                               WHERE id = :aid")->execute(['aid' => $admission_id]);

                // Defensive: remove any OPD pending payment created for the same person today
                try {
                    $remove_opd = $db->prepare(
                        "DELETE p FROM opd_payments p
                         JOIN opd_visits ov ON ov.id = p.visit_id
                         JOIN patients ip ON (ip.phone = ov.contact_number OR ip.name = ov.patient_name)
                         WHERE p.payment_status = 'pending' AND DATE(ov.arrival_time) = CURDATE()"
                    );
                    $remove_opd->execute();
                } catch (Exception $ignore) { /* opd_payments may not exist */ }
            }
            // Create or fetch patient portal account for this admission's patient
            try {
                $pa = $db->prepare("SELECT pa.patient_id, p.name FROM admission_billing ab JOIN patient_admissions pa ON ab.admission_id = pa.id LEFT JOIN patients p ON pa.patient_id = p.id WHERE ab.id = :id LIMIT 1");
                $pa->execute(['id'=>$billing_id]);
                $paRow = $pa->fetch(PDO::FETCH_ASSOC);
                if ($paRow) {
                    // Resolve patient_record_id via name + phone match using latest record
                    $prq = $db->prepare("SELECT pr.id FROM patients ip JOIN patient_records pr ON pr.patient_name = ip.name AND pr.contact_number = ip.phone WHERE ip.id = :pid ORDER BY pr.id DESC LIMIT 1");
                    $prq->execute(['pid'=>$paRow['patient_id']]);
                    $prId = intval($prq->fetchColumn());
                    if ($prId > 0) {
                        // Call helper endpoint
                        // Silent fire-and-forget via curl-like fetch using file_get_contents
                        $context = stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/json','content'=>json_encode(['patient_record_id'=>$prId])]]);
                        @file_get_contents((isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']).'/ensure_patient_portal_account.php', false, $context);
                    }
                }
            } catch (Exception $ignore) {}
        }

        $db->commit();
        $success_message = 'Billing updated successfully';
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = 'Error: ' . $e->getMessage();
    }
}

// Fetch list only if a search term is present; otherwise keep empty to hide listings by default
$list = [];
$search_term = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($search_term !== '') {
    $stmt = $db->prepare("SELECT ab.*, pa.id as admission_id, p.id as patient_id, p.name as patient_name, p.age, p.gender, r.room_number, b.bed_number
                          FROM admission_billing ab
                          JOIN patient_admissions pa ON ab.admission_id = pa.id
                          LEFT JOIN patients p ON pa.patient_id = p.id
                          LEFT JOIN rooms r ON pa.room_id = r.id
                          LEFT JOIN beds b ON pa.bed_id = b.id
                          WHERE ab.payment_status = 'unpaid' AND (p.name LIKE :term OR r.room_number LIKE :term OR b.bed_number LIKE :term)
                          ORDER BY ab.updated_at DESC");
    $like = "%".$search_term."%";
    $stmt->bindParam(':term', $like);
    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Discharge Billing';
define('INCLUDED_IN_PAGE', true);
require_once 'includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discharge Billing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
    .or-af51{width:600px;background:#fff;border:1px solid #999;padding:12px;font-family:Arial,Helvetica,sans-serif;color:#000}
    .or-af51 .af51-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;font-size:12px}
    .or-af51 .af51-topboxes{display:flex;gap:8px;margin-bottom:6px}
    .or-af51 .af51-topboxes .box{border:1px solid #999;height:38px}
    .or-af51 .af51-topboxes .large{flex:1}
    .or-af51 .af51-topboxes .barcode{width:220px}
    .or-af51 .af51-row{display:flex;gap:8px;margin-bottom:6px}
    .or-af51 .af51-field{border:1px solid #999;padding:6px;flex:1;font-size:13px;min-height:34px}
    .or-af51 table{width:100%;border-collapse:collapse;font-size:13px}
    .or-af51 th,.or-af51 td{border:1px solid #999;padding:4px 6px}
    .or-af51 tfoot td{border-top:2px solid #000}
    .or-af51 .checkline{display:flex;gap:16px;align-items:center;margin-top:8px;font-size:13px}
    .or-af51 .cb{display:inline-block;width:13px;height:13px;border:1px solid #000;text-align:center;line-height:13px;font-size:12px}
    .or-af51 .af51-ack{display:flex;justify-content:space-between;align-items:center;margin-top:10px;font-size:12px}
    .or-af51 .af51-note{font-size:11px;color:#555;margin-top:6px}
    .or-af-service-table{width:100%;border-collapse:collapse;font-size:13px}
    .or-af-service-table th,.or-af-service-table td{border:1px solid #000;padding:4px 6px}
    .or-af-service-table tfoot td{border-top:2px solid #000}
    </style>
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><i class="fas fa-file-invoice-dollar me-2"></i>Discharge Billing</h3>
        <a href="dashboard.php" class="btn btn-outline-danger">Back</a>
    </div>

    <form method="GET" class="input-group mb-3" action="">
        <span class="input-group-text"><i class="fas fa-search"></i></span>
        <input type="text" id="billingSearch" name="q" class="form-control" placeholder="Search patient name to view records..." value="<?php echo htmlspecialchars($search_term); ?>">
        <button class="btn btn-danger" type="submit">Search</button>
    </form>

    <?php if ($search_term === ''): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>Please search the patient name to view pending discharges.
        </div>
    <?php endif; ?>

    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <?php if ($search_term !== '' && empty($list)): ?>
        <div class="alert alert-info">No discharge billings found for your search.</div>
    <?php elseif ($search_term !== ''): ?>
        <?php foreach ($list as $bill): ?>
            <?php
            // Resolve patient_record_id reliably from patients table
            $patient_record_id_for_bill = 0;
            try {
                if (!empty($bill['patient_id'])) {
                    $prq = $db->prepare("SELECT pr.id FROM patients ip JOIN patient_records pr ON pr.patient_name = ip.name AND pr.contact_number = ip.phone WHERE ip.id = :pid ORDER BY pr.id DESC LIMIT 1");
                    $prq->execute(['pid' => $bill['patient_id']]);
                    $patient_record_id_for_bill = intval($prq->fetchColumn());
                }
            } catch (Exception $e) { $patient_record_id_for_bill = 0; }
            ?>
			<div class="card mb-3 billing-card" data-name="<?php echo htmlspecialchars($bill['patient_name']); ?>" data-room="<?php echo htmlspecialchars($bill['room_number']); ?>" data-bed="<?php echo htmlspecialchars($bill['bed_number']); ?>" data-status="<?php echo htmlspecialchars($bill['payment_status']); ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h5 class="mb-1"><?php echo htmlspecialchars($bill['patient_name']); ?></h5>
							<div class="text-muted small">Room <?php echo htmlspecialchars($bill['room_number']); ?> / Bed <?php echo htmlspecialchars($bill['bed_number']); ?> • Receipt #<?php echo intval($bill['id']); ?></div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
							<button type="button" class="btn btn-sm btn-outline-primary btn-view-orders" title="Completed Orders" data-popover-target="orders-popover-<?php echo $bill['id']; ?>">
                                <i class="fas fa-eye"></i>
							</button>
						<span class="badge bg-<?php echo $bill['payment_status'] === 'paid' ? 'success' : 'warning'; ?>" data-status-badge="1"><?php echo ucfirst($bill['payment_status']); ?></span>
						</div>
					</div>

<?php 
// Preload completed lab tests as item rows
$lab_items_stmt = $db->prepare("SELECT lr.id AS ref_id, lt.test_name AS name, lt.cost AS amount
	FROM lab_requests lr
	JOIN lab_tests lt ON lr.test_id = lt.id
	LEFT JOIN admission_billing ab ON ab.admission_id = lr.admission_id
	LEFT JOIN admission_billing_suppressed s ON s.billing_id = ab.id AND s.type = 'lab' AND s.ref_id = lr.id
	WHERE lr.admission_id = :aid AND lr.status = 'completed' AND s.id IS NULL");
$lab_items_stmt->execute(['aid' => $bill['admission_id']]);
$lab_items = $lab_items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Include any previously saved manual items
$saved_items_stmt = $db->prepare("SELECT description AS name, amount FROM admission_billing_items WHERE billing_id = :bid ORDER BY id ASC");
$saved_items_stmt->execute(['bid' => $bill['id']]);
$saved_items = $saved_items_stmt->fetchAll(PDO::FETCH_ASSOC);
$all_items = array_merge($saved_items, $lab_items);
// Preload completed doctor orders for popover
$orders_for_pop = [];
try {
    $orders_stmt = $db->prepare("SELECT do.*, u.name AS completed_by_name
                                 FROM doctors_orders do
                                 LEFT JOIN users u ON do.completed_by = u.id
                                 WHERE do.admission_id = :aid AND do.status = 'completed'
                                 ORDER BY do.completed_at DESC");
    $orders_stmt->execute(['aid' => $bill['admission_id']]);
    $orders_for_pop = $orders_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { $orders_for_pop = []; }
?>
					<div id="orders-popover-<?php echo $bill['id']; ?>" class="d-none">
						<div class="small" style="max-width: 340px; max-height: 320px; overflow-y: auto; word-break: break-word;">
							<strong>Completed Orders<?php echo !empty($orders_for_pop) ? ' ('.count($orders_for_pop).')' : ''; ?></strong>
							<hr class="my-1" />
							<?php if (empty($orders_for_pop)): ?>
								<div class="text-muted">No completed orders.</div>
							<?php else: foreach ($orders_for_pop as $o): ?>
								<?php
									$preview = trim((string)$o['order_details']);
									$preview_stripped = preg_replace('/\s+/',' ',strip_tags($preview));
									if (mb_strlen($preview_stripped) > 60) { $preview_stripped = mb_substr($preview_stripped, 0, 60) . '…'; }
								?>
								<details class="mb-2">
									<summary style="cursor:pointer;">
										<span class="badge bg-secondary text-capitalize"><?php echo htmlspecialchars(str_replace('_',' ', $o['order_type'])); ?></span>
										<small class="text-muted ms-1"><?php echo $o['completed_at'] ? date('M d, Y h:i A', strtotime($o['completed_at'])) : ''; ?></small>
										<div class="text-muted mt-1"><?php echo htmlspecialchars($preview_stripped ?: '-'); ?></div>
									</summary>
									<div class="mt-2 ps-1">
										<div class="text-muted">Frequency</div>
										<div class="mb-1"><?php echo htmlspecialchars($o['frequency'] ?: '-'); ?></div>
										<div class="text-muted">Order Details</div>
										<div class="mb-1"><?php echo nl2br(htmlspecialchars($o['order_details'] ?: '-')); ?></div>
										<div class="text-muted">Duration</div>
										<div class="mb-1"><?php echo htmlspecialchars($o['duration'] ?: '-'); ?></div>
										<div class="text-muted">Special Instructions</div>
										<div class="mb-1"><?php echo nl2br(htmlspecialchars($o['special_instructions'] ?: '-')); ?></div>
										<?php if (!empty($o['completion_note'])): ?>
											<div class="text-muted">Completion Note</div>
											<div class="mb-1"><?php echo nl2br(htmlspecialchars($o['completion_note'])); ?></div>
										<?php endif; ?>
										<div class="text-muted small">By: <?php echo htmlspecialchars($o['completed_by_name'] ?: '-'); ?></div>
									</div>
								</details>
							<?php endforeach; endif; ?>
                        </div>
                    </div>

                    <form method="POST" class="receipt-form" data-form-id="<?php echo $bill['id']; ?>" data-admission-id="<?php echo intval($bill['admission_id']); ?>" data-patient-record-id="<?php echo intval($patient_record_id_for_bill); ?>">
                                <input type="hidden" name="billing_id" value="<?php echo $bill['id']; ?>">
						<input type="hidden" name="subtotal" class="rf-subtotal" value="<?php echo number_format($bill['subtotal'], 2, '.', ''); ?>">
						<input type="hidden" name="discount_amount" class="rf-discount-amount" value="<?php echo number_format($bill['discount_amount'], 2, '.', ''); ?>">
						<div class="table-responsive">
                            <table class="table table-sm align-middle mb-2">
									<thead>
										<tr>
											<th style="width:70%">Order / Treatment</th>
											<th class="text-end" style="width:25%">Amount (₱)</th>
											<th style="width:5%"></th>
										</tr>
									</thead>
									<tbody class="rf-items">
										<?php if (!empty($saved_items)): foreach ($saved_items as $it): ?>
										<tr data-origin="saved">
											<td><input type="text" class="form-control form-control-sm rf-desc" value="<?php echo htmlspecialchars($it['name']); ?>" placeholder="Description"></td>
											<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end rf-amt" value="<?php echo number_format($it['amount'], 2, '.', ''); ?>"></td>
											<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger rf-remove">&times;</button></td>
										</tr>
										<?php endforeach; endif; ?>
										<?php if (!empty($lab_items)): foreach ($lab_items as $it): ?>
										<tr data-origin="lab" data-ref-id="<?php echo intval($it['ref_id']); ?>">
											<td><input type="text" class="form-control form-control-sm rf-desc" value="<?php echo htmlspecialchars($it['name']); ?>" placeholder="Description"></td>
											<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end rf-amt" value="<?php echo number_format($it['amount'], 2, '.', ''); ?>"></td>
											<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger rf-remove">&times;</button></td>
										</tr>
										<?php endforeach; endif; ?>
									</tbody>
                            </table>
                                </div>
						<div class="d-flex justify-content-between mb-3">
							<button type="button" class="btn btn-sm btn-outline-secondary rf-add-item">
								<i class="fas fa-plus me-1"></i>Add Item
							</button>
							<div class="text-muted small">Add manual items for treatments, professional fees, etc.</div>
                                </div>

						<div class="receipt-totals" style="width: 100%;">
							<table class="table table-sm align-middle mb-2">
								<tbody>
									<tr>
										<td style="width:70%">Subtotal</td>
										<td class="text-end pe-0" style="width:25%"><input type="text" class="form-control form-control-sm text-end rf-subtotal-display" value="<?php echo number_format($bill['subtotal'], 2); ?>" disabled></td>
										<td style="width:5%"></td>
									</tr>
									<tr>
										<td colspan="3">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <label class="form-label small mb-0">Discounts</label>
                                                <div class="d-flex gap-2">
                                                    <select class="form-select form-select-sm rf-discount-select" style="min-width: 200px;">
                                                        <option value="">Select discount…</option>
                                                        <option value="senior">Senior Citizen - 20%</option>
                                                        <option value="pwd">PWD - 20%</option>
                                                        <option value="philhealth">Philhealth - Custom %</option>
                                                    </select>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary rf-add-discount"><i class="fas fa-plus"></i></button>
                                                </div>
                                </div>
											<input type="hidden" name="discount_label" class="rf-label-hidden" value="<?php echo htmlspecialchars($bill['discount_label']); ?>">
											<table class="table table-sm mb-1">
												<thead>
													<tr><th style="width:70%">Label</th><th style="width:25%" class="text-end">%</th><th style="width:5%"></th></tr>
												</thead>
												<tbody class="rf-discounts">
													<!-- rows injected by JS -->
												</tbody>
											</table>
										</td>
									</tr>
									<tr>
										<td>Discount Amt</td>
										<td class="text-end pe-0"><input type="text" class="form-control form-control-sm text-end rf-disc-amt-display" value="<?php echo number_format($bill['discount_amount'], 2); ?>" disabled></td>
										<td></td>
									</tr>
									<tr class="table-active">
										<td class="fw-bold">TOTAL</td>
										<td class="text-end pe-0"><input type="text" class="form-control form-control-sm text-end rf-total-display fw-bold" value="<?php echo number_format($bill['total_due'], 2); ?>" disabled></td>
										<td></td>
									</tr>
								</tbody>
							</table>
							<hr class="my-3">
							<div class="rf-capture-print border rounded p-3 bg-white">
								<div class="small text-muted mb-2">Date: <?php echo date('M d, Y h:i A'); ?></div>
								<div class="mb-1"><strong>Receipt No.:</strong> <?php echo intval($bill['id']); ?> <span class="text-muted">(System-generated)</span></div>
								<div class="mb-1"><strong>Payor:</strong> <?php echo htmlspecialchars($bill['patient_name']); ?></div>
								<div class="table-responsive mt-2">
									<table class="or-af-service-table">
										<thead>
											<tr>
												<th style="width:70%">Nature of Collection</th>
												<th style="width:30%" class="text-end">Amount (₱)</th>
											</tr>
										</thead>
										<tbody class="rf-print-items"></tbody>
										<tfoot>
											<tr>
												<td class="fw-bold">TOTAL</td>
												<td class="text-end fw-bold">₱<span class="rf-print-total"><?php echo number_format($bill['total_due'], 2); ?></span></td>
											</tr>
										</tfoot>
									</table>
								</div>
								<div class="mb-1"><strong>Amount in Words:</strong> <span class="rf-print-words"></span></div>
								<div class="mb-1"><strong>Payment Method:</strong> <span class="rf-print-method">Cash</span></div>
								<div class="mb-1"><strong>Notes:</strong> <span class="rf-print-notes">-</span></div>
								<div class="mb-1"><strong>Portal Account:</strong> <span class="rf-print-portal"></span></div>
								<div class="d-flex justify-content-between mb-1">
									<div><strong>Cash Intended:</strong> ₱<span class="rf-print-cash">0.00</span> &nbsp; <strong>Change:</strong> ₱<span class="rf-print-change">0.00</span></div>
									<div><strong>Cashier:</strong> <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></div>
								</div>
							</div>
							<div class="d-flex justify-content-between align-items-center">
								<div class="d-flex align-items-end gap-3">
									<div class="me-2">
										<label class="form-label small mb-1">Cash Intended</label>
										<input type="number" step="0.01" min="0" name="cash_tendered" class="form-control form-control-sm rf-cash" placeholder="0.00">
									</div>
									<div class="text-end">
										<div class="small text-muted">Change</div>
										<div class="fw-semibold">₱<span class="rf-change">0.00</span></div>
									</div>
								</div>
								<div class="ms-3">
									<label class="form-label small mb-1">Payment Method</label>
									<div class="d-flex gap-3">
										<div class="form-check">
											<input class="form-check-input rf-method" type="radio" name="rf_method_<?php echo $bill['id']; ?>" id="rfMethodCash<?php echo $bill['id']; ?>" value="Cash" checked>
											<label class="form-check-label" for="rfMethodCash<?php echo $bill['id']; ?>">Cash</label>
										</div>
										<div class="form-check">
											<input class="form-check-input rf-method" type="radio" name="rf_method_<?php echo $bill['id']; ?>" id="rfMethodCheck<?php echo $bill['id']; ?>" value="Check">
											<label class="form-check-label" for="rfMethodCheck<?php echo $bill['id']; ?>">Check</label>
										</div>
									</div>
								</div>
								<div class="ms-3" style="min-width: 220px;">
									<label class="form-label small mb-1">Notes</label>
									<input type="text" class="form-control form-control-sm rf-notes-input" placeholder="Optional notes">
								</div>
								<div class="text-end">
									<button type="submit" class="btn btn-danger btn-sm">
										<i class="fas fa-save me-1"></i>Save
									</button>
								</div>
							</div>
                        </div>
                        <div class="small mt-3 d-none" id="acctInfo<?php echo $bill['id']; ?>"></div>
					</form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
	// Recalc functions per form
	document.querySelectorAll('.receipt-form').forEach(function(form){
		const itemsBody = form.querySelector('.rf-items');
		const addBtn = form.querySelector('.rf-add-item');
		const subtotalHidden = form.querySelector('.rf-subtotal');
		const subtotalDisplay = form.querySelector('.rf-subtotal-display');
        const discAmtHidden = form.querySelector('.rf-discount-amount');
        // create/locate total discount percentage hidden field
        let discPctTotalHidden = form.querySelector('input[name="discount_pct_total"]');
        if (!discPctTotalHidden) {
            discPctTotalHidden = document.createElement('input');
            discPctTotalHidden.type = 'hidden';
            discPctTotalHidden.name = 'discount_pct_total';
            form.appendChild(discPctTotalHidden);
        }
		const discAmtDisplay = form.querySelector('.rf-disc-amt-display');
		const totalDisplay = form.querySelector('.rf-total-display');
		const labelHidden = form.querySelector('.rf-label-hidden');
		const discTable = form.querySelector('.rf-discounts');
		const addDiscBtn = form.querySelector('.rf-add-discount');
		const cashEl = form.querySelector('.rf-cash');
		const changeEl = form.querySelector('.rf-change');
		const initialPaid = ((form.closest('.billing-card')?.dataset.status || '').toLowerCase() === 'paid');
		// Print preview elements
		const printItems = form.querySelector('.rf-print-items');
		const printTotal = form.querySelector('.rf-print-total');
		const printMethod = form.querySelector('.rf-print-method');
		const printAccount = form.querySelector('.rf-print-account');
		const printNotes = form.querySelector('.rf-print-notes');
		const printCash = form.querySelector('.rf-print-cash');
		const printChange = form.querySelector('.rf-print-change');
		const methodInputs = Array.from(form.querySelectorAll('.rf-method'));
		const accountInput = form.querySelector('.rf-account-input');
		const notesInput = form.querySelector('.rf-notes-input');
		// status pill
		const saveBtn = form.querySelector('.btn.btn-danger.btn-sm');
		const saveContainer = saveBtn ? saveBtn.parentElement : form;
		// prevent unintended autosave on first load and enable change detection
		let isSeeding = true;
		let lastSentSerial = '';
		function serializeForSave(){
			const payload = {
				billing_id: form.querySelector('input[name="billing_id"]').value,
				subtotal: subtotalHidden.value,
				discount_amount: discAmtHidden.value,
				discount_label: labelHidden.value || '',
				discount_pct_total: discPctTotalHidden.value,
				items: []
			};
			itemsBody.querySelectorAll('tr').forEach(tr => {
				if ((tr.dataset.origin || '') === 'lab') return; // manual items only
				const d = tr.querySelector('.rf-desc').value;
				const a = tr.querySelector('.rf-amt').value;
				if (d && (parseFloat(a)||0) >= 0) payload.items.push({ description: d, amount: parseFloat(a)||0 });
			});
			payload.suppressed = [];
			itemsBody.querySelectorAll('tr.removed-lab').forEach(tr => {
				const ref = parseInt(tr.dataset.refId || '0');
				if (ref) payload.suppressed.push(ref);
			});
			return JSON.stringify(payload);
		}
		let statusEl = form.querySelector('.rf-status');
		if (!statusEl) {
			statusEl = document.createElement('span');
			statusEl.className = 'rf-status ms-2 small text-muted';
			saveContainer.appendChild(statusEl);
		}

		let autosaveTimer;
		function showSaved(msg){ statusEl.textContent = msg || 'Saved'; statusEl.classList.remove('text-danger'); statusEl.classList.add('text-success'); setTimeout(()=>{ statusEl.textContent=''; }, 1500); }
		function showSaving(){ statusEl.textContent = 'Saving…'; statusEl.classList.remove('text-danger'); statusEl.classList.remove('text-success'); }
		function showError(){ statusEl.textContent = 'Save failed'; statusEl.classList.remove('text-success'); statusEl.classList.add('text-danger'); }
		function autosave(){
			const serial = serializeForSave();
			if (serial === lastSentSerial) { showSaved(); return; }
			showSaving();
			const data = JSON.parse(serial);
			const fd = new FormData();
			fd.append('billing_id', data.billing_id);
			fd.append('subtotal', data.subtotal);
			fd.append('discount_amount', data.discount_amount);
			fd.append('discount_label', data.discount_label);
			fd.append('items_json', JSON.stringify(data.items));
			fd.append('discount_pct_total', data.discount_pct_total);
			fd.append('suppressed_labs_json', JSON.stringify(data.suppressed));
			fetch(window.location.pathname, { method: 'POST', body: fd })
				.then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
				.then(() => { lastSentSerial = serial; showSaved(); })
				.catch(() => { showError(); });
		}
		function scheduleAutosave(){
			if (isSeeding) return;
			if (initialPaid) return; // never autosave once already paid
			clearTimeout(autosaveTimer);
			autosaveTimer = setTimeout(autosave, 800);
		}

		function toNumber(v){ return parseFloat(v || '0') || 0; }
		function rebuildLabelHidden(){
			const labels = [];
			discTable.querySelectorAll('tr').forEach(tr => {
				const l = tr.querySelector('.rf-disc-label').value.trim();
				if (l) labels.push(l);
			});
			labelHidden.value = labels.join(', ');
		}
		function numberToWords(n){
			function small(n){const a=['','one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];return a[n]||'';}
			function tens(n){const a=['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];return a[n]||'';}
			function chunk(n){let s='';if(n>=100){s+=small(Math.floor(n/100))+' hundred ';n%=100;}if(n>=20){s+=tens(Math.floor(n/10))+' ';n%=10;}if(n>0){s+=small(n)+' ';}return s.trim();}
			if (!isFinite(n)) return '';
			const pesos=Math.floor(n); const cents=Math.round((n-pesos)*100);
			function big(n){const units=['','thousand','million','billion'];let i=0;let res='';let x=n;while(x>0){const c=x%1000;if(c){res=chunk(c)+' '+units[i]+' '+res;}x=Math.floor(x/1000);i++;}return res.trim()||'zero';}
			const pesoWords=big(pesos)+' peso'+(pesos===1?'':'s');
			const centWords=cents>0?(' and '+chunk(cents)+' centavo'+(cents===1?'':'s')):'';
			return (pesoWords+centWords).toUpperCase();
		}
		function recalc(){
			let sub = 0;
			itemsBody.querySelectorAll('.rf-amt').forEach(i => { sub += toNumber(i.value); });
			subtotalHidden.value = sub.toFixed(2);
			subtotalDisplay.value = sub.toFixed(2);
			let totalPct = 0;
            discTable.querySelectorAll('.rf-disc-pct').forEach(inp => { totalPct += toNumber(inp.value); });
            if (totalPct < 0) totalPct = 0;
            if (totalPct > 100) totalPct = 100;
            if (totalPct < 0) totalPct = 0;
            if (totalPct > 100) totalPct = 100;
            discPctTotalHidden.value = totalPct.toFixed(2);
            let disc = (totalPct / 100) * sub;
			discAmtHidden.value = disc.toFixed(2);
			discAmtDisplay.value = disc.toFixed(2);
			const total = Math.max(0, sub - disc);
			totalDisplay.value = total.toFixed(2);
			// Update change if cash intended present
			if (cashEl && changeEl) {
				const tendered = toNumber(cashEl.value);
				changeEl.textContent = Math.max(0, tendered - total).toFixed(2);
				if (printCash) { printCash.textContent = tendered.toFixed(2); }
				if (printChange) { printChange.textContent = Math.max(0, tendered - total).toFixed(2); }
			}
			// Build print preview
			if (printItems) {
				printItems.innerHTML = '';
				itemsBody && itemsBody.querySelectorAll('tr').forEach(function(tr){
					const d = tr.querySelector('.rf-desc')?.value || '';
					const a = toNumber(tr.querySelector('.rf-amt')?.value || '0');
					if (d.trim() !== '') {
						const row = document.createElement('tr');
						row.innerHTML = '<td>'+d.replace(/</g,'&lt;')+'</td><td class="text-end">₱'+a.toFixed(2)+'</td>';
						printItems.appendChild(row);
					}
				});
			}
			if (printTotal) { printTotal.textContent = total.toFixed(2); }
			if (printMethod) {
				const sel = methodInputs.find(i => i.checked);
				printMethod.textContent = sel ? sel.value : 'Cash';
			}
			// amount in words
			(function(){ const wordsEl = form.querySelector('.rf-print-words'); if (wordsEl) { wordsEl.textContent = numberToWords(total); } })();
			if (printAccount) { printAccount.textContent = (accountInput && accountInput.value.trim()) ? accountInput.value.trim() : '-'; }
			if (printNotes) { printNotes.textContent = (notesInput && notesInput.value.trim()) ? notesInput.value.trim() : '-'; }
			rebuildLabelHidden();
			scheduleAutosave();
		}
		function bindRow(row){
			row.querySelectorAll('.rf-amt, .rf-desc').forEach(inp => inp.addEventListener('input', recalc));
			const del = row.querySelector('.rf-remove');
			del && del.addEventListener('click', function(){
				if ((row.dataset.origin || '') === 'lab') { row.classList.add('removed-lab'); row.style.display = 'none'; scheduleAutosave(); }
				else { row.remove(); recalc(); }
			});
		}
		itemsBody.querySelectorAll('tr').forEach(bindRow);
        addDiscBtn.addEventListener('click', function(){
            const select = form.querySelector('.rf-discount-select');
            if (!select) return;
            const val = select.value; if (!val) return;
            let label = ''; let pct = 0; let locked = false;
            if (val === 'senior') { label = 'Senior Citizen'; pct = 20; locked = true; }
            else if (val === 'pwd') { label = 'PWD'; pct = 20; locked = true; }
            else if (val === 'philhealth') { label = 'Philhealth'; pct = 0; locked = false; }
            const tr = document.createElement('tr');
            tr.setAttribute('data-discount', val);
            tr.innerHTML = '<td><input type="text" class="form-control form-control-sm rf-disc-label" value="'+label.replace(/"/g,'&quot;')+'" readonly></td>'+
                '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end rf-disc-pct" value="'+pct.toFixed(2)+'" '+(locked?'disabled':'')+'></td>'+
                '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger rf-del-discount">&times;</button></td>';
            discTable.appendChild(tr);
            const pctInp = tr.querySelector('.rf-disc-pct'); pctInp && pctInp.addEventListener('input', recalc);
            const delBtn = tr.querySelector('.rf-del-discount');
            delBtn && delBtn.addEventListener('click', function(){
                tr.remove();
                const opt = select.querySelector('option[value="'+val+'"]'); if (opt) opt.disabled = false;
                recalc();
            });
            const opt = select.querySelector('option[value="'+val+'"]'); if (opt) opt.disabled = true;
            select.value = '';
            recalc();
        });
		cashEl && cashEl.addEventListener('input', recalc);
		methodInputs.forEach(function(mi){ if (!mi.dataset.bound){ mi.addEventListener('change', recalc); mi.dataset.bound='1'; } });
		if (accountInput && !accountInput.dataset.bound) { accountInput.addEventListener('input', recalc); accountInput.dataset.bound='1'; }
		if (notesInput && !notesInput.dataset.bound) { notesInput.addEventListener('input', recalc); notesInput.dataset.bound='1'; }

		// On submit: require sufficient cash intended, capture the billing card image, then submit
		form.addEventListener('submit', function(ev){
			// ensure fields are up-to-date but avoid triggering autosave
			const wasSeeding = isSeeding; isSeeding = true; recalc(); isSeeding = wasSeeding;
			const total = toNumber(totalDisplay.value);
			const tendered = cashEl ? toNumber(cashEl.value) : 0;
			if (tendered < total) { ev.preventDefault(); alert('Insufficient cash intended.'); cashEl && cashEl.focus(); return; }
			// Remove/hide the save button to prevent duplicate actions
			if (saveBtn) { try { saveBtn.remove(); } catch(e) { saveBtn.style.display = 'none'; } }
			// Capture the billing card section
			const cardEl = form.querySelector('.rf-capture-print') || form.closest('.card') || form;
			try {
				ev.preventDefault();
				html2canvas(cardEl, { scale: 2 }).then(function(canvas){
					canvas.toBlob(function(blob){
						const a = document.createElement('a');
						const url = URL.createObjectURL(blob);
						a.href = url;
						a.download = 'Discharge_Billing_' + (form.getAttribute('data-form-id') || '') + '.png';
						document.body.appendChild(a);
						a.click();
						setTimeout(function(){ URL.revokeObjectURL(url); a.remove(); form.submit(); }, 150);
					}, 'image/png');
				}).catch(function(){ form.submit(); });
			} catch(err) { /* Fallback: continue submit */ }
		});

		// Seed discount rows based on existing values
        (function seedDiscounts(){
            const select = form.querySelector('.rf-discount-select');
            const existingLabels = (labelHidden.value || '').split(',').map(s=>s.trim()).filter(Boolean);
            let remainingPct = 0;
            const sub = toNumber(subtotalDisplay.value);
            if (sub > 0) {
                remainingPct = (toNumber(discAmtDisplay.value) / sub) * 100;
                if (remainingPct < 0) remainingPct = 0;
                if (remainingPct > 100) remainingPct = 100;
            }
            existingLabels.forEach((lbl) => {
                let val = ''; let pct = 0; let locked = false;
                if (lbl.toLowerCase().indexOf('senior') !== -1) { val='senior'; pct=20; locked=true; }
                else if (lbl.toLowerCase().indexOf('pwd') !== -1) { val='pwd'; pct=20; locked=true; }
                else if (lbl.toLowerCase().indexOf('philhealth') !== -1) { val='philhealth'; pct=Math.max(0, remainingPct); locked=false; }
                else { val='custom'; pct=Math.max(0, remainingPct); }
                remainingPct = Math.max(0, remainingPct - pct);
                const tr = document.createElement('tr');
                tr.setAttribute('data-discount', val);
                tr.innerHTML = '<td><input type="text" class="form-control form-control-sm rf-disc-label" value="'+lbl.replace(/"/g,'&quot;')+'" '+(val==='custom'?'':'readonly')+'></td>'+
                    '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end rf-disc-pct" value="'+pct.toFixed(2)+'" '+(locked?'disabled':'')+'></td>'+
                    '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger rf-del-discount">&times;</button></td>';
                discTable.appendChild(tr);
                const pctInp = tr.querySelector('.rf-disc-pct'); pctInp && pctInp.addEventListener('input', recalc);
                const delBtn = tr.querySelector('.rf-del-discount');
                delBtn && delBtn.addEventListener('click', function(){
                    tr.remove();
                    if (val!=='custom' && select) {
                        const opt = select.querySelector('option[value="'+val+'"]'); if (opt) opt.disabled=false;
                    }
                    recalc();
                });
                if (val!=='custom' && select) { const opt = select.querySelector('option[value="'+val+'"]'); if (opt) opt.disabled=true; }
            });
			recalc();
			// establish baseline so initial load doesn't autosave and won't bump updated_at
			lastSentSerial = serializeForSave();
			isSeeding = false;
		})();

		recalc();
		addBtn.addEventListener('click', function(){
			const tr = document.createElement('tr');
			tr.setAttribute('data-origin','manual');
			tr.innerHTML = '<td><input type="text" class="form-control form-control-sm rf-desc" placeholder="Description"></td>'+
				'<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end rf-amt" value="0.00"></td>'+
				'<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger rf-remove">&times;</button></td>';
			itemsBody.appendChild(tr);
			bindRow(tr);
			recalc();
			tr.querySelector('.rf-desc').focus();
		});
	});

    // Ensure or fetch patient portal account info for each billing card (shows username and reset-only password)
    document.querySelectorAll('.receipt-form').forEach(function(form){
        try {
            const acctInfoEl = document.getElementById('acctInfo'+(form.getAttribute('data-form-id')||''));
            const prId = Number(form.getAttribute('data-patient-record-id')||0);
            const admissionId = Number(form.getAttribute('data-admission-id')||0);
            const payload = prId ? { patient_record_id: prId } : (admissionId ? { admission_id: admissionId } : null);
            if (payload && acctInfoEl) {
                fetch('ensure_patient_portal_account.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) })
                  .then(r=>r.json())
                  .then(data=>{ if (data && data.success) { acctInfoEl.innerHTML = '<strong>Portal Account</strong>: ' + data.username + (data.password ? ' / ' + data.password : ''); const ps = form.querySelector('.rf-print-portal'); if (ps) { ps.textContent = data.username + (data.password ? ' / ' + data.password : ''); } } })
                  .catch(()=>{});
            }
        } catch(e) { /* ignore */ }
    });

    // search filter from earlier feature remains
	const q = document.getElementById('billingSearch');
	const cards = Array.from(document.querySelectorAll('.billing-card'));
	function applyFilter() {
		const term = (q.value || '').toLowerCase();
		cards.forEach(c => {
			const name = (c.dataset.name || '').toLowerCase();
			const room = (c.dataset.room || '').toLowerCase();
			const bed = (c.dataset.bed || '').toLowerCase();
			const status = (c.dataset.status || '').toLowerCase();
			const match = name.includes(term) || room.includes(term) || bed.includes(term) || status.includes(term);
			c.style.display = match ? '' : 'none';
		});
	}
	q && q.addEventListener('input', applyFilter);

	// Hover popover on eye icon showing completed orders
	document.querySelectorAll('.btn-view-orders').forEach(function(btn){
		const targetId = btn.getAttribute('data-popover-target');
		function getContentHtml(){
			const el = document.getElementById(targetId);
			return el ? el.innerHTML : '<div class="small text-muted px-2">No completed orders.</div>';
		}
		const pop = new bootstrap.Popover(btn, {
			container: 'body',
			html: true,
			sanitize: false,
			trigger: 'manual',
			placement: 'bottom',
			content: getContentHtml()
		});
		function refreshContent(){
			try {
				const tipId = btn.getAttribute('aria-describedby');
				if (!tipId) return;
				const tip = document.getElementById(tipId);
				if (tip) {
					const body = tip.querySelector('.popover-body');
					if (body) body.innerHTML = getContentHtml();
				}
			} catch(_) {}
		}
		// Toggle on click, keep open while cursor is over the popover.
		btn.addEventListener('click', function(e){
			e.preventDefault();
			const opened = !!btn.getAttribute('aria-describedby');
			if (opened) { pop.hide(); return; }
			refreshContent();
			pop.show();
		});
		// Close on outside click or ESC
		document.addEventListener('click', function(ev){
			const tipId = btn.getAttribute('aria-describedby');
			if (!tipId) return;
			const tip = document.getElementById(tipId);
			const clickedInsidePopover = tip && tip.contains(ev.target);
			const clickedButton = btn.contains(ev.target);
			if (!clickedInsidePopover && !clickedButton) {
				pop.hide();
			}
		});
		document.addEventListener('keydown', function(ev){
			if (ev.key === 'Escape') pop.hide();
		});
	});
});
</script>
</body>
</html> 