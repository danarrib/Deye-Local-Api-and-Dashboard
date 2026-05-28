<?php
/**
 * SolarmanV5 / Modbus protocol implementation.
 * Adapted from poc/solarman_test.php for production polling.
 *
 * Entry point: solarman_poll($ip, $logger_serial)
 * Returns structured data array on success, ['error' => ...] on failure.
 */

// ---------------------------------------------------------------------------
// Modbus CRC-16
// ---------------------------------------------------------------------------

function solarman_modbus_crc(string $data): int
{
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($data); $i++) {
        $crc ^= ord($data[$i]);
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 1) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
        }
    }
    return $crc & 0xFFFF;
}

// ---------------------------------------------------------------------------
// Modbus RTU frame builder (FC 0x03 read holding registers)
// ---------------------------------------------------------------------------

function solarman_modbus_request(int $start, int $qty): string
{
    $frame = chr(0x01) . chr(0x03) . pack('n', $start) . pack('n', $qty);
    return $frame . pack('v', solarman_modbus_crc($frame));
}

// ---------------------------------------------------------------------------
// SolarmanV5 frame encoder / decoder
// ---------------------------------------------------------------------------

function solarman_v5_encode(int $serial, string $modbus_frame, int $seq): string
{
    $payload_len = 15 + strlen($modbus_frame);

    $header = chr(0xA5)
        . pack('v', $payload_len)
        . chr(0x10)
        . chr(0x45)
        . pack('v', $seq)
        . pack('V', $serial);

    $payload = chr(0x02)
        . "\x00\x00"
        . "\x00\x00\x00\x00"
        . "\x00\x00\x00\x00"
        . "\x00\x00\x00\x00"
        . $modbus_frame;

    $frame = $header . $payload;

    $sum = 0;
    for ($i = 1; $i < strlen($frame); $i++) {
        $sum = ($sum + ord($frame[$i])) & 0xFF;
    }

    return $frame . chr($sum) . chr(0x15);
}

function solarman_v5_time_response(string $frame, int $serial): string
{
    $seq       = substr($frame, 5, 2);
    $req_type  = ord($frame[4]);
    $resp_type = $req_type - 0x30;

    $payload_len = 10;
    $header = chr(0xA5)
        . pack('v', $payload_len)
        . chr(0x10)
        . chr($resp_type)
        . $seq
        . pack('V', $serial);

    $header[5] = chr((ord($header[5]) + 1) & 0xFF);

    $payload = pack('v', 0x0100)
        . pack('V', time())
        . pack('V', 0);

    $frame_out = $header . $payload;

    $sum = 0;
    for ($i = 1; $i < strlen($frame_out); $i++) {
        $sum = ($sum + ord($frame_out[$i])) & 0xFF;
    }

    return $frame_out . chr($sum) . chr(0x15);
}

function solarman_v5_scan_frames(string $buf): array
{
    $frames = [];
    $pos    = 0;
    $len    = strlen($buf);

    while ($pos < $len) {
        if (ord($buf[$pos]) !== 0xA5) { $pos++; continue; }
        if ($pos + 3 > $len) break;

        $payload_len = unpack('v', substr($buf, $pos + 1, 2))[1];
        $frame_len   = 13 + $payload_len;

        if ($pos + $frame_len > $len) break;

        $frame = substr($buf, $pos, $frame_len);
        if (ord($frame[$frame_len - 1]) !== 0x15) { $pos++; continue; }

        $frames[] = [ord($frame[4]), $frame];
        $pos += $frame_len;
    }

    return $frames;
}

function solarman_v5_extract_modbus(string $v5_frame): ?string
{
    $len = strlen($v5_frame);
    if ($len < 27) return null;
    $mb = substr($v5_frame, 25, $len - 27);
    return strlen($mb) >= 5 ? $mb : null;
}

// ---------------------------------------------------------------------------
// Modbus FC 0x03 response parser
// ---------------------------------------------------------------------------

function solarman_modbus_parse_registers(string $mb_frame, int $start): ?array
{
    if (strlen($mb_frame) < 5) return null;
    $fc = ord($mb_frame[1]);

    if ($fc & 0x80) return null; // Modbus exception

    if ($fc !== 0x03) return null;

    $byte_count = ord($mb_frame[2]);
    $reg_count  = intdiv($byte_count, 2);
    $values     = [];

    for ($i = 0; $i < $reg_count; $i++) {
        $raw = unpack('n', substr($mb_frame, 3 + $i * 2, 2))[1];
        $values[$start + $i] = $raw;
    }

    return $values;
}

// ---------------------------------------------------------------------------
// Register decoder — converts raw register map to structured data
// ---------------------------------------------------------------------------

function solarman_decode_registers(array $regs): array
{
    // Energy totals
    $energy_today = isset($regs[0x003C]) ? round($regs[0x003C] * 0.1, 2) : 0.0;

    $total_low  = $regs[0x003F] ?? 0;
    $total_high = $regs[0x0040] ?? 0;
    $energy_total = round((($total_high << 16) | $total_low) * 0.1, 2);

    // AC output power (32-bit combined LOW+HIGH)
    $power_low  = $regs[0x0056] ?? 0;
    $power_high = $regs[0x0057] ?? 0;
    $power_now  = round((($power_high << 16) | $power_low) * 0.1, 1);

    // Radiator temperature: (raw - 1000) * 0.01 → °C, stored as SMALLINT
    $radiator_temp = null;
    if (isset($regs[0x005A]) && $regs[0x005A] > 0) {
        $radiator_temp = (int) round(($regs[0x005A] - 1000) * 0.01);
    }

    // Per-panel inputs — only include panels with voltage > 0 (connected)
    // energy_total is only documented for PV1 (0x0045) and PV2 (0x0047)
    $pv_map = [
        1 => ['v' => 0x006D, 'i' => 0x006E, 'e_today' => 0x0041, 'e_total' => 0x0045],
        2 => ['v' => 0x006F, 'i' => 0x0070, 'e_today' => 0x0042, 'e_total' => 0x0047],
        3 => ['v' => 0x0071, 'i' => 0x0072, 'e_today' => 0x0043, 'e_total' => null],
        4 => ['v' => 0x0073, 'i' => 0x0074, 'e_today' => 0x0044, 'e_total' => null],
    ];

    $pv_inputs = [];
    foreach ($pv_map as $num => $addrs) {
        $voltage = isset($regs[$addrs['v']]) ? round($regs[$addrs['v']] * 0.1, 1) : 0.0;
        if ($voltage <= 0.0) continue;

        $current      = isset($regs[$addrs['i']]) ? round($regs[$addrs['i']] * 0.1, 1) : 0.0;
        $pv_e_today   = ($addrs['e_today'] !== null && isset($regs[$addrs['e_today']]))
                        ? round($regs[$addrs['e_today']] * 0.1, 2) : null;
        $pv_e_total   = ($addrs['e_total'] !== null && isset($regs[$addrs['e_total']]))
                        ? round($regs[$addrs['e_total']] * 0.1, 2) : null;

        $pv_inputs[] = [
            'pv_number'    => $num,
            'voltage'      => $voltage,
            'current'      => $current,
            'power'        => round($voltage * $current, 2),
            'energy_today' => $pv_e_today,
            'energy_total' => $pv_e_total,
        ];
    }

    return [
        'power_now'     => $power_now,
        'energy_today'  => $energy_today,
        'energy_total'  => $energy_total,
        'radiator_temp' => $radiator_temp,
        'pv_inputs'     => $pv_inputs,
    ];
}

// ---------------------------------------------------------------------------
// Verification (lightweight single-register read — used during discovery)
// ---------------------------------------------------------------------------

/**
 * Confirm that port 8899 is reachable and the logger serial is correct by
 * reading a single register (0x003B Running Status). Returns true on any
 * valid Modbus response, false on timeout or connection failure.
 */
function solarman_verify(string $ip, int $logger_serial, int $timeout = 5): bool
{
    $port  = 8899;
    $start = 0x003B; // Running Status — minimal read, one register
    $qty   = 1;

    $sock = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if (!$sock) return false;
    stream_set_timeout($sock, $timeout);

    $seq    = rand(1, 254);
    $mb_req = solarman_modbus_request($start, $qty);
    $v5_req = solarman_v5_encode($logger_serial, $mb_req, $seq);
    fwrite($sock, $v5_req);

    $buf      = '';
    $found    = false;
    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $chunk = fread($sock, 4096);
        if ($chunk === false || $chunk === '') {
            if (feof($sock)) break;
            usleep(50000);
            continue;
        }
        $buf .= $chunk;

        foreach (solarman_v5_scan_frames($buf) as [$type, $frame]) {
            if ($type === 0x15 && ord($frame[5]) === ($seq & 0xFF)) {
                $found = true;
            } elseif (in_array($type, [0x41, 0x42, 0x43, 0x47, 0x48], true)) {
                fwrite($sock, solarman_v5_time_response($frame, $logger_serial));
            }
        }

        if ($found) break;
    }

    fclose($sock);
    return $found;
}

// ---------------------------------------------------------------------------
// Main poll function
// ---------------------------------------------------------------------------

/**
 * Poll a single inverter via SolarmanV5 / Modbus on TCP port 8899.
 *
 * @param string $ip            Inverter IP address
 * @param int    $logger_serial Logger serial number (inverters.device_sn)
 * @param int    $timeout       TCP + read timeout in seconds
 * @return array Structured data on success, ['error' => string] on failure
 */
function solarman_poll(string $ip, int $logger_serial, int $timeout = 10): array
{
    $port  = 8899;
    $start = 0x0001;
    $qty   = 0x007D; // 125 registers — covers the full documented range in one round-trip

    $sock = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if (!$sock) {
        return ['error' => "TCP connect to {$ip}:{$port} failed: {$errstr} (errno {$errno})"];
    }
    stream_set_timeout($sock, $timeout);

    $seq    = rand(1, 254);
    $mb_req = solarman_modbus_request($start, $qty);
    $v5_req = solarman_v5_encode($logger_serial, $mb_req, $seq);
    fwrite($sock, $v5_req);

    $buf        = '';
    $modbus_raw = null;
    $deadline   = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $chunk = fread($sock, 4096);
        if ($chunk === false || $chunk === '') {
            if (feof($sock)) break;
            usleep(50000);
            continue;
        }
        $buf .= $chunk;

        foreach (solarman_v5_scan_frames($buf) as [$type, $frame]) {
            if ($type === 0x15 && ord($frame[5]) === ($seq & 0xFF)) {
                $modbus_raw = $frame;
            } elseif (in_array($type, [0x41, 0x42, 0x43, 0x47, 0x48], true)) {
                // Logger-initiated handshake / heartbeat — send time-sync response
                fwrite($sock, solarman_v5_time_response($frame, $logger_serial));
            }
        }

        if ($modbus_raw !== null) break;
    }

    fclose($sock);

    if ($modbus_raw === null) {
        return ['error' => "No valid Modbus response from {$ip} within {$timeout}s"];
    }

    $mb_frame = solarman_v5_extract_modbus($modbus_raw);
    if ($mb_frame === null) {
        return ['error' => 'Could not extract Modbus frame from V5 response'];
    }

    $regs = solarman_modbus_parse_registers($mb_frame, $start);
    if ($regs === null) {
        return ['error' => 'Failed to parse Modbus FC 0x03 response'];
    }

    return solarman_decode_registers($regs);
}
