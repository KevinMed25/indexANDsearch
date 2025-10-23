<?php
// upload_handler.php
/**
 * upload_handler.php
 *
 * Procesa las subidas de archivos desde el formulario en `index.php`.
 * Valida los archivos, los mueve a la carpeta `uploads/` y luego invoca
 * al script `indexer.php` para que procese cada nuevo archivo y lo añada al índice.
 *
 * @package    NorthwindSearchEngine
 */

require_once 'db_connection.php';
require_once 'indexer.php';

// --- Configuración ---
$upload_dir = __DIR__ . '/uploads/';

// Crear el directorio de subidas si no existe
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0777, true)) {
        die("Error: No se pudo crear el directorio de subidas.");
    }
}

/**
 * Reorganiza el array $_FILES para que sea más fácil de iterar.
 * @param array $file_post El array $_FILES['input_name']
 * @return array Un array de arrays, donde cada subarray representa un archivo.
 */
function rearrange_files_array(array $file_post) {
    $file_ary = [];
    $file_count = count($file_post['name']);
    $file_keys = array_keys($file_post);

    for ($i = 0; $i < $file_count; $i++) {
        foreach ($file_keys as $key) {
            $file_ary[$i][$key] = $file_post[$key][$i];
        }
    }

    return $file_ary;
}


if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["files_to_upload"])) {
    
    // Usamos una página simple para mostrar el resultado del proceso
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Resultado de Carga</title><link rel="stylesheet" href="styles.css"></head><body><div class="container">';
    echo '<h1>Proceso de Carga e Indexación</h1>';
    echo '<div class="debug-sql" style="text-align: left; white-space: pre-wrap;">';

    $files_to_process = rearrange_files_array($_FILES["files_to_upload"]);

    foreach ($files_to_process as $file) {
        if ($file["error"] === UPLOAD_ERR_OK) {
            $filename = basename($file["name"]);
            $target_filepath = $upload_dir . $filename;

            // Mover el archivo subido al directorio de destino
            if (move_uploaded_file($file["tmp_name"], $target_filepath)) {
                echo "--------------------------------------------------\n";
                echo "Archivo '$filename' subido con éxito.\n";
                
                // Llamar al indexador para procesar el nuevo archivo
                index_file($target_filepath, $conn);

            } else {
                echo "--------------------------------------------------\n";
                echo "Error al mover el archivo subido '$filename'.\n";
            }
        } else {
            echo "--------------------------------------------------\n";
            echo "Error al subir el archivo '" . ($file['name'] ?? 'desconocido') . "'. Código de error: " . $file["error"] . "\n";
        }
    }
    
    echo "</div>";
    echo '<br><a href="index.php" class="search-button" style="text-decoration: none;">Volver a la búsqueda</a>';
    echo '</div></body></html>';

} else {
    // Redirigir si se accede al script directamente sin enviar datos
    header("Location: index.php");
    exit();
}
?>