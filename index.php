<?php
// index.php - Versión final y corregida
/**
 * index.php
 *
 * Es el punto de entrada principal y la interfaz de usuario de la aplicación.
 * Muestra el formulario de búsqueda y el de subida de archivos.
 * Orquesta el proceso de búsqueda: recibe la consulta, la pasa al parser y al motor de búsqueda,
 * y finalmente muestra los resultados ordenados por relevancia.
 * @package    DocumentSearchEngine
 */
require_once 'db_connection.php';
require_once 'parser.php';

require_once 'search_engine.php'; // Nuevo motor de búsqueda

$query_string = isset($_GET['q']) ? $_GET['q'] : '';
$results = [];
$error_message = '';
$debug_info = '';

if (!empty($query_string)) {
    try {
        // 1. Parsear la consulta a tokens
        $tokens = parse_query_to_tokens($query_string);

        // 2. Ejecutar la búsqueda usando el nuevo motor
        $search_result = execute_search($tokens, $conn);

        $doc_ids = $search_result['doc_ids'];
        $debug_info = $search_result['debug'];

        if (!empty($doc_ids)) {
            // 3. Calcular la relevancia (TF-IDF) para los documentos encontrados
            // Extraer términos de la consulta para el cálculo de la puntuación.
            $query_terms = [];
            foreach ($tokens as $token) {
                if ($token['type'] === 'term' || $token['type'] === 'patron') {
                    $query_terms[] = $token;
                } elseif ($token['type'] === 'cadena') {
                    // Para CADENA, descomponer la frase en términos individuales para el scoring.
                    $phrase_sub_terms = normalize_and_tokenize($token['value']);
                    foreach ($phrase_sub_terms as $sub_term) {
                        $query_terms[] = ['type' => 'term', 'value' => $sub_term];
                    }
                }
            }
            $ranked_docs = calculate_tfidf_scores($doc_ids, $query_terms, $conn);

            // 4. Ordenar los documentos por puntuación de relevancia (descendente)
            // Usamos uasort para ordenar el array por el 'score' descendente, manteniendo las claves (doc_id).
            uasort($ranked_docs, function($a, $b) {
                if ($a['score'] == $b['score']) {
                    return 0;
                }
                return ($a['score'] < $b['score']) ? 1 : -1;
            });

            // 5. Obtener la información de los documentos para mostrarla
            $doc_ids_ordered = array_keys($ranked_docs);
            if (empty($doc_ids_ordered)) {
                // Si después de puntuar no queda nada (raro, pero posible), vaciamos los resultados.
                $results = [];
            } else {
                $ids_placeholder = implode(',', $doc_ids_ordered);

                $sql = "SELECT doc_id, filename, filepath, snippet 
                        FROM documents 
                        WHERE doc_id IN ($ids_placeholder)
                        ORDER BY FIELD(doc_id, $ids_placeholder)";

                $result = $conn->query($sql);

                if (!$result) {
                    throw new Exception("Error al recuperar documentos: " . $conn->error);
                }
                $docs_info = [];
                while ($row = $result->fetch_assoc()) {
                    $docs_info[$row['doc_id']] = $row;
                }

                // 6. Construir el array final de resultados con la puntuación
                foreach ($doc_ids_ordered as $id) {
                    if (isset($docs_info[$id])) {
                        $results[] = [
                            'filename' => $docs_info[$id]['filename'],
                            'filepath' => 'uploads/' . rawurlencode($docs_info[$id]['filename']), // URL relativa segura
                            'snippet' => $docs_info[$id]['snippet'],
                            'score' => $ranked_docs[$id]['score'],
                            'cosine_sim' => $ranked_docs[$id]['cosine_sim']
                        ];
                    }
                }
            }
        }

    } catch (Exception $e) {
        $error_message = "Error al procesar la consulta: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Búsqueda de Documentos</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <h1>Buscador de Documentos</h1>
        <form action="index.php" method="get" class="search-form">
            <input type="text" name="q" class="search-input" placeholder="Escribe tu consulta aquí..." value="<?php echo htmlspecialchars($query_string); ?>">
            <button type="submit" class="search-button">Buscar</button>
        </form>

        <div class="upload-section">
            <h2>Indexar Nuevos Documentos</h2>
            <p>Sube archivos de texto (.txt) para añadirlos al índice de búsqueda.</p>
            <form action="upload_handler.php" method="post" enctype="multipart/form-data" class="upload-form">
                <label for="files_to_upload">Selecciona uno o varios archivos:</label>
                <input type="file" name="files_to_upload[]" id="files_to_upload" multiple accept=".txt" required>
                <button type="submit" class="search-button" style="align-self: flex-start;">Cargar e Indexar</button>
            </form>
        </div>

        <?php if ($debug_info): ?>
            <h3>Información de Depuración:</h3>
            <div class="debug-sql"><?php echo nl2br(htmlspecialchars($debug_info)); ?></div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <p class="error"><?php echo htmlspecialchars($error_message); ?></p>
        <?php elseif (!empty($query_string)): ?>
            <h2>Resultados de la Búsqueda</h2>
            <?php if (!empty($results)): ?>
                <div class="results-list">
                    <?php foreach ($results as $doc): ?>
                        <div class="result-item">
                            <a href="<?php echo htmlspecialchars($doc['filepath']); ?>" class="result-title" download>
                                <?php echo htmlspecialchars($doc['filename']); ?>
                            </a>
                            <p class="result-snippet"><?php echo htmlspecialchars($doc['snippet']); ?>...</p>
                            <div class="scores">
                                <span class="result-score">Relevancia (TF-IDF): <?php echo number_format($doc['score'], 4); ?></span>
                                <span class="result-score coseno">Similitud Coseno: <?php echo number_format($doc['cosine_sim'], 4); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="no-results">No se encontraron resultados para tu consulta.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>