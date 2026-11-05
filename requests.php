<?php
/**
 * BloodLink Pulse - Requests RESTful API Endpoint
 * Handles full CRUD operations for emergency blood requests and smart donor matching:
 * - Urgency Triage Priority: Critical > High > Medium
 * - Smart Blood Compatibility Matcher
 * - Automatic SMS gateway notification trigger for Critical triage requests
 *
 * Security: PDO Prepared Statements, input sanitization, filter_var validation.
 */

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/fake_sms_gateway.php';

handleCorsPreflight();

$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGetRequests($pdo);
        break;

    case 'POST':
        handleCreateRequest($pdo);
        break;

    case 'PUT':
        handleUpdateRequest($pdo);
        break;

    case 'DELETE':
        handleDeleteRequest($pdo);
        break;

    default:
        sendJsonResponse(405, ['success' => false, 'message' => 'Method Not Allowed']);
}

/**
 * Handles GET requests: List requests with triage sorting, or fetch compatible donors for a request
 */
function handleGetRequests(PDO $pdo): void {
    try {
        $requestId = !empty($_GET['id']) ? (int) $_GET['id'] : null;
        $action    = !empty($_GET['action']) ? sanitizeInput($_GET['action']) : '';

        // If requesting compatible donors for a specific request ID
        if ($requestId && ($action === 'compatible_donors' || $action === 'matches')) {
            findCompatibleDonorsForRequest($pdo, $requestId);
            return;
        }

        // Fetch single request
        if ($requestId) {
            $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $requestId]);
            $request = $stmt->fetch();

            if (!$request) {
                sendJsonResponse(404, ['success' => false, 'message' => 'Blood request not found']);
            }

            $request['compatible_donor_types'] = getCompatibleDonorTypes($request['blood_type']);
            sendJsonResponse(200, ['success' => true, 'data' => $request]);
        }

        // List requests with triage sorting and filters
        $urgency   = !empty($_GET['urgency']) ? sanitizeInput($_GET['urgency']) : null;
        $bloodType = !empty($_GET['blood_type']) ? sanitizeInput($_GET['blood_type']) : null;
        $status    = !empty($_GET['status']) ? sanitizeInput($_GET['status']) : null;
        $city      = !empty($_GET['city']) ? sanitizeInput($_GET['city']) : null;
        $search    = !empty($_GET['search']) ? sanitizeInput($_GET['search']) : null;

        $sql = "SELECT * FROM requests WHERE 1=1";
        $params = [];

        if ($urgency && validateUrgency($urgency)) {
            $sql .= " AND urgency = :urgency";
            $params[':urgency'] = $urgency;
        }

        if ($bloodType && validateBloodType($bloodType)) {
            $sql .= " AND blood_type = :blood_type";
            $params[':blood_type'] = $bloodType;
        }

        if ($status && in_array($status, ['Pending', 'Matched', 'Fulfilled', 'Cancelled'], true)) {
            $sql .= " AND status = :status";
            $params[':status'] = $status;
        }

        if ($city) {
            $sql .= " AND city LIKE :city";
            $params[':city'] = '%' . $city . '%';
        }

        if ($search) {
            $sql .= " AND (patient_name LIKE :search OR hospital_name LIKE :search OR city LIKE :search OR contact_phone LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        // Urgency Triage Ordering: Critical = 1, High = 2, Medium = 3, then newest first
        $sql .= " ORDER BY 
            CASE urgency 
                WHEN 'Critical' THEN 1 
                WHEN 'High' THEN 2 
                WHEN 'Medium' THEN 3 
                ELSE 4 
            END ASC, 
            id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $requests = $stmt->fetchAll();

        foreach ($requests as &$r) {
            $r['compatible_donor_types'] = getCompatibleDonorTypes($r['blood_type']);
        }
        unset($r);

        sendJsonResponse(200, [
            'success' => true,
            'count'   => count($requests),
            'data'    => $requests
        ]);
    } catch (Exception $e) {
        error_log("Error in handleGetRequests: " . $e->getMessage());
        sendJsonResponse(500, ['success' => false, 'message' => 'Internal server error fetching requests']);
    }
}

/**
 * Smart Blood Matcher: Finds and ranks all medically compatible donors for a request
 */
function findCompatibleDonorsForRequest(PDO $pdo, int $requestId): void {
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $requestId]);
    $request = $stmt->fetch();

    if (!$request) {
        sendJsonResponse(404, ['success' => false, 'message' => 'Request not found']);
    }

    $recipientBlood = $request['blood_type'];
    $compatibleDonorTypes = getCompatibleDonorTypes($recipientBlood);

    if (empty($compatibleDonorTypes)) {
        sendJsonResponse(200, [
            'success' => true,
            'request' => $request,
            'compatible_types' => [],
            'count'   => 0,
            'donors'  => []
        ]);
    }

    // Build dynamic placeholders for IN clause
    $inPlaceholders = implode(',', array_fill(0, count($compatibleDonorTypes), '?'));
    $donorSql = "SELECT * FROM donors WHERE blood_type IN ($inPlaceholders) ORDER BY status ASC, id DESC";

    $donorStmt = $pdo->prepare($donorSql);
    $donorStmt->execute($compatibleDonorTypes);
    $rawDonors = $donorStmt->fetchAll();

    $rankedDonors = [];
    foreach ($rawDonors as $d) {
        $intervalInfo = checkDonationInterval($d['last_donation_date']);
        $d['eligibility'] = $intervalInfo;

        // Calculate Smart Match Compatibility Score
        $score = 50; // base score for RBC compatibility

        // Exact match bonus
        if ($d['blood_type'] === $recipientBlood) {
            $score += 25;
            $compatibilityType = 'Exact Match (' . $d['blood_type'] . ')';
        } elseif ($d['blood_type'] === 'O-') {
            $score += 20;
            $compatibilityType = 'Universal Donor (O-)';
        } else {
            $score += 15;
            $compatibilityType = 'Compatible Alternative (' . $d['blood_type'] . ')';
        }

        // City proximity bonus
        $isLocal = (strcasecmp(trim($d['city']), trim($request['city'])) === 0);
        if ($isLocal) {
            $score += 20;
        }

        // Availability bonus vs cooldown penalty
        if ($d['status'] === 'Available' && $intervalInfo['is_eligible']) {
            $score += 15;
        } elseif ($d['status'] === 'Cooldown' || !$intervalInfo['is_eligible']) {
            $score -= 30;
        } elseif ($d['status'] === 'Inactive') {
            $score -= 40;
        }

        $d['match_score'] = max(10, min(100, $score));
        $d['is_local'] = $isLocal;
        $d['compatibility_type'] = $compatibilityType;

        $rankedDonors[] = $d;
    }

    // Sort by match score descending
    usort($rankedDonors, function ($a, $b) {
        return $b['match_score'] <=> $a['match_score'];
    });

    sendJsonResponse(200, [
        'success'                => true,
        'request'                => $request,
        'compatible_donor_types' => $compatibleDonorTypes,
        'count'                  => count($rankedDonors),
        'donors'                 => $rankedDonors
    ]);
}

/**
 * Handles POST requests: Create emergency blood request
 */
function handleCreateRequest(PDO $pdo): void {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;

    $errors = [];

    // 1. Patient Name validation (>= 3 chars)
    $patientName = sanitizeInput($input['patient_name'] ?? '');
    if (strlen($patientName) < 3) {
        $errors['patient_name'] = 'Patient Name must be at least 3 characters long.';
    }

    // 2. Blood Type validation
    $bloodType = trim($input['blood_type'] ?? '');
    if (!validateBloodType($bloodType)) {
        $errors['blood_type'] = 'Invalid blood type requested.';
    }

    // 3. Units Needed (1 to 20)
    $unitsNeeded = (int) ($input['units_needed'] ?? 1);
    if ($unitsNeeded < 1 || $unitsNeeded > 20) {
        $errors['units_needed'] = 'Units needed must be between 1 and 20 units.';
    }

    // 4. Urgency Triage Level
    $urgency = trim($input['urgency'] ?? 'High');
    if (!validateUrgency($urgency)) {
        $errors['urgency'] = 'Urgency triage must be Critical, High, or Medium.';
    }

    // 5. Hospital Name validation (>= 3 chars)
    $hospitalName = sanitizeInput($input['hospital_name'] ?? '');
    if (strlen($hospitalName) < 3) {
        $errors['hospital_name'] = 'Hospital/Facility name is required (min 3 chars).';
    }

    // 6. City validation
    $city = sanitizeInput($input['city'] ?? '');
    if (empty($city)) {
        $errors['city'] = 'City is required for emergency dispatch routing.';
    }

    // 7. Contact Phone validation (10 digits)
    $contactPhone = normalizePhone($input['contact_phone'] ?? '');
    if (!validatePhone($contactPhone)) {
        $errors['contact_phone'] = 'Contact phone must be an exact 10-digit emergency number.';
    }

    $notes = sanitizeInput($input['notes'] ?? '');

    if (!empty($errors)) {
        sendJsonResponse(422, [
            'success' => false,
            'message' => 'Validation failed for one or more fields.',
            'errors'  => $errors
        ]);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO requests (patient_name, blood_type, units_needed, urgency, hospital_name, city, contact_phone, status, notes)
            VALUES (:patient_name, :blood_type, :units_needed, :urgency, :hospital_name, :city, :contact_phone, 'Pending', :notes)
        ");

        $stmt->execute([
            ':patient_name'  => $patientName,
            ':blood_type'    => $bloodType,
            ':units_needed'  => $unitsNeeded,
            ':urgency'       => $urgency,
            ':hospital_name' => $hospitalName,
            ':city'          => $city,
            ':contact_phone' => $contactPhone,
            ':notes'         => $notes
        ]);

        $newRequestId = (int) $pdo->lastInsertId();

        $fetchStmt = $pdo->prepare("SELECT * FROM requests WHERE id = :id");
        $fetchStmt->execute([':id' => $newRequestId]);
        $newRequest = $fetchStmt->fetch();
        $newRequest['compatible_donor_types'] = getCompatibleDonorTypes($newRequest['blood_type']);

        // Automated Emergency Match & Mock SMS Gateway Dispatch for CRITICAL triage
        $autoDispatch = null;
        if ($urgency === 'Critical') {
            $compatTypes = $newRequest['compatible_donor_types'];
            if (!empty($compatTypes)) {
                $placeholders = implode(',', array_fill(0, count($compatTypes), '?'));
                $matchStmt = $pdo->prepare("
                    SELECT * FROM donors 
                    WHERE blood_type IN ($placeholders) 
                      AND status = 'Available'
                    ORDER BY (city = ?) DESC, id ASC 
                    LIMIT 1
                ");
                $params = array_merge($compatTypes, [$city]);
                $matchStmt->execute($params);
                $bestDonor = $matchStmt->fetch();

                if ($bestDonor) {
                    $autoDispatch = dispatchEmergencySmsAlert([
                        'donor_name'    => $bestDonor['name'],
                        'donor_phone'   => $bestDonor['phone'],
                        'patient_name'  => $newRequest['patient_name'],
                        'hospital'      => $newRequest['hospital_name'],
                        'blood_type'    => $newRequest['blood_type'],
                        'units_needed'  => $newRequest['units_needed'],
                        'urgency'       => 'Critical',
                        'request_id'    => $newRequestId,
                        'donor_id'      => $bestDonor['id'],
                        'contact_phone' => $newRequest['contact_phone']
                    ]);
                    $newRequest['status'] = 'Matched';
                }
            }
        }

        sendJsonResponse(201, [
            'success'       => true,
            'message'       => 'Emergency blood request registered successfully.',
            'data'          => $newRequest,
            'auto_dispatch' => $autoDispatch
        ]);
    } catch (Exception $e) {
        error_log("Error creating request: " . $e->getMessage());
        sendJsonResponse(500, ['success' => false, 'message' => 'Failed to save emergency request.']);
    }
}

/**
 * Handles PUT requests: Update existing request status or details
 */
function handleUpdateRequest(PDO $pdo): void {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];

    $id = !empty($input['id']) ? (int) $input['id'] : (!empty($_GET['id']) ? (int) $_GET['id'] : null);
    if (!$id) {
        sendJsonResponse(400, ['success' => false, 'message' => 'Missing request ID for update.']);
    }

    $checkStmt = $pdo->prepare("SELECT * FROM requests WHERE id = :id");
    $checkStmt->execute([':id' => $id]);
    $existing = $checkStmt->fetch();
    if (!$existing) {
        sendJsonResponse(404, ['success' => false, 'message' => 'Request not found.']);
    }

    $errors = [];
    $patientName = isset($input['patient_name']) ? sanitizeInput($input['patient_name']) : $existing['patient_name'];
    if (strlen($patientName) < 3) {
        $errors['patient_name'] = 'Patient Name must be at least 3 characters.';
    }

    $bloodType = isset($input['blood_type']) ? trim($input['blood_type']) : $existing['blood_type'];
    if (!validateBloodType($bloodType)) {
        $errors['blood_type'] = 'Invalid blood type.';
    }

    $unitsNeeded = isset($input['units_needed']) ? (int) $input['units_needed'] : (int) $existing['units_needed'];
    if ($unitsNeeded < 1 || $unitsNeeded > 20) {
        $errors['units_needed'] = 'Units needed must be between 1 and 20.';
    }

    $urgency = isset($input['urgency']) ? trim($input['urgency']) : $existing['urgency'];
    if (!validateUrgency($urgency)) {
        $errors['urgency'] = 'Invalid urgency triage.';
    }

    $hospitalName = isset($input['hospital_name']) ? sanitizeInput($input['hospital_name']) : $existing['hospital_name'];
    $city = isset($input['city']) ? sanitizeInput($input['city']) : $existing['city'];
    $contactPhone = isset($input['contact_phone']) ? normalizePhone($input['contact_phone']) : $existing['contact_phone'];
    if (!validatePhone($contactPhone)) {
        $errors['contact_phone'] = 'Contact phone must be exactly 10 digits.';
    }

    $status = isset($input['status']) && in_array($input['status'], ['Pending', 'Matched', 'Fulfilled', 'Cancelled'], true)
        ? $input['status']
        : $existing['status'];

    $notes = isset($input['notes']) ? sanitizeInput($input['notes']) : $existing['notes'];

    if (!empty($errors)) {
        sendJsonResponse(422, ['success' => false, 'errors' => $errors]);
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE requests 
            SET patient_name = :patient_name, blood_type = :blood_type, units_needed = :units_needed,
                urgency = :urgency, hospital_name = :hospital_name, city = :city,
                contact_phone = :contact_phone, status = :status, notes = :notes
            WHERE id = :id
        ");
        $stmt->execute([
            ':patient_name'  => $patientName,
            ':blood_type'    => $bloodType,
            ':units_needed'  => $unitsNeeded,
            ':urgency'       => $urgency,
            ':hospital_name' => $hospitalName,
            ':city'          => $city,
            ':contact_phone' => $contactPhone,
            ':status'        => $status,
            ':notes'         => $notes,
            ':id'            => $id
        ]);

        $checkStmt->execute([':id' => $id]);
        $updated = $checkStmt->fetch();
        $updated['compatible_donor_types'] = getCompatibleDonorTypes($updated['blood_type']);

        sendJsonResponse(200, [
            'success' => true,
            'message' => 'Request updated successfully.',
            'data'    => $updated
        ]);
    } catch (Exception $e) {
        error_log("Error updating request: " . $e->getMessage());
        sendJsonResponse(500, ['success' => false, 'message' => 'Failed to update request.']);
    }
}

/**
 * Handles DELETE requests: Remove a request record
 */
function handleDeleteRequest(PDO $pdo): void {
    $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;

    if (!$id) {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);
        $id = !empty($input['id']) ? (int) $input['id'] : null;
    }

    if (!$id) {
        sendJsonResponse(400, ['success' => false, 'message' => 'Missing request ID to delete.']);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM requests WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            sendJsonResponse(404, ['success' => false, 'message' => 'Request not found or already deleted.']);
        }

        sendJsonResponse(200, [
            'success' => true,
            'message' => "Request #{$id} deleted successfully."
        ]);
    } catch (Exception $e) {
        error_log("Error deleting request: " . $e->getMessage());
        sendJsonResponse(500, ['success' => false, 'message' => 'Failed to delete request.']);
    }
}
