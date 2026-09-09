<?php
/**
 * Dockyard admin API.
 *
 *   GET  api/index.php?file=config.json   -> reads a JSON data file
 *   POST api/index.php                    -> writes config.json / catalog.json
 *        (requires "Authorization: Bearer <token>" header)
 *
 * Data files live one directory above this script (the site root). Writes are
 * atomic (temp file + rename) and a timestamped backup is kept before each
 * write so mistakes are recoverable.
 *
 * Set the admin token in api/secret.php BEFORE going live.
 */

declare(strict_types=1);

require __DIR__ . '/secret.php';

const DATA_DIR = __DIR__ . '/..';
const ALLOWED_FILES = ['config.json', 'catalog.json'];
const MAX_BODY_BYTES = 2_000_000;

function respond(int $status, array $payload): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function requireToken(): bool {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m) !== 1) {
        return false;
    }
    $provided = trim($m[1]);
    return !empty(ADMIN_TOKEN) && hash_equals(ADMIN_TOKEN, $provided);
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!defined('ADMIN_TOKEN') || ADMIN_TOKEN === '') {
    respond(500, ['ok' => false, 'error' => 'API not configured. Set ADMIN_TOKEN in api/secret.php.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $file = $_GET['file'] ?? '';
    if (!in_array($file, ALLOWED_FILES, true)) {
        respond(400, ['ok' => false, 'error' => 'Unknown file. Use config.json or catalog.json.']);
    }
    $path = DATA_DIR . '/' . $file;
    if (!is_file($path) || !is_readable($path)) {
        respond(404, ['ok' => false, 'error' => 'File not found: ' . $file]);
    }
    header('Content-Type: application/json; charset=utf-8');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if (!requireToken()) {
    respond(401, ['ok' => false, 'error' => 'Unauthorized. Include a valid Bearer token.']);
}

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_BODY_BYTES) {
    respond(413, ['ok' => false, 'error' => 'Payload too large.']);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    respond(400, ['ok' => false, 'error' => 'Request body must be valid JSON.']);
}

$file = $body['file'] ?? '';
$content = $body['content'] ?? null;
if (!in_array($file, ALLOWED_FILES, true)) {
    respond(400, ['ok' => false, 'error' => 'Unknown file. Use config.json or catalog.json.']);
}
if (!is_array($content)) {
    respond(400, ['ok' => false, 'error' => '"content" must be a JSON object.']);
}

// Shallow validation so the app never receives malformed data files.
if ($file === 'catalog.json' && !isset($content['mods'])) {
    respond(400, ['ok' => false, 'error' => 'catalog.json content must contain a "mods" array.']);
}

$path = DATA_DIR . '/' . $file;
$json = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    respond(400, ['ok' => false, 'error' => 'Content could not be encoded as JSON.']);
}

// Backup the current version so administrators can recover mistakes.
if (is_file($path)) {
    @copy($path, $path . '.' . date('YmdHis') . '.bak');
}

$tmp = $path . '.tmp';
if (file_put_contents($tmp, $json . "\n") === false) {
    respond(500, ['ok' => false, 'error' => 'Could not write temp file. Check directory permissions.']);
}
if (!@rename($tmp, $path)) {
    @unlink($tmp);
    respond(500, ['ok' => false, 'error' => 'Could not replace ' . $file . '. Check directory permissions.']);
}

respond(200, ['ok' => true, 'file' => $file]);