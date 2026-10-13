-- Runs once, on first start of an empty data volume (docker-entrypoint-initdb.d).
-- The main database and the `calendar` user come from MYSQL_DATABASE / MYSQL_USER.
-- The test database is used by .env.testing (php artisan test, migrate --env=testing).
CREATE DATABASE IF NOT EXISTS `calendar_lead_info_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `calendar_lead_info_test`.* TO 'calendar'@'%';
FLUSH PRIVILEGES;
