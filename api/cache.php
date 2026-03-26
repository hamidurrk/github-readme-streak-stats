<?php

declare(strict_types=1);

/**
 * Simple file-based cache for GitHub contribution stats
 *
 * Caches stats for 24 hours to avoid repeated API calls
 */

// Default cache duration: 24 hours (in seconds)
define("CACHE_DURATION", 24 * 60 * 60);
define("CACHE_DIR", resolveCacheDir());

/**
 * Resolve a writable cache directory
 *
 * Priority:
 * 1. CACHE_DIR environment variable (if set)
 * 2. Project cache directory when writable
 * 3. System temp directory (for serverless/read-only deployments)
 *
 * @return string Absolute path to cache directory
 */
function resolveCacheDir(): string
{
    $envCacheDir = getenv("CACHE_DIR");
    if (is_string($envCacheDir) && $envCacheDir !== "") {
        return rtrim($envCacheDir, "/\\");
    }

    $projectCacheDir = __DIR__ . "/../cache";
    if (is_dir($projectCacheDir) && is_writable($projectCacheDir)) {
        return $projectCacheDir;
    }

    $projectParentDir = dirname($projectCacheDir);
    if (is_dir($projectParentDir) && is_writable($projectParentDir)) {
        return $projectCacheDir;
    }

    return rtrim(sys_get_temp_dir(), "/\\") . "/github-readme-streak-stats-cache";
}

/**
 * Generate a cache key for a user's request
 *
 * Uses structured JSON format to prevent hash collisions between different
 * user/options combinations that could produce the same concatenated string.
 *
 * @param string $user GitHub username
 * @param array $options Additional options that affect the stats (mode, exclude_days, starting_year)
 * @return string Cache key (filename-safe)
 */
function getCacheKey(string $user, array $options = []): string
{
    ksort($options);
    try {
        $keyData = json_encode(["user" => $user, "options" => $options], JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        // Fallback to simple concatenation if JSON encoding fails
        error_log("Cache key JSON encoding failed: " . $e->getMessage());
        $keyData = $user . serialize($options);
    }
    return hash("sha256", $keyData);
}

/**
 * Get the cache file path for a given key
 *
 * @param string $key Cache key
 * @return string Full path to cache file
 */
function getCacheFilePath(string $key): string
{
    return CACHE_DIR . "/" . $key . ".json";
}

/**
 * Ensure the cache directory exists
 *
 * @return bool True if directory exists or was created
 */
function ensureCacheDir(): bool
{
    if (is_dir(CACHE_DIR)) {
        return is_writable(CACHE_DIR);
    }

    $parentDir = dirname(CACHE_DIR);
    if (!is_dir($parentDir) || !is_writable($parentDir)) {
        error_log("Cache parent directory is not writable: " . $parentDir);
        return false;
    }

    // Suppress runtime warnings and rely on boolean return for graceful fallback.
    return @mkdir(CACHE_DIR, 0755, true) || is_dir(CACHE_DIR);
}

/**
 * Get cached stats if available and not expired
 *
 * @param string $user GitHub username
 * @param array $options Additional options
 * @param int $maxAge Maximum age in seconds (default: 24 hours)
 * @return array|null Cached stats array or null if not cached/expired
 */
function getCachedStats(string $user, array $options = [], int $maxAge = CACHE_DURATION): ?array
{
    $key = getCacheKey($user, $options);
    $filePath = getCacheFilePath($key);

    if (!file_exists($filePath)) {
        return null;
    }

    $mtime = filemtime($filePath);
    if ($mtime === false) {
        return null;
    }

    $fileAge = time() - $mtime;
    if ($fileAge > $maxAge) {
        unlink($filePath);
        return null;
    }

    $handle = fopen($filePath, "r");
    if ($handle === false) {
        return null;
    }

    if (!flock($handle, LOCK_SH)) {
        fclose($handle);
        return null;
    }

    $contents = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    if ($contents === false || $contents === "") {
        return null;
    }

    $data = json_decode($contents, true);
    if (!is_array($data)) {
        return null;
    }

    return $data;
}

/**
 * Save stats to cache
 *
 * @param string $user GitHub username
 * @param array $options Additional options
 * @param array $stats Stats array to cache
 * @return bool True if successfully cached
 */
function setCachedStats(string $user, array $options, array $stats): bool
{
    if (!ensureCacheDir()) {
        error_log("Failed to create cache directory: " . CACHE_DIR);
        return false;
    }

    $key = getCacheKey($user, $options);
    $filePath = getCacheFilePath($key);

    $data = json_encode($stats);
    if ($data === false) {
        error_log("Failed to encode stats to JSON for user: " . $user);
        return false;
    }

    $result = file_put_contents($filePath, $data, LOCK_EX);
    if ($result === false) {
        error_log("Failed to write cache file: " . $filePath);
        return false;
    }

    return true;
}

/**
 * Clear all expired cache files
 *
 * @param int $maxAge Maximum age in seconds
 * @return int Number of files deleted
 */
function clearExpiredCache(int $maxAge = CACHE_DURATION): int
{
    if (!is_dir(CACHE_DIR)) {
        return 0;
    }

    $deleted = 0;
    $files = glob(CACHE_DIR . "/*.json");

    if ($files === false) {
        return 0;
    }

    foreach ($files as $file) {
        $mtime = filemtime($file);
        if ($mtime === false) {
            continue;
        }
        $fileAge = time() - $mtime;
        if ($fileAge > $maxAge) {
            if (unlink($file)) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

/**
 * Clear cache for a specific user
 *
 * Note: This function only clears the cache for the user with empty/default options.
 * Cache entries with non-empty options (starting_year, mode, exclude_days) will NOT
 * be cleared. This is a limitation of the hash-based cache key system - we cannot
 * enumerate all possible option combinations without storing additional metadata.
 *
 * @param string $user GitHub username
 * @return bool True if cache was cleared (or didn't exist)
 */
function clearUserCache(string $user): bool
{
    if (!is_dir(CACHE_DIR)) {
        return true;
    }

    $key = getCacheKey($user, []);
    $filePath = getCacheFilePath($key);

    if (file_exists($filePath)) {
        return unlink($filePath);
    }

    return true;
}
