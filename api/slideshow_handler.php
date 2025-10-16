<?php
// Archivo: api/slideshow_handler.php
// Descripción: Gestiona las peticiones relacionadas con los slides (CRUD) con protección de sesión.

// Iniciar sesión para acceder a $_SESSION
session_start();

header('Content-Type: application/json');

// Incluimos nuestro script de conexión a la base de datos.
require_once __DIR__ . '/db_connect.php'; 

// Definimos la función principal para devolver una respuesta JSON y cerrar la conexión.
function sendResponse($conn, $status, $message, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    $conn->close();
    die();
}

// ====================================================================
// --- VERIFICACIÓN DE PERFIL ---
// ====================================================================
$current_profile = $_SESSION['user_profile'] ?? 'invitado'; // Asignamos 'invitado' si no hay sesión

// Chequeamos si el usuario tiene permiso para MODIFICAR (CRUD que no sea READ)
$can_modify = in_array($current_profile, ['admin_global', 'diseno']);


// Obtenemos el método de la petición HTTP
$method = $_SERVER['REQUEST_METHOD'];


// ====================================================================
// --- LÓGICA DE LECTURA (READ) ---
// La lectura está permitida para CUALQUIER usuario (incluyendo invitados)
// ====================================================================
if ($method === 'GET') {
    // Si no es un admin, solo devolvemos los campos esenciales (seguridad por si acaso)
    $sql_select_fields = "id, titulo, descripcion, imagen_url, fecha_creacion";
    $sql = "SELECT $sql_select_fields FROM slideshow ORDER BY id DESC";

    $result = $conn->query($sql);

    if ($result) {
        $slides = [];
        while ($row = $result->fetch_assoc()) {
            $slides[] = $row;
        }
        sendResponse($conn, 'success', 'Slides obtenidos exitosamente.', $slides);
    } else {
        sendResponse($conn, 'error', 'Error al ejecutar la consulta SELECT en la base de datos.', null, 500);
    }
} 
// ====================================================================
// --- LÓGICA DE MODIFICACIÓN (CREATE, UPDATE, DELETE) ---
// ====================================================================
else {
    // 1. Verificar si el usuario puede modificar
    if (!$can_modify) {
        sendResponse($conn, 'error', 'Acceso denegado. Se requiere el perfil de Administrador Global o Diseño para modificar slides.', null, 403);
    }

    // Leemos el contenido de la petición (debe ser JSON)
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if ($data === null) {
        sendResponse($conn, 'error', 'Formato JSON inválido o datos faltantes.', null, 400);
    }

    $action = $data['action'] ?? null;
    $id = $data['id'] ?? null;
    $titulo = $data['title'] ?? null;
    $descripcion = $data['description'] ?? null;
    $imagen_url = $data['image-url'] ?? null;

    // --- CREACIÓN (CREATE) ---
    if ($action === 'create') {
        if (!$titulo || !$descripcion || !$imagen_url) {
            sendResponse($conn, 'error', 'Faltan campos requeridos (título, descripción o URL de imagen).', null, 400);
        }

        // Usamos prepared statement para prevenir inyección SQL
        $stmt = $conn->prepare("INSERT INTO slideshow (titulo, descripcion, imagen_url) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $titulo, $descripcion, $imagen_url);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            sendResponse($conn, 'success', 'Slide creado exitosamente.', ['id' => $new_id]);
        } else {
            sendResponse($conn, 'error', 'Error al crear el slide: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    }
    
    // --- ACTUALIZACIÓN (UPDATE) ---
    else if ($action === 'update') {
        if (!$id || !$titulo || !$descripcion || !$imagen_url) {
            sendResponse($conn, 'error', 'Faltan campos requeridos para la actualización.', null, 400);
        }

        // Usamos prepared statement
        $stmt = $conn->prepare("UPDATE slideshow SET titulo = ?, descripcion = ?, imagen_url = ? WHERE id = ?");
        $stmt->bind_param("sssi", $titulo, $descripcion, $imagen_url, $id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                 sendResponse($conn, 'success', 'Slide actualizado exitosamente.', ['id' => $id]);
            } else {
                 sendResponse($conn, 'success', 'Slide actualizado exitosamente (o no se encontraron cambios).', ['id' => $id]);
            }
        } else {
            sendResponse($conn, 'error', 'Error al actualizar el slide: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    }

    // --- ELIMINACIÓN (DELETE) ---
    else if ($action === 'delete') {
        if (!$id) {
            sendResponse($conn, 'error', 'ID del slide es requerido para eliminar.', null, 400);
        }

        // Usamos prepared statement
        $stmt = $conn->prepare("DELETE FROM slideshow WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
             if ($stmt->affected_rows > 0) {
                 sendResponse($conn, 'success', 'Slide eliminado exitosamente.', ['id' => $id]);
            } else {
                 sendResponse($conn, 'error', 'No se encontró el slide con el ID proporcionado.', null, 404);
            }
        } else {
            sendResponse($conn, 'error', 'Error al eliminar el slide: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    } 

    // --- MÉTODO NO SOPORTADO O ACCIÓN INVÁLIDA ---
    else {
        sendResponse($conn, 'error', 'Petición no válida o acción no reconocida.', null, 405);
    }
}
?>
