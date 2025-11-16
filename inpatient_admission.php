<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['medical_records', 'receptionist', 'nurse'])) {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$success_message = $error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        // Insert admission record
        $admission_query = "INSERT INTO patient_admissions (
            patient_id, admission_source, admission_notes, 
            consent_signed, consent_signed_by, consent_relationship, consent_datetime,
            admitted_by
        ) VALUES (
            :patient_id, :admission_source, :admission_notes,
            :consent_signed, :consent_signed_by, :consent_relationship, 
            CASE WHEN :consent_signed = 1 THEN CURRENT_TIMESTAMP ELSE NULL END,
            :admitted_by
        )";

        $admission_stmt = $db->prepare($admission_query);
        $admission_stmt->bindParam(":patient_id", $_POST['patient_id']);
        $admission_stmt->bindParam(":admission_source", $_POST['admission_source']);
        $enc_notes = encrypt_strict((string)$_POST['admission_notes']);
        $admission_stmt->bindParam(":admission_notes", $enc_notes);
        $admission_stmt->bindParam(":consent_signed", $_POST['consent_signed']);
        $admission_stmt->bindParam(":consent_signed_by", $_POST['consent_signed_by']);
        $admission_stmt->bindParam(":consent_relationship", $_POST['consent_relationship']);
        $admission_stmt->bindParam(":admitted_by", $_SESSION['user_id']);
        
        if ($admission_stmt->execute()) {
            $admission_id = $db->lastInsertId();
            
            // Handle document uploads
            if (isset($_FILES['documents']) && !empty($_FILES['documents']['name'][0])) {
                $upload_dir = "uploads/admission_documents/";
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                foreach ($_FILES['documents']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['documents']['error'][$key] === 0) {
                        $file_extension = pathinfo($_FILES['documents']['name'][$key], PATHINFO_EXTENSION);
                        $new_filename = uniqid('doc_') . '.' . $file_extension;
                        $file_path = $upload_dir . $new_filename;

                        if (move_uploaded_file($tmp_name, $file_path)) {
                            $doc_query = "INSERT INTO admission_documents (
                                admission_id, document_type, file_path, notes, uploaded_by
                            ) VALUES (
                                :admission_id, :document_type, :file_path, :notes, :uploaded_by
                            )";
                            
                            $doc_stmt = $db->prepare($doc_query);
                            $doc_stmt->bindParam(":admission_id", $admission_id);
                            $doc_stmt->bindParam(":document_type", $_POST['document_types'][$key]);
                            $doc_stmt->bindParam(":file_path", $file_path);
                            $doc_stmt->bindParam(":notes", $_POST['document_notes'][$key]);
                            $doc_stmt->bindParam(":uploaded_by", $_SESSION['user_id']);
                            $doc_stmt->execute();
                        }
                    }
                }
            }

            $db->commit();
            $success_message = "Admission record created successfully.";
        }
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Error: " . $e->getMessage();
    }
}

// Get patient list for dropdown
$patient_query = "SELECT p.*, 
                        CASE 
                            WHEN ov.visit_status = 'completed' AND ov.diagnosis LIKE '%admission%' 
                            THEN 'Recommended for Admission'
                            ELSE ''
                        END as admission_recommendation
                 FROM patients p
                 LEFT JOIN opd_visits ov ON p.id = ov.patient_record_id 
                 WHERE ov.visit_status = 'completed'
                 ORDER BY p.name ASC";
$patient_stmt = $db->prepare($patient_query);
$patient_stmt->execute();
$patients = $patient_stmt->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$page_title = "Inpatient Admission";
require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inpatient Admission - Hospital Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .document-upload {
            border: 2px dashed #ddd;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .document-upload:hover {
            border-color: #aaa;
            cursor: pointer;
        }
        .consent-section {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row mb-4">
            <div class="col">
                <h2><i class="fas fa-procedures me-2"></i>Inpatient Admission</h2>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="admissionForm">
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">Patient Information</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Select Patient <span class="text-danger">*</span></label>
                                <select class="form-select" name="patient_id" required>
                                    <option value="">Choose patient...</option>
                                    <?php foreach ($patients as $patient): ?>
                                        <option value="<?php echo $patient['id']; ?>" 
                                                <?php echo $patient['admission_recommendation'] ? 'class="text-danger"' : ''; ?>>
                                            <?php 
                                                echo htmlspecialchars($patient['name']) . 
                                                     " (" . $patient['age'] . "y/" . ucfirst($patient['gender']) . ")" .
                                                     ($patient['admission_recommendation'] ? ' - ' . $patient['admission_recommendation'] : '');
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Admission Source <span class="text-danger">*</span></label>
                                <select class="form-select" name="admission_source" required>
                                    <option value="opd">OPD</option>
                                    <option value="emergency">Emergency</option>
                                    <option value="transfer">Transfer</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Admission Notes</label>
                        <textarea class="form-control" name="admission_notes" rows="3"></textarea>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">Consent Information</h5>
                    <div class="consent-section">
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="consent_signed" value="1" id="consentCheck">
                                <label class="form-check-label" for="consentCheck">
                                    Consent form has been signed
                                </label>
                            </div>
                        </div>
                        <div id="consentDetails" style="display: none;">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Signed By</label>
                                        <input type="text" class="form-control" name="consent_signed_by">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Relationship to Patient</label>
                                        <input type="text" class="form-control" name="consent_relationship">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">Document Upload</h5>
                    <div id="documentUploads">
                        <div class="document-upload mb-3">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Document Type</label>
                                        <select class="form-select" name="document_types[]">
                                            <option value="consent_form">Consent Form</option>
                                            <option value="patient_chart">Patient Chart</option>
                                            <option value="id_card">ID Card</option>
                                            <option value="insurance">Insurance</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">File</label>
                                        <input type="file" class="form-control" name="documents[]">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Notes</label>
                                        <input type="text" class="form-control" name="document_notes[]">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-primary" id="addDocument">
                        <i class="fas fa-plus me-2"></i>Add Another Document
                    </button>
                </div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <button type="button" class="btn btn-secondary me-md-2" onclick="history.back()">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-check me-2"></i>Create Admission
                </button>
            </div>
        </form>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle consent checkbox
            const consentCheck = document.getElementById('consentCheck');
            const consentDetails = document.getElementById('consentDetails');
            
            consentCheck.addEventListener('change', function() {
                consentDetails.style.display = this.checked ? 'block' : 'none';
            });

            // Handle document upload
            const addDocumentBtn = document.getElementById('addDocument');
            const documentUploads = document.getElementById('documentUploads');
            
            addDocumentBtn.addEventListener('click', function() {
                const template = documentUploads.children[0].cloneNode(true);
                // Clear input values
                template.querySelectorAll('input').forEach(input => input.value = '');
                documentUploads.appendChild(template);
            });
        });
    </script>
</body>
</html> 