<?php

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/webhook_error.log');
error_reporting(E_ALL);


// webhook.php
require_once "db.php";

// $data = json_decode(file_get_contents("php://input"), true);

// header('Content-Type: application/json; charset=utf-8');
$data = json_decode(file_get_contents("mensaje_demo.json"), true);

$PHONE_NUMBERID = $config['PHONE_NUMBERID'];
$VERIFY_TOKEN   = $config['VERIFY_TOKEN'];
$ACCESS_TOKEN   = $config['ACCESS_TOKEN'];
$API_URL        = "https://graph.facebook.com/v22.0/$PHONE_NUMBERID/messages";

// Verificación Webhook Meta
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_verify_token'])) {
    if ($_GET['hub_verify_token'] === $VERIFY_TOKEN) {
        echo $_GET['hub_challenge'];
        exit;
    } else {
        echo "Token inválido.";
        exit;
    }
}



file_put_contents("whatsapp_log.txt", "\n📩 Mensaje recibido: " . json_encode($data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

// if (isset($data['entry']) &&   
//     isset($data['entry'][0]['changes']) && 
//     isset($data['entry'][0]['changes'][0]['value']) && 
//     isset($data['entry'][0]['changes'][0]['value']['messages']) &&
//     isset($data['entry'][0]['changes'][0]['value']['messages'][0]['type'])) {

//     $message = $data['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'];
//     $phone = $data['entry'][0]['changes'][0]['value']['messages'][0]['from'];
// }

// $phone = $data['from'] ?? '';
// $message = strtolower(trim($data['message'] ?? ''));

if (isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
    $messageData = $data['entry'][0]['changes'][0]['value']['messages'][0];
    $phone = $messageData['from'];
    $type = $messageData['type'];

    switch ($type) {
        case 'text':
            $message = strtolower(trim($messageData['text']['body']));
            break;

        case 'interactive':
            $interactive = $messageData['interactive'];
            if ($interactive['type'] === 'button_reply') {
                $message = strtolower(trim($interactive['button_reply']['id']));
            } elseif ($interactive['type'] === 'list_reply') {
                $message = strtolower(trim($interactive['list_reply']['id']));
            }
            break;

        case 'button':
            $message = strtolower(trim($messageData['button']['payload']));
            break;

        default:
            $message = '';
            break;
    }

    file_put_contents("whatsapp_log.txt", "📥 Tipo de mensaje: $type | Texto recibido: $message\n", FILE_APPEND);

} else {
    file_put_contents("whatsapp_log.txt", "⚠️ No se recibió mensaje válido en formato esperado.\n", FILE_APPEND);
    http_response_code(400);
    echo "Formato no compatible";
    exit;
}



file_put_contents("whatsapp_log.txt", "🔍 Verificando tipo de mensaje recibido de $phone: '$message'\n", FILE_APPEND);

if (!$phone || !$message) {
    http_response_code(400);
    echo "Datos faltantes";
    exit;
}

if (preg_match('/^521(\d{10})$/', $phone, $matches)) {
    $phone = '52' . $matches[1];
}

// var_dump($message);
// var_dump($phone);


$triggers = ['hola', 'inicio', 'menu'];
if (in_array($message, $triggers)) {
    iniciarConversacion($phone, $pdo);

    // var_dump($phone, $pdo);

} else {
    continuarFlujo($phone, $message, $pdo);
}

function iniciarConversacion($phone, $pdo) {
    file_put_contents("whatsapp_log.txt", "⚙️ Iniciando conversación con $phone\n", FILE_APPEND);

    // var_dump($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO users_sessions (phone_number, nodo_actual)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE nodo_actual = VALUES(nodo_actual)
    ");
    $stmt->execute([$phone, 'menu_principal']);


    // var_dump($stmt);

    mostrarNodo('menu_principal', $pdo, $phone);
}

function continuarFlujo($phone, $message, $pdo) {
    $stmt = $pdo->prepare("SELECT nodo_actual, context FROM users_sessions WHERE phone_number = ?");
    $stmt->execute([$phone]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    $nodoActual = $session['nodo_actual'] ?? 'inicio';
    $context = json_decode($session['context'] ?? '{}', true);

    // Detectar "postularme_{id}"
    if (strpos($message, 'postularme_') === 0) {
        $vacanteId = (int) str_replace('postularme_', '', $message);
        $stmt = $pdo->prepare("UPDATE users_sessions SET nodo_actual = 'cuestionario', context = JSON_SET(IFNULL(context, '{}'), '$.vacante_id', ?, '$.pregunta_actual', 1) WHERE phone_number = ?");
        $stmt->execute([$vacanteId, $phone]);
        return enviarSiguientePregunta($pdo, $phone);
    }

    if ($nodoActual === 'cuestionario') {
        $vacanteId = $context['vacante_id'];
        $preguntaActual = $context['pregunta_actual'];

        $stmt = $pdo->prepare("SELECT * FROM cuestionarios WHERE vacante_id = ? AND orden = ?");
        $stmt->execute([$vacanteId, $preguntaActual]);
        $pregunta = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pregunta) {
            $stmt = $pdo->prepare("INSERT INTO respuestas_usuarios (phone_number, vacante_id, pregunta_id, campo_respuesta, respuesta) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$phone, $vacanteId, $pregunta['id'], $pregunta['campo_respuesta'], $message]);

            $pdo->prepare("UPDATE users_sessions SET context = JSON_SET(context, '$.pregunta_actual', ?) WHERE phone_number = ?")
                ->execute([$preguntaActual + 1, $phone]);

            return enviarSiguientePregunta($pdo, $phone);
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM flujo_nodos WHERE nombre_nodo = ?");
    $stmt->execute([$nodoActual]);
    $nodo = $stmt->fetch(PDO::FETCH_ASSOC);

    // var_dump($nodo);

    if ($nodo['tipo_fuente'] === 'dinamico') {
        $siguienteNodo = $nodo['siguiente_por_defecto'];

        if (strpos($message, 'sucursal_') === 0) {
            $sucursalId = (int) str_replace('sucursal_', '', $message);
            $pdo->prepare("
                UPDATE users_sessions 
                SET context = JSON_SET(IFNULL(context, '{}'), '$.sucursal_id', ?) 
                WHERE phone_number = ?
            ")->execute([$sucursalId, $phone]);


            // var_dump($siguienteNodo);

            return mostrarNodo($siguienteNodo, $pdo, $phone);
        }

        if (strpos($message, 'area_') === 0) {
            $areaId = (int) str_replace('area_', '', $message);
            $pdo->prepare("UPDATE users_sessions SET context = JSON_SET(IFNULL(context, '{}'), '$.area_id', ?), nodo_actual = ? WHERE phone_number = ?")
                ->execute([$areaId, $siguienteNodo, $phone]);
            return mostrarNodo($siguienteNodo, $pdo, $phone);
        }
    }

    // Opción seleccionada de lista estática
    if ($nodo['opciones']) {
        $opciones = json_decode($nodo['opciones'], true);
        foreach ($opciones as $op) {
            if ($message === $op['id']) {
                $pdo->prepare("UPDATE users_sessions SET nodo_actual = ? WHERE phone_number = ?")
                    ->execute([$op['next'], $phone]);
                return mostrarNodo($op['next'], $pdo, $phone);
            }
        }
    }

    mostrarNodo($nodo['siguiente_por_defecto'], $pdo, $phone);
}

function mostrarNodo($nombreNodo, $pdo, $phone = null) {
    $stmt = $pdo->prepare("SELECT * FROM flujo_nodos WHERE nombre_nodo = ? ORDER BY orden asc");
    $stmt->execute([$nombreNodo]);
    $nodos = $stmt->fetchAll(PDO::FETCH_ASSOC);

   
    file_put_contents("whatsapp_log.txt", "➡️ Mostrando nodo: $nombreNodo para $phone\n", FILE_APPEND);
    
    foreach ($nodos as $nodo) {

        if ($nodo['tipo_fuente'] === 'dinamico') {
            $opciones = obtenerOpcionesDinamicas($nodo['fuente_datos'], $pdo, $phone);
            $respuesta = [
                "type" => "list",
                "body" => $nodo['mensaje'],
                "button" => "Elegir opción",
                "sections" => [[
                    "title" => "Opciones disponibles",
                    "rows" => $opciones
                ]]
            ];
        } elseif ($nodo['tipo'] === 'lista' && $nodo['opciones']) {
            $opciones = json_decode($nodo['opciones'], true);
            $respuesta = [
                "type" => "list",
                "body" => $nodo['mensaje'],
                "button" => "Elegir opción",
                "sections" => [[
                    "title" => "Opciones disponibles",
                    "rows" => array_map(function($op) {
                        return ["id" => $op['id'], "title" => $op['title'], "description" => $op['description'] ?? ""];
                    }, $opciones)
                ]]
            ];
        } else {
            $respuesta = [
                "type" => "text",
                "body" => $nodo['mensaje']
            ];
        }

        # Enviando respuestas...
        enviarRespuesta($respuesta, $phone);
    }
}

function enviarSiguientePregunta($pdo, $phone) {
    $stmt = $pdo->prepare("SELECT context FROM users_sessions WHERE phone_number = ?");
    $stmt->execute([$phone]);
    $context = json_decode($stmt->fetchColumn(), true);

    $vacanteId = $context['vacante_id'];
    $orden = $context['pregunta_actual'];

    $stmt = $pdo->prepare("SELECT * FROM cuestionarios WHERE vacante_id = ? AND orden = ? LIMIT 1");
    $stmt->execute([$vacanteId, $orden]);
    $pregunta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pregunta) {
        $pdo->prepare("UPDATE users_sessions SET nodo_actual = 'fin_cuestionario' WHERE phone_number = ?")
            ->execute([$phone]);
        enviarRespuesta(["type" => "text", "body" => "✅ ¡Gracias! Hemos registrado tu postulación."], $phone);
        return;
    }   

    $pdo->prepare("UPDATE users_sessions SET context = JSON_SET(context, '$.pregunta_id', ?, '$.campo_respuesta', ?) WHERE phone_number = ?")
        ->execute([$pregunta['id'], $pregunta['campo_respuesta'], $phone]);

    if (in_array($pregunta['tipo'], ['boton', 'lista'])) {
        $opciones = json_decode($pregunta['opciones'], true);
        $respuesta = [
            "type" => "list",
            "body" => $pregunta['pregunta'],
            "button" => "Elegir",
            "sections" => [[
                "title" => "Opciones",
                "rows" => array_map(function($op) {
                    return ["id" => $op['id'], "title" => $op['title']];
                }, $opciones)
            ]]
        ];
    } else {
        $respuesta = ["type" => "text", "body" => $pregunta['pregunta']];
    }

    enviarRespuesta($respuesta, $phone);
}

function obtenerOpcionesDinamicas($fuente, $pdo, $phone) {
    $rows = [];

    if ($fuente === 'sucursales') {
        $stmt = $pdo->query("SELECT id, nombre FROM sucursales");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => ["id" => "sucursal_{$r['id']}", "title" => $r['nombre']], $rows);
    }

    if ($fuente === 'areas_trabajo') {
        $stmt = $pdo->prepare("SELECT context FROM users_sessions WHERE phone_number = ?");
        $stmt->execute([$phone]);
        $context = json_decode($stmt->fetchColumn(), true);
        $sucursalId = $context['sucursal_id'] ?? 0;

        $stmt = $pdo->prepare("SELECT id, nombre FROM areas_trabajo WHERE sucursal_id = ?");
        $stmt->execute([$sucursalId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => ["id" => "area_{$r['id']}", "title" => $r['nombre']], $rows);
    }

    if ($fuente === 'vacantes') {
        $stmt = $pdo->prepare("SELECT context FROM users_sessions WHERE phone_number = ?");
        $stmt->execute([$phone]);
        $context = json_decode($stmt->fetchColumn(), true);
        $sucursalId = $context['sucursal_id'] ?? 0;
        $areaId = $context['area_id'] ?? 0;

        $stmt = $pdo->prepare("SELECT id, titulo FROM vacantes WHERE sucursal_id = ? AND area_id = ?");
        $stmt->execute([$sucursalId, $areaId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => ["id" => "postularme_{$r['id']}", "title" => $r['titulo']], $rows);
    }

    return [];
}

// function enviarRespuesta($respuesta) {
//     file_put_contents("whatsapp_log.txt", "📤 Respuesta enviada: " . json_encode($respuesta, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
//     header('Content-Type: application/json');
//     echo json_encode($respuesta);
// } 


function enviarRespuesta($respuesta, $telefono = null) {
    // var_dump($respuesta);
    global $API_URL, $ACCESS_TOKEN;

    file_put_contents("whatsapp_log.txt", "📤 Preparando respuesta: " . json_encode($respuesta, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
    
    // Debug adicional - Registrar variables globales
    file_put_contents("whatsapp_log.txt", "🔍 API_URL: $API_URL, TOKEN disponible: " . (!empty($ACCESS_TOKEN) ? "Sí" : "No") . "\n", FILE_APPEND);

    if (!$telefono) {
        file_put_contents("whatsapp_log.txt", "⚠️ No se proporcionó teléfono para envío.\n", FILE_APPEND);
        return;
    }

    $payload = [
        "messaging_product" => "whatsapp",
        "to" => $telefono,
        "type" => $respuesta['type']
    ];

    if ($respuesta['type'] === 'text') {
        $payload['text'] = ["body" => $respuesta['body']];
    } elseif ($respuesta['type'] === 'list') {
        // Verificar si existe el campo 'button' en la respuesta
        $button_text = isset($respuesta['button']) ? $respuesta['button'] : "Ver opciones";
        // Corregido: En mensajes de tipo 'list', se debe usar 'interactive' en lugar de 'type'
        $payload = [
            "messaging_product" => "whatsapp",
            "to" => $telefono,
            "type" => "interactive",  // Cambiado de $respuesta['type'] a "interactive"
            "interactive" => [
                "type" => "list",
                "header" => ["type" => "text", "text" => "Seleccione una opción"],
                "body" => ["text" => $respuesta['body']],
                "footer" => ["text" => "Powered by Falco"],
                "action" => [
                    "button" => $button_text,  // Usa el texto del botón de la respuesta o el valor predeterminado
                    "sections" => $respuesta['sections']
                ]
            ]
        ];
    }

    // Usar CURL en lugar de file_get_contents para mejor manejo de errores y respuestas HTTP
    $ch = curl_init($API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $ACCESS_TOKEN",
            "Content-Type: application/json"
        ],
        // Opciones para solucionar problemas de SSL
        CURLOPT_SSL_VERIFYPEER => false,  // Deshabilita la verificación del certificado del peer
        CURLOPT_SSL_VERIFYHOST => 0       // No verifica que el nombre común exista
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($response === false) {
        $error = curl_error($ch);
        file_put_contents("whatsapp_log.txt", "❌ Error al enviar mensaje: " . $error . "\n", FILE_APPEND);
    } else {
        file_put_contents("whatsapp_log.txt", "✅ Mensaje enviado a $telefono\nCódigo HTTP: $httpCode\nRespuesta: $response\n", FILE_APPEND);
        
        header('Content-Type: application/json');
        echo json_encode($respuesta);
    }
    
    curl_close($ch);
    
    return $response;
}