<?php
// A tiny server for CurlTransportTest, run with `php -S`.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/slow') {
    usleep(1_500_000);
}
$headers = [];
foreach (getallheaders() as $k => $v) {
    $headers[strtolower($k)] = $v;
}
http_response_code($path === '/refuse' ? 422 : 200);
header('Content-Type: application/json');
header('X-Echo: yes');
header('Set-Cookie: a=1');
header('Set-Cookie: b=2', false);
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
]);
