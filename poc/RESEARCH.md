# SolarmanV5 / Modbus Research Notes

> **Purpose:** Document the findings from the PoC (`solarman_test.php`) so we can implement a proper polling routine that fetches extended inverter data — temperature, per-panel voltage/current, per-panel daily energy — alongside the existing `status.html` data.

---

## Background

The current polling approach (`deye.php`) makes an HTTP Basic Auth request to `http://{ip}/status.html` and parses the HTML response with regex. It yields only six fields:

| Field | Notes |
|---|---|
| `inverter_sn` | Inverter serial number |
| `power_now` | Current AC output (W) |
| `power_today` | Energy produced today (kWh) |
| `power_total` | Cumulative energy (kWh) |
| `device_sn` | Logger serial number |
| `device_ver` | Firmware version string |

Missing data the inverter actually exposes: temperature, per-panel voltage/current, per-panel daily energy, grid frequency, grid voltage, grid current, apparent power, running status.

---

## How the New Approach Works

Every Deye micro-inverter's Wi-Fi logging stick (the small module that connects the inverter to the local network and to the Solarman cloud) exposes a **TCP server on port 8899**. This is the same port the Solarman mobile app uses for local communication. It speaks the **SolarmanV5 protocol**, which wraps **Modbus RTU** frames.

This approach:
- Works entirely on the local network — no cloud dependency
- Runs **alongside** Solarman; no configuration changes to the inverter are needed
- Is purely a read operation (polling Modbus input/holding registers)
- Has the same latency characteristics as the current HTTP polling — connect, request, response, disconnect

The logger serial number (printed on the Wi-Fi stick, visible in the Solarman app under device info) is required for the V5 frame header. It is **different** from the inverter serial number.

---

## SolarmanV5 Frame Format

All communication is over a persistent (or short-lived) TCP connection to port 8899. Frames are binary with this layout:

```
┌──────────────────────────────────────────────────────────────────┐
│ 0xA5         │ Start magic (1 byte)                              │
│ payload_len  │ Payload length, uint16 little-endian (2 bytes)    │
│ 0x10         │ Control code suffix (1 byte, always 0x10)         │
│ control      │ Control code (1 byte)                             │
│ seq          │ Sequence number, uint16 little-endian (2 bytes)   │
│ serial       │ Logger serial, uint32 little-endian (4 bytes)     │
├──────────────────────────────────────────────────────────────────┤
│              │ Payload (payload_len bytes)                        │
│              │   For REQUEST frames:                              │
│              │     0x02           frametype (1 byte)             │
│              │     0x0000         sensortype (2 bytes)           │
│              │     0x00000000     deliverytime (4 bytes)         │
│              │     0x00000000     powerontime (4 bytes)          │
│              │     0x00000000     offsettime (4 bytes)           │
│              │     <modbus frame> (variable)                     │
├──────────────────────────────────────────────────────────────────┤
│ checksum     │ Sum of all bytes from index 1 to end of payload,  │
│              │ & 0xFF (1 byte)                                   │
│ 0x15         │ End magic (1 byte)                                │
└──────────────────────────────────────────────────────────────────┘
```

**Total overhead:** 11-byte header + 15-byte payload prefix + 2-byte footer = 28 bytes around the Modbus RTU frame.

### Control Codes

| Code (request) | Code (response) | Meaning |
|---|---|---|
| `0x41` | `0x11` | Handshake |
| `0x42` | `0x12` | Data push (logger → cloud) |
| `0x43` | `0x13` | Info |
| `0x45` | `0x15` | **Modbus request/response** |
| `0x47` | `0x17` | Heartbeat |

The response control code is always `request - 0x30`.

### Checksum

Sum of all bytes **excluding** the start magic (`0xA5`) and the final two bytes (checksum itself + end magic `0x15`). Take the result modulo 256.

### Modbus RTU Position in Response Frame

The Modbus RTU response frame starts at **byte offset 25** and ends at `len - 3` (inclusive), i.e. everything before the checksum + end magic.

---

## Modbus RTU

The inverter exposes **holding registers** (Modbus function code `0x03`). No input registers (FC `0x04`) are used.

### Read Holding Registers Request (FC 0x03)

```
┌────────────────────────────────────────┐
│ slave_id    │ 1 byte  (always 0x01)   │
│ 0x03        │ 1 byte  function code   │
│ start_addr  │ 2 bytes big-endian      │
│ quantity    │ 2 bytes big-endian      │
│ CRC         │ 2 bytes little-endian   │
└────────────────────────────────────────┘
```

### CRC

Standard **CRC-16/MODBUS** (polynomial `0xA001`, initial value `0xFFFF`).

### Response

```
┌────────────────────────────────────────────────────────┐
│ slave_id   │ 1 byte                                    │
│ 0x03       │ 1 byte  function code                     │
│ byte_count │ 1 byte  (quantity × 2)                    │
│ data       │ byte_count bytes (registers, big-endian)  │
│ CRC        │ 2 bytes little-endian                     │
└────────────────────────────────────────────────────────┘
```

Each register value is a **16-bit unsigned big-endian integer**.

---

## Register Map

### Overview

All documented registers are **holding registers (FC 0x03)**. A single request for the range `0x0001`–`0x007D` (125 registers) retrieves all useful data in one round-trip.

Sources:
- `deye_2mppt.yaml` and `deye_4mppt.yaml` from [StephanJoubert/home_assistant_solarman](https://github.com/StephanJoubert/home_assistant_solarman)
- Empirical observations from a live **SUNM225G4** (4-input, 4-MPPT micro-inverter, rated 2250 W)

### Identity & Configuration

| Address | Decimal | Name | Scale | Unit | Notes |
|---|---|---|---|---|---|
| `0x0003`–`0x0007` | 3–7 | Inverter ID | — | — | 5 × uint16, decode as ASCII pairs |
| `0x000C` | 12 | Hardware Version | — | — | |
| `0x000D` | 13 | DC Master Firmware Version | — | — | |
| `0x000E` | 14 | AC Firmware Version | — | — | |
| `0x000F` | 15 | Unknown (mirrors `0x000D`) | — | — | |
| `0x0010` | 16 | Rated Power | ×0.1 | W | |
| `0x0012` | 18 | Communication Protocol Version | — | — | |
| `0x0015` | 21 | Startup Self-Check Time | ×1 | s | |

### Protection Limits

| Address | Decimal | Name | Scale | Unit |
|---|---|---|---|---|
| `0x001B` | 27 | Grid Voltage Upper Limit | ×0.1 | V |
| `0x001C` | 28 | Grid Voltage Lower Limit | ×0.1 | V |
| `0x001D` | 29 | Grid Frequency Upper Limit | ×0.01 | Hz |
| `0x001E` | 30 | Grid Frequency Lower Limit | ×0.01 | Hz |
| `0x0022` | 34 | Overfreq Load Reduction Start | ×0.01 | Hz |
| `0x0023` | 35 | Overfreq Load Reduction | ×1 | % |

### Settings

| Address | Decimal | Name | Scale | Unit | Notes |
|---|---|---|---|---|---|
| `0x0028` | 40 | Active Power Regulation | ×1 | % | |
| `0x002B` | 43 | ON/OFF Enable | — | — | 0=OFF, 1=ON |
| `0x002E` | 46 | Island Protection Enable | — | — | 0=Disabled, 1=Enabled |
| `0x002F` | 47 | Soft Start Enable | — | — | 0=Disabled, 1=Enabled |
| `0x0031` | 49 | Overfreq Load Shed Enable | — | — | 0=Disabled, 1=Enabled |
| `0x0032` | 50 | Power Factor Regulation | ×0.1 | — | signed int16 |

### Inverter Status & Energy

| Address | Decimal | Name | Scale | Unit | Notes |
|---|---|---|---|---|---|
| `0x003B` | 59 | **Running Status** | — | — | 0=Standby, 1=Self-check, 2=Normal, 3=Warning, 4=Fault |
| `0x003C` | 60 | **Daily Production (total)** | ×0.1 | kWh | All panels combined |
| `0x003F` | 63 | Total Production (LOW word) | — | — | See note below |
| `0x0040` | 64 | Total Production (HIGH word) | — | — | See note below |
| `0x0041` | 65 | Daily Production PV1 | ×0.1 | kWh | |
| `0x0042` | 66 | Daily Production PV2 | ×0.1 | kWh | |
| `0x0043` | 67 | Daily Production PV3 | ×0.1 | kWh | Empirical — 4-MPPT only |
| `0x0044` | 68 | Daily Production PV4 | ×0.1 | kWh | Empirical — 4-MPPT only |
| `0x0045` | 69 | Total Production PV1 | ×0.1 | kWh | |
| `0x0047` | 71 | Total Production PV2 | ×0.1 | kWh | |

**32-bit total production:** registers `0x003F` (LOW word) and `0x0040` (HIGH word) form a 32-bit value as `(HIGH << 16) | LOW`, then multiply by 0.1 to get kWh. In practice, for inverters with under ~6553 kWh lifetime production, the HIGH word is 0 and the LOW word alone suffices.

### Grid / AC Output

| Address | Decimal | Name | Scale | Unit | Notes |
|---|---|---|---|---|---|
| `0x0049` | 73 | **AC Voltage** | ×0.1 | V | |
| `0x004A` | 74 | AC Apparent Power | ×0.1 | VA | Empirical |
| `0x004C` | 76 | **Grid Current** | ×0.1 | A | signed int16 |
| `0x004D` | 77 | AC Active Power | ×0.1 | W | Empirical |
| `0x004F` | 79 | **AC Frequency** | ×0.01 | Hz | |
| `0x0056` | 86 | Total AC Output Power (LOW word) | — | — | See note below |
| `0x0057` | 87 | Total AC Output Power (HIGH word) | — | — | See note below |

**32-bit total AC power:** same LOW/HIGH word convention as total production. `(HIGH << 16) | LOW`, then ×0.1 → W. HIGH is almost always 0 for residential micro-inverters (would need to exceed 6553.5 W).

### Temperature

| Address | Decimal | Name | Scale | Unit | Formula |
|---|---|---|---|---|---|
| `0x005A` | 90 | **Radiator Temperature** | — | °C | `(raw − 1000) × 0.01` |

Example: raw value `5890` → `(5890 − 1000) × 0.01` = **48.90 °C**

### Per-Module AC (4-MPPT devices)

These appear to be per-module readings for the second and third physical inverter modules inside a multi-module device (e.g. SUNM225G4 = 4 modules):

| Address | Decimal | Name | Scale | Unit | Notes |
|---|---|---|---|---|---|
| `0x005B` | 91 | AC Voltage module 2 | ×0.1 | V | Empirical |
| `0x005C` | 92 | AC Voltage module 3 | ×0.1 | V | Empirical |
| `0x005D` | 93 | AC Frequency module 2/3 | ×0.01 | Hz | Empirical |

### DC Inputs (PV panels)

| Address | Decimal | Name | Scale | Unit | Notes |
|---|---|---|---|---|---|
| `0x006D` | 109 | **PV1 Voltage** | ×0.1 | V | |
| `0x006E` | 110 | **PV1 Current** | ×0.1 | A | |
| `0x006F` | 111 | **PV2 Voltage** | ×0.1 | V | |
| `0x0070` | 112 | **PV2 Current** | ×0.1 | A | |
| `0x0071` | 113 | **PV3 Voltage** | ×0.1 | V | 4-MPPT only |
| `0x0072` | 114 | **PV3 Current** | ×0.1 | A | 4-MPPT only |
| `0x0073` | 115 | **PV4 Voltage** | ×0.1 | V | 4-MPPT only |
| `0x0074` | 116 | **PV4 Current** | ×0.1 | A | 4-MPPT only |
| `0x0075` | 117 | Unknown DC voltage | ×0.1 | V? | Empirical — purpose unclear |
| `0x0076` | 118 | Unknown DC voltage | ×0.1 | V? | Empirical — purpose unclear |

Registers `0x0075` and `0x0076` consistently hold values in the panel voltage range (~37–40 V) but do not track any individual PV channel exactly. Possibly averaged or filtered readings, or per-module DC bus voltages.

---

## Live Test Results (SUNM225G4, 2026-05-28)

```
Inverter IP:       192.168.15.201
Logger serial:     3898318653
Port:              8899
Rated Power:       2250 W (4 × 225 W inputs)
Running Status:    Normal

Radiator Temp:     48.90 °C

AC Voltage:        237.0 V
Grid Current:      2.4 A
AC Frequency:      60.14 Hz
Total AC Power:    601.3 W

Daily Production:  4.5 kWh
Total Production:  4041.6 kWh

PV1:  38.0 V / 4.7 A
PV2:  37.7 V / 3.8 A
PV3:  37.1 V / 4.7 A
PV4:  36.9 V / 3.6 A

Daily PV1: 1.4 kWh  Total PV1: 1074.6 kWh
Daily PV2: 0.8 kWh  Total PV2:  928.1 kWh
Daily PV3: 1.4 kWh
Daily PV4: 0.8 kWh
```

---

## Protocol Behaviour Notes

- **No handshake required.** Connecting and immediately sending a REQUEST frame (FC `0x45`) gets a valid response on the first try. The logger does not require a prior handshake exchange before serving Modbus requests.
- **Unsolicited frames.** The logger may send handshake (`0x41`) or heartbeat (`0x47`) frames at any time, including interleaved with a Modbus response. These expect a time-sync response (see `v5_time_response()` in `solarman_test.php`). For a fire-and-forget poll these can be ignored; for a persistent connection they should be handled.
- **Frame identification.** The response to a REQUEST is identified by byte 4 = `0x15` and the sequence number at byte 5 matching what was sent.
- **One request covers everything.** A single FC `0x03` read of addresses `0x0001`–`0x007D` (125 registers, the Modbus maximum per read) returns all the data above in one round-trip (~280-byte response).
- **Solarman co-existence.** Port 8899 accepts multiple simultaneous connections. The Solarman app and this polling routine can both run without interference.

---

## Inverter Discovery Flow

When a user adds a new inverter through the admin panel, the logger serial number must be obtained before the SolarmanV5 connection can be used. The two-step discovery flow is:

### Step 1 — HTTP fetch (already implemented)

`GET http://{ip}/status.html` with HTTP Basic Auth (username + password provided by the user).

The response is an HTML page containing JavaScript variable assignments. The relevant fields are extracted with regex:

| JS variable | Regex | Stored as | Value |
|---|---|---|---|
| `webdata_sn` | `/webdata_sn = "(.*?)"/` | `inverter_sn` | Inverter's own serial (e.g. `240814032D`) |
| `cover_mid` | `/cover_mid = "(.*?)"/` | `device_sn` | **Logger serial** (e.g. `3898318653`) |
| `cover_ver` | `/cover_ver = "(.*?)"/` | `device_ver` | Firmware version string |

`cover_mid` — the value behind `device_sn` in the database — is the Wi-Fi data logging stick serial. This is exactly the serial required for the SolarmanV5 V5 frame header.

The existing `get_inverter_data()` function in `functions.php` already performs this fetch and returns `device_sn`. No changes are needed to extract the logger serial; it is already stored in the `inverters` table.

### Step 2 — SolarmanV5 verification

Once `device_sn` (logger serial) is obtained from Step 1, open a TCP connection to `{ip}:8899` and send a minimal Modbus holding-register read (e.g. register `0x003B`, quantity 1 — Running Status). A valid response confirms:

- Port 8899 is reachable on this inverter
- The logger serial decoded from `cover_mid` is correct
- The inverter will respond to the full polling request

If Step 2 fails (connection refused, timeout, or serial mismatch), the inverter should still be saved with a flag indicating SolarmanV5 is unavailable, and the system should fall back to the `status.html` polling path.

### Sequence diagram

```
Admin panel          PHP backend              Inverter
     │                    │                       │
     │── Add inverter ───►│                       │
     │   (ip, user, pass) │                       │
     │                    │── GET /status.html ──►│
     │                    │◄─ HTML (cover_mid) ───│
     │                    │   parse device_sn     │
     │                    │                       │
     │                    │── TCP :8899 ─────────►│
     │                    │   V5 REQUEST (0x45)   │
     │                    │◄─ V5 RESPONSE (0x15) ─│
     │                    │   verify seq + serial │
     │                    │                       │
     │◄── Inverter saved ─│                       │
     │    (with solarman  │                       │
     │     confirmed flag)│                       │
```

### Schema addition for discovery state

A boolean (or small enum) column on `inverters` is useful to record the outcome of Step 2 and control which polling path is used at runtime:

```sql
ALTER TABLE inverters ADD COLUMN solarman_enabled BOOLEAN NOT NULL DEFAULT FALSE;
```

The polling routine checks this flag: if `TRUE`, use SolarmanV5; if `FALSE`, fall back to `status.html`.

---

## Implementation Plan for the Main Project

### New data to store per poll cycle

Beyond what `pvstatsdetail` already holds (`power_now`, `power_today`, `power_total`), the new routine should capture:

| Field | Register | Formula | Type |
|---|---|---|---|
| `inverter_temp_c` | `0x005A` | `(raw − 1000) × 0.01` | `NUMERIC(5,2)` |
| `ac_voltage_v` | `0x0049` | `raw × 0.1` | `NUMERIC(6,1)` |
| `ac_current_a` | `0x004C` | signed `raw × 0.1` | `NUMERIC(5,1)` |
| `ac_frequency_hz` | `0x004F` | `raw × 0.01` | `NUMERIC(5,2)` |
| `pv1_voltage_v` | `0x006D` | `raw × 0.1` | `NUMERIC(5,1)` |
| `pv1_current_a` | `0x006E` | `raw × 0.1` | `NUMERIC(4,1)` |
| `pv2_voltage_v` | `0x006F` | `raw × 0.1` | `NUMERIC(5,1)` |
| `pv2_current_a` | `0x0070` | `raw × 0.1` | `NUMERIC(4,1)` |
| `pv3_voltage_v` | `0x0071` | `raw × 0.1` | `NUMERIC(5,1)` |
| `pv3_current_a` | `0x0072` | `raw × 0.1` | `NUMERIC(4,1)` |
| `pv4_voltage_v` | `0x0073` | `raw × 0.1` | `NUMERIC(5,1)` |
| `pv4_current_a` | `0x0074` | `raw × 0.1` | `NUMERIC(4,1)` |

`pv3_*` and `pv4_*` columns should be nullable so the schema works for 2-MPPT inverters too.

### Inverter discovery

The `inverters` table already stores IP, credentials, and — critically — `device_sn`, which holds the **logger serial number** parsed from `cover_mid` in the `status.html` response. No new column is needed for the serial itself.

The only new column required is `solarman_enabled BOOLEAN` (see the Discovery Flow section above), which controls whether the polling routine uses SolarmanV5 or falls back to HTTP for each inverter.

### Polling logic

The new routine (`solarman_poll.php` or equivalent) should:

1. Load inverter list from the database (IP + logger serial).
2. For each inverter:
   a. Open TCP socket to `{ip}:8899`, 10-second timeout.
   b. Build Modbus FC `0x03` request for registers `0x0001`–`0x007D`.
   c. Wrap in V5 frame with logger serial and a random sequence number.
   d. Send; read until a complete frame with control byte `0x15` arrives.
   e. Extract Modbus RTU from bytes 25 to `len−3`.
   f. Decode registers; apply formulas from the table above.
3. Write results to `pvstatsdetail` alongside the existing `power_now` / `power_today` / `power_total` values (which can now come from the Modbus registers `0x003C`, `0x003F`/`0x0040` instead of `status.html`).
4. On failure (timeout, bad frame), fall back to the existing `status.html` fetch or log the error and skip.

### 2-MPPT vs 4-MPPT compatibility

Read all four PV channel registers unconditionally. A 2-MPPT inverter will return 0 for registers `0x0071`–`0x0074`; store NULL in those columns when the raw value is 0.

### PHP reference implementation

`poc/solarman_test.php` contains working PHP implementations of:
- `modbus_crc()` — CRC-16/MODBUS
- `modbus_read_holding_registers()` — FC 0x03 frame builder
- `v5_encode()` — SolarmanV5 frame encoder
- `v5_time_response()` — response to unsolicited handshake/heartbeat frames
- `v5_scan_frames()` — frame extractor from a raw TCP byte buffer
- `v5_extract_modbus()` — Modbus RTU extractor from a V5 response
- `modbus_parse_holding_registers()` — FC 0x03 response decoder

These can be moved into `functions.php` or a new `solarman_functions.php` file as-is.
