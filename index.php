<?php
// index.php - Versión final y corregida
require_once 'db_connection.php';
require_once 'parser.php';

$query_string = isset($_GET['q']) ? $_GET['q'] : '';
$results = [];
$error_message = '';
$sql_query = '';

if (!empty($query_string)) {
    try {
        // Parsear la consulta del usuario para obtener los componentes SQL
        $sql_parts = parse_query_to_sql($query_string, $conn);
        
        // Aplicamos el alias 'p' SÓLO si es una búsqueda por defecto.
        // Para CAMPOS(), usamos el nombre completo de la tabla sin alias.
        $from_table = $sql_parts['table'];
        if ($sql_parts['is_default'] && $sql_parts['table'] === 'products') {
            $from_table = 'products p';
        }

        // Construir la consulta SQL final
        $sql_query = "SELECT {$sql_parts['select']} FROM {$from_table} {$sql_parts['joins']} WHERE {$sql_parts['where']}";
        
        // Ejecutar la consulta
        $result = $conn->query($sql_query);
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $results[] = $row;
            }
        } else {
            $error_message = "Error en la consulta SQL: " . $conn->error;
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
    <title>Búsqueda en Northwind</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <h1>Buscar en Northwind 🔍</h1>
        <form action="index.php" method="get" class="search-form">
            <input type="text" name="q" class="search-input" placeholder="Escribe tu consulta aquí..." value="<?php echo htmlspecialchars($query_string); ?>">
            <button type="submit" class="search-button">Buscar</button>
        </form>

        <?php if ($sql_query): ?>
            <h3>Consulta SQL generada:</h3>
            <div class="debug-sql"><?php echo htmlspecialchars($sql_query); ?></div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <p class="error"><?php echo htmlspecialchars($error_message); ?></p>
        <?php elseif (!empty($query_string)): ?>
            <h2>Resultados de la Búsqueda</h2>
            <?php if (!empty($results)): ?>
                <div class="table-wrapper">
                    <table class="results-table">
                        <thead>
                            <tr>
                                <?php foreach (array_keys($results[0]) as $header): ?>
                                    <th><?php echo htmlspecialchars($header); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $row): ?>
                                <tr>
                                    <?php foreach ($row as $cell): ?>
                                        <td><?php echo htmlspecialchars($cell); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-results">No se encontraron resultados para tu consulta.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>