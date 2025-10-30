-- ir_tables.sql
-- Script para crear las tablas del sistema de Recuperación de Información (IR).

-- Crea la base de datos si no existe y la selecciona.
CREATE SCHEMA IF NOT EXISTS `search_engine_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `search_engine_db`;

--
-- Tabla para almacenar información sobre los documentos (archivos) cargados.
--
CREATE TABLE IF NOT EXISTS `documents` (
  `doc_id` INT(11) NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(255) NOT NULL,
  `filepath` VARCHAR(512) NOT NULL,
  `snippet` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_terms` INT(11) NOT NULL DEFAULT 0,
  `doc_magnitude` DOUBLE NOT NULL DEFAULT 0 COMMENT 'Magnitud del vector del documento para el cálculo del coseno.',
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`doc_id`),
  UNIQUE KEY `idx_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Tabla para el diccionario de términos (índice invertido).
-- Almacena cada palabra única encontrada en la colección de documentos.
--
CREATE TABLE IF NOT EXISTS `terms` (
  `term_id` INT(11) NOT NULL AUTO_INCREMENT,
  `term_text` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `doc_frequency` INT(11) NOT NULL DEFAULT 0 COMMENT 'Número de documentos que contienen este término.',
  `collection_frequency` INT(11) NOT NULL DEFAULT 0 COMMENT 'Frecuencia total del término en toda la colección.',
  PRIMARY KEY (`term_id`),
  UNIQUE KEY `idx_term_text` (`term_text`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Tabla de "Postings".
-- Conecta los términos con los documentos en los que aparecen.
--
CREATE TABLE IF NOT EXISTS `postings` (
  `posting_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `doc_id` INT(11) NOT NULL,
  `term_id` INT(11) NOT NULL,
  `term_frequency_in_doc` INT(11) NOT NULL COMMENT 'Frecuencia del término en este documento (TF).',
  `positions` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Lista de posiciones del término en el documento, separadas por comas.',
  PRIMARY KEY (`posting_id`),
  UNIQUE KEY `idx_doc_term` (`doc_id`, `term_id`),
  KEY `fk_postings_term_id` (`term_id`),
  CONSTRAINT `fk_postings_doc_id` FOREIGN KEY (`doc_id`) REFERENCES `documents` (`doc_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_postings_term_id` FOREIGN KEY (`term_id`) REFERENCES `terms` (`term_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;