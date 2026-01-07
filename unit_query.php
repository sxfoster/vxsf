<?php
// unit_query.php
// Simple endpoint that queries Salesforce Unit__c records and returns JSON.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$instanceBase = 'https://nosoftware-platform-1391.my.salesforce.com';
$apiVersion = 'v61.0';

// SOQL query (unencoded)
$soql = "SELECT Id,Name,Status__c,Sub_Status__c,Unit_Offline__c,Model__c,GPS_IMEI__c,GPS_URL__c,"
    . "Spot_Ai_Serial_Number__c,Starlink_Serial_Number__c,Carbo_Gx_Serial_Number__c,LastModifiedDate "
    . "FROM Unit__c";

// Token retrieval (preferred: env var)
$token = getenv('SF_BEARER_TOKEN');

// Optional: allow header token for dev use
if (!$token) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (isset($headers['X-SF-Token'])) {
        $token = $headers['X-SF-Token'];
    } elseif (isset($headers['x-sf-token'])) {
        $token = $headers['x-sf-token'];
    }
}

if (!$token) {
    http_response_code(400);
    echo json_encode([
        'error' => 'missing_token',
        'message' => 'Provide SF_BEARER_TOKEN env var (preferred) or X-SF-Token header (dev only).',
    ]);
    exit;
}

$url = $instanceBase . "/services/data/{$apiVersion}/query?q=" . rawurlencode($soql);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$token}",
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT => 30,
]);

$responseBody = curl_exec($ch);
$curlErrNo = curl_errno($ch);
$curlErr = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErrNo) {
    http_response_code(502);
    echo json_encode([
        'error' => 'network_error',
        'message' => 'Failed to reach Salesforce.',
        'details' => $curlErr,
    ]);
    exit;
}

// If Salesforce returns non-2xx, forward status and include body for debugging
if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode ?: 502);

    // Try to decode SF error JSON; if not JSON, return raw text in details
    $decoded = json_decode($responseBody, true);
    echo json_encode([
        'error' => 'salesforce_request_failed',
        'status' => $httpCode,
        'sf' => $decoded ?: null,
        'details' => $decoded ? null : $responseBody,
    ]);
    exit;
}

// Success: pass-through Salesforce JSON
http_response_code(200);
echo $responseBody;
