<?php
// Archivo: api/auth_handler.php
// Descripción: Gestiona el login, logout y la actualización del perfil/contraseña del usuario.

session_start();

header('Content-Type: application/json');

// Incluimos nuestro script de conexión a la base de datos.
require_once __DIR__ . '/db_connect.php'; 

// Función de utilidad para cerrar la conexión y enviar la respuesta JSON
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

$method = $_SERVER['REQUEST_METHOD'];

// Obtenemos el cuerpo de la petición (para POST)
$data = [];
if ($method === 'POST') {
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);
    if ($data === null) {
        // Solo enviamos error si el cuerpo era POST y no se pudo decodificar
        if (!isset($data['action'])) {
            sendResponse($conn, 'error', 'Formato JSON inválido o acción no especificada.', null, 400);
        }
    }
}

// ====================================================================
// --- LÓGICA DE AUTENTICACIÓN (LOGIN, LOGOUT, CHECK_SESSION) ---
// ====================================================================

if ($method === 'POST' && isset($data['action'])) {
    
    $action = $data['action'];

    if ($action === 'login') {
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            sendResponse($conn, 'error', 'Faltan credenciales.', null, 400);
        }

        $stmt = $conn->prepare("SELECT id, nombre, email, password_hash, perfil FROM usuarios WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Éxito: Crear sesión
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['nombre'];
            $_SESSION['user_profile'] = $user['perfil'];

            sendResponse($conn, 'success', 'Login exitoso.', [
                'name' => $user['nombre'],
                'profile' => $user['perfil']
            ]);
        } else {
            sendResponse($conn, 'error', 'Email o contraseña incorrectos.', null, 401);
        }
    }

    else if ($action === 'logout') {
        // Destruir sesión
        session_unset();
        session_destroy();
        // Nota: El frontend maneja la redirección a login.html
        sendResponse($conn, 'success', 'Sesión cerrada correctamente.');
    }

    else if ($action === 'check_session') {
        if (isset($_SESSION['user_id'])) {
            sendResponse($conn, 'success', 'Sesión activa.', [
                'name' => $_SESSION['user_name'],
                'profile' => $_SESSION['user_profile'],
                'id' => $_SESSION['user_id']
            ]);
        } else {
            // Nota: El frontend maneja la redirección a login.html si no hay sesión
            sendResponse($conn, 'error', 'No hay sesión activa.', null, 401);
        }
    }
    
    // ====================================================================
    // --- NUEVAS LÓGICAS DE PERFIL (UPDATE_PROFILE, CHANGE_PASSWORD) ---
    // ====================================================================
    
    else if (!isset($_SESSION['user_id'])) {
         sendResponse($conn, 'error', 'Se requiere autenticación para realizar esta acción.', null, 401);
    }
    
    else if ($action === 'update_profile') {
        $user_id = $_SESSION['user_id'];
        $new_name = $data['name'] ?? null;
        $new_photo_url = $data['photo_url'] ?? null;
        
        if (!$new_name) {
            sendResponse($conn, 'error', 'El nombre es obligatorio.', null, 400);
        }
        
        // 1. Actualizar datos en la DB
        $stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, foto_url = ? WHERE id = ?");
        $stmt->bind_param("ssi", $new_name, $new_photo_url, $user_id);

        if ($stmt->execute()) {
            // 2. Actualizar datos de sesión
            $_SESSION['user_name'] = $new_name;
            
            sendResponse($conn, 'success', 'Perfil actualizado correctamente.');
        } else {
            sendResponse($conn, 'error', 'Error al actualizar el perfil: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    }
    
    else if ($action === 'change_password') {
        $user_id = $_SESSION['user_id'];
        $current_password = $data['current_password'] ?? null;
        $new_password = $data['new_password'] ?? null;
        
        if (!$current_password || !$new_password || strlen($new_password) < 6) {
             sendResponse($conn, 'error', 'Contraseña actual y nueva son obligatorias. La nueva debe tener al menos 6 caracteres.', null, 400);
        }
        
        // 1. Verificar la contraseña actual
        $stmt = $conn->prepare("SELECT password_hash FROM usuarios WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if (!$user || !password_verify($current_password, $user['password_hash'])) {
            sendResponse($conn, 'error', 'La contraseña actual es incorrecta.', null, 401);
        }
        
        // 2. Hashear la nueva contraseña y actualizar
        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?");
        $stmt->bind_param("si", $new_password_hash, $user_id);

        if ($stmt->execute()) {
            sendResponse($conn, 'success', 'Contraseña actualizada correctamente. Necesitarás iniciar sesión de nuevo.');
            // Opcional: Cerrar sesión después del cambio de contraseña
            session_unset();
            session_destroy();
        } else {
            sendResponse($conn, 'error', 'Error al cambiar la contraseña: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    }
    
    else {
        // Enviar respuesta si la acción POST no es reconocida
        sendResponse($conn, 'error', 'Acción no reconocida.', null, 400);
    }
}
// ====================================================================
// --- LÓGICA DE LECTURA DE PERFIL (GET) ---
// ====================================================================

else if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_profile') {
     if (!isset($_SESSION['user_id'])) {
         sendResponse($conn, 'error', 'Se requiere autenticación.', null, 401);
     }
     
     $user_id = $_SESSION['user_id'];
     
     $stmt = $conn->prepare("SELECT id, nombre, email, perfil, foto_url FROM usuarios WHERE id = ?");
     $stmt->bind_param("i", $user_id);
     $stmt->execute();
     $result = $stmt->get_result();
     $user_data = $result->fetch_assoc();
     $stmt->close();
     
     if ($user_data) {
         sendResponse($conn, 'success', 'Datos de perfil obtenidos.', $user_data);
     } else {
         sendResponse($conn, 'error', 'Usuario no encontrado.', null, 404);
     }
}

// Si llega un método no manejado (ej. GET sin acción o no POST/GET)
else {
    sendResponse($conn, 'error', 'Método o petición no soportada.', null, 405);
}
?>
