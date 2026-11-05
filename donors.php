<?php
/**
 * BloodLink Pulse - Donors RESTful API Endpoint
 * Handles full CRUD operations for registered donors:
 * - GET: List donors (with filtering by blood_type, status, city, search) or fetch by ID
 * - POST: Register new donor with server-side validation & 90-day cooldown interval evaluation
 * - PUT: Update donor record
 * - DELETE: Remove donor record
 *
 * Security: PDO Prepared Statements, htmlspecialchars sanitization, filter_var validation.
 */

require_once __DIR__ . '/../db_config.php';

handleCorsPreflight();

$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGetDonors($pdo);
        break;

    case 'POST':
        handleCreateDonor($pdo);
        break;

    case 'PUT':
        handleUpdateDonor($pdo);
        break;

    case 'DELETE':
        handleDeleteDonor($pdo);
        break;

    default:
        sendJsonResponse(405, ['success' => false, 'message' => 'Method Not Allowed']);
}

/**
 * Handles GET requests: Fetch all donors or a specific donor by ID
 */
function handleGetDonors(PDO $pdo): void {
    try {
        if (!empty($_GET['id'])) {
            $donorId = (int) $_GET['id'];
            $stmt = $pdo->prepare("SELECT * FROM donors WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $donorId]);
            $donor = $stmt->fetch();

            if (!$donor) {
                sendJsonResponse(404, ['success' => false, 'message' => 'Donor not found']);
            }

            // Calculate live interval eligibility
            $eligibility = checkDonationInterval($donor['last_donation_date']);
            $donor['eligibility'] = $eligibility;
            $donor['can_donate_to'] = getCompatibleRecipientTypes($donor['blood_type']);

            sendJsonResponse(200, ['success' => true, 'data' => $donor]);
        }

        // Listing with optional filters
        $bloodType = !empty($_GET['blood_type']) ? sanitizeInput($_GET['blood_type']) : null;
        $status    = !empty($_GET['status']) ? sanitizeInput($_GET['status']) : null;
        $city      = !empty($_GET['city']) ? sanitizeInput($_GET['city']) : null;
        $search    = !empty($_GET['search']) ? sanitizeInput($_GET['search']) : null;

        $sql = "SELECT * FROM donors WHERE 1=1";
        $params = [];

        if ($bloodType && validateBloodType($bloodType)) {
            $sql .= " AND blood_type = :blood_type";
            $params[':blood_type'] = $bloodType;
        }

        if ($status && in_array($status, ['Available', 'Cooldown', 'Inactive'], true)) {
            $sql .= " AND status = :status";
            $params[':status'] = $status;
        }

        if ($city) {
            $sql .= " AND city LIKE :city";
            $params[':city'] = '%' . $city . '%';
        }

        if ($search) {
            $sql .= " AND (name LIKE :search OR phone LIKE :search OR email LIKE :search OR city LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $donors = $stmt->fetchAll();

        // Enhance each record with live donation interval data
        foreach ($donors as &$d) {
            $eligibility = checkDonationInterval($d['last_donation_date']);
            $d['eligibility'] = $eligibility;
            $d['can_donate_to'] = getCompatibleRecipientTypes($d['blood_type']);
            
            // Auto-update status if cooldown passed
            if ($d['status'] === 'Cooldown' && $eligibility['is_eligible']) {
                $d['status'] = 'Available';
                $upd = $pdo->prepare("UPDATE donors SET status = 'Available' WHERE id = :id");
                $upd->execute([':id' => $d['id']]);
            }
        }
        unset($d);

        sendJsonResponse(200, [
            'success' => true,
            'count'   => count($donors),
            'data'    => $donors
        ]);
    } catch (Exception $e) {
        error_log("Error in handleGetDonors: " . $e->getMessage());
        sendJsonResponse(500, ['success' => false, 'message' => 'Internal server error fetching donors']);
    }
}

/**
 * Handles POST requests: Register a new donor
 */
function handleCreateDonor(PDO $pdo): void {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;

    $errors = [];

    // 1. Name validation (>= 3 chars)
    $name = sanitizeInput($input['name'] ?? '');
    if (strlen($name) < 3) {
        $errors['name'] = 'Full Name must be at least 3 characters long.';
    }

    // 2. Blood Type validation
    $bloodType = trim($input['blood_type'] ?? '');
    if (!validateBloodType($bloodType)) {
        $errors['blood_type'] = 'Invalid blood type selected. Choose from: A+, A-, B+, B-, AB+, AB-, O+, O-.';
    }

    // 3. Phone validation (10 digits)
    $phone = normalizePhone($input['phone'] ?? '');
    if (!validatePhone($phone)) {
        $errors['phone'] = 'Contact Phone must be an exact 10-digit number.';
    }

    // 4. Email validation
    $email = trim($input['email'] ?? '');
    if (!validateEmail($email)) {
        $errors['email'] = 'A valid email address is required.';
    } else {
        $email = sanitizeInput($email);
    }

    // 5. City validation
    $city = sanitizeInput($input['city'] ?? '');
    if (empty($city)) {
        $errors['city'] = 'City is required.';
    }

    // 6. Last Donation Date & Interval validation (>= 90 days)
    $lastDonationDate = !empty($input['last_donation_date']) ? trim($input['last_donation_date']) : null;
    $computedStatus = 'Available';

    if ($lastDonationDate) {
        $intervalCheck = checkDonationInterval($lastDonationDate);
        if (!$intervalCheck['is_eligible']) {
            if (isset($intervalCheck['error'])) {
                $errors['last_donation_date'] = $intervalCheck['error'];
            } else {
                // If it is within 90 days, donor status is marked as Cooldown
                $computedStatus = 'Cooldown';
            }
        }
    }

    if (!empty($errors)) {
        sendJsonResponse(422, [
            'success' => false,
            'message' => 'Validation failed for one or more fields.',
            'errors'  => $errors
        ]);
    }

    // Explicit status override if requested
    if (!empty($input['status']) && in_array($input['status'], ['Available', 'Cooldown', 'Inactive'], true)) {
        if ($computedStatus !== 'Cooldown') {
            $computedStatus = $input['status'];
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO donors (name, blood_type, phone, email, city, last_donation_date, status)
            VALUES (:name, :blood_type, :phone, :email, :city, :last_donation_date, :status)
        ");

        $stmt->execute([
            ':name'               => $name,
            ':blood_type'         => $bloodType,
            ':phone'              => $phone,
            ':email'              => $email,
            ':city'               => $city,
            ':last_donation_date' => $lastDonationDate,
            ':status'             => $computedStatus
        ]);

        $newId = (int) $pdo->lastInsertId();

        $fetchStmt = $pdo->prepare("SELECT * FROM donors WHERE id = :id");
        $fetchStmt->execute([':id' => $newId]);
        $newDonor = $fetchStmt->fetch();
        $newDonor['eligibility'] = checkDonationInterval($newDonor['last_donation_date']);
        $newDonor['can_donate_to'] = getCompatibleRecipientTypes($newDonor['blood_type']);

        sendJsonResponse(201, [
            'success' => true,
            'message' => 'Donor registered successfully.',
            'data'    => $newDonor
        ]);
    } catch (Exception $e) {
        error_log("Error creating donor: " . $e->getMessage());
        sendJsonResponse(500, ['success' => false, 'message' => 'Failed to save donor record.']);
    }
}

/**
 * Handles PUT requests: Update existing donor
 */
function handleUpdateDonor(PDO $pdo): void {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];

    $id = !empty($input['id']) ? (int) $input['id'] : (!empty($_GET['id']) ? (int) $_GET['id'] : null);
    if (!$id) {
        sendJsonResponse(400, ['success' => false, 'message' => 'Missing donor ID for update.']);
    }

    // Check existence
    $checkStmt = $pdo->prepare("SELECT * FROM donors WHERE id = :id");
    $checkStmt->execute([':id' => $id]);
    $existing = $checkStmt->fetch();
    if (!$existing) {
        sendJsonResponse(404, ['success' => false, 'message' => 'Donor not found.']);
    }

    $errors = [];
    $name = isset($input['name']) ? sanitizeInput($input['name']) : $existing['name'];
    if (strlen($name) < 3) {
        $errors['name'] = 'Full Name must be at least 3 characters long.';
    }

    $bloodType = isset($input['blood_type']) ? trim($input['blood_type']) : $existing['blood_type'];
    if (!validateBloodType($bloodType)) {
        $errors['blood_type'] = 'Invalid blood type.';
    }

    $phone = isset($input['phone']) ? normalizePhone($input['phone']) : $existing['phone'];
    if (!validatePhone($phone)) {
        $errors['phone'] = 'Phone must be exactly 10 digits.';
    }

    $email = isset($input['email']) ? trim($input['email']) : $existing['email'];
    if (!validateEmail($email)) {
        $errors['email'] = 'Invalid email address.';
    } else {
        $email = sanitizeInput($email);
    }

    $city = isset($input['city']) ? sanitizeInput($input['city']) : $existing['city'];
    $lastDonationDate = array_key_exists('last_donation_date', $input) ? (empty($input['last_donation_date']) ? null : trim($input['last_donation_date'])) : $existing['last_donation_date'];

    $status = isset($input['status']) && in_array($input['status'], ['Available', 'Cooldown', 'Inactive'], true)
        ? $input['status']
        : $existing['status'];

    if ($lastDonationDate) {
        $intervalCheck = checkDonationInterval($lastDonationDate);
        if (!$intervalCheck['is_eligible']) {
            if (isset($intervalCheck['error'])) {
                $errors['last_donation_date'] = $intervalCheck['error'];
            } else {
                $status = 'Cooldown';
            }
        } else {
            if ($status === 'Cooldown') {
                $status = 'Available';
            }
        }
    }

    if (!empty($errors)) {
        sendJsonResponse(422, ['success' => false, 'errors' => $errors]);
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE donors 
            SET name = :name, blood_type = :blood_type, phone = :phone, email = :email, 
                city = :city, last_donation_date = :last_donation_date, status = :status
            WHERE id = :id
        ");
        $stmt->execute([
            ':name'               => $name,
            ':blood_type'         => $bloodType,
            ':phone'              => $phone,
            ':email'              => $email,
            ':city'               => $city,
            ':last_donation_date' => $lastDonationDate,
            ':status'             => $status,
            ':id'                 => $id
        ]);

        $checkStmt->execute([':id' => $id]);
        $updated = $checkStmt->fetch();
        $updated['eligibility'] = checkDonationInterval($updated['last_donation_date']);
        $updated['can_donate_to'] = getCompatibleRecipientTypes($updated['blood_type']);

        sendJsonResponse(200, [
            'success' => true,
            'message' => 'Donor record updated successfully.',
            'data'    => $updated
        ]);
    } catch (Exception $e) {
        error_log("Error updating donor: " . $e->getMessage());
        sendJsonResponse(500, ['success' => false, 'message' => 'Failed to update donor record.']);
    }
}

/**
 * Handles DELETE requests: Remove a donor record
 */
function handleDeleteDonor(PDO $pdo): void {
    $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;

    if (!$id) {
        // Also check JSON body if not in query string
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);
        $id = !empty($input['id']) ? (int) $input['id'] : null;
    }

    if (!$id) {
        sendJsonResponse(400, ['success' => false, 'message' => 'Missing donor ID to delete.']);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM donors WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            sendJsonResponse(404, ['success' => false, 'message' => 'Donor not found or already deleted.']);
        }

        sendJsonResponse(200, [
            'success' => true,
            'message' => "Donor #{$id} deleted successfully."
        ]);
    } catch (Exception $e) {
        error_log("Error deleting donor: " . $e->getMessage());
        sendJsonResponse(500, ['success' => false, 'message' => 'Failed to delete donor.']);
    }
}
