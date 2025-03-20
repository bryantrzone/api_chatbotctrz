<?php
// CONFIGURACIÓN DEL WEBHOOK
$VERIFY_TOKEN = "falco_verificacion";
$ACCESS_TOKEN = "EAASBWzT6HkkBOweokDwUjyqjwrp1QuBCUY9h1EvGpsdmnv2WZBvzoPz8LCVvTO1GcD2j6MnfO57F1KZBZC4vYsLvw7o4ZBhIHMCypZBHlZB6IoVG9XdUY6VE2ZCEh0aLWV8Uunjhb3BEqZBmr3AZBHTUeZAFP5hN7hjBy8ZCZAezZAmdV3wd620Yturm4YZAb8oZCycZCUUZA70qAk9g89wikgYmZBmBYz8ks9b38pOOhtOiZAHZBSN1P4qzpyZCoE7QZD";
$API_URL = "https://graph.facebook.com/v17.0/YOUR_PHONE_NUMBER_ID/messages";

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
