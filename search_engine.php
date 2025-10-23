<?php
// search_engine.php
/**
 * search_engine.php
 *
 * Contiene toda la lógica del motor de búsqueda. Implementa el algoritmo Shunting-yard
 * para procesar consultas booleanas, recupera documentos del índice invertido,
 * y calcula la relevancia de los resultados usando el modelo TF-IDF.
 * @package    NorthwindSearchEngine
 */

require_once 'utils.php';

/**
 * Obtiene los doc_ids para un solo término.
 * @param string $term El término a buscar.
 * @param bool $is_pattern Si la búsqueda es con LIKE (para PATRON).
 * @param mysqli $conn Conexión a la BD.
 * @return array Lista de doc_ids.
 */
function get_doc_ids_for_term($term, $is_pattern, $conn) {
    $doc_ids = [];
    $sql = "SELECT p.doc_id FROM terms t JOIN postings p ON t.term_id = p.term_id WHERE t.term_text ";
    $sql .= $is_pattern ? "LIKE ?" : "= ?";
    $param = $is_pattern ? "%$term%" : $term;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $param);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $doc_ids[] = $row['doc_id'];
    }
    $stmt->close();
    return array_unique($doc_ids);
}

/**
 * Obtiene los doc_ids para una frase exacta usando las posiciones.
 * @param string $phrase La frase a buscar.
 * @param mysqli $conn Conexión a la BD.
 * @return array Lista de doc_ids que contienen la frase exacta.
 */
function get_doc_ids_for_phrase($phrase, $conn) {
    $phrase_tokens = normalize_and_tokenize($phrase);
    if (empty($phrase_tokens)) return [];

    $candidate_docs = null;
    foreach ($phrase_tokens as $token) {
        $docs_for_token = get_doc_ids_for_term($token, false, $conn);
        if ($candidate_docs === null) {
            $candidate_docs = $docs_for_token;
        } else {
            $candidate_docs = array_intersect($candidate_docs, $docs_for_token);
        }
    }

    if (empty($candidate_docs)) return [];

    // --- Optimización: Obtener todas las posiciones en una sola consulta ---
    $positions_by_doc = [];
    $doc_ids_placeholder = implode(',', array_fill(0, count($candidate_docs), '?'));
    $term_text_placeholder = implode(',', array_fill(0, count($phrase_tokens), '?'));

    $sql = "SELECT p.doc_id, t.term_text, p.positions 
            FROM postings p 
            JOIN terms t ON p.term_id = t.term_id 
            WHERE p.doc_id IN ($doc_ids_placeholder) AND t.term_text IN ($term_text_placeholder)";

    $stmt = $conn->prepare($sql);
    $types = str_repeat('i', count($candidate_docs)) . str_repeat('s', count($phrase_tokens));
    $stmt->bind_param($types, ...$candidate_docs, ...$phrase_tokens);
    $stmt->execute();
    $result = $stmt->get_result();

    // Agrupar las posiciones por documento y término en un array de PHP
    while ($row = $result->fetch_assoc()) {
        if (!isset($positions_by_doc[$row['doc_id']])) {
            $positions_by_doc[$row['doc_id']] = [];
        }
        $positions_by_doc[$row['doc_id']][$row['term_text']] = explode(',', $row['positions']);
    }
    $stmt->close();
    // --- Fin de la optimización ---
    
    // 3. Verificación posicional en PHP (mucho más rápido)
    $final_doc_ids = [];
    foreach ($positions_by_doc as $doc_id => $positions_by_term) {
        if (count($positions_by_term) < count($phrase_tokens)) continue;

        $first_term_positions = $positions_by_term[$phrase_tokens[0]] ?? [];
        foreach ($first_term_positions as $pos) {
            $is_match = true;
            for ($i = 1; $i < count($phrase_tokens); $i++) {
                // Verificar si el siguiente término existe y si su posición es adyacente
                if (!isset($positions_by_term[$phrase_tokens[$i]]) || !in_array($pos + $i, $positions_by_term[$phrase_tokens[$i]])) {
                    $is_match = false;
                    break;
                }
            }
            if ($is_match) {
                $final_doc_ids[] = $doc_id;
                break; // Encontramos una coincidencia, pasamos al siguiente documento.
            }
        }
    }
    return $final_doc_ids;
}

/**
 * Ejecuta la búsqueda booleana sobre los tokens.
 * @param array $tokens Los tokens de la consulta.
 * @param mysqli $conn Conexión a la BD.
 * @return array Un array con 'doc_ids' y 'debug' info.
 */
function execute_search(array $tokens, $conn) {
     $debug_log = "";
     $all_doc_ids = null;
 
     // --- Shunting-yard Algorithm ---
     $output_queue = [];
     $operator_stack = [];
     $precedence = ['NOT' => 3, 'AND' => 2, 'OR' => 1];
 
     foreach ($tokens as $token) {
         if ($token['type'] !== 'operator') {
             // Si es un operando (término, cadena, etc.), añadir a la cola de salida
             $output_queue[] = $token;
         } elseif ($token['type'] === 'operator') {
             while (
                 !empty($operator_stack) &&
                 ($precedence[end($operator_stack)['value']] ?? 0) >= ($precedence[$token['value']] ?? 0)
             ) {
                 $output_queue[] = array_pop($operator_stack);
             }
             $operator_stack[] = $token;
         }
     }
 
     while (!empty($operator_stack)) {
         $output_queue[] = array_pop($operator_stack);
     }
     // --- Fin de Shunting-yard ---
 
     // --- Evaluación de la notación polaca inversa (RPN) ---
     $evaluation_stack = [];
 
     $apply_op = function($op, $left, $right) use (&$all_doc_ids, $conn) {
         // Asegurarse de que los operandos son arrays
         $left = is_array($left) ? $left : [];
         $right = is_array($right) ? $right : [];
 
         if ($op === 'AND') return array_intersect($left, $right);
         if ($op === 'OR') return array_unique(array_merge($left, $right));
         if ($op === 'NOT') {
             if ($all_doc_ids === null) {
                 $all_doc_ids = [];
                 $result = $conn->query("SELECT doc_id FROM documents");
                 while ($row = $result->fetch_assoc()) $all_doc_ids[] = $row['doc_id'];
             }
             return array_diff($all_doc_ids, $right); // NOT solo usa el operando derecho
         }
         return [];
     };
 
     foreach ($output_queue as $token) {
         if ($token['type'] !== 'operator') {
             // Es un operando, obtener sus doc_ids y empujarlos a la pila
             if ($token['type'] === 'term' || $token['type'] === 'patron') {
                 $is_pattern = $token['type'] === 'patron';
                 $result = get_doc_ids_for_term($token['value'], $is_pattern, $conn);
                 $debug_log .= "Término '{$token['value']}' encontrado en " . count($result) . " documentos.\n";
                 $evaluation_stack[] = $result;
             } elseif ($token['type'] === 'cadena') {
                 $result = get_doc_ids_for_phrase($token['value'], $conn);
                 $debug_log .= "Frase '{$token['value']}' encontrada en " . count($result) . " documentos.\n";
                 $evaluation_stack[] = $result;
             }
         } else {
             // Es un operador, sacar operandos de la pila y aplicar la operación
             $op = $token['value'];
             if ($op === 'NOT') {
                 $operand = array_pop($evaluation_stack);
                 $evaluation_stack[] = $apply_op($op, null, $operand);
             } else {
                 $right = array_pop($evaluation_stack);
                 $left = array_pop($evaluation_stack);
                 $evaluation_stack[] = $apply_op($op, $left, $right);
             }
         }
     }
 
     $final_result = $evaluation_stack[0] ?? [];
     $debug_log .= "Resultado final: " . count($final_result) . " documentos encontrados.";
     return ['doc_ids' => array_values($final_result), 'debug' => $debug_log];
}

/**
 * Calcula las puntuaciones TF-IDF para un conjunto de documentos y términos.
 * @param array $doc_ids IDs de los documentos a puntuar.
 * @param array $query_terms Términos de la consulta.
 * @param mysqli $conn Conexión a la BD.
 * @return array Un array asociativo [doc_id => score].
 */
function calculate_tfidf_scores(array $doc_ids, array $query_terms, $conn) {
    if (empty($doc_ids) || empty($query_terms)) return [];

    $scores = array_fill_keys($doc_ids, 0.0);
    $total_docs_result = $conn->query("SELECT COUNT(doc_id) as total FROM documents");
    $N = $total_docs_result->fetch_assoc()['total'];
    if ($N == 0) return [];

    $doc_ids_placeholder = implode(',', array_fill(0, count($doc_ids), '?'));
    $doc_ids_types = str_repeat('i', count($doc_ids));
    $term_values = array_map(fn($t) => $t['value'], $query_terms);
    $term_placeholder = implode(',', array_fill(0, count($term_values), '?'));
    $term_types = str_repeat('s', count($term_values));

    $sql = "SELECT p.doc_id, p.term_frequency_in_doc, d.total_terms, t.doc_frequency
            FROM postings p
            JOIN terms t ON p.term_id = t.term_id
            JOIN documents d ON p.doc_id = d.doc_id
            WHERE p.doc_id IN ($doc_ids_placeholder) AND t.term_text IN ($term_placeholder)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($doc_ids_types . $term_types, ...$doc_ids, ...$term_values);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $tf = $row['term_frequency_in_doc'] / $row['total_terms'];
        $idf = log($N / $row['doc_frequency']);
        $scores[$row['doc_id']] += $tf * $idf;
    }

    return $scores;
}
?>