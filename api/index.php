<?php
// Front controller for Vercel single-lambda deployment.
// Routes every request to the matching project PHP file so all pages
// (admin-audit, broadcast, resident-dashboard, etc.) work without
// hitting Vercel's 12-serverless-function Hobby cap.

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = parse_url($requestUri, PHP_URL_PATH);
$uri = ltrim($uri, '/');

if ($uri === '' || $uri === 'index.php') {
    $target = __DIR__ . '/../index.php';
    $script = 'index.php';
} else {
    $target = __DIR__ . '/../' . $uri;
    $script = $uri;
}

// Make included files see the correct script name (active-link highlighting, etc.)
$_SERVER['PHP_SELF'] = '/' . $script;
$_SERVER['SCRIPT_NAME'] = '/' . $script;

if (is_file($target) && substr($target, -4) === '.php') {
    require $target;
    exit;
}

// Static assets that reached here (shouldn't, routes handle them) -> 404
http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo '404 Not Found: ' . $script;
