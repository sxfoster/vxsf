<?php
// unit_query.php
// Simple endpoint that queries Salesforce Unit__c records and returns JSON.

declare(strict_types=1);

$expectedToken = getenv('UNIT_QUERY_API_KEY');
if (!$expectedToken || $expectedToken === 'REPLACE_WITH_A_LONG_RANDOM_SECRET') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'misconfigured_api_key',
        'message' => 'UNIT_QUERY_API_KEY is missing or invalid.',
    ]);
    exit;
}

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

$where = [];

$unitId = $_GET['unit_id'] ?? null;
if ($unitId !== null && $unitId !== '') {
    $unitId = trim((string) $unitId);
    if (!preg_match('/^[a-zA-Z0-9]{15,18}$/', $unitId)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_unit_id',
            'message' => 'unit_id must be a 15-18 character Salesforce ID.',
        ]);
        exit;
    }
    $where[] = "Id = '{$unitId}'";
}

$from = $_GET['from'] ?? null;
if ($from !== null && $from !== '') {
    $from = trim((string) $from);
    $fromDate = DateTime::createFromFormat('Y-m-d', $from);
    if (!$fromDate || $fromDate->format('Y-m-d') !== $from) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_from',
            'message' => 'from must be in YYYY-MM-DD format.',
        ]);
        exit;
    }
    $where[] = "LastModifiedDate >= {$from}T00:00:00Z";
}

$to = $_GET['to'] ?? null;
if ($to !== null && $to !== '') {
    $to = trim((string) $to);
    $toDate = DateTime::createFromFormat('Y-m-d', $to);
    if (!$toDate || $toDate->format('Y-m-d') !== $to) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_to',
            'message' => 'to must be in YYYY-MM-DD format.',
        ]);
        exit;
    }
    $where[] = "LastModifiedDate <= {$to}T23:59:59Z";
}

$limit = $_GET['limit'] ?? null;
if ($limit !== null && $limit !== '') {
    if (filter_var($limit, FILTER_VALIDATE_INT) === false) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_limit',
            'message' => 'limit must be an integer.',
        ]);
        exit;
    }
    $limit = (int) $limit;
    if ($limit < 1 || $limit > 2000) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_limit',
            'message' => 'limit must be between 1 and 2000.',
        ]);
        exit;
    }
}

if ($where) {
    $soql .= ' WHERE ' . implode(' AND ', $where);
}

if ($limit !== null && $limit !== '') {
    $soql .= " LIMIT {$limit}";
}

$cacheFile = __DIR__ . '/.cache/unit_query_' . sha1($soql) . '.json';
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
