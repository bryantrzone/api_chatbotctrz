<?php
// Configuración básica para errores
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/webhook_error.log');
error_reporting(E_ALL);

// Incluir configuración de base de datos
require_once "db.php";

// Obtener datos de la solicitud entrante
$data = json_decode(file_get_contents("php://input"), true);

// Configuración de WhatsApp Business API
$PHONE_NUMBERID = $config['PHONE_NUMBERID'];
$VERIFY_TOKEN   = $config['VERIFY_TOKEN'];
$ACCESS_TOKEN   = $config['ACCESS_TOKEN'];
$API_URL        = "https://graph.facebook.com/v22.0/$PHONE_NUMBERID/messages";

// Verificación de Webhook (requerido por Meta)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_verify_token'])) {
    if ($_GET['hub_verify_token'] === $VERIFY_TOKEN) {
        echo $_GET['hub_challenge'];
        exit;
    } else {
        echo "Token inválido.";
        exit;
    }
}

// Registro del mensaje recibido para debugging
file_put_contents("whatsapp_log.txt", "\n📩 Mensaje recibido: " . json_encode($data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

// Extraer información del mensaje
$phone = '';
$message = '';
$messageType = '';
$userName = '';
$timestamp = '';

if (isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
    $messageData = $data['entry'][0]['changes'][0]['value']['messages'][0];
    $phone = $messageData['from'];
    $messageType = $messageData['type'];
    $timestamp = $messageData['timestamp'] ?? time();
    
    // Extraer el mensaje según su tipo
    switch ($messageType) {
        case 'text':
            $message = $messageData['text']['body'];
            break;
        case 'interactive':
            $interactive = $messageData['interactive'];
            if ($interactive['type'] === 'button_reply') {
                $message = $interactive['button_reply']['id'];
            } elseif ($interactive['type'] === 'list_reply') {
                $message = $interactive['list_reply']['id'];
            }
            break;
        case 'button':
            $message = $messageData['button']['payload'];
            break;
        case 'image':
            $message = $messageData['image']['caption'] ?? 'Imagen recibida';
            // Puedes almacenar la URL de la imagen si es necesario
            $mediaUrl = $messageData['image']['url'] ?? '';
            $mediaId = $messageData['image']['id'] ?? '';
            break;
        case 'audio':
            $message = 'Nota de audio recibida';
            $mediaUrl = $messageData['audio']['url'] ?? '';
            $mediaId = $messageData['audio']['id'] ?? '';
            break;
        case 'video':
            $message = $messageData['video']['caption'] ?? 'Video recibido';
            $mediaUrl = $messageData['video']['url'] ?? '';
            $mediaId = $messageData['video']['id'] ?? '';
            break;
        case 'document':
            $message = $messageData['document']['caption'] ?? $messageData['document']['filename'] ?? 'Documento recibido';
            $mediaUrl = $messageData['document']['url'] ?? '';
            $mediaId = $messageData['document']['id'] ?? '';
            break;
        case 'location':
            $latitude = $messageData['location']['latitude'] ?? '';
            $longitude = $messageData['location']['longitude'] ?? '';
            $message = "Ubicación: $latitude, $longitude";
            $address = $messageData['location']['address'] ?? '';
            if ($address) {
                $message .= " ($address)";
            }
            break;
        case 'sticker':
            $message = 'Sticker recibido';
            $mediaId = $messageData['sticker']['id'] ?? '';
            break;
        case 'contacts':
            $message = 'Contacto(s) recibido(s)';
            // Podrías extraer más detalles si es necesario
            break;
        default:
            $message = 'Contenido no reconocido';
            break;
    }
    
    // Obtener nombre del contacto si está disponible
    if (isset($data['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'])) {
        $userName = $data['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'];
    }
    
    // Preparar datos de medios adicionales si existen
    $mediaData = [
        'url' => $mediaUrl ?? '',
        'id' => $mediaId ?? '',
        'additional_info' => ''
    ];
    
    // Añadir información adicional según el tipo de medio
    if ($messageType === 'location' && isset($latitude) && isset($longitude)) {
        $mediaData['additional_info'] = json_encode([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => $address ?? ''
        ]);
    }
    
    // Guardar mensaje recibido en la base de datos
    guardarMensaje($pdo, $phone, $message, $messageType, 'recibido', $timestamp, $userName, $mediaData);
    
    // // Respuesta simple (opcional)
    // $respuesta = [
    //     "type" => "text",
    //     "body" => "Mensaje recibido. Gracias."
    // ];
    
    // // Enviar respuesta
    // $responseData = enviarRespuesta($respuesta, $phone);
    
    // Guardar respuesta enviada
    // guardarMensaje($pdo, $phone, $respuesta['body'], 'text', 'enviado', time(), $userName);
    
} else {
    file_put_contents("whatsapp_log.txt", "⚠️ No se recibió mensaje válido en formato esperado.\n", FILE_APPEND);
    http_response_code(400);
    echo "Formato no compatible";
    exit;
}

/**
 * Guarda el mensaje en la base de datos
 */
function guardarMensaje($pdo, $phone, $message, $type, $direction, $timestamp, $userName = '', $mediaData = []) {
    try {
        // Si el usuario no existe, insertarlo
        if (!empty($userName)) {
            $userStmt = $pdo->prepare("
                INSERT INTO users (phone_number, name, last_activity) 
                VALUES (?, ?, FROM_UNIXTIME(?))
                ON DUPLICATE KEY UPDATE 
                    name = IF(name = '' OR name IS NULL, VALUES(name), name),
                    last_activity = FROM_UNIXTIME(?)
            ");
            $userStmt->execute([$phone, $userName, $timestamp, $timestamp]);
        } else {
            // Actualizar solo la actividad
            $userStmt = $pdo->prepare("
                INSERT INTO users (phone_number, last_activity) 
                VALUES (?, FROM_UNIXTIME(?))
                ON DUPLICATE KEY UPDATE last_activity = FROM_UNIXTIME(?)
            ");
            $userStmt->execute([$phone, $timestamp, $timestamp]);
        }
        
        // Insertar el mensaje con información multimedia si existe
        $mediaUrl = isset($mediaData['url']) ? $mediaData['url'] : '';
        $mediaId = isset($mediaData['id']) ? $mediaData['id'] : '';
        $additionalInfo = isset($mediaData['additional_info']) ? $mediaData['additional_info'] : '';
        
        $msgStmt = $pdo->prepare("
            INSERT INTO messages (
                phone_number, 
                message, 
                message_type, 
                direction, 
                timestamp,
                media_url,
                media_id,
                additional_info
            ) VALUES (
                ?, ?, ?, ?, FROM_UNIXTIME(?), ?, ?, ?
            )
        ");
        $msgStmt->execute([
            $phone, 
            $message, 
            $type, 
            $direction, 
            $timestamp, 
            $mediaUrl, 
            $mediaId, 
            $additionalInfo
        ]);
        
        file_put_contents("whatsapp_log.txt", "✅ Mensaje guardado en la BD: $phone - $message\n", FILE_APPEND);
    } catch (PDOException $e) {
        file_put_contents("whatsapp_log.txt", "❌ Error al guardar mensaje: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

/**
 * Envía la respuesta a WhatsApp
 */
function enviarRespuesta($respuesta, $telefono) {
    global $API_URL, $ACCESS_TOKEN;

    if (!$telefono) {
        file_put_contents("whatsapp_log.txt", "⚠️ No se proporcionó teléfono para envío.\n", FILE_APPEND);
        return false;
    }

    $payload = [
        "messaging_product" => "whatsapp",
        "to" => $telefono,
        "type" => $respuesta['type']
    ];

    if ($respuesta['type'] === 'text') {
        $payload['text'] = ["body" => $respuesta['body']];
    } elseif ($respuesta['type'] === 'interactive') {
        // Configuración para mensajes interactivos
        $payload['interactive'] = $respuesta['interactive'];
    }

    // Usar CURL para enviar la solicitud
    $ch = curl_init($API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $ACCESS_TOKEN",
            "Content-Type: application/json"
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($response === false) {
        $error = curl_error($ch);
        file_put_contents("whatsapp_log.txt", "❌ Error al enviar mensaje: " . $error . "\n", FILE_APPEND);
        curl_close($ch);
        return false;
    } else {
        file_put_contents("whatsapp_log.txt", "✅ Mensaje enviado a $telefono\nCódigo HTTP: $httpCode\nRespuesta: $response\n", FILE_APPEND);
        curl_close($ch);
        return $response;
    }
}
?>