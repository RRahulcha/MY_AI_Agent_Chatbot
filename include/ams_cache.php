<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * ams_cache.php  —  Lightweight file-based cache
 * Place: /include/ams_cache.php
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Provides simple key-value caching backed by temp files.
 * Used by check_punch_out_status.php to avoid hitting the DB on every poll.
 *
 * PUBLIC API:
 *   ams_cache_get(string $key): mixed|false
 *   ams_cache_set(string $key, mixed $data, int $ttl = 30): bool
 *   ams_cache_delete(string $key): bool
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */

/**
 * Get a cached value by key.
 *
 * @param  string $key  Cache key (e.g. "punch_out_status:USER001:2026-06-18")
 * @return mixed        The cached data (array/string/int), or FALSE on miss/expiry.
 */
function ams_cache_get(string $key)
{
    $file = _ams_cache_path($key);

    if (!file_exists($file)) {
        return false;
    }

    $raw = @file_get_contents($file);
    if ($raw === false) {
        return false;
    }

    $entry = @json_decode($raw, true);
    if (!is_array($entry) || empty($entry['expiry'])) {
        return false;
    }

    // Expired — clean up and return miss
    if (time() >= (int)$entry['expiry']) {
        @unlink($file);
        return false;
    }

    return $entry['data'] ?? false;
}

/**
 * Store a value in the cache.
 *
 * @param  string $key   Cache key
 * @param  mixed  $data  Data to cache (must be JSON-serializable)
 * @param  int    $ttl   Time-to-live in seconds (default 30)
 * @return bool          TRUE on success
 */
function ams_cache_set(string $key, $data, int $ttl = 30): bool
{
    $file = _ams_cache_path($key);
    $dir  = dirname($file);

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $entry = json_encode([
        'expiry' => time() + $ttl,
        'data'   => $data,
    ], JSON_UNESCAPED_UNICODE);

    // LOCK_EX ensures atomic writes — no partial reads from concurrent requests
    return file_put_contents($file, $entry, LOCK_EX) !== false;
}

/**
 * Delete a cached entry.
 *
 * @param  string $key  Cache key
 * @return bool         TRUE if deleted or already absent
 */
function ams_cache_delete(string $key): bool
{
    $file = _ams_cache_path($key);

    if (file_exists($file)) {
        return @unlink($file);
    }

    return true;
}

/**
 * Build the filesystem path for a cache key.
 * Keys are hashed to avoid filesystem-unsafe characters.
 *
 * @param  string $key
 * @return string      Absolute path to the cache file
 */
function _ams_cache_path(string $key): string
{
    $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ams_cache';
    $safeKey  = md5($key);

    return $cacheDir . DIRECTORY_SEPARATOR . $safeKey . '.json';
}
