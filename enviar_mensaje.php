<?php

// Mostrar errores en desarrollo
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Manejo de CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    http_response_code(200);
    exit();
}

// Headers para POST y GET normales
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

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
    $header_parameter = [];
    if (isset($parametros_header['type']) && isset($parametros_header['parameter'])) {
        $header_type = $parametros_header['type'];
        switch ($header_type) {
            case 'text':
                $header_parameter = [["type" => "text", "text" => $parametros_header['parameter']]];
                break;
            case 'image':
                $header_parameter = [["type" => "image", "image" => ["link" => $parametros_header['parameter']]]];
                break;
            case 'document':
                $header_parameter = [["type" => "document", "document" => ["link" => $parametros_header['parameter']]]];
                break;
            case 'video':
                $header_parameter = [["type" => "video", "video" => ["link" => $parametros_header['parameter']]]];
                break;
        }
    } else {
        $header_parameter = [["type" => "text", "text" => $parametros_header[0] ?? ""]];
    }
    $components[] = ["type" => "header", "parameters" => $header_parameter];
}

// 2. Agregar componente de cuerpo si existe
if (!empty($parametros_body)) {
    $body_parameters = array_map(fn($text) => ["type" => "text", "text" => $text], $parametros_body);
    $components[] = ["type" => "body", "parameters" => $body_parameters];
}

// 3. Construir parámetros para botones
foreach ($parametros_botones as $btn) {
    if (!isset($btn['index'], $btn['parameters'])) continue;
    $button_parameters = array_map(fn($text) => ["type" => "text", "text" => $text], $btn['parameters']);
    $components[] = [
        "type" => "button",
        "sub_type" => $btn['sub_type'] ?? "url",
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
    $checkUser = $pdo->prepare("SELECT 1 FROM users WHERE phone_number = ? LIMIT 1");
    $checkUser->execute([$telefono]);
    if (!$checkUser->fetchColumn()) {
        $createUser = $pdo->prepare("INSERT INTO users (phone_number, created_at, last_activity) VALUES (?, NOW(), NOW())");
        $createUser->execute([$telefono]);
    }
} catch (PDOException $e) {
    error_log("Error al verificar/crear usuario: " . $e->getMessage());
}

// Enviar solicitud a la API de WhatsApp
$ch = curl_init("https://graph.facebook.com/v22.0/$phone_number_id/messages");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $access_token",
        "Content-Type: application/json"
    ]
]);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$response_data = json_decode($response, true);
$wamid = '';

// MANEJO DE ERRORES SÍNCRONOS
if ($httpcode !== 200) {
    $error_details = $response_data['error'] ?? ['message' => 'Error desconocido', 'type' => 'N/A', 'code' => $httpcode];
    $errorMessage = sprintf(
        "Error SÍNCRONO al enviar plantilla '%s' a %s. Código: %s, Tipo: %s, Mensaje: %s",
        $plantilla,
        $telefono,
        $error_details['code'],
        $error_details['type'],
        $error_details['message']
    );
    notificarErrorAdmin($errorMessage);
} else {
    $wamid = $response_data['messages'][0]['id'] ?? '';
}

// Registrar respuesta en el log principal
file_put_contents("whatsapp_api_log.txt", "\n" . date('Y-m-d H:i:s') . " - Envío a $telefono:\n" . 
    "Payload: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n" . 
    "Respuesta ($httpcode): $response\n" . 
    "WAMID: $wamid\n", 
    FILE_APPEND);

// Guardar el mensaje en la base de datos
try {
    $stmt = $pdo->prepare("\n        INSERT INTO messages (phone_number, message, message_type, direction, timestamp, additional_info, wamid, status)\n        VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)\n    ");
    
    $messageInfo = json_encode([
        'plantilla' => $plantilla,
        'area' => $area,
        'payload' => $payload,
        'response' => $response_data
    ]);

    // Si hubo un error, el estado es 'failed', si no, es 'sent' (aceptado por WhatsApp)
    $status = ($httpcode === 200) ? 'sent' : 'failed';
    
    $stmt->execute([
        $telefono, "Plantilla: $plantilla", 'template', 'enviado', $messageInfo, $wamid, $status
    ]);
    
    $messageId = $pdo->lastInsertId();
    
    if (empty($wamid) && $messageId && $status === 'sent') {
        file_put_contents("pending_wamid.log", date('Y-m-d H:i:s') . " - Mensaje ID: $messageId, Teléfono: $telefono, Pendiente de wamid\n", FILE_APPEND);
    }
    
} catch (PDOException $e) {
    error_log("Error al guardar mensaje en BD: " . $e->getMessage());
    file_put_contents("whatsapp_api_log.txt", "❌ Error al guardar mensaje en BD: " . $e->getMessage() . "\n", FILE_APPEND);
}

// Función de notificación de errores
function notificarErrorAdmin($mensaje) {
    $logMessage = date('Y-m-d H:i:s') . " - " . $mensaje . "\n";
    file_put_contents("failed_messages.log", $logMessage, FILE_APPEND);
}

// Devolver respuesta original de la API de WhatsApp
http_response_code($httpcode);
echo $response;

?>