<?php

require 'config.php'; 

// Asignación dinámica
$PHONE_NUMBERID = $config['PHONE_NUMBERID'];
$VERIFY_TOKEN   = $config['VERIFY_TOKEN'];
$ACCESS_TOKEN   = $config['ACCESS_TOKEN'];
$API_URL        = "https://graph.facebook.com/v17.0/$PHONE_NUMBERID/messages";

// **1️⃣ Verificación del Webhook en Meta**
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_verify_token'])) {
    if ($_GET['hub_verify_token'] === $VERIFY_TOKEN) {
        echo $_GET['hub_challenge'];
        exit;
    } else {
        echo "Token inválido.";
        exit;
    }
}

// **2️⃣ Recibir Mensajes de WhatsApp**
$input = json_decode(file_get_contents("php://input"), true);

// **Guardar logs de la solicitud para debug**
file_put_contents("whatsapp_log.txt", json_encode($input, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

// **Verificar que el mensaje es válido**
if (isset($input['entry'][0]['changes'][0]['value']['messages'][0])) {
    $message_data = $input['entry'][0]['changes'][0]['value']['messages'][0];
    $phone_number = corregirFormatoTelefono($message_data['from']); // Número del usuario
    // $message_text = strtolower(trim($message_data['text']['body'] ?? ''));

   // Variables iniciales
    $message_text = "";
    $mensaje_original = "";
    $tipo_mensaje = "texto";

    // Mensaje de texto normal
    if (isset($message_data['text'])) {
        $mensaje_original = trim($message_data['text']['body']);
        $message_text = strtolower($mensaje_original);
        $tipo_mensaje = "texto";
    }

    // Mensaje tipo lista interactiva
    elseif (isset($message_data['interactive']['type']) && $message_data['interactive']['type'] === "list_reply") {
        $message_text = strtolower(trim($message_data['interactive']['list_reply']['id'])); // ID para lógica
        $mensaje_original = trim($message_data['interactive']['list_reply']['title']); // Lo que vio el usuario
        $tipo_mensaje = "list_reply";
    }

    // Guardar en la base de datos si tenemos algo
    if (!empty($message_text)) {
        // $estado = cargarHistorialUsuario($phone_number)['estado'] ?? null;
        $nombre_usuario = $input['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? null;

        guardarMensajeChat(
            $phone_number,
            $mensaje_original,
            $tipo_mensaje,
            null,               // Se llenará la respuesta del bot cuando respondas
            $estado,
            $nombre_usuario
        );
    }


    // **Guardar logs del mensaje recibido**
    file_put_contents("whatsapp_log.txt", "Número: $phone_number, Mensaje: $message_text\n", FILE_APPEND);


    // **3️⃣ Si el usuario envía "Hola", responde con el menú interactivo**
    if ($message_text === "hola") {
        enviarMensajeInteractivo($phone_number, 
            "😊 *¡Bienvenido! Soy Falco, tu asistente virtual 🤖.*\n\nEstoy aquí para resolver tus dudas y guiarte en lo que necesites. \n\n*¿Cómo puedo ayudarte hoy?*",
            [
                ["id" => "bolsa_trabajo", "title" => "Bolsa de Trabajo"],
                ["id" => "atencion_clientes", "title" => "Atención a clientes"],
                ["id" => "cotizacion", "title" => "Cotización"]
            ]
        );
    }

    // **4️⃣ Si el usuario selecciona "Bolsa de Trabajo", responde con áreas laborales**
    elseif ($message_text === "bolsa_trabajo") {
        // Guardamos el nuevo estado
        guardarHistorialUsuario($phone_number, ["estado" => "seleccion_sucursal"]);
    
        $secciones = obtenerListaSucursales();

        enviarMensajeInteractivo(
            $phone_number,
            "🏢 *Estas son las sucursales con vacantes activas.*\n\nPor favor, selecciona la sucursal en la que te gustaría trabajar:",
            $secciones
        );
    }
    
    elseif (strpos($message_text, "sucursal_") === 0) {
        $clave = str_replace("sucursal_", "", $message_text);
    
        // Buscar el nombre de la sucursal
        $stmt = $pdo->prepare("SELECT nombre FROM sucursales WHERE clave = ? AND status = 1");
        $stmt->execute([$clave]);
        $sucursal = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if ($sucursal) {
            $historial = cargarHistorialUsuario($phone_number);
            $historial['estado'] = 'seleccion_area';
            $historial['sucursal'] = $clave;
            $historial['sucursal_nombre'] = $sucursal['nombre'];
            guardarHistorialUsuario($phone_number, $historial);
    
            // Mostrar áreas laborales disponibles
            enviarMensajeInteractivo($phone_number,
                "📌 *Sucursal seleccionada:* {$sucursal['nombre']}.\n\n¿En qué área te gustaría trabajar?",
                [
                    ["id" => "ventas", "title" => "Ventas"],
                    ["id" => "almacen", "title" => "Almacén"],
                    ["id" => "contabilidad", "title" => "Contabilidad"],
                    ["id" => "reparto", "title" => "Reparto"]
                ]
            );
        } else {
            enviarMensajeTexto($phone_number, "⚠️ La sucursal seleccionada no es válida.");
        }
    }
    
    

}

// **4️⃣ Función para enviar respuestas interactivas a WhatsApp**
function enviarMensajeInteractivo($telefono, $mensaje, $opciones = []) {
    global $API_URL, $ACCESS_TOKEN;

    // $telefono = corregirFormatoTelefono($telefono);

    $payload = [
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" => $telefono,
        "type" => "interactive",
        "interactive" => [
            "type" => "list",
            "header" => ["type" => "text", "text" => "Seleccione una opción"],
            "body" => ["text" => $mensaje],
            "footer" => ["text" => "Powered by Halconet"],
            "action" => [
                "button" => "Elegir",
                "sections" => [
                    [
                        "title" => "Opciones de servicio",
                        "rows" => $opciones
                    ]
                ]
            ]
        ]
    ];

    enviarAPI($payload);

    // Guardar en base de datos como respuesta del bot
    // $estado = cargarHistorialUsuario($telefono)['estado'] ?? null;
    // guardarMensajeChat($telefono, null, 'respuesta_interactiva', $mensaje, $estado);
}

function enviarMensajeTexto($telefono, $mensaje) {
    global $API_URL, $ACCESS_TOKEN;

    // $telefono = corregirFormatoTelefono($telefono);

    file_put_contents("whatsapp_log.txt", "Enviando mensaje a $telefono: $mensaje\n", FILE_APPEND);

    $payload = [
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" => $telefono,
        "type" => "text",
        "text" => ["body" => $mensaje]
    ];

    enviarAPI($payload);

    // Guardar la respuesta del bot en la base de datos
    // $estado = cargarHistorialUsuario($telefono)['estado'] ?? null;
    // guardarMensajeChat($telefono, null, 'respuesta', $mensaje, $estado);
}

// **5️⃣ Función para enviar la solicitud a la API de WhatsApp**
function enviarAPI($payload) {
    global $API_URL, $ACCESS_TOKEN;

    file_put_contents("whatsapp_log.txt", "Enviando mensaje: " . json_encode($payload) . "\n", FILE_APPEND);

    $context = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Authorization: Bearer $ACCESS_TOKEN\r\nContent-Type: application/json",
            "content" => json_encode($payload)
        ]
    ]);

    $response = file_get_contents($API_URL, false, $context);

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
    file_put_contents("whatsapp_log.txt", "Guardando historial para $telefono: " . json_encode($datos) . "\n", FILE_APPEND);
}


function guardarMensajeChat($telefono, $mensaje, $tipo = 'texto', $respuesta_bot = null, $estado = null, $nombre_usuario = null) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("INSERT INTO whatsapp_mensajes (telefono, nombre_usuario, mensaje, tipo_mensaje, respuesta_del_bot, flujo_estado) 
                               VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $telefono,
            $nombre_usuario,
            $mensaje,
            $tipo,
            $respuesta_bot,
            $estado
        ]);
    } catch (PDOException $e) {
        file_put_contents("error_log_sql.txt", date('Y-m-d H:i:s') . " | Error en guardarMensajeChat: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

function obtenerListaSucursales() {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT DISTINCT s.clave, s.nombre
        FROM sucursales s
        INNER JOIN vacantes v ON v.sucursal = s.nombre
        WHERE s.status = 1 AND v.status = 'activo'
        ORDER BY s.nombre ASC
    ");
    $stmt->execute();
    $sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($sucursales as $sucursal) {
        $rows[] = [
            "id" => "sucursal_" . $sucursal['clave'],
            "title" => $sucursal['nombre']
        ];
    }

    // Solo una sección si son menos de 10
    return [[
        "title" => "Sucursales disponibles",
        "rows" => $rows
    ]];
}



?>
