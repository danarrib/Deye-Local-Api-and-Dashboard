<?php
    function setup_db() {
        global $db;

        // Create the table "pvstatsdetail" if it does not exist
        $query = "CREATE TABLE IF NOT EXISTS pvstatsdetail (
            id BIGSERIAL PRIMARY KEY NOT NULL,
            device_sn VARCHAR(30) NOT NULL,
            power_now NUMERIC NOT NULL,
            power_today NUMERIC NOT NULL,
            power_total NUMERIC NOT NULL,
            created_at TIMESTAMPTZ NOT NULL
        )";
        pg_query($db, $query);

        // Create a index for the device_sn column
        pg_query($db, "CREATE INDEX IF NOT EXISTS device_sn_idx ON pvstatsdetail (device_sn)");

        // Migrate inverter_details -> inverters, or create inverters table for fresh installs
        $check = pg_query($db, "SELECT to_regclass('public.inverter_details') IS NOT NULL AS exists_old,
                                       to_regclass('public.inverters') IS NOT NULL AS exists_new");
        $row = pg_fetch_assoc($check);

        if ($row['exists_old'] === 't' && $row['exists_new'] === 'f') {
            // Existing installation: rename and add new columns
            pg_query($db, "ALTER TABLE inverter_details RENAME TO inverters");
            pg_query($db, "ALTER TABLE inverters RENAME COLUMN last_ip_address TO ip_address");
            pg_query($db, "ALTER TABLE inverters ALTER COLUMN ip_address TYPE VARCHAR(100)");
            pg_query($db, "ALTER TABLE inverters ADD COLUMN IF NOT EXISTS username VARCHAR(50) NOT NULL DEFAULT 'admin'");
            pg_query($db, "ALTER TABLE inverters ADD COLUMN IF NOT EXISTS password VARCHAR(50) NOT NULL DEFAULT 'admin'");
            pg_query($db, "ALTER TABLE inverters ADD COLUMN IF NOT EXISTS \"order\" INT");
        } elseif ($row['exists_new'] === 'f') {
            // Fresh install: create inverters table directly
            $query = "CREATE TABLE IF NOT EXISTS inverters (
                id BIGSERIAL PRIMARY KEY NOT NULL,
                device_sn VARCHAR(30) NOT NULL,
                friendly_name VARCHAR(100) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                ip_address VARCHAR(100) NOT NULL,
                username VARCHAR(50) NOT NULL DEFAULT 'admin',
                password VARCHAR(50) NOT NULL DEFAULT 'admin',
                \"order\" INT
            )";
            pg_query($db, $query);
            pg_query($db, "CREATE UNIQUE INDEX IF NOT EXISTS device_sn_uniq ON inverters (device_sn)");
        } else {
            // inverters table already exists, ensure new columns are present
            pg_query($db, "ALTER TABLE inverters ADD COLUMN IF NOT EXISTS username VARCHAR(50) NOT NULL DEFAULT 'admin'");
            pg_query($db, "ALTER TABLE inverters ADD COLUMN IF NOT EXISTS password VARCHAR(50) NOT NULL DEFAULT 'admin'");
        }

        pg_query($db, "CREATE INDEX IF NOT EXISTS idx_pvstatsdetail_created_at ON pvstatsdetail (created_at)");

        // Create a table for Weather information if it doesn't already exist
        $query = "CREATE TABLE IF NOT EXISTS weather_info (
            id BIGSERIAL PRIMARY KEY NOT NULL,
            temperature SMALLINT NOT NULL,
            is_clear BOOLEAN NOT NULL,
            is_cloudy BOOLEAN NOT NULL,
            is_rainy BOOLEAN NOT NULL,
            is_stormy BOOLEAN NOT NULL,
            is_snowy BOOLEAN NOT NULL,
            is_foggy BOOLEAN NOT NULL,
            condition VARCHAR(30) NOT NULL,
            created_at TIMESTAMPTZ NOT NULL
        )";
        pg_query($db, $query);

        // Create the users table for admin authentication
        $query = "CREATE TABLE IF NOT EXISTS users (
            id BIGSERIAL PRIMARY KEY NOT NULL,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            user_type VARCHAR(20) NOT NULL DEFAULT 'admin',
            created_at TIMESTAMPTZ NOT NULL
        )";
        pg_query($db, $query);

        // Create the powerplant settings table
        $query = "CREATE TABLE IF NOT EXISTS powerplant (
            id BIGSERIAL PRIMARY KEY NOT NULL,
            name VARCHAR(100) NOT NULL,
            timezone VARCHAR(50) NOT NULL,
            latitude DOUBLE PRECISION NOT NULL,
            longitude DOUBLE PRECISION NOT NULL,
            telegram_token VARCHAR(255),
            telegram_chat_id VARCHAR(50)
        )";
        pg_query($db, $query);

        pg_query($db, "ALTER TABLE powerplant ADD COLUMN IF NOT EXISTS language VARCHAR(10) NOT NULL DEFAULT 'en'");

        // Create the logs table
        $query = "CREATE TABLE IF NOT EXISTS logs (
            id BIGSERIAL PRIMARY KEY NOT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            message TEXT NOT NULL,
            context JSONB
        )";
        pg_query($db, $query);

        pg_query($db, "CREATE INDEX IF NOT EXISTS idx_logs_created_at ON logs (created_at DESC)");

        // Migrate pvstatsdetail.created_at from TIMESTAMP WITHOUT TIME ZONE to TIMESTAMPTZ.
        // The column was originally created without timezone info but always stored UTC values
        // (PHP inserts Z-suffixed ISO strings). The USING clause reattaches the UTC declaration.
        pg_query($db, "DO \$\$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name  = 'pvstatsdetail'
                      AND column_name = 'created_at'
                      AND data_type   = 'timestamp without time zone'
                ) THEN
                    ALTER TABLE pvstatsdetail
                        ALTER COLUMN created_at TYPE TIMESTAMPTZ
                        USING created_at AT TIME ZONE 'UTC';
                END IF;
            END
        \$\$");
    }

    function save_inverter_data($data) {
        global $db;

        // if power_now is 0, power_today is 0 and power_total is 0, just skip it
        if ($data['power_now'] == 0 && $data['power_today'] == 0 && $data['power_total'] == 0) {
            return;
        }

        pg_query_params($db,
            "INSERT INTO pvstatsdetail (device_sn, power_now, power_today, power_total, created_at) VALUES ($1, $2, $3, $4, $5)",
            [$data['device_sn'], $data['power_now'], $data['power_today'], $data['power_total'], $data['timestamp']]
        );

        pg_query_params($db,
            "INSERT INTO inverters (device_sn, friendly_name, created_at, ip_address, \"order\") VALUES ($1, $2, $3, $4, $5)
             ON CONFLICT (device_sn) DO UPDATE SET friendly_name = $2, created_at = $3, ip_address = $4, \"order\" = $5",
            [$data['device_sn'], $data['friendly_name'], $data['timestamp'], $data['ip_address'], $data['order']]
        );
    }

    function get_today_latest_data($date = null) {
        global $db;
        global $powerplant_timezone;

        if ($date !== null) {
            $currentDate = new DateTime($date, new DateTimeZone($powerplant_timezone));
        } else {
            $currentDate = new DateTime(null, new DateTimeZone($powerplant_timezone));
        }
        $currentDate->setTime(0, 0, 0);
        $start_utc = clone $currentDate;
        $start_utc->setTimezone(new DateTimeZone('UTC'));
        $end_utc = clone $start_utc;
        $end_utc->modify('+1 day');

        $query = "SELECT DISTINCT ON (pd.device_sn) pd.*, idet.friendly_name FROM pvstatsdetail pd left join inverters idet on pd.device_sn = idet.device_sn WHERE pd.created_at between $1 AND $2 ORDER BY pd.device_sn, pd.created_at DESC;";
        $result = pg_query_params($db, $query, [$start_utc->format('Y-m-d H:i:sO'), $end_utc->format('Y-m-d H:i:sO')]);

        return pg_fetch_all($result) ?: [];
    }

    function get_weather_changes_for_date($sunrise, $sunset) {
        global $db;

        $sunrise_utc = (clone $sunrise)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sO');
        $sunset_utc = (clone $sunset)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sO');

        $query = "SELECT created_at, condition
                  FROM (
                      SELECT created_at, condition,
                             LAG(condition) OVER (ORDER BY created_at) AS prev_condition
                      FROM weather_info
                      WHERE created_at BETWEEN $1 AND $2
                  ) sub
                  WHERE condition <> prev_condition OR prev_condition IS NULL
                  ORDER BY created_at";

        $result = pg_query_params($db, $query, [$sunrise_utc, $sunset_utc]);
        $data = pg_fetch_all($result) ?: [];

        foreach ($data as &$row) {
            $dt = new DateTime($row['created_at'], new DateTimeZone('UTC'));
            $row['time'] = $dt->format('Y-m-d\TH:i:s\Z');
            unset($row['created_at']);
        }

        return $data;
    }

    function get_weather_for_date($sunrise, $sunset) {
        global $db;

        $sunrise_utc = (clone $sunrise)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sO');
        $sunset_utc = (clone $sunset)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sO');

        $query = "SELECT ROUND(AVG(temperature))::int AS temperature, condition, COUNT(*) AS condition_count
                  FROM weather_info
                  WHERE created_at BETWEEN $1 AND $2
                  GROUP BY condition
                  ORDER BY condition_count DESC
                  LIMIT 1";

        $result = pg_query_params($db, $query, [$sunrise_utc, $sunset_utc]);

        if (!$result || pg_num_rows($result) === 0) {
            return null;
        }

        return pg_fetch_assoc($result);
    }

    function get_detailed_inverter_todays_data($sunrise, $sunset, $reference_date) {
        global $db;

        $day_start_utc = (clone $reference_date)->setTimezone(new DateTimeZone('UTC'));
        $day_end_utc   = (clone $day_start_utc)->modify('+1 day');
        $ref_now = new DateTime(null, new DateTimeZone('UTC'));

        $query = "WITH time_intervals AS (
    SELECT generate_series(
        $1::timestamptz,
        $2::timestamptz,
        interval '5 minutes'
    ) AS interval_start
)
SELECT ti.interval_start as time, idet.device_sn, pvsd.power_now, pvsd.power_today, idet.friendly_name, idet.order
FROM inverters idet
left join time_intervals ti ON true
LEFT JOIN LATERAL (
    SELECT DISTINCT ON (device_sn) *
    FROM pvstatsdetail pvd
    WHERE pvd.created_at BETWEEN ti.interval_start AND (ti.interval_start + interval '5 minutes')
    ORDER BY pvd.device_sn, pvd.created_at DESC
) pvsd ON pvsd.device_sn = idet.device_sn
ORDER BY ti.interval_start, idet.order,pvsd.device_sn, pvsd.created_at;";

        $result = pg_query_params($db, $query, [
            $day_start_utc->format('Y-m-d H:i:sO'),
            $day_end_utc->format('Y-m-d H:i:sO'),
        ]);
        $data = pg_fetch_all($result) ?: [];

        $data = array_values(array_filter($data, function($row) use ($sunrise, $sunset, $ref_now) {
            $interval_start_utc = new DateTime($row['time'], new DateTimeZone('UTC'));
            return $interval_start_utc >= $sunrise && $interval_start_utc <= $sunset && $interval_start_utc <= $ref_now;
        }));

        foreach ($data as &$row) {
            $interval_start_utc = new DateTime($row['time'], new DateTimeZone('UTC'));
            $row['time'] = $interval_start_utc->format('Y-m-d\TH:i:s\Z');
        }

        return $data;
    }

    function get_detailed_powerplant_todays_data($sunrise, $sunset, $reference_date) {
        global $db;

        $day_start_utc = (clone $reference_date)->setTimezone(new DateTimeZone('UTC'));
        $day_end_utc   = (clone $day_start_utc)->modify('+1 day');
        $ref_now = new DateTime(null, new DateTimeZone('UTC'));

        $query = "WITH time_intervals AS (
    SELECT generate_series(
        $1::timestamptz,
        $2::timestamptz,
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

        $result = pg_query_params($db, $query, [
            $day_start_utc->format('Y-m-d H:i:sO'),
            $day_end_utc->format('Y-m-d H:i:sO'),
        ]);
        $data = pg_fetch_all($result) ?: [];

        $data = array_values(array_filter($data, function($row) use ($sunrise, $sunset, $ref_now) {
            $interval_start_utc = new DateTime($row['time'], new DateTimeZone('UTC'));
            return $interval_start_utc >= $sunrise && $interval_start_utc <= $sunset && $interval_start_utc <= $ref_now;
        }));

        foreach ($data as &$row) {
            $interval_start_utc = new DateTime($row['time'], new DateTimeZone('UTC'));
            $row['time'] = $interval_start_utc->format('Y-m-d\TH:i:s\Z');
            $row['total_power_now'] = $row['total_power_now'] != NULL ? round(floatval($row['total_power_now']), 1) : null;
        }

        return $data;
    }

    function getDailyTopEnergy($reference_date) {
        global $db;
        global $powerplant_timezone;

        $reference_date = $reference_date->format('Y-m-d');
        $tz = pg_escape_string($db, $powerplant_timezone);

        $query = "with total_energy as (
	with inverter_energy as (
		select (pd.created_at at time zone '$tz')::date as reference_date, pd.device_sn, max(pd.power_today) as energy
		from pvstatsdetail pd
		group by reference_date, pd.device_sn
		order by reference_date, pd.device_sn
	)
	select ie.reference_date, SUM(energy) as total_energy from inverter_energy ie group by ie.reference_date order by ie.reference_date
)
select max(total_energy) as top_energy from total_energy where reference_date <> $1::date;";

        $result = pg_query_params($db, $query, [$reference_date]);
        $data = pg_fetch_assoc($result);
        return $data['top_energy'] != NULL ? round(floatval($data['top_energy']), 1) : 0;
    }

    function getMonthlyTopEnergy($reference_date) {
        global $db;
        global $powerplant_timezone;

        $reference_date = $reference_date->format('Y-m');
        $tz = pg_escape_string($db, $powerplant_timezone);

        $query = "with total_energy as (
	with inverter_energy as (
		select (pd.created_at at time zone '$tz')::date as reference_date, pd.device_sn, max(pd.power_today) as energy
		from pvstatsdetail pd
		group by reference_date, pd.device_sn
	)
	select to_char(ie.reference_date, 'YYYY-MM') as year_month, SUM(energy) as total_energy from inverter_energy ie
	group by year_month order by year_month
)
select max(total_energy) as top_energy from total_energy where year_month <> $1;";

        $result = pg_query_params($db, $query, [$reference_date]);
        $data = pg_fetch_assoc($result);
        return $data['top_energy'] != NULL ? round(floatval($data['top_energy']), 1) : 0;
    }

    function getMonthEnergy($reference_date) {
        global $db;
        global $powerplant_timezone;

        $reference_date = $reference_date->format('Y-m');
        $next_month_date = (new DateTime($reference_date . '-01'))->modify('+1 month')->format('Y-m');
        $tz = pg_escape_string($db, $powerplant_timezone);

        $query = "with total_energy as (
	with inverter_energy as (
		select (pd.created_at at time zone '$tz')::date as reference_date, pd.device_sn, max(pd.power_today) as energy
		from pvstatsdetail pd
		where (pd.created_at at time zone '$tz') between $1::date and $2::date
		group by reference_date, pd.device_sn
	)
	select to_char(ie.reference_date, 'YYYY-MM') as year_month, SUM(energy) as total_energy from inverter_energy ie
	group by year_month order by year_month
)
select max(total_energy) as total_energy from total_energy;";

        $result = pg_query_params($db, $query, [$reference_date . '-01', $next_month_date . '-01']);
        $data = pg_fetch_assoc($result);
        return $data['total_energy'] != NULL ? round(floatval($data['total_energy']), 1) : 0;
    }

    function fix_incomplete_data($reference_date) {
        global $db;
        global $powerplant_timezone;

        $day_start = new DateTime($reference_date->format('Y-m-d') . ' 00:00:00', new DateTimeZone($powerplant_timezone));
        $day_start_utc = (clone $day_start)->setTimezone(new DateTimeZone('UTC'));
        $day_end_utc   = (clone $day_start_utc)->modify('+1 day');

        $query = "with pvdata as (
select		p.device_sn,
			p.created_at,
			p.power_now,
			p.power_today as energy_today,
			p.power_total as energy_total
from 		pvstatsdetail p
where 		p.created_at >= $1 and p.created_at < $2
order by	device_sn, created_at
),
pvgapbegin as (
select 		pv.device_sn,
			pv.created_at as gap_begin,
			pv.power_now  as power_now_begin,
			pv.energy_today as energy_today_begin,
			pv.energy_total as energy_total_begin
from 		pvdata pv
where 		not exists(	select 	1
						from 	pvdata pv2
						where 	pv2.device_sn = pv.device_sn
						and 	pv2.created_at between pv.created_at + interval '1 minute' and pv.created_at + interval '6 minutes')
),
pvgaps as (
select 		pgb.device_sn,
			pgb.gap_begin,
			pgb.power_now_begin,
			pgb.energy_today_begin,
			pgb.energy_total_begin,
			min(pge.created_at) as gap_end,
			round(extract(epoch from min(pge.created_at) - pgb.gap_begin) / 60) as gap_minutes,
			round(round(extract(epoch from min(pge.created_at) - pgb.gap_begin) / 60) / 5) - 1 as qty_fillers
from 		pvgapbegin pgb
inner join 	pvdata pge
	on 		pgb.device_sn = pge.device_sn
		and pge.created_at > pgb.gap_begin
group by 	pgb.device_sn, pgb.gap_begin, pgb.power_now_begin, pgb.energy_today_begin, pgb.energy_total_begin
order by 	pgb.device_sn, pgb.gap_begin, pgb.power_now_begin, pgb.energy_today_begin, pgb.energy_total_begin
), pvalldata as (
select 		pvg.device_sn,
			pvg.gap_begin,
			pvg.power_now_begin,
			pvg.energy_today_begin,
			pvg.energy_total_begin,
			pvg.gap_end,
			pd.power_now as power_now_end,
			pd.energy_today as energy_today_end,
			pd.energy_total as energy_total_end,
			pvg.qty_fillers
from 		pvgaps pvg
inner join	pvdata pd on pd.device_sn = pvg.device_sn and pd.created_at = pvg.gap_end
), pvdataready as (
select		pvad.device_sn,
			round((pvad.power_now_begin + pvad.power_now_end) / 2) as power_now,
			round(pvad.energy_today_begin + (((pvad.energy_today_end - pvad.energy_today_begin) / (pvad.qty_fillers+1)) * gs),1) as energy_today,
			round(pvad.energy_total_begin + (((pvad.energy_total_end - pvad.energy_total_begin) / (pvad.qty_fillers+1)) * gs),1) as energy_total,
			pvad.gap_begin + ((5*gs) * interval '1 minute') as created_at
from 		pvalldata pvad
cross join lateral generate_series(1, pvad.qty_fillers) gs
)
insert into pvstatsdetail (device_sn, power_now, power_today, power_total, created_at)
select pdr.device_sn, pdr.power_now, pdr.energy_today, pdr.energy_total, pdr.created_at from pvdataready pdr
where not exists (
    select 1 from pvstatsdetail p
    where p.device_sn = pdr.device_sn
    and p.created_at = pdr.created_at
)";

        $result = pg_query_params($db, $query, [
            $day_start_utc->format('Y-m-d H:i:sO'),
            $day_end_utc->format('Y-m-d H:i:sO'),
        ]);
        return pg_affected_rows($result);
    }

    function reprocess_fix_incomplete_data() {
        global $db;
        global $powerplant_timezone;

        $tz = pg_escape_string($db, $powerplant_timezone);

        $query = "SELECT DISTINCT (created_at at time zone '$tz')::date as reference_date FROM pvstatsdetail ORDER BY reference_date;";
        $result = pg_query($db, $query);
        $dates = pg_fetch_all($result);

        foreach ($dates as $date) {
            $ref_date = new DateTime($date['reference_date']);
            $affected_rows = fix_incomplete_data($ref_date);
            echo "<p>Processed date " . $date['reference_date'] . ", affected rows: " . $affected_rows . "</p>\n";
        }
    }

    function purge_old_logs($days = 30) {
        global $db;
        $days = intval($days);
        pg_query($db, "DELETE FROM logs WHERE created_at < NOW() - INTERVAL '$days days'");
    }

    function resolve_pending_inverter($device_sn, $ip_address) {
        global $db;
        pg_query_params($db,
            "UPDATE inverters SET device_sn = $1 WHERE ip_address = $2 AND device_sn LIKE 'PENDING_%'",
            [$device_sn, $ip_address]
        );
    }

?>
