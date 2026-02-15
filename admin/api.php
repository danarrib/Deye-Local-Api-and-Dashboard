<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../config_loader.php';
require_once __DIR__ . '/auth.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {
    case 'status':
        handle_status();
        break;
    case 'setup-db':
        handle_setup_db();
        break;
    case 'setup-admin':
        handle_setup_admin();
        break;
    case 'login':
        handle_login();
        break;
    case 'timezones':
        handle_timezones();
        break;
    case 'settings':
        if ($method === 'GET') handle_get_settings();
        else handle_save_settings();
        break;
    case 'inverters':
        if ($method === 'GET') handle_get_inverters();
        elseif ($method === 'POST') handle_add_inverter();
        elseif ($method === 'PUT') handle_update_inverter();
        elseif ($method === 'DELETE') handle_delete_inverter();
        break;
    case 'telegram-getupdates':
        handle_telegram_getupdates();
        break;
    case 'telegram-test-message':
        handle_telegram_test_message();
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unknown action']);
}

// ─── Handlers ───────────────────────────────────────────────────────────────

function handle_status() {
    echo json_encode(['status' => get_system_status()]);
}

function handle_setup_db() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $host = $input['host'] ?? '';
    $port = $input['port'] ?? '5432';
    $dbname = $input['dbname'] ?? '';
    $user = $input['user'] ?? '';
    $password = $input['password'] ?? '';

    if (empty($host) || empty($dbname) || empty($user)) {
        http_response_code(400);
        echo json_encode(['error' => 'Host, database name, and user are required']);
        return;
    }

    // Test the connection
    $conn_string = "host=$host port=$port dbname=$dbname user=$user password=$password";
    $conn = @pg_connect($conn_string);
    if (!$conn) {
        http_response_code(400);
        echo json_encode(['error' => 'Could not connect to the database. Please check your settings.']);
        return;
    }
    pg_close($conn);

    // Write config file
    $config = [
        'host' => $host,
        'port' => $port,
        'dbname' => $dbname,
        'user' => $user,
        'password' => $password,
    ];
    write_config_file($config);

    // Run database setup
    $GLOBALS['db_host'] = $host;
    $GLOBALS['db_port'] = $port;
    $GLOBALS['db_name'] = $dbname;
    $GLOBALS['db_user'] = $user;
    $GLOBALS['db_pass'] = $password;

    require_once __DIR__ . '/../db_functions.php';
    setup_db();

    echo json_encode(['success' => true]);
}

function handle_setup_admin() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    // Only allow setup if no admin exists yet
    $status = get_system_status();
    if ($status !== 'needs-admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin account already exists']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Username and password are required']);
        return;
    }

    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 6 characters']);
        return;
    }

    $db = get_db_connection();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        return;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

    $result = pg_query_params($db,
        "INSERT INTO users (username, password_hash, user_type, created_at) VALUES ($1, $2, 'admin', $3)",
        [$username, $hash, $now]
    );

    pg_close($db);

    if (!$result) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create admin account']);
        return;
    }

    echo json_encode(['success' => true]);
}

function handle_login() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Username and password are required']);
        return;
    }

    $db = get_db_connection();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        return;
    }

    $result = pg_query_params($db, "SELECT id, username, password_hash FROM users WHERE username = $1", [$username]);
    $user = pg_fetch_assoc($result);
    pg_close($db);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        return;
    }

    $token = jwt_generate($user['id'], $user['username']);
    echo json_encode(['token' => $token]);
}

function handle_timezones() {
    echo json_encode(['timezones' => DateTimeZone::listIdentifiers()]);
}

function handle_get_settings() {
    require_auth();

    $db = get_db_connection();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        return;
    }

    $result = pg_query($db, "SELECT * FROM powerplant LIMIT 1");
    $row = pg_fetch_assoc($result);
    pg_close($db);

    echo json_encode($row ?: (object)[]);
}

function handle_save_settings() {
    require_auth();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $name = trim($input['name'] ?? '');
    $timezone = trim($input['timezone'] ?? '');
    $latitude = $input['latitude'] ?? null;
    $longitude = $input['longitude'] ?? null;
    $telegram_token = trim($input['telegram_token'] ?? '');
    $telegram_chat_id = trim($input['telegram_chat_id'] ?? '');

    if (empty($name) || empty($timezone) || $latitude === null || $longitude === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Name, timezone, latitude, and longitude are required']);
        return;
    }

    $db = get_db_connection();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        return;
    }

    // Check if a row already exists
    $result = pg_query($db, "SELECT id FROM powerplant LIMIT 1");
    $existing = pg_fetch_assoc($result);

    if ($existing) {
        pg_query_params($db,
            "UPDATE powerplant SET name = $1, timezone = $2, latitude = $3, longitude = $4, telegram_token = $5, telegram_chat_id = $6 WHERE id = $7",
            [$name, $timezone, (float)$latitude, (float)$longitude, $telegram_token ?: null, $telegram_chat_id ?: null, $existing['id']]
        );
    } else {
        pg_query_params($db,
            "INSERT INTO powerplant (name, timezone, latitude, longitude, telegram_token, telegram_chat_id) VALUES ($1, $2, $3, $4, $5, $6)",
            [$name, $timezone, (float)$latitude, (float)$longitude, $telegram_token ?: null, $telegram_chat_id ?: null]
        );
    }

    pg_close($db);
    echo json_encode(['success' => true]);
}

function handle_get_inverters() {
    require_auth();

    $db = get_db_connection();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        return;
    }

    $result = pg_query($db, "SELECT id, device_sn, friendly_name, ip_address, username, password, \"order\" FROM inverters ORDER BY \"order\", id");
    $rows = pg_fetch_all($result);
    pg_close($db);

    echo json_encode(['inverters' => $rows ?: []]);
}

function handle_add_inverter() {
    require_auth();

    $input = json_decode(file_get_contents('php://input'), true);
    $friendly_name = trim($input['friendly_name'] ?? '');
    $ip_address = trim($input['ip_address'] ?? '');
    $username = trim($input['username'] ?? 'admin');
    $password = trim($input['password'] ?? 'admin');

    if (empty($friendly_name) || empty($ip_address)) {
        http_response_code(400);
        echo json_encode(['error' => 'Friendly name and IP address are required']);
        return;
    }

    $db = get_db_connection();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        return;
    }

    $device_sn = 'PENDING_' . uniqid();
    $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

    // Get next order value
    $result = pg_query($db, "SELECT COALESCE(MAX(\"order\"), 0) + 1 AS next_order FROM inverters");
    $row = pg_fetch_assoc($result);
    $order = (int)$row['next_order'];

    $result = pg_query_params($db,
        "INSERT INTO inverters (device_sn, friendly_name, created_at, ip_address, username, password, \"order\") VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id",
        [$device_sn, $friendly_name, $now, $ip_address, $username, $password, $order]
    );

    $new_row = pg_fetch_assoc($result);
    pg_close($db);

    echo json_encode(['success' => true, 'id' => (int)$new_row['id']]);
}

function handle_update_inverter() {
    require_auth();

    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Inverter ID is required']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $friendly_name = trim($input['friendly_name'] ?? '');
    $ip_address = trim($input['ip_address'] ?? '');
    $username = trim($input['username'] ?? 'admin');
    $password = trim($input['password'] ?? 'admin');

    if (empty($friendly_name) || empty($ip_address)) {
        http_response_code(400);
        echo json_encode(['error' => 'Friendly name and IP address are required']);
        return;
    }

    $db = get_db_connection();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        return;
    }

    pg_query_params($db,
        "UPDATE inverters SET friendly_name = $1, ip_address = $2, username = $3, password = $4 WHERE id = $5",
        [$friendly_name, $ip_address, $username, $password, (int)$id]
    );

    pg_close($db);
    echo json_encode(['success' => true]);
}

function handle_delete_inverter() {
    require_auth();

    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Inverter ID is required']);
        return;
    }

    $db = get_db_connection();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        return;
    }

    pg_query_params($db, "DELETE FROM inverters WHERE id = $1", [(int)$id]);
    pg_close($db);

    echo json_encode(['success' => true]);
}

function handle_telegram_getupdates() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    require_auth();

    $input = json_decode(file_get_contents('php://input'), true);
    $token = trim($input['token'] ?? '');

    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bot token is required']);
        return;
    }

    $url = "https://api.telegram.org/bot{$token}/getUpdates";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($http_code !== 200 || !$response) {
        http_response_code(400);
        echo json_encode(['error' => 'Could not reach Telegram API. Check that the token is correct.']);
        return;
    }

    $data = json_decode($response, true);
    if (!$data || !$data['ok'] || empty($data['result'])) {
        echo json_encode(['chats' => []]);
        return;
    }

    // Extract unique chats from updates
    $chats = [];
    $seen = [];
    foreach ($data['result'] as $update) {
        $msg = $update['message'] ?? $update['channel_post'] ?? null;
        if (!$msg || !isset($msg['chat'])) continue;

        $chat = $msg['chat'];
        $chat_id = (string)$chat['id'];
        if (isset($seen[$chat_id])) continue;
        $seen[$chat_id] = true;

        $name = $chat['title'] ?? $chat['first_name'] ?? 'Unknown';
        if (isset($chat['last_name'])) $name .= ' ' . $chat['last_name'];

        $chats[] = [
            'id' => $chat_id,
            'name' => $name,
            'type' => $chat['type'] ?? 'unknown',
        ];
    }

    echo json_encode(['chats' => $chats]);
}

function handle_telegram_test_message() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    require_auth();

    $input = json_decode(file_get_contents('php://input'), true);
    $token = trim($input['token'] ?? '');
    $chat_id = trim($input['chat_id'] ?? '');

    if (empty($token) || empty($chat_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bot token and chat ID are required']);
        return;
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'chat_id' => $chat_id,
        'text' => "✅ Deye Solar Dashboard - Telegram integration is working!\n\nThis is a test message from the admin panel.",
    ]));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($http_code !== 200 || !$response) {
        http_response_code(400);
        echo json_encode(['error' => 'Could not send message. Check that the token and chat ID are correct.']);
        return;
    }

    $data = json_decode($response, true);
    if (!$data || !$data['ok']) {
        $desc = $data['description'] ?? 'Unknown error';
        http_response_code(400);
        echo json_encode(['error' => "Telegram API error: $desc"]);
        return;
    }

    echo json_encode(['success' => true]);
}
