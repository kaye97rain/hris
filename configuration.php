<?php
$vendorPaths = [__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'];
foreach ($vendorPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

// Autoload Dotenv and other packages

use Dotenv\Dotenv;

// Load .env file from the eBeterinaryo directory
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Access .env variables (optional)
$apiBaseUrl = $_ENV['API_BASE_URL'] ?? '';
