<?php
    // Set the start date and time to now
    $processStartDateTime = new DateTime();

    // Set timeout limit to 120 seconds
    set_time_limit(120);

    // Include functions.php
    include 'functions.php';

    // Refresh inverter data
    refresh_inverter_data();

    // Send daily report
    send_daily_report();





?>