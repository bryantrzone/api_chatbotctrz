<?php

require 'config.php'; 

// Asignación dinámica
$PHONE_NUMBERID = $config['PHONE_NUMBERID'];
$VERIFY_TOKEN   = $config['VERIFY_TOKEN'];
$ACCESS_TOKEN   = $config['ACCESS_TOKEN'];
$API_URL        = "https://graph.facebook.com/v22.0/$PHONE_NUMBERID/messages";

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

// **Verificar que el mensaje es válido** Primer bloque
// if (isset($input['entry'][0]['changes'][0]['value']['messages'][0])) {
//     $message_data = $input['entry'][0]['changes'][0]['value']['messages'][0];
//     $phone_number = corregirFormatoTelefono($message_data['from']); // Número del usuario

//    // Variables iniciales
//     $message_text = "";
//     $mensaje_original = "";
//     $tipo_mensaje = "texto";

//     // Mensaje de texto normal
//     if (isset($message_data['text'])) {
//         $mensaje_original = trim($message_data['text']['body']);
//         $message_text = strtolower($mensaje_original);
//         $tipo_mensaje = 'texto';
//     } elseif (isset($message_data['interactive']['type']) && $message_data['interactive']['type'] === "list_reply") {
//         $message_text = strtolower(trim($message_data['interactive']['list_reply']['id']));
//         $mensaje_original = $message_data['interactive']['list_reply']['title'] ?? '';
//         $tipo_mensaje = 'lista';
//     }

//     // Cargar estado actual del usuario
//     $historial_usuario = cargarHistorialUsuario($phone_number);
//     $estado = $historial_usuario['estado'] ?? null;

//     // Guardar en la base de datos si tenemos algo
//     if (!empty($message_text)) {
//         $nombre_usuario = $input['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? null;

//         guardarMensajeChat(
//             $phone_number,
//             $mensaje_original,
//             $tipo_mensaje,
//             null,               // Se llenará la respuesta del bot cuando respondas
//             $estado,
//             $nombre_usuario
//         );
//     }

//     // **Guardar logs del mensaje recibido**
//     file_put_contents("whatsapp_log.txt", "Número: $phone_number, Mensaje: $message_text, Estado actual: $estado\n", FILE_APPEND);


//     // **3️⃣ Si el usuario envía "Hola", responde con el menú interactivo**
//     if ($message_text === "hola") {
//         enviarMensajeInteractivo($phone_number, 
//             "😊 *¡Bienvenido! Soy Falco, tu asistente virtual 🤖.*\n\nEstoy aquí para resolver tus dudas y guiarte en lo que necesites. \n\n*¿Cómo puedo ayudarte hoy?*",
//             [
//                 [
//                     "title" => "Opciones de servicio",
//                     "rows" => [
//                         ["id" => "bolsa_trabajo", "title" => "Lista de vacantes"],
//                         ["id" => "atencion_clientes", "title" => "Postularse a una vacante"],
//                         ["id" => "cotizacion", "title" => "Quejas Grupo Tractozone"]
//                     ]
//                 ]
//             ]
//         );

//     }

//     // **4️⃣ Si el usuario selecciona "Bolsa de Trabajo", responde con áreas laborales**
//     elseif ($message_text === "bolsa_trabajo") {
//         // Guardamos el nuevo estado
//         guardarHistorialUsuario($phone_number, ["estado" => "seleccion_sucursal"]);
    
//         $secciones = obtenerListaSucursales();

//         enviarMensajeInteractivo(
//             $phone_number,
//             "🏢 *Estas son las sucursales con vacantes activas.*\n\nPor favor, selecciona la sucursal en la que te gustaría trabajar:",
//             $secciones
//         );
//     }
    
//     elseif (strpos($message_text, "sucursal_") === 0) {
//         file_put_contents("whatsapp_log.txt", "✅ Entró al bloque de sucursal. mensaje_text: $message_text\n", FILE_APPEND);
    
//         $clave = str_replace("sucursal_", "", strtolower(trim($message_text)));
    
//         file_put_contents("whatsapp_log.txt", "➡️ Clave extraída: $clave\n", FILE_APPEND);
    
//         // Buscar el nombre de la sucursal
//         $stmt = $pdo->prepare("SELECT nombre FROM sucursales WHERE clave = ? AND status = 1");
//         $stmt->execute([$clave]);
//         $sucursal = $stmt->fetch(PDO::FETCH_ASSOC);
    
//         file_put_contents("whatsapp_log.txt", "🔎 Resultado de la sucursal: " . json_encode($sucursal) . "\n", FILE_APPEND);
    
//         if ($sucursal) {
//             $sucursal_nombre = $sucursal['nombre'];
    
//             // Guardar historial en MySQL
//             $historial = cargarHistorialUsuario($phone_number);
//             $historial['estado'] = 'seleccion_area';
//             $historial['sucursal'] = $clave;
//             $historial['sucursal_nombre'] = $sucursal_nombre;
//             guardarHistorialUsuario($phone_number, $historial);
    
//             // Consultar áreas disponibles en esa sucursal
//             $stmt = $pdo->prepare("SELECT DISTINCT area FROM vacantes WHERE sucursal = ? AND status = 'activo'");
//             $stmt->execute([$sucursal_nombre]);
//             $areas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
//             file_put_contents("whatsapp_log.txt", "🗂️ Áreas encontradas: " . json_encode($areas) . "\n", FILE_APPEND);
    
//             if (count($areas) > 0) {
//                 $area_rows = [];
//                 foreach ($areas as $area) {
//                     $id = strtolower(preg_replace('/\s+/', '_', $area));
//                     $area_rows[] = ["id" => $id, "title" => $area];
//                 }
    
//                 enviarMensajeInteractivo(
//                     $phone_number,
//                     "📌 *Sucursal seleccionada:* $sucursal_nombre\n\n¿En qué área te gustaría trabajar?",
//                     [[
//                         "title" => "Áreas disponibles",
//                         "rows" => $area_rows
//                     ]]
//                 );
//             } else {
//                 enviarMensajeTexto($phone_number, "⚠️ No hay vacantes activas en esta sucursal.");
//             }
//         } else {
//             enviarMensajeTexto($phone_number, "⚠️ La sucursal seleccionada no es válida.");
//         }
//     }
    
//     elseif ($estado === "seleccion_area" || $estado === "mostrar_vacantes") {
//         $area = ucwords(str_replace('_', ' ', strtolower($message_text))); // Ejemplo: ventas → Ventas
//         $historial = cargarHistorialUsuario($phone_number);
//         $sucursal_nombre = $historial['sucursal_nombre'] ?? null;
    
//         if (!$sucursal_nombre) {
//             enviarMensajeTexto($phone_number, "⚠️ Hubo un error al recuperar tu sucursal. Por favor, si quieres comenzar de nuevo, escribe 'Menú principal'.");
//             return;
//         }
    
//         // Guardar el área seleccionada en el historial
//         $historial['estado'] = 'mostrar_vacantes';
//         $historial['area'] = $area;
//         guardarHistorialUsuario($phone_number, $historial);
    
//         // Consultar vacantes activas en la sucursal y área seleccionada
//         $stmt = $pdo->prepare("SELECT id, nombre, descripcion, horario FROM vacantes WHERE status = 'activo' AND sucursal = ? AND area = ?");
//         $stmt->execute([$sucursal_nombre, $area]);
//         $vacantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
//         if (count($vacantes) === 0) {
//             enviarMensajeTexto($phone_number, "😕 No hay vacantes activas en *$area* en *$sucursal_nombre*.");
//             return;
//         }
    
//         // Enviar cada vacante en un mensaje separado con botones
//         foreach ($vacantes as $v) {
//             $mensaje = "📌 *Vacante Disponible:*\n\n"
//                      . "📢 *{$v['nombre']}*\n"
//                      . "📍 *Sucursal:* $sucursal_nombre\n"
//                      . "📝 *Descripción:* {$v['descripcion']}\n"
//                      . "⏰ *Horario:* {$v['horario']}\n";
    
//             enviarMensajeConBotones($phone_number, $mensaje, [
//                 ["id" => "seleccionar_{$v['id']}", "title" => "Seleccionar"],
//                 ["id" => "ver_detalles_{$v['id']}", "title" => "Ver más detalles"]
//             ]);
//         }
//     }

//     // Manejador para el botón "Ver más detalles"   
//     elseif (strpos($message_text, "ver_detalles_") === 0) {
//         // Extraer el ID de la vacante del mensaje
//         $vacante_id = intval(str_replace("ver_detalles_", "", $message_text));
//         file_put_contents("whatsapp_log.txt", "🔍 Mostrando detalles de la vacante ID: $vacante_id\n", FILE_APPEND);
        
//         // Consultar los detalles completos de la vacante en la base de datos
//         $stmt = $pdo->prepare("SELECT * FROM vacantes WHERE id = ? AND status = 'activo'");
//         $stmt->execute([$vacante_id]);
//         $vacante = $stmt->fetch(PDO::FETCH_ASSOC);
        
//         if ($vacante) {
//             // Guardar el estado actual para seguimiento
//             $historial = cargarHistorialUsuario($phone_number);
//             $historial['estado'] = 'ver_detalles_vacante';
//             $historial['vacante_id'] = $vacante_id;
//             guardarHistorialUsuario($phone_number, $historial);
            
//             // Construir mensaje detallado con toda la información de la vacante
//             $mensaje = "📋 *DETALLES DE LA VACANTE*\n\n";
//             $mensaje .= "📢 *{$vacante['nombre']}*\n";
//             $mensaje .= "📍 *Sucursal:* {$vacante['sucursal']}\n";
//             $mensaje .= "🏢 *Área:* {$vacante['area']}\n";
//             $mensaje .= "⏰ *Horario:* {$vacante['horario']}\n";
            
//             // Agregar salario si está disponible
//             if (!empty($vacante['salario'])) {
//                 $mensaje .= "💰 *Salario:* {$vacante['salario']}\n";
//             } else {
//                 $mensaje .= "💰 *Salario:* A tratar en entrevista\n";
//             }
            
//             // Agregar descripción completa
//             $mensaje .= "\n📝 *Descripción del puesto:*\n{$vacante['descripcion']}\n";
            
//             // Agregar requisitos si existen
//             if (!empty($vacante['requisitos'])) {
//                 $mensaje .= "\n✅ *Requisitos:*\n{$vacante['requisitos']}\n";
//             }
            
//             // Agregar beneficios si existen
//             if (!empty($vacante['beneficios'])) {
//                 $mensaje .= "\n🎁 *Beneficios:*\n{$vacante['beneficios']}\n";
//             }
            
//             // Agregar información adicional si existe
//             if (!empty($vacante['info_adicional'])) {
//                 $mensaje .= "\n📌 *Información adicional:*\n{$vacante['info_adicional']}\n";
//             }
            
//             // Mensaje de cierre
//             $mensaje .= "\n¿Te interesa postularte para esta vacante?";
            
//             // Enviar mensaje con botones para postularse o ver otras vacantes
//             enviarMensajeConBotones($phone_number, $mensaje, [
//                 ["id" => "postularme_{$vacante_id}", "title" => "Postularme"],
//                 ["id" => "ver_otra", "title" => "Ver otras vacantes"]
//             ]);
            
//             // Guardar el mensaje en el historial de chat
//             guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
//         } else {
//             // Si no se encuentra la vacante
//             enviarMensajeTexto($phone_number, "⚠️ Lo siento, no pudimos encontrar la información de esta vacante. Puede que ya no esté disponible.");
//         }
//     }

//     // // Manejador para el botón "Postularme"
//     // elseif (strpos($message_text, "postularme_") === 0) {
//     //     // Extraer el ID de la vacante
//     //     $vacante_id = intval(str_replace("postularme_", "", $message_text));
//     //     file_put_contents("whatsapp_log.txt", "✅ Usuario quiere postularse a la vacante ID: $vacante_id\n", FILE_APPEND);
        
//     //     // Verificar que la vacante sigue existiendo y activa
//     //     $stmt = $pdo->prepare("SELECT nombre, sucursal, area FROM vacantes WHERE id = ? AND status = 'activo'");
//     //     $stmt->execute([$vacante_id]);
//     //     $vacante = $stmt->fetch(PDO::FETCH_ASSOC);
        
//     //     if ($vacante) {
//     //         // Actualizar el estado del usuario
//     //         $historial = cargarHistorialUsuario($phone_number);
//     //         $historial['estado'] = 'registro_datos';
//     //         $historial['registro_paso'] = 'inicio';
//     //         $historial['vacante_id'] = $vacante_id;
//     //         $historial['vacante_nombre'] = $vacante['nombre'];
//     //         $historial['sucursal_nombre'] = $vacante['sucursal'];
//     //         $historial['area'] = $vacante['area'];
//     //         guardarHistorialUsuario($phone_number, $historial);
            
//     //         // Mensaje para iniciar el proceso de postulación
//     //         $mensaje = "🎯 *¡Excelente elección!*\n\n";
//     //         $mensaje .= "Estás a punto de postularte para: *{$vacante['nombre']}*\n";
//     //         $mensaje .= "En la sucursal: *{$vacante['sucursal']}*\n\n";
//     //         $mensaje .= "Para continuar con tu postulación, necesito algunos datos básicos.\n\n";
//     //         $mensaje .= "📝 Por favor, envíame tu *nombre completo*:";
            
//     //         enviarMensajeTexto($phone_number, $mensaje);
//     //     } else {
//     //         // Si la vacante ya no está disponible
//     //         enviarMensajeTexto($phone_number, "⚠️ Lo siento, esta vacante ya no está disponible. ¿Te gustaría ver otras opciones?");
            
//     //         // Ofrecer volver a ver vacantes
//     //         enviarMensajeConBotones($phone_number, "Puedo mostrarte otras vacantes disponibles:", [
//     //             ["id" => "ver_otra", "title" => "Ver otras vacantes"],
//     //             ["id" => "menu_principal", "title" => "Menú principal"]
//     //         ]);
//     //     }
//     // }

//     // // Manejo del flujo de registro de datos del candidato
//     // elseif ($estado === "registro_datos") {
//     //     // Verificar en qué paso del registro estamos
//     //     $historial = cargarHistorialUsuario($phone_number);
//     //     $paso = $historial['registro_paso'] ?? 'inicio';
        
//     //     file_put_contents("whatsapp_log.txt", "👤 Procesando registro en paso: $paso - Mensaje: $message_text\n", FILE_APPEND);
        
//     //     switch ($paso) {
//     //         case 'inicio':
//     //             // Ya solicitamos el nombre, procesamos la respuesta
//     //             $historial['registro_paso'] = 'nombre';
//     //             $historial['nombre'] = $mensaje_original;
//     //             guardarHistorialUsuario($phone_number, $historial);
                
//     //             // Solicitar la edad
//     //             enviarMensajeTexto($phone_number, "Gracias *{$mensaje_original}*.\n\n¿Cuál es tu edad?");
//     //             break;
                
//     //         case 'nombre':
//     //             // Procesamos la edad
//     //             if (is_numeric($message_text) && intval($message_text) >= 18 && intval($message_text) <= 70) {
//     //                 $historial['registro_paso'] = 'edad';
//     //                 $historial['edad'] = intval($message_text);
//     //                 guardarHistorialUsuario($phone_number, $historial);
                    
//     //                 // Solicitar experiencia
//     //                 enviarMensajeTexto($phone_number, "Perfecto.\n\n¿Cuál es tu experiencia relacionada con el puesto? Si no tienes experiencia previa, puedes escribir 'Sin experiencia'.");
//     //             } else {
//     //                 enviarMensajeTexto($phone_number, "⚠️ Por favor, ingresa una edad válida entre 18 y 70 años.");
//     //             }
//     //             break;
                
//     //         case 'edad':
//     //             // Procesamos la experiencia
//     //             $historial['registro_paso'] = 'experiencia';
//     //             $historial['experiencia'] = $mensaje_original;
//     //             guardarHistorialUsuario($phone_number, $historial);
                
//     //             // Solicitar email
//     //             enviarMensajeTexto($phone_number, "Excelente. Por último, necesito tu correo electrónico para que nuestro equipo de reclutamiento pueda contactarte:");
//     //             break;
                
//     //         case 'experiencia':
//     //             // Procesamos el email
//     //             if (filter_var($message_text, FILTER_VALIDATE_EMAIL)) {
//     //                 $historial['registro_paso'] = 'completo';
//     //                 $historial['email'] = $message_text;
//     //                 guardarHistorialUsuario($phone_number, $historial);
                    
//     //                 // Guardar la postulación en la base de datos
//     //                 try {
//     //                     $stmt = $pdo->prepare("INSERT INTO postulaciones 
//     //                         (telefono, nombre, edad, experiencia, email, vacante_id, fecha_postulacion, status) 
//     //                         VALUES (?, ?, ?, ?, ?, ?, NOW(), 'pendiente')");
                        
//     //                     $stmt->execute([
//     //                         $phone_number,
//     //                         $historial['nombre'],
//     //                         $historial['edad'],
//     //                         $historial['experiencia'],
//     //                         $historial['email'],
//     //                         $historial['vacante_id']
//     //                     ]);
                        
//     //                     // Mensaje de confirmación con datos del candidato
//     //                     $mensaje = "🎉 *¡Felicidades! Tu postulación ha sido registrada exitosamente*\n\n";
//     //                     $mensaje .= "📝 *Resumen de tu postulación:*\n";
//     //                     $mensaje .= "👤 *Nombre:* {$historial['nombre']}\n";
//     //                     $mensaje .= "📧 *Email:* {$historial['email']}\n";
//     //                     $mensaje .= "📢 *Vacante:* {$historial['vacante_nombre']}\n";
//     //                     $mensaje .= "📍 *Sucursal:* {$historial['sucursal_nombre']}\n\n";
//     //                     $mensaje .= "Nuestro equipo de recursos humanos revisará tu información y se pondrá en contacto contigo en un máximo de 3 días hábiles a través del correo proporcionado.\n\n";
//     //                     $mensaje .= "Si tienes alguna duda adicional, no dudes en escribirnos.";
                        
//     //                     // Enviar confirmación y opciones para continuar
//     //                     enviarMensajeTexto($phone_number, $mensaje);
                        
//     //                     // Pequeña pausa para no saturar de mensajes
//     //                     sleep(1);
                        
//     //                     // Ofrecer opciones para continuar
//     //                     enviarMensajeConBotones($phone_number, "¿Qué te gustaría hacer ahora?", [
//     //                         ["id" => "ver_otra", "title" => "Ver otras vacantes"],
//     //                         ["id" => "menu_principal", "title" => "Volver al menú"]
//     //                     ]);
                        
//     //                     // Resetear el estado para permitir otras operaciones
//     //                     $historial['estado'] = 'postulacion_completada';
//     //                     guardarHistorialUsuario($phone_number, $historial);
                        
//     //                 } catch (PDOException $e) {
//     //                     file_put_contents("error_log_sql.txt", date('Y-m-d H:i:s') . " | Error al guardar postulación: " . $e->getMessage() . "\n", FILE_APPEND);
//     //                     enviarMensajeTexto($phone_number, "❌ Lo sentimos, hubo un error al procesar tu postulación. Por favor, intenta nuevamente más tarde o comunícate directamente con nuestra área de recursos humanos.");
//     //                 }
//     //             } else {
//     //                 enviarMensajeTexto($phone_number, "⚠️ El correo electrónico ingresado no es válido. Por favor, ingresa un correo electrónico correcto.");
//     //             }
//     //             break;
                
//     //         case 'completo':
//     //             // Si el usuario escribe algo después de completar el registro
//     //             enviarMensajeConBotones($phone_number, "Ya has completado tu postulación. ¿Qué te gustaría hacer ahora?", [
//     //                 ["id" => "ver_otra", "title" => "Ver otras vacantes"],
//     //                 ["id" => "menu_principal", "title" => "Volver al menú"]
//     //             ]);
//     //             break;
                
//     //         default:
//     //             // Si hay algún problema con el estado
//     //             enviarMensajeTexto($phone_number, "Parece que hubo un problema con tu registro. Por favor, intenta nuevamente desde el principio escribiendo 'Hola'.");
//     //             break;
//     //     }
//     // }
// }

// Segundo bloque

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
    } elseif (isset($message_data['interactive']['type']) && $message_data['interactive']['type'] === "button_reply") {
        // Capturar respuesta de botones
        $message_text = strtolower(trim($message_data['interactive']['button_reply']['id']));
        $mensaje_original = $message_data['interactive']['button_reply']['title'] ?? '';
        $tipo_mensaje = 'boton';
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


    if ($estado === "registro_datos") {
        // Verificar en qué paso del registro estamos
        $historial = cargarHistorialUsuario($phone_number);
        $paso = $historial['registro_paso'] ?? 'inicio';
        
        file_put_contents("whatsapp_log.txt", "👤 Procesando registro en paso: $paso - Mensaje: $message_text\n", FILE_APPEND);
        
        switch ($paso) {
            case 'inicio':
                // Ya solicitamos el nombre, procesamos la respuesta
                $historial['registro_paso'] = 'nombre';
                $historial['nombre'] = $mensaje_original;
                guardarHistorialUsuario($phone_number, $historial);
                
                // Solicitar la edad
                $mensaje = "Gracias *{$mensaje_original}*.\n\n¿Cuál es tu edad?";
                enviarMensajeTexto($phone_number, $mensaje);
                guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
                break;
                
            case 'nombre':
                // Procesamos la edad
                if (is_numeric($message_text) && intval($message_text) >= 18 && intval($message_text) <= 70) {
                    $historial['registro_paso'] = 'edad';
                    $historial['edad'] = intval($message_text);
                    guardarHistorialUsuario($phone_number, $historial);
                    
                    // Solicitar experiencia
                    $mensaje = "Perfecto.\n\n¿Cuál es tu experiencia relacionada con el puesto? Si no tienes experiencia previa, puedes escribir 'Sin experiencia'.";
                    enviarMensajeTexto($phone_number, $mensaje);
                    guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
                } else {
                    $mensaje = "⚠️ Por favor, ingresa una edad válida entre 18 y 70 años.";
                    enviarMensajeTexto($phone_number, $mensaje);
                    guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
                }
                break;
                
            case 'edad':
                // Procesamos la experiencia
                $historial['registro_paso'] = 'experiencia';
                $historial['experiencia'] = $mensaje_original;
                guardarHistorialUsuario($phone_number, $historial);
                
                // Solicitar email
                $mensaje = "Excelente. Por último, necesito tu correo electrónico para que nuestro equipo de reclutamiento pueda contactarte:";
                enviarMensajeTexto($phone_number, $mensaje);
                guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
                break;
                
            case 'experiencia':
                // Procesamos el email
                if (filter_var($message_text, FILTER_VALIDATE_EMAIL)) {
                    $historial['registro_paso'] = 'completo';
                    $historial['email'] = $message_text;
                    guardarHistorialUsuario($phone_number, $historial);
                    
                    // Guardar la postulación en la base de datos
                    try {
                        $stmt = $pdo->prepare("INSERT INTO postulaciones 
                            (telefono, nombre, edad, experiencia, email, vacante_id, fecha_postulacion, status) 
                            VALUES (?, ?, ?, ?, ?, ?, NOW(), 'pendiente')");
                        
                        $stmt->execute([
                            $phone_number,
                            $historial['nombre'],
                            $historial['edad'],
                            $historial['experiencia'],
                            $historial['email'],
                            $historial['vacante_id']
                        ]);
                        
                        // Mensaje de confirmación con datos del candidato
                        $mensaje = "🎉 *¡Felicidades! Tu postulación ha sido registrada exitosamente*\n\n";
                        $mensaje .= "📝 *Resumen de tu postulación:*\n";
                        $mensaje .= "👤 *Nombre:* {$historial['nombre']}\n";
                        $mensaje .= "📧 *Email:* {$historial['email']}\n";
                        $mensaje .= "📢 *Vacante:* {$historial['vacante_nombre']}\n";
                        $mensaje .= "📍 *Sucursal:* {$historial['sucursal_nombre']}\n\n";
                        $mensaje .= "Nuestro equipo de recursos humanos revisará tu información y se pondrá en contacto contigo en un máximo de 3 días hábiles a través del correo proporcionado.\n\n";
                        $mensaje .= "Si tienes alguna duda adicional, no dudes en escribirnos.";
                        
                        // Enviar confirmación y opciones para continuar
                        enviarMensajeTexto($phone_number, $mensaje);
                        guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
                        
                        // Pequeña pausa para no saturar de mensajes
                        sleep(1);
                        
                        // Ofrecer opciones para continuar
                        enviarMensajeConBotones($phone_number, "¿Qué te gustaría hacer ahora?", [
                            ["id" => "ver_otra", "title" => "Ver otras vacantes"],
                            ["id" => "menu_principal", "title" => "Volver al menú"]
                        ]);
                        
                        // Resetear el estado para permitir otras operaciones
                        $historial['estado'] = 'postulacion_completada';
                        guardarHistorialUsuario($phone_number, $historial);
                        
                    } catch (PDOException $e) {
                        file_put_contents("error_log_sql.txt", date('Y-m-d H:i:s') . " | Error al guardar postulación: " . $e->getMessage() . "\n", FILE_APPEND);
                        $mensaje = "❌ Lo sentimos, hubo un error al procesar tu postulación. Por favor, intenta nuevamente más tarde o comunícate directamente con nuestra área de recursos humanos.";
                        enviarMensajeTexto($phone_number, $mensaje);
                        guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
                    }
                } else {
                    $mensaje = "⚠️ El correo electrónico ingresado no es válido. Por favor, ingresa un correo electrónico correcto.";
                    enviarMensajeTexto($phone_number, $mensaje);
                    guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
                }
                break;
                
            case 'completo':
                // Si el usuario escribe algo después de completar el registro
                $mensaje = "Ya has completado tu postulación. ¿Qué te gustaría hacer ahora?";
                enviarMensajeConBotones($phone_number, $mensaje, [
                    ["id" => "ver_otra", "title" => "Ver otras vacantes"],
                    ["id" => "menu_principal", "title" => "Volver al menú"]
                ]);
                guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
                break;
                
            default:
                // Si hay algún problema con el estado
                $mensaje = "Parece que hubo un problema con tu registro. Por favor, intenta nuevamente desde el principio escribiendo 'Hola'.";
                enviarMensajeTexto($phone_number, $mensaje);
                guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
                break;
        }
        
        // *** Importante: Retornar después de procesar el registro para evitar que se ejecuten los demás bloques ***
        return;
    }

    // **IMPORTANTE: Verificar primero si es una acción de botón para ver detalles o postularse**
    if (strpos($message_text, "ver_detalles_") === 0) {
        // Extraer el ID de la vacante del mensaje
        $vacante_id = intval(str_replace("ver_detalles_", "", $message_text));
        file_put_contents("whatsapp_log.txt", "🔍 Mostrando detalles de la vacante ID: $vacante_id\n", FILE_APPEND);
        
        // Consultar los detalles completos de la vacante en la base de datos
        $stmt = $pdo->prepare("SELECT * FROM vacantes WHERE id = ? AND status = 'activo'");
        $stmt->execute([$vacante_id]);
        $vacante = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($vacante) {
            // Guardar el estado actual para seguimiento
            $historial = cargarHistorialUsuario($phone_number);
            $historial['estado'] = 'ver_detalles_vacante';
            $historial['vacante_id'] = $vacante_id;
            guardarHistorialUsuario($phone_number, $historial);
            
            // Construir mensaje detallado con toda la información de la vacante
            $mensaje = "📋 *DETALLES DE LA VACANTE*\n\n";
            $mensaje .= "📢 *{$vacante['nombre']}*\n";
            $mensaje .= "📍 *Sucursal:* {$vacante['sucursal']}\n";
            $mensaje .= "🏢 *Área:* {$vacante['area']}\n";
            $mensaje .= "⏰ *Horario:* {$vacante['horario']}\n";
            
            // Agregar salario si está disponible
            if (!empty($vacante['salario'])) {
                $mensaje .= "💰 *Salario:* {$vacante['salario']}\n";
            } else {
                $mensaje .= "💰 *Salario:* A tratar en entrevista\n";
            }
            
            // Agregar descripción completa
            $mensaje .= "\n📝 *Descripción del puesto:*\n{$vacante['descripcion']}\n";
            
            // Agregar requisitos si existen
            if (!empty($vacante['requisitos'])) {
                $mensaje .= "\n✅ *Requisitos:*\n{$vacante['requisitos']}\n";
            }
            
            // Agregar beneficios si existen
            if (!empty($vacante['beneficios'])) {
                $mensaje .= "\n🎁 *Beneficios:*\n{$vacante['beneficios']}\n";
            }
            
            // Agregar información adicional si existe
            if (!empty($vacante['info_adicional'])) {
                $mensaje .= "\n📌 *Información adicional:*\n{$vacante['info_adicional']}\n";
            }
            
            // Mensaje de cierre
            $mensaje .= "\n¿Te interesa postularte para esta vacante?";
            
            // Enviar mensaje con botones para postularse o ver otras vacantes
            enviarMensajeConBotones($phone_number, $mensaje, [
                ["id" => "postularme_{$vacante_id}", "title" => "Postularme"],
                ["id" => "ver_otra", "title" => "Ver otras vacantes"]
            ]);
            
            // Guardar el mensaje en el historial de chat
            guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
        } else {
            // Si no se encuentra la vacante
            enviarMensajeTexto($phone_number, "⚠️ Lo siento, no pudimos encontrar la información de esta vacante. Puede que ya no esté disponible.");
        }
    }
    // Manejador para el botón "Postularme"
    elseif (strpos($message_text, "postularme_") === 0) {
        // Extraer el ID de la vacante
        $vacante_id = intval(str_replace("postularme_", "", $message_text));
        file_put_contents("whatsapp_log.txt", "✅ Usuario quiere postularse a la vacante ID: $vacante_id\n", FILE_APPEND);
        
        // Verificar que la vacante sigue existiendo y activa
        $stmt = $pdo->prepare("SELECT nombre, sucursal, area FROM vacantes WHERE id = ? AND status = 'activo'");
        $stmt->execute([$vacante_id]);
        $vacante = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($vacante) {
            // Actualizar el estado del usuario
            $historial = cargarHistorialUsuario($phone_number);
            $historial['estado'] = 'registro_datos';
            $historial['registro_paso'] = 'inicio';
            $historial['vacante_id'] = $vacante_id;
            $historial['vacante_nombre'] = $vacante['nombre'];
            $historial['sucursal_nombre'] = $vacante['sucursal'];
            $historial['area'] = $vacante['area'];
            guardarHistorialUsuario($phone_number, $historial);
            
            // Mensaje para iniciar el proceso de postulación
            $mensaje = "🎯 *¡Excelente elección!*\n\n";
            $mensaje .= "Estás a punto de postularte para: *{$vacante['nombre']}*\n";
            $mensaje .= "En la sucursal: *{$vacante['sucursal']}*\n\n";
            $mensaje .= "Para continuar con tu postulación, necesito algunos datos básicos.\n\n";
            $mensaje .= "📝 Por favor, envíame tu *nombre completo*:";
            
            enviarMensajeTexto($phone_number, $mensaje);
            
            // Guardar en el historial de chat
            guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
        } else {
            // Si la vacante ya no está disponible
            enviarMensajeTexto($phone_number, "⚠️ Lo siento, esta vacante ya no está disponible. ¿Te gustaría ver otras opciones?");
            
            // Ofrecer volver a ver vacantes
            enviarMensajeConBotones($phone_number, "Puedo mostrarte otras vacantes disponibles:", [
                ["id" => "ver_otra", "title" => "Ver otras vacantes"],
                ["id" => "menu_principal", "title" => "Menú principal"]
            ]);
        }
    }
    // Manejador para el botón "Ver otras vacantes"
    elseif ($message_text === "ver_otra") {
        // Obtener información del historial del usuario
        $historial = cargarHistorialUsuario($phone_number);
        $sucursal = $historial['sucursal'] ?? null;
        $sucursal_nombre = $historial['sucursal_nombre'] ?? null;
        
        file_put_contents("whatsapp_log.txt", "🔄 Usuario quiere ver otras vacantes. Sucursal actual: $sucursal_nombre\n", FILE_APPEND);
        
        if ($sucursal && $sucursal_nombre) {
            // Regresar al menú de áreas para esta sucursal
            $historial['estado'] = 'seleccion_area';
            guardarHistorialUsuario($phone_number, $historial);
            
            // Obtener las áreas disponibles para esta sucursal
            $stmt = $pdo->prepare("SELECT DISTINCT area FROM vacantes WHERE sucursal = ? AND status = 'activo'");
            $stmt->execute([$sucursal_nombre]);
            $areas = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (count($areas) > 0) {
                $area_rows = [];
                foreach ($areas as $area) {
                    $id = strtolower(preg_replace('/\s+/', '_', $area));
                    $area_rows[] = ["id" => $id, "title" => $area];
                }

                $mensaje = "📌 *Sucursal:* {$sucursal_nombre}\n\n¿En qué área te gustaría trabajar?";
                enviarMensajeInteractivo(
                    $phone_number,
                    $mensaje,
                    [[
                        "title" => "Áreas disponibles",
                        "rows" => $area_rows
                    ]]
                );
                
                // Guardar mensaje en el historial
                guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
            } else {
                $mensaje = "⚠️ No hay vacantes activas en esta sucursal en este momento.";
                enviarMensajeTexto($phone_number, $mensaje);
                guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
                
                // Ofrecer volver al menú principal
                enviarMensajeConBotones(
                    $phone_number, 
                    "¿Deseas ver otra sucursal o regresar al menú principal?", 
                    [
                        ["id" => "bolsa_trabajo", "title" => "Ver otra sucursal"],
                        ["id" => "menu_principal", "title" => "Menú principal"]
                    ]
                );
            }
        } else {
            // Si no hay información de sucursal, volver al inicio de bolsa de trabajo
            $mensaje = "Para mostrar las vacantes disponibles, primero necesito que selecciones una sucursal.";
            enviarMensajeTexto($phone_number, $mensaje);
            guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, 'seleccion_sucursal');
            
            // Obtener lista de sucursales y mostrarla
            $secciones = obtenerListaSucursales();
            
            if (!empty($secciones[0]['rows'])) {
                enviarMensajeInteractivo(
                    $phone_number,
                    "🏢 *Estas son las sucursales con vacantes activas.*\n\nPor favor, selecciona la sucursal en la que te gustaría trabajar:",
                    $secciones
                );
            } else {
                enviarMensajeTexto($phone_number, "⚠️ No hay sucursales con vacantes disponibles en este momento. Por favor, intenta más tarde.");
                
                // Volver al menú principal
                enviarMensajeConBotones($phone_number, "¿Deseas volver al menú principal?", [
                    ["id" => "menu_principal", "title" => "Menú principal"]
                ]);
            }
        }
    }
    
    // **3️⃣ Si el usuario envía "Hola", responde con el menú interactivo**
    elseif ($message_text === "hola") {
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
    
    // Manejador para el botón "Menú principal"
    elseif ($message_text === "menu_principal" || $message_text === "menu") {
        // Resetear el estado del usuario
        guardarHistorialUsuario($phone_number, ["estado" => null]);
        
        // Mostrar el menú principal
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
        
        // Guardar mensaje en el historial
        guardarMensajeChat($phone_number, null, 'respuesta', "Menú principal mostrado", "menu_principal");
    }

    

    // Si ninguno de los bloques anteriores manejó el mensaje
    else {
        // Verificar si estamos en un flujo específico
        if (empty($estado)) {
        // Si no hay estado, probablemente sea un mensaje genérico o el primer mensaje
        enviarMensajeTexto($phone_number, "👋 Hola, para comenzar a usar este servicio, por favor escribe 'Hola' o 'Menú' para ver las opciones disponibles.");
        }
    }
} // Cierre del bloque principal

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

// Ahora necesitamos agregar una nueva función para enviar mensajes con botones:
function enviarMensajeConBotones($telefono, $mensaje, $botones) {
    global $API_URL, $ACCESS_TOKEN;

    $telefono = corregirFormatoTelefono($telefono);

    $payload = [
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" => $telefono,
        "type" => "interactive",
        "interactive" => [
            "type" => "button",
            "body" => ["text" => $mensaje],
            "action" => [
                "buttons" => array_map(function($btn) {
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

    // Guardar en logs
    file_put_contents("whatsapp_log.txt", "🟡 Enviando mensaje con botones:\n" . json_encode($payload, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

    $context = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Authorization: Bearer $ACCESS_TOKEN\r\nContent-Type: application/json",
            "content" => json_encode($payload)
        ]
    ]);

    $response = file_get_contents($API_URL, false, $context);

    // Guardar logs de la respuesta
    file_put_contents("whatsapp_log.txt", "✅ Respuesta de WhatsApp: " . $response . "\n", FILE_APPEND);
}


?>