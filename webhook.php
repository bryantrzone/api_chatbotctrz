<?php

require 'config.php'; 

// CONFIGURACIÓN DEL WEBHOOK
$PHONE_NUMBERID=498027520054701;
$VERIFY_TOKEN = "falco_verificacion";
$ACCESS_TOKEN = "EAASBWzT6HkkBO2EEKsCULIAGCavkPRR8ueVH7GaDnuXZBRdxYl9pyuW92EFDhIqmXLRHpNejdOZBe7CKpLAdZCD4xx5cx9oA7oZCmNgLa9q1gtKPMbYALZAKON7K35ehC5V70OjPwR3ryYmCW7KouPPz5DYiER2DAicEvUfhZCHxpavDY6PqKsOEYrBMZCG9tZBkbZAAy6c8OxPNOhrHrG94sC7SxBPKcaJlnPZCC2vLZB5VTjBK4hrS9cZD";
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

    // **5️⃣ Si el usuario selecciona un área laboral, ahora pedir la ciudad o estado**
    elseif (in_array($message_text, ["ventas", "almacen", "contabilidad", "reparto"])) {
        file_put_contents("whatsapp_log.txt", "Área laboral seleccionada: $message_text por $phone_number\n", FILE_APPEND);

        // Guardamos el área en el historial del usuario
        guardarHistorialUsuario($phone_number, ["estado" => "seleccion_ciudad", "area" => $message_text]);

        // Preguntar la ciudad en lugar de mostrar la lista de sucursales
        enviarMensajeTexto($phone_number, "📍 *¿En qué ciudad o estado te encuentras?*\n\nEscríbelo en un mensaje (Ejemplo: *Puebla*, *CDMX*, *Monterrey*...)");
    }

    // **6️⃣ Si el usuario responde con una ciudad, buscar la sucursal más cercana**
    elseif ($estado_anterior === "seleccion_ciudad") {
        $ciudad = ucfirst(trim($message_text));

        // Buscar sucursal por coincidencia parcial
        $stmt = $pdo->prepare("SELECT clave, nombre FROM sucursales WHERE nombre LIKE ? AND status = 1 LIMIT 1");
        $stmt->execute(["%" . $ciudad . "%"]);
        $sucursal = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($sucursal) {
            // Guardar en historial
            $historial = cargarHistorialUsuario($phone_number);
            $historial['sucursal'] = $sucursal['clave'];
            $historial['sucursal_nombre'] = $sucursal['nombre'];
            $historial['estado'] = 'solicitar_nombre';
            guardarHistorialUsuario($phone_number, $historial);

            // Pedir el nombre completo del usuario
            enviarMensajeTexto($phone_number, "✍️ *Por favor, escribe tu nombre completo para continuar con el registro:*");
        } else {
            enviarMensajeTexto($phone_number, "⚠️ No encontré ninguna sucursal con ese nombre.\n\nPor favor, intenta escribir otra ciudad o estado:");
        }
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

function enviarMensajeTexto($telefono, $mensaje) {
    global $API_URL, $ACCESS_TOKEN;

    // $telefono = corregirFormatoTelefono($telefono);

    file_put_contents("whatsapp_log.txt", "Enviando mensaje a $telefono: $mensaje\n", FILE_APPEND);

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

function guardarHistorialUsuario($telefono, $datos) {
    file_put_contents("usuarios/$telefono.json", json_encode($datos));
    file_put_contents("whatsapp_log.txt", "Guardando historial para $telefono: " . json_encode($datos) . "\n", FILE_APPEND);
}

function obtenerListaSucursales() {
    global $pdo;

    $stmt = $pdo->prepare("SELECT clave, nombre FROM sucursales WHERE status = 1 ORDER BY nombre ASC");
    $stmt->execute();
    $sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $opciones = [];
    foreach ($sucursales as $sucursal) {
        $opciones[] = [
            "id" => "sucursal_" . $sucursal['clave'],
            "title" => $sucursal['nombre']
        ];
    }

    // WhatsApp permite máximo 10 filas por sección
    $chunks = array_chunk($opciones, 10);

    $secciones = [];
    foreach ($chunks as $index => $chunk) {
        $secciones[] = [
            "title" => "Sucursales disponibles " . ($index + 1),
            "rows" => $chunk
        ];
    }

    return $secciones;
}




?>
