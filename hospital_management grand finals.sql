create database hospital_management;
use hospital_management;



-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 26, 2025 at 04:10 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `hospital_management`
--

-- --------------------------------------------------------

--
-- Table structure for table `admission_billing`
--

CREATE TABLE `admission_billing` (
  `id` int(11) NOT NULL,
  `admission_id` int(11) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_label` varchar(100) DEFAULT NULL,
  `total_due` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('unpaid','paid') DEFAULT 'unpaid',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admission_billing_items`
--

CREATE TABLE `admission_billing_items` (
  `id` int(11) NOT NULL,
  `billing_id` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admission_billing_suppressed`
--

CREATE TABLE `admission_billing_suppressed` (
  `id` int(11) NOT NULL,
  `billing_id` int(11) NOT NULL,
  `type` enum('lab') NOT NULL,
  `ref_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `beds`
--

CREATE TABLE `beds` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `bed_number` varchar(50) NOT NULL,
  `status` enum('available','occupied','maintenance') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bed_profile_tokens`
--

CREATE TABLE `bed_profile_tokens` (
  `id` int(11) NOT NULL,
  `bed_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `birth_certificates`
--

CREATE TABLE `birth_certificates` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `admission_id` int(11) DEFAULT NULL,
  `mother_name` varchar(150) NOT NULL,
  `father_name` varchar(150) DEFAULT NULL,
  `newborn_name` varchar(150) DEFAULT NULL,
  `sex` enum('male','female') NOT NULL,
  `date_of_birth` date NOT NULL,
  `time_of_birth` time NOT NULL,
  `place_of_birth` varchar(255) NOT NULL,
  `birth_weight_kg` decimal(5,2) DEFAULT NULL,
  `birth_length_cm` decimal(5,2) DEFAULT NULL,
  `type_of_birth` enum('single','twin','multiple') DEFAULT 'single',
  `attendant_at_birth` varchar(150) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('submitted','approved','rejected') DEFAULT 'submitted',
  `submitted_by` int(11) NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `doctors_orders`
--

CREATE TABLE `doctors_orders` (
  `id` int(11) NOT NULL,
  `admission_id` int(11) NOT NULL,
  `order_type` enum('medication','lab_test','diagnostic','diet','activity','monitoring','other','discharge') NOT NULL,
  `order_details` text NOT NULL,
  `frequency` varchar(100) DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `special_instructions` text DEFAULT NULL,
  `status` enum('active','in_progress','completed','discontinued') DEFAULT 'active',
  `ordered_by` int(11) NOT NULL,
  `ordered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `claimed_by` int(11) DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completion_note` text DEFAULT NULL,
  `discontinued_by` int(11) DEFAULT NULL,
  `discontinued_at` datetime DEFAULT NULL,
  `discontinue_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_payments`
--

CREATE TABLE `lab_payments` (
  `id` int(11) NOT NULL,
  `lab_request_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_status` enum('pending','completed','refunded') DEFAULT 'pending',
  `payment_date` timestamp NULL DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_requests`
--

CREATE TABLE `lab_requests` (
  `id` int(11) NOT NULL,
  `visit_id` int(11) DEFAULT NULL,
  `admission_id` int(11) DEFAULT NULL,
  `doctor_id` int(11) NOT NULL,
  `test_id` int(11) NOT NULL,
  `priority` enum('normal','urgent','emergency') NOT NULL DEFAULT 'normal',
  `status` enum('pending_payment','pending','in_progress','completed','cancelled') DEFAULT 'pending_payment',
  `payment_status` enum('unpaid','paid','refunded') DEFAULT 'unpaid',
  `notes` text DEFAULT NULL,
  `result` text DEFAULT NULL,
  `result_file_path` varchar(255) DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_tests`
--

CREATE TABLE `lab_tests` (
  `id` int(11) NOT NULL,
  `test_name` varchar(100) NOT NULL,
  `test_type` enum('laboratory','radiology') NOT NULL,
  `cost` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lab_tests`
--

INSERT INTO `lab_tests` (`id`, `test_name`, `test_type`, `cost`, `description`, `created_at`) VALUES
(11, 'Fasting Blood Sugar (FBS)', 'laboratory', 100.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(12, 'Random Blood Sugar (RBS)', 'laboratory', 100.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(13, 'Blood Uric Acid (BUA)', 'laboratory', 130.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(14, 'Blood Urea Nitrogen (BUN)', 'laboratory', 120.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(15, 'Creatinine', 'laboratory', 130.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(16, 'Total Cholesterol', 'laboratory', 110.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(17, 'Triglyceride', 'laboratory', 150.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(18, 'HDL', 'laboratory', 120.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(19, 'LDL', 'laboratory', 120.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(20, 'Lipid Profile', 'laboratory', 490.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(21, 'SGOT/AST', 'laboratory', 180.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(22, 'SGPT/ALT', 'laboratory', 180.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(23, 'Sodium (Na)', 'laboratory', 200.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(24, 'Potassium (K)', 'laboratory', 200.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(25, 'Calcium (Ca) total', 'laboratory', 200.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(26, 'Ionized Calcium', 'laboratory', 450.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(27, 'Chloride (Cl)', 'laboratory', 200.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(28, 'HbA1c', 'laboratory', 750.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(29, 'OGTT', 'laboratory', 700.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(30, 'Total Bilirubin', 'laboratory', 500.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(31, 'TPAG', 'laboratory', 350.00, 'Blood Chemistry', '2025-09-25 00:58:16'),
(32, 'Complete Blood Count', 'laboratory', 170.00, 'Hematology', '2025-09-25 00:58:16'),
(33, 'Complete Blood Count with Platelet', 'laboratory', 250.00, 'Hematology', '2025-09-25 00:58:16'),
(34, 'Hemoglobin (Hgb)', 'laboratory', 80.00, 'Hematology', '2025-09-25 00:58:16'),
(35, 'Hematocrit (Hct)', 'laboratory', 80.00, 'Hematology', '2025-09-25 00:58:16'),
(36, 'White Blood Cell with Differential Count', 'laboratory', 50.00, 'Hematology', '2025-09-25 00:58:16'),
(37, 'Platelet Count', 'laboratory', 75.00, 'Hematology', '2025-09-25 00:58:16'),
(38, 'Clotting Time, Bleeding Time', 'laboratory', 100.00, 'Hematology', '2025-09-25 00:58:16'),
(39, 'Dengue Duo', 'laboratory', 1300.00, 'Serology', '2025-09-25 00:58:16'),
(40, 'Dengue NS1', 'laboratory', 200.00, 'Serology', '2025-09-25 00:58:16'),
(41, 'HBsAg', 'laboratory', 250.00, 'Serology', '2025-09-25 00:58:16'),
(42, 'Typhidot', 'laboratory', 1000.00, 'Serology', '2025-09-25 00:58:16'),
(43, 'Blood Typing + Rh Factor', 'laboratory', 150.00, 'Blood Grouping', '2025-09-25 00:58:16'),
(44, 'Rh Factor', 'laboratory', 60.00, 'Blood Grouping', '2025-09-25 00:58:16'),
(45, 'FA/Fecalysis', 'laboratory', 50.00, 'Clinical Microscopy', '2025-09-25 00:58:16'),
(46, 'UA/Urinalysis', 'laboratory', 50.00, 'Clinical Microscopy', '2025-09-25 00:58:16'),
(47, 'Urine Albumin', 'laboratory', 30.00, 'Clinical Microscopy', '2025-09-25 00:58:16'),
(48, 'Pregnancy Test (Urine)', 'laboratory', 150.00, 'Clinical Microscopy', '2025-09-25 00:58:16'),
(49, 'Gram Staining', 'laboratory', 120.00, 'Clinical Microscopy', '2025-09-25 00:58:16'),
(50, 'KOH Mounting', 'laboratory', 150.00, 'Clinical Microscopy', '2025-09-25 00:58:16'),
(51, 'Occult Blood', 'laboratory', 250.00, 'Clinical Microscopy', '2025-09-25 00:58:16'),
(52, 'Ultrasound - Abdomino-Pelvic', 'radiology', 1950.00, 'Ultrasound', '2025-09-25 00:58:16'),
(53, 'Ultrasound - Hemithorax', 'radiology', 650.00, 'Ultrasound', '2025-09-25 00:58:16'),
(54, 'Ultrasound - HBT', 'radiology', 650.00, 'Ultrasound', '2025-09-25 00:58:16'),
(55, 'Ultrasound - KUB and Pelvic/Prostate', 'radiology', 1300.00, 'Ultrasound', '2025-09-25 00:58:16'),
(56, 'Ultrasound - KUB', 'radiology', 910.00, 'Ultrasound', '2025-09-25 00:58:16'),
(57, 'Ultrasound - KUB (Pre and Post Void)', 'radiology', 1560.00, 'Ultrasound', '2025-09-25 00:58:16'),
(58, 'Ultrasound - Lower Abdomen', 'radiology', 910.00, 'Ultrasound', '2025-09-25 00:58:16'),
(59, 'Ultrasound - Pelvic', 'radiology', 650.00, 'Ultrasound', '2025-09-25 00:58:16'),
(60, 'Ultrasound - Renal', 'radiology', 910.00, 'Ultrasound', '2025-09-25 00:58:16'),
(61, 'Ultrasound - Upper Abdomen', 'radiology', 910.00, 'Ultrasound', '2025-09-25 00:58:16'),
(62, 'Ultrasound - Whole Abdomen', 'radiology', 1560.00, 'Ultrasound', '2025-09-25 00:58:16'),
(63, 'Ultrasound - Whole Abdomen with Prostate', 'radiology', 1950.00, 'Ultrasound', '2025-09-25 00:58:16'),
(64, 'Ultrasound - Cranial', 'radiology', 910.00, 'Ultrasound', '2025-09-25 00:58:16'),
(65, 'Ultrasound - Neck', 'radiology', 910.00, 'Ultrasound', '2025-09-25 00:58:16'),
(66, 'Ultrasound - Thyroid', 'radiology', 910.00, 'Ultrasound', '2025-09-25 00:58:16'),
(67, 'Ultrasound - Breast (Unilateral)', 'radiology', 910.00, 'Ultrasound', '2025-09-25 00:58:16'),
(68, 'Ultrasound - Breast (Bilateral)', 'radiology', 1820.00, 'Ultrasound', '2025-09-25 00:58:16'),
(69, 'Ultrasound - Transvaginal', 'radiology', 1300.00, 'Ultrasound', '2025-09-25 00:58:16'),
(70, 'X-ray - Chest (PA)', 'radiology', 250.00, 'X-ray', '2025-09-25 00:58:17'),
(71, 'X-ray - Chest (APL)', 'radiology', 350.00, 'X-ray', '2025-09-25 00:58:17'),
(72, 'X-ray - Plain Abdomen / KUB', 'radiology', 300.00, 'X-ray', '2025-09-25 00:58:17'),
(73, 'X-ray - Plain Abdomen Upright / Supine', 'radiology', 600.00, 'X-ray', '2025-09-25 00:58:17'),
(74, 'X-ray - Plain Abdomen (Upright / Supine / Lateral)', 'radiology', 900.00, 'X-ray', '2025-09-25 00:58:17'),
(75, 'X-ray - Skull APL', 'radiology', 450.00, 'X-ray', '2025-09-25 00:58:17'),
(76, 'X-ray - Lumbosacral Spine APL', 'radiology', 550.00, 'X-ray', '2025-09-25 00:58:17'),
(77, 'X-ray - Cervical Spine APL Paranasal Sinuses', 'radiology', 450.00, 'X-ray', '2025-09-25 00:58:17'),
(78, 'X-ray - Skull Series', 'radiology', 900.00, 'X-ray', '2025-09-25 00:58:17'),
(79, 'X-ray - Extremities', 'radiology', 160.00, 'X-ray', '2025-09-25 00:58:17');

-- --------------------------------------------------------

--
-- Table structure for table `medicines`
--

CREATE TABLE `medicines` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `generic_name` varchar(100) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `unit` varchar(20) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL,
  `reorder_level` int(11) NOT NULL DEFAULT 10,
  `low_stock_threshold` int(11) NOT NULL DEFAULT 0,
  `near_expiry_days` int(11) NOT NULL DEFAULT 30,
  `expiration_date` date DEFAULT NULL,
  `manufacturer` varchar(100) DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `medicine_orders`
--

CREATE TABLE `medicine_orders` (
  `id` int(11) NOT NULL,
  `order_number` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','processing','completed','cancelled') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `medicine_order_items`
--

CREATE TABLE `medicine_order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `medicine_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `instructions` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `medicine_order_notifications`
--

CREATE TABLE `medicine_order_notifications` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `notification_type` enum('new_order','order_processed','order_completed','order_cancelled') NOT NULL,
  `status` enum('unread','read') DEFAULT 'unread',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `nursing_notes`
--

CREATE TABLE `nursing_notes` (
  `id` int(11) NOT NULL,
  `admission_id` int(11) NOT NULL,
  `note_type` enum('assessment','medication','intervention','monitoring','handover') NOT NULL,
  `note_text` text NOT NULL,
  `vital_signs` text DEFAULT NULL,
  `intake_output` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `opd_visits`
--

CREATE TABLE `opd_visits` (
  `id` int(11) NOT NULL,
  `patient_record_id` int(11) DEFAULT NULL,
  `patient_name` varchar(100) NOT NULL,
  `age` int(11) NOT NULL,
  `gender` enum('male','female','other') NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `birthdate` date DEFAULT NULL,
  `civil_status` enum('single','married','widowed','separated','unknown') DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `head_of_family` varchar(100) DEFAULT NULL,
  `religion` varchar(100) DEFAULT NULL,
  `philhealth` enum('yes','no') DEFAULT NULL,
  `arrival_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `symptoms` text NOT NULL,
  `temperature` decimal(4,1) NOT NULL,
  `blood_pressure` varchar(10) NOT NULL,
  `pulse_rate` int(11) NOT NULL,
  `respiratory_rate` int(11) NOT NULL,
  `oxygen_saturation` int(11) DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `visit_type` enum('new','follow_up') NOT NULL,
  `pregnancy_status` enum('none','pregnant','labor') DEFAULT 'none',
  `visit_status` enum('waiting','in_progress','completed','cancelled') DEFAULT 'waiting',
  `registered_by` int(11) NOT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `lmp` date DEFAULT NULL,
  `medical_history` text DEFAULT NULL,
  `medications` text DEFAULT NULL,
  `noi` varchar(255) DEFAULT NULL,
  `poi` varchar(255) DEFAULT NULL,
  `doi` date DEFAULT NULL,
  `toi` time DEFAULT NULL,
  `consultation_start` timestamp NULL DEFAULT NULL,
  `consultation_end` timestamp NULL DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `treatment_plan` text DEFAULT NULL,
  `prescription` text DEFAULT NULL,
  `follow_up_date` date DEFAULT NULL,
  `progress_notes` text DEFAULT NULL,
  `last_progress_update` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `age` int(11) NOT NULL,
  `gender` enum('male','female','other') NOT NULL,
  `blood_group` varchar(5) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `doctor_id` int(11) NOT NULL,
  `added_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patient_admissions`
--

CREATE TABLE `patient_admissions` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `room_id` int(11) DEFAULT NULL,
  `bed_id` int(11) DEFAULT NULL,
  `admission_source` varchar(32) DEFAULT 'opd',
  `admission_date` datetime DEFAULT NULL,
  `expected_discharge_date` date DEFAULT NULL,
  `actual_discharge_date` datetime DEFAULT NULL,
  `admission_status` enum('pending','admitted','discharged') DEFAULT 'pending',
  `admission_notes` text DEFAULT NULL,
  `admitted_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patient_profile_tokens`
--

CREATE TABLE `patient_profile_tokens` (
  `id` int(11) NOT NULL,
  `patient_record_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patient_progress`
--

CREATE TABLE `patient_progress` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `progress_date` datetime NOT NULL DEFAULT current_timestamp(),
  `progress_note` text NOT NULL,
  `vital_signs` text DEFAULT NULL,
  `treatment_response` text DEFAULT NULL,
  `next_appointment` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patient_progress_notes`
--

CREATE TABLE `patient_progress_notes` (
  `id` int(11) NOT NULL,
  `patient_record_id` int(11) NOT NULL,
  `visit_id` int(11) NOT NULL,
  `note_text` text NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patient_records`
--

CREATE TABLE `patient_records` (
  `id` int(11) NOT NULL,
  `patient_name` varchar(100) NOT NULL,
  `age` int(11) NOT NULL,
  `gender` enum('male','female','other') NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patient_share_tokens`
--

CREATE TABLE `patient_share_tokens` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `shared_by` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `access_level` enum('full','basic') NOT NULL DEFAULT 'basic',
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_notifications`
--

CREATE TABLE `payment_notifications` (
  `id` int(11) NOT NULL,
  `lab_payment_id` int(11) NOT NULL,
  `notification_type` enum('payment_request','payment_completed','payment_reminder') NOT NULL,
  `status` enum('unread','read') DEFAULT 'unread',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `id` int(11) NOT NULL,
  `room_number` varchar(50) NOT NULL,
  `room_type` enum('private','semi_private','ward','labor_room','delivery_room','surgery_room') NOT NULL,
  `floor_number` int(11) NOT NULL,
  `status` enum('active','maintenance','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'user',
  `employee_type` varchar(50) NOT NULL,
  `department` varchar(50) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `status` enum('pending','active','inactive') DEFAULT 'pending',
  `last_activity` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `employee_type`, `department`, `profile_picture`, `status`, `last_activity`, `created_at`) VALUES
(1, 'Supra Admin', 'supraadmin@hospital.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'supra_admin', 'administrator', 'Administration', NULL, 'active', NULL, '2025-08-31 05:56:05'),
(2, 'General Doctor', 'general_doctor@gmail.com', '$2y$10$PNcqVyK.YN17Jz3mpxvF7eHoqF4Q.Y0TDJzDiWtNYq5o4O/NcFPMi', 'user', 'general_doctor', 'General Practice', NULL, 'active', NULL, '2025-08-31 05:56:05'),
(3, 'Nurse', 'nurse@gmail.com', '$2y$10$PNcqVyK.YN17Jz3mpxvF7eHoqF4Q.Y0TDJzDiWtNYq5o4O/NcFPMi', 'user', 'nurse', 'General Ward', NULL, 'active', NULL, '2025-08-31 05:56:05'),
(4, 'Medical Technician', 'medical_technician@gmail.com', '$2y$10$PNcqVyK.YN17Jz3mpxvF7eHoqF4Q.Y0TDJzDiWtNYq5o4O/NcFPMi', 'user', 'medical_technician', 'Laboratory', NULL, 'active', NULL, '2025-08-31 05:56:05'),
(5, 'Receptionist', 'receptionist@gmail.com', '$2y$10$PNcqVyK.YN17Jz3mpxvF7eHoqF4Q.Y0TDJzDiWtNYq5o4O/NcFPMi', 'user', 'receptionist', 'Front Desk', NULL, 'active', NULL, '2025-08-31 05:56:05'),
(6, 'Medical Staff', 'medical_staff@gmail.com', '$2y$10$PNcqVyK.YN17Jz3mpxvF7eHoqF4Q.Y0TDJzDiWtNYq5o4O/NcFPMi', 'user', 'medical_staff', 'General Practice', NULL, 'active', NULL, '2025-08-31 05:56:05'),
(7, 'Finance Staff', 'finance@gmail.com', '$2y$10$PNcqVyK.YN17Jz3mpxvF7eHoqF4Q.Y0TDJzDiWtNYq5o4O/NcFPMi', 'user', 'finance', 'Billing', NULL, 'active', NULL, '2025-08-31 05:56:05'),
(8, 'Radiologist', 'radiologist@gmail.com', '$2y$10$PNcqVyK.YN17Jz3mpxvF7eHoqF4Q.Y0TDJzDiWtNYq5o4O/NcFPMi', 'user', 'radiologist', 'X-Ray', NULL, 'active', NULL, '2025-08-31 05:56:05'),
(9, 'Pharmacist', 'pharmacist@gmail.com', '$2y$10$PNcqVyK.YN17Jz3mpxvF7eHoqF4Q.Y0TDJzDiWtNYq5o4O/NcFPMi', 'user', 'pharmacist', 'Main Pharmacy', NULL, 'active', NULL, '2025-08-31 05:56:05'),
(10, 'Admin Staff', 'admin_staff@gmail.com', '$2y$10$PNcqVyK.YN17Jz3mpxvF7eHoqF4Q.Y0TDJzDiWtNYq5o4O/NcFPMi', 'user', 'admin_staff', 'Administration', NULL, 'active', NULL, '2025-08-31 05:56:05'),
(11, 'IT Staff', 'it@gmail.com', '$2y$10$PNcqVyK.YN17Jz3mpxvF7eHoqF4Q.Y0TDJzDiWtNYq5o4O/NcFPMi', 'user', 'it', 'Technical Support', NULL, 'active', NULL, '2025-08-31 05:56:05'),
(12, 'Medical Records Staff', 'medical_records@gmail.com', '$2y$10$PNcqVyK.YN17Jz3mpxvF7eHoqF4Q.Y0TDJzDiWtNYq5o4O/NcFPMi', 'user', 'medical_records', 'Records Management', NULL, 'active', NULL, '2025-08-31 05:56:05'),
(13, 'OB GYN', 'obgyn@gmail.com', '$2y$10$hZQy1AnHv6ylRnKtpDNUI.Vd2JojUECLkBmsG.9zZiYbXV4GD.qyq', 'staff', 'doctor', 'OB-GYN', NULL, 'active', NULL, '2025-09-01 06:11:01'),
(14, 'Pediatrician', 'pediatrician@gmail.com', '$2y$10$L6kBNOYyG4lRj5s3pEfA8ePZYAdAXRX22LN.XbBQLXD6x0ROMCvGK', 'staff', 'doctor', 'Pediatrics', NULL, 'active', NULL, '2025-09-12 02:39:53');

-- --------------------------------------------------------

--
-- Table structure for table `user_share_tokens`
--

CREATE TABLE `user_share_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admission_billing`
--
ALTER TABLE `admission_billing`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_admission_billing_admission` (`admission_id`);

--
-- Indexes for table `admission_billing_items`
--
ALTER TABLE `admission_billing_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `billing_id_idx` (`billing_id`);

--
-- Indexes for table `admission_billing_suppressed`
--
ALTER TABLE `admission_billing_suppressed`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_suppress` (`billing_id`,`type`,`ref_id`);

--
-- Indexes for table `beds`
--
ALTER TABLE `beds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `room_bed_unique` (`room_id`,`bed_number`),
  ADD KEY `idx_beds_status` (`status`);

--
-- Indexes for table `bed_profile_tokens`
--
ALTER TABLE `bed_profile_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_bed` (`bed_id`),
  ADD UNIQUE KEY `uniq_bed_profile_token` (`token`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `birth_certificates`
--
ALTER TABLE `birth_certificates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admission_id` (`admission_id`),
  ADD KEY `submitted_by` (`submitted_by`),
  ADD KEY `reviewed_by` (`reviewed_by`),
  ADD KEY `idx_birth_cert_patient` (`patient_id`),
  ADD KEY `idx_birth_cert_status` (`status`);

--
-- Indexes for table `doctors_orders`
--
ALTER TABLE `doctors_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admission_id` (`admission_id`),
  ADD KEY `ordered_by` (`ordered_by`),
  ADD KEY `claimed_by` (`claimed_by`),
  ADD KEY `completed_by` (`completed_by`),
  ADD KEY `discontinued_by` (`discontinued_by`);

--
-- Indexes for table `lab_payments`
--
ALTER TABLE `lab_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lab_request_id` (`lab_request_id`),
  ADD KEY `processed_by` (`processed_by`);

--
-- Indexes for table `lab_requests`
--
ALTER TABLE `lab_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `visit_id` (`visit_id`),
  ADD KEY `doctor_id` (`doctor_id`),
  ADD KEY `test_id` (`test_id`);

--
-- Indexes for table `lab_tests`
--
ALTER TABLE `lab_tests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `medicines`
--
ALTER TABLE `medicines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `added_by` (`added_by`),
  ADD KEY `idx_medicines_expiration_date` (`expiration_date`),
  ADD KEY `idx_medicines_quantity` (`quantity`);

--
-- Indexes for table `medicine_orders`
--
ALTER TABLE `medicine_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `doctor_id` (`doctor_id`),
  ADD KEY `patient_id` (`patient_id`);

--
-- Indexes for table `medicine_order_items`
--
ALTER TABLE `medicine_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `medicine_id` (`medicine_id`);

--
-- Indexes for table `medicine_order_notifications`
--
ALTER TABLE `medicine_order_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `nursing_notes`
--
ALTER TABLE `nursing_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_nursing_notes_admission` (`admission_id`),
  ADD KEY `idx_nursing_notes_created` (`created_at`);

--
-- Indexes for table `opd_visits`
--
ALTER TABLE `opd_visits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `registered_by` (`registered_by`),
  ADD KEY `doctor_id` (`doctor_id`),
  ADD KEY `patient_record_id` (`patient_record_id`),
  ADD KEY `idx_opd_pregnancy_status` (`pregnancy_status`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `doctor_id` (`doctor_id`),
  ADD KEY `added_by` (`added_by`);

--
-- Indexes for table `patient_admissions`
--
ALTER TABLE `patient_admissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `room_id` (`room_id`),
  ADD KEY `bed_id` (`bed_id`),
  ADD KEY `admitted_by` (`admitted_by`),
  ADD KEY `idx_patient_admissions_status` (`admission_status`),
  ADD KEY `idx_patient_admissions_dates` (`admission_date`,`actual_discharge_date`);

--
-- Indexes for table `patient_profile_tokens`
--
ALTER TABLE `patient_profile_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_patient_record` (`patient_record_id`),
  ADD UNIQUE KEY `uniq_patient_profile_token` (`token`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `patient_progress`
--
ALTER TABLE `patient_progress`
  ADD PRIMARY KEY (`id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `doctor_id` (`doctor_id`);

--
-- Indexes for table `patient_progress_notes`
--
ALTER TABLE `patient_progress_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `patient_record_id` (`patient_record_id`),
  ADD KEY `visit_id` (`visit_id`),
  ADD KEY `doctor_id` (`doctor_id`);

--
-- Indexes for table `patient_records`
--
ALTER TABLE `patient_records`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `patient_share_tokens`
--
ALTER TABLE `patient_share_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_token` (`token`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `shared_by` (`shared_by`);

--
-- Indexes for table `payment_notifications`
--
ALTER TABLE `payment_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lab_payment_id` (`lab_payment_id`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `room_number` (`room_number`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_share_tokens`
--
ALTER TABLE `user_share_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `token` (`token`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admission_billing`
--
ALTER TABLE `admission_billing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `admission_billing_items`
--
ALTER TABLE `admission_billing_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=304;

--
-- AUTO_INCREMENT for table `admission_billing_suppressed`
--
ALTER TABLE `admission_billing_suppressed`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `beds`
--
ALTER TABLE `beds`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `bed_profile_tokens`
--
ALTER TABLE `bed_profile_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `birth_certificates`
--
ALTER TABLE `birth_certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `doctors_orders`
--
ALTER TABLE `doctors_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `lab_payments`
--
ALTER TABLE `lab_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `lab_requests`
--
ALTER TABLE `lab_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `lab_tests`
--
ALTER TABLE `lab_tests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=80;

--
-- AUTO_INCREMENT for table `medicines`
--
ALTER TABLE `medicines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `medicine_orders`
--
ALTER TABLE `medicine_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `medicine_order_items`
--
ALTER TABLE `medicine_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `medicine_order_notifications`
--
ALTER TABLE `medicine_order_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `nursing_notes`
--
ALTER TABLE `nursing_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `opd_visits`
--
ALTER TABLE `opd_visits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `patient_admissions`
--
ALTER TABLE `patient_admissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `patient_profile_tokens`
--
ALTER TABLE `patient_profile_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `patient_progress`
--
ALTER TABLE `patient_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `patient_progress_notes`
--
ALTER TABLE `patient_progress_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `patient_records`
--
ALTER TABLE `patient_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `patient_share_tokens`
--
ALTER TABLE `patient_share_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_notifications`
--
ALTER TABLE `payment_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `user_share_tokens`
--
ALTER TABLE `user_share_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admission_billing`
--
ALTER TABLE `admission_billing`
  ADD CONSTRAINT `admission_billing_ibfk_1` FOREIGN KEY (`admission_id`) REFERENCES `patient_admissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `admission_billing_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `admission_billing_items`
--
ALTER TABLE `admission_billing_items`
  ADD CONSTRAINT `admission_billing_items_ibfk_1` FOREIGN KEY (`billing_id`) REFERENCES `admission_billing` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `admission_billing_suppressed`
--
ALTER TABLE `admission_billing_suppressed`
  ADD CONSTRAINT `admission_billing_suppressed_ibfk_1` FOREIGN KEY (`billing_id`) REFERENCES `admission_billing` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `beds`
--
ALTER TABLE `beds`
  ADD CONSTRAINT `beds_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`);

--
-- Constraints for table `bed_profile_tokens`
--
ALTER TABLE `bed_profile_tokens`
  ADD CONSTRAINT `bed_profile_tokens_ibfk_1` FOREIGN KEY (`bed_id`) REFERENCES `beds` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bed_profile_tokens_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `birth_certificates`
--
ALTER TABLE `birth_certificates`
  ADD CONSTRAINT `birth_certificates_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `birth_certificates_ibfk_2` FOREIGN KEY (`admission_id`) REFERENCES `patient_admissions` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `birth_certificates_ibfk_3` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `birth_certificates_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `doctors_orders`
--
ALTER TABLE `doctors_orders`
  ADD CONSTRAINT `doctors_orders_ibfk_1` FOREIGN KEY (`admission_id`) REFERENCES `patient_admissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `doctors_orders_ibfk_2` FOREIGN KEY (`ordered_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `doctors_orders_ibfk_3` FOREIGN KEY (`claimed_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `doctors_orders_ibfk_4` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `doctors_orders_ibfk_5` FOREIGN KEY (`discontinued_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `lab_payments`
--
ALTER TABLE `lab_payments`
  ADD CONSTRAINT `lab_payments_ibfk_1` FOREIGN KEY (`lab_request_id`) REFERENCES `lab_requests` (`id`),
  ADD CONSTRAINT `lab_payments_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `lab_requests`
--
ALTER TABLE `lab_requests`
  ADD CONSTRAINT `lab_requests_ibfk_1` FOREIGN KEY (`visit_id`) REFERENCES `opd_visits` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lab_requests_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `lab_requests_ibfk_3` FOREIGN KEY (`test_id`) REFERENCES `lab_tests` (`id`);

--
-- Constraints for table `medicines`
--
ALTER TABLE `medicines`
  ADD CONSTRAINT `medicines_ibfk_1` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `medicine_orders`
--
ALTER TABLE `medicine_orders`
  ADD CONSTRAINT `medicine_orders_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `medicine_orders_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`);

--
-- Constraints for table `medicine_order_items`
--
ALTER TABLE `medicine_order_items`
  ADD CONSTRAINT `medicine_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `medicine_orders` (`id`),
  ADD CONSTRAINT `medicine_order_items_ibfk_2` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`);

--
-- Constraints for table `medicine_order_notifications`
--
ALTER TABLE `medicine_order_notifications`
  ADD CONSTRAINT `medicine_order_notifications_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `medicine_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `nursing_notes`
--
ALTER TABLE `nursing_notes`
  ADD CONSTRAINT `nursing_notes_ibfk_1` FOREIGN KEY (`admission_id`) REFERENCES `patient_admissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `nursing_notes_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `opd_visits`
--
ALTER TABLE `opd_visits`
  ADD CONSTRAINT `opd_visits_ibfk_1` FOREIGN KEY (`registered_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `opd_visits_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `opd_visits_ibfk_3` FOREIGN KEY (`patient_record_id`) REFERENCES `patient_records` (`id`);

--
-- Constraints for table `patients`
--
ALTER TABLE `patients`
  ADD CONSTRAINT `patients_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `patients_ibfk_2` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `patient_admissions`
--
ALTER TABLE `patient_admissions`
  ADD CONSTRAINT `patient_admissions_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  ADD CONSTRAINT `patient_admissions_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  ADD CONSTRAINT `patient_admissions_ibfk_3` FOREIGN KEY (`bed_id`) REFERENCES `beds` (`id`),
  ADD CONSTRAINT `patient_admissions_ibfk_4` FOREIGN KEY (`admitted_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `patient_profile_tokens`
--
ALTER TABLE `patient_profile_tokens`
  ADD CONSTRAINT `patient_profile_tokens_ibfk_1` FOREIGN KEY (`patient_record_id`) REFERENCES `patient_records` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `patient_profile_tokens_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `patient_progress`
--
ALTER TABLE `patient_progress`
  ADD CONSTRAINT `patient_progress_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  ADD CONSTRAINT `patient_progress_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `patient_progress_notes`
--
ALTER TABLE `patient_progress_notes`
  ADD CONSTRAINT `patient_progress_notes_ibfk_1` FOREIGN KEY (`patient_record_id`) REFERENCES `patient_records` (`id`),
  ADD CONSTRAINT `patient_progress_notes_ibfk_2` FOREIGN KEY (`visit_id`) REFERENCES `opd_visits` (`id`),
  ADD CONSTRAINT `patient_progress_notes_ibfk_3` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `patient_share_tokens`
--
ALTER TABLE `patient_share_tokens`
  ADD CONSTRAINT `patient_share_tokens_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `patient_share_tokens_ibfk_2` FOREIGN KEY (`shared_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_notifications`
--
ALTER TABLE `payment_notifications`
  ADD CONSTRAINT `payment_notifications_ibfk_1` FOREIGN KEY (`lab_payment_id`) REFERENCES `lab_payments` (`id`);

--
-- Constraints for table `user_share_tokens`
--
ALTER TABLE `user_share_tokens`
  ADD CONSTRAINT `user_share_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
