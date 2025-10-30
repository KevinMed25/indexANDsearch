<?php
// utils.php
/**
 * utils.php
 *
 * Contiene funciones de utilidad compartidas por diferentes partes de la aplicación,
 * como la normalización y tokenización de texto.
 *
 * @package    DocumentSearchEngine
 */

/**
 * Procesa y normaliza el contenido de un texto para la indexación o consulta.
 * Convierte a minúsculas, elimina puntuación y números, y tokeniza el texto.
 *
 * @param string $content El contenido del texto a procesar.
 * @return array Un array de tokens (palabras) normalizados.
 */
function normalize_and_tokenize($content) {
    // 1. Normalizar y transliterar caracteres a su equivalente ASCII (ej. "é" -> "e")
    if (function_exists('transliterator_transliterate')) {
        // Método preferido y moderno si la extensión intl está habilitada
        $content = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $content);
    } else {
        // Fallback si la extensión intl no está disponible
        // Convierte a minúsculas y luego intenta transliterar a ASCII
        $content = strtolower($content);
        // La función iconv es una alternativa común para la transliteración
        $content = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $content);
    }

    // 2. Eliminar todo lo que no sea letra o espacio
    $content = preg_replace('/[^a-z\s]/', '', $content);
    
    // 3. Dividir en tokens (palabras)
    $tokens = preg_split('/\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY);

    // 4. Definir y eliminar stopwords
    $stopwords = [
        'de', 'la', 'que', 'el', 'en', 'y', 'a', 'los', 'del', 'se', 'las', 'por', 'un',
        'para', 'con', 'no', 'una', 'su', 'al', 'lo', 'como', 'mas', 'pero', 'sus',
        'le', 'ya', 'o', 'este', 'ha', 'me', 'si', 'sin', 'sobre', 'es', 'son'
    ];

    return array_values(array_diff($tokens, $stopwords));
}
?>