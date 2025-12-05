-- Cache Database Setup SQL
-- Import this file into MySQL to set up the cache database
-- You can import via phpMyAdmin or command line: mysql -u root < cache_database.sql

-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS `cashe` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Use the database
USE `cashe`;

-- Create cache table
CREATE TABLE IF NOT EXISTS `cache` (
    `cache_key` VARCHAR(255) NOT NULL PRIMARY KEY,
    `cache_data` LONGTEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NOT NULL,
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
