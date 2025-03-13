<?php
header("Content-Type: application/json");
require '../config.php'; // Asegúrate de que la ruta sea correcta


// var_dump($_GET);

// Verificar si el método es GET
if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // Obtener los parámetros de la URL
    $area = isset($_GET['Area']) ? urldecode($_GET['Area']) : null;
    $sucursal = isset($_GET['Sucursal']) ? urldecode($_GET['Sucursal']) : null;

    if (!$area && !$sucursal) {
        echo json_encode(["error" => "Debe especificar al menos un filtro (Área o Sucursal)"]);
        exit;
    }

    getVacantesPorFiltros($area, $sucursal);
} else {
    echo json_encode(["error" => "Método no permitido"]);
    http_response_code(405);
}

// Función para obtener vacantes filtradas
function getVacantesPorFiltros($area, $sucursal) {
    global $pdo;

    // Construir la consulta dinámicamente
    $query = "SELECT * FROM vacantes WHERE status = 'activo'";
    $params = [];

    if ($area) {
        $query .= " AND area = ?";
        $params[] = $area;
    }

    if ($sucursal) {
        $query .= " AND sucursal = ?";
        $params[] = $sucursal;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $vacantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["vacantes" => $vacantes]);
}
?>
