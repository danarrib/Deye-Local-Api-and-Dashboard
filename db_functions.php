<?php
    function setup_db() {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Create the table "pvstatsdetail" if it does not exist
        $query = "CREATE TABLE IF NOT EXISTS pvstatsdetail (
            id BIGSERIAL PRIMARY KEY NOT NULL,
            device_sn VARCHAR(30) NOT NULL,
            power_now NUMERIC NOT NULL,
            power_today NUMERIC NOT NULL,
            power_total NUMERIC NOT NULL,
            created_at TIMESTAMPTZ NOT NULL
        )";
        $result = pg_query($db, $query);

        // Create a index for the device_sn column
        $query = "CREATE INDEX IF NOT EXISTS device_sn_idx ON pvstatsdetail (device_sn)";
        $result = pg_query($db, $query);

        // Create a table for storing the inverter details
        $query = "CREATE TABLE IF NOT EXISTS inverter_details (
            id BIGSERIAL PRIMARY KEY NOT NULL,
            device_sn VARCHAR(30) NOT NULL,
            friendly_name VARCHAR(100) NOT NULL,
            created_at TIMESTAMPTZ NOT NULL,
            last_ip_address VARCHAR(45) NOT NULL
        )";
        $result = pg_query($db, $query);

        // Create a unique index for the device_sn column
        $query = "CREATE UNIQUE INDEX IF NOT EXISTS device_sn_uniq ON inverter_details (device_sn)";
        $result = pg_query($db, $query);

        // Add an "order" field to the inverter_details table if it doesn't already exists
        $query = "ALTER TABLE inverter_details ADD COLUMN IF NOT EXISTS \"order\" INT";
        $result = pg_query($db, $query);

        // Create a unique index for the device_sn column
        $query = "CREATE INDEX IF NOT EXISTS idx_pvstatsdetail_created_at ON pvstatsdetail (created_at)";
        $result = pg_query($db, $query);

        // Create a table for Weather information if it doesn't already exists
        // Columns: id (bigserial primary key), temperature (smallint, celsius), condition (varchar), created_at (timestamptz)
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
        $result = pg_query($db, $query);

        // Close the connection to the database
        pg_close($db);
    }

    function save_inverter_data($data) {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;

        // if power_now is 0, power_today is 0 and power_total is 0, just skip it
        if ($data['power_now'] == 0 && $data['power_today'] == 0 && $data['power_total'] == 0) {
            return;
        }

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Insert the data into the table "pvstatsdetail"
        $query = "INSERT INTO pvstatsdetail (device_sn, power_now, power_today, power_total, created_at) VALUES ($1, $2, $3, $4, $5)";
        $result = pg_query_params($db, $query, array($data['device_sn'], $data['power_now'], $data['power_today'], $data['power_total'], $data['timestamp']));

        // Upsert data into the inverter_details table
        $query = "INSERT INTO inverter_details (device_sn, friendly_name, created_at, last_ip_address, \"order\") VALUES ($1, $2, $3, $4, $5)
                  ON CONFLICT (device_sn) DO UPDATE SET friendly_name = $2, created_at = $3, last_ip_address = $4, \"order\" = $5";
        $result = pg_query_params($db, $query, array($data['device_sn'], $data['friendly_name'], $data['timestamp'], $data['last_ip_address'], $data['order']));

        // Close the connection to the database
        pg_close($db);
    }

    function get_today_latest_data() {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;
        global $powerplant_timezone, $reference_date;

        // Get Current Date at powerplant timezone
        $currentDate = new DateTime(null, new DateTimeZone($powerplant_timezone));
        $currentDate->setTime(0, 0, 0);
        $start_utc = clone $currentDate;
        $start_utc->setTimezone(new DateTimeZone('UTC'));
        $end_utc = clone $start_utc;
        $end_utc->modify('+1 day');

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Get the latest data for today
        $query = "SELECT DISTINCT ON (pd.device_sn) pd.*, idet.friendly_name FROM pvstatsdetail pd left join inverter_details idet on pd.device_sn = idet.device_sn WHERE pd.created_at at time zone 'UTC' between $1 AND $2 ORDER BY pd.device_sn, pd.created_at DESC;";

        $result = pg_query_params($db, $query, array($start_utc->format('Y-m-d H:i:sO'), $end_utc->format('Y-m-d H:i:sO')));

        // Fetch the result as an associative array
        $data = pg_fetch_all($result);

        // Close the connection to the database
        pg_close($db);

        // Return the data
        return $data;
    }

    function get_detailed_inverter_todays_data($sunrise, $sunset, $reference_date) {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;

        // Format reference date as "YYYY-MM-DD"
        $reference_date = $reference_date->format('Y-m-d');
        $ref_now = new DateTime(null, new DateTimeZone('UTC'));

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Get the latest data for today
        $query = "WITH time_intervals AS (
    SELECT generate_series(
        date_trunc('day', '$reference_date' AT TIME ZONE 'UTC'),  -- Start at midnight today
        date_trunc('day', '$reference_date' AT TIME ZONE 'UTC' + interval '1 day'),  -- End at midnight tomorrow
        interval '5 minutes'
    ) AS interval_start
)
SELECT ti.interval_start as time, idet.device_sn, pvsd.power_now, pvsd.power_today, idet.friendly_name, idet.order
FROM inverter_details idet
left join time_intervals ti ON true
LEFT JOIN LATERAL (
    SELECT DISTINCT ON (device_sn) *
    FROM pvstatsdetail pvd
    WHERE pvd.created_at BETWEEN ti.interval_start AND (ti.interval_start + interval '5 minutes')
    ORDER BY pvd.device_sn, pvd.created_at DESC  -- Explicitly pick the latest record
) pvsd ON pvsd.device_sn = idet.device_sn
ORDER BY ti.interval_start, idet.order,pvsd.device_sn, pvsd.created_at;";

        $result = pg_query($db, $query);

        // Fetch the result as an associative array
        $data = pg_fetch_all($result);

        // Close the connection to the database
        pg_close($db);

        // Remove the rows with device_sn as NULL and (time before sunrise or after sunset)
        $data = array_values(array_filter($data, function($row) use ($sunrise, $sunset, $ref_now) {
            $interval_start_utc = new DateTime($row['time'], new DateTimeZone('UTC'));
            return $interval_start_utc >= $sunrise && $interval_start_utc <= $sunset && $interval_start_utc <= $ref_now;
        }));

        // Update the data interval_start to add the timezone information
        foreach ($data as &$row) {
            $interval_start_utc = new DateTime($row['time'], new DateTimeZone('UTC'));
            $row['time'] = $interval_start_utc->format('Y-m-d\TH:i:s\Z');
        }

        // Return the data
        return $data;

    }

    function get_detailed_powerplant_todays_data($sunrise, $sunset, $reference_date) {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;

        // Format reference date as "YYYY-MM-DD"
        $reference_date = $reference_date->format('Y-m-d');

        // Get current time in UTC
        $ref_now = new DateTime(null, new DateTimeZone('UTC'));

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Get the latest data for today
        $query = "WITH time_intervals AS (
    SELECT generate_series(
        date_trunc('day', '$reference_date' AT TIME ZONE 'UTC'),  -- Start at midnight today
        date_trunc('day', '$reference_date' AT TIME ZONE 'UTC' + interval '1 day'),  -- End at midnight tomorrow
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

        $result = pg_query($db, $query);

        // Fetch the result as an associative array
        $data = pg_fetch_all($result);

        // Close the connection to the database
        pg_close($db);

        // Remove the rows with device_sn as NULL and (time before sunrise or after sunset)
        $data = array_values(array_filter($data, function($row) use ($sunrise, $sunset, $ref_now) {
            $interval_start_utc = new DateTime($row['time'], new DateTimeZone('UTC'));
            return $interval_start_utc >= $sunrise && $interval_start_utc <= $sunset && $interval_start_utc <= $ref_now;
        }));

        // Update the data interval_start to add the timezone information
        foreach ($data as &$row) {
            $interval_start_utc = new DateTime($row['time'], new DateTimeZone('UTC'));
            $row['time'] = $interval_start_utc->format('Y-m-d\TH:i:s\Z');

            // total_power_now is a number with 1 decimal digit, so convert it to float with 1 decimal digit if it is not NULL, otherwise set to 0
            $row['total_power_now'] = $row['total_power_now'] != NULL ? round(floatval($row['total_power_now']), 1) : null;
            
        }

        // Return the data
        return $data;

    }

    function getDailyTopEnergy($reference_date)
    {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;
        global $powerplant_timezone;

        $reference_date = $reference_date->format('Y-m-d');

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Get the latest data for today
        $query = "with total_energy as (
	with inverter_energy as (
		select ((pd.created_at at time zone 'UTC') at time zone '$powerplant_timezone')::date as reference_date, pd.device_sn, max(pd.power_today) as energy
		from pvstatsdetail pd
		group by reference_date, pd.device_sn
		order by reference_date, pd.device_sn
	)
	select ie.reference_date, SUM(energy) as total_energy from inverter_energy ie group by ie.reference_date order by ie.reference_date
)
select max(total_energy) as top_energy from total_energy where reference_date <> '$reference_date';";

        $result = pg_query($db, $query);

        // Get the first row of the result, and the value of the top_energy column
        $data = pg_fetch_assoc($result);
        $top_energy = $data['top_energy'] != NULL ? round(floatval($data['top_energy']), 1) : 0;

        // Close the connection to the database
        pg_close($db);
        return $top_energy;
    }

    function getMonthlyTopEnergy($reference_date)
    {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;
        global $powerplant_timezone;

        $reference_date = $reference_date->format('Y-m');

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Get the latest data for today
        $query = "with total_energy as (
	with inverter_energy as (
		select ((pd.created_at at time zone 'UTC') at time zone '$powerplant_timezone')::date as reference_date, pd.device_sn, max(pd.power_today) as energy
		from pvstatsdetail pd
		group by reference_date, pd.device_sn
	)
	select to_char(ie.reference_date, 'YYYY-MM') as year_month, SUM(energy) as total_energy from inverter_energy ie 
	group by year_month order by year_month
)
select max(total_energy) as top_energy from total_energy where year_month <> '$reference_date';";

        $result = pg_query($db, $query);

        // Get the first row of the result, and the value of the top_energy column
        $data = pg_fetch_assoc($result);
        $top_energy = $data['top_energy'] != NULL ? round(floatval($data['top_energy']), 1) : 0;

        // Close the connection to the database
        pg_close($db);
        return $top_energy;
    }

    function getMonthEnergy($reference_date)
    {
        global $db_host, $db_port, $db_name, $db_user, $db_pass, $powerplant_timezone;
        $reference_date = $reference_date->format('Y-m');
        $next_month_date = (new DateTime($reference_date . '-01'))->modify('+1 month')->format('Y-m');

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Get the latest data for today
        $query = "with total_energy as (
	with inverter_energy as (
		select ((pd.created_at at time zone 'UTC') at time zone '$powerplant_timezone')::date as reference_date, pd.device_sn, max(pd.power_today) as energy
		from pvstatsdetail pd
		where ((pd.created_at at time zone 'UTC') at time zone '$powerplant_timezone') between '$reference_date-01' and '$next_month_date-01'
		group by reference_date, pd.device_sn
	)
	select to_char(ie.reference_date, 'YYYY-MM') as year_month, SUM(energy) as total_energy from inverter_energy ie 
	group by year_month order by year_month
)
select max(total_energy) as total_energy from total_energy;";

        $result = pg_query($db, $query);

        // Get the first row of the result, and the value of the top_energy column
        $data = pg_fetch_assoc($result);
        $total_energy = $data['total_energy'] != NULL ? round(floatval($data['total_energy']), 1) : 0;

        // Close the connection to the database
        pg_close($db);
        return $total_energy;
    }

    function fix_incomplete_data($reference_date) {

        global $db_host, $db_port, $db_name, $db_user, $db_pass;
        global $powerplant_timezone;

        $reference_date = $reference_date->format('Y-m-d');

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        $query = "with pvdata as (
select		p.device_sn, 
			p.created_at, 
			p.power_now,
			p.power_today as energy_today,
			p.power_total as energy_total
from 		pvstatsdetail p 
where 		p.created_at between '$reference_date 00:00:00' and '$reference_date 23:59:59'
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
select pdr.device_sn, pdr.power_now, pdr.energy_today, pdr.energy_total, pdr.created_at from pvdataready pdr";

        $result = pg_query($db, $query);

        $cmdtuples = pg_affected_rows($result);

        // Close the connection to the database
        pg_close($db);

        return $cmdtuples;
    }

    function reprocess_fix_incomplete_data() {
        global $db_host, $db_port, $db_name, $db_user, $db_pass;
        global $powerplant_timezone;

        // Connect to the Postgres database "deye_data", using username and password
        $db = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_pass");

        // Get distinct dates from pvstatsdetail table
        $query = "SELECT DISTINCT ((created_at at time zone 'UTC') at time zone '$powerplant_timezone')::date as reference_date FROM pvstatsdetail ORDER BY reference_date;";
        $result = pg_query($db, $query);

        // Fetch all distinct dates
        $dates = pg_fetch_all($result);

        // Close the connection to the database
        pg_close($db);

        // Loop through each date and call fix_incomplete_data
        foreach ($dates as $date) {
            $ref_date = new DateTime($date['reference_date']);
            $affected_rows = fix_incomplete_data($ref_date);
            echo "<p>Processed date " . $date['reference_date'] . ", affected rows: " . $affected_rows . "</p>\n";
        }
    }




?>
