# Deye Local Api and Dashboard

A local RESTFul API (and a Dashboard) to access Deye Micro Inverter Data

## Context

Deye Micro-inverters are devices used to inject on the electric grid the power produced by solar panels.

These micro-inverters have a network connection, and one can use the "Solarman" app on the phone to monitor the power plant stats. However, these data inside the app cannot be accessed by 3rd-party apps, or by custom apps, and it require the inverter to have an active internet connection to work.

## Purpose of this project

Create a "local" data repository, so you can both get instant data straight from the inverters, and also to store this data on a local database for future use.

## Current status of this project

This is what is already working:

- [x] Provide a standard RESTFul API to get data from Deye Micro-inverters over local network
- [x] Store data from the micro-inverters to a database, so the data can be used to create useful reports
- [x] Create a Dashboard to present the powerplant data
- [x] Send a daily report to a Telegram Group (using a Telegram Bot)

## What else needs to be done
- [ ] Get data from individual PV inputs
- [ ] Save data from individual PV inputs
- [ ] Configuration UI
- [ ] Make it work as a Docker compose package

## How to set up

1. Get a Linux machine running on the local network
2. Install Postgres, Apache, PHP...
3. Yeah, this sucks... I'll make it a Docker compose package to make the set up easier.
