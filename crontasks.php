<?php
    // Set timeout limit to 300 seconds
    set_time_limit(300);

    // Include functions.php
    include 'functions.php';

    // Refresh inverter data
    refresh_inverter_data();

    // Save weather data
    saveWeatherData();
    
    // Send daily report
    send_daily_report();





?>
