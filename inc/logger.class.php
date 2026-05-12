<?php

/**
 * Lightweight file logger for the datainjection plugin.
 *
 * Writes timestamped lines to <GLPI_LOG_DIR>/datainjection.log (typically
 * /var/log/glpi/datainjection.log). Rotates when the current file exceeds
 * MAX_SIZE_BYTES and retains the last RETAIN_FILES previous segments
 * (datainjection.log.1, .2, …). No external dependency, no daemon.
 *
 * Use:
 *     PluginDatainjectionLogger::info("Model created", ['id' => $id]);
 *     PluginDatainjectionLogger::error("Tab load failed", ['itemtype' => $itemtype]);
 *     PluginDatainjectionLogger::exception($e, "context label");
 */
class PluginDatainjectionLogger
{
    private const LOG_FILE        = 'datainjection.log';
    private const MAX_SIZE_BYTES  = 5 * 1024 * 1024; // 5 MB
    private const RETAIN_FILES    = 3;

    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('WARN', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
        // Mirror to GLPI's main log so admins see a breadcrumb in the UI.
        try {
            if (class_exists('Toolbox')) {
                Toolbox::logError("Datainjection: {$message}");
            }
        } catch (\Throwable $e) {
            // Best effort.
        }
    }

    /**
     * Log a Throwable with file:line and a compact stack trace. Useful in
     * the catch-all wrappers around plugin entry points.
     */
    public static function exception(\Throwable $e, string $message = ''): void
    {
        $context = [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'where'     => $e->getFile() . ':' . $e->getLine(),
            'trace'     => self::compactTrace($e->getTrace()),
        ];
        self::error($message !== '' ? $message : $e->getMessage(), $context);
    }

    private static function log(string $level, string $message, array $context): void
    {
        $ts     = date('Y-m-d H:i:s');
        $userId = 0;
        try {
            if (class_exists('Session')) {
                $userId = (int) (Session::getLoginUserID() ?: 0);
            }
        } catch (\Throwable $e) {
            // Cron / pre-session paths.
        }

        $ctxStr = '';
        if ($context !== []) {
            $encoded = @json_encode(
                $context,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
            );
            if (is_string($encoded)) {
                $ctxStr = ' | ' . $encoded;
            }
        }

        $line = "[{$ts}] [{$level}] [user:{$userId}] {$message}{$ctxStr}";

        $file    = self::getLogFile();
        $written = false;
        if ($file !== null) {
            self::ensureRotation($file);
            $bytes = @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
            $written = ($bytes !== false && $bytes > 0);
        }

        // Always mirror to PHP's error_log so messages are never lost — even
        // if GLPI_LOG_DIR is undefined, missing, or unwritable by the web
        // user. Apache/php-fpm route this to /var/log/php-fpm/error.log,
        // /var/log/apache2/error.log, or wherever error_log() points.
        if (!$written) {
            @error_log('[datainjection] ' . $line);
        }
    }

    private static function getLogFile(): ?string
    {
        $dir = defined('GLPI_LOG_DIR') ? GLPI_LOG_DIR : null;
        if ($dir === null || !is_string($dir) || $dir === '') {
            return null;
        }
        $dir = rtrim($dir, '/');
        if (!is_dir($dir) || !is_writable($dir)) {
            return null;
        }
        return $dir . '/' . self::LOG_FILE;
    }

    /**
     * Size-based rotation. Cheap to call: stat()s the file, no-ops below
     * the threshold, otherwise shifts segments and renames the live file.
     */
    private static function ensureRotation(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        $size = @filesize($file);
        if ($size === false || $size < self::MAX_SIZE_BYTES) {
            return;
        }

        // Drop the oldest segment first, then shift .{N-1} → .{N}, …, file → .1
        $oldest = $file . '.' . self::RETAIN_FILES;
        if (is_file($oldest)) {
            @unlink($oldest);
        }
        for ($i = self::RETAIN_FILES - 1; $i >= 1; $i--) {
            $src = $file . '.' . $i;
            if (is_file($src)) {
                @rename($src, $file . '.' . ($i + 1));
            }
        }
        @rename($file, $file . '.1');
    }

    /**
     * Reduce a backtrace to a list of short call-site strings.
     *
     * @param array $trace as returned by Throwable::getTrace()
     * @return array<int, string>
     */
    private static function compactTrace(array $trace, int $limit = 10): array
    {
        $out = [];
        foreach ($trace as $frame) {
            $where = ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?');
            $call  = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $out[] = trim($where . ' ' . $call);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }
}
