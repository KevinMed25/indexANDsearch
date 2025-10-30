<?php
// indexer.php
/**
 * indexer.php
 *
 * Contiene la lógica principal para procesar archivos de texto y construir el índice invertido.
 * Lee un archivo, lo normaliza, y puebla las tablas `documents`, `terms` y `postings`
 * de manera transaccional para garantizar la integridad de los datos.
 * @package    DocumentSearchEngine
 */

require_once 'utils.php';

/**
 * Indexa un archivo de texto, poblando las tablas documents, terms y postings.
 *
 * @param string $filepath La ruta completa al archivo a indexar.
 * @param mysqli $conn La conexión a la base de datos.
 * @return void
 */
function index_file($filepath, $conn) {
    if (!file_exists($filepath) || !is_readable($filepath)) {
        echo "Error: El archivo no existe o no se puede leer: $filepath\n";
        return;
    }

    $filename = basename($filepath);
    echo "Iniciando indexación para: $filename\n";

    // Iniciar transacción para asegurar la integridad de los datos
    $conn->begin_transaction();

    try {
        // --- 1. Limpieza de datos antiguos (si el archivo ya fue indexado) ---
        $stmt = $conn->prepare("SELECT doc_id FROM documents WHERE filename = ?");
        $stmt->bind_param('s', $filename);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $doc_id_to_delete = $result->fetch_assoc()['doc_id'];
            echo "El archivo ya existe. Re-indexando (ID de documento antiguo: $doc_id_to_delete)...\n";

            // Antes de borrar, obtener los términos y frecuencias para actualizar los contadores
            $postings_to_update_stmt = $conn->prepare("SELECT term_id, term_frequency_in_doc FROM postings WHERE doc_id = ?");
            $postings_to_update_stmt->bind_param('i', $doc_id_to_delete);
            $postings_to_update_stmt->execute();
            $postings_result = $postings_to_update_stmt->get_result();

            $update_term_stmt = $conn->prepare("UPDATE terms SET doc_frequency = doc_frequency - 1, collection_frequency = collection_frequency - ? WHERE term_id = ?");
            while ($posting_row = $postings_result->fetch_assoc()) {
                $update_term_stmt->bind_param('ii', $posting_row['term_frequency_in_doc'], $posting_row['term_id']);
                $update_term_stmt->execute();
            }
            $postings_to_update_stmt->close();
            $update_term_stmt->close();

            // Eliminar el documento antiguo. ON DELETE CASCADE se encargará de los postings.
            $delete_stmt = $conn->prepare("DELETE FROM documents WHERE doc_id = ?");
            $delete_stmt->bind_param('i', $doc_id_to_delete);
            $delete_stmt->execute();
            $delete_stmt->close();

            // Opcional: Limpiar términos que ya no están en ningún documento
            $conn->query("DELETE FROM terms WHERE doc_frequency <= 0");
        }
        $stmt->close();

        // --- 2. Procesar el contenido del archivo ---
        $content = file_get_contents($filepath);
        $tokens = normalize_and_tokenize($content);
        $total_terms_in_doc = count($tokens);

        if ($total_terms_in_doc === 0) {
            echo "El archivo está vacío o no contiene palabras válidas. Saltando.\n";
            $conn->rollback(); // No hay nada que hacer
            return;
        }

        // --- 4. Calcular frecuencias y posiciones de términos ---
        $term_stats = [];
        $doc_magnitude_sq = 0.0; // Suma de los cuadrados de los pesos (TF)

        foreach ($tokens as $position => $token) {
            if (!isset($term_stats[$token])) {
                $term_stats[$token] = ['freq' => 0, 'pos' => []];
            }
            $term_stats[$token]['freq']++;
            $term_stats[$token]['pos'][] = $position;
        }

        // Calcular la suma de los cuadrados de los TF
        foreach ($term_stats as $term => $stats) {
            $tf = $stats['freq'] / $total_terms_in_doc;
            $doc_magnitude_sq += ($tf * $tf);
        }
        $doc_magnitude = sqrt($doc_magnitude_sq);

        // --- 3. Insertar el nuevo documento en la tabla `documents` (movido aquí) ---
        $snippet = mb_substr($content, 0, 250, 'UTF-8');
        $stmt = $conn->prepare("INSERT INTO documents (filename, filepath, snippet, total_terms, doc_magnitude) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('sssid', $filename, $filepath, $snippet, $total_terms_in_doc, $doc_magnitude);
        $stmt->execute();
        $doc_id = $conn->insert_id;
        $stmt->close();
        echo "Documento insertado con ID: $doc_id y Magnitud: $doc_magnitude\n";

        // --- 5. Actualizar tablas `terms` y `postings` ---
        foreach ($term_stats as $term => $stats) {
            // Obtener o crear el término en la tabla `terms`
            $stmt = $conn->prepare(
                "INSERT INTO terms (term_text, doc_frequency, collection_frequency) VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE 
                 doc_frequency = doc_frequency + 1, 
                 collection_frequency = collection_frequency + ?"
            );
            $stmt->bind_param('sii', $term, $stats['freq'], $stats['freq']);
            $stmt->execute();
            $stmt->close();

            // Obtener el ID del término
            $stmt = $conn->prepare("SELECT term_id FROM terms WHERE term_text = ?");
            $stmt->bind_param('s', $term);
            $stmt->execute();
            $term_id = $stmt->get_result()->fetch_assoc()['term_id'];
            $stmt->close();

            // Insertar la entrada en la tabla `postings`
            $positions_str = implode(',', $stats['pos']);
            $stmt = $conn->prepare("INSERT INTO postings (doc_id, term_id, term_frequency_in_doc, positions) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('iiis', $doc_id, $term_id, $stats['freq'], $positions_str);
            $stmt->execute();
            $stmt->close();
        }

        // Si todo fue bien, confirmar la transacción
        $conn->commit();
        echo "Indexación completada con éxito para: $filename\n";

    } catch (Exception $e) {
        // Si algo falla, revertir todos los cambios
        $conn->rollback();
        echo "Error durante la indexación. Transacción revertida. Mensaje: " . $e->getMessage() . "\n";
    }
}

// --- EJEMPLO DE USO ---
// Para usar este script, necesitarás llamarlo desde otro archivo o desde la línea de comandos.

/*
require_once 'db_connection.php';

// Asegúrate de que la carpeta 'uploads' exista en el mismo directorio que este script.
$uploads_dir = __DIR__ . '/uploads/';
if (!is_dir($uploads_dir)) {
    mkdir($uploads_dir, 0777, true);
}

// Supongamos que tienes un archivo llamado 'ejemplo.txt' en la carpeta 'uploads'
$file_to_index = $uploads_dir . 'ejemplo.txt';

// Crea el archivo si no existe para poder probar
if (!file_exists($file_to_index)) {
    file_put_contents($file_to_index, "El queso de cabra es un queso sabroso. El queso manchego también es un queso excelente.");
}

index_file($file_to_index, $conn);
*/

?>