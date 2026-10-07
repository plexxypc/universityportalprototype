CREATE DATABASE IF NOT EXISTS university_portal_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON university_portal_testing.* TO 'portal'@'%';

FLUSH PRIVILEGES;
