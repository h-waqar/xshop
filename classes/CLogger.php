<?php
namespace classes;

defined('ABSPATH') || exit;

// classes\CLogger.php

class CLogger
{
    private static string $logDir;
    private static ?string $currentFile = null;

    private static function init(): void
    {
        if (!isset(self::$logDir)) {
            self::$logDir = XSHOP_PLUGIN_DIR_PATH . 'logs/';

            if (!file_exists(self::$logDir)) {
                wp_mkdir_p(self::$logDir);
            }
        }
    }

    /**
     * Start a new log session (usually at process start).
     * Creates a unique log file for the session.
     */
    public static function startSession(string $prefix = 'log'): void
    {
        self::init();

        $timestamp = current_time('timestamp');
        $datePart  = date('Y-m-d_H-i-s', $timestamp);
        $uniq      = uniqid('', true); // prevents collisions if 2 processes same second

        self::$currentFile = self::$logDir . "{$prefix}_{$datePart}_{$uniq}.log";
    }

    /**
     * Write log entry to the current session file (or fallback per-second file).
     */
    public static function log(string $title, $data = null): string
    {
        self::init();

        // If no session started, fall back to per-second log file
        if (!self::$currentFile) {
            $timestamp = current_time('timestamp');
            $datePart  = date('Y-m-d_H-i-s', $timestamp);
            self::$currentFile = self::$logDir . "log_{$datePart}.log";
        }

        // WordPress local time
        $timestamp = current_time('timestamp');
        $humanTime = date('Y-m-d H:i:s', $timestamp);

        $content  = '[' . $humanTime . " LOCAL]\n";
        $content .= $title . "\n";
        $content .= str_repeat('-', 50) . "\n";

        if (is_array($data) || is_object($data)) {
            $content .= print_r($data, true);
        } else {
            $content .= (string) $data;
        }

        $content .= "\n\n";

        file_put_contents(self::$currentFile, $content, FILE_APPEND);

        return self::$currentFile;
    }
}
