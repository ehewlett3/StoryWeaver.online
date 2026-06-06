<?php
/**
 * StoryWeaver — access-checked story image server.
 */

require_once __DIR__ . '/_lib/auth_check.php';
require_once __DIR__ . '/_lib/nodes.php';

$story_id = (string) ($_GET['story'] ?? '');
$node_id = (string) ($_GET['node'] ?? '');
$filename = basename((string) ($_GET['file'] ?? ''));
$user = current_user();

if (!validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
    http_response_code(404);
    exit;
}

$pattern = '/^' . preg_quote($node_id, '/') . '-\d+-[a-f0-9]{8}\.(png|jpg|jpeg|gif|webp)$/';
if ($filename === '' || preg_match($pattern, $filename) !== 1) {
    http_response_code(404);
    exit;
}

$node = node_read_for_user($story_id, $node_id, $user);
if ($node === null) {
    http_response_code(404);
    exit;
}

$path = sw_root() . '/_assets/images/' . $filename;
$real = realpath($path);
$images_dir = realpath(sw_root() . '/_assets/images');
if ($real === false || $images_dir === false || !str_starts_with($real, $images_dir . DIRECTORY_SEPARATOR) || !is_file($real)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$mime = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($real));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($real);
