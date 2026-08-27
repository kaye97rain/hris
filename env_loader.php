<?php
/**
 * env_loader.php — the one canonical .env-file parser.
 *
 * Replaces 6 independently copy-pasted loaders across config.php/db_auth.php/
 * leave_db.php/pds_db.php/system/config.php. Several of those shared the name
 * `loadEnv` guarded by `function_exists()`, so which copy's behavior actually
 * ran already depended on undocumented include order, not on which file you
 * were reading — this removes that lottery, not just the duplication.
 */
if (!function_exists('hrmis_load_env_file')) {
    function hrmis_load_env_file(string $path): void {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if ($name === '' || array_key_exists($name, $_SERVER) || array_key_exists($name, $_ENV)) {
                continue;
            }
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
