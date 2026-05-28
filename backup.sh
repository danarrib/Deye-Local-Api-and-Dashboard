#!/bin/bash
BACKUP_DIR=/backups
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
FILE="$BACKUP_DIR/deye_backup_$TIMESTAMP.sql.gz"
pg_dump -h deye_db -U "$PGUSER" "$PGDATABASE" | gzip > "$FILE"
find "$BACKUP_DIR" -name "deye_backup_*.sql.gz" -mtime +7 -delete
