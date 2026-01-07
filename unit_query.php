<?php
// unit_query.php
// Simple endpoint that queries Salesforce Unit__c records and returns JSON.
// Pagination query params:
// - limit: max rows (1-2000) when using offset pagination.
// - offset: zero-based offset (0-2000); requires limit.
// - next_cursor: Salesforce nextRecordsUrl (relative or full) for query continuation.

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

function abortBadRequest(string $error, string $message): void
{
    http_response_code(400);
    echo json_encode([
        'error' => $error,
        'message' => $message,
    ]);
    exit;
}

function parseCsvParam(string $rawValue, string $error, string $message): array
{
    $parts = explode(',', $rawValue);
    $values = [];
    foreach ($parts as $part) {
        $value = trim($part);
        if ($value === '') {
            abortBadRequest($error, $message);
        }
        $values[] = $value;
    }
    if (!$values) {
        abortBadRequest($error, $message);
    }
    return array_values(array_unique($values));
}

// SOQL query (unencoded)
$allowedFields = [
    'Id',
    'Name',
    'Status__c',
    'Sub_Status__c',
    'Unit_Offline__c',
    'LastModifiedDate',
    'Model__c',
];
$defaultFields = [
    'Id',
    'Name',
    'Status__c',
    'Sub_Status__c',
    'Unit_Offline__c',
    'LastModifiedDate',
];

$fieldsParam = $_GET['fields'] ?? null;
if ($fieldsParam !== null && $fieldsParam !== '') {
    $requestedFields = parseCsvParam(
        (string) $fieldsParam,
        'invalid_fields',
        'fields must include at least one field name.'
    );
    $unknownFields = array_diff($requestedFields, $allowedFields);
    if ($unknownFields) {
        abortBadRequest(
            'invalid_fields',
            'Unknown field(s): ' . implode(', ', $unknownFields) . '.'
        );
    }
    $selectFields = $requestedFields;
} else {
    $selectFields = $defaultFields;
}

$soql = 'SELECT ' . implode(',', $selectFields) . ' FROM Unit__c';

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

$status = $_GET['status'] ?? null;
if ($status !== null && $status !== '') {
    $statusValues = parseCsvParam(
        (string) $status,
        'invalid_status',
        'status must be a comma-separated list of values.'
    );
    $escaped = array_map(
        static fn (string $value): string => str_replace("'", "\\'", $value),
        $statusValues
    );
    $where[] = "Status__c IN ('" . implode("','", $escaped) . "')";
}

$subStatus = $_GET['sub_status'] ?? null;
if ($subStatus !== null && $subStatus !== '') {
    $subStatusValues = parseCsvParam(
        (string) $subStatus,
        'invalid_sub_status',
        'sub_status must be a comma-separated list of values.'
    );
    $escaped = array_map(
        static fn (string $value): string => str_replace("'", "\\'", $value),
        $subStatusValues
    );
    $where[] = "Sub_Status__c IN ('" . implode("','", $escaped) . "')";
}

$model = $_GET['model'] ?? null;
if ($model !== null && $model !== '') {
    $modelValues = parseCsvParam(
        (string) $model,
        'invalid_model',
        'model must be a comma-separated list of values.'
    );
    $escaped = array_map(
        static fn (string $value): string => str_replace("'", "\\'", $value),
        $modelValues
    );
    $where[] = "Model__c IN ('" . implode("','", $escaped) . "')";
}

$offline = $_GET['offline'] ?? null;
if ($offline !== null && $offline !== '') {
    $normalized = strtolower(trim((string) $offline));
    if (!in_array($normalized, ['true', 'false'], true)) {
        abortBadRequest(
            'invalid_offline',
            'offline must be true or false.'
        );
    }
    $where[] = "Unit_Offline__c = {$normalized}";
}

$modifiedSince = $_GET['modified_since'] ?? null;
if ($modifiedSince !== null && $modifiedSince !== '') {
    $modifiedSince = trim((string) $modifiedSince);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $modifiedSince)) {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $modifiedSince);
        if (!$date || $date->format('Y-m-d') !== $modifiedSince) {
            abortBadRequest(
                'invalid_modified_since',
                'modified_since must be a valid date in YYYY-MM-DD format.'
            );
        }
        $dateUtc = $date->setTimezone(new DateTimeZone('UTC'));
        $where[] = 'LastModifiedDate >= ' . $dateUtc->format('Y-m-d\TH:i:s\Z');
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $modifiedSince)) {
        try {
            $dateTime = new DateTimeImmutable($modifiedSince);
        } catch (Exception $exception) {
            $dateTime = false;
        }
        if (!$dateTime) {
            abortBadRequest(
                'invalid_modified_since',
                'modified_since must be a valid ISO datetime.'
            );
        }
        $dateUtc = $dateTime->setTimezone(new DateTimeZone('UTC'));
        $where[] = 'LastModifiedDate >= ' . $dateUtc->format('Y-m-d\TH:i:s\Z');
    } else {
        abortBadRequest(
            'invalid_modified_since',
            'modified_since must be a date (YYYY-MM-DD) or ISO datetime.'
        );
    }
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

$maxLimit = 200;
$limit = $_GET['limit'] ?? null;
if ($limit === null || $limit === '') {
    $limit = $maxLimit;
} else {
    if (filter_var($limit, FILTER_VALIDATE_INT) === false) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_limit',
            'message' => 'limit must be an integer.',
        ]);
        exit;
    }
    $limit = (int) $limit;
    if ($limit < 1 || $limit > $maxLimit) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_limit',
            'message' => "limit must be between 1 and {$maxLimit}.",
        ]);
        exit;
    }
}

$offset = $_GET['offset'] ?? null;
if ($offset !== null && $offset !== '') {
    if (filter_var($offset, FILTER_VALIDATE_INT) === false) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_offset',
            'message' => 'offset must be an integer.',
        ]);
        exit;
    }
    $offset = (int) $offset;
    if ($offset < 0 || $offset > 2000) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_offset',
            'message' => 'offset must be between 0 and 2000.',
        ]);
        exit;
    }
    if ($limit === null || $limit === '') {
        http_response_code(400);
        echo json_encode([
            'error' => 'offset_requires_limit',
            'message' => 'offset requires limit to be set.',
        ]);
        exit;
    }
}

$nextCursor = $_GET['next_cursor'] ?? null;
if ($nextCursor !== null && $nextCursor !== '') {
    $nextCursor = trim((string) $nextCursor);
    $hasUnitId = $unitId !== null && $unitId !== '';
    $hasFrom = $from !== null && $from !== '';
    $hasTo = $to !== null && $to !== '';
    $hasStatus = $status !== null && $status !== '';
    $hasSubStatus = $subStatus !== null && $subStatus !== '';
    $hasModel = $model !== null && $model !== '';
    $hasOffline = $offline !== null && $offline !== '';
    $hasModifiedSince = $modifiedSince !== null && $modifiedSince !== '';
    $hasFields = $fieldsParam !== null && $fieldsParam !== '';
    if ($limit !== null || $offset !== null || $hasUnitId || $hasFrom || $hasTo || $hasStatus || $hasSubStatus || $hasModel || $hasOffline || $hasModifiedSince || $hasFields) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_next_cursor_usage',
            'message' => 'next_cursor cannot be combined with other query filters.',
        ]);
        exit;
    }
}

if ($where) {
    $soql .= ' WHERE ' . implode(' AND ', $where);
}

$soql .= " LIMIT {$limit}";

if ($offset !== null && $offset !== '') {
    $soql .= " OFFSET {$offset}";
}

$cacheKey = $nextCursor ? "cursor:{$nextCursor}" : $soql;
$cacheFile = __DIR__ . '/.cache/unit_query_' . sha1($cacheKey) . '.json';
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
if ($nextCursor !== null && $nextCursor !== '') {
    if (str_starts_with($nextCursor, 'https://')) {
        if (!str_starts_with($nextCursor, $instanceBase)) {
            http_response_code(400);
            echo json_encode([
                'error' => 'invalid_next_cursor',
                'message' => 'next_cursor must match the configured Salesforce instance.',
            ]);
            exit;
        }
        $url = $nextCursor;
    } elseif (str_starts_with($nextCursor, '/services/data/')) {
        $url = $instanceBase . $nextCursor;
    } else {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_next_cursor',
            'message' => 'next_cursor must be a Salesforce nextRecordsUrl.',
        ]);
        exit;
    }
}

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

// Success: pass-through Salesforce JSON (with pagination metadata if applicable)
$cacheDir = dirname($cacheFile);
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}
$payload = json_decode($responseBody, true);
if (is_array($payload)) {
    $pagination = [];
    $nextRecordsUrl = $payload['nextRecordsUrl'] ?? null;
    $records = $payload['records'] ?? [];
    $returnedCount = is_array($records) ? count($records) : 0;
    if (is_string($nextRecordsUrl) && $nextRecordsUrl !== '') {
        $pagination['next_cursor'] = $nextRecordsUrl;
        $pagination['nextRecordsUrl'] = $nextRecordsUrl;
        $pagination['has_more'] = true;
    } elseif ($limit !== null) {
        $offsetValue = $offset ?? 0;
        $totalSize = $payload['totalSize'] ?? null;
        if (is_int($totalSize) && ($offsetValue + $returnedCount) < $totalSize) {
            $pagination['next_cursor'] = $offsetValue + $limit;
            $pagination['has_more'] = true;
        }
    }
    if ($pagination) {
        $pagination['limit'] = $limit;
        $pagination['offset'] = $offset ?? 0;
        $pagination['returned'] = $returnedCount;
        $pagination['total_size'] = $payload['totalSize'] ?? null;
        $payload['pagination'] = $pagination;
    }
    $responseBody = json_encode($payload);
}
file_put_contents($cacheFile, $responseBody, LOCK_EX);
http_response_code(200);
echo $responseBody;
