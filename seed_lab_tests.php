<?php
session_start();
require_once "config/database.php";

echo "Seeding lab tests...<br>";

try {
	$database = new Database();
	$db = $database->getConnection();

	$tests = [];

	// Laboratory - Blood Chemistry
	$tests[] = ['Fasting Blood Sugar (FBS)', 'laboratory', 100.00, 'Blood Chemistry'];
	$tests[] = ['Random Blood Sugar (RBS)', 'laboratory', 100.00, 'Blood Chemistry'];
	$tests[] = ['Blood Uric Acid (BUA)', 'laboratory', 130.00, 'Blood Chemistry'];
	$tests[] = ['Blood Urea Nitrogen (BUN)', 'laboratory', 120.00, 'Blood Chemistry'];
	$tests[] = ['Creatinine', 'laboratory', 130.00, 'Blood Chemistry'];
	$tests[] = ['Total Cholesterol', 'laboratory', 110.00, 'Blood Chemistry'];
	$tests[] = ['Triglyceride', 'laboratory', 150.00, 'Blood Chemistry'];
	$tests[] = ['HDL', 'laboratory', 120.00, 'Blood Chemistry'];
	$tests[] = ['LDL', 'laboratory', 120.00, 'Blood Chemistry'];
	$tests[] = ['Lipid Profile', 'laboratory', 490.00, 'Blood Chemistry'];
	$tests[] = ['SGOT/AST', 'laboratory', 180.00, 'Blood Chemistry'];
	$tests[] = ['SGPT/ALT', 'laboratory', 180.00, 'Blood Chemistry'];
	$tests[] = ['Sodium (Na)', 'laboratory', 200.00, 'Blood Chemistry'];
	$tests[] = ['Potassium (K)', 'laboratory', 200.00, 'Blood Chemistry'];
	$tests[] = ['Calcium (Ca) total', 'laboratory', 200.00, 'Blood Chemistry'];
	$tests[] = ['Ionized Calcium', 'laboratory', 450.00, 'Blood Chemistry'];
	$tests[] = ['Chloride (Cl)', 'laboratory', 200.00, 'Blood Chemistry'];
	$tests[] = ['HbA1c', 'laboratory', 750.00, 'Blood Chemistry'];
	$tests[] = ['OGTT', 'laboratory', 700.00, 'Blood Chemistry'];
	$tests[] = ['Total Bilirubin', 'laboratory', 500.00, 'Blood Chemistry'];
	$tests[] = ['TPAG', 'laboratory', 350.00, 'Blood Chemistry'];

	// Laboratory - Hematology
	$tests[] = ['Complete Blood Count', 'laboratory', 170.00, 'Hematology'];
	$tests[] = ['Complete Blood Count with Platelet', 'laboratory', 250.00, 'Hematology'];
	$tests[] = ['Hemoglobin (Hgb)', 'laboratory', 80.00, 'Hematology'];
	$tests[] = ['Hematocrit (Hct)', 'laboratory', 80.00, 'Hematology'];
	$tests[] = ['White Blood Cell with Differential Count', 'laboratory', 50.00, 'Hematology'];
	$tests[] = ['Platelet Count', 'laboratory', 75.00, 'Hematology'];
	$tests[] = ['Clotting Time, Bleeding Time', 'laboratory', 100.00, 'Hematology'];

	// Laboratory - Serology
	$tests[] = ['Dengue Duo', 'laboratory', 1300.00, 'Serology'];
	$tests[] = ['Dengue NS1', 'laboratory', 200.00, 'Serology'];
	$tests[] = ['HBsAg', 'laboratory', 250.00, 'Serology'];
	$tests[] = ['Typhidot', 'laboratory', 1000.00, 'Serology'];

	// Laboratory - Blood Grouping
	$tests[] = ['Blood Typing + Rh Factor', 'laboratory', 150.00, 'Blood Grouping'];
	$tests[] = ['Rh Factor', 'laboratory', 60.00, 'Blood Grouping'];

	// Laboratory - Clinical Microscopy
	$tests[] = ['FA/Fecalysis', 'laboratory', 50.00, 'Clinical Microscopy'];
	$tests[] = ['UA/Urinalysis', 'laboratory', 50.00, 'Clinical Microscopy'];
	$tests[] = ['Urine Albumin', 'laboratory', 30.00, 'Clinical Microscopy'];
	$tests[] = ['Pregnancy Test (Urine)', 'laboratory', 150.00, 'Clinical Microscopy'];
	$tests[] = ['Gram Staining', 'laboratory', 120.00, 'Clinical Microscopy'];
	$tests[] = ['KOH Mounting', 'laboratory', 150.00, 'Clinical Microscopy'];
	$tests[] = ['Occult Blood', 'laboratory', 250.00, 'Clinical Microscopy'];

	// Radiology - Ultrasound
	$tests[] = ['Ultrasound - Abdomino-Pelvic', 'radiology', 1950.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - Hemithorax', 'radiology', 650.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - HBT', 'radiology', 650.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - KUB and Pelvic/Prostate', 'radiology', 1300.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - KUB', 'radiology', 910.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - KUB (Pre and Post Void)', 'radiology', 1560.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - Lower Abdomen', 'radiology', 910.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - Pelvic', 'radiology', 650.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - Renal', 'radiology', 910.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - Upper Abdomen', 'radiology', 910.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - Whole Abdomen', 'radiology', 1560.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - Whole Abdomen with Prostate', 'radiology', 1950.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - Cranial', 'radiology', 910.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - Neck', 'radiology', 910.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - Thyroid', 'radiology', 910.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - Breast (Unilateral)', 'radiology', 910.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - Breast (Bilateral)', 'radiology', 1820.00, 'Ultrasound'];
	$tests[] = ['Ultrasound - Transvaginal', 'radiology', 1300.00, 'Ultrasound'];

	// Radiology - X-ray
	$tests[] = ['X-ray - Chest (PA)', 'radiology', 250.00, 'X-ray'];
	$tests[] = ['X-ray - Chest (APL)', 'radiology', 350.00, 'X-ray'];
	$tests[] = ['X-ray - Plain Abdomen / KUB', 'radiology', 300.00, 'X-ray'];
	$tests[] = ['X-ray - Plain Abdomen Upright / Supine', 'radiology', 600.00, 'X-ray'];
	$tests[] = ['X-ray - Plain Abdomen (Upright / Supine / Lateral)', 'radiology', 900.00, 'X-ray'];
	$tests[] = ['X-ray - Skull APL', 'radiology', 450.00, 'X-ray'];
	$tests[] = ['X-ray - Lumbosacral Spine APL', 'radiology', 550.00, 'X-ray'];
	$tests[] = ['X-ray - Cervical Spine APL Paranasal Sinuses', 'radiology', 450.00, 'X-ray'];
	$tests[] = ['X-ray - Skull Series', 'radiology', 900.00, 'X-ray'];
	$tests[] = ['X-ray - Extremities', 'radiology', 160.00, 'X-ray'];

	$insert = $db->prepare("INSERT INTO lab_tests (test_name, test_type, cost, description) VALUES (:name, :type, :cost, :desc)");
	$exists = $db->prepare("SELECT id FROM lab_tests WHERE test_name = :name LIMIT 1");

	$countInserted = 0;
	foreach ($tests as $t) {
		list($name, $type, $cost, $desc) = $t;
		$exists->execute([':name' => $name]);
		if (!$exists->fetch(PDO::FETCH_ASSOC)) {
			$insert->execute([':name' => $name, ':type' => $type, ':cost' => $cost, ':desc' => $desc]);
			$countInserted++;
		}
	}
	echo "Inserted $countInserted tests (skipped existing).<br>";
	echo "Done.";

} catch (Exception $e) {
	echo "Error: " . htmlspecialchars($e->getMessage());
}
