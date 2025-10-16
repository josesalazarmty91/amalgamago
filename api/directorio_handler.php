<?php
// Archivo: api/directorio_handler.php
// Descripción: Gestiona las peticiones de lectura (READ) para el Directorio de Empleados.

// Iniciar sesión para usar $_SESSION (necesario para verificar si el usuario está logeado)
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

// 1. Verificación de Autenticación
// Solo usuarios autenticados (no invitados) pueden ver el directorio.
// NOTA: La lógica de perfiles avanzados se podría añadir aquí si fuera necesario.
if (!isset($_SESSION['user_id']) || ($_SESSION['user_profile'] ?? 'invitado') === 'invitado') {
    sendResponse($conn, 'error', 'Acceso denegado. Se requiere autenticación para ver el directorio.', null, 403);
}

// 2. Lógica para obtener departamentos (para el filtrado en el frontend)
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

// 3. Lógica principal para obtener empleados (Filtrado/Búsqueda)
$search_term = $_GET['search'] ?? '';
$department_filter = $_GET['department'] ?? '';

$sql = "SELECT id, nombre, puesto, departamento, email, telefono, foto_url FROM empleados";
$conditions = [];
$params = [];
$types = '';

// a) Condición de Búsqueda por Texto (nombre o puesto)
if (!empty($search_term)) {
    $conditions[] = "(nombre LIKE ? OR puesto LIKE ?)";
    $params[] = '%' . $search_term . '%';
    $params[] = '%' . $search_term . '%';
    $types .= 'ss';
}

// b) Condición de Filtrado por Departamento
if (!empty($department_filter) && $department_filter !== 'Todos') {
    $conditions[] = "departamento = ?";
    $params[] = $department_filter;
    $types .= 's';
}

// Construcción final de la consulta
if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY nombre ASC";

// 4. Ejecución del Prepared Statement
if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    // bind_param requiere el string de tipos y luego los parámetros individuales.
    // Usamos el operador de dispersión (...) para pasar los parámetros de forma dinámica.
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    // Si no hay parámetros, ejecutamos la consulta simple
    $result = $conn->query($sql);
}

// 5. Devolver resultados
if ($result) {
    $employees = [];
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    sendResponse($conn, 'success', 'Empleados obtenidos exitosamente.', $employees);
} else {
    // Si hubo un error en la ejecución, incluso si es una consulta simple.
    sendResponse($conn, 'error', 'Error al ejecutar la consulta de empleados: ' . $conn->error, null, 500);
}

$conn->close();
?>
