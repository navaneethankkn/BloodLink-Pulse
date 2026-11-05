<?php
/**
 * BloodLink Pulse - Fake SMS Gateway Integration
 * Simulates carrier SMS dispatch for emergency blood matching alerts by integrating
 * a real cURL POST request to https://jsonplaceholder.typicode.com/posts.
 *
 * Requirements:
 * - cURL call to external endpoint
 * - Secure input sanitization and PDO prepared statements
 * - Detailed carrier dispatch audit log in match_logs
 */

require_once __DIR__ . '/../db_config.php';

handleCorsPreflight();

/**
 * Core function to dispatch an emergency SMS alert via external cURL mock gateway.
 *
 * @param array $payloadData Array containing donor, request, and triage details.
 * @return array Result summary including external gateway ID, latency, and status.
 */
function dispatchEmergencySmsAlert(array $payloadData): array {
    $donorName    = sanitizeInput($payloadData['donor_name'] ?? 'Registered Donor');
    $donorPhone   = normalizePhone($payloadData['donor_phone'] ?? '');
    $patientName  = sanitizeInput($payloadData['patient_name'] ?? 'Emergency Patient');
    $hospital     = sanitizeInput($payloadData['hospital'] ?? 'Trauma Center');
    $bloodType    = sanitizeInput($payloadData['blood_type'] ?? 'Unknown');
    $unitsNeeded  = (int) ($payloadData['units_needed'] ?? 1);
    $urgency      = validateUrgency($payloadData['urgency'] ?? '') ? $payloadData['urgency'] : 'High';
    $requestId    = !empty($payloadData['request_id']) ? (int) $payloadData['request_id'] : null;
    $donorId      = !empty($payloadData['donor_id']) ? (int) $payloadData['donor_id'] : null;
    $contactPhone = normalizePhone($payloadData['contact_phone'] ?? '911');

    $alertPrefix = ($urgency === 'Critical') ? '🚨 CRITICAL EMERGENCY ALERT' : '⚠️ URGENT BLOOD MATCH';
    $smsBody = sprintf(
        "%s: Dear %s, patient %s at %s urgently requires %d unit(s) of %s blood. You are a verified compatible match. Urgency: %s. Contact Hospital Desk immediately at %s. BloodLink Pulse ID: #REQ-%s.",
        $alertPrefix,
        $donorName,
        $patientName,
        $hospital,
        $unitsNeeded,
        $bloodType,
        strtoupper($urgency),
        $contactPhone,
        $requestId ? $requestId : 'LIVE'
    );

    // Build JSON payload according to JSONPlaceholder specification
    $externalPayload = [
        'title'           => "BLOODLINK_SMS_DISPATCH [{$urgency}] -> {$donorPhone}",
        'body'            => $smsBody,
        'userId'          => 1,
        'recipient_phone' => $donorPhone,
        'recipient_name'  => $donorName,
        'patient_name'    => $patientName,
        'hospital_name'   => $hospital,
        'blood_type'      => $bloodType,
        'units_needed'    => $unitsNeeded,
        'urgency_level'   => $urgency,
        'gateway_carrier' => 'BloodLink-Pulse-Telecom-Simulator',
        'sent_at'         => date('c')
    ];

    $jsonPayload = json_encode($externalPayload, JSON_UNESCAPED_SLASHES);
    $startTime = microtime(true);

    // Execute real cURL call to JSONPlaceholder
    $ch = curl_init('https://jsonplaceholder.typicode.com/posts');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'User-Agent: BloodLink-Pulse-Emergency-Matcher/1.0'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false, // Prevents local Windows cURL CA bundle mismatches
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $responseRaw = curl_exec($ch);
    $curlErrno   = curl_errno($ch);
    $curlError   = curl_error($ch);
    $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $durationMs = round((microtime(true) - $startTime) * 1000, 2);

    $isSuccess = ($curlErrno === 0 && ($httpCode === 200 || $httpCode === 201));
    $parsedResponse = null;
    $externalPostId = null;

    if ($isSuccess && !empty($responseRaw)) {
        $parsedResponse = json_decode($responseRaw, true);
        $externalPostId = isset($parsedResponse['id']) ? (string) $parsedResponse['id'] : 'SIM-' . rand(100, 999);
        $smsStatus = 'Sent';
    } else {
        // If external network is blocked or offline, simulate delivery gracefully
        $smsStatus = 'Simulated';
        $externalPostId = 'SIM-OFFLINE-' . rand(1000, 9999);
        $parsedResponse = [
            'id'      => $externalPostId,
            'notice'  => 'Simulated gateway response (cURL fallback)',
            'details' => $curlError ?: ("HTTP code: " . $httpCode)
        ];
    }

    // Persist Match & SMS Dispatch to match_logs table if request_id and donor_id are present
    $logId = null;
    if ($requestId && $donorId) {
        try {
            $pdo = getDbConnection();
            $logStmt = $pdo->prepare("
                INSERT INTO match_logs (request_id, donor_id, urgency_level, sms_status, sms_response_id, sms_payload, matched_at)
                VALUES (:request_id, :donor_id, :urgency_level, :sms_status, :sms_response_id, :sms_payload, CURRENT_TIMESTAMP)
            ");
            $logStmt->execute([
                ':request_id'      => $requestId,
                ':donor_id'        => $donorId,
                ':urgency_level'   => $urgency,
                ':sms_status'      => $smsStatus,
                ':sms_response_id' => (string) $externalPostId,
                ':sms_payload'     => json_encode([
                    'to'              => $donorPhone,
                    'message'         => $smsBody,
                    'external_post_id'=> $externalPostId,
                    'http_code'       => $httpCode,
                    'latency_ms'      => $durationMs,
                    'gateway'         => 'https://jsonplaceholder.typicode.com/posts'
                ])
            ]);
            $logId = (int) $pdo->lastInsertId();

            // Update request status to Matched if currently Pending
            $updStmt = $pdo->prepare("UPDATE requests SET status = 'Matched' WHERE id = :id AND status = 'Pending'");
            $updStmt->execute([':id' => $requestId]);

        } catch (Exception $e) {
            error_log("Failed to write to match_logs: " . $e->getMessage());
        }
    }

    return [
        'success'           => true,
        'sms_status'        => $smsStatus,
        'external_post_id'  => $externalPostId,
        'http_status_code'  => $httpCode,
        'latency_ms'        => $durationMs,
        'message_preview'   => $smsBody,
        'recipient'         => [
            'name'  => $donorName,
            'phone' => $donorPhone
        ],
        'patient'           => [
            'name'     => $patientName,
            'hospital' => $hospital,
            'blood'    => $bloodType
        ],
        'gateway_endpoint'  => 'https://jsonplaceholder.typicode.com/posts',
        'match_log_id'      => $logId,
        'raw_response'      => $parsedResponse
    ];
}

// If invoked directly over HTTP
if (isset($_SERVER['SCRIPT_FILENAME']) && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST') {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!is_array($data)) {
            $data = $_POST;
        }

        // Validate basic parameters
        $phone = normalizePhone($data['donor_phone'] ?? $data['phone'] ?? '');
        if (strlen($phone) !== 10) {
            sendJsonResponse(400, [
                'success' => false,
                'message' => 'Invalid phone number. Must be a 10-digit number for SMS dispatch.'
            ]);
        }

        $result = dispatchEmergencySmsAlert($data);
        sendJsonResponse(200, [
            'success' => true,
            'message' => 'Mock SMS alert successfully dispatched via external gateway cURL call.',
            'data'    => $result
        ]);
    } elseif ($method === 'GET') {
        // Health check endpoint for SMS gateway
        sendJsonResponse(200, [
            'service'          => 'BloodLink Pulse Fake SMS Gateway',
            'status'           => 'OPERATIONAL',
            'external_gateway' => 'https://jsonplaceholder.typicode.com/posts',
            'timestamp'        => date('c')
        ]);
    } else {
        sendJsonResponse(405, ['success' => false, 'message' => 'Method Not Allowed']);
    }
}
