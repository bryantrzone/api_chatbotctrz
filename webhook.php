<?php

// CONFIGURACIÓN DEL WEBHOOK
$PHONE_NUMBERID=498027520054701;
$VERIFY_TOKEN = "falco_verificacion";
$ACCESS_TOKEN = "EAASBWzT6HkkBO8lKUQ87aJgdTnzqN5x0ZBHB8enaheTrgpHjsPQLsZCD8wvNcpZABukXcJS5d9VdONHzxyUuH6QV3W80126VSza42d2ZA0ZAHq3yNTBe1FK5ZCQiO4G0nGy119nnd0bJrYLOBNopNTXXZAbxSs9zLl7GErwslwfHySVp37lJgWvfifx5RaEdbZBT4Hadke6w2OozXuJHKO9xi41SbJiFZB1y9vW5PIwJ9K9m06V4GZAqFO";
$API_URL = "https://graph.facebook.com/v22.0/".$PHONE_NUMBERID."/messages";

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

    $message_text = "";
    
    // **Detectar si el mensaje es un texto o una respuesta interactiva**
    if (isset($message_data['text'])) {
        $message_text = strtolower(trim($message_data['text']['body']));
    } elseif (isset($message_data['interactive']['type']) && $message_data['interactive']['type'] === "list_reply") {
        $message_text = strtolower(trim($message_data['interactive']['list_reply']['id'])); // Aquí obtenemos la ID de la opción seleccionada
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
        enviarMensajeInteractivo($phone_number, 
            "📢 *Actualmente contamos con diversas oportunidades laborales.*\n\n_¿En qué área le gustaría trabajar?_",
            [
                ["id" => "ventas", "title" => "Ventas"],
                ["id" => "almacen", "title" => "Almacén"],
                ["id" => "contabilidad", "title" => "Contabilidad"],
                ["id" => "reparto", "title" => "Reparto"]
            ]
        );
    }

    // **5️⃣ Si el usuario selecciona un área laboral, preguntar la ciudad**
    elseif (in_array($message_text, ["ventas", "almacen", "contabilidad", "reparto"])) {
        // Guardamos el área en su historial para la siguiente interacción
        // guardarHistorialUsuario($phone_number, ["estado" => "seleccion_ciudad", "area" => $message_text]);

        // Enviar mensaje preguntando la ciudad
        enviarMensajeTexto($phone_number, "📍 *Mencione la ciudad donde se encuentra (Puebla, CDMX, Tijuana, etc):*");
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
}

// **7️⃣ Función para enviar mensaje de texto normal**
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

?>
