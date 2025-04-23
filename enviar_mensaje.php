<?php

// Incluir configuración de base de datos
require_once "db.php";

// Configuración general
$access_token = $config['ACCESS_TOKEN'];
$phone_number_id = $config['PHONE_NUMBERID'];

// Leer entrada JSON
$data = json_decode(file_get_contents('php://input'), true);

// Validaciones básicas
if (!isset($data['telefono'], $data['plantilla'], $data['parametros'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Faltan parámetros obligatorios']);
    exit;
}

// Parámetros principales
$telefono = $data['telefono'];  
$area = $data['area'] ?? '';
$plantilla = $data['plantilla'];
$idioma = $data['idioma'] ?? 'es_MX';

// Obtener parámetros para los diferentes componentes
$parametros_header = $data['parametros']['header'] ?? [];
$parametros_body = $data['parametros']['body'] ?? [];
$parametros_botones = $data['parametros']['button'] ?? [];

// Construir componentes para la plantilla
$components = [];

// 1. Agregar componente de encabezado si existe
if (!empty($parametros_header)) {
    // Determinar el tipo de header basado en el primer parámetro
    $header_type = "text"; // Por defecto
    $header_parameter = [];

    if (isset($parametros_header['type']) && isset($parametros_header['parameter'])) {
        // Si se proporciona el tipo y el parámetro directamente
        $header_type = $parametros_header['type'];
        
        switch ($header_type) {
            case 'text':
                $header_parameter = [
                    ["type" => "text", "text" => $parametros_header['parameter']]
                ];
                break;
            case 'image':
                $header_parameter = [
                    ["type" => "image", "image" => ["link" => $parametros_header['parameter']]]
                ];
                break;
            case 'document':
                $header_parameter = [
                    ["type" => "document", "document" => ["link" => $parametros_header['parameter']]]
                ];
                break;
            case 'video':
                $header_parameter = [
                    ["type" => "video", "video" => ["link" => $parametros_header['parameter']]]
                ];
                break;
        }
    } else {
        // Si solo se proporciona el texto directamente (para compatibilidad)
        $header_parameter = [
            ["type" => "text", "text" => $parametros_header[0] ?? ""]
        ];
    }

    $components[] = [
        "type" => "header",
        "parameters" => $header_parameter
    ];
}

// 2. Agregar componente de cuerpo si existe
if (!empty($parametros_body)) {
    $body_parameters = array_map(function($text) {
        return ["type" => "text", "text" => $text];
    }, $parametros_body);

    $components[] = [
        "type" => "body",
        "parameters" => $body_parameters
    ];
}

// 3. Construir parámetros para botones
foreach ($parametros_botones as $btn) {
    if (!isset($btn['index'], $btn['parameters'])) continue;

    $button_parameters = array_map(function($text) {
        return ["type" => "text", "text" => $text];
    }, $btn['parameters']);

    $components[] = [
        "type" => "button",
        "sub_type" => $btn['sub_type'] ?? "url", // Puede ser 'url' o 'quick_reply'
        "index" => (string)$btn['index'],
        "parameters" => $button_parameters
    ];
}

// Payload final
$payload = [
    "messaging_product" => "whatsapp",
    "to" => $telefono,
    "type" => "template",
    "template" => [
        "name" => $plantilla,
        "language" => ["code" => $idioma],
        "components" => $components
    ]
];

// Verificar si el usuario existe
try {
    // Primero verificamos si el usuario existe
    $checkUser = $pdo->prepare("SELECT 1 FROM users WHERE phone_number = ? LIMIT 1");
    $checkUser->execute([$telefono]);
    
    if (!$checkUser->fetchColumn()) {
        // El usuario no existe, lo creamos
        $createUser = $pdo->prepare("
            INSERT INTO users (phone_number, created_at, last_activity) 
            VALUES (?, NOW(), NOW())
        ");
        $createUser->execute([$telefono]);
    }
} catch (PDOException $e) {
    error_log("Error al verificar/crear usuario: " . $e->getMessage());
}

// Enviar solicitud a la API de WhatsApp
$ch = curl_init("https://graph.facebook.com/v22.0/$phone_number_id/messages");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $access_token",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Analizar la respuesta para obtener el wamid
$wamid = '';
$response_data = json_decode($response, true);

if ($httpcode === 200 && isset($response_data['messages']) && !empty($response_data['messages'][0]['id'])) {
    $wamid = $response_data['messages'][0]['id'];
}

// Registrar respuesta
file_put_contents("whatsapp_api_log.txt", "\n" . date('Y-m-d H:i:s') . " - Envío a $telefono:\n" . 
    "Payload: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n" .
    "Respuesta ($httpcode): $response\n" .
    "WAMID: $wamid\n", 
    FILE_APPEND);

curl_close($ch);

// Guardar el mensaje en la base de datos con el wamid
try {
    // Registrar el mensaje en la BD
    $stmt = $pdo->prepare("
        INSERT INTO messages (
            phone_number, 
            message, 
            message_type, 
            direction, 
            timestamp,
            additional_info,
            wamid
        ) VALUES (?, ?, ?, ?, NOW(), ?, ?)
    ");
    
    $messageInfo = json_encode([
        'plantilla' => $plantilla,
        'area' => $area,
        'payload' => $payload,
        'response' => $response_data
    ]);
    
    $stmt->execute([
        $telefono,
        "Plantilla: $plantilla",
        'template',
        'enviado',
        $messageInfo,
        $wamid
    ]);
    
    // Obtener el ID del mensaje insertado
    $messageId = $pdo->lastInsertId();
    
    // Si no se pudo obtener el wamid inicialmente, pero se insertó el mensaje,
    // podemos preparar una actualización posterior cuando llegue la confirmación de WhatsApp
    if (empty($wamid) && $messageId) {
        file_put_contents("pending_wamid.log", date('Y-m-d H:i:s') . " - Mensaje ID: $messageId, Teléfono: $telefono, Pendiente de wamid\n", FILE_APPEND);
    }
    
} catch (PDOException $e) {
    // Registrar error, pero continuar con la respuesta
    error_log("Error al guardar mensaje en BD: " . $e->getMessage());
    file_put_contents("whatsapp_api_log.txt", "❌ Error al guardar mensaje en BD: " . $e->getMessage() . "\n", FILE_APPEND);
}

// Respuesta
http_response_code($httpcode);
echo $response;