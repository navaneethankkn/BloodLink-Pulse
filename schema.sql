-- ============================================================================
-- BloodLink Pulse - Emergency Blood Donor & Request Matcher
-- Relational Database Schema & Seed Data (MySQL 5.7+ / 8.0+)
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `bloodlink_pulse` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `bloodlink_pulse`;

-- Drop existing tables in reverse order of foreign keys
DROP TABLE IF EXISTS `match_logs`;
DROP TABLE IF EXISTS `requests`;
DROP TABLE IF EXISTS `donors`;

-- ----------------------------------------------------------------------------
-- Table 1: Donors
-- Holds registered blood donors with contact info, location, and donation history
-- ----------------------------------------------------------------------------
CREATE TABLE `donors` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `blood_type` ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-') NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `email` VARCHAR(120) NOT NULL,
    `city` VARCHAR(100) NOT NULL,
    `last_donation_date` DATE NULL,
    `status` ENUM('Available', 'Cooldown', 'Inactive') NOT NULL DEFAULT 'Available',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_donors_blood_type` (`blood_type`),
    INDEX `idx_donors_status` (`status`),
    INDEX `idx_donors_city` (`city`),
    INDEX `idx_donors_last_donation` (`last_donation_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 2: Requests
-- Holds emergency hospital blood requests with urgency triage levels
-- Urgency levels: Critical (Immediate life-threat), High (Urgent surgery), Medium (Standard transfusion)
-- ----------------------------------------------------------------------------
CREATE TABLE `requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_name` VARCHAR(100) NOT NULL,
    `blood_type` ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-') NOT NULL,
    `units_needed` INT NOT NULL DEFAULT 1,
    `urgency` ENUM('Critical', 'High', 'Medium') NOT NULL DEFAULT 'High',
    `hospital_name` VARCHAR(150) NOT NULL,
    `city` VARCHAR(100) NOT NULL,
    `contact_phone` VARCHAR(20) NOT NULL,
    `status` ENUM('Pending', 'Matched', 'Fulfilled', 'Cancelled') NOT NULL DEFAULT 'Pending',
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_requests_blood_urgency` (`blood_type`, `urgency`),
    INDEX `idx_requests_status` (`status`),
    INDEX `idx_requests_city` (`city`),
    INDEX `idx_requests_urgency` (`urgency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 3: Match Logs
-- Records matching events between donors and emergency requests, along with
-- simulated SMS alert dispatch gateway outcomes via external cURL calls
-- ----------------------------------------------------------------------------
CREATE TABLE `match_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT NOT NULL,
    `donor_id` INT NOT NULL,
    `urgency_level` ENUM('Critical', 'High', 'Medium') NOT NULL,
    `sms_status` ENUM('Sent', 'Failed', 'Simulated') NOT NULL DEFAULT 'Simulated',
    `sms_response_id` VARCHAR(100) NULL,
    `sms_payload` TEXT NULL,
    `matched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_match_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_match_donor` FOREIGN KEY (`donor_id`) REFERENCES `donors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_match_request_id` (`request_id`),
    INDEX `idx_match_donor_id` (`donor_id`),
    INDEX `idx_match_matched_at` (`matched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Seed Records: Realistic initial data covering various blood groups and triage
-- ============================================================================

-- 1. Seed Donors (Diverse blood types, including universal donor O- and universal recipient AB+)
-- Note: Dates are relative to allow test cases for >= 90 days eligibility and < 90 days cooldown
INSERT INTO `donors` (`id`, `name`, `blood_type`, `phone`, `email`, `city`, `last_donation_date`, `status`) VALUES
(1, 'Alexander Wright', 'O-', '5552345678', 'alex.wright@lifelink.org', 'Metropolis', DATE_SUB(CURRENT_DATE, INTERVAL 120 DAY), 'Available'),
(2, 'Elena Rostova', 'O+', '5553456789', 'elena.rostova@healthmail.com', 'Metropolis', DATE_SUB(CURRENT_DATE, INTERVAL 95 DAY), 'Available'),
(3, 'Marcus Sterling', 'A+', '5554567890', 'marcus.s@pulsegrid.net', 'Gotham', DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY), 'Cooldown'),
(4, 'Chloe Bennet', 'A-', '5555678901', 'chloe.bennet@apexmed.com', 'Metropolis', DATE_SUB(CURRENT_DATE, INTERVAL 180 DAY), 'Available'),
(5, 'David Kim', 'B+', '5556789012', 'david.kim@carehub.org', 'Star City', DATE_SUB(CURRENT_DATE, INTERVAL 110 DAY), 'Available'),
(6, 'Fatima Al-Mansoor', 'B-', '5557890123', 'fatima.m@globaldonor.org', 'Gotham', DATE_SUB(CURRENT_DATE, INTERVAL 45 DAY), 'Cooldown'),
(7, 'Julian Vance', 'AB+', '5558901234', 'julian.vance@biocare.io', 'Metropolis', DATE_SUB(CURRENT_DATE, INTERVAL 150 DAY), 'Available'),
(8, 'Samantha Reed', 'AB-', '5559012345', 's.reed@transfusion.org', 'Star City', DATE_SUB(CURRENT_DATE, INTERVAL 210 DAY), 'Available');

-- 2. Seed Emergency Requests (Different triage levels: Critical, High, Medium)
INSERT INTO `requests` (`id`, `patient_name`, `blood_type`, `units_needed`, `urgency`, `hospital_name`, `city`, `contact_phone`, `status`, `notes`) VALUES
(1, 'Robert Vance', 'O-', 3, 'Critical', 'Metropolis Trauma Center', 'Metropolis', '5559110001', 'Pending', 'Massive hemorrhage from emergency vehicular accident - immediate O- donor required.'),
(2, 'Maria Santos', 'A+', 2, 'High', 'St. Jude General Hospital', 'Gotham', '5559110002', 'Pending', 'Scheduled open-heart arterial bypass surgery within 12 hours.'),
(3, 'Liam O\'Connor', 'B+', 1, 'Medium', 'Star City Memorial', 'Star City', '5559110003', 'Fulfilled', 'Oncology supportive care platelet transfusion completed.'),
(4, 'Amara Okafor', 'AB-', 2, 'Critical', 'Metro Presbyterian Hospital', 'Metropolis', '5559110004', 'Matched', 'Pediatric severe aplastic crisis; compatible rare negative donor identified.');

-- 3. Seed Match Logs with Simulated External SMS Gateway Responses
INSERT INTO `match_logs` (`id`, `request_id`, `donor_id`, `urgency_level`, `sms_status`, `sms_response_id`, `sms_payload`, `matched_at`) VALUES
(1, 4, 8, 'Critical', 'Sent', '101', '{"to":"5559012345","recipient":"Samantha Reed","patient":"Amara Okafor","hospital":"Metro Presbyterian Hospital","units":2,"urgency":"Critical","blood_type":"AB-","gateway_id":"101","status":"DELIVERED"}', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(2, 3, 5, 'Medium', 'Sent', '102', '{"to":"5556789012","recipient":"David Kim","patient":"Liam O\'Connor","hospital":"Star City Memorial","units":1,"urgency":"Medium","blood_type":"B+","gateway_id":"102","status":"DELIVERED"}', DATE_SUB(NOW(), INTERVAL 1 DAY));
