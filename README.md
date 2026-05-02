# Database Backups

Nightly backups of the Brilliant Mind Moods MySQL database.
Kept for 30 days.

**Restore:** `gunzip -c backup-YYYY-MM-DD.sql.gz | mysql -h HOST -P PORT -u USER -p DBNAME`
