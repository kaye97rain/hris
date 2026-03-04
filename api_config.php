<?php
// Load environment variables
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

header("Content-Type: application/javascript");
?>
const API_BASE_URL = "<?php echo rtrim($_ENV['API_BASE_URL'], '/'); ?>";