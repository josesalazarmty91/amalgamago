<?php
// Archivo: api/slideshow_handler.php
// Descripción: Gestiona las peticiones relacionadas con los slides (CRUD).

// Establece la cabecera para indicar que la respuesta será en formato JSON.
// Esto es crucial para que el navegador sepa cómo interpretar los datos.
header('Content-Type: application/json');

// Incluimos nuestro script de conexión a la base de datos.
// Si la conexión falla, el script 'db_connect.php' se encargará de detener todo
// y enviar una respuesta de error, por lo que no necesitamos verificarlo de nuevo aquí.
require_once __DIR__ . '/db_connect.php';

// -- LÓGICA PARA OBTENER LOS SLIDES (LEER / READ) --

// Preparamos la consulta SQL para seleccionar todos los campos de la tabla 'slideshow'.
// Ordenamos por 'id' de forma descendente para que los slides más nuevos aparezcan primero.
$sql = "SELECT id, titulo, descripcion, imagen_url, fecha_creacion FROM slideshow ORDER BY id DESC";

// Ejecutamos la consulta en la base de datos.
$result = $conn->query($sql);

// Verificamos si la consulta fue exitosa.
if ($result) {
    // Si hay resultados, los procesamos.
    $slides = []; // Creamos un array vacío para almacenar los slides.
    
    // Iteramos sobre cada fila que devolvió la consulta.
    // fetch_assoc() convierte cada fila en un array asociativo (clave => valor).
    while ($row = $result->fetch_assoc()) {
        $slides[] = $row; // Añadimos la fila al array de slides.
    }
    
    // Devolvemos el array de slides codificado en formato JSON.
    // El frontend recibirá este JSON y lo usará para construir el carrusel.
    echo json_encode(['status' => 'success', 'data' => $slides]);
    
} else {
    // Si hubo un error al ejecutar la consulta SQL.
    http_response_code(500); // Error interno del servidor.
    echo json_encode(['status' => 'error', 'message' => 'Error al ejecutar la consulta en la base de datos.']);
}

// Cerramos la conexión a la base de datos para liberar recursos.
$conn->close();
