-- TCG Database: creates the tcg_database database and all of its tables.
-- Run in phpMyAdmin: click the "SQL" tab (at the top, with no database selected), paste this whole file, click Go.
-- Or use the Import tab and choose this file.
-- Safe to run on a fresh server. It will NOT overwrite tables that already exist.

CREATE DATABASE IF NOT EXISTS `tcg_database`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

USE `tcg_database`;

-- ---------------------------------------------------------------
-- Pokémon inventory: one row per card + set + number + condition + finish
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pokemon_inv` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `name`           VARCHAR(255) NOT NULL,
  `quantity`       INT NOT NULL DEFAULT 1,
  `set_name`       VARCHAR(100) NULL,          -- set code like MEG or set name like "Mega Evolution"
  `set_number`     VARCHAR(20)  NULL,          -- e.g. 113/132
  `regulation`     VARCHAR(5)   NULL,          -- regulation mark, e.g. H
  `card_condition` ENUM('NM','LP','MP','HP','DMG') NOT NULL DEFAULT 'NM',
  `finish`         ENUM('normal','foil','reverse','etched') NOT NULL DEFAULT 'normal',
  `image_url`      VARCHAR(500) NULL,          -- filled in automatically from the card catalog
  -- Fingerprint of everything except quantity; importing the same card again adds to quantity
  `row_key` CHAR(40) AS (SHA1(CONCAT_WS(0x1F,
      LOWER(TRIM(COALESCE(`name`, ''))), LOWER(TRIM(COALESCE(`set_name`, ''))),
      LOWER(TRIM(COALESCE(`set_number`, ''))), LOWER(TRIM(COALESCE(`regulation`, ''))),
      LOWER(TRIM(COALESCE(`card_condition`, ''))), LOWER(TRIM(COALESCE(`finish`, '')))))) STORED,
  UNIQUE KEY `uq_row_key` (`row_key`),
  KEY `idx_name` (`name`),
  KEY `idx_set` (`set_name`),
  KEY `idx_number` (`set_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Magic: The Gathering inventory
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mtg_inv` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `name`             VARCHAR(255) NOT NULL,
  `quantity`         INT NOT NULL DEFAULT 1,
  `set_name`         VARCHAR(100) NULL,        -- set name like "Magic 2010" or code like M10
  `collector_number` VARCHAR(20)  NULL,        -- e.g. 146
  `card_condition`   ENUM('NM','LP','MP','HP','DMG') NOT NULL DEFAULT 'NM',
  `finish`           ENUM('normal','foil','reverse','etched') NOT NULL DEFAULT 'normal',
  `image_url`        VARCHAR(500) NULL,
  `row_key` CHAR(40) AS (SHA1(CONCAT_WS(0x1F,
      LOWER(TRIM(COALESCE(`name`, ''))), LOWER(TRIM(COALESCE(`set_name`, ''))),
      LOWER(TRIM(COALESCE(`collector_number`, ''))),
      LOWER(TRIM(COALESCE(`card_condition`, ''))), LOWER(TRIM(COALESCE(`finish`, '')))))) STORED,
  UNIQUE KEY `uq_row_key` (`row_key`),
  KEY `idx_name` (`name`),
  KEY `idx_set` (`set_name`),
  KEY `idx_number` (`collector_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Card catalog: every printed card with its picture (filled by the Card Catalog page)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `card_catalog` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `game`      VARCHAR(10)  NOT NULL,           -- 'pokemon' or 'mtg'
  `ext_id`    VARCHAR(64)  NOT NULL,           -- Scryfall / TCGdex id
  `name`      VARCHAR(255) NOT NULL,
  `name_key`  VARCHAR(190) NOT NULL,
  `set_code`  VARCHAR(20)  NULL,
  `set_name`  VARCHAR(150) NULL,
  `set_alt`   VARCHAR(20)  NULL,
  `number`    VARCHAR(20)  NULL,
  `num_key`   VARCHAR(20)  NULL,
  `released`  DATE NULL,
  `image_url` VARCHAR(500) NULL,
  `colors`    VARCHAR(10)  NULL,               -- Magic: "WU" etc. ('' = colorless)
  `mana_cost` VARCHAR(100) NULL,               -- Magic: "{1}{W}{U}"
  `cmc`       DECIMAL(6,1) NULL,               -- Magic: mana value
  `type_line` VARCHAR(150) NULL,
  `rarity`    VARCHAR(40)  NULL,               -- e.g. mythic / Double rare
  UNIQUE KEY `uq_ext` (`game`, `ext_id`),
  KEY `idx_name` (`game`, `name_key`, `released`),
  KEY `idx_num` (`game`, `num_key`),
  KEY `idx_img` (`image_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- List builder
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `card_lists` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(150) NOT NULL,
  `game`       VARCHAR(20)  NOT NULL,
  `notes`      TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `source`     VARCHAR(20)  NULL,               -- 'kiosk' = sent by a customer from the Customer Lookup
  `seen_at`    TIMESTAMP NULL                   -- when staff first opened it
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `card_list_items` (
  `id`       INT AUTO_INCREMENT PRIMARY KEY,
  `list_id`  INT NOT NULL,
  `name`     VARCHAR(255) NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  KEY `idx_list_name` (`list_id`, `name`),
  CONSTRAINT `fk_list_items_list` FOREIGN KEY (`list_id`) REFERENCES `card_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Cards pulled from inventory in the List Builder (used for Undo)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pull_log` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `list_id`   INT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `game`      VARCHAR(10)  NOT NULL,
  `card_name` VARCHAR(255) NOT NULL,
  `details`   VARCHAR(255) NOT NULL,           -- e.g. "Magic 2010 · 146 · NM · Foil"
  `snapshot`  TEXT NOT NULL,                   -- the card's details, so Undo can put it back
  `quantity`  INT NOT NULL,
  `pulled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `undone_at` TIMESTAMP NULL,
  `batch_id`  CHAR(16) NULL,                   -- pulls made together (one pick list)
  KEY `idx_list` (`list_id`, `item_name`),
  KEY `idx_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- App settings (schema version, last catalog download)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_meta` (
  `k` VARCHAR(64) PRIMARY KEY,
  `v` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
