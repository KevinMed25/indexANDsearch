<?php
/**
 * db_connection.php
 *
 * Este script se encarga de establecer y configurar la conexión
 * con la base de datos MySQL. Define las constantes de conexión
 * y crea una instancia global del objeto mysqli.
 *
 * @package    DocumentSearchEngine
 */

define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'root');
define('DB_NAME', 'search_engine_db');

/** @var mysqli $conn La instancia de conexión a la base de datos. */
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}
// Se establece utf8mb4 para consistencia con la BD y soporte completo de Unicode.
$conn->set_charset("utf8mb4");
?>