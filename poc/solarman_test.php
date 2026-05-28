<?php
/**
 * SolarmanV5 PoC - Reads Modbus holding registers from a Deye SUN600G3
 * micro-inverter via the SolarmanV5 protocol on port 8899.
 *
 * Register map source: deye_4mppt.yaml (StephanJoubert/home_assistant_solarman)
 * Confirmed against SUNM225G4 (4-input, 4-MPPT micro-inverter)
 * Protocol source: pysolarmanv5 (jmccrohan/pysolarmanv5)
 *
 * Usage:
 *   php solarman_test.php <inverter_ip> <logger_serial_number>
 *
 * Example:
 *   php solarman_test.php 192.168.1.100 2712345678
 *
 * The logger serial is the number printed on the Wi-Fi stick label,
 * NOT the inverter serial number.
 */

// ---------------------------------------------------------------------------
// Modbus CRC-16
// ---------------------------------------------------------------------------

function modbus_crc(string $data): int
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
// Modbus RTU frame builders
// ---------------------------------------------------------------------------

function modbus_read_holding_registers(int $slave, int $start, int $qty): string
{
    $frame = chr($slave) . chr(0x03) . pack('n', $start) . pack('n', $qty);
    return $frame . pack('v', modbus_crc($frame)); // CRC is little-endian
}

// ---------------------------------------------------------------------------
// SolarmanV5 frame encoder / decoder
// ---------------------------------------------------------------------------

/**
 * Wrap a Modbus RTU frame in a SolarmanV5 REQUEST frame (control code 0x45).
 *
 * Frame layout (11-byte header):
 *   A5 | payload_len(2 LE) | 10 | 45 | seq(2 LE) | serial(4 LE)
 * Then 15 bytes of payload header:
 *   02 | 00 00 | 00 00 00 00 | 00 00 00 00 | 00 00 00 00
 * Then the Modbus RTU frame, then checksum + 15 footer.
 */
function v5_encode(int $serial, string $modbus_frame, int $seq): string
{
    $payload_len = 15 + strlen($modbus_frame);

    $header = chr(0xA5)
        . pack('v', $payload_len)   // 2 bytes LE
        . chr(0x10)                 // control code suffix
        . chr(0x45)                 // REQUEST
        . pack('v', $seq)           // sequence number, 2 bytes LE
        . pack('V', $serial);       // logger serial, 4 bytes LE

    $payload = chr(0x02)            // frametype
        . "\x00\x00"                // sensortype
        . "\x00\x00\x00\x00"        // deliverytime
        . "\x00\x00\x00\x00"        // powerontime
        . "\x00\x00\x00\x00"        // offsettime
        . $modbus_frame;

    $frame = $header . $payload;

    // Checksum: sum of all bytes from index 1 to end (excludes start magic 0xA5)
    $sum = 0;
    for ($i = 1; $i < strlen($frame); $i++) {
        $sum = ($sum + ord($frame[$i])) & 0xFF;
    }

    return $frame . chr($sum) . chr(0x15);
}

/**
 * Build a time-sync response for handshake / heartbeat frames.
 * The logger expects this before it will answer Modbus requests.
 */
function v5_time_response(string $frame, int $serial): string
{
    $seq       = substr($frame, 5, 2);
    $req_type  = ord($frame[4]);
    $resp_type = $req_type - 0x30;    // 0x41→0x11, 0x47→0x17, etc.

    $payload_len = 10;
    $header = chr(0xA5)
        . pack('v', $payload_len)
        . chr(0x10)
        . chr($resp_type)
        . $seq
        . pack('V', $serial);

    // Bump sequence byte 5 by 1 (mirrors pysolarmanv5 behaviour)
    $header[5] = chr((ord($header[5]) + 1) & 0xFF);

    $payload = pack('v', 0x0100)          // frame & sensor type
        . pack('V', time())               // current Unix timestamp
        . pack('V', 0);                   // offset

    $frame_out = $header . $payload;

    $sum = 0;
    for ($i = 1; $i < strlen($frame_out); $i++) {
        $sum = ($sum + ord($frame_out[$i])) & 0xFF;
    }

    return $frame_out . chr($sum) . chr(0x15);
}

/**
 * Extract the Modbus RTU frame from a V5 response frame.
 * Modbus lives at bytes 25..(len-3) inclusive.
 */
function v5_extract_modbus(string $v5_frame): ?string
{
    $len = strlen($v5_frame);
    if ($len < 27) return null;
    $mb = substr($v5_frame, 25, $len - 27);
    return strlen($mb) >= 5 ? $mb : null;
}

/**
 * Scan a raw byte buffer and yield complete V5 frames.
 * Returns an array of [type_byte, raw_frame] tuples.
 */
function v5_scan_frames(string $buf): array
{
    $frames = [];
    $pos    = 0;
    $len    = strlen($buf);

    while ($pos < $len) {
        // Find next start magic
        if (ord($buf[$pos]) !== 0xA5) { $pos++; continue; }
        if ($pos + 3 > $len) break;

        $payload_len  = unpack('v', substr($buf, $pos + 1, 2))[1];
        $frame_len    = 13 + $payload_len;

        if ($pos + $frame_len > $len) break; // incomplete frame

        $frame       = substr($buf, $pos, $frame_len);
        $last        = ord($frame[$frame_len - 1]);

        if ($last !== 0x15) { $pos++; continue; } // bad end magic

        $frames[] = [ord($frame[4]), $frame];
        $pos += $frame_len;
    }

    return $frames;
}

// ---------------------------------------------------------------------------
// Modbus FC 0x03 response parser
// ---------------------------------------------------------------------------

function modbus_parse_holding_registers(string $mb_frame, int $start): ?array
{
    if (strlen($mb_frame) < 5) return null;
    $fc = ord($mb_frame[1]);

    if ($fc & 0x80) {
        $codes = [1=>'ILLEGAL_FUNCTION',2=>'ILLEGAL_DATA_ADDRESS',3=>'ILLEGAL_DATA_VALUE'];
        $ec    = ord($mb_frame[2]);
        printf("  Modbus exception: %s (code %d)\n", $codes[$ec] ?? 'UNKNOWN', $ec);
        return null;
    }

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
// Known register map — Deye 4-MPPT micro-inverter (confirmed on SUNM225G4)
// Source: StephanJoubert/home_assistant_solarman deye_4mppt.yaml
//         + empirical observations from live SUNM225G4 register dump
//
// Columns: [label, scale, unit, signed, note]
//   scale  = null  → display raw value only (string/enum/multi-register)
//   signed = true  → treat raw as int16 (two's complement)
// ---------------------------------------------------------------------------

const REGISTERS = [
    // --- Identity & firmware ---
    0x0003 => ['Inverter ID (word 1/5)',          null,   '',    false, ''],
    0x0004 => ['Inverter ID (word 2/5)',          null,   '',    false, ''],
    0x0005 => ['Inverter ID (word 3/5)',          null,   '',    false, ''],
    0x0006 => ['Inverter ID (word 4/5)',          null,   '',    false, ''],
    0x0007 => ['Inverter ID (word 5/5)',          null,   '',    false, ''],
    0x000C => ['Hardware Version',                null,   '',    false, ''],
    0x000D => ['DC Master Firmware Version',      null,   '',    false, ''],
    0x000E => ['AC Firmware Version',             null,   '',    false, ''],
    0x0010 => ['Rated Power',                     0.1,    'W',   false, ''],
    0x0012 => ['Communication Protocol Version',  null,   '',    false, ''],
    0x0015 => ['Startup Self-Check Time',         1,      's',   false, ''],

    // --- Protection limits ---
    0x001B => ['Grid Voltage Upper Limit',        0.1,    'V',   false, ''],
    0x001C => ['Grid Voltage Lower Limit',        0.1,    'V',   false, ''],
    0x001D => ['Grid Freq Upper Limit',           0.01,   'Hz',  false, ''],
    0x001E => ['Grid Freq Lower Limit',           0.01,   'Hz',  false, ''],
    0x0022 => ['Overfreq Load Reduction Start',   0.01,   'Hz',  false, ''],
    0x0023 => ['Overfreq Load Reduction',         1,      '%',   false, ''],

    // --- Settings ---
    0x0028 => ['Active Power Regulation',         1,      '%',   false, ''],
    0x002B => ['ON-OFF Enable',                   null,   '',    false, '0=OFF 1=ON'],
    0x002E => ['Island Protection Enable',        null,   '',    false, '0=Disabled 1=Enabled'],
    0x002F => ['Soft Start Enable',               null,   '',    false, '0=Disabled 1=Enabled'],
    0x0031 => ['Overfreq Load Shed Enable',       null,   '',    false, '0=Disabled 1=Enabled'],
    0x0032 => ['Power Factor Regulation',         0.1,    '',    true,  ''],

    // --- Status & energy ---
    0x003B => ['Running Status',                  null,   '',    false, '0=Standby 1=Self-check 2=Normal 3=Warning 4=Fault'],
    0x003C => ['Daily Production (total)',        0.1,    'kWh', false, ''],
    0x003F => ['Total Production (LOW word)',     null,   '',    false, 'combined with 0x0040 HIGH'],
    0x0040 => ['Total Production (HIGH word)',    null,   '',    false, 'combined with 0x003F LOW'],
    0x0041 => ['Daily Production PV1',            0.1,    'kWh', false, ''],
    0x0042 => ['Daily Production PV2',            0.1,    'kWh', false, ''],
    0x0043 => ['Daily Production PV3',            0.1,    'kWh', false, 'empirical — 4-MPPT'],
    0x0044 => ['Daily Production PV4',            0.1,    'kWh', false, 'empirical — 4-MPPT'],
    0x0045 => ['Total Production PV1',            0.1,    'kWh', false, ''],
    0x0047 => ['Total Production PV2',            0.1,    'kWh', false, ''],

    // --- Grid / AC output ---
    0x0049 => ['AC Voltage',                      0.1,    'V',   false, ''],
    0x004A => ['AC Apparent Power',               0.1,    'VA',  false, 'empirical'],
    0x004C => ['Grid Current',                    0.1,    'A',   true,  ''],
    0x004D => ['AC Active Power',                 0.1,    'W',   false, 'empirical'],
    0x004F => ['AC Frequency',                    0.01,   'Hz',  false, ''],
    0x0056 => ['Total AC Power (LOW word)',        null,   '',    false, 'combined with 0x0057 HIGH: *0.1 W'],
    0x0057 => ['Total AC Power (HIGH word)',       null,   '',    false, 'combined with 0x0056 LOW: *0.1 W'],
    0x005A => ['Radiator Temperature',            null,   '°C',  false, '(raw - 1000) * 0.01'],
    0x005B => ['AC Voltage module 2',             0.1,    'V',   false, 'empirical — 4-MPPT'],
    0x005C => ['AC Voltage module 3',             0.1,    'V',   false, 'empirical — 4-MPPT'],
    0x005D => ['AC Frequency module 2/3',         0.01,   'Hz',  false, 'empirical — 4-MPPT'],

    // --- DC inputs (all 4 PV channels) ---
    0x006D => ['PV1 Voltage',                     0.1,    'V',   false, ''],
    0x006E => ['PV1 Current',                     0.1,    'A',   false, ''],
    0x006F => ['PV2 Voltage',                     0.1,    'V',   false, ''],
    0x0070 => ['PV2 Current',                     0.1,    'A',   false, ''],
    0x0071 => ['PV3 Voltage',                     0.1,    'V',   false, ''],
    0x0072 => ['PV3 Current',                     0.1,    'A',   false, ''],
    0x0073 => ['PV4 Voltage',                     0.1,    'V',   false, ''],
    0x0074 => ['PV4 Current',                     0.1,    'A',   false, ''],
    0x0075 => ['Unknown (DC voltage?)',            0.1,    'V?',  false, 'empirical — possibly 5th channel or duplicate'],
    0x0076 => ['Unknown (DC voltage?)',            0.1,    'V?',  false, 'empirical — possibly 6th channel or duplicate'],
];

// ---------------------------------------------------------------------------
// Display helpers
// ---------------------------------------------------------------------------

function display_registers(array $regs): void
{
    // Computed multi-register values
    $total_prod = null;
    $total_power = null;
    if (isset($regs[0x003F], $regs[0x0040])) {
        // Registers stored as [LOW word, HIGH word]: 0x003F=LOW, 0x0040=HIGH
        $total_prod = (($regs[0x0040] << 16) | $regs[0x003F]) * 0.1;
    }
    if (isset($regs[0x0056], $regs[0x0057])) {
        // Registers stored as [LOW word, HIGH word]: 0x0056=LOW, 0x0057=HIGH
        $total_power = (($regs[0x0057] << 16) | $regs[0x0056]) * 0.1;
    }

    echo "\n=== Decoded Known Registers ===\n\n";

    foreach (REGISTERS as $addr => $info) {
        if (!isset($regs[$addr])) continue;
        [$label, $scale, $unit, $signed, $note] = $info;
        $raw = $regs[$addr];

        printf("  0x%04X (%3d)  %-42s  raw=%5d", $addr, $addr, $label, $raw);

        if ($addr === 0x005A) {
            $temp = ($raw - 1000) * 0.01;
            printf("  => %.2f °C", $temp);
        } elseif ($addr === 0x003B) {
            $statuses = [0=>'Standby',1=>'Self-check',2=>'Normal',3=>'Warning',4=>'Fault'];
            printf("  => %s", $statuses[$raw] ?? "Unknown({$raw})");
        } elseif ($addr === 0x003F && $total_prod !== null) {
            printf("  => %.1f kWh (combined LOW+HIGH)", $total_prod);
        } elseif ($addr === 0x0040 && $total_prod !== null) {
            // shown via 0x003F above
        } elseif ($addr === 0x0056 && $total_power !== null) {
            printf("  => %.1f W (combined LOW+HIGH)", $total_power);
        } elseif ($addr === 0x0057 && $total_power !== null) {
            // shown via 0x0056 above
        } elseif ($scale !== null) {
            $val = $signed && $raw > 32767 ? ($raw - 65536) * $scale : $raw * $scale;
            printf("  => %s %s", $val, $unit);
        } elseif ($note) {
            printf("  [%s]", $note);
        }

        echo "\n";
    }

    echo "\n=== All Non-Zero Registers (raw dump) ===\n\n";
    foreach ($regs as $addr => $raw) {
        if ($raw === 0) continue;
        $signed = $raw > 32767 ? $raw - 65536 : $raw;
        $label  = REGISTERS[$addr][0] ?? '';
        printf("  0x%04X (%3d)  raw=%5d (0x%04X)  signed=%6d  %s\n",
               $addr, $addr, $raw, $raw, $signed, $label);
    }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

if ($argc < 3) {
    echo "Usage: php solarman_test.php <inverter_ip> <logger_serial>\n";
    echo "   eg: php solarman_test.php 192.168.1.100 2712345678\n\n";
    echo "The logger serial is on the Wi-Fi stick label (not the inverter serial).\n";
    exit(1);
}

$ip     = $argv[1];
$serial = intval($argv[2]);
$port   = 8899;
$slave  = 1;
$start  = 0x0001;
$qty    = 0x007D; // 125 registers → covers the full documented range

echo "Connecting to {$ip}:{$port}  (logger serial: {$serial})\n";

$sock = @fsockopen($ip, $port, $errno, $errstr, 10);
if (!$sock) {
    echo "Connection failed: {$errstr} (errno {$errno})\n";
    exit(1);
}
stream_set_timeout($sock, 10);
echo "Connected.\n";

// Send the Modbus request immediately
$seq       = rand(1, 254);
$mb_req    = modbus_read_holding_registers($slave, $start, $qty);
$v5_req    = v5_encode($serial, $mb_req, $seq);

printf("Sending request: %d bytes (seq=%d, registers 0x%04X..0x%04X)\n",
       strlen($v5_req), $seq, $start, $start + $qty - 1);
fwrite($sock, $v5_req);

// Read incoming data for up to 10 seconds, respond to handshake/heartbeat frames
$buf        = '';
$modbus_raw = null;
$deadline   = microtime(true) + 10;

while (microtime(true) < $deadline) {
    $chunk = fread($sock, 4096);
    if ($chunk === false || $chunk === '') {
        // Nothing yet; keep waiting if within timeout
        if (feof($sock)) break;
        usleep(50000); // 50 ms
        continue;
    }
    $buf .= $chunk;

    $frames = v5_scan_frames($buf);
    foreach ($frames as [$type, $frame]) {
        printf("  Received V5 frame: type=0x%02X  len=%d\n", $type, strlen($frame));

        if ($type === 0x15) {
            // This is the response to our REQUEST (0x45 → response 0x15)
            // Verify sequence number (byte 5)
            if (ord($frame[5]) === ($seq & 0xFF)) {
                $modbus_raw = $frame;
            } else {
                printf("  Warning: seq mismatch (got %d, expected %d) — ignoring\n",
                       ord($frame[5]), $seq & 0xFF);
            }
        } elseif (in_array($type, [0x11, 0x17], true)) {
            // 0x11 = handshake response, 0x17 = heartbeat response — echoes of our own time responses
        } elseif (in_array($type, [0x41, 0x42, 0x43, 0x47, 0x48], true)) {
            // Logger-initiated frames that expect a time response
            $type_names = [0x41=>'HANDSHAKE',0x42=>'DATA',0x43=>'INFO',0x47=>'HEARTBEAT',0x48=>'REPORT'];
            printf("  Responding to %s frame\n", $type_names[$type] ?? "0x{$type}");
            $resp = v5_time_response($frame, $serial);
            fwrite($sock, $resp);
        }
    }

    if ($modbus_raw !== null) break;
}

fclose($sock);

if ($modbus_raw === null) {
    echo "\nNo valid Modbus response received within timeout.\n";
    if ($buf !== '') {
        echo "Raw received data (" . strlen($buf) . " bytes):\n";
        $hex = strtoupper(chunk_split(bin2hex($buf), 2, ' '));
        foreach (array_chunk(explode(' ', trim($hex)), 16) as $line) {
            echo '  ' . implode(' ', $line) . "\n";
        }
    }
    exit(1);
}

$mb_frame = v5_extract_modbus($modbus_raw);
if ($mb_frame === null) {
    echo "Could not extract Modbus frame from V5 response.\n";
    exit(1);
}

echo "Modbus response: " . strlen($mb_frame) . " bytes\n";

$registers = modbus_parse_holding_registers($mb_frame, $start);
if ($registers === null) {
    echo "Failed to parse Modbus response.\n";
    exit(1);
}

echo "Parsed " . count($registers) . " registers.\n";

display_registers($registers);

echo "\nDone.\n";
