<?php
// Archivo: api/directorio_handler.php
// Descripción: Gestiona las peticiones CRUD y de lectura para el Directorio de Empleados.

session_start();

header('Content-Type: application/json');

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

// 1. Verificación de Autenticación para acceso total al Directorio (solo lectura para todos, excepto invitados)
$isAuthenticated = isset($_SESSION['user_id']);
$userProfile = $_SESSION['user_profile'] ?? 'invitado';
$isAdminGlobal = $userProfile === 'admin_global';

if (!$isAuthenticated && $userProfile !== 'invitado') {
    // Si no está logeado, redirigir es manejado por el frontend, aquí solo denegamos acceso a datos sensibles.
    // Solo permitimos GETs a usuarios logeados o que al menos puedan ver el slideshow
}
if ($userProfile === 'invitado' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
     sendResponse($conn, 'error', 'Acceso denegado. Se requiere un perfil de usuario para ver el directorio completo.', null, 403);
}

// ====================================================================
// --- LÓGICA DE LECTURA (READ) y Búsqueda/Filtro (GET) ---
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // Lógica para obtener departamentos (para el filtrado en el frontend)
    if (isset($_GET['action']) && $_GET['action'] === 'departments') {
        $sql = "SELECT DISTINCT departamento FROM empleados ORDER BY departamento ASC";
        $result = $conn->query($sql);
        if ($result) {
            $departments = [];
            while ($row = $result->fetch_assoc()) {
                $departments[] = $row['departamento'];
            }
            sendResponse($conn, 'success', 'Departamentos obtenidos exitosamente.', $departments);
        } else {
            sendResponse($conn, 'error', 'Error al obtener departamentos.', null, 500);
        }
    }
    
    // Lógica para obtener empleado individual (para edición)
    if (isset($_GET['action']) && $_GET['action'] === 'get_employee' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT id, nombre, puesto, departamento, email, telefono, ubicacion, foto_url FROM empleados WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $employee = $result->fetch_assoc();
        $stmt->close();

        if ($employee) {
            sendResponse($conn, 'success', 'Empleado obtenido exitosamente.', $employee);
        } else {
            sendResponse($conn, 'error', 'Empleado no encontrado.', null, 404);
        }
    }


    // Lógica principal para obtener empleados (Filtrado/Búsqueda)
    $search_term = $_GET['search'] ?? '';
    $department_filter = $_GET['department'] ?? '';

    // Agregamos 'ubicacion' a la selección
    $sql = "SELECT id, nombre, puesto, departamento, email, telefono, ubicacion, foto_url FROM empleados";
    $conditions = [];
    $params = [];
    $types = '';

    // Condición de Búsqueda por Texto (nombre, puesto, email)
    if (!empty($search_term)) {
        $search_like = '%' . $search_term . '%';
        $conditions[] = "(nombre LIKE ? OR puesto LIKE ? OR email LIKE ?)";
        $params[] = $search_like;
        $params[] = $search_like;
        $params[] = $search_like;
        $types .= 'sss';
    }

    // Condición de Filtrado por Departamento
    if (!empty($department_filter) && $department_filter !== 'Todos') {
        $conditions[] = "departamento = ?";
        $params[] = $department_filter;
        $types .= 's';
    }

    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY nombre ASC";

    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }

    if ($result) {
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        sendResponse($conn, 'success', 'Empleados obtenidos exitosamente.', $employees);
    } else {
        sendResponse($conn, 'error', 'Error al ejecutar la consulta de empleados: ' . $conn->error, null, 500);
    }
} 
// ====================================================================
// --- LÓGICA DE MODIFICACIÓN (CREATE, UPDATE, DELETE) (POST) ---
// ====================================================================
else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Solo el administrador global puede realizar CRUD en el Directorio
    if (!$isAdminGlobal) {
        sendResponse($conn, 'error', 'Permiso denegado. Se requiere perfil de Administrador Global para modificar el Directorio.', null, 403);
    }

    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if ($data === null) {
        sendResponse($conn, 'error', 'Formato JSON inválido o datos faltantes.', null, 400);
    }

    $action = $data['action'] ?? null;
    $id = $data['id'] ?? null;
    $nombre = $data['nombre'] ?? null;
    $puesto = $data['puesto'] ?? null;
    $departamento = $data['departamento'] ?? null;
    $email = $data['email'] ?? null;
    $telefono = $data['telefono'] ?? null;
    $ubicacion = $data['ubicacion'] ?? null;
    $foto_url = $data['foto_url'] ?? null;

    // --- CREACIÓN (CREATE) ---
    if ($action === 'create') {
        if (!$nombre || !$puesto || !$departamento || !$email) {
            sendResponse($conn, 'error', 'Faltan campos obligatorios para crear el empleado.', null, 400);
        }

        $stmt = $conn->prepare("INSERT INTO empleados (nombre, puesto, departamento, email, telefono, ubicacion, foto_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $nombre, $puesto, $departamento, $email, $telefono, $ubicacion, $foto_url);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            sendResponse($conn, 'success', 'Empleado creado exitosamente.', ['id' => $new_id]);
        } else {
            // Verificar error de duplicado (ej. email)
            if ($conn->errno === 1062) {
                sendResponse($conn, 'error', 'Error: El correo electrónico ya está registrado.', null, 409);
            }
            sendResponse($conn, 'error', 'Error al crear el empleado: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    }
    
    // --- ACTUALIZACIÓN (UPDATE) ---
    else if ($action === 'update') {
        if (!$id || !$nombre || !$puesto || !$departamento || !$email) {
            sendResponse($conn, 'error', 'Faltan campos obligatorios para la actualización.', null, 400);
        }

        $stmt = $conn->prepare("UPDATE empleados SET nombre = ?, puesto = ?, departamento = ?, email = ?, telefono = ?, ubicacion = ?, foto_url = ? WHERE id = ?");
        $stmt->bind_param("sssssssi", $nombre, $puesto, $departamento, $email, $telefono, $ubicacion, $foto_url, $id);

        if ($stmt->execute()) {
            sendResponse($conn, 'success', 'Empleado actualizado exitosamente.', ['id' => $id]);
        } else {
            sendResponse($conn, 'error', 'Error al actualizar el empleado: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    }

    // --- ELIMINACIÓN (DELETE) ---
    else if ($action === 'delete') {
        if (!$id) {
            sendResponse($conn, 'error', 'ID del empleado es requerido para eliminar.', null, 400);
        }

        $stmt = $conn->prepare("DELETE FROM empleados WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
             if ($stmt->affected_rows > 0) {
                 sendResponse($conn, 'success', 'Empleado eliminado exitosamente.', ['id' => $id]);
            } else {
                 sendResponse($conn, 'error', 'No se encontró el empleado con el ID proporcionado.', null, 404);
            }
        } else {
            sendResponse($conn, 'error', 'Error al eliminar el empleado: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    } 

    // --- ACCIÓN INVÁLIDA ---
    else {
        sendResponse($conn, 'error', 'Petición no válida o acción no reconocida.', null, 405);
    }
}
?>