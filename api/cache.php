<?php

declare(strict_types=1);

/**
 * Simple file-based cache for GitHub contribution stats
 *
 * Caches stats for 24 hours to avoid repeated API calls.
 * Made safer for serverless environments like Vercel:
 * - prefers /tmp
 * - never prints mkdir/file warnings into image output
 * - gracefully skips caching if filesystem is unavailable
 */

// Default cache duration: 24 hours (in seconds)
define("CACHE_DURATION", 24 * 60 * 60);

/**
 * Get the best writable cache directory for the current environment.
 *
 * @return string
 */
function getCacheDir(): string
{
    $candidates = [
        "/tmp/github-readme-streak-stats-cache",
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "github-readme-streak-stats-cache",
        __DIR__ . "/cache",
    ];

    foreach ($candidates as $dir) {
        $parent = dirname($dir);

        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }

        if (is_dir($parent) && is_writable($parent)) {
            return $dir;
        }
    }

    // Final fallback; caching will just fail gracefully if this is not writable
    return "/tmp/github-readme-streak-stats-cache";
}

define("CACHE_DIR", getCacheDir());

/**
 * Generate a cache key for a user's request
 *
 * Uses structured JSON format to prevent hash collisions between different
 * user/options combinations that could produce the same concatenated string.
 *
 * @param string $user GitHub username
 * @param array $options Additional options that affect the stats
 * @return string Cache key (filename-safe)
 */
function getCacheKey(string $user, array $options = []): string
{
    ksort($options);

    try {
        $keyData = json_encode(
            ["user" => $user, "options" => $options],
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
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
    return rtrim(CACHE_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $key . ".json";
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

    $parent = dirname(CACHE_DIR);

    if (!is_dir($parent) || !is_writable($parent)) {
        error_log("Cache parent directory is not writable: " . $parent);
        return false;
    }

    // Suppress PHP warnings from leaking into image output
    $created = @mkdir(CACHE_DIR, 0755, true);

    if ($created === false && !is_dir(CACHE_DIR)) {
        error_log("Failed to create cache directory: " . CACHE_DIR);
        return false;
    }

    return is_writable(CACHE_DIR);
}

/**
 * Get cached stats if available and not expired
 *
 * @param string $user GitHub username
 * @param array $options Additional options
 * @param int $maxAge Maximum age in seconds
 * @return array|null Cached stats array or null if not cached/expired
 */
function getCachedStats(string $user, array $options = [], int $maxAge = CACHE_DURATION): ?array
{
    $key = getCacheKey($user, $options);
    $filePath = getCacheFilePath($key);

    if (!is_file($filePath)) {
        return null;
    }

    $mtime = @filemtime($filePath);
    if ($mtime === false) {
        return null;
    }

    $fileAge = time() - $mtime;
    if ($fileAge > $maxAge) {
        @unlink($filePath);
        return null;
    }

    $handle = @fopen($filePath, "r");
    if ($handle === false) {
        return null;
    }

    if (!@flock($handle, LOCK_SH)) {
        fclose($handle);
        return null;
    }

    $contents = stream_get_contents($handle);

    @flock($handle, LOCK_UN);
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
        return false;
    }

    $key = getCacheKey($user, $options);
    $filePath = getCacheFilePath($key);

    $data = json_encode($stats);
    if ($data === false) {
        error_log("Failed to encode stats to JSON for user: " . $user);
        return false;
    }

    $result = @file_put_contents($filePath, $data, LOCK_EX);
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
    $files = @glob(rtrim(CACHE_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "*.json");

    if ($files === false) {
        return 0;
    }

    foreach ($files as $file) {
        $mtime = @filemtime($file);
        if ($mtime === false) {
            continue;
        }

        $fileAge = time() - $mtime;
        if ($fileAge > $maxAge) {
            if (@unlink($file)) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

/**
 * Clear cache for a specific user
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
        return @unlink($filePath);
    }

    return true;
}
