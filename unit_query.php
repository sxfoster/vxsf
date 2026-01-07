<?php
// unit_query.php
// Simple endpoint that queries Salesforce Unit__c records and returns JSON.

declare(strict_types=1);

$expectedToken = getenv('UNIT_QUERY_API_KEY') ?: 'REPLACE_WITH_A_LONG_RANDOM_SECRET';

// Grab Authorization header
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!$auth && function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $headerName => $headerValue) {
        if (strcasecmp($headerName, 'Authorization') === 0) {
            $auth = (string) $headerValue;
            break;
        }
    }
}
if (!$auth && isset($_SERVER['Authorization'])) {
    $auth = (string) $_SERVER['Authorization'];
}
if (!$auth) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing Authorization header']);
    exit;
}

// Expect: Authorization: Bearer <token>
if (!preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid Authorization format']);
    exit;
}

$token = trim($m[1]);
if (!hash_equals($expectedToken, $token)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$instanceBase = 'https://nosoftware-platform-1391.my.salesforce.com';
$apiVersion = 'v61.0';

// SOQL query (unencoded)
$soql = "SELECT Id,Name,Status__c,Sub_Status__c,Unit_Offline__c,Model__c,GPS_IMEI__c,GPS_URL__c,"
    . "Spot_Ai_Serial_Number__c,Starlink_Serial_Number__c,Carbo_Gx_Serial_Number__c,LastModifiedDate "
    . "FROM Unit__c";

$cacheFile = __DIR__ . '/.cache/unit_query.json';
$cacheTtlSeconds = 300;

// Token retrieval (preferred: protected file under web root)
$tokenFile = getenv('SF_BEARER_TOKEN_FILE') ?: (__DIR__ . '/.secrets/sf_bearer_token');
$sfToken = null;
if (is_readable($tokenFile)) {
    $sfToken = trim((string) file_get_contents($tokenFile));
}

if (!$sfToken) {
    http_response_code(400);
    echo json_encode([
        'error' => 'missing_token',
        'message' => 'Provide a readable SF_BEARER_TOKEN_FILE with the bearer token (default: ./.secrets/sf_bearer_token).',
    ]);
    exit;
}

if (is_readable($cacheFile)) {
    $cacheMtime = filemtime($cacheFile);
    if ($cacheMtime !== false && (time() - $cacheMtime) < $cacheTtlSeconds) {
        http_response_code(200);
        echo (string) file_get_contents($cacheFile);
        exit;
    }
}

$url = $instanceBase . "/services/data/{$apiVersion}/query?q=" . rawurlencode($soql);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$sfToken}",
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
    if (is_readable($cacheFile)) {
        $cacheBody = (string) file_get_contents($cacheFile);
        $cachedPayload = json_decode($cacheBody, true);
        http_response_code(200);
        if (is_array($cachedPayload)) {
            $cachedPayload['cached'] = true;
            echo json_encode($cachedPayload);
        } else {
            echo json_encode([
                'cached' => true,
                'data' => $cacheBody,
            ]);
        }
        exit;
    }
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
    if (is_readable($cacheFile)) {
        $cacheBody = (string) file_get_contents($cacheFile);
        $cachedPayload = json_decode($cacheBody, true);
        http_response_code(200);
        if (is_array($cachedPayload)) {
            $cachedPayload['cached'] = true;
            echo json_encode($cachedPayload);
        } else {
            echo json_encode([
                'cached' => true,
                'data' => $cacheBody,
            ]);
        }
        exit;
    }
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
$cacheDir = dirname($cacheFile);
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}
file_put_contents($cacheFile, $responseBody, LOCK_EX);
http_response_code(200);
echo $responseBody;
