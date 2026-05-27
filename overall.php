<?php
    include 'functions.php';

    header('Content-Type: application/json');

    $total_power_now = 0;
    $total_power_today = 0;
    $total_power_total = 0;
    $peak_power_now = 0;
    $latest_inverter_data = array();
    $is_historical = false;

    // Handle optional ?date=YYYY-MM-DD parameter
    $requested_date = isset($_GET['date']) ? trim($_GET['date']) : null;
    if ($requested_date !== null) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested_date)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD.']);
            exit;
        }
        $parsed = DateTime::createFromFormat('Y-m-d', $requested_date, new DateTimeZone($powerplant_timezone));
        if (!$parsed || $parsed->format('Y-m-d') !== $requested_date) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date.']);
            exit;
        }
        $today = new DateTime('today', new DateTimeZone($powerplant_timezone));
        if ($parsed > $today) {
            http_response_code(400);
            echo json_encode(['error' => 'Date cannot be in the future.']);
            exit;
        }
        if ($parsed->format('Y-m-d') < $today->format('Y-m-d')) {
            $is_historical = true;
        }
    }

    $date = new DateTime(null, new DateTimeZone($powerplant_timezone));
    $strDate = $date->format('Y-m-d\TH:i:s P');

    if ($is_historical) {
        $reference_date = clone $parsed;
        $reference_date->setTime(0, 0, 0);
    } else {
        $reference_date = new DateTime(null, new DateTimeZone($powerplant_timezone));
        $reference_date->setTime(0, 0, 0);
        $sunrise_today = get_sunrise($date);
        $sunrise_date = clone $sunrise_today;
        $sunrise_date->setTime(0, 0, 0);
        if ($reference_date == $sunrise_date && $date < $sunrise_today) {
            $reference_date->sub(new DateInterval('P1D'));
        }
    }

    $sunrise = get_sunrise($reference_date);
    $sunset = get_sunset($reference_date);
    $sunrise_utc = (clone $sunrise)->setTimezone(new DateTimeZone('UTC'));
    $sunset_utc = (clone $sunset)->setTimezone(new DateTimeZone('UTC'));

    $all_data = get_today_latest_data($is_historical ? $reference_date->format('Y-m-d') : null);

    foreach ($all_data as $row) {
        $created_at_utc = new DateTime($row['created_at'], new DateTimeZone('UTC'));
        $row['created_at_local'] = $created_at_utc->setTimezone(new DateTimeZone($powerplant_timezone))->format('Y-m-d\TH:i:s P');
        $row['created_at'] = $created_at_utc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $latest_inverter_data[] = $row;
        $total_power_today += $row['power_today'];
        $total_power_total += $row['power_total'];

        if (!$is_historical) {
            $now = new DateTime(null, new DateTimeZone('UTC'));
            $diff = $now->getTimestamp() - $created_at_utc->getTimestamp();
            if ($diff <= 300) {
                $total_power_now += $row['power_now'];
            }
        }
    }

    $total_power_today = round($total_power_today, 1);
    $total_power_total = round($total_power_total, 1);

    $detailed_inverter_data = get_detailed_inverter_todays_data($sunrise, $sunset, $reference_date);
    $detailed_powerplant_data = get_detailed_powerplant_todays_data($sunrise, $sunset, $reference_date);

    if ($is_historical) {
        foreach ($detailed_powerplant_data as $row) {
            if ($row['total_power_now'] !== null && floatval($row['total_power_now']) > $peak_power_now) {
                $peak_power_now = floatval($row['total_power_now']);
            }
        }
        $latest_weather_data = get_weather_for_date($sunrise, $sunset);
        if ($latest_weather_data !== null) {
            $latest_weather_data['icon'] = returnWeatherIcon($latest_weather_data['condition']);
            $latest_weather_data['temperature'] = (int) $latest_weather_data['temperature'];
        }
    } else {
        $latest_weather_data = fetchLatestWeatherData();
    }

    $json = array(
        "powerplant_name" => $powerplant_name,
        "powerplant_timezone" => $powerplant_timezone,
        "total_power_now" => $total_power_now,
        "total_power_today" => $total_power_today,
        "total_power_total" => $total_power_total,
        "peak_power_now" => $peak_power_now,
        "is_historical" => $is_historical,
        "timestamp" => $strDate,
        "reference_date" => $reference_date->format('Y-m-d\TH:i:s P'),
        "sunrise" => $sunrise_utc->format('Y-m-d\TH:i:s\Z'),
        "sunset" => $sunset_utc->format('Y-m-d\TH:i:s\Z'),
        "latest_inverter_data" => $latest_inverter_data,
        "detailed_inverter_data" => $detailed_inverter_data,
        "detailed_powerplant_data" => $detailed_powerplant_data,
        "latest_weather_data" => $latest_weather_data,
    );

    echo json_encode($json);
?>
