<?php

namespace App\Support;

class LoggerHelper
{
    public static function info(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "({$timestamp}) {$message}" . PHP_EOL;
        
        // Log to stdout or logfile
        error_log($logMessage);
    }
}
