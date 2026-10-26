<?php
function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        // Skip empty lines or comments (lines starting with #)
        if ($trimmed === '' || strpos($trimmed, '#') === 0 || strpos($trimmed, '=') === false) {
            continue;
        }

        // Split by the first '=' found
        $parts = explode('=', $trimmed, 2);
        if (count($parts) < 2) {
            continue;
        }

        $name = trim($parts[0]);
        $value = trim($parts[1]);

        if ($name === '') {
            continue;
        }

        // Set the variable in the environment and $_ENV superglobal
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
        }
    }
    // return true;
}