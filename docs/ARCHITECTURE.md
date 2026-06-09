# Architecture

Technical overview of how Deye Local API and Dashboard is built and how data flows through the system.

## Stack

| Layer | Technology |
|-------|-----------|
| Backend | Vanilla PHP (no framework, no Composer) |
| Database | PostgreSQL |
| Frontend | Bootstrap 5, Chart.js, vanilla JS |
| Runtime | Docker Compose — three containers |

## Containers

| Container | Role | Default port |
|-----------|------|-------------|
| `deye_php` | Apache/PHP — dashboard, reports, API, admin panel | 8080 |
| `deye_db` | PostgreSQL database | 5442 (host only) |
| `deye_cron` | Data collection every 5 min, daily Telegram report, nightly backup | — |

## Data flow

Every 5 minutes the cron container calls `crontasks.php`, which:

1. Polls each configured inverter (SolarmanV5 if available, HTTP fallback otherwise)
2. Stores the result in PostgreSQL (`pvstatsdetail` + `pvinputstats`)
3. Fetches weather data from Open-Meteo (if internet is available)
4. Runs gap-filling interpolation on today's data
5. At sunset, generates a PNG chart with GD and sends the daily Telegram report

## Inverter polling

### SolarmanV5 / Modbus (primary)

Every Deye micro-inverter's Wi-Fi logging stick exposes a TCP server on **port 8899** — the same port the Solarman mobile app uses for local communication. This project speaks the **SolarmanV5 protocol**, which wraps **Modbus RTU** frames, to read holding registers directly from the inverter.

A single Modbus FC `0x03` read of registers `0x0001`–`0x007D` returns all useful data in one round-trip:

- Power output now (W)
- Energy produced today and lifetime total (kWh)
- Inverter radiator temperature (°C)
- Per-panel voltage (V), current (A), power (W), daily and lifetime energy (kWh) — up to 4 panels (PV1–PV4)

This runs entirely on the local network with no cloud dependency. Protocol framing, register decoding, and Modbus RTU wrapping are implemented in `solarman_functions.php`. For the full protocol specification and register map, see [`poc/RESEARCH.md`](../poc/RESEARCH.md).

When you add or edit an inverter in the admin panel, the system automatically:

1. Fetches `status.html` to resolve the logger serial number
2. Opens a TCP connection to port 8899 to verify SolarmanV5 is reachable
3. Sets the `solarman_enabled` flag on the inverter record accordingly — no manual configuration needed

### HTTP fallback

For inverters where port 8899 is not reachable, the system falls back to parsing the `status.html` web interface over HTTP Basic Auth (factory default credentials: `admin` / `admin`).

This path provides a reduced set of fields — no temperature or per-panel data:

- Device and inverter serial numbers
- Firmware version
- Power now (W)
- Energy today and total (kWh)

## Key files

| File | Purpose |
|------|---------|
| `functions.php` | Central config and core logic: data fetching, sunrise/sunset calculation, GD chart generation, i18n helpers |
| `solarman_functions.php` | Full SolarmanV5/Modbus protocol stack |
| `db_functions.php` | All PostgreSQL operations; auto-creates and migrates schema on startup |
| `crontasks.php` | Cron entry point: poll inverters, fetch weather, trigger Telegram report at sunset |
| `overall.php` | JSON API — aggregated plant data used by the dashboard |
| `reports.php` | JSON API — historical report queries with flexible grouping |
| `deye.php` | JSON API — live single-inverter query over HTTP (no database) |
| `index.html` | Dashboard UI |
| `reports.html` | Report builder UI |
| `admin/` | Admin panel: setup wizard, inverter management, settings, logs |
| `lang/` | Translation files for 16 languages + `i18n.js` client-side engine |

## Database schema

### `pvstatsdetail` — time-series power data

One row per inverter per 5-minute poll cycle.

| Column | Type | Description |
|--------|------|-------------|
| `id` | serial | Primary key; referenced by `pvinputstats` |
| `device_sn` | text | Logger serial number |
| `power_now` | integer | Instantaneous output in Watts |
| `energy_today` | numeric | Energy produced today in kWh |
| `energy_total` | numeric | Lifetime energy in kWh |
| `radiator_temp` | smallint | Heat-sink temperature in °C (SolarmanV5 only; null otherwise) |
| `created_at` | timestamptz | Poll timestamp (UTC) |

### `pvinputstats` — per-panel data (SolarmanV5 only)

| Column | Type | Description |
|--------|------|-------------|
| `pvstatsdetail_id` | integer | FK to `pvstatsdetail.id` |
| `pv_number` | smallint | Panel index (1–4) |
| `voltage` | numeric | Panel voltage in V |
| `current` | numeric | Panel current in A |
| `power` | numeric | Panel power in W |
| `energy_today` | numeric | Panel energy today in kWh |
| `energy_total` | numeric | Panel lifetime energy in kWh (null for PV3/PV4 on some models — registers not yet confirmed) |

Panels with voltage = 0 (input not connected) are omitted entirely.

### `inverters`

| Column | Type | Description |
|--------|------|-------------|
| `device_sn` | text | Logger serial number (primary key) |
| `ip_address` | text | Inverter IP on the local network |
| `username` / `password` | text | HTTP Basic Auth credentials |
| `friendly_name` | text | Display label shown on the dashboard |
| `solarman_enabled` | boolean | Whether SolarmanV5 is available on port 8899 |
| `order` | integer | Display order on the dashboard |

### `weather_info`

One row per 5-minute cron cycle. Fields: `condition`, `temperature`, `humidity`, `wind_speed`, `wind_direction`, `created_at`.

### `powerplant`

Single-row table: plant name, timezone, latitude, longitude, Telegram bot token and chat ID, UI language.

## Gap filling

If a poll cycle is missed — because the host machine was restarting or there was a brief network issue — that interval is empty in the database and appears as a gap in the charts. `fix_incomplete_data()` in `db_functions.php` (also available via the admin panel's Tools tab) interpolates missing values between adjacent real data points. Gap-filled rows are flagged in the database and have no `pvinputstats` entries.

## Internationalisation

The UI language is stored in `powerplant.language`. `lang/i18n.js` handles all client-side translation via `data-i18n` attributes and the `t()` function. `php_t()` in `functions.php` mirrors this for server-side use in chart labels and Telegram messages. Chart fonts are selected per-script from TrueType files in `assets/` to support non-Latin scripts (Cyrillic, CJK, Arabic, Hebrew, Devanagari).
