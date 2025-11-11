<?php

    function getWeatherDataFromAPI() {
        global $powerplant_latitude, $powerplant_longitude, $powerplant_timezone;
        $apiUrl = "https://api.open-meteo.com/v1/forecast?latitude=$powerplant_latitude&longitude=$powerplant_longitude&current=temperature_2m,weather_code&timezone=$powerplant_timezone";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);

        /*
        API Will return data in the following format (only the important parts shown):
        {
            "current": {
                "temperature_2m": 15.4,
                "weather_code": 3
            }
        }
        */

        /*
        We need to build an object like this:
        {
            "temperature": 15,
            "condition": "clear",
            "is_clear": true,
            "is_cloudy": false,
            "is_rainy": false,
            "is_snowy": false,
            "is_stormy": false,
            "is_foggy": false,
            "created_at": "2024-06-01T12:34:56Z"
        }
        */

        $retObj = new stdClass();
        $data = json_decode($response, true);
        // Temperature is in Celsius, and should be an Integer
        $retObj->temperature = (int) $data['current']['temperature_2m'];
        $retObj->is_clear = false;
        $retObj->is_cloudy = false;
        $retObj->is_rainy = false;
        $retObj->is_snowy = false;
        $retObj->is_stormy = false;
        $retObj->is_foggy = false;
        $weather_code = $data['current']['weather_code'];

        /*
        Code	    Description
        0	        Clear sky
        1, 2, 3	    Mainly clear, partly cloudy, and overcast
        45, 48	    Fog and depositing rime fog
        51, 53, 55	Drizzle: Light, moderate, and dense intensity
        56, 57	    Freezing Drizzle: Light and dense intensity
        61, 63, 65	Rain: Slight, moderate and heavy intensity
        66, 67	    Freezing Rain: Light and heavy intensity
        71, 73, 75	Snow fall: Slight, moderate, and heavy intensity
        77	        Snow grains
        80, 81, 82	Rain showers: Slight, moderate, and violent
        85, 86	    Snow showers slight and heavy
        95 *	    Thunderstorm: Slight or moderate
        96, 99 *	Thunderstorm with slight and heavy hail
        */

        if (in_array($weather_code, [0, 1])) {
            $retObj->condition = $weather_code == 0 ? "clear sky" : "mainly clear";
            $retObj->is_clear = true;
        } elseif (in_array($weather_code, [2, 3])) {
            $retObj->condition = $weather_code == 2 ? "partly cloudy" : "overcast";
            $retObj->is_cloudy = true;
        } elseif (in_array($weather_code, [45, 48])) {
            $retObj->condition = "foggy";
            $retObj->is_foggy = true;
        } elseif (in_array($weather_code, [51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82])) {
            switch ($weather_code) {
                case 51:
                    $retObj->condition = "light drizzle";
                    break;
                case 53:
                    $retObj->condition = "moderate drizzle";
                    break;
                case 55:
                    $retObj->condition = "dense drizzle";
                    break;
                case 56:
                    $retObj->condition = "light freezing drizzle";
                    break;
                case 57:
                    $retObj->condition = "dense freezing drizzle";
                    break;
                case 61:
                    $retObj->condition = "slight rain";
                    break;
                case 63:
                    $retObj->condition = "moderate rain";
                    break;
                case 65:
                    $retObj->condition = "heavy rain";
                    break;
                case 66:
                    $retObj->condition = "light freezing rain";
                    break;
                case 67:
                    $retObj->condition = "heavy freezing rain";
                    break;
                case 80:
                    $retObj->condition = "slight rain showers";
                    break;
                case 81:
                    $retObj->condition = "moderate rain showers";
                    break;
                case 82:
                    $retObj->condition = "violent rain showers";
                    break;
            }

            $retObj->is_rainy = true;
        } elseif (in_array($weather_code, [71, 73, 75, 77, 85, 86])) {
            switch ($weather_code) {
                case 71:
                    $retObj->condition = "slight snowfall";
                    break;
                case 73:
                    $retObj->condition = "moderate snowfall";
                    break;
                case 75:
                    $retObj->condition = "heavy snowfall";
                    break;
                case 77:
                    $retObj->condition = "snow grains";
                    break;
                case 85:
                    $retObj->condition = "slight snow showers";
                    break;
                case 86:
                    $retObj->condition = "heavy snow showers";
                    break;
            }

            $retObj->is_snowy = true;
        } elseif (in_array($weather_code, [95, 96, 99])) {
            switch ($weather_code) {
                case 95:
                    $retObj->condition = "slight thunderstorm";
                    break;
                case 96:
                    $retObj->condition = "moderate thunderstorm";
                    break;
                case 99:
                    $retObj->condition = "heavy thunderstorm";
                    break;
            }

            $retObj->is_stormy = true;
        } else {
            $retObj->condition = "unknown";
        }

        $retObj->created_at = date("Y-m-d\TH:i:s\Z");

        return $retObj;
    }

    function saveWeatherDataToDB($weatherData) {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Insert the data into the table "weather_info"
        $query = "INSERT INTO weather_info (temperature, condition, is_clear, is_cloudy, is_rainy, is_snowy, is_stormy, is_foggy, created_at) 
                  VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)";

        $result = pg_query_params($db, $query, array(
            $weatherData->temperature,
            $weatherData->condition,
            $weatherData->is_clear ? "true" : "false",
            $weatherData->is_cloudy ? "true" : "false",
            $weatherData->is_rainy ? "true" : "false",
            $weatherData->is_snowy ? "true" : "false",
            $weatherData->is_stormy ? "true" : "false",
            $weatherData->is_foggy ? "true" : "false",
            $weatherData->created_at
        ));

        pg_close($db);
    }

    function saveWeatherData(){
        try {
            $weatherData = getWeatherDataFromAPI();
            saveWeatherDataToDB($weatherData);
        } catch (Exception $e) {
            error_log("Error saving weather data: " . $e->getMessage());
        }
        echo "Weather data saved successfully.\n";
    }

    function fetchLatestWeatherData() {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Fetch the latest weather data
        $query = "SELECT temperature, condition, is_clear, is_cloudy, is_rainy, is_snowy, is_stormy, is_foggy, created_at 
                  FROM weather_info 
                  ORDER BY created_at DESC 
                  LIMIT 1";

        $result = pg_query($db, $query);
        $row = pg_fetch_assoc($result);

        pg_close($db);

        if ($row) {
            $weatherData = new stdClass();
            $weatherData->temperature = (int) $row['temperature'];
            $weatherData->condition = $row['condition'];
            $weatherData->is_clear = $row['is_clear'];
            $weatherData->is_cloudy = $row['is_cloudy'];
            $weatherData->is_rainy = $row['is_rainy'];
            $weatherData->is_snowy = $row['is_snowy'];
            $weatherData->is_stormy = $row['is_stormy'];
            $weatherData->is_foggy = $row['is_foggy'];
            $weatherData->created_at = $row['created_at'];
            $weatherData->icon = returnWeatherIcon($weatherData->condition);

            return $weatherData;
        } else {
            return getWeatherDataFromAPI();
        }
    }

    function returnWeatherIcon($condition) {
        /*
        List of all possible values for condition:
        clear sky
        mainly clear
        partly cloudy
        overcast
        foggy
        light drizzle
        moderate drizzle
        dense drizzle
        light freezing drizzle
        dense freezing drizzle
        slight rain
        moderate rain
        heavy rain
        light freezing rain
        heavy freezing rain
        slight rain showers
        moderate rain showers
        violent rain showers
        slight snowfall
        moderate snowfall
        heavy snowfall
        snow grains
        slight snow showers
        heavy snow showers
        slight thunderstorm
        moderate thunderstorm
        heavy thunderstorm
        unknown

        Possible return values:
        clear
        cloudy
        rainy
        snowy
        stormy
        foggy
        unknown
        */
        
        switch ($condition) {
            case "clear sky":
            case "mainly clear":
                return "clear";
            case "partly cloudy":
            case "overcast":
                return "cloudy";
            case "light drizzle":
            case "moderate drizzle":
            case "dense drizzle":
            case "light freezing drizzle":
            case "dense freezing drizzle":
            case "slight rain":
            case "moderate rain":
            case "heavy rain":
            case "light freezing rain":
            case "heavy freezing rain":
            case "slight rain showers":
            case "moderate rain showers":
            case "violent rain showers":
                return "rainy";
            case "slight snowfall":
            case "moderate snowfall":
            case "heavy snowfall":
            case "snow grains":
            case "slight snow showers":
            case "heavy snow showers":
                return "snowy";
            case "slight thunderstorm":
            case "moderate thunderstorm":
            case "heavy thunderstorm":
                return "stormy";
            case "foggy":
                return "foggy";
            default:
                return "unknown";
        }
    }



?>