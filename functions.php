<?php
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL ^ E_DEPRECATED);

    // ==== You are free to edit below this line ====
 
    $powerplant_timezone = 'America/Sao_Paulo'; // Used for displaying the local time, IANA timezone format (https://nodatime.org/TimeZones)
    $powerplant_name = "My Power Plant"; // Used for displaying the powerplant name
    $powerplant_latitude = -23.5; // Program use coordinates to know the exact time for sunrise and sunset
    $powerplant_longitude = -46.6;

    $telegram_token = "your_token_here"; // The token of the Telegram bot
    $telegram_chatId = "chat_id_here"; // The Chat ID or Group ID where the bot will send messages

    // Don't forget to set the inverters to not use dynamic IPs. Set the IPs statically.
    // Add as many inverters as you need
    $inverter_list = array(
        array("ipaddress" => "192.168.15.201", "username" => "admin", "password" => "admin", "friendly_name" => "North A"),
        array("ipaddress" => "192.168.15.202", "username" => "admin", "password" => "admin", "friendly_name" => "North B"),
        array("ipaddress" => "192.168.15.203", "username" => "admin", "password" => "admin", "friendly_name" => "South A"),
        array("ipaddress" => "192.168.15.204", "username" => "admin", "password" => "admin", "friendly_name" => "South B"),
        array("ipaddress" => "192.168.15.205", "username" => "admin", "password" => "admin", "friendly_name" => "South C"),
    );

    // ==== Do not edit below this line ====

    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_user = getenv('DB_USER') ?: 'deye_user';
    $db_pass = getenv('DB_PASS') ?: 'deye123@';
    $db_name = getenv('DB_NAME') ?: 'deye_data';
    $db_port = "5432";

    include_once 'db_functions.php';
    include_once 'weather_functions.php';
    include_once 'telegram_functions.php';

    $processStartDateTime = new DateTime(null, new DateTimeZone($powerplant_timezone));

    setup_db();

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
                "power_today" => $power_today,
                "power_total" => $power_total,
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
        if(is_after_sunset_or_before_sunrise()) {
            return;
        }

        global $inverter_list;
        global $processStartDateTime;
        $order = 0;

        // Iterate over the list of inverters
        foreach ($inverter_list as $inverter) {
            $order++;
            $data = get_inverter_data($inverter['ipaddress'], $inverter['username'], $inverter['password']);

            // If the data has "error" key, try to restart the inverter and get the data again after 1 minute
            if (array_key_exists("error", $data)) {
                $tryToRestart = false;

                if($tryToRestart === true){
                    // Restart the inverter
                    $restart = restart_inverter($inverter['ipaddress'], $inverter['username'], $inverter['password']);
                    if ($restart) {
                        // Wait for 1 minute before trying to get the data again
                        set_time_limit(120);
                        sleep(60);
                    }
                }

                sleep(10);
                $data = get_inverter_data($inverter['ipaddress'], $inverter['username'], $inverter['password']);
            }

            // if the data has "error" key, just skip it
            if (array_key_exists("error", $data)) {
                continue;
            }

            $data["friendly_name"] = $inverter['friendly_name'];
            $data["last_ip_address"] = $inverter['ipaddress'];
            $data["order"] = $order;

            save_inverter_data($data);
        }

        fix_incomplete_data($processStartDateTime);
    }

    function send_daily_report($force = false) {
        global $powerplant_timezone;
        global $processStartDateTime;

        // Get the sunset time for today
        $sunset = get_todays_sunset();

        // If the current time is between sunset and 5 minutes after sunset, send a message to the telegram group
        if (($processStartDateTime->getTimestamp() >= $sunset->getTimestamp() 
            && $processStartDateTime->getTimestamp() <= $sunset->getTimestamp() + 300) || $force === true) {
            // Get latest data from the inverters
            $all_data = get_today_latest_data();

            // Create variables to store the total power being generated now, today and total
            $total_power_now = 0;
            $total_power_today = 0;
            $total_power_total = 0;

            // Iterate over the list of inverters
            foreach ($all_data as $data) {
                $total_power_now += $data['power_now'];
                $total_power_today += $data['power_today'];
                $total_power_total += $data['power_total'];
            }

            $messageText = "Total energy generated today: " . number_format($total_power_today, 1) . " kWh";

            $top_daily_energy = getDailyTopEnergy($processStartDateTime);

            // If top_daily_energy is greater than total_power_today, add a message to the telegram message
            if (($top_daily_energy > 0 && $total_power_today >= $top_daily_energy) || $force === true) {
                $messageText .= "\n🏆 New daily record! Previous record was " . number_format($top_daily_energy, 1) . " kWh";
            }

            // If today is the last day of the month
            if($processStartDateTime->format('d') == $processStartDateTime->format('t') || $force === true) {
                $top_monthly_energy = getMonthlyTopEnergy($processStartDateTime);
                $energy_this_month = getMonthEnergy($processStartDateTime);

                $messageText .= "\n\nTotal energy generated this month: " . number_format($energy_this_month, 1) . " kWh";

                // If top_monthly_energy is greater than energy_this_month, add a message to the telegram message
                if ($top_monthly_energy > 0 && $energy_this_month >= $top_monthly_energy) {
                    $messageText .= "\n🏆 New monthly record! Previous record was " . number_format($top_monthly_energy, 1) . " kWh";
                }
            }

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

    function generateTodaysChart() {
        global $powerplant_name, $powerplant_timezone;
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

        $total_energy_today = 0;
        $peak_power_today = 0;
        $peak_power_time = '';

        foreach ($detailed_powerplant_data as $point) 
        {
            if ($point['total_power_now'] > $peak_power_today) {
                $peak_power_today = $point['total_power_now'];
                $peak_power_time = (new DateTime($point['time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($powerplant_timezone))->format('H:i');
            }
        }

        // Get latest data from the inverters
        $latest_data = get_today_latest_data();

        // Iterate over the list of inverters
        foreach ($latest_data as $data) {
            $total_energy_today += $data['power_today'];
        }

        // Start building the chart
        $canvas_resolution = 'hd'; // Options: 'sd', 'hd', 'fhd', 'qhd', '4k', '8k'

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
        $summary_font_size = $canvas_height * 0.022;
        $font_name = __DIR__ . '/assets/UbuntuMono-Regular.ttf'; // Path to a TTF font file

        $numberOfPoints = count($detailed_powerplant_data);
        $chartWidth = $canvas_width - $margins * 2;
        $chartHeight = $canvas_height - $margins * 2;
        $maxValue = max(array_column($detailed_powerplant_data, 'total_power_now'));
        $maxValue = ceil($maxValue / 100) * 100; // Round up to the nearest 100
        $minValue = 0; // We want the chart to start at 0
        $ratioX = $chartWidth / ($numberOfPoints - 1);
        $ratioY = $chartHeight / ($maxValue - $minValue);

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

        // Draw the labels (power) on the y-axis
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

            // Draw the polygon
            $points = array(
                $x1, $canvas_height - $margins,
                $x1, $y1,
                $x2, $y2,
                $x2, $canvas_height - $margins
            );
            imagefilledpolygon($img, $points, 4, $light_blue);

            // Draw the line
            imageline($img, $x1, $y1, $x2, $y2, $blue);
        }

        // Draw the border around the chart
        imageline($img, $margins, $canvas_height - $margins, $margins, $margins, $black);
        imageline($img, $margins, $canvas_height - $margins, $canvas_width - $margins, $canvas_height - $margins, $black);
        imageline($img, $canvas_width - $margins, $canvas_height - $margins, $canvas_width - $margins, $margins, $black);
        imageline($img, $margins, $margins, $canvas_width - $margins, $margins, $black);

        // Add the chart title - powerplant name and date
        $title = $powerplant_name . " - " . $reference_date->format('Y-m-d');
        imagettftext($img, $title_font_size, 0, $canvas_width / 2 - strlen($title) * 4, $canvas_height * 0.05, $black, $font_name, $title);
        $xAxisLabel = "Time";
        imagettftext($img, $summary_font_size, 0, $canvas_width / 2 - strlen($xAxisLabel) * 4, $canvas_height * 0.96, $black, $font_name, $xAxisLabel);
        $yAxisLabel = "Power (W)";
        imagettftext($img, $summary_font_size, 90, $canvas_width * 0.02, $canvas_height / 2 + strlen($yAxisLabel) * 4, $black, $font_name, $yAxisLabel);

        // Add the summary information: total energy generated today, peak power and time it occurred, sunrise and sunset times
        $summary =  "Total Energy: " . number_format($total_energy_today, 1) . " kWh\n";
        $summary .= "Peak Power:   " . number_format($peak_power_today, 0) . " W at " . $peak_power_time . "\n";
        $summary .= "Sunrise: " . $sunrise->format('H:i') . " | Sunset: " . $sunset->format('H:i') . "\n";

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
