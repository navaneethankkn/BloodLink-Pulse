<?php
/**
 * BloodLink Pulse - Database Configuration & Core Utilities
 * Provides secure PDO database connectivity (MySQL with SQLite automatic fallback),
 * strict input sanitization, medical blood compatibility rules, and JSON helpers.
 */

// Global Error Reporting Configuration (Production-Ready)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Database Credentials (overridable via Environment Variables)
define('DB_DRIVER', getenv('DB_DRIVER') ?: 'mysql');
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'bloodlink_pulse');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('SQLITE_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'bloodlink_pulse.sqlite');

/**
 * Obtain a persistent, secure PDO database connection.
 * Attempts MySQL first; if MySQL is unavailable, automatically falls back to an SQLite
 * embedded database initialized with the full relational schema and seed records.
 *
 * @return PDO
 * @throws Exception
 */
function getDbConnection(): PDO {
    static $pdoInstance = null;

    if ($pdoInstance !== null) {
        return $pdoInstance;
    }

    $driver = strtolower(DB_DRIVER);

    // 1. Try MySQL Connection if configured
    if ($driver === 'mysql') {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 3,
            ];
            $pdoInstance = new PDO($dsn, DB_USER, DB_PASS, $options);
            return $pdoInstance;
        } catch (PDOException $e) {
            // Log notice of MySQL unavailability and fall back gracefully to SQLite
            error_log("MySQL connection failed ({$e->getMessage()}). Initializing local SQLite fallback instance.");
        }
    }

    // 2. Resilient Fallback to SQLite (ensures 100% zero-config operability)
    try {
        $dbExisted = file_exists(SQLITE_FILE);
        $pdoInstance = new PDO('sqlite:' . SQLITE_FILE, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdoInstance->exec('PRAGMA foreign_keys = ON;');

        if (!$dbExisted || filesize(SQLITE_FILE) === 0) {
            initializeSqliteSchema($pdoInstance);
        }

        return $pdoInstance;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        throw new Exception("Unable to establish secure database connection.");
    }
}

/**
 * Initializes SQLite database tables and seeds if MySQL is not currently running.
 * Matches the normalized schema and data requirements exactly.
 */
function initializeSqliteSchema(PDO $pdo): void {
    $schema = "
    CREATE TABLE IF NOT EXISTS donors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        blood_type TEXT NOT NULL,
        phone TEXT NOT NULL,
        email TEXT NOT NULL,
        city TEXT NOT NULL,
        last_donation_date DATE NULL,
        status TEXT NOT NULL DEFAULT 'Available',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE INDEX IF NOT EXISTS idx_donors_bt ON donors(blood_type);
    CREATE INDEX IF NOT EXISTS idx_donors_status ON donors(status);
    CREATE INDEX IF NOT EXISTS idx_donors_city ON donors(city);

    CREATE TABLE IF NOT EXISTS requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_name TEXT NOT NULL,
        blood_type TEXT NOT NULL,
        units_needed INTEGER NOT NULL DEFAULT 1,
        urgency TEXT NOT NULL DEFAULT 'High',
        hospital_name TEXT NOT NULL,
        city TEXT NOT NULL,
        contact_phone TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'Pending',
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE INDEX IF NOT EXISTS idx_req_bt_urg ON requests(blood_type, urgency);
    CREATE INDEX IF NOT EXISTS idx_req_status ON requests(status);
    CREATE INDEX IF NOT EXISTS idx_req_city ON requests(city);

    CREATE TABLE IF NOT EXISTS match_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER NOT NULL,
        donor_id INTEGER NOT NULL,
        urgency_level TEXT NOT NULL,
        sms_status TEXT NOT NULL DEFAULT 'Simulated',
        sms_response_id TEXT NULL,
        sms_payload TEXT NULL,
        matched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE CASCADE
    );

    CREATE INDEX IF NOT EXISTS idx_ml_req ON match_logs(request_id);
    CREATE INDEX IF NOT EXISTS idx_ml_don ON match_logs(donor_id);
    ";

    $pdo->exec($schema);

    // Seed Donors
    $seedDonors = [
        [1, 'Alexander Wright', 'O-', '5552345678', 'alex.wright@lifelink.org', 'Metropolis', date('Y-m-d', strtotime('-120 days')), 'Available'],
        [2, 'Elena Rostova', 'O+', '5553456789', 'elena.rostova@healthmail.com', 'Metropolis', date('Y-m-d', strtotime('-95 days')), 'Available'],
        [3, 'Marcus Sterling', 'A+', '5554567890', 'marcus.s@pulsegrid.net', 'Gotham', date('Y-m-d', strtotime('-30 days')), 'Cooldown'],
        [4, 'Chloe Bennet', 'A-', '5555678901', 'chloe.bennet@apexmed.com', 'Metropolis', date('Y-m-d', strtotime('-180 days')), 'Available'],
        [5, 'David Kim', 'B+', '5556789012', 'david.kim@carehub.org', 'Star City', date('Y-m-d', strtotime('-110 days')), 'Available'],
        [6, 'Fatima Al-Mansoor', 'B-', '5557890123', 'fatima.m@globaldonor.org', 'Gotham', date('Y-m-d', strtotime('-45 days')), 'Cooldown'],
        [7, 'Julian Vance', 'AB+', '5558901234', 'julian.vance@biocare.io', 'Metropolis', date('Y-m-d', strtotime('-150 days')), 'Available'],
        [8, 'Samantha Reed', 'AB-', '5559012345', 's.reed@transfusion.org', 'Star City', date('Y-m-d', strtotime('-210 days')), 'Available']
    ];

    $stmtDonor = $pdo->prepare("INSERT OR IGNORE INTO donors (id, name, blood_type, phone, email, city, last_donation_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($seedDonors as $d) {
        $stmtDonor->execute($d);
    }

    // Seed Requests
    $seedRequests = [
        [1, 'Robert Vance', 'O-', 3, 'Critical', 'Metropolis Trauma Center', 'Metropolis', '5559110001', 'Pending', 'Massive hemorrhage from emergency vehicular accident - immediate O- donor required.'],
        [2, 'Maria Santos', 'A+', 2, 'High', 'St. Jude General Hospital', 'Gotham', '5559110002', 'Pending', 'Scheduled open-heart arterial bypass surgery within 12 hours.'],
        [3, 'Liam O\'Connor', 'B+', 1, 'Medium', 'Star City Memorial', 'Star City', '5559110003', 'Fulfilled', 'Oncology supportive care platelet transfusion completed.'],
        [4, 'Amara Okafor', 'AB-', 2, 'Critical', 'Metro Presbyterian Hospital', 'Metropolis', '5559110004', 'Matched', 'Pediatric severe aplastic crisis; compatible rare negative donor identified.']
    ];

    $stmtReq = $pdo->prepare("INSERT OR IGNORE INTO requests (id, patient_name, blood_type, units_needed, urgency, hospital_name, city, contact_phone, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($seedRequests as $r) {
        $stmtReq->execute($r);
    }

    // Seed Match Logs
    $seedLogs = [
        [1, 4, 8, 'Critical', 'Sent', '101', '{"to":"5559012345","recipient":"Samantha Reed","patient":"Amara Okafor","hospital":"Metro Presbyterian Hospital","units":2,"urgency":"Critical","blood_type":"AB-","gateway_id":"101","status":"DELIVERED"}', date('Y-m-d H:i:s', strtotime('-2 hours'))],
        [2, 3, 5, 'Medium', 'Sent', '102', '{"to":"5556789012","recipient":"David Kim","patient":"Liam O\'Connor","hospital":"Star City Memorial","units":1,"urgency":"Medium","blood_type":"B+","gateway_id":"102","status":"DELIVERED"}', date('Y-m-d H:i:s', strtotime('-1 day'))]
    ];

    $stmtLog = $pdo->prepare("INSERT OR IGNORE INTO match_logs (id, request_id, donor_id, urgency_level, sms_status, sms_response_id, sms_payload, matched_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($seedLogs as $l) {
        $stmtLog->execute($l);
    }
}

/**
 * Strict Input Sanitization Helper
 * Applies htmlspecialchars with ENT_QUOTES and UTF-8 encoding.
 */
function sanitizeInput(?string $data): string {
    if ($data === null) {
        return '';
    }
    return htmlspecialchars(trim($data), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Validates Email Address using filter_var.
 */
function validateEmail(string $email): bool {
    return (bool) filter_var(trim($email), FILTER_VALIDATE_EMAIL);
}

/**
 * Validates 10-Digit Contact Phone Number.
 * Accepts digits only or removes punctuation to enforce exact 10 digits.
 */
function validatePhone(string $phone): bool {
    $cleaned = preg_replace('/[^\d]/', '', $phone);
    return strlen($cleaned) === 10;
}

/**
 * Normalizes phone number into 10-digit standard string.
 */
function normalizePhone(string $phone): string {
    return preg_replace('/[^\d]/', '', $phone);
}

/**
 * Validates Standard ABO and Rh Blood Types.
 */
function validateBloodType(string $bloodType): bool {
    $validTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    return in_array(trim($bloodType), $validTypes, true);
}

/**
 * Validates Clinical Urgency Triage Level.
 */
function validateUrgency(string $urgency): bool {
    $validUrgencies = ['Critical', 'High', 'Medium'];
    return in_array(trim($urgency), $validUrgencies, true);
}

/**
 * Checks donation eligibility based on the 90-day cooldown rule.
 * Returns array with eligibility status and remaining cooldown days.
 */
function checkDonationInterval(?string $lastDonationDate): array {
    if (empty($lastDonationDate)) {
        return [
            'is_eligible' => true,
            'days_since'  => null,
            'days_needed' => 0,
            'status'      => 'Available'
        ];
    }

    $lastDate = DateTime::createFromFormat('Y-m-d', $lastDonationDate);
    if (!$lastDate) {
        return [
            'is_eligible' => false,
            'error'       => 'Invalid date format (must be YYYY-MM-DD)'
        ];
    }

    $now = new DateTime('today');
    if ($lastDate > $now) {
        return [
            'is_eligible' => false,
            'error'       => 'Donation date cannot be in the future'
        ];
    }

    $interval = $now->diff($lastDate);
    $daysSince = (int) $interval->days;

    if ($daysSince >= 90) {
        return [
            'is_eligible' => true,
            'days_since'  => $daysSince,
            'days_needed' => 0,
            'status'      => 'Available'
        ];
    } else {
        return [
            'is_eligible' => false,
            'days_since'  => $daysSince,
            'days_needed' => 90 - $daysSince,
            'status'      => 'Cooldown'
        ];
    }
}

/**
 * Medical Blood Compatibility Matrix:
 * Returns the list of compatible DONOR blood types for a given RECIPIENT blood type.
 * Based on Red Blood Cell (RBC) antigen-antibody compatibility:
 * - O- is universal donor (anyone can receive O-)
 * - AB+ is universal recipient (can receive from any group)
 */
function getCompatibleDonorTypes(string $recipientType): array {
    $matrix = [
        'O-'  => ['O-'],
        'O+'  => ['O+', 'O-'],
        'A-'  => ['A-', 'O-'],
        'A+'  => ['A+', 'A-', 'O+', 'O-'],
        'B-'  => ['B-', 'O-'],
        'B+'  => ['B+', 'B-', 'O+', 'O-'],
        'AB-' => ['AB-', 'A-', 'B-', 'O-'],
        'AB+' => ['AB+', 'AB-', 'A+', 'A-', 'B+', 'B-', 'O+', 'O-']
    ];

    return $matrix[$recipientType] ?? [];
}

/**
 * Medical Blood Compatibility Matrix:
 * Returns the list of RECIPIENT blood types that a given DONOR blood type can donate to.
 */
function getCompatibleRecipientTypes(string $donorType): array {
    $matrix = [
        'O-'  => ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'], // Universal donor
        'O+'  => ['O+', 'A+', 'B+', 'AB+'],
        'A-'  => ['A-', 'A+', 'AB-', 'AB+'],
        'A+'  => ['A+', 'AB+'],
        'B-'  => ['B-', 'B+', 'AB-', 'AB+'],
        'B+'  => ['B+', 'AB+'],
        'AB-' => ['AB-', 'AB+'],
        'AB+' => ['AB+']                                              // Can donate only to AB+
    ];

    return $matrix[$donorType] ?? [];
}

/**
 * Sends a structured JSON HTTP response.
 */
function sendJsonResponse(int $statusCode, array $data): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        http_response_code($statusCode);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Handles CORS Preflight OPTIONS requests.
 */
function handleCorsPreflight(): void {
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        http_response_code(204);
        exit;
    }
}
