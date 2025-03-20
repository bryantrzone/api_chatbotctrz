<?php
header("Content-Type: application/json");
require '../config.php'; // Asegúrate de que la ruta sea correcta

// Verificar si el método es GET
if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // Obtener los parámetros de la URL
    $area = isset($_GET['Area']) ? urldecode($_GET['Area']) : null;
    $sucursal = isset($_GET['Sucursal']) ? urldecode($_GET['Sucursal']) : null;

    if (!$area && !$sucursal) {
        echo json_encode(["mensaje_vacantes" => "⚠️ Debes especificar un área o sucursal para ver las vacantes disponibles."]);
        exit;
    }

    getVacantesPorFiltros($area, $sucursal);
} else {
    echo json_encode(["mensaje_vacantes" => "❌ Método no permitido."]);
    http_response_code(405);
}

// Función para obtener vacantes filtradas y devolver mensaje formateado
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

    // Si no hay vacantes, enviar un mensaje informativo
    if (count($vacantes) == 0) {
        echo json_encode(["mensaje_vacantes" => "⚠️ No hay vacantes disponibles en $sucursal para el área de $area en este momento."]);
        exit;
    }

    // Generar mensaje dinámico con un máximo de 5 vacantes
    $mensaje = "📢 *Vacantes disponibles en $sucursal ($area):*\n\n";

    $contador = 1;
    foreach ($vacantes as $vacante) {
        $mensaje .= "🔹 *" . $vacante['nombre'] . "*\n";
        $mensaje .= "📍 *Sucursal:* " . $vacante['sucursal'] . "\n";
        $mensaje .= "📝 *Descripción:* " . $vacante['descripcion'] . "\n";
        $mensaje .= "⏰ *Horario:* " . $vacante['horario'] . "\n\n";
        
        if ($contador >= 5) {
            break; // Solo mostrar las primeras 5 vacantes
        }
        $contador++;
    }

    // Agregar enlace para más vacantes si hay más de 5
    if (count($vacantes) > 5) {
        $mensaje .= "🔗 *Ver más vacantes aquí:* [https://halconet.com.mx/empleo](https://halconet.com.mx/empleo)\n";
    }

    echo json_encode(["mensaje_vacantes" => $mensaje]);
}
?>
