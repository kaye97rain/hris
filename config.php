<?php
// Load .env file manually (only if not already loaded)
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
        return true;
    }
}

loadEnv(__DIR__ . '/.env');

// Set API directory path for PHP includes
$_ENV['API_DIR_PATH'] = __DIR__;


$host     = $_ENV['DB_HOST'];
$dbname   = $_ENV['DB_NAME'];
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASS'];

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    // Only output error if this file is called directly, not when included
    if (basename($_SERVER['SCRIPT_FILENAME']) === 'config.php') {
        echo json_encode(['error' => $e->getMessage()]);
    } else {
        // For included files, throw exception instead of outputting
        throw $e;
    }
    exit;
}
?>
