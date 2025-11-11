<?php

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

    function send_telegram_daily_chart($caption = '') {
        global $telegram_token, $telegram_chatId;

        $url = "https://api.telegram.org/bot$telegram_token/sendPhoto";
        
        // Create a temporary file from the stream
        $tempFile = tmpfile();
        fwrite($tempFile, generateTodaysChart());
        $tempFilePath = stream_get_meta_data($tempFile)['uri'];
        
        // Prepare the photo for upload
        $photo = new CURLFile($tempFilePath, 'image/png', 'photo.png');
        
        // Set up the POST fields
        $postFields = [
            'chat_id' => $telegram_chatId,
            'photo' => $photo,
        ];
        
        if (!empty($caption)) {
            $postFields['caption'] = $caption;
        }
        
        // Initialize cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        // Execute the request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        // Clean up
        curl_close($ch);
        fclose($tempFile);
        
        // Check for errors
        if ($response === false || $httpCode !== 200) {
            return [
                'success' => false,
                'error' => $error ?: 'HTTP Code: ' . $httpCode,
            ];
        }
        
        // Decode the response
        $result = json_decode($response, true);
        
        return [
            'success' => $result['ok'] ?? false,
            'result' => $result['result'] ?? null,
            'error' => $result['description'] ?? null,
        ];
    }

?>