<?php
    // Include functions.php
    include 'functions.php';

    // Set the response header to be JSON
    header('Content-Type: application/json');

    // Create variables to store the total power being generated now, today and total
    $total_power_now = 0;
    $total_power_today = 0;
    $total_power_total = 0;
    $latest_inverter_data = array();

    // Get latest data from the inverters
    $all_data = get_today_latest_data();

    // Iterate over the list of inverters
    foreach ($all_data as $data) {
        $created_at_utc = new DateTime($data['created_at'], new DateTimeZone('UTC'));
        // Add local timezone date to the data array
        $data['created_at_local'] = $created_at_utc->setTimezone(new DateTimeZone($powerplant_timezone))->format('Y-m-d\TH:i:s P');
        $data['created_at'] = $created_at_utc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        $latest_inverter_data[] = $data;

        $total_power_today += $data['power_today'];
        $total_power_total += $data['power_total'];

        // Check if the "created_at" date is less than 5 minutes ago, if not, skip it
        $now = new DateTime(null, new DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $created_at_utc->getTimestamp();
        if ($diff > 300) {
            continue;
        }

        $total_power_now += $data['power_now'];
    }

    $date = new DateTime(null, new DateTimeZone($powerplant_timezone));
    $strDate = $date->format('Y-m-d\TH:i:s P');

    $sunrise = get_sunrise($date);
    $sunset = get_sunset($date);

    // total_power_today and total_power_total has only one decimal digit, but still numbers
    $total_power_today = round($total_power_today, 1);
    $total_power_total = round($total_power_total, 1);

    // Create a new date object for reference date with the current date if after sunrise, or yesterday if before sunrise, without the time part
    $reference_date = new DateTime(null, new DateTimeZone($powerplant_timezone));
    $reference_date->setTime(0, 0, 0);

    // Clone sunrise date to compare with the current date
    $sunrise_date = clone $sunrise;
    $sunrise_date->setTime(0, 0, 0);

    if ($reference_date == $sunrise_date && $date < $sunrise) {
        $reference_date->sub(new DateInterval('P1D'));
    }

    $sunrise = get_sunrise($reference_date);
    $sunset = get_sunset($reference_date);

    // Get Detailed data to build the chart
    $detailed_inverter_data = get_detailed_inverter_todays_data($sunrise, $sunset, $reference_date);
    $detailed_powerplant_data = get_detailed_powerplant_todays_data($sunrise, $sunset, $reference_date);

    // Build a JSON object with the total power being generated now, today and total
    $json = array(
        "powerplant_name" => $powerplant_name,
        "powerplant_timezone" => $powerplant_timezone,
        "total_power_now" => $total_power_now,
        "total_power_today" => $total_power_today,
        "total_power_total" => $total_power_total,
        "timestamp" => $strDate,
        "reference_date" => $reference_date->format('Y-m-d\TH:i:s P'),
        "sunrise" => $sunrise->format('Y-m-d\TH:i:s P'),
        "sunset" => $sunset->format('Y-m-d\TH:i:s P'),
        "latest_inverter_data" => $latest_inverter_data,
        "detailed_inverter_data" => $detailed_inverter_data,
        "detailed_powerplant_data" => $detailed_powerplant_data,
    );

    // Return the JSON object
    echo json_encode($json);

?>