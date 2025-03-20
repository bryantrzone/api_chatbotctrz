<?php

require 'config.php';

// CONFIGURACIÓN DEL WEBHOOK
$PHONE_NUMBERID=498027520054701;
$VERIFY_TOKEN = "falco_verificacion";
$ACCESS_TOKEN = "EAASBWzT6HkkBOweokDwUjyqjwrp1QuBCUY9h1EvGpsdmnv2WZBvzoPz8LCVvTO1GcD2j6MnfO57F1KZBZC4vYsLvw7o4ZBhIHMCypZBHlZB6IoVG9XdUY6VE2ZCEh0aLWV8Uunjhb3BEqZBmr3AZBHTUeZAFP5hN7hjBy8ZCZAezZAmdV3wd620Yturm4YZAb8oZCycZCUUZA70qAk9g89wikgYmZBmBYz8ks9b38pOOhtOiZAHZBSN1P4qzpyZCoE7QZD";
$API_URL = "https://graph.facebook.com/v22.0/".$PHONE_NUMBERID."/messages";


if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_verify_token'])) {
    if ($_GET['hub_verify_token'] === $VERIFY_TOKEN) {
        echo $_GET['hub_challenge'];
        exit;
    } else {
        echo "Token inválido.";
        exit;
    }
}

$input = json_decode(file_get_contents("php://input"), true);
$messages = $input['entry'][0]['changes'][0]['value']['messages'] ?? [];

foreach ($messages as $message) {
    $phone_number = corregirFormatoTelefono($message['from']);
    $message_text = strtolower(trim($message['text']['body'] ?? ''));

    file_put_contents("whatsapp_log.txt", "Número: $phone_number, Mensaje recibido: $message_text\n", FILE_APPEND);

    $user_data = cargarHistorialUsuario($phone_number);
    file_put_contents("whatsapp_log.txt", "Historial del usuario: " . json_encode($user_data) . "\n", FILE_APPEND);

    if ($user_data["estado"] === "seleccion_ciudad" && !empty($message_text)) {
        $area = $user_data["area"];
        $ciudad = $message_text;

        guardarHistorialUsuario($phone_number, ["estado" => "seleccion_vacante", "ciudad" => $ciudad]);

        // 🔍 Consultar vacantes desde la BD
        $vacantes = obtenerVacantesDesdeBD($area, $ciudad);
        file_put_contents("whatsapp_log.txt", "Vacantes obtenidas: " . json_encode($vacantes) . "\n", FILE_APPEND);

        if ($vacantes) {
            enviarMensajeInteractivo($phone_number, "📄 *Aquí tienes las vacantes disponibles en $ciudad para $area:*", $vacantes);
        } else {
            enviarMensajeTexto($phone_number, "❌ No encontramos vacantes en esta ciudad. Prueba otra ubicación.");
        }
    }
    elseif ($user_data["estado"] === "seleccion_vacante" && strpos($message_text, "vacante_") !== false) {
        $vacante_id = str_replace("vacante_", "", $message_text);
        guardarHistorialUsuario($phone_number, ["estado" => "solicitar_nombre", "vacante" => $vacante_id]);
        enviarMensajeTexto($phone_number, "📝 *Para continuar, dime tu nombre completo:*");
    }
}

// **FUNCIONES AUXILIARES**
function obtenerVacantesDesdeBD($area, $sucursal) {
    global $pdo;

    file_put_contents("whatsapp_log.txt", "Consultando BD: Área=$area, Sucursal=$sucursal\n", FILE_APPEND);

    $query = "SELECT id, nombre, descripcion, sucursal, horario FROM vacantes WHERE status = 'activo'";
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

    file_put_contents("whatsapp_log.txt", "BD - Resultado de Vacantes: " . json_encode($vacantes) . "\n", FILE_APPEND);

    if (count($vacantes) == 0) {
        return false;
    }

    $opciones = [];
    foreach ($vacantes as $vacante) {
        $opciones[] = [
            "id" => "vacante_".$vacante['id'],
            "title" => $vacante['nombre'],
            "description" => $vacante['sucursal']." - ".$vacante['horario']
        ];
    }

    return $opciones;
}

function enviarMensajeInteractivo($telefono, $mensaje, $opciones = []) {
    global $API_URL, $ACCESS_TOKEN;

    $telefono = corregirFormatoTelefono($telefono);

    $payload = [
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" => $telefono,
        "type" => "interactive",
        "interactive" => [
            "type" => "list",
            "header" => ["type" => "text", "text" => "Vacantes Disponibles"],
            "body" => ["text" => $mensaje],
            "footer" => ["text" => "Powered by Halconet"],
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

    enviarAPI($payload);
}

function enviarMensajeTexto($telefono, $mensaje) {
    global $API_URL, $ACCESS_TOKEN;

    $telefono = corregirFormatoTelefono($telefono);

    $payload = [
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" => $telefono,
        "type" => "text",
        "text" => ["body" => $mensaje]
    ];

    enviarAPI($payload);
}

function enviarAPI($payload) {
    global $API_URL, $ACCESS_TOKEN;

    file_put_contents("whatsapp_log.txt", "Enviando mensaje: " . json_encode($payload) . "\n", FILE_APPEND);

    $response = file_get_contents($API_URL, false, stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Authorization: Bearer $ACCESS_TOKEN\r\nContent-Type: application/json",
            "content" => json_encode($payload)
        ]
    ]));

    file_put_contents("whatsapp_log.txt", "Respuesta de WhatsApp: " . $response . "\n", FILE_APPEND);
}

function corregirFormatoTelefono($telefono) {
    if (preg_match('/^521(\d{10})$/', $telefono, $matches)) {
        return "52" . $matches[1]; // Elimina el "1"
    }
    return $telefono;
}

function guardarHistorialUsuario($telefono, $datos) {
    file_put_contents("usuarios/$telefono.json", json_encode($datos));
}

function cargarHistorialUsuario($telefono) {
    return file_exists("usuarios/$telefono.json") ? json_decode(file_get_contents("usuarios/$telefono.json"), true) : [];
}

?>
