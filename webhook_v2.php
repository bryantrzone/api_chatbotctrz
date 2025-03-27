<?php
/**
 * Webhook dinámico para WhatsApp con flujos configurables desde base de datos
 * 
 * Este webhook maneja todas las interacciones con WhatsApp Cloud API
 * y procesa las conversaciones según los flujos definidos en la base de datos
 */

require 'config.php';
require 'classes/FlowEngine.php';
require 'classes/MessageHandler.php';
require 'classes/Database.php';
require 'classes/Logger.php';

// Inicializar objetos principales
$logger = new Logger('whatsapp_log.txt');
$db = new Database($config['DB_HOST'], $config['DB_NAME'], $config['DB_USER'], $config['DB_PASS']);
$messageHandler = new MessageHandler($config, $logger);
$flowEngine = new FlowEngine($db, $messageHandler, $logger);

// Verificación del Webhook para Meta
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_verify_token'])) {
    if ($_GET['hub_verify_token'] === $config['VERIFY_TOKEN']) {
        echo $_GET['hub_challenge'];
        exit;
    } else {
        echo "Token inválido.";
        exit;
    }
}

// Recibir Mensajes de WhatsApp
$input = json_decode(file_get_contents("php://input"), true);

// Registrar la petición completa para debug
$logger->debug('Webhook recibió:', $input);

// Verificar que el mensaje es válido
if (!$messageHandler->isValidMessage($input)) {
    $logger->error('Mensaje no válido recibido');
    exit;
}

// Extraer los datos del mensaje
$messageData = $messageHandler->extractMessageData($input);
$logger->info("Mensaje recibido de {$messageData['phone']} tipo: {$messageData['type']}");

// Procesar el mensaje según su tipo
try {
    // Buscar o crear sesión
    $session = $flowEngine->getOrCreateSession($messageData['phone']);
    
    // Actualizar datos de la sesión
    $session = $flowEngine->updateSessionData($session, $messageData);
    
    // Verificar si estamos procesando un archivo
    if (in_array($messageData['type'], ['document', 'image', 'audio', 'video'])) {
        $flowEngine->processMediaMessage($session, $messageData);
    } 
    // Procesar mensaje de texto o interactivo
    else {
        $flowEngine->processMessage($session, $messageData);
    }
    
    $logger->info("Mensaje procesado correctamente");
    
} catch (Exception $e) {
    $logger->error("Error al procesar mensaje: " . $e->getMessage());
    // Enviar mensaje genérico de error al usuario
    $messageHandler->sendTextMessage($messageData['phone'], "Lo sentimos, ocurrió un error al procesar tu mensaje. Por favor, intenta nuevamente más tarde.");
}

