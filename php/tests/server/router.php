<?php

// Router for `php -S` used by CurlTransportTest. Never used in production.

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/api/v1/wallet') {
    echo json_encode(['currency' => 'MZN', 'balance' => 10.5, 'reserved' => 0.5, 'available' => 10]);
    return true;
}
if ($path === '/api/v1/messages' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(402);
    header('Content-Type: application/problem+json');
    echo json_encode(['title' => 'Saldo insuficiente para este envio.', 'status' => 402, 'traceId' => 'trace-1']);
    return true;
}
if (preg_match('#^/status/(\d{3})$#', $path, $m) === 1) {
    http_response_code((int) $m[1]);
    header('Retry-After: 7');
    echo json_encode(['title' => 'Estado ' . $m[1]]);
    return true;
}
if ($path === '/sleep') {
    sleep(2);
    echo '{}';
    return true;
}

$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower($name)] = $value;
}
header('X-Echo: yes');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
]);
return true;
