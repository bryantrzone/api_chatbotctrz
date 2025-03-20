<?php
// CONFIGURACIÓN DEL WEBHOOK DE WHATSAPP BUSINESS API

$VERIFY_TOKEN = "falco_verificacion"; // Debe coincidir con el ingresado en Meta
$ACCESS_TOKEN = "EAASBWzT6HkkBO2Vds4Iq2B9J2x3sMbexKVWbc6Oxz9ZCn0YZChOo7heFZBDqMWQZB3qPEMEXYrcraZCJSxbkhEuhtrdJ6koHQsuEiCmXHHU3H77jVyefcjiTZCEwMnPDAAj33Dxep8xGuaGT0kXJCtwxxZCuwo9AurdpQxCvzQofqrZAanxRqilcGCSI7NiMgBATBuVI42Q4maWhfQRWyWFsgGnPuhvkrxL1RuY25ri2hsNXSYRdXhMZD"; // Generado en Meta Developers
$API_URL = "https://graph.facebook.com/v17.0/YOUR_PHONE_NUMBER_ID/messages";

// 1️⃣ **Verificar el Webhook en WhatsApp**
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_verify_token'])) {
    if ($_GET['hub_verify_token'] === $VERIFY_TOKEN) {
        echo $_GET['hub_challenge']; // Responder con el reto de verificación
        exit;
    } else {
        echo "Token inválido.";
        exit;
    }
}

// 2️⃣ **Recibir mensajes de WhatsApp**
$input = json_decode(file_get_contents("php://input"), true);
$messages = $input['entry'][0]['changes'][0]['value']['messages'] ?? [];

foreach ($messages as $message) {
    $phone_number = $message['from']; // Número del usuario
    $text = strtolower(trim($message['text']['body'] ?? ''));

    // 3️⃣ **Responde con un mensaje de bienvenida**
    if ($text === "hola" || $text === "inicio") {
        enviarMensaje($phone_number, "😊 *¡Bienvenido! Soy Falco, tu asistente virtual 🤖.*\n\nEstoy aquí para resolver tus dudas y guiarte en lo que necesites.\n\n*¿Cómo puedo ayudarte hoy?*",
            [
                ["id" => "trabajo", "title" => "Bolsa de Trabajo"],
                ["id" => "clientes", "title" => "Atención a Clientes"],
                ["id" => "cotizacion", "title" => "Cotización"]
            ]
        );
    }
}

// 4️⃣ **Funciones para enviar mensajes**
function enviarMensaje($telefono, $mensaje, $opciones = []) {
    global $API_URL, $ACCESS_TOKEN;
    $payload = [
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" => $telefono,
        "type" => "interactive",
        "interactive" => [
            "type" => "list",
            "header" => ["type" => "text", "text" => "Selecciona una opción"],
            "body" => ["text" => $mensaje],
            "action" => ["button" => "Elegir", "sections" => [["title" => "Opciones", "rows" => $opciones]]]
        ]
    ];
    enviarAPI($payload);
}

function enviarAPI($payload) {
    global $API_URL, $ACCESS_TOKEN;
    file_get_contents($API_URL, false, stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Authorization: Bearer $ACCESS_TOKEN\r\nContent-Type: application/json",
            "content" => json_encode($payload)
        ]
    ]));
}

?>
