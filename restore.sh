#!/bin/bash
set -e

BACKUP_DIR="./backups"

if [ -n "$1" ]; then
    BACKUP_FILE="$1"
else
    BACKUP_FILE=$(ls -t "$BACKUP_DIR"/deye_backup_*.sql.gz 2>/dev/null | head -1)
    if [ -z "$BACKUP_FILE" ]; then
        echo "No backup files found in $BACKUP_DIR"
        exit 1
    fi
    echo "No file specified, using latest backup: $BACKUP_FILE"
fi

if [ ! -f "$BACKUP_FILE" ]; then
    echo "Backup file not found: $BACKUP_FILE"
    exit 1
fi

echo "Restoring from: $BACKUP_FILE"
read -p "This will overwrite all current data. Continue? [y/N] " confirm
if [[ "$confirm" != [yY] ]]; then
    echo "Aborted."
    exit 0
fi

echo "Stopping app containers..."
docker compose stop deye_php deye_cron

echo "Dropping and recreating database..."
docker compose exec -T deye_db psql -U deye_db_user -d postgres -c "DROP DATABASE IF EXISTS deye_data;"
docker compose exec -T deye_db psql -U deye_db_user -d postgres -c "CREATE DATABASE deye_data;"

echo "Restoring data..."
gunzip -c "$BACKUP_FILE" | docker compose exec -T deye_db psql -U deye_db_user deye_data

echo "Starting app containers..."
docker compose start deye_php deye_cron

echo "Restore complete."
