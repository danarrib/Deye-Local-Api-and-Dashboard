<?php
    // Include functions.php
    include 'functions.php';

    // Set the response header to be JSON
    header('Content-Type: application/json');

    // Receive via GET the values of the variables: ipaddress, username, password
    $ipaddress = $_GET['ipaddress'];
    $username = $_GET['username'];
    $password = $_GET['password'];

    // Check if the values of the variables are not empty (all of them are required)
    if (empty($ipaddress) || empty($username) || empty($password)) {
        $json = array(
            "error" => "Missing required parameters."
        );
        // If any of the values is empty, return an error message
        echo json_encode($json);

        // Return status 400 Bad Request
        http_response_code(400);
        return;
    }

    // Call the function get_inverter_data passing the values of the variables: ipaddress, username, password
    $data = get_inverter_data($ipaddress, $username, $password);

    // If the data has "error" key, return status 500 Internal Server Error
    if (array_key_exists("error", $data)) {
        http_response_code(500);
    }

    // Return the JSON object
    echo json_encode($data);
    



?>