<?php
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL ^ E_DEPRECATED);

    // Load database configuration
    require_once __DIR__ . '/config_loader.php';

    $db_config = load_db_config();
    if ($db_config) {
        $db_host = $db_config['host'];
        $db_port = $db_config['port'];
        $db_name = $db_config['dbname'];
        $db_user = $db_config['user'];
        $db_pass = $db_config['password'];
    } else {
        $db_host = getenv('DB_HOST') ?: 'localhost';
        $db_user = getenv('DB_USER') ?: 'deye_user';
        $db_pass = getenv('DB_PASS') ?: 'deye123@';
        $db_name = getenv('DB_NAME') ?: 'deye_data';
        $db_port = '5432';
    }

    include_once 'db_functions.php';
    include_once 'weather_functions.php';
    include_once 'telegram_functions.php';
    include_once 'log_functions.php';
    include_once 'solarman_functions.php';

    $db = get_db_connection();

    setup_db();

    // Load settings from database, with hardcoded fallbacks
    $powerplant_settings = load_powerplant_settings();
    $powerplant_timezone = $powerplant_settings['timezone'] ?? 'America/Sao_Paulo';
    $powerplant_name = $powerplant_settings['name'] ?? 'My Power Plant';
    $powerplant_latitude = $powerplant_settings['latitude'] ?? -23.5;
    $powerplant_longitude = $powerplant_settings['longitude'] ?? -46.6;
    $telegram_token = $powerplant_settings['telegram_token'] ?? '';
    $telegram_chatId = $powerplant_settings['telegram_chat_id'] ?? '';
    $powerplant_language = $powerplant_settings['language'] ?? 'en';

    $_php_lang_file = __DIR__ . '/lang/' . $powerplant_language . '.json';
    if (!file_exists($_php_lang_file)) {
        $_php_lang_file = __DIR__ . '/lang/en.json';
    }
    $_php_translations = json_decode(file_get_contents($_php_lang_file), true) ?? [];

    function php_t($key, $vars = []) {
        global $_php_translations;
        $str = $_php_translations[$key] ?? $key;
        foreach ($vars as $k => $v) {
            $str = str_replace('{' . $k . '}', $v, $str);
        }
        return $str;
    }

    $inverter_list = load_inverter_list();

    $processStartDateTime = new DateTime(null, new DateTimeZone($powerplant_timezone));

    function get_inverter_data($ipaddress, $username, $password) {
        // Try to fetch the contents of http://{ipaddress}/status.html, using the provided username and password (simple http authentication)
        $url = "http://$ipaddress/status.html";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Set the timeout to 15 seconds
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $output = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        // If the request was successful, try to parse the output and get the values of the variables: 
        // webdata_sn (Inverter Serial Number), webdata_now_p (Power being generated now), webdata_today_e (Power generated today), webdata_total_e (Total power generated), 
        // cover_mid (Device serial number), cover_ver (Device firmware version)
        if ($info['http_code'] == 200) {
            $inverter_sn = preg_match('/webdata_sn = "(.*?)"/', $output, $matches) ? $matches[1] : "N/A";
            $power_now = preg_match('/webdata_now_p = "(.*?)"/', $output, $matches) ? $matches[1] : "N/A";
            $power_today = preg_match('/webdata_today_e = "(.*?)"/', $output, $matches) ? $matches[1] : "N/A";
            $power_total = preg_match('/webdata_total_e = "(.*?)"/', $output, $matches) ? $matches[1] : "N/A";
            $device_sn = preg_match('/cover_mid = "(.*?)"/', $output, $matches) ? $matches[1] : "N/A";
            $device_ver = preg_match('/cover_ver = "(.*?)"/', $output, $matches) ? $matches[1] : "N/A";

            // Get current UTC date and time
            $date = new DateTime(null, new DateTimeZone('UTC'));

            // Trim the value of the inverter serial number
            $inverter_sn = trim($inverter_sn);

            // power_now, power_today and power_total are numbers, so convert them to float if they are not "N/A", otherwise set to 0
            $power_now = $power_now != "N/A" ? floatval($power_now) : 0;
            $power_today = $power_today != "N/A" ? floatval($power_today) : 0;
            $power_total = $power_total != "N/A" ? floatval($power_total) : 0;

            // Build a JSON object with the values of the variables
            $json = array(
                "inverter_sn" => $inverter_sn,
                "power_now" => $power_now,
                "energy_today" => $power_today,
                "energy_total" => $power_total,
                "device_sn" => $device_sn,
                "device_ver" => $device_ver,
                "timestamp" => $date->format('Y-m-d\TH:i:s\Z'),
                "ipaddress" => $ipaddress,
            );

            // Return the JSON object
            return $json;
        } else {
            // If the request was not successful, return an error message
            $json = array(
                "error" => "Could not fetch the status page.",
                "ipaddress" => $ipaddress,
                "status" => $info['http_code'],
                "timestamp" => date('Y-m-d\TH:i:s\Z')
            );
            return $json;
        }
    }

    function restart_inverter($ipaddress, $username, $password) {
        // To restart the inverter, POST to the URL http://{ipaddress}/success.html with the username and password (simple http authentication)
        // The payload is a FORM data with the field "HF_PROCESS_CMD" and value "RESTART"
    
        $url = "http://$ipaddress/success.html";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "HF_PROCESS_CMD=RESTART");

        // Set the timeout to 5 seconds
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $output = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        return $info['http_code'] == 200;
    }

    function refresh_inverter_data() {
        if (is_after_sunset_or_before_sunrise()) {
            return;
        }

        global $inverter_list;
        global $processStartDateTime;
        $order = 0;

        foreach ($inverter_list as $inverter) {
            $order++;
            $data = null;

            // Try SolarmanV5 first when enabled for this inverter
            if (!empty($inverter['solarman_enabled']) && $inverter['solarman_enabled'] === 't') {
                $sm = solarman_poll($inverter['ipaddress'], intval($inverter['device_sn']));
                if (!isset($sm['error'])) {
                    $data = $sm;
                    $data['device_sn'] = $inverter['device_sn'];
                    $data['timestamp'] = (new DateTime(null, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
                } else {
                    app_log('warning', 'SolarmanV5 poll failed, falling back to HTTP', [
                        'event'    => 'solarman_fallback',
                        'inverter' => $inverter['ipaddress'],
                        'error'    => $sm['error'],
                    ]);
                }
            }

            // Fall back to HTTP polling if SolarmanV5 is disabled or failed
            if ($data === null) {
                $data = get_inverter_data($inverter['ipaddress'], $inverter['username'], $inverter['password']);

                if (array_key_exists('error', $data)) {
                    sleep(10);
                    $data = get_inverter_data($inverter['ipaddress'], $inverter['username'], $inverter['password']);
                }

                if (array_key_exists('error', $data)) {
                    app_log('error', 'Failed to fetch inverter data', [
                        'event'    => 'inverter_fetch_error',
                        'inverter' => $inverter['ipaddress'],
                        'name'     => $inverter['friendly_name'],
                    ]);
                    continue;
                }
            }

            app_log('info', 'Inverter data collected', [
                'event'        => 'inverter_fetch',
                'inverter'     => $inverter['ipaddress'],
                'name'         => $inverter['friendly_name'],
                'power_now'    => $data['power_now'],
                'energy_today' => $data['energy_today'],
            ]);

            $data['friendly_name'] = $inverter['friendly_name'];
            $data['ip_address']    = $inverter['ipaddress'];
            $data['order']         = $order;

            resolve_pending_inverter($data['device_sn'], $inverter['ipaddress']);
            save_inverter_data($data);
        }

        fix_incomplete_data($processStartDateTime);
    }

    function send_daily_report($force = false) {
        global $powerplant_timezone;
        global $processStartDateTime;
        global $telegram_token, $telegram_chatId;

        if (empty($telegram_token) || empty($telegram_chatId)) {
            return;
        }

        // Get the sunset time for today
        $sunset = get_todays_sunset();

        // If the current time is between sunset and 5 minutes after sunset, send a message to the telegram group
        if (($processStartDateTime->getTimestamp() >= $sunset->getTimestamp() 
            && $processStartDateTime->getTimestamp() <= $sunset->getTimestamp() + 300) || $force === true) {
            // Get latest data from the inverters
            $all_data = get_today_latest_data();

            // Create variables to store the total power being generated now, today and total
            $total_power_now = 0;
            $total_energy_today = 0;
            $total_energy_total = 0;

            // Iterate over the list of inverters
            foreach ($all_data as $data) {
                $total_power_now += $data['power_now'];
                $total_energy_today += $data['energy_today'];
                $total_energy_total += $data['energy_total'];
            }

            $messageText = php_t('tg_daily_energy', ['kwh' => number_format($total_energy_today, 1)]);

            $top_daily_energy = getDailyTopEnergy($processStartDateTime);

            // If top_daily_energy is greater than total_energy_today, add a message to the telegram message
            if (($top_daily_energy > 0 && $total_energy_today >= $top_daily_energy) || $force === true) {
                $messageText .= "\n" . php_t('tg_daily_record', ['kwh' => number_format($top_daily_energy, 1)]);
            }

            // If today is the last day of the month
            if($processStartDateTime->format('d') == $processStartDateTime->format('t') || $force === true) {
                $top_monthly_energy = getMonthlyTopEnergy($processStartDateTime);
                $energy_this_month = getMonthEnergy($processStartDateTime);

                $messageText .= "\n\n" . php_t('tg_monthly_energy', ['kwh' => number_format($energy_this_month, 1)]);

                // If top_monthly_energy is greater than energy_this_month, add a message to the telegram message
                if ($top_monthly_energy > 0 && $energy_this_month >= $top_monthly_energy) {
                    $messageText .= "\n" . php_t('tg_monthly_record', ['kwh' => number_format($top_monthly_energy, 1)]);
                }
            }

            app_log('info', 'Daily report sent', [
                'event'        => 'daily_report',
                'total_kwh'    => round($total_energy_today, 2),
            ]);
            send_telegram_daily_chart($messageText);
        }
    }

    function is_after_sunset_or_before_sunrise() {
        global $processStartDateTime;

        $sunset = get_todays_sunset();
        $sunrise = get_todays_sunrise();

        // Check if the current time is after sunset or before sunrise, with a 10 minute buffer
        if ($processStartDateTime->getTimestamp() >= $sunset->getTimestamp() + 600 || $processStartDateTime->getTimestamp() <= $sunrise->getTimestamp() - 600) {
            return true;
        }

        return false;
    }

    function get_todays_sunset() {
        global $powerplant_timezone;

        // Get the current date and time
        $date = new DateTime(null, new DateTimeZone($powerplant_timezone));

        // Get the sunset time for today
        return get_sunset($date);
    }

    function get_sunset($date) {
        global $powerplant_latitude, $powerplant_longitude, $powerplant_timezone;

        // Get the sunset time for date
        $suninfo = date_sun_info($date->getTimestamp(), $powerplant_latitude, $powerplant_longitude);
        $sunset = new DateTime("@{$suninfo['sunset']}", new DateTimeZone($powerplant_timezone));

        // Set the sunset time to the powerplant timezone
        $sunset->setTimezone(new DateTimeZone($powerplant_timezone));

        // Return the sunset date time object
        return $sunset;
    }

    function get_todays_sunrise() {
        global $powerplant_timezone;

        // Get the current date and time
        $date = new DateTime(null, new DateTimeZone($powerplant_timezone));

        // Get the sunset time for today
        return get_sunrise($date);
    }

    function get_sunrise($date) {
        global $powerplant_latitude, $powerplant_longitude, $powerplant_timezone;

        // Get the sunset time date
        $suninfo = date_sun_info($date->getTimestamp(), $powerplant_latitude, $powerplant_longitude);
        $sunrise = new DateTime("@{$suninfo['sunrise']}", new DateTimeZone($powerplant_timezone));

        // Set the sunset time to the powerplant timezone
        $sunrise->setTimezone(new DateTimeZone($powerplant_timezone));

        // Return the sunset date time object
        return $sunrise;
    }

    function loadWeatherIcon($condition, $target_size) {
        $c = strtolower($condition ?? '');
        if ($c === 'clear sky' || $c === 'mainly clear')        $name = 'clear';
        elseif ($c === 'partly cloudy')                         $name = 'partly_cloudy';
        elseif ($c === 'overcast')                              $name = 'overcast';
        elseif (strpos($c, 'fog') !== false)                    $name = 'fog';
        elseif (strpos($c, 'thunder') !== false || strpos($c, 'storm') !== false) $name = 'storm';
        elseif (strpos($c, 'snow') !== false)                   $name = 'snow';
        elseif (strpos($c, 'drizzle') !== false || strpos($c, 'rain') !== false || strpos($c, 'shower') !== false) $name = 'rain';
        else                                                    $name = 'unknown';

        $path = __DIR__ . '/img/icon_' . $name . '.png';
        if (!file_exists($path)) return false;

        $src = imagecreatefrompng($path);
        if (!$src) return false;

        $dst = imagecreatetruecolor($target_size, $target_size);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagealphablending($dst, false);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $target_size, $target_size, imagesx($src), imagesy($src));
        imagedestroy($src);

        return $dst;
    }

    function generateTodaysChart() {
        global $powerplant_name, $powerplant_timezone, $powerplant_language;
        $date = new DateTime(null, new DateTimeZone($powerplant_timezone));
        $strDate = $date->format('Y-m-d\TH:i:s P');

        $sunrise = get_sunrise($date);
        $sunset = get_sunset($date);

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
        $detailed_powerplant_data = get_detailed_powerplant_todays_data($sunrise, $sunset, $reference_date);
        $weather_changes = get_weather_changes_for_date($sunrise, $sunset);

        $total_energy_today = 0;
        $peak_power_today = 0;
        $peak_power_time = '';
        $rad_temps = [];

        foreach ($detailed_powerplant_data as $point)
        {
            if ($point['total_power_now'] > $peak_power_today) {
                $peak_power_today = $point['total_power_now'];
                $peak_power_time = (new DateTime($point['time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($powerplant_timezone))->format('H:i');
            }
            if ($point['avg_radiator_temp'] !== null && $point['avg_radiator_temp'] !== '') {
                $rad_temps[] = (int)$point['avg_radiator_temp'];
            }
        }

        $avg_radiator_temp  = count($rad_temps) > 0 ? round(array_sum($rad_temps) / count($rad_temps)) : null;
        $peak_radiator_temp = count($rad_temps) > 0 ? max($rad_temps) : null;

        // Get latest data from the inverters
        $latest_data = get_today_latest_data();

        // Iterate over the list of inverters
        foreach ($latest_data as $data) {
            $total_energy_today += $data['energy_today'];
        }

        // Start building the chart
        $canvas_resolution = 'fhd'; // Options: 'sd', 'hd', 'fhd', 'qhd', '4k', '8k'

        if ($canvas_resolution == 'sd') {
            $canvas_width = 640;
            $canvas_height = 360;
        } elseif ($canvas_resolution == 'hd') {
            $canvas_width = 1280;
            $canvas_height = 720;
        } elseif ($canvas_resolution == 'fhd') {
            $canvas_width = 1920;
            $canvas_height = 1080;
        } elseif ($canvas_resolution == 'qhd') {
            $canvas_width = 2560;
            $canvas_height = 1440;
        } elseif ($canvas_resolution == '4k') {
            $canvas_width = 3840;
            $canvas_height = 2160;
        } elseif ($canvas_resolution == '8k') {
            $canvas_width = 7680;
            $canvas_height = 4320;
        } else {
            // Default to HD
            $canvas_width = 1280;
            $canvas_height = 720;
        }

        $margins = $canvas_width * 0.065;
        $labels_font_size = $canvas_height * 0.017;
        $title_font_size = $canvas_height * 0.025;
        $summary_font_size = $canvas_height * 0.015;
        $font_map = [
            'ru'    => 'NotoSans.ttf',
            'hi'    => 'NotoSans.ttf',
            'zh-CN' => 'NotoSansSC.ttf',
            'zh-TW' => 'NotoSansTC.ttf',
            'ja'    => 'NotoSansJP.ttf',
            'ko'    => 'NotoSansKR.ttf',
            'he'    => 'NotoSansHebrew.ttf',
            'ar'    => 'NotoSansArabic.ttf',
        ];
        $font_name = __DIR__ . '/assets/' . ($font_map[$powerplant_language] ?? 'UbuntuMono-Regular.ttf');

        $numberOfPoints = count($detailed_powerplant_data);
        $chartWidth = $canvas_width - $margins * 2;
        $chartHeight = $canvas_height - $margins * 2;
        $maxValue = max(array_column($detailed_powerplant_data, 'total_power_now'));
        $maxValue = ceil($maxValue / 100) * 100; // Round up to the nearest 100
        $minValue = 0; // We want the chart to start at 0
        $ratioX = $chartWidth / ($numberOfPoints - 1);
        $ratioY = $chartHeight / ($maxValue - $minValue);
        $tempMinValue = 0;
        $tempMaxValue = 90;
        $ratioYTemp = $chartHeight / ($tempMaxValue - $tempMinValue);

        // Create the image
        $img = imagecreatetruecolor($canvas_width, $canvas_height);

        // Set anti-aliasing
        imageantialias($img, false);

        // Create some colors
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        $red = imagecolorallocate($img, 255, 0, 0);
        $grey = imagecolorallocate($img, 200, 200, 200);
        $blue = imagecolorallocate($img, 0, 0, 255);
        //$light_blue = imagecolorallocate($img, 150, 200, 255);
        $light_blue = imagecolorallocatealpha($img, 0, 100, 255, 75);
        $light_gray = imagecolorallocate($img, 240, 240, 240);

        // Fill the background with white
        imagefilledrectangle($img, 0, 0, $canvas_width, $canvas_height, $white);

        // Draw the labels (times) on the x-axis
        for ($i = 0; $i < $numberOfPoints; $i += max(1, intval($numberOfPoints / 20))) {
            $x = $margins + $i * $ratioX;
            $y = $canvas_height - $margins + ($canvas_height * 0.027);
            $timeLabel = (new DateTime($detailed_powerplant_data[$i]['time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($powerplant_timezone))->format('H:i');
            imagettftext($img, $labels_font_size, 0, $x - ($canvas_width * 0.008), $y, $black, $font_name, $timeLabel);
            imageline($img, $x, $canvas_height - $margins, $x, $margins, $grey);
        }

        // Draw the labels (power) on the left y-axis
        $maxPowerLabel = ceil($maxValue / 100) * 100; // Round up to the nearest 100
        $numberOfYLabels = 5;
        $step = ($maxPowerLabel - $minValue) / $numberOfYLabels;
        for ($i = 0; $i <= $numberOfYLabels; $i++) {
            $powerValue = $minValue + $i * $step;
            $y = $canvas_height - $margins - ($powerValue - $minValue) * $ratioY;
            $label = number_format(round($powerValue / 1000, 1),1) . "kW";
            imagettftext($img, $labels_font_size, 0, $margins - ($canvas_width * 0.039), $y + 5, $black, $font_name, $label);
            imageline($img, $margins, $y, $canvas_width - $margins, $y, $grey);
        }

        // Draw temperature labels on the right y-axis
        $numberOfTempLabels = 6;
        $tempStep = ($tempMaxValue - $tempMinValue) / $numberOfTempLabels;
        for ($i = 0; $i <= $numberOfTempLabels; $i++) {
            $tempValue = $tempMinValue + $i * $tempStep;
            $y = $canvas_height - $margins - ($tempValue - $tempMinValue) * $ratioYTemp;
            $label = round($tempValue) . "°C";
            imagettftext($img, $labels_font_size, 0, $canvas_width - $margins + ($canvas_width * 0.007), $y + 5, $red, $font_name, $label);
        }

        $line_thickness = max(2, (int)($canvas_width * 0.002));

        // Draw the chart
        for ($i = 0; $i < $numberOfPoints - 1; $i++) {
            // If the next point is NULL, skip it
            if ($detailed_powerplant_data[$i]['total_power_now'] === null || $detailed_powerplant_data[$i + 1]['total_power_now'] === null) {
                continue;
            }

            $x1 = $margins + $i * $ratioX;
            $y1 = $canvas_height - $margins - ($detailed_powerplant_data[$i]['total_power_now'] - $minValue) * $ratioY;
            $x2 = $margins + ($i + 1) * $ratioX;
            $y2 = $canvas_height - $margins - ($detailed_powerplant_data[$i + 1]['total_power_now'] - $minValue) * $ratioY;

            // Round the coordinates to avoid anti-aliasing issues
            $x1 = round($x1)+1;
            $y1 = round($y1);
            $x2 = round($x2);
            $y2 = round($y2);

            // Draw the fill polygon at thickness 1 to avoid compounding alpha on edges
            imagesetthickness($img, 1);
            $points = array(
                $x1, $canvas_height - $margins,
                $x1, $y1,
                $x2, $y2,
                $x2, $canvas_height - $margins
            );
            imagefilledpolygon($img, $points, 4, $light_blue);

            // Draw the top line at full thickness
            imagesetthickness($img, $line_thickness);
            imageline($img, $x1, $y1, $x2, $y2, $blue);
        }

        imagesetthickness($img, $line_thickness);
        // Draw the radiator temperature line (red, no fill)
        for ($i = 0; $i < $numberOfPoints - 1; $i++) {
            if ($detailed_powerplant_data[$i]['avg_radiator_temp'] === null || $detailed_powerplant_data[$i]['avg_radiator_temp'] === ''
                || $detailed_powerplant_data[$i + 1]['avg_radiator_temp'] === null || $detailed_powerplant_data[$i + 1]['avg_radiator_temp'] === '') {
                continue;
            }
            $x1 = round($margins + $i * $ratioX) + 1;
            $y1 = round($canvas_height - $margins - ((int)$detailed_powerplant_data[$i]['avg_radiator_temp'] - $tempMinValue) * $ratioYTemp);
            $x2 = round($margins + ($i + 1) * $ratioX);
            $y2 = round($canvas_height - $margins - ((int)$detailed_powerplant_data[$i + 1]['avg_radiator_temp'] - $tempMinValue) * $ratioYTemp);
            imageline($img, $x1, $y1, $x2, $y2, $red);
        }

        imagesetthickness($img, 1);
        // Draw weather condition change annotations
        if (!empty($weather_changes) && $numberOfPoints > 1) {
            $weather_grey = imagecolorallocatealpha($img, 100, 100, 100, 64);
            imagealphablending($img, true);
            $icon_size    = (int)($canvas_height * 0.03);
            $dash_len     = (int)($canvas_height * 0.012);
            $gap_len      = (int)($canvas_height * 0.008);

            foreach ($weather_changes as $change) {
                $changeTime = new DateTime($change['time'], new DateTimeZone('UTC'));

                // Find closest data point index by timestamp
                $closestIdx = 0;
                $minDiff    = PHP_INT_MAX;
                foreach ($detailed_powerplant_data as $idx => $point) {
                    $diff = abs($changeTime->getTimestamp() - (new DateTime($point['time'], new DateTimeZone('UTC')))->getTimestamp());
                    if ($diff < $minDiff) { $minDiff = $diff; $closestIdx = $idx; }
                }

                $x     = (int)round($margins + $closestIdx * $ratioX);
                $y_top = (int)$margins;
                $y_bot = (int)($canvas_height - $margins);

                // Dashed vertical line
                for ($y = $y_top; $y < $y_bot; $y += $dash_len + $gap_len) {
                    imageline($img, $x, $y, $x, min($y + $dash_len, $y_bot), $weather_grey);
                }

                $icon = loadWeatherIcon($change['condition'], $icon_size);
                if ($icon !== false) {
                    imagealphablending($img, true);
                    imagecopy($img, $icon, $x + 1, $y_top + 2, 0, 0, $icon_size, $icon_size);
                    imagedestroy($icon);
                }
            }
        }

        // Draw the border around the chart
        imageline($img, $margins, $canvas_height - $margins, $margins, $margins, $black);
        imageline($img, $margins, $canvas_height - $margins, $canvas_width - $margins, $canvas_height - $margins, $black);
        imageline($img, $canvas_width - $margins, $canvas_height - $margins, $canvas_width - $margins, $margins, $black);
        imageline($img, $margins, $margins, $canvas_width - $margins, $margins, $black);

        // Add the chart title - powerplant name and date
        $title = $powerplant_name . " - " . $reference_date->format('Y-m-d');
        imagettftext($img, $title_font_size, 0, $canvas_width / 2 - strlen($title) * 4, $canvas_height * 0.05, $black, $font_name, $title);
        $xAxisLabel = php_t('chart_x_axis');
        imagettftext($img, $summary_font_size, 0, $canvas_width / 2 - strlen($xAxisLabel) * 4, $canvas_height * 0.96, $black, $font_name, $xAxisLabel);
        $yAxisLabel = php_t('chart_y_axis');
        imagettftext($img, $summary_font_size, 90, $canvas_width * 0.02, $canvas_height / 2 + strlen($yAxisLabel) * 4, $black, $font_name, $yAxisLabel);

        // Add the summary information: total energy generated today, peak power and time it occurred, sunrise and sunset times
        $rad_str = $avg_radiator_temp !== null ? $avg_radiator_temp . "/" . $peak_radiator_temp . " C" : php_t('chart_summary_na');
        $summary  = php_t('chart_summary_energy',  ['kwh'     => number_format($total_energy_today, 1)]) . "\n";
        $summary .= php_t('chart_summary_peak',    ['power'   => number_format($peak_power_today, 0), 'time' => $peak_power_time]) . "\n";
        $summary .= php_t('chart_summary_radiator',['temps'   => $rad_str]) . "\n";
        $summary .= php_t('chart_summary_sun',     ['sunrise' => $sunrise->format('H:i'), 'sunset' => $sunset->format('H:i')]) . "\n";

        // Get the text size to draw a box around it
        $bbox = imagettfbbox($summary_font_size, 0, $font_name, $summary);
        $textWidth = $bbox[2] - $bbox[0];
        $textHeight = $bbox[1] - $bbox[7];

        $x = $canvas_width - ($margins * 1.5) - $textWidth;
        $y = $margins * 1.5;
        $boxmargin = 10;

        imagefilledrectangle($img, $x - $boxmargin, $y - $boxmargin - $summary_font_size, $x + $textWidth + $boxmargin, $y + $textHeight + $boxmargin - $summary_font_size, $light_gray);
        imagerectangle($img, $x - $boxmargin, $y - $boxmargin - $summary_font_size, $x + $textWidth + $boxmargin, $y + $textHeight + $boxmargin - $summary_font_size, $black);

        // Draw the text inside the box
        imagettftext($img, $summary_font_size, 0, $x, $y, $black, $font_name, $summary);

        // Output the image
        ob_start();
        imagepng($img);
        $image_data = ob_get_clean();
        imagedestroy($img);
        return $image_data;
    }

?>
