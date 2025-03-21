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

   // Variables iniciales
    $message_text = "";
    $mensaje_original = "";
    $tipo_mensaje = "texto";

    // Mensaje de texto normal
    if (isset($message_data['text'])) {
        $mensaje_original = trim($message_data['text']['body']);
        $message_text = strtolower($mensaje_original);
        $tipo_mensaje = 'texto';
    } elseif (isset($message_data['interactive']['type']) && $message_data['interactive']['type'] === "list_reply") {
        $message_text = strtolower(trim($message_data['interactive']['list_reply']['id']));
        $mensaje_original = $message_data['interactive']['list_reply']['title'] ?? '';
        $tipo_mensaje = 'lista';
    }

    // Cargar estado actual del usuario
    $historial_usuario = cargarHistorialUsuario($phone_number);
    $estado = $historial_usuario['estado'] ?? null;

    // Guardar en la base de datos si tenemos algo
    if (!empty($message_text)) {
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
    file_put_contents("whatsapp_log.txt", "Número: $phone_number, Mensaje: $message_text, Estado actual: $estado\n", FILE_APPEND);


    // **3️⃣ Si el usuario envía "Hola", responde con el menú interactivo**
    if ($message_text === "hola") {
        enviarMensajeInteractivo($phone_number, 
            "😊 *¡Bienvenido! Soy Falco, tu asistente virtual 🤖.*\n\nEstoy aquí para resolver tus dudas y guiarte en lo que necesites. \n\n*¿Cómo puedo ayudarte hoy?*",
            [
                [
                    "title" => "Opciones de servicio",
                    "rows" => [
                        ["id" => "bolsa_trabajo", "title" => "Bolsa de Trabajo"],
                        ["id" => "atencion_clientes", "title" => "Atención a clientes"],
                        ["id" => "cotizacion", "title" => "Cotización"]
                    ]
                ]
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
        file_put_contents("whatsapp_log.txt", "✅ Entró al bloque de sucursal. mensaje_text: $message_text\n", FILE_APPEND);
    
        $clave = str_replace("sucursal_", "", strtolower(trim($message_text)));
    
        file_put_contents("whatsapp_log.txt", "➡️ Clave extraída: $clave\n", FILE_APPEND);
    
        // Buscar el nombre de la sucursal
        $stmt = $pdo->prepare("SELECT nombre FROM sucursales WHERE clave = ? AND status = 1");
        $stmt->execute([$clave]);
        $sucursal = $stmt->fetch(PDO::FETCH_ASSOC);
    
        file_put_contents("whatsapp_log.txt", "🔎 Resultado de la sucursal: " . json_encode($sucursal) . "\n", FILE_APPEND);
    
        if ($sucursal) {
            $sucursal_nombre = $sucursal['nombre'];
    
            // Guardar historial en MySQL
            $historial = cargarHistorialUsuario($phone_number);
            $historial['estado'] = 'seleccion_area';
            $historial['sucursal'] = $clave;
            $historial['sucursal_nombre'] = $sucursal_nombre;
            guardarHistorialUsuario($phone_number, $historial);
    
            // Consultar áreas disponibles en esa sucursal
            $stmt = $pdo->prepare("SELECT DISTINCT area FROM vacantes WHERE sucursal = ? AND status = 'activo'");
            $stmt->execute([$sucursal_nombre]);
            $areas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
            file_put_contents("whatsapp_log.txt", "🗂️ Áreas encontradas: " . json_encode($areas) . "\n", FILE_APPEND);
    
            if (count($areas) > 0) {
                $area_rows = [];
                foreach ($areas as $area) {
                    $id = strtolower(preg_replace('/\s+/', '_', $area));
                    $area_rows[] = ["id" => $id, "title" => $area];
                }
    
                enviarMensajeInteractivo(
                    $phone_number,
                    "📌 *Sucursal seleccionada:* $sucursal_nombre\n\n¿En qué área te gustaría trabajar?",
                    [[
                        "title" => "Áreas disponibles",
                        "rows" => $area_rows
                    ]]
                );
            } else {
                enviarMensajeTexto($phone_number, "⚠️ No hay vacantes activas en esta sucursal.");
            }
        } else {
            enviarMensajeTexto($phone_number, "⚠️ La sucursal seleccionada no es válida.");
        }
    }

    // Corregido: Usando $estado en lugar de $estado_anterior que no existía
    elseif ($estado === "seleccion_area" || $estado === "mostrar_vacantes") {
        $area = ucwords(str_replace('_', ' ', strtolower($message_text))); // Convierte 'ventas' en 'Ventas'
        $historial = cargarHistorialUsuario($phone_number);
        $sucursal_nombre = $historial['sucursal_nombre'] ?? null;

        if (!$sucursal_nombre) {
            enviarMensajeTexto($phone_number, "⚠️ Hubo un error al recuperar tu sucursal. Si quieres comenzar de nuevo, escribe 'Menú principal'.");
            return;
        }

        // Guardar el área seleccionada en historial
        actualizarHistorialUsuario($phone_number, ["estado" => "mostrar_vacantes", "area" => $area]);

        // Consultar vacantes activas en la sucursal y área
        $stmt = $pdo->prepare("SELECT id, nombre, descripcion, horario FROM vacantes WHERE status = 'activo' AND sucursal = ? AND area = ?");
        $stmt->execute([$sucursal_nombre, $area]);
        $vacantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($vacantes) === 0) {
            enviarMensajeTexto($phone_number, "😕 No se encontraron vacantes activas en *$area* en *$sucursal_nombre*.");
            return;
        }

        // Enviar cada vacante con botones de acción
        foreach ($vacantes as $v) {
            enviarMensajeConBotones(
                $phone_number,
                "📦 *{$v['nombre']}*\n📍 *Sucursal:* $sucursal_nombre\n📝 *Descripción:* {$v['descripcion']}\n⏰ *Horario:* {$v['horario']}",
                [
                    ["id" => "postular_{$v['id']}", "title" => "📩 Postularme"],
                    ["id" => "detalles_{$v['id']}", "title" => "📄 Ver detalles"]
                ]
            );
        }
    }
    
    // Agregar manejo para estado esperando_vacante_id
    elseif ($estado === "esperando_vacante_id") {
        // Verificar que el mensaje es un número de ID
        if (is_numeric($message_text)) {
            $vacante_id = intval($message_text);
            $historial = cargarHistorialUsuario($phone_number);
            $sucursal_nombre = $historial['sucursal_nombre'] ?? null;
            $area = $historial['area'] ?? null;
            
            // Verificar que la vacante existe
            $stmt = $pdo->prepare("SELECT nombre FROM vacantes WHERE id = ? AND status = 'activo' AND sucursal = ? AND area = ?");
            $stmt->execute([$vacante_id, $sucursal_nombre, $area]);
            $vacante = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($vacante) {
                // Actualizar historial
                $historial['estado'] = 'registro_datos';
                $historial['vacante_id'] = $vacante_id;
                $historial['vacante_nombre'] = $vacante['nombre'];
                guardarHistorialUsuario($phone_number, $historial);
                
                // Enviar mensaje para continuar con registro
                $mensaje = "✅ *Has seleccionado la vacante:* {$vacante['nombre']}\n\n";
                $mensaje .= "Para completar tu registro, por favor envía tus datos con el siguiente formato:\n\n";
                $mensaje .= "*Nombre:* Tu Nombre Completo\n";
                $mensaje .= "*Edad:* Tu Edad\n";
                $mensaje .= "*Experiencia:* Tu Experiencia Relevante\n";
                
                enviarMensajeTexto($phone_number, $mensaje);
            } else {
                enviarMensajeTexto($phone_number, "⚠️ La vacante seleccionada no existe o no está disponible. Por favor, selecciona un ID válido.");
            }
        } else {
            enviarMensajeTexto($phone_number, "⚠️ Por favor, responde únicamente con el número ID de la vacante que te interesa.");
        }
    }
}

// **4️⃣ Función para enviar respuestas interactivas a WhatsApp**
function enviarMensajeInteractivo($telefono, $mensaje, $secciones = []) {
    global $API_URL, $ACCESS_TOKEN;

    // Asegurar formato correcto de teléfono
    $telefono = corregirFormatoTelefono($telefono);

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
                "sections" => $secciones
            ]
        ]
    ];

    // Enviar a la API
    $context = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Authorization: Bearer $ACCESS_TOKEN\r\nContent-Type: application/json",
            "content" => json_encode($payload)
        ]
    ]);

    $response = @file_get_contents($API_URL, false, $context);
    
    if ($response === false) {
        $error = error_get_last();
        file_put_contents("whatsapp_log.txt", "❌ Error al enviar mensaje interactivo: " . $error['message'] . "\n", FILE_APPEND);
    } else {
        // Guardar log
        file_put_contents("whatsapp_log.txt", "🟡 Envío de lista interactiva a $telefono\nPayload:\n" . json_encode($payload, JSON_PRETTY_PRINT) . "\n\nRespuesta:\n$response\n\n", FILE_APPEND);
        
        // Guardar mensaje del bot
        $estado = cargarHistorialUsuario($telefono)['estado'] ?? null;
        guardarMensajeChat($telefono, null, 'respuesta', $mensaje, $estado);
    }
}

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

    // Guardar en log
    file_put_contents("whatsapp_log.txt", "🟢 Enviando mensaje a $telefono: $mensaje\n", FILE_APPEND);

    $context = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Authorization: Bearer $ACCESS_TOKEN\r\nContent-Type: application/json",
            "content" => json_encode($payload)
        ]
    ]);

    $response = @file_get_contents($API_URL, false, $context);
    
    if ($response === false) {
        $error = error_get_last();
        file_put_contents("whatsapp_log.txt", "❌ Error al enviar mensaje de texto: " . $error['message'] . "\n", FILE_APPEND);
    } else {
        file_put_contents("whatsapp_log.txt", "🔁 Respuesta de WhatsApp: " . $response . "\n", FILE_APPEND);

        // Guardar mensaje del bot
        $estado = cargarHistorialUsuario($telefono)['estado'] ?? null;
        guardarMensajeChat($telefono, null, 'respuesta', $mensaje, $estado);
    }
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

    $response = @file_get_contents($API_URL, false, $context);
    
    if ($response === false) {
        $error = error_get_last();
        file_put_contents("whatsapp_log.txt", "❌ Error al enviar mensaje a la API: " . $error['message'] . "\n", FILE_APPEND);
        return false;
    } else {
        file_put_contents("whatsapp_log.txt", "Respuesta de WhatsApp: " . $response . "\n", FILE_APPEND);
        return $response;
    }
}


function corregirFormatoTelefono($telefono) {
    // Aplicar formato consistente para el teléfono
    if (preg_match('/^521(\d{10})$/', $telefono, $matches)) {
        return "52" . $matches[1]; // Elimina el "1"
    }
    return $telefono;
}

function guardarHistorialUsuario($telefono, $datos) {
    global $pdo;
    
    // Asegurar formato correcto del teléfono
    $telefono = corregirFormatoTelefono($telefono);

    try {
        $stmt = $pdo->prepare("SELECT id FROM usuarios_historial WHERE telefono = ?");
        $stmt->execute([$telefono]);
        $existe = $stmt->fetch();

        if ($existe) {
            $sql = "UPDATE usuarios_historial SET 
                        estado = :estado, 
                        sucursal = :sucursal, 
                        sucursal_nombre = :sucursal_nombre, 
                        area = :area,
                        vacante_id = :vacante_id,
                        vacante_nombre = :vacante_nombre,
                        updated_at = NOW()
                    WHERE telefono = :telefono";
        } else {
            $sql = "INSERT INTO usuarios_historial (telefono, estado, sucursal, sucursal_nombre, area, vacante_id, vacante_nombre)
                    VALUES (:telefono, :estado, :sucursal, :sucursal_nombre, :area, :vacante_id, :vacante_nombre)";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ":telefono" => $telefono,
            ":estado" => $datos['estado'] ?? null,
            ":sucursal" => $datos['sucursal'] ?? null,
            ":sucursal_nombre" => $datos['sucursal_nombre'] ?? null,
            ":area" => $datos['area'] ?? null,
            ":vacante_id" => $datos['vacante_id'] ?? null,
            ":vacante_nombre" => $datos['vacante_nombre'] ?? null
        ]);

        file_put_contents("whatsapp_log.txt", "✅ Historial guardado en BD para $telefono: " . json_encode($datos) . "\n", FILE_APPEND);
        return true;
    } catch (PDOException $e) {
        file_put_contents("error_log_sql.txt", date('Y-m-d H:i:s') . " | Error en guardarHistorialUsuario: " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}

function cargarHistorialUsuario($telefono) {
    global $pdo;

    // Asegurar formato correcto del teléfono
    $telefono = corregirFormatoTelefono($telefono);

    try {
        $stmt = $pdo->prepare("SELECT * FROM usuarios_historial WHERE telefono = ?");
        $stmt->execute([$telefono]);
        $historial = $stmt->fetch(PDO::FETCH_ASSOC);

        return $historial ?: [];
    } catch (PDOException $e) {
        file_put_contents("error_log_sql.txt", date('Y-m-d H:i:s') . " | Error en cargarHistorialUsuario: " . $e->getMessage() . "\n", FILE_APPEND);
        return [];
    }
}

function guardarMensajeChat($telefono, $mensaje, $tipo = 'texto', $respuesta_bot = null, $estado = null, $nombre_usuario = null) {
    global $pdo;

    // Asegurar formato correcto del teléfono
    $telefono = corregirFormatoTelefono($telefono);

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
        return true;
    } catch (PDOException $e) {
        file_put_contents("error_log_sql.txt", date('Y-m-d H:i:s') . " | Error en guardarMensajeChat: " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}

function obtenerListaSucursales() {
    global $pdo;

    try {
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
                "id" => "sucursal_" . $sucursal['clave'], // este ID se usará para el flujo
                "title" => $sucursal['nombre']
            ];
        }

        // WhatsApp permite máx 10 por sección. Aquí asumimos <10.
        return [[
            "title" => "Sucursales disponibles",
            "rows" => $rows
        ]];

    } catch (PDOException $e) {
        file_put_contents("error_log_sql.txt", date('Y-m-d H:i:s') . " | Error al obtener sucursales: " . $e->getMessage() . "\n", FILE_APPEND);
        return [];
    }
}

function enviarMensajeConBotones($telefono, $mensaje, $botones) {
    global $API_URL, $ACCESS_TOKEN;

    $payload = [
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" => $telefono,
        "type" => "interactive",
        "interactive" => [
            "type" => "button",
            "body" => ["text" => $mensaje],
            "action" => [
                "buttons" => array_map(function ($btn) {
                    return [
                        "type" => "reply",
                        "reply" => [
                            "id" => $btn["id"],
                            "title" => $btn["title"]
                        ]
                    ];
                }, $botones)
            ]
        ]
    ];

    enviarAPI($payload);
}

?>