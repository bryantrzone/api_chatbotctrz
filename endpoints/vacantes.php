<?php
header("Content-Type: application/json");
require '../config.php'; // Conexión a la BD

// Verificar si el método es GET o POST
if ($_SERVER["REQUEST_METHOD"] == "GET") {
    if (isset($_GET['Area']) || isset($_GET['Sucursal'])) {
        getVacantesEnFormatoWhatsApp($_GET['Area'] ?? null, $_GET['Sucursal'] ?? null);
    } elseif (isset($_GET['id_vacante'])) {
        validarDisponibilidad($_GET['id_vacante']);
    } else {
        echo json_encode(["mensaje" => "⚠️ Debes especificar un filtro o ID de vacante."]);
    }
} elseif ($_SERVER["REQUEST_METHOD"] == "POST") {
    $data = json_decode(file_get_contents("php://input"), true);
    if (isset($data['id_vacante'], $data['nombre'], $data['email'], $data['telefono'])) {
        enviarDatosAlReclutador($data);
    } else {
        echo json_encode(["mensaje" => "❌ Datos incompletos."]);
    }
} else {
    echo json_encode(["mensaje" => "❌ Método no permitido."]);
}

// Función para obtener vacantes y devolverlas en formato WhatsApp
function getVacantesEnFormatoWhatsApp($area, $sucursal) {
    global $pdo;
    
    $query = "SELECT id, nombre, descripcion, area, sucursal, horario FROM vacantes WHERE status = 'activo'";
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

    if (count($vacantes) == 0) {
        echo json_encode(["mensaje" => "⚠️ No hay vacantes disponibles en este momento."]);
        exit;
    }

    // Construcción de botones para WhatsApp
    $opciones = [];
    foreach ($vacantes as $vacante) {
        $opciones[] = [
            "id" => "vacante_".$vacante['id'],
            "title" => $vacante['nombre'],
            "description" => $vacante['sucursal']." - ".$vacante['horario']
        ];
    }

    // Formato JSON compatible con WhatsApp Interactive Messages
    $respuestaWhatsApp = [
        "type" => "interactive",
        "interactive" => [
            "type" => "list",
            "header" => [
                "type" => "text",
                "text" => "Vacantes Disponibles"
            ],
            "body" => [
                "text" => "Selecciona la vacante en la que estás interesado:"
            ],
            "footer" => [
                "text" => "Powered by Halconet"
            ],
            "action" => [
                "button" => "Ver vacantes",
                "sections" => [
                    [
                        "title" => "Lista de Vacantes",
                        "rows" => $opciones
                    ]
                ]
            ]
        ]
    ];

    echo json_encode($respuestaWhatsApp);
}

// Función para validar si la vacante sigue disponible
function validarDisponibilidad($id_vacante) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM vacantes WHERE id = ? AND status = 'activo'");
    $stmt->execute([$id_vacante]);
    $vacante = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($vacante) {
        echo json_encode(["mensaje" => "✅ La vacante *{$vacante['nombre']}* aún está disponible. Envíame tus datos para postularte."]);
    } else {
        echo json_encode(["mensaje" => "⚠️ Esta vacante ya no está disponible."]);
    }
}

// Función para enviar los datos al reclutador
function enviarDatosAlReclutador($data) {
    global $pdo;

    // Obtener correo del reclutador
    $stmt = $pdo->prepare("SELECT email_reclutador FROM vacantes WHERE id = ?");
    $stmt->execute([$data['id_vacante']]);
    $vacante = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vacante) {
        echo json_encode(["mensaje" => "❌ No se encontró la vacante."]);
        return;
    }

    $email_reclutador = $vacante['email_reclutador'];

    // Enviar correo
    $asunto = "Nueva postulación para la vacante #{$data['id_vacante']}";
    $mensaje = "Hola,\n\nUn candidato se ha postulado para la vacante #{$data['id_vacante']}.\n\n".
               "👤 Nombre: {$data['nombre']}\n".
               "📧 Email: {$data['email']}\n".
               "📞 Teléfono: {$data['telefono']}\n\n".
               "Saludos,\nSistema de Reclutamiento.";

    mail($email_reclutador, $asunto, $mensaje);

    echo json_encode(["mensaje" => "✅ ¡Postulación enviada! Pronto el reclutador se pondrá en contacto contigo."]);
}
?>
