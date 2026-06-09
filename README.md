# Deye Local API and Dashboard

A self-hosted dashboard and local API for Deye solar micro-inverters. Polls your inverters directly over the local network every 5 minutes, stores the data in PostgreSQL, and makes it available through a live dashboard, a report builder, and a JSON API.

<img alt="Dashboard screenshot" src="docs/screenshots/dashboard.png" />

## What it does

- **Live dashboard** — combined plant power output throughout the day, per-inverter breakdowns, per-panel (PV input) stacked charts, and weather condition overlays. Navigate to any past date with prev/next buttons or a date picker.
- **Report builder** — aggregate production by hour, day, week, month, or season; compare any two date ranges side by side; filter by time of day or individual inverter.

  <img alt="Reports screenshot" src="docs/screenshots/reports.png" />

- **Daily Telegram reports** — a production chart and energy total sent automatically to a chat or group at sunset each day.
- **JSON API** — read live and historical data from any application on your network, including Home Assistant, Node-RED, and Grafana.
- **SolarmanV5 protocol** — direct Modbus/TCP polling via port 8899 for richer data: inverter temperature and per-panel voltage, current, power, and energy.
- **16-language UI** — including full RTL support for Arabic and Hebrew; language is stored system-wide and switchable from any page.

## Compatible hardware

Any Solarman-compatible Deye micro-inverter with a Wi-Fi logging stick should work. See [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md) for the full list of tested and likely-compatible models.

## Quick start

You need Docker, Docker Compose, and Git on a Linux machine on the same local network as your inverters. A Raspberry Pi 3B or newer works well.

1. Clone the repository:
   ```bash
   git clone https://github.com/danarrib/Deye-Local-Api-and-Dashboard.git
   cd Deye-Local-Api-and-Dashboard
   ```
2. Start the stack:
   ```bash
   docker compose up -d
   ```
3. Open `http://localhost:8080/admin/` and follow the setup wizard to create an admin account, configure your plant settings, and add your inverters.

Data collection starts automatically every 5 minutes once an inverter is added.

For full prerequisites, network requirements, Telegram setup, and configuration options, see the [User Manual](docs/USERMANUAL.md).

## Documentation

- [User Manual](docs/USERMANUAL.md) — installation, configuration, all features, troubleshooting, and API reference
- [Architecture](docs/ARCHITECTURE.md) — how the SolarmanV5 protocol works, data flow, stack overview, and database schema
- [Compatibility](docs/COMPATIBILITY.md) — tested and likely-compatible inverter models

## Privacy

This project does **not** disconnect your inverter from the Solarman cloud. It runs alongside the existing connection without conflict, so the official Solarman app continues to work normally.

If removing the cloud connection is important to you, see the [Deye Microinverter Cloud-free](https://github.com/Hypfer/deye-microinverter-cloud-free) project.
