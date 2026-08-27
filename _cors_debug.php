<?php
// TEMPORARY diagnostic — upload, open once in a browser, read the output,
// then DELETE this file from the server. It reveals nothing sensitive
// (no passwords), but it's not meant to stay live.
header('Content-Type: text/plain');

echo "getenv('CORS_ALLOWED_ORIGINS'): ";
var_export(getenv('CORS_ALLOWED_ORIGINS'));
echo "\n";

echo "\$_ENV['CORS_ALLOWED_ORIGINS']: ";
var_export($_ENV['CORS_ALLOWED_ORIGINS'] ?? null);
echo "\n";

echo "\$_SERVER['CORS_ALLOWED_ORIGINS'] (before load): ";
var_export($_SERVER['CORS_ALLOWED_ORIGINS'] ?? null);
echo "\n\n";

$envFile = __DIR__ . '/.env';
echo "Reading: $envFile\n";
echo "File exists: " . (is_file($envFile) ? 'yes' : 'NO') . "\n";
echo "File readable: " . (is_readable($envFile) ? 'yes' : 'NO') . "\n\n";

if (is_readable($envFile)) {
    echo "Lines containing CORS or API_BASE_URL:\n";
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $i => $line) {
        if (stripos($line, 'CORS') !== false || stripos($line, 'API_BASE_URL') !== false) {
            echo "  line " . ($i + 1) . ": " . $line . "\n";
        }
    }
}

echo "\n--- Now actually loading via the app's own loader ---\n";
require_once __DIR__ . '/env_loader.php';
hrmis_load_env_file($envFile);
echo "getenv('CORS_ALLOWED_ORIGINS') after hrmis_load_env_file(): ";
var_export(getenv('CORS_ALLOWED_ORIGINS'));
echo "\n";

echo "\nRequest Origin header seen by this script: ";
var_export($_SERVER['HTTP_ORIGIN'] ?? null);
echo "\n";

echo "\nRequest Host: ";
var_export($_SERVER['HTTP_HOST'] ?? null);
echo "\n";

echo "\nHTTPS server var: ";
var_export($_SERVER['HTTPS'] ?? null);
echo "\n";
