<?php
/**
 * BloodLink Pulse - Matches & Match Logs RESTful API Endpoint
 * Handles:
 * - GET: Fetch match logs joined with request, patient, donor, and SMS payload details
 * - POST: Execute a verified manual match between a request and donor, dispatching SMS alert via cURL
 */

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/fake_sms_gateway.php';

handleCorsPreflight();

$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGetMatches($pdo);
        break;

    case 'POST':
        handleCreateMatch($pdo);
        break;

    default:
        sendJsonResponse(405, ['success' => false, 'message' => 'Method Not Allowed']);
}

/**
 * Handles GET requests: Fetch all match logs with joined details
 */
function handleGetMatches(PDO $pdo): void {
    try {
        $sql = "
            SELECT 
                ml.id AS match_id,
                ml.urgency_level,
                ml.sms_status,
                ml.sms_response_id,
                ml.sms_payload,
                ml.matched_at,
                r.id AS request_id,
                r.patient_name,
                r.blood_type AS requested_blood_type,
                r.hospital_name,
                r.city AS request_city,
                r.units_needed,
                d.id AS donor_id,
                d.name AS donor_name,
                d.blood_type AS donor_blood_type,
                d.phone AS donor_phone,
                d.city AS donor_city
            FROM match_logs ml
            JOIN requests r ON ml.request_id = r.id
            JOIN donors d ON ml.donor_id = d.id
            ORDER BY ml.id DESC
        ";

        $stmt = $pdo->query($sql);
        $logs = $stmt->fetchAll();

        foreach ($logs as &$log) {
            if (!empty($log['sms_payload'])) {
                $decoded = json_decode($log['sms_payload'], true);
                $log['sms_payload_parsed'] = $decoded ?: $log['sms_payload'];
            }
        }
        unset($log);

        sendJsonResponse(200, [
            'success' => true,
            'count'   => count($logs),
            'data'    => $logs
        ]);
    } catch (Exception $e) {
        error_log("Error in handleGetMatches: " . $e->getMessage());
        sendJsonResponse(500, ['success' => false, 'message' => 'Failed to fetch match logs.']);
    }
}

/**
 * Handles POST requests: Match a donor to a request and dispatch mock SMS alert via cURL
 */
function handleCreateMatch(PDO $pdo): void {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;

    $requestId = !empty($input['request_id']) ? (int) $input['request_id'] : null;
    $donorId   = !empty($input['donor_id']) ? (int) $input['donor_id'] : null;

    if (!$requestId || !$donorId) {
        sendJsonResponse(400, [
            'success' => false,
            'message' => 'Both request_id and donor_id are required to establish a match.'
        ]);
    }

    // 1. Fetch request details
    $reqStmt = $pdo->prepare("SELECT * FROM requests WHERE id = :id LIMIT 1");
    $reqStmt->execute([':id' => $requestId]);
    $request = $reqStmt->fetch();

    if (!$request) {
        sendJsonResponse(404, ['success' => false, 'message' => 'Request not found.']);
    }

    // 2. Fetch donor details
    $donStmt = $pdo->prepare("SELECT * FROM donors WHERE id = :id LIMIT 1");
    $donStmt->execute([':id' => $donorId]);
    $donor = $donStmt->fetch();

    if (!$donor) {
        sendJsonResponse(404, ['success' => false, 'message' => 'Donor not found.']);
    }

    // 3. Verify medical compatibility
    $compatibleTypes = getCompatibleDonorTypes($request['blood_type']);
    if (!in_array($donor['blood_type'], $compatibleTypes, true)) {
        sendJsonResponse(400, [
            'success' => false,
            'message' => sprintf(
                'Incompatible blood types! Patient requires %s (compatible with: %s), but donor is %s.',
                $request['blood_type'],
                implode(', ', $compatibleTypes),
                $donor['blood_type']
            )
        ]);
    }

    // 4. Dispatch SMS via external mock cURL gateway
    $smsResult = dispatchEmergencySmsAlert([
        'donor_name'    => $donor['name'],
        'donor_phone'   => $donor['phone'],
        'patient_name'  => $request['patient_name'],
        'hospital'      => $request['hospital_name'],
        'blood_type'    => $request['blood_type'],
        'units_needed'  => $request['units_needed'],
        'urgency'       => $request['urgency'],
        'request_id'    => $requestId,
        'donor_id'      => $donorId,
        'contact_phone' => $request['contact_phone']
    ]);

    sendJsonResponse(201, [
        'success'    => true,
        'message'    => 'Donor successfully matched to request and emergency SMS dispatched.',
        'match_data' => [
            'request_id'     => $requestId,
            'patient_name'   => $request['patient_name'],
            'hospital'       => $request['hospital_name'],
            'donor_id'       => $donorId,
            'donor_name'     => $donor['name'],
            'donor_phone'    => $donor['phone'],
            'urgency'        => $request['urgency'],
            'compatibility'  => "{$donor['blood_type']} -> {$request['blood_type']}"
        ],
        'sms_receipt' => $smsResult
    ]);
}
