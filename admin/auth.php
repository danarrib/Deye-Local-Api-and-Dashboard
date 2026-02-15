<?php

require_once __DIR__ . '/../config_loader.php';

function get_jwt_secret() {
    $config = load_db_config();
    return hash('sha256', $config['host'] . $config['dbname'] . $config['password'] . 'deye-admin-jwt');
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_generate($user_id, $username) {
    $secret = get_jwt_secret();

    $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
    $payload = json_encode([
        'sub' => $user_id,
        'username' => $username,
        'iat' => time(),
        'exp' => time() + 86400, // 24 hours
    ]);

    $header_encoded = base64url_encode($header);
    $payload_encoded = base64url_encode($payload);

    $signature = hash_hmac('sha256', "$header_encoded.$payload_encoded", $secret, true);
    $signature_encoded = base64url_encode($signature);

    return "$header_encoded.$payload_encoded.$signature_encoded";
}

function jwt_validate($token) {
    $secret = get_jwt_secret();

    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;

    [$header_encoded, $payload_encoded, $signature_encoded] = $parts;

    // Verify signature
    $expected_signature = base64url_encode(
        hash_hmac('sha256', "$header_encoded.$payload_encoded", $secret, true)
    );

    if (!hash_equals($expected_signature, $signature_encoded)) return false;

    // Decode payload
    $payload = json_decode(base64url_decode($payload_encoded), true);
    if (!$payload) return false;

    // Check expiration
    if (isset($payload['exp']) && $payload['exp'] < time()) return false;

    return $payload;
}

function require_auth() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
        $payload = jwt_validate($matches[1]);
        if ($payload) return $payload;
    }

    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
