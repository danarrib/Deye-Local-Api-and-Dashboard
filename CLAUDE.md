# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Local API and dashboard for Deye solar micro-inverters. Fetches real-time power data from inverters on the local network, stores it in PostgreSQL, serves a web dashboard, and sends daily Telegram reports with generated charts.

## Build & Run

```bash
# Rebuild and restart all containers (tears down, removes images, rebuilds)
./rebuild.sh

# Or manually:
docker compose up -d
docker compose down
```

Services: PHP/Apache on port 8080, PostgreSQL on port 5442, cron runner (every 5 min).

No test suite or linter is configured.

## Architecture

**Stack:** Vanilla PHP (no framework), PostgreSQL, Bootstrap 5 frontend, Docker Compose.

**Key files:**
- `functions.php` — Central config (inverter IPs, credentials, timezone, Telegram token, lat/lon) and core logic (data fetching, sunrise/sunset calc, GD-based chart generation)
- `db_functions.php` — All PostgreSQL operations using `pg_*` functions; auto-creates schema on first run; includes gap-filling logic (`fix_incomplete_data`) that interpolates missing 5-minute data points
- `crontasks.php` — Entry point for the cron job; orchestrates data collection, weather fetch, and sunset-triggered Telegram reports
- `deye.php` — REST API endpoint for single-inverter queries (params: ipaddress, username, password)
- `overall.php` — JSON API returning aggregated data (power, weather, charts) for the dashboard
- `telegram_functions.php` — Telegram Bot API integration (sendMessage, sendPhoto)
- `weather_functions.php` — Open-Meteo API integration with WMO weather code mapping
- `index.html` — Dashboard UI (vanilla JS + Bootstrap, calls overall.php)

**Data flow:** Cron (every 5 min) → fetch from inverters via HTTP Basic Auth → store in PostgreSQL → fill gaps in time-series data (interpolation) → at sunset, generate PNG chart with GD library → send to Telegram.

**Database tables:** `pvstatsdetail` (time-series power data), `inverter_details` (inverter metadata), `weather_info` (weather conditions).

## Configuration

All config is hardcoded in `functions.php` (lines 6-24): timezone, plant name, lat/lon, Telegram credentials, and `$inverter_list` array. Database credentials are in `docker-compose.yml` as environment variables (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`).

## External APIs

- **Deye inverters:** HTTP Basic Auth to `http://{ip}/status.html`, parsed with regex
- **Open-Meteo:** Weather forecast API (no auth required)
- **Telegram Bot API:** Daily reports with chart images
