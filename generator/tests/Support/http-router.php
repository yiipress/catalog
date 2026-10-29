<?php

declare(strict_types=1);

// Router for the PHP built-in server used by the HTTP client tests.

$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = parse_url($uri, PHP_URL_PATH);
$query = [];
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
$header = static fn(string $name): string => is_string($_SERVER[$name] ?? null) ? $_SERVER[$name] : 'none';
$to = is_string($query['to'] ?? null) ? $query['to'] : '/auth';

switch ($path) {
    case '/auth':
        header('X-Ratelimit-Remaining: 4999');
        echo $header('HTTP_AUTHORIZATION');
        break;
    case '/agent':
        echo $header('HTTP_USER_AGENT');
        break;
    case '/redirect':
        header('Location: ' . $to, true, 302);
        break;
    case '/loop':
        header('Location: /loop', true, 302);
        break;
    case '/big':
        echo str_repeat('x', 2000);
        break;
    case '/missing':
        http_response_code(404);
        echo '{"message":"Not Found"}';
        break;
    case '/slow':
        sleep(3);
        echo 'late';
        break;
    default:
        http_response_code(500);
}
