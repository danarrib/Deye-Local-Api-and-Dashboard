# Compatibility List

This page lists Deye micro-inverter models and their known compatibility with this project.

## Status definitions

| Status | Meaning |
|--------|---------|
| **Confirmed** | Tested directly by a project maintainer or contributor. All features verified to work. |
| **Likely compatible** | Not tested, but shares the same hardware generation or protocol stack as a confirmed model. Expected to work. |
| **Untested** | No data available. May work, may not. Feedback welcome. |

---

## Deye Micro-Inverters

| Model | Max Power | MPPT Inputs | SolarmanV5 | HTTP Fallback | Status | Notes |
|-------|-----------|-------------|------------|---------------|--------|-------|
| SUN-M225G4-EU-Q0 | 2250 W | 4 | Yes | Yes | **Confirmed** | Tested by project author. Full data including per-panel voltage, current, power, and temperature. |
| SUN-M80G4-EU-Q0 | 800 W | 4 | Yes | Yes | Likely compatible | Same G4 generation as the confirmed model. |
| SUN600G3-EU-230 | 600 W | 2 | Yes | Yes | Likely compatible | G3 generation; the SolarmanV5 implementation in this project was built using specs from a library targeting this model. |
| SUN-M80G3-EU-Q0 | 800 W | 2 | Yes | Yes | Likely compatible | G3 generation; same rationale as SUN600G3-EU-230. |

---

## General compatibility

Any micro-inverter that meets all three of the following conditions should work with this project:

1. **Solarman-compatible Wi-Fi connectivity** — the inverter's Wi-Fi module must speak the SolarmanV5 protocol on TCP port 8899. Wi-Fi may be built into the inverter (as on the SUN-M225G4-EU-Q0) or provided by an external logging stick that plugs into the inverter. Either form works as long as the inverter is reachable on the local network.
2. **`status.html` web interface** — the inverter must expose a `status.html` page over HTTP (Basic Auth, default credentials `admin`/`admin`). This is used as a fallback and to resolve the logger serial number during setup.
3. **Reachable on the local network** — the host machine running this project must be able to reach the inverter's IP address directly (same subnet or routed).

If SolarmanV5 (port 8899) is not reachable, the system automatically falls back to the HTTP interface, which provides a more limited set of fields (no temperature, no per-panel data).

---

## Contributing compatibility reports

If you have tested this project with a model not listed here, please open a GitHub issue or pull request with:
- The exact model name printed on the inverter label
- Whether SolarmanV5 (port 8899) was reachable
- Which data fields were populated correctly
- Any unexpected behaviour or missing data

Your report helps other users know what to expect before they install.
