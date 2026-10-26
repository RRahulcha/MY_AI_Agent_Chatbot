<?php
// ✅ Fast server-side redirect — no browser round-trip, no JS needed.
// Preserves VERSION and token query params if present.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$q = '';

if (isset($_GET['VERSION'])) {
    $q .= '&VERSION=' . urlencode($_GET['VERSION']);
}

if (isset($_GET['token'])) {
    $q .= '&token=' . urlencode($_GET['token']);
}

// Build final URL: trim leading '&' if no '?' base yet
$target = 'pages/index.php' . ($q !== '' ? '?' . ltrim($q, '&') : '');

header("Location: $target");
exit;
?>
