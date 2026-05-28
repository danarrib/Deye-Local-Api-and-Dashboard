<?php

function load_db_config() {
    // Tier 1: config.php file
    $config_path = __DIR__ . '/config.php';
    if (file_exists($config_path)) {
        $config = include $config_path;
        if (is_array($config) && !empty($config['host'])) {
            return $config;
        }
    }

    // Tier 2: environment variables
    $host = getenv('DB_HOST');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    $name = getenv('DB_NAME');
    $port = getenv('DB_PORT') ?: '5432';

    if ($host && $user && $name) {
        $config = [
            'host' => $host,
            'port' => $port,
            'dbname' => $name,
            'user' => $user,
            'password' => $pass ?: '',
        ];

        // Test connection and auto-write config.php on success
        $conn_string = "host={$config['host']} port={$config['port']} dbname={$config['dbname']} user={$config['user']} password={$config['password']}";
        $conn = @pg_connect($conn_string, PGSQL_CONNECT_FORCE_NEW);
        if ($conn) {
            pg_close($conn);
            write_config_file($config);
            return $config;
        }
    }

    // Tier 3: no config available
    return false;
}

function write_config_file(array $config) {
    $config_path = __DIR__ . '/config.php';
    $content = "<?php\nreturn " . var_export($config, true) . ";\n";
    file_put_contents($config_path, $content);
}

function get_db_connection() {
    static $conn = null;

    if ($conn !== null && pg_connection_status($conn) === PGSQL_CONNECTION_OK) {
        return $conn;
    }

    $config = load_db_config();
    if (!$config) return false;

    $conn_string = "host={$config['host']} port={$config['port']} dbname={$config['dbname']} user={$config['user']} password={$config['password']}";
    $conn = @pg_connect($conn_string, PGSQL_CONNECT_FORCE_NEW);
    return $conn ?: false;
}

function get_system_status() {
    $db = get_db_connection();
    if (!$db) return 'needs-db-config';

    $result = @pg_query($db, "SELECT COUNT(*) AS cnt FROM users");
    if (!$result) return 'needs-admin';

    $row = pg_fetch_assoc($result);
    if ((int)$row['cnt'] === 0) return 'needs-admin';
    return 'ready';
}

function load_powerplant_settings() {
    $db = get_db_connection();
    if (!$db) return [];

    $result = @pg_query($db, "SELECT * FROM powerplant LIMIT 1");
    if (!$result) return [];

    $row = pg_fetch_assoc($result);
    return $row ?: [];
}

function load_inverter_list() {
    $db = get_db_connection();
    if (!$db) return [];

    $result = @pg_query($db, "SELECT ip_address AS ipaddress, username, password, friendly_name, device_sn, solarman_enabled FROM inverters ORDER BY \"order\", id");
    if (!$result) return [];

    $rows = pg_fetch_all($result);
    return $rows ?: [];
}
