<?php
    // Set timeout limit to 300 seconds
    set_time_limit(300);

    // Include functions.php
    include 'functions.php';

    app_log('info', 'Cron job started', ['event' => 'cron_start']);

    // Refresh inverter data
    refresh_inverter_data();

    // Save weather data
    saveWeatherData();
    
    // Send daily report
    send_daily_report();

    // Purge logs older than 30 days — runs once a day at midnight (00:00–00:04 in plant timezone)
    if ($processStartDateTime->format('H') === '00' && (int)$processStartDateTime->format('i') < 5) {
        purge_old_logs(30);
        app_log('info', 'Old logs purged', ['event' => 'log_purge', 'retention_days' => 30]);
    }

    app_log('info', 'Cron job finished', ['event' => 'cron_end']);
?>
