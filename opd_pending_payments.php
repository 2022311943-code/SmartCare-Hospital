<?php
	session_start();
	define('INCLUDED_IN_PAGE', true);
	require_once "config/database.php";
	require_once "includes/crypto.php";

if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'finance') {
		header("Location: index.php");
		exit();
	}

	$database = new Database();
	$db = $database->getConnection();

// Ensure payments table exists (safe in MySQL)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS opd_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        visit_id INT NOT NULL UNIQUE,
        patient_record_id INT NULL,
        amount_due DECIMAL(12,2) DEFAULT 0,
        payment_status ENUM('pending','paid') DEFAULT 'pending',
        payment_date DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
			} catch (Exception $e) {
    // ignore
}

// Ensure patient_admissions has source_visit_id for reliable linkage from OPD → Admission
try { $db->exec("ALTER TABLE patient_admissions ADD COLUMN source_visit_id INT NULL"); } catch (Exception $e) { /* ignore */ }
try { $db->exec("CREATE INDEX IF NOT EXISTS idx_pa_source_visit ON patient_admissions (source_visit_id)"); } catch (Exception $e) { /* ignore */ }

// Enforce uniqueness retroactively and clean duplicates defensively (older installs might lack UNIQUE)
try {
    // Remove duplicate rows keeping the lowest id per visit_id
    $db->exec("DELETE p1 FROM opd_payments p1 JOIN opd_payments p2 ON p1.visit_id = p2.visit_id AND p1.id > p2.id");
} catch (Exception $e) { /* ignore */ }
try {
    $db->exec("ALTER TABLE opd_payments ADD UNIQUE KEY uniq_visit (visit_id)");
} catch (Exception $e) { /* index may already exist; ignore */ }

// Backfill: ensure any completed OPD visits have a pending payment row (exclude those admitted)
try {
    $db->exec("INSERT INTO opd_payments (visit_id, patient_record_id, amount_due, payment_status, created_at)
               SELECT ov.id, ov.patient_record_id, 0, 'pending', NOW()
               FROM opd_visits ov
               LEFT JOIN opd_payments p ON p.visit_id = ov.id
               WHERE ov.visit_status = 'completed' AND p.id IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM patient_admissions pa
                   WHERE pa.source_visit_id = ov.id
               )
               AND NOT EXISTS (
                   SELECT 1
                   FROM patient_admissions pa
                   JOIN patients ip ON pa.patient_id = ip.id
                   JOIN patient_records pr ON pr.id = ov.patient_record_id AND pr.patient_name = ip.name AND pr.contact_number = ip.phone
               )");
	} catch (Exception $e) {
    // ignore backfill errors to avoid breaking page
}

// Search
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$params = [];
$sql = "SELECT p.id as pay_id, p.visit_id, p.amount_due, p.payment_status, ov.patient_name, ov.age, ov.gender, ov.arrival_time
        FROM opd_payments p
        JOIN opd_visits ov ON ov.id = p.visit_id
        WHERE p.payment_status = 'pending'
          AND NOT EXISTS (
            SELECT 1 FROM patient_admissions pa WHERE pa.source_visit_id = ov.id
          )
          AND NOT EXISTS (
            SELECT 1
            FROM patient_admissions pa
            JOIN patients ip ON pa.patient_id = ip.id
            JOIN patient_records pr ON pr.id = ov.patient_record_id AND pr.patient_name = ip.name AND pr.contact_number = ip.phone
          )";
if ($q !== '') {
    $sql .= " AND (ov.patient_name LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
$sql .= " ORDER BY ov.arrival_time DESC";
$stmt = $db->prepare($sql);
foreach ($params as $k=>$v) { $stmt->bindValue($k, $v); }
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Decrypt sensitive patient fields for display
foreach ($rows as &$rowTmp) {
    if (isset($rowTmp['patient_name'])) { $rowTmp['patient_name'] = decrypt_safe((string)$rowTmp['patient_name']); }
}
unset($rowTmp);

// Handle mark paid (from OR modal submit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='mark_paid') {
    $pid = intval($_POST['payment_id']);
    $amt = isset($_POST['amount_due']) ? floatval($_POST['amount_due']) : 0;
    $db->beginTransaction();
    try {
        $upd = $db->prepare("UPDATE opd_payments SET amount_due = :amt, payment_status = 'paid', payment_date = NOW() WHERE id = :id");
        $upd->execute([':amt'=>$amt, ':id'=>$pid]);
        // Optional: insert into a consolidated finance ledger in future
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
    }
    header('Location: opd_pending_payments.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OPD Pending Payments</title>
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
	/* Minimal AF-like service table styling */
	.or-af-service-table{width:100%;border-collapse:collapse;font-size:13px}
	.or-af-service-table th,.or-af-service-table td{border:1px solid #000;padding:4px 6px}
	.or-af-service-table tfoot td{border-top:2px solid #000}
	</style>
</head>
<body class="bg-light">
	<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="fas fa-receipt me-2"></i>OPD Pending Payments</h3>
        <a href="dashboard.php" class="btn btn-outline-danger">Back</a>
		</div>

    <form method="GET" class="input-group mb-3" style="max-width: 420px;">
        <span class="input-group-text"><i class="fas fa-search"></i></span>
        <input type="text" name="q" class="form-control" placeholder="Search patient..." value="<?php echo htmlspecialchars($q); ?>">
        <button class="btn btn-danger" type="submit">Search</button>
    </form>

    <?php if (empty($rows)): ?>
        <div class="alert alert-info">No pending OPD payments found.</div>
    <?php else: ?>
				<div class="table-responsive">
					<table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Patient</th>
                        <th>Age/Gender</th>
                        <th>Arrival</th>
                        <th>Status</th>
                        <th>Amount (₱)</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['patient_name']); ?></td>
                        <td><?php echo intval($r['age']); ?>/<?php echo ucfirst($r['gender']); ?></td>
                        <td><?php echo date('M d, Y h:i A', strtotime($r['arrival_time'])); ?></td>
                        <td><span class="badge bg-warning text-dark">Pending</span></td>
                        <td>₱<?php echo number_format((float)$r['amount_due'],2); ?></td>
                        <td>
                            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#opdPayModal<?php echo $r['pay_id']; ?>">
											<i class="fas fa-check me-1"></i>Process Payment
										</button>

                            <!-- OPD OR Modal -->
                            <div class="modal fade" id="opdPayModal<?php echo $r['pay_id']; ?>" tabindex="-1">
											<div class="modal-dialog modal-md">
												<div class="modal-content">
													<div class="modal-header">
														<h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Official Receipt (O.R.)</h5>
														<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
													</div>
                                        <form method="POST" class="or-form" data-payment-id="<?php echo $r['pay_id']; ?>" data-visit-id="<?php echo intval($r['visit_id']); ?>">
														<div class="modal-body">
                                                <input type="hidden" name="action" value="mark_paid">
                                                <input type="hidden" name="payment_id" value="<?php echo intval($r['pay_id']); ?>">
                                                <input type="hidden" name="amount_due" class="or-total-hidden" value="<?php echo number_format((float)$r['amount_due'],2,'.',''); ?>">
															<div class="mb-3">
																<label class="form-label">Receipt No. (System-generated)</label>
                                                    <input type="text" class="form-control" value="<?php echo intval($r['pay_id']); ?>" readonly required>
															</div>
															<div class="or-capture border rounded p-3">
																<div class="d-flex justify-content-between small text-muted mb-2">
																	<span>Date: <?php echo date('M d, Y h:i A'); ?></span>
																	<span>Cashier: <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></span>
																</div>
                                                    <div class="mb-1"><strong>Payor:</strong> <?php echo htmlspecialchars($r['patient_name']); ?></div>
                                                    <div class="mb-1"><strong>Visit:</strong> OPD Consultation</div>

																<div class="d-flex justify-content-between align-items-center mt-3 mb-2">
																	<h6 class="mb-0">Order / Treatment</h6>
																	<button type="button" class="btn btn-sm btn-outline-secondary or-add-item"><i class="fas fa-plus me-1"></i>Add Item</button>
																</div>
																<div class="table-responsive">
																	<table class="table table-sm align-middle mb-2">
																		<thead>
																			<tr>
																				<th style="width:70%">Description</th>
																				<th class="text-end" style="width:25%">Amount (₱)</th>
																				<th style="width:5%"></th>
																		</tr>
																	</thead>
																	<tbody class="or-items">
																		<tr>
																			<td><input type="text" class="form-control form-control-sm or-desc" value="Consultation Fee" /></td>
                                                                    <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end or-amt" value="<?php echo number_format((float)$r['amount_due'],2,'.',''); ?>" /></td>
																			<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger or-remove">&times;</button></td>
																		</tr>
																	</tbody>
																</table>
															</div>

                                                            <hr class="my-3">
                                                            <div class="or-capture-print border rounded p-3 bg-white">
                                                                <div class="d-flex justify-content-between small text-muted mb-2">
                                                                    <span>Date: <?php echo date('M d, Y h:i A'); ?></span>
                                                                    <span>Cashier: <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></span>
                                                                </div>
                                                                <div class="mb-1"><strong>Receipt No.:</strong> <?php echo intval($r['pay_id']); ?> <span class="text-muted">(System-generated)</span></div>
                                                                <div class="mb-1"><strong>Payor:</strong> <?php echo htmlspecialchars($r['patient_name']); ?></div>
                                                                <div class="table-responsive mt-2">
                                                                    <table class="or-af-service-table">
                                                                        <thead>
                                                                            <tr>
                                                                                <th style="width:70%">Nature of Collection</th>
                                                                                <th style="width:30%" class="text-end">Amount (₱)</th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody class="or-print-items"></tbody>
                                                                        <tfoot>
                                                                            <tr>
                                                                                <td class="fw-bold">TOTAL</td>
                                                                                <td class="text-end fw-bold">₱<span class="or-print-total">0.00</span></td>
                                                                            </tr>
                                                                        </tfoot>
                                                                    </table>
                                                                </div>
																<div class="mb-1"><strong>Amount in Words:</strong> <span class="or-print-words"></span></div>
                                                                <div class="mb-1"><strong>Payment Method:</strong> <span class="or-print-method">Cash</span></div>
                                                                <div class="mb-1"><strong>Notes:</strong> <span class="or-print-notes">-</span></div>
                                                                <div class="mb-1"><strong>Portal Account:</strong> <span class="or-print-portal"></span></div>
                                                                <div class="d-flex justify-content-end mb-1">
                                                                    <div><strong>Cashier:</strong> <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></div>
                                                                </div>
                                                            </div>

                                                            <div class="row g-3 mt-2">
                                                                <div class="col-12">
                                                                    <label class="form-label">Payment Method</label>
                                                                    <div class="d-flex gap-3">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input or-method" type="radio" name="or_method_<?php echo $r['pay_id']; ?>" id="orMethodCash<?php echo $r['pay_id']; ?>" value="Cash" checked>
                                                                            <label class="form-check-label" for="orMethodCash<?php echo $r['pay_id']; ?>">Cash</label>
                                                                        </div>
                                                                        <div class="form-check">
                                                                            <input class="form-check-input or-method" type="radio" name="or_method_<?php echo $r['pay_id']; ?>" id="orMethodCheck<?php echo $r['pay_id']; ?>" value="Check">
                                                                            <label class="form-check-label" for="orMethodCheck<?php echo $r['pay_id']; ?>">Check</label>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-12">
                                                                    <label class="form-label">Notes</label>
                                                                    <input type="text" class="form-control or-notes-input" placeholder="Optional notes">
                                                                </div>
                                                            </div>

                                                            <div class="mb-2 mt-2">
                                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                                    <label class="form-label small mb-0">Discounts</label>
                                                                    <div class="d-flex gap-2">
                                                                        <select class="form-select form-select-sm or-discount-select" style="min-width: 200px;">
                                                                            <option value="">Select discount…</option>
                                                                            <option value="senior">Senior Citizen - 20%</option>
                                                                            <option value="pwd">PWD - 20%</option>
                                                                            <option value="philhealth">Philhealth - Custom %</option>
                                                                        </select>
                                                                        <button type="button" class="btn btn-sm btn-outline-secondary or-add-discount"><i class="fas fa-plus"></i></button>
                                                                    </div>
                                                                </div>
																<table class="table table-sm mb-1">
																	<thead>
                                                                        <tr><th style="width:70%">Label</th><th style="width:25%" class="text-end">%</th><th style="width:5%"></th></tr>
																	</thead>
																	<tbody class="or-discounts"></tbody>
																</table>
															</div>

															<table class="table table-sm mb-2">
																<tbody>
																	<tr>
																		<td style="width:70%">Subtotal</td>
																		<td class="text-end pe-0" style="width:25%"><input type="text" class="form-control form-control-sm text-end or-subtotal" value="0.00" disabled></td>
																		<td style="width:5%"></td>
																	</tr>
																	<tr>
																		<td>Discount Amt</td>
																		<td class="text-end pe-0"><input type="text" class="form-control form-control-sm text-end or-disc-amt" value="0.00" disabled></td>
																		<td></td>
																	</tr>
																	<tr class="table-active">
																		<td class="fw-bold">TOTAL</td>
																		<td class="text-end pe-0"><input type="text" class="form-control form-control-sm text-end or-total fw-bold" value="0.00" disabled></td>
																		<td></td>
																	</tr>
																</tbody>
															</table>

															<div class="row g-2 align-items-end">
																<div class="col-6">
																	<label class="form-label">Cash Intended</label>
																	<input type="number" step="0.01" min="0" class="form-control or-cash" placeholder="0.00">
																</div>
																<div class="col-6 text-end">
																	<div class="small text-muted">Change</div>
																	<div class="fs-6 fw-semibold">₱<span class="or-change">0.00</span></div>
																</div>
                                                            </div>
														</div>
                                                <div class="form-text mt-2">O.R. preview mirrors the Lab Payments and Discharge Billing style.</div>
													</div>
													<div class="modal-footer">
														<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i>Confirm Payment</button>
													</div>
												</form>
											</div>
										</div>
									</div>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
    <?php endif; ?>
	</div>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
	<script>
	document.addEventListener('DOMContentLoaded', function(){
		document.querySelectorAll('.modal').forEach(function(modal){
			modal.addEventListener('shown.bs.modal', function(ev){
				const root = ev.target;
				const cash = root.querySelector('.or-cash');
				const changeEl = root.querySelector('.or-change');
				const itemsBody = root.querySelector('.or-items');
				const addItemBtn = root.querySelector('.or-add-item');
				const addDiscBtn = root.querySelector('.or-add-discount');
				const discTable = root.querySelector('.or-discounts');
				const subtotalEl = root.querySelector('.or-subtotal');
				const discAmtEl = root.querySelector('.or-disc-amt');
				const totalEl = root.querySelector('.or-total');
            const totalHidden = root.querySelector('.or-total-hidden');
				// Print preview bindings
				const printItems = root.querySelector('.or-print-items');
				const printTotal = root.querySelector('.or-print-total');
                const printMethod = root.querySelector('.or-print-method');
				const printNotes = root.querySelector('.or-print-notes');
                const printWords = root.querySelector('.or-print-words');
                const printCash = root.querySelector('.or-print-cash');
                const printChange = root.querySelector('.or-print-change');
				const methodInputs = Array.from(root.querySelectorAll('.or-method'));
                // account input removed
				const notesInput = root.querySelector('.or-notes-input');
				// Simple localStorage helpers for portal creds (persist across sessions)
				function loadPortalCredByVisit(vid){
					try {
						const raw = localStorage.getItem('portalCreds_'+String(vid));
						return raw ? JSON.parse(raw) : null;
					} catch(e) { return null; }
				}
				function savePortalCredByVisit(vid, username, password){
					try {
						const toSave = { username: username || '', password: password || '' };
						localStorage.setItem('portalCreds_'+String(vid), JSON.stringify(toSave));
					} catch(e) {}
				}
				function loadPortalCredByPrid(prid){
					try {
						const raw = localStorage.getItem('portalCredsPrid_'+String(prid));
						return raw ? JSON.parse(raw) : null;
					} catch(e) { return null; }
				}
				function savePortalCredByPrid(prid, username, password){
					try {
						const toSave = { username: username || '', password: password || '' };
						localStorage.setItem('portalCredsPrid_'+String(prid), JSON.stringify(toSave));
					} catch(e) {}
				}
				function loadPortalCredByUser(user){
					try {
						const raw = localStorage.getItem('portalCredsUser_'+String(user||''));
						return raw ? JSON.parse(raw) : null;
					} catch(e) { return null; }
				}
				function savePortalCredByUser(user, username, password){
					try {
						const toSave = { username: username || '', password: password || '' };
						localStorage.setItem('portalCredsUser_'+String(user||''), JSON.stringify(toSave));
					} catch(e) {}
				}
				function toNum(v){ return parseFloat(v || '0') || 0; }
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
				function bindRow(row){
					if (row.dataset.bound === '1') return;
					row.dataset.bound = '1';
					row.querySelectorAll('.or-amt, .or-desc').forEach(inp => {
						if (!inp.dataset.bound) { inp.addEventListener('input', recalc); inp.dataset.bound = '1'; }
					});
					const del = row.querySelector('.or-remove');
					if (del && !del.dataset.bound) { del.addEventListener('click', function(){ row.remove(); recalc(); }); del.dataset.bound = '1'; }
				}
				function recalc(){
                let sub = 0; itemsBody && itemsBody.querySelectorAll('.or-amt').forEach(i => { sub += toNum(i.value); });
					if (subtotalEl) subtotalEl.value = sub.toFixed(2);
                let pct = 0; discTable && discTable.querySelectorAll('.or-disc-pct').forEach(i => { pct += toNum(i.value); });
                const disc = (pct / 100) * sub; if (discAmtEl) discAmtEl.value = disc.toFixed(2);
                const total = Math.max(0, sub - disc); if (totalEl) totalEl.value = total.toFixed(2); if (totalHidden) totalHidden.value = total.toFixed(2);
                if (cash && changeEl) { const tendered = toNum(cash.value); const ch = Math.max(0, tendered - total); changeEl.textContent = ch.toFixed(2); if (printCash) printCash.textContent = tendered.toFixed(2); if (printChange) printChange.textContent = ch.toFixed(2); }
					// Build print preview (2 columns: Nature of Collection, Amount)
					if (printItems) {
						printItems.innerHTML = '';
						itemsBody && itemsBody.querySelectorAll('tr').forEach(function(tr){
							const d = (tr.querySelector('.or-desc')?.value || '').trim();
							const a = toNum(tr.querySelector('.or-amt')?.value || '0');
							if (d !== '') {
								const row = document.createElement('tr');
								row.innerHTML = '<td>'+d.replace(/</g,'&lt;')+'</td><td class="text-end">₱'+a.toFixed(2)+'</td>';
								printItems.appendChild(row);
							}
						});
					}
					if (printTotal) { printTotal.textContent = (totalEl ? totalEl.value : total.toFixed(2)); }
					if (printMethod) {
						const sel = methodInputs.find(i => i.checked);
						printMethod.textContent = sel ? sel.value : 'Cash';
					}
					if (printNotes) { printNotes.textContent = (notesInput && notesInput.value.trim()) ? notesInput.value.trim() : '-'; }
                    if (printWords) { 
                        // Generate words for the total
                        try { printWords.textContent = numberToWords(total); } catch(_) {}
                    }
				}
				if (itemsBody) itemsBody.querySelectorAll('tr').forEach(bindRow);
            if (addItemBtn && !addItemBtn.dataset.bound) { addItemBtn.addEventListener('click', function(){ const tr = document.createElement('tr'); tr.innerHTML = '<td><input type="text" class="form-control form-control-sm or-desc" placeholder="Description"></td>'+'<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end or-amt" value="0.00"></td>'+'<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger or-remove">&times;</button></td>'; itemsBody.appendChild(tr); bindRow(tr); recalc(); }); addItemBtn.dataset.bound = '1'; }
            if (addDiscBtn && !addDiscBtn.dataset.bound) {
                addDiscBtn.addEventListener('click', function(){
                    const select = root.querySelector('.or-discount-select');
                    if (!select) return;
                    const val = select.value; if (!val) return;
                    let label = ''; let pct = 0; let locked = false;
                    if (val === 'senior') { label = 'Senior Citizen'; pct = 20; locked = true; }
                    else if (val === 'pwd') { label = 'PWD'; pct = 20; locked = true; }
                    else if (val === 'philhealth') { label = 'Philhealth'; pct = 0; locked = false; }
                    const tr = document.createElement('tr');
                    tr.setAttribute('data-discount', val);
                    tr.innerHTML = '<td><input type="text" class="form-control form-control-sm or-disc-label" value="'+label.replace(/"/g,'&quot;')+'" readonly></td>'+
                                   '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end or-disc-pct" value="'+pct.toFixed(2)+'" '+(locked?'disabled':'')+'></td>'+
                                   '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger or-del-discount">&times;</button></td>';
                    discTable.appendChild(tr);
                    const pctInp = tr.querySelector('.or-disc-pct'); pctInp && pctInp.addEventListener('input', recalc);
                    const delBtn = tr.querySelector('.or-del-discount');
                    if (delBtn) {
                        delBtn.addEventListener('click', function(){
                            tr.remove();
                            const opt = select.querySelector('option[value="'+val+'"]'); if (opt) opt.disabled = false;
                            recalc();
                        });
                    }
                    const opt = select.querySelector('option[value="'+val+'"]'); if (opt) opt.disabled = true;
                    select.value='';
                    recalc();
                });
                addDiscBtn.dataset.bound = '1';
            }
				if (cash && !cash.dataset.bound) { cash.addEventListener('input', recalc); cash.dataset.bound = '1'; }
				methodInputs.forEach(function(mi){ if (!mi.dataset.bound){ mi.addEventListener('change', recalc); mi.dataset.bound='1'; } });
				if (notesInput && !notesInput.dataset.bound) { notesInput.addEventListener('input', recalc); notesInput.dataset.bound='1'; }
            recalc(); cash && cash.focus();

            // Ensure portal account and show credentials (username / optional temp password)
            try {
                const formEl = root.querySelector('form.or-form');
                const acctInfoEl = root.querySelector('#acctInfo'+(formEl ? formEl.getAttribute('data-payment-id') : ''));
                const visitId = formEl ? Number(formEl.getAttribute('data-visit-id')||0) : 0;
                if (visitId) {
                    // Render from cache first if available
                    window._portalCredsByVisit = window._portalCredsByVisit || {};
                    let cached = loadPortalCredByVisit(visitId) || window._portalCredsByVisit[visitId];
                    if (cached && (cached.username || cached.password)) {
                        if (acctInfoEl) { acctInfoEl.innerHTML = '<strong>Portal Account</strong>: ' + (cached.username || '') + (cached.password ? ' / ' + cached.password : ''); }
                        const ps = root.querySelector('.or-print-portal'); if (ps) { ps.textContent = (cached.username || '') + (cached.password ? ' / ' + cached.password : ''); }
                        window._portalCredsByVisit[visitId] = cached;
                    } else {
                        if (acctInfoEl) { acctInfoEl.textContent = ''; }
                    }
                    fetch('ensure_patient_portal_account.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ visit_id: visitId }) })
                      .then(r=>r.json())
                      .then(data=>{
                          if (data && data.success) {
                              // Determine best-available password for display
                              let finalPwd = (data.password && data.password !== '') ? data.password : '';
                              if (!finalPwd) {
                                  const byVisit = loadPortalCredByVisit(visitId);
                                  if (byVisit && byVisit.password) finalPwd = byVisit.password;
                              }
                              if (!finalPwd && data.patient_record_id) {
                                  const cp = loadPortalCredByPrid(data.patient_record_id);
                                  if (cp && cp.password) finalPwd = cp.password;
                              }
                              if (!finalPwd && data.username) {
                                  const cu = loadPortalCredByUser(data.username);
                                  if (cu && cu.password) finalPwd = cu.password;
                              }
                              const cred = { username: data.username, password: finalPwd };
                              window._portalCredsByVisit[visitId] = cred;
                              savePortalCredByVisit(visitId, cred.username, cred.password);
                              if (data.patient_record_id) { savePortalCredByPrid(data.patient_record_id, cred.username, cred.password); }
                              if (data.username) { savePortalCredByUser(data.username, cred.username, cred.password); }
                              if (acctInfoEl) { acctInfoEl.innerHTML = '<strong>Portal Account</strong>: ' + data.username + (finalPwd ? ' / ' + finalPwd : ''); }
                              const ps = root.querySelector('.or-print-portal'); if (ps) { ps.textContent = data.username + (finalPwd ? ' / ' + finalPwd : ''); }
                          }
                      })
                      .catch(()=>{});
                }
            } catch(e) { /* ignore */ }
			});
		});

    // Capture and submit
		document.querySelectorAll('.or-form').forEach(function(form){
			form.addEventListener('submit', function(ev){
				const captureEl = form.querySelector('.or-capture-print');
            const totEl = form.querySelector('.or-total');
            const totHidden = form.querySelector('.or-total-hidden');
				const cashEl = form.querySelector('.or-cash');
            const total = parseFloat((totEl && totEl.value) || '0') || 0;
				const tendered = parseFloat((cashEl && cashEl.value) || '0') || 0;
            if (tendered < total) { ev.preventDefault(); alert('Insufficient cash tendered.'); cashEl && cashEl.focus(); return; }
            if (totHidden) totHidden.value = total.toFixed(2);
            if (!captureEl) return;
            try {
                const acctInfo = document.getElementById('acctInfo'+(form.getAttribute('data-payment-id')||'')); // may be null
                const visitId = Number(form.getAttribute('data-visit-id')||0);
                // Ensure portal account present in print before capture
                fetch('ensure_patient_portal_account.php', { method:'POST', headers:{'Content-Type':'application/json'}, cache:'no-store', body: JSON.stringify({ visit_id: visitId }) })
                  .then(r=>r.json()).then(data=>{
                      if (data && data.success) {
                          let finalPwd = (data.password && data.password !== '') ? data.password : '';
                          if (!finalPwd) {
                              const prev = (function(){ try { return JSON.parse(localStorage.getItem('portalCreds_'+String(visitId))||'{}'); } catch(_) { return {}; } })();
                              if (prev && prev.password) finalPwd = prev.password;
                          }
                          if (acctInfo) { acctInfo.innerHTML = '<strong>Portal Account</strong>: ' + data.username + (finalPwd ? ' / ' + finalPwd : ''); }
                          const ps = form.querySelector('.or-print-portal'); if (ps) { ps.textContent = data.username + (finalPwd ? ' / ' + finalPwd : ''); }
                      }
                  })
                  .catch(()=>{})
                  .finally(()=>{
                      // Give the DOM a brief tick then capture
                      setTimeout(function(){
                        html2canvas(captureEl, {scale:2, useCORS:true}).then(function(canvas){
                          canvas.toBlob(function(blob){
                            const a = document.createElement('a');
                            const url = URL.createObjectURL(blob);
                            a.href = url; a.download = 'OPD_OR_'+ (form.getAttribute('data-payment-id')||'') +'.png';
                            document.body.appendChild(a); a.click();
                            setTimeout(function(){ URL.revokeObjectURL(url); a.remove(); form.submit(); }, 150);
                          }, 'image/png');
                        });
                      }, 100);
                  });
                ev.preventDefault();
            } catch(e) { /* fallback submit */ }
			});
		});
	});
	</script>
</body>
</html>
