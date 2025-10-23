<?php
// parser.php - Versión final y corregida

function parse_query_to_sql($query_string, $conn) {
    // 1. Configuración de campos adaptada a tu estructura de DB
    $fields_config = [
        'default' => true,
        'table' => 'products',
        'fields_to_search' => ['p.product_name', 'p.quantity_per_unit', 'p.category'],
        'select_fields' => 'p.product_code, p.product_name, p.quantity_per_unit, p.category, p.list_price',
        'joins' => ''
    ];

    if (preg_match('/CAMPOS\s*\((.*?)\)/i', $query_string, $matches)) {
        $query_string = trim(preg_replace('/CAMPOS\s*\((.*?)\)/i', '', $query_string));
        
        $fields_str = $matches[1];
        $fields_array = array_map('trim', explode(',', $fields_str));
        
        if (!empty($fields_array[0])) {
            list($table, ) = explode('.', $fields_array[0]);
            $fields_config['default'] = false;
            $fields_config['table'] = $table;
            $fields_config['fields_to_search'] = $fields_array;
            $fields_config['select_fields'] = '*';
            $fields_config['joins'] = '';
        }
    }

    if (empty(trim($query_string))) {
        return [
            'table' => $fields_config['table'], 
            'select' => $fields_config['select_fields'], 
            'joins' => $fields_config['joins'], 
            'where' => '1',
            'is_default' => $fields_config['default']
        ];
    }
    
    // 3. Extraer CADENA() y PATRON()
    $placeholders = [];
    $counter = 0;
    
    $query_string = preg_replace_callback('/PATRON\s*\((.*?)\)/i', function($matches) use (&$placeholders, &$counter, $conn, $fields_config) {
        $term = mysqli_real_escape_string($conn, trim($matches[1]));
        $key = "__PLACEHOLDER_{$counter}__";
        $conditions = array_map(fn($f) => "$f LIKE '%{$term}%'", $fields_config['fields_to_search']);
        $placeholders[$key] = '(' . implode(' OR ', $conditions) . ')';
        $counter++;
        return $key;
    }, $query_string);

    $query_string = preg_replace_callback('/CADENA\s*\((.*?)\)/i', function($matches) use (&$placeholders, &$counter, $conn, $fields_config) {
        $term = mysqli_real_escape_string($conn, trim($matches[1]));
        $key = "__PLACEHOLDER_{$counter}__";
        $conditions = array_map(fn($f) => "$f LIKE '%{$term}%'", $fields_config['fields_to_search']);
        $placeholders[$key] = '(' . implode(' OR ', $conditions) . ')';
        $counter++;
        return $key;
    }, $query_string);
    
    // 4. Lógica de Tokens para manejar correctamente los operadores
    $query_string_padded = preg_replace('/\s*(AND|OR|NOT)\s*/i', ' $1 ', $query_string);
    $tokens = preg_split('/\s+/', trim($query_string_padded), -1, PREG_SPLIT_NO_EMPTY);
    
    $final_tokens = [];
    $last_token_was_term = false;

    foreach($tokens as $token) {
        $is_operator = in_array(strtoupper($token), ['AND', 'OR', 'NOT']);
        if (!$is_operator && $last_token_was_term) {
            $final_tokens[] = 'OR';
        }
        $final_tokens[] = $token;
        $last_token_was_term = !$is_operator;
    }

    $query_string = implode(' ', $final_tokens);
    $query_string = str_ireplace([' AND ', ' OR ', ' NOT '], [' & ', ' | ', ' !'], " $query_string ");
    
    // 5. Construir la cláusula WHERE
    $or_parts = explode('|', $query_string);
    $sql_or_groups = [];

    foreach ($or_parts as $or_part) {
        $and_parts = explode('&', $or_part);
        $sql_and_groups = [];
        foreach ($and_parts as $and_part) {
            $and_part = trim($and_part);
            if (empty($and_part)) continue;

            $is_not = (strpos($and_part, '!') === 0);
            $term = $is_not ? trim(substr($and_part, 1)) : $and_part;

            if (isset($placeholders[$term])) {
                $condition = $placeholders[$term];
            } else {
                $term_escaped = mysqli_real_escape_string($conn, $term);
                $conditions = array_map(fn($f) => "$f LIKE '%{$term_escaped}%'", $fields_config['fields_to_search']);
                $condition = '(' . implode(' OR ', $conditions) . ')';
            }
            
            $sql_and_groups[] = $is_not ? "NOT {$condition}" : $condition;
        }
        if (!empty($sql_and_groups)) {
            $sql_or_groups[] = '(' . implode(' AND ', $sql_and_groups) . ')';
        }
    }
    
    $where_clause = !empty($sql_or_groups) ? implode(' OR ', $sql_or_groups) : '1';

    return [
        'table' => $fields_config['table'],
        'select' => $fields_config['select_fields'],
        'joins' => $fields_config['joins'],
        'where' => $where_clause,
        'is_default' => $fields_config['default']
    ];
}
?>