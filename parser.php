<?php
// parser.php - Refactorizado para el sistema de Recuperación de Información (IR)
/**
 * parser.php
 *
 * Responsable de analizar la cadena de consulta del usuario. Convierte la entrada
 * en lenguaje natural (con operadores y funciones) en una estructura de tokens que
 * el motor de búsqueda puede entender.
 * @package    NorthwindSearchEngine
 */

require_once 'utils.php';

/**
 * Analiza la cadena de consulta del usuario y la convierte en una estructura de tokens.
 * Maneja operadores (AND, OR, NOT), frases (CADENA) y patrones (PATRON).
 *
 * @param string $query_string La consulta del usuario.
 * @return array Una lista de tokens y operadores.
 */
function parse_query_to_tokens($query_string) {
    // Normalizar la consulta a minúsculas para consistencia
    $query_string = mb_strtolower($query_string, 'UTF-8');

    // 1. Extraer funciones especiales (CADENA, PATRON) y reemplazarlas con placeholders
    $placeholders = [];
    $counter = 0;

    $query_string = preg_replace_callback('/(cadena|patron)\s*\((.*?)\)/i', function($matches) use (&$placeholders, &$counter) {
        $type = strtolower($matches[1]);
        // Extraer el valor y eliminar las comillas dobles o simples de los extremos.
        $value = trim($matches[2]);
        $value = trim($value, "\"'");
        $key = "__PLACEHOLDER_{$counter}__";
        $placeholders[$key] = ['type' => $type, 'value' => $value];
        $counter++;
        return $key;
    }, $query_string);

    // 2. Tokenizar la consulta restante, manejando operadores
    // Añadir espacios alrededor de los operadores para una división segura
    $query_string_padded = preg_replace('/\s*(and|or|not)\s*/i', ' $1 ', $query_string);
    $raw_tokens = preg_split('/\s+/', trim($query_string_padded), -1, PREG_SPLIT_NO_EMPTY);

    // 3. Procesar tokens para insertar 'OR' implícito y construir la estructura final
    $final_tokens = [];
    $last_token_was_term = false;

    foreach ($raw_tokens as $token) {
        $upper_token = strtoupper($token);
        $is_operator = in_array($upper_token, ['AND', 'OR', 'NOT']);

        // Si el token actual no es un operador y el anterior tampoco lo era, inserta un OR
        if (!$is_operator && $last_token_was_term) {
            $final_tokens[] = ['type' => 'operator', 'value' => 'OR'];
        }

        if ($is_operator) {
            $final_tokens[] = ['type' => 'operator', 'value' => $upper_token];
        } elseif (isset($placeholders[$token])) {
            // Es un placeholder de CADENA o PATRON
            $final_tokens[] = $placeholders[$token];
        } else {
            // Es un término simple
            // Normalizamos el término para que coincida con la forma en que se indexa
            $normalized_tokens = normalize_and_tokenize($token);
            if (!empty($normalized_tokens)) {
                $final_tokens[] = ['type' => 'term', 'value' => $normalized_tokens[0]];
            }
        }

        $last_token_was_term = !$is_operator;
    }

    return $final_tokens;
}
?>