<?php
include 'functions.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? 'report';

if ($action === 'config') {
    $inv_res = pg_query($db, 'SELECT device_sn, friendly_name FROM inverters ORDER BY "order", device_sn');
    echo json_encode([
        'latitude'  => (float)$powerplant_latitude,
        'timezone'  => $powerplant_timezone,
        'inverters' => pg_fetch_all($inv_res) ?: [],
    ]);
    exit;
}

// Validate group
$group = $_GET['group'] ?? 'day';
if (!in_array($group, ['hour', 'halfday', 'day', 'week', 'month'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid group.']);
    exit;
}

// Per-range time of day filters (HH:MM; default = full day)
$a_time_from = trim($_GET['a_time_from'] ?? '00:00');
$a_time_to   = trim($_GET['a_time_to']   ?? '23:59');
$b_time_from = trim($_GET['b_time_from'] ?? '00:00');
$b_time_to   = trim($_GET['b_time_to']   ?? '23:59');

foreach ([$a_time_from, $a_time_to, $b_time_from, $b_time_to] as $t) {
    if (!preg_match('/^\d{2}:\d{2}$/', $t)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid time format. Use HH:MM.']);
        exit;
    }
}

// Inverter selection (comma-separated SNs; empty = all inverters)
$sanitize_inverters = fn($raw) => array_values(array_filter(
    array_map('trim', explode(',', (string)$raw)),
    fn($s) => $s !== '' && preg_match('/^[\w-]+$/', $s)
));
$a_inverters = $sanitize_inverters($_GET['a_inverters'] ?? '');
$b_inverters = $sanitize_inverters($_GET['b_inverters'] ?? '');

// Validate Range A
$a_from = trim($_GET['a_from'] ?? '');
$a_to   = trim($_GET['a_to']   ?? '');
if (!$a_from || !$a_to) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing a_from or a_to.']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $a_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $a_to)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD.']);
    exit;
}

$result = ['range_a' => fetch_report_data($a_from, $a_to, $group, $powerplant_timezone, $a_inverters, $a_time_from, $a_time_to)];

// Optional Range B (comparison)
$b_from = trim($_GET['b_from'] ?? '');
$b_to   = trim($_GET['b_to']   ?? '');
if ($b_from && $b_to) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $b_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $b_to)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format for range B. Use YYYY-MM-DD.']);
        exit;
    }
    $result['range_b'] = fetch_report_data($b_from, $b_to, $group, $powerplant_timezone, $b_inverters, $b_time_from, $b_time_to);
}

echo json_encode($result);

// ---------------------------------------------------------------------------

function fetch_report_data($from_date, $to_date, $group, $tz, $inverters = [], $time_from = '00:00', $time_to = '23:59') {
    global $db;

    // Convert plant-local date boundaries to UTC for DB queries
    $start = new DateTime($from_date . ' 00:00:00', new DateTimeZone($tz));
    $start->setTimezone(new DateTimeZone('UTC'));
    $end = new DateTime($to_date . ' 00:00:00', new DateTimeZone($tz));
    $end->modify('+1 day');
    $end->setTimezone(new DateTimeZone('UTC'));

    $start_utc = $start->format('Y-m-d H:i:sO');
    $end_utc   = $end->format('Y-m-d H:i:sO');

    // $1=tz  $2=start_utc  $3=end_utc  $4=time_from  $5=time_to  [$6=inverter array]
    $params = [$tz, $start_utc, $end_utc, $time_from, $time_to];

    $inv_clause = '';
    if (!empty($inverters)) {
        $literal  = '{' . implode(',', array_map(fn($s) => '"' . pg_escape_string($db, $s) . '"', $inverters)) . '}';
        $params[] = $literal;
        $inv_clause = "\n              AND device_sn = ANY(\$" . count($params) . "::text[])";
    }

    // All interpolated values below come from a validated whitelist — safe to use directly in SQL.

    $local_stats_cte = "
        local_stats AS (
            SELECT *
            FROM (
                SELECT
                    device_sn,
                    (created_at AT TIME ZONE \$1)::timestamp AS local_ts,
                    energy_today,
                    power_now,
                    radiator_temp
                FROM pvstatsdetail
                WHERE created_at >= \$2 AND created_at < \$3{$inv_clause}
            ) _raw
            WHERE (
                CASE WHEN \$4::time <= \$5::time
                    THEN date_trunc('minute', local_ts)::time BETWEEN \$4::time AND \$5::time
                    ELSE date_trunc('minute', local_ts)::time >= \$4::time
                      OR date_trunc('minute', local_ts)::time <= \$5::time
                END
            )
        )";

    if ($group === 'hour') {
        // Returns up to 24 rows, one per hour-of-day (0–23), aggregated across the entire date range.
        // Energy is MAX-MIN per device per day per hour-of-day, then summed across all inverters and days.
        // period_start is formatted as HH:00 text for clear JS labelling.
        $query = "
            WITH
            {$local_stats_cte},
            daily_energy AS (
                SELECT
                    device_sn,
                    local_ts::date AS local_day,
                    EXTRACT(hour FROM local_ts)::int AS hour_of_day,
                    GREATEST(0, MAX(energy_today) - MIN(energy_today)) AS kwh
                FROM local_stats
                GROUP BY device_sn, local_ts::date, hour_of_day
            ),
            period_energy AS (
                SELECT
                    LPAD(hour_of_day::text, 2, '0') || ':00' AS period_start,
                    ROUND(SUM(kwh)::numeric, 2) AS energy_kwh
                FROM daily_energy
                GROUP BY hour_of_day
            ),
            ts_power AS (
                SELECT
                    EXTRACT(hour FROM local_ts)::int AS hour_of_day,
                    local_ts,
                    SUM(power_now) AS total_now
                FROM local_stats
                GROUP BY hour_of_day, local_ts
            ),
            period_peak AS (
                SELECT
                    LPAD(hour_of_day::text, 2, '0') || ':00' AS period_start,
                    ROUND(MAX(total_now)::numeric) AS peak_power_w
                FROM ts_power
                GROUP BY hour_of_day
            ),
            period_weather AS (
                SELECT
                    LPAD(EXTRACT(hour FROM (created_at AT TIME ZONE \$1)::timestamp)::int::text, 2, '0') || ':00' AS period_start,
                    ROUND(AVG(temperature))::int AS avg_temp,
                    MODE() WITHIN GROUP (ORDER BY condition) AS dominant_condition
                FROM weather_info
                WHERE created_at >= \$2 AND created_at < \$3
                GROUP BY period_start
            ),
            period_radiator AS (
                SELECT
                    LPAD(EXTRACT(hour FROM local_ts)::int::text, 2, '0') || ':00' AS period_start,
                    ROUND(AVG(radiator_temp))::int AS avg_radiator_temp
                FROM local_stats
                WHERE radiator_temp IS NOT NULL
                GROUP BY EXTRACT(hour FROM local_ts)::int
            )
            SELECT
                pe.period_start,
                pe.energy_kwh,
                pp.peak_power_w,
                pw.avg_temp,
                pw.dominant_condition,
                pr.avg_radiator_temp
            FROM period_energy pe
            LEFT JOIN period_peak pp ON pe.period_start = pp.period_start
            LEFT JOIN period_weather pw ON pe.period_start = pw.period_start
            LEFT JOIN period_radiator pr ON pe.period_start = pr.period_start
            ORDER BY pe.period_start
        ";
    } elseif ($group === 'halfday') {
        // Returns exactly two rows: period_start = 'morning' | 'afternoon'
        // Energy is computed per device per day per half (MAX-MIN of cumulative energy_today),
        // then summed across all inverters and all days in the range.
        $query = "
            WITH
            {$local_stats_cte},
            daily_energy AS (
                SELECT
                    device_sn,
                    local_ts::date AS local_day,
                    CASE WHEN EXTRACT(hour FROM local_ts) < 12 THEN 'morning' ELSE 'afternoon' END AS half,
                    GREATEST(0, MAX(energy_today) - MIN(energy_today)) AS kwh
                FROM local_stats
                GROUP BY device_sn, local_ts::date, half
            ),
            period_energy AS (
                SELECT half AS period_start, ROUND(SUM(kwh)::numeric, 2) AS energy_kwh
                FROM daily_energy
                GROUP BY half
            ),
            ts_power AS (
                SELECT
                    CASE WHEN EXTRACT(hour FROM local_ts) < 12 THEN 'morning' ELSE 'afternoon' END AS period_start,
                    local_ts,
                    SUM(power_now) AS total_now
                FROM local_stats
                GROUP BY period_start, local_ts
            ),
            period_peak AS (
                SELECT period_start, ROUND(MAX(total_now)::numeric) AS peak_power_w
                FROM ts_power
                GROUP BY period_start
            ),
            period_weather AS (
                SELECT
                    CASE WHEN EXTRACT(hour FROM (created_at AT TIME ZONE \$1)::timestamp) < 12
                         THEN 'morning' ELSE 'afternoon' END AS period_start,
                    ROUND(AVG(temperature))::int AS avg_temp,
                    MODE() WITHIN GROUP (ORDER BY condition) AS dominant_condition
                FROM weather_info
                WHERE created_at >= \$2 AND created_at < \$3
                GROUP BY period_start
            ),
            period_radiator AS (
                SELECT
                    CASE WHEN EXTRACT(hour FROM local_ts) < 12 THEN 'morning' ELSE 'afternoon' END AS period_start,
                    ROUND(AVG(radiator_temp))::int AS avg_radiator_temp
                FROM local_stats
                WHERE radiator_temp IS NOT NULL
                GROUP BY period_start
            )
            SELECT
                pe.period_start,
                pe.energy_kwh,
                pp.peak_power_w,
                pw.avg_temp,
                pw.dominant_condition,
                pr.avg_radiator_temp
            FROM period_energy pe
            LEFT JOIN period_peak pp ON pe.period_start = pp.period_start
            LEFT JOIN period_weather pw ON pe.period_start = pw.period_start
            LEFT JOIN period_radiator pr ON pe.period_start = pr.period_start
            ORDER BY CASE pe.period_start WHEN 'morning' THEN 1 ELSE 2 END
        ";
    } else {
        // day / week / month: bucket by day, roll up to period.
        $period_trunc = $group;
        $period_cast  = 'date';

        $query = "
            WITH
            {$local_stats_cte},
            daily_energy AS (
                SELECT
                    device_sn,
                    local_ts::date AS bucket,
                    GREATEST(0, MAX(energy_today) - MIN(energy_today)) AS kwh
                FROM local_stats
                GROUP BY device_sn, local_ts::date
            ),
            daily_totals AS (
                SELECT bucket, SUM(kwh) AS energy_kwh
                FROM daily_energy
                GROUP BY bucket
            ),
            period_energy AS (
                SELECT
                    DATE_TRUNC('{$period_trunc}', bucket)::{$period_cast} AS period_start,
                    ROUND(SUM(energy_kwh)::numeric, 2) AS energy_kwh
                FROM daily_totals
                GROUP BY period_start
            ),
            ts_power AS (
                SELECT
                    DATE_TRUNC('{$period_trunc}', local_ts)::{$period_cast} AS period_start,
                    local_ts,
                    SUM(power_now) AS total_now
                FROM local_stats
                GROUP BY period_start, local_ts
            ),
            period_peak AS (
                SELECT period_start, ROUND(MAX(total_now)::numeric) AS peak_power_w
                FROM ts_power
                GROUP BY period_start
            ),
            period_weather AS (
                SELECT
                    DATE_TRUNC('{$period_trunc}', (created_at AT TIME ZONE \$1)::timestamp)::{$period_cast} AS period_start,
                    ROUND(AVG(temperature))::int AS avg_temp,
                    MODE() WITHIN GROUP (ORDER BY condition) AS dominant_condition
                FROM weather_info
                WHERE created_at >= \$2 AND created_at < \$3
                GROUP BY period_start
            ),
            period_radiator AS (
                SELECT
                    DATE_TRUNC('{$period_trunc}', local_ts)::{$period_cast} AS period_start,
                    ROUND(AVG(radiator_temp))::int AS avg_radiator_temp
                FROM local_stats
                WHERE radiator_temp IS NOT NULL
                GROUP BY period_start
            )
            SELECT
                pe.period_start,
                pe.energy_kwh,
                pp.peak_power_w,
                pw.avg_temp,
                pw.dominant_condition,
                pr.avg_radiator_temp
            FROM period_energy pe
            LEFT JOIN period_peak pp ON pe.period_start = pp.period_start
            LEFT JOIN period_weather pw ON pe.period_start = pw.period_start
            LEFT JOIN period_radiator pr ON pe.period_start = pr.period_start
            ORDER BY pe.period_start
        ";
    }

    $res  = pg_query_params($db, $query, $params);
    $rows = pg_fetch_all($res) ?: [];

    // Summary stats
    $total_energy        = 0.0;
    $peak_power          = 0.0;
    $temp_sum            = 0;
    $temp_count          = 0;
    $radiator_temp_sum   = 0;
    $radiator_temp_count = 0;
    $condition_counts    = [];

    foreach ($rows as $row) {
        $total_energy += (float)$row['energy_kwh'];
        if ((float)$row['peak_power_w'] > $peak_power) {
            $peak_power = (float)$row['peak_power_w'];
        }
        if ($row['avg_temp'] !== null) {
            $temp_sum += (int)$row['avg_temp'];
            $temp_count++;
        }
        if ($row['avg_radiator_temp'] !== null && $row['avg_radiator_temp'] !== '') {
            $radiator_temp_sum += (int)$row['avg_radiator_temp'];
            $radiator_temp_count++;
        }
        if ($row['dominant_condition']) {
            $c = $row['dominant_condition'];
            $condition_counts[$c] = ($condition_counts[$c] ?? 0) + 1;
        }
    }

    $d1        = new DateTime($from_date);
    $d2        = new DateTime($to_date);
    $day_count = (int)$d1->diff($d2)->days + 1;

    arsort($condition_counts);
    $dominant = array_key_first($condition_counts);

    return [
        'from'    => $from_date,
        'to'      => $to_date,
        'group'   => $group,
        'summary' => [
            'total_energy_kwh'   => round($total_energy, 2),
            'daily_avg_kwh'      => $day_count > 0 ? round($total_energy / $day_count, 2) : 0,
            'peak_power_w'       => (int)$peak_power,
            'avg_temp'           => $temp_count > 0 ? (int)round($temp_sum / $temp_count) : null,
            'avg_radiator_temp'  => $radiator_temp_count > 0 ? (int)round($radiator_temp_sum / $radiator_temp_count) : null,
            'dominant_condition' => $dominant ?: null,
        ],
        'data' => $rows,
    ];
}
