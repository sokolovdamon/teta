#!/bin/sh
# Daily logical backup of the TETA database with rotation. Restore: gunzip -c FILE | psql -d teta
set -eu
stamp=$(date -u +%Y%m%d-%H%M%S)
file="/backups/teta-${stamp}.sql.gz"
pg_dump --no-owner --format=plain teta | gzip -9 > "${file}.tmp"
mv "${file}.tmp" "${file}"
find /backups -name 'teta-*.sql.gz' -mtime "+${BACKUP_KEEP_DAYS:-14}" -delete
echo "backup written: ${file}"
