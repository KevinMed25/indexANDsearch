# Motor de Búsqueda de Documentos con PHP y MySQL

Este proyecto implementa una aplicación web que funciona como un motor de búsqueda de documentos de texto plano. Originalmente concebido para buscar en la base de datos Northwind, ha evolucionado a un sistema completo de Recuperación de Información (IR) con su propio índice y lógica de relevancia.


La aplicación permite a los usuarios subir archivos de texto (`.txt`) y luego realizar búsquedas complejas sobre el contenido de todos los documentos subidos. Los resultados se presentan ordenados por relevancia, mostrando primero los documentos más importantes para la consulta del usuario.

## Explicación del Funcionamiento

El sistema se divide en dos procesos principales: **Indexación** y **Búsqueda**.

### 1. Indexación

Cuando un usuario sube uno o varios archivos de texto:

1.  **Recepción y Almacenamiento**: El `upload_handler.php` recibe los archivos y los guarda en una carpeta `uploads/` en el servidor.
2.  **Procesamiento de Texto**: El `indexer.php` toma cada archivo y lo procesa:
    *   **Normalización**: El texto se convierte a minúsculas, se eliminan acentos y caracteres especiales.
    *   **Tokenización**: El texto se divide en palabras individuales (tokens).
    *   **Eliminación de Stopwords**: Se eliminan palabras comunes sin significado (como "el", "la", "de") para mejorar la calidad de la búsqueda.
3.  **Construcción del Índice Invertido**: La información procesada se almacena en tres tablas de MySQL:
    *   `documents`: Guarda metadatos de cada archivo (nombre, ruta, etc.).
    *   `terms`: Almacena cada palabra única encontrada en la colección.
    *   `postings`: Conecta las palabras con los documentos en los que aparecen, guardando la frecuencia y las posiciones exactas de cada palabra.

### 2. Búsqueda

Cuando un usuario realiza una consulta:

1.  **Análisis de la Consulta**: El `parser.php` analiza la cadena de búsqueda, reconociendo operadores (`AND`, `OR`, `NOT`) y funciones especiales como `CADENA("frase exacta")` y `PATRON(patrón)`.
2.  **Ejecución Booleana**: El `search_engine.php` utiliza el **algoritmo Shunting-yard** para evaluar la lógica booleana de la consulta contra el índice invertido, obteniendo una lista de documentos que coinciden.
3.  **Cálculo de Relevancia**: Para los documentos encontrados, se calcula una puntuación de relevancia utilizando el algoritmo **TF-IDF** (Term Frequency-Inverse Document Frequency).
4.  **Presentación**: Los resultados se muestran al usuario ordenados de mayor a menor relevancia, junto con un fragmento del documento y su puntuación.

## Gui de Uso

### 1. Configuración Inicial

1.  **Servidor**: Asegúrate de tener un entorno de servidor web como XAMPP con Apache y MySQL en funcionamiento.
2.  **Base de Datos**:
    *   Importa la base de datos `northwind.sql` en tu MySQL.
    *   Ejecuta el script `ir_tables.sql` sobre la base de datos `northwind` para crear las tablas del motor de búsqueda (`documents`, `terms`, `postings`).
3.  **Conexión**: Verifica que las credenciales en `db_connection.php` sean correctas para tu entorno.
4.  **Directorio `uploads`**: El script creará automáticamente la carpeta `uploads/` la primera vez que subas un archivo. Asegúrate de que el servidor tenga permisos de escritura en el directorio del proyecto.

### 2. Probar la Indexación

1.  **Crea archivos de prueba**: Crea varios archivos `.txt` con contenido variado.
2.  **Sube los archivos**: Usa el formulario "Indexar Nuevos Documentos" en la página principal para subirlos.
3.  **Verifica la Base de Datos**: Con una herramienta como phpMyAdmin, revisa que las tablas `documents`, `terms` y `postings` se hayan poblado con la información de tus archivos.
4.  **Prueba la re-indexación**: Modifica uno de los archivos y vuelve a subirlo. Verifica que los contadores en la tabla `terms` se actualicen correctamente.

### 3. Probar la Búsqueda y Relevancia

1.  **Búsqueda Simple**: Busca un término que sepas que existe en tus documentos.
2.  **Búsqueda Booleana**: Prueba combinaciones como `termino1 AND termino2`, `termino1 OR termino2` y `termino1 AND NOT termino2`.
3.  **Funciones Especiales**: Prueba `CADENA("una frase exacta de tus documentos")` y `PATRON(parte_de_una_palabra)`.
4.  **Prueba de Relevancia (TF-IDF)**:
    *   Crea un documento donde un término se repita muchas veces (alta relevancia).
    *   Crea otro documento donde el mismo término aparezca solo una vez en un texto largo (baja relevancia).
    *   Busca ese término. El primer documento debería aparecer primero en los resultados con una puntuación más alta.