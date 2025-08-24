<?php
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL ^ E_DEPRECATED);

    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_user = getenv('DB_USER') ?: 'deye_user';
    $db_pass = getenv('DB_PASS') ?: 'deye123@';
    $db_name = getenv('DB_NAME') ?: 'deye_data';
    $db_port = "5432";

    $powerplant_timezone = 'America/Sao_Paulo'; // Used for displaying the local time
    $powerplant_name = "My Powerplant"; // Used for displaying the powerplant name
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

    $processStartDateTime = new DateTime(null, new DateTimeZone($powerplant_timezone));

    setup_db();

    function get_inverter_data($ipaddress, $username, $password) {
        // Try to fetch the contents of http://{ipaddress}/status.html, using the provided username and password (simple http authentication)
        $url = "http://$ipaddress/status.html";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Set the timeout to 5 seconds
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

    function setup_db() {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Create the table "pvstatsdetail" if it does not exist
        $query = "CREATE TABLE IF NOT EXISTS pvstatsdetail (
            id BIGSERIAL PRIMARY KEY NOT NULL,
            device_sn VARCHAR(30) NOT NULL,
            power_now NUMERIC NOT NULL,
            power_today NUMERIC NOT NULL,
            power_total NUMERIC NOT NULL,
            created_at TIMESTAMPTZ NOT NULL
        )";
        $result = pg_query($db, $query);

        // Create a index for the device_sn column
        $query = "CREATE INDEX IF NOT EXISTS device_sn_idx ON pvstatsdetail (device_sn)";
        $result = pg_query($db, $query);

        // Create a table for storing the inverter details
        $query = "CREATE TABLE IF NOT EXISTS inverter_details (
            id BIGSERIAL PRIMARY KEY NOT NULL,
            device_sn VARCHAR(30) NOT NULL,
            friendly_name VARCHAR(100) NOT NULL,
            created_at TIMESTAMPTZ NOT NULL,
            last_ip_address VARCHAR(45) NOT NULL
        )";
        $result = pg_query($db, $query);

        // Create a unique index for the device_sn column
        $query = "CREATE UNIQUE INDEX IF NOT EXISTS device_sn_uniq ON inverter_details (device_sn)";
        $result = pg_query($db, $query);

        // Add an "order" field to the inverter_details table if it doesn't already exists
        $query = "ALTER TABLE inverter_details ADD COLUMN IF NOT EXISTS \"order\" INT";
        $result = pg_query($db, $query);

        // Close the connection to the database
        pg_close($db);
    }

    function save_inverter_data($data) {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;

        // if power_now is 0, power_today is 0 and power_total is 0, just skip it
        if ($data['power_now'] == 0 && $data['power_today'] == 0 && $data['power_total'] == 0) {
            return;
        }

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Insert the data into the table "pvstatsdetail"
        $query = "INSERT INTO pvstatsdetail (device_sn, power_now, power_today, power_total, created_at) VALUES ($1, $2, $3, $4, $5)";
        $result = pg_query_params($db, $query, array($data['device_sn'], $data['power_now'], $data['power_today'], $data['power_total'], $data['timestamp']));

        // Upsert data into the inverter_details table
        $query = "INSERT INTO inverter_details (device_sn, friendly_name, created_at, last_ip_address, \"order\") VALUES ($1, $2, $3, $4, $5)
                  ON CONFLICT (device_sn) DO UPDATE SET friendly_name = $2, created_at = $3, last_ip_address = $4, \"order\" = $5";
        $result = pg_query_params($db, $query, array($data['device_sn'], $data['friendly_name'], $data['timestamp'], $data['last_ip_address'], $data['order']));

        // Close the connection to the database
        pg_close($db);
    }

    function refresh_inverter_data() {
        if(is_after_sunset_or_before_sunrise()) {
            return;
        }

        global $inverter_list;
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
    }

    

    function get_today_latest_data() {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Get the latest data for today
        $query = "SELECT DISTINCT ON (device_sn) * FROM pvstatsdetail WHERE DATE(created_at) = CURRENT_DATE ORDER BY device_sn, created_at DESC;";
        $query = "SELECT DISTINCT ON (pd.device_sn) pd.*, idet.friendly_name FROM pvstatsdetail pd left join inverter_details idet on pd.device_sn = idet.device_sn WHERE DATE(pd.created_at) = CURRENT_DATE ORDER BY pd.device_sn, pd.created_at DESC;";

        $result = pg_query($db, $query);

        // Fetch the result as an associative array
        $data = pg_fetch_all($result);

        // Close the connection to the database
        pg_close($db);

        // Return the data
        return $data;
    }


    function send_daily_report() {
        global $powerplant_timezone;
        global $processStartDateTime;

        // Get the sunset time for today
        $sunset = get_todays_sunset();

        // If the current time is between sunset and 5 minutes after sunset, send a message to the telegram group
        if ($processStartDateTime->getTimestamp() >= $sunset->getTimestamp() && $processStartDateTime->getTimestamp() <= $sunset->getTimestamp() + 300) {
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

            $messageText = "Total power generated today: " . number_format($total_power_today, 1) . " kWh\n";

            send_telegram_message($messageText);
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

    function send_telegram_message($messageText) {
        global $telegram_token, $telegram_chatId;

        $url = "https://api.telegram.org/bot$telegram_token/sendMessage?chat_id=$telegram_chatId&text=" . urlencode($messageText);

        $options = [
            "http" => [
                "method" => "GET",
                "header" => "Content-Type: application/json\r\n"
            ]
        ];
    
        // Create context stream
        $context = stream_context_create($options);
    
        // Make the GET request and get the response
        $response = file_get_contents($url, false, $context);
    
        // Check for errors
        if ($response === FALSE) {
            return false;
        } else {
            return true;
        }

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

    function get_detailed_inverter_todays_data($sunrise, $sunset, $reference_date) {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;

        // Format reference date as "YYYY-MM-DD"
        $reference_date = $reference_date->format('Y-m-d');

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Get the latest data for today
        $query = "WITH time_intervals AS (
    SELECT generate_series(
        date_trunc('day', '$reference_date' AT TIME ZONE 'UTC'),  -- Start at midnight today
        date_trunc('day', '$reference_date' AT TIME ZONE 'UTC' + interval '1 day'),  -- End at midnight tomorrow
        interval '5 minutes'
    ) AS interval_start
)
SELECT ti.interval_start as time, pvsd.device_sn, pvsd.power_now, pvsd.power_today, idet.friendly_name, idet.order
FROM time_intervals ti
LEFT JOIN LATERAL (
    SELECT DISTINCT ON (device_sn) *
    FROM pvstatsdetail pvd
    WHERE pvd.created_at BETWEEN ti.interval_start AND (ti.interval_start + interval '5 minutes')
    ORDER BY pvd.device_sn, pvd.created_at DESC  -- Explicitly pick the latest record
) pvsd ON TRUE
LEFT JOIN inverter_details idet ON pvsd.device_sn = idet.device_sn
ORDER BY ti.interval_start, idet.order,pvsd.device_sn, pvsd.created_at;";

        $result = pg_query($db, $query);

        // Fetch the result as an associative array
        $data = pg_fetch_all($result);

        // Close the connection to the database
        pg_close($db);

        // Remove the rows with device_sn as NULL and (time before sunrise or after sunset)
        $data = array_values(array_filter($data, function($row) use ($sunrise, $sunset) {
            $interval_start_utc = new DateTime($row['time'], new DateTimeZone('UTC'));
            return $row['device_sn'] != NULL && $interval_start_utc >= $sunrise && $interval_start_utc <= $sunset;
        }));

        // Update the data interval_start to add the timezone information
        foreach ($data as &$row) {
            $interval_start_utc = new DateTime($row['time'], new DateTimeZone('UTC'));
            $row['time'] = $interval_start_utc->format('Y-m-d\TH:i:s\Z');
        }

        // Return the data
        return $data;

    }

    function get_detailed_powerplant_todays_data($sunrise, $sunset, $reference_date) {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;

        // Format reference date as "YYYY-MM-DD"
        $reference_date = $reference_date->format('Y-m-d');

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Get the latest data for today
        $query = "WITH time_intervals AS (
    SELECT generate_series(
        date_trunc('day', '$reference_date' AT TIME ZONE 'UTC'),  -- Start at midnight today
        date_trunc('day', '$reference_date' AT TIME ZONE 'UTC' + interval '1 day'),  -- End at midnight tomorrow
        interval '5 minutes'
    ) AS interval_start
)
SELECT 
    ti.interval_start as time,
    SUM(pvsd.power_now) as total_power_now
FROM time_intervals ti
LEFT JOIN LATERAL (
    SELECT *
    FROM pvstatsdetail
    WHERE created_at BETWEEN ti.interval_start AND (ti.interval_start + interval '5 minutes')
    ORDER BY created_at DESC
) pvsd ON TRUE
GROUP BY ti.interval_start
ORDER BY ti.interval_start;";

        $result = pg_query($db, $query);

        // Fetch the result as an associative array
        $data = pg_fetch_all($result);

        // Close the connection to the database
        pg_close($db);

        // Remove the rows with device_sn as NULL and (time before sunrise or after sunset)
        $data = array_values(array_filter($data, function($row) use ($sunrise, $sunset) {
            $interval_start_utc = new DateTime($row['time'], new DateTimeZone('UTC'));
            return $row['total_power_now'] !== NULL && $interval_start_utc >= $sunrise && $interval_start_utc <= $sunset;
        }));

        // Update the data interval_start to add the timezone information
        foreach ($data as &$row) {
            $interval_start_utc = new DateTime($row['time'], new DateTimeZone('UTC'));
            $row['time'] = $interval_start_utc->format('Y-m-d\TH:i:s\Z');

            // total_power_now is a number with 1 decimal digit, so convert it to float with 1 decimal digit if it is not NULL, otherwise set to 0
            $row['total_power_now'] = $row['total_power_now'] != NULL ? round(floatval($row['total_power_now']), 1) : 0;
            
        }

        // Return the data
        return $data;

    }

?>