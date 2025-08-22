# Deye Local Api and Dashboard

A local RESTFul API (and a Dashboard) to access Deye Micro Inverter Data

## Context

Deye Micro-inverters are devices used to inject on the electric grid the power produced by solar panels.

These micro-inverters have a network connection, and one can use the "Solarman" app on the phone to monitor the power plant stats. However, the data inside the app cannot be accessed by 3rd-party apps (or by custom apps).

## Purpose of this project

Create a local data repository, so you can both get instant data straight from the inverters, and also to store this data on a local database to use however you like.

This project alone will not remove the requirement of the micro-inverter be connected to the internet, hence, will not improve your privacy in any way. It will just allow you to have access to a clean copy of your stats data.

If privacy is a concern for you, then you should check out the "[Deye Microinverter Cloud-free](https://github.com/Hypfer/deye-microinverter-cloud-free)" project.

## Current status of this project

This is what is already working:

- [x] Provide a standard RESTFul API to get data from Deye Micro-inverters over local network
- [x] Store data from the micro-inverters to a database, so the data can be used to create useful reports
- [x] Create a Dashboard to present the powerplant data
- [x] Send a daily report to a Telegram Group (using a Telegram Bot)



## How to set up

1. Get a Linux machine running on the local network

2. Install Postgres, Apache, PHP

3. Set up a new user and a new database on Postgres

4. Create a new directory called `deye_api` inside an apache exposed directory (usually `/var/www/html/`)

5. Copy to that directory all the files of this repository

6. Edit the `functions.php` file and change the settings according to your database and powerplant configuration

7. Add a Cron job to run the `crontasks.php` file every 5 minutes (run `crontab -e` and add a new line at the end of the file with `*/5 * * * * php /var/www/html/deye_api/crontasks.php`)

## How to use

There are two ways to use: The API and the Dashboard:

### Dashboard
Open an Internet Browser and open `http://localhost/deye_api/`

<img alt="image" src="https://github.com/user-attachments/assets/1a8689a3-aa75-4ffb-a7ea-f49da9f6e0c6" />

### API
Open an Internet Browser and open `http://localhost/deye_api/deye.php?user=admin&password=admin&ipaddress=192.168.15.201`

Replace on the above URL the parameters with your inverter specific details.

If you plan to use only the API, you don't need to set up anything on the `function.php` file, and you don't need a database.

#### Data Structure example

```
{
"inverter_sn": "1234567890",
"power_now": 725,
"power_today": 1,
"power_total": 1612.6,
"device_sn": "0987654321",
"device_ver": "MW3_16U_5406_1.63",
"timestamp": "2025-08-20T13:04:45Z",
"ipaddress": "192.168.15.201"
}
```

## How it works

Every Deye Microinverter has a web interface, that can be accessed by opening the browser and acessing the IP Address of the inverter by http (the default username and password are both `admin`).

Example:
<img alt="image" src="https://github.com/user-attachments/assets/32dc1811-2719-40e1-b175-7291387eeb94" />

If you open the Network console of the browser, you'll notice that this page gets data from another page from the inverter: The `status.html` file.

<img width="1609" height="1382" alt="image" src="https://github.com/user-attachments/assets/a3973f91-0199-436b-b928-ab53aa91a99a" />

So, by parsing the contents of this file, we can get the stats directly from the inverter.

This program does exactly that: Parses the `status.html` file and get the following information:
* Device Serial Number
* Inverter Serial Number
* Device Firmware Version
* Power being generated now (in Watts)
* Energy produced today (in Kilowatts-Hour)
* Energy produced in total (in Kilowatts-Hour)

## Project requirements
* Backend uses only PHP and Postgres
* UI uses [Bootstrap](https://github.com/twbs/bootstrap) and [Chart.js](https://github.com/chartjs/Chart.js) libraries only
* No fancy Frameworks to bloat the application. No Package managers, nothing. Just plain HTML, Javascript and PHP. This project is intended to be simple.

## Development roadmap
Here's a list of new features I wish to add to this project in the future:

- [ ] Get data from individual PV inputs (each individual solar panel of each micro-inverter) 
  - Voltage (V)
  - Current (A)
  - Power (W)
  - Energy Today (kWh)
  - Energy Total (kWh)
- [ ] Get Inverter Temperature (Celsius)
- [ ] Get Weather Data (ambient temperature, humidity, wind speed and direction, condition like clear, cloudy, raining, snowing, etc)
- [ ] Configuration UI (as it is now, the configuration is stored on the `function.php` file)
- [ ] Make it work as a Docker compose package, to make set up easier for everyone
