# TODO — Deye Local API and Dashboard

Planned improvements, feature ideas, and community-growth tasks.

---

## Features

### MQTT Publisher
Publish inverter data to an MQTT broker so the project integrates naturally with Home Assistant, Node-RED, openHAB, and the broader home automation ecosystem. This is likely the single biggest discoverability win — people searching "Deye Home Assistant" would find the project.

Suggested topics:
- `deye/inverters/{serial}/power_now`
- `deye/inverters/{serial}/energy_today`
- `deye/inverters/{serial}/energy_total`
- `deye/inverters/{serial}/temperature`
- `deye/inverters/{serial}/pv/{n}/voltage` / `current` / `power`
- `deye/plant/power_total` (sum across all inverters)

Configuration (broker host/port/credentials) should be added to the admin panel settings page.

### Report Presets
Allow users to save the current report configuration (date ranges, time-of-day filters, group-by, chart type, inverter selection) as a named preset. A preset selector on the reports page would let them pick a saved preset and generate the report in one click.

Storage: presets can be saved as rows in a new `report_presets` table (name, JSON blob of parameters) and managed via the admin panel or directly on the reports page with save/delete controls.

### CSV / Excel Export from Reports
Add an "Export CSV" button to the reports page. The API already has all the data — just serialize the result as CSV and trigger a download. Zero new dependencies (generate the file in PHP). Useful for users who want to process data in spreadsheets.

### Alerts and Notifications via Telegram
Send a Telegram message when something unexpected happens during daylight hours. Possible triggers:
- An inverter has produced zero power for more than N minutes while the sun is up
- An inverter is unreachable (TCP connection fails)
- Production dropped more than X% compared to the same time yesterday

Since Telegram is already wired up, this is low-hanging fruit.

### Per-PV Input Filtering and Grouping in Reports
Extend the reports page to allow selecting, filtering, and grouping data by individual PV inputs (panels). Currently the reports aggregate across all panels of an inverter. Users should be able to:
- Select which PV inputs (e.g. PV1, PV2, PV3, PV4) to include in a query
- Group/aggregate by PV input number (one series or bar per panel)
- See per-panel voltage, current, power, daily energy, and lifetime energy in the report results and summary cards

Data already exists in `pvinputstats` linked to each poll cycle — this is primarily a UI and SQL query change in `reports.php` and `reports.html`.

### Per-Panel Dashboard View
A dedicated section on the dashboard (or a new page) showing each panel's current voltage, current, and power as a grid of cards. Useful for quickly spotting a shaded or underperforming panel. Data already exists in `pvinputstats`.

---

## Documentation

### User Manual
A comprehensive guide covering day-to-day usage of all three interfaces:
- Dashboard: reading the power chart, weather annotations, date navigation, dark mode
- Reports: building queries, choosing group-by, comparing two ranges, reading summary cards
- Admin panel: managing inverters, changing settings, language switching

Target audience: non-technical users who just want to use the app after someone else set it up.

File: `docs/USER_MANUAL.md`

### Comprehensive Setup Guide
A step-by-step installation guide that goes deeper than the README quickstart:
- Hardware requirements and tested environments (Raspberry Pi models, x86 mini-PCs)
- Network prerequisites (static IPs for inverters, firewall rules)
- Docker installation on Raspberry Pi OS / Ubuntu / Debian
- Full walkthrough of the setup wizard with annotated screenshots
- Configuring Telegram bot (creating the bot, getting the chat ID)
- Verifying data is flowing (checking the database, reading the dashboard)
- Common problems and fixes (port 8899 unreachable, wrong serial number, timezone issues)

File: `docs/SETUP_GUIDE.md`

### Compatible Hardware Table
A table in the README listing confirmed-working inverter models, which protocol they use (SolarmanV5 / HTTP fallback), and which data fields are available for each. Lowers the "will this work for me?" barrier for new users.

### Troubleshooting Section in README
Short answers to the most common failure modes:
- Port 8899 not reachable → check inverter firewall / use HTTP fallback
- Dashboard shows no data → check cron container logs
- Telegram report not sending → verify bot token and chat ID
- Wrong timezone on charts → set timezone in admin panel settings

---

## Community & Discoverability

### GitHub Repository Topics
Add topics to the GitHub repository to improve search visibility:
`deye`, `solar`, `solar-energy`, `micro-inverter`, `solarman`, `solarman-v5`, `home-automation`, `self-hosted`, `dashboard`, `raspberry-pi`

### Post on Reddit
- **r/selfhosted** — the primary audience for self-hosted Docker apps
- **r/homeassistant** — especially relevant once MQTT support is added
- **r/solar** — for users looking to monitor their own systems

### Submit to Awesome Lists
- [awesome-selfhosted](https://github.com/awesome-selfhosted/awesome-selfhosted) — curated list of self-hosted applications
- [awesome-homeassistant](https://github.com/frenck/awesome-home-assistant) — Home Assistant community resources

### Docker Hub Image
Publish a pre-built image to Docker Hub so users can pull without cloning the repo. Reduces the setup barrier and makes the project visible to people browsing Docker Hub.
