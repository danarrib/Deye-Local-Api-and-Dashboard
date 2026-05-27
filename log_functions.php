<?php

    /**
     * Write a structured log entry to the database logs table.
     *
     * Usage:
     *   app_log('info',    'Inverter data collected', ['inverter' => '192.168.1.10', 'power_now' => 1200]);
     *   app_log('error',   'Failed to fetch inverter', ['inverter' => '192.168.1.10']);
     *   app_log('warning', 'Skipping — outside daylight hours');
     *
     * Levels: debug, info, warning, error
     */
    function app_log(string $level, string $message, array $context = []): void {
        global $db;
        if (!$db) return;

        $context_json = !empty($context) ? json_encode($context) : null;
        @pg_query_params($db,
            "INSERT INTO logs (level, message, context) VALUES ($1, $2, $3)",
            [$level, $message, $context_json]
        );
    }

?>
