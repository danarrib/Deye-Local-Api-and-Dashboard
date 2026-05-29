#!/bin/bash
# Usage:
#   bash rebuild-test.sh           — rebuild but keep the database volume (default)
#   bash rebuild-test.sh --wipe-data — rebuild and wipe data

if [ "$1" = "--wipe-data" ]; then
    docker compose -p deye-test -f docker-compose.test.yml down -v
else
    docker compose -p deye-test -f docker-compose.test.yml down
fi

docker compose -p deye-test -f docker-compose.test.yml up -d --build --remove-orphans
