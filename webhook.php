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
    $phone_number = $message_data['from']; // Número del usuario
    $message_text = strtolower(trim($message_data['text']['body'] ?? ''));

    // **Guardar logs del mensaje recibido**
    file_put_contents("whatsapp_log.txt", "Número: $phone_number, Mensaje: $message_text\n", FILE_APPEND);

    // **3️⃣ Responder al usuario**
    enviarMensajeTexto($phone_number, "¡Hola! Recibí tu mensaje: *$message_text* 🤖");
}

// **4️⃣ Función para enviar respuestas a WhatsApp**
function enviarMensajeTexto($telefono, $mensaje) {
    global $API_URL, $ACCESS_TOKEN;

    // **Corrección automática del número de México**
    if (preg_match('/^521(\d{10})$/', $telefono, $matches)) {
        $telefono = "52" . $matches[1]; // Elimina el "1" después del código de país
    }

    $payload = [
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" => $telefono,
        "type" => "text",
        "text" => ["body" => $mensaje]
    ];

    // **Guardar logs del mensaje enviado**
    file_put_contents("whatsapp_log.txt", "Enviando respuesta a $telefono: $mensaje\n", FILE_APPEND);

    $context = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Authorization: Bearer $ACCESS_TOKEN\r\nContent-Type: application/json",
            "content" => json_encode($payload)
        ]
    ]);

    $response = file_get_contents($API_URL, false, $context);

    // **Guardar logs de la respuesta de WhatsApp**
    file_put_contents("whatsapp_log.txt", "Respuesta de WhatsApp: " . $response . "\n", FILE_APPEND);
}

?>
