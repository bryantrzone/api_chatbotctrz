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

// Al inicio de tu script, añade un log para registrar el mensaje entrante
file_put_contents("whatsapp_log.txt", "📩 Mensaje recibido de $phone_number: '$message_text', Estado actual: $estado\n", FILE_APPEND);

// **Verificar que el mensaje es válido**
// Al inicio del script, añade esto para una mejor depuración
file_put_contents("whatsapp_log.txt", "🔍 Verificando tipo de mensaje recibido\n", FILE_APPEND);

// Verifica detalladamente la estructura del mensaje para depurar
if (isset($input['entry']) && 
    isset($input['entry'][0]['changes']) && 
    isset($input['entry'][0]['changes'][0]['value']) && 
    isset($input['entry'][0]['changes'][0]['value']['messages']) &&
    isset($input['entry'][0]['changes'][0]['value']['messages'][0]['type'])) {
        
    // Verifica el historial actual del usuario
    $historial = cargarHistorialUsuario($phone_number);
    $estado = $historial['estado'] ?? 'inicio';
    $paso = $historial['registro_paso'] ?? '';
    
    file_put_contents("whatsapp_log.txt", "👤 Estado actual: $estado, Paso: $paso\n", FILE_APPEND);

    $message_data = $input['entry'][0]['changes'][0]['value']['messages'][0];
    // $phone_number = corregirFormatoTelefono($message_data['from']); // Número del usuario
    $message_type = $input['entry'][0]['changes'][0]['value']['messages'][0]['type'];
    $phone_number = $input['entry'][0]['changes'][0]['value']['messages'][0]['from'];

    file_put_contents("whatsapp_log.txt", "📱 Mensaje detectado de $phone_number de tipo: $message_type\n", FILE_APPEND);

    // Verificar el tipo de mensaje
    if ($message_type === 'document' || $message_type === 'image') {
        $historial = cargarHistorialUsuario($phone_number);
        $estado = $historial['estado'] ?? 'inicio';
        
        // Solo procesamos archivos si estamos en el estado correcto
        if ($estado === 'registro_datos' && $historial['registro_paso'] === 'esperando_cv') {
            // Es un documento
            if ($message_type === 'document') {
                $media_id = $input['entry'][0]['changes'][0]['value']['messages'][0]['document']['id'];
                $file_name = $input['entry'][0]['changes'][0]['value']['messages'][0]['document']['filename'];
                $mime_type = $input['entry'][0]['changes'][0]['value']['messages'][0]['document']['mime_type'];
                
                // Verificar tipos de archivo permitidos
                $allowed_mime_types = [
                    'application/pdf', 
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
                    'application/msword' // .doc
                ];
                
                if (in_array($mime_type, $allowed_mime_types)) {
                    procesarArchivo($phone_number, $media_id, $file_name, $mime_type, $historial);
                } else {
                    enviarMensajeTexto($phone_number, "⚠️ Formato no soportado. Por favor, envía tu CV en formato PDF o Word (.doc/.docx).");
                }
            }
            // Es una imagen
            else if ($message_type === 'image') {
                $media_id = $input['entry'][0]['changes'][0]['value']['messages'][0]['image']['id'];
                $mime_type = $input['entry'][0]['changes'][0]['value']['messages'][0]['image']['mime_type'];
                $file_name = "imagen_cv_" . time() . ".jpg"; // Generamos un nombre para la imagen
                
                procesarArchivo($phone_number, $media_id, $file_name, $mime_type, $historial);
            }
        }
    }

   // Variables iniciales
    $message_text = "";
    $mensaje_original = "";
    $tipo_mensaje = "texto";

    // Determinar el tipo de mensaje y extraer su contenido
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
    
    // Registrar información para debug
    file_put_contents("whatsapp_log.txt", "📌 DATOS RECIBIDOS - Teléfono: $phone_number, Mensaje: $message_text, Estado: $estado, Tipo: $tipo_mensaje\n", FILE_APPEND);

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

    // ************************************************************************
    // *** PRIORIDAD MÁXIMA: Manejo del flujo de registro de datos ***********
    // ************************************************************************
    // Este bloque debe ejecutarse primero para evitar conflictos con otros manejadores
    if ($estado === "registro_datos") {
        // Verificar en qué paso del registro estamos
        $historial = cargarHistorialUsuario($phone_number);
        $paso = $historial['registro_paso'] ?? 'inicio';
        
        file_put_contents("whatsapp_log.txt", "🔵 PROCESANDO REGISTRO en paso: $paso - Mensaje: $message_text\n", FILE_APPEND);
        
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
            case 'esperando_cv':
                    // Si el usuario envía texto en vez de un archivo
                    if ($message_type === 'text') {
                        // Registrar en el log
                        file_put_contents("whatsapp_log.txt", "🔄 Usuario en estado esperando_cv envió texto: $message_text\n", FILE_APPEND);
                        
                        // Verificar si quiere omitir el CV
                        if (strtolower($message_text) === 'no tengo cv' || 
                            strtolower($message_text) === 'no' || 
                            strtolower($message_text) === 'pasar' || 
                            strtolower($message_text) === 'omitir') {
                            
                            // El usuario indica que no tiene CV o quiere omitir este paso
                            $mensaje = "No hay problema. Hemos completado tu postulación sin adjuntar CV.\n\n";
                            $mensaje .= "Nuestro equipo de recursos humanos revisará tu información y se pondrá en contacto contigo en un máximo de 3 días hábiles a través del correo proporcionado.\n\n";
                            $mensaje .= "Si en algún momento deseas enviarnos tu CV, simplemente envíanos el archivo y lo adjuntaremos a tu postulación.";
                            
                            enviarMensajeTexto($phone_number, $mensaje);
                            guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
                            
                            // Actualizar el estado
                            $historial['registro_paso'] = 'completo';
                            guardarHistorialUsuario($phone_number, $historial);
                            
                            // Ofrecer opciones para continuar
                            enviarMensajeConBotones($phone_number, "¿Qué te gustaría hacer ahora?", [
                                ["id" => "ver_otra", "title" => "Ver otras vacantes"],
                                ["id" => "menu_principal", "title" => "Volver al menú"]
                            ]);
                        } else {
                            // Recordar al usuario que esperamos un archivo
                            $mensaje = "Estamos esperando tu CV en formato PDF, Word o imagen. Si no tienes CV, puedes escribir 'Omitir' para finalizar tu postulación sin adjuntar CV.";
                            enviarMensajeTexto($phone_number, $mensaje);
                            guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
                        }
                    }
                    // Si es un documento o imagen, se maneja en la parte superior del script
                    break;
                case 'experiencia':
                    // Procesamos el email
                    if (filter_var($message_text, FILTER_VALIDATE_EMAIL)) {
                        // Guardar el email
                        $historial['email'] = $message_text;
                        // Cambiar a paso esperando_cv (¡NO a completo todavía!)
                        $historial['registro_paso'] = 'esperando_cv';
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
                            $mensaje = "🎉 *¡Excelente! Tu información básica ha sido registrada*\n\n";
                            $mensaje .= "📝 *Resumen de tu postulación:*\n";
                            $mensaje .= "👤 *Nombre:* {$historial['nombre']}\n";
                            $mensaje .= "📧 *Email:* {$historial['email']}\n";
                            $mensaje .= "📢 *Vacante:* {$historial['vacante_nombre']}\n";
                            $mensaje .= "📍 *Sucursal:* {$historial['sucursal_nombre']}\n\n";
                            
                            // IMPORTANTE: Añadir la solicitud del CV antes de dar opciones para continuar
                            $mensaje .= "📄 *¡Un paso más!* Si tienes tu CV listo, puedes enviarlo ahora como archivo PDF, Word o una imagen. Esto ayudará a nuestro equipo a evaluar mejor tu perfil.\n\n";
                            $mensaje .= "Si no tienes CV disponible, puedes escribir 'Omitir' para finalizar tu postulación sin CV.";
                            
                            // Enviar confirmación y solicitud de CV
                            enviarMensajeTexto($phone_number, $mensaje);
                            guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);
                            
                            // NO envíes botones para continuar todavía - espera el CV
                            
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
        
        // *** IMPORTANTE: Terminar la ejecución aquí para evitar que continúe con otros manejadores ***
        return;
    }

    // **IMPORTANTE: Verificar si es una acción de botón para ver detalles o postularse**
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
        
        // Salir después de procesar la acción del botón
        return;
    }
    // Manejador para el botón "Postularme" o "Seleccionar"
    elseif (strpos($message_text, "postularme_") === 0 || strpos($message_text, "seleccionar_") === 0) {
        // Extraer el ID de la vacante
        $vacante_id = 0;
        if (strpos($message_text, "postularme_") === 0) {
            $vacante_id = intval(str_replace("postularme_", "", $message_text));
            file_put_contents("whatsapp_log.txt", "✅ Usuario quiere postularse a la vacante ID: $vacante_id\n", FILE_APPEND);
        } else {
            $vacante_id = intval(str_replace("seleccionar_", "", $message_text));
            file_put_contents("whatsapp_log.txt", "✅ Usuario quiere seleccionar la vacante ID: $vacante_id\n", FILE_APPEND);
        }
        
        // Verificar que la vacante sigue existiendo y activa
        $stmt = $pdo->prepare("SELECT nombre, sucursal, area FROM vacantes WHERE id = ? AND status = 'activo'");
        $stmt->execute([$vacante_id]);
        $vacante = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($vacante) {
            // Actualizar el estado del usuario - IMPORTANTE para el flujo de registro
                // Al inicio, antes de cargar el historial
            file_put_contents("whatsapp_log.txt", "🔍 Intentando cargar historial para: $phone_number\n", FILE_APPEND);

            $historial = cargarHistorialUsuario($phone_number);
            // Después de cargar el historial
            file_put_contents("whatsapp_log.txt", "📊 Historial cargado: " . json_encode($historial) . "\n", FILE_APPEND);
            
            $historial['estado'] = 'registro_datos';
            $historial['registro_paso'] = 'inicio';
            $historial['vacante_id'] = $vacante_id;
            $historial['vacante_nombre'] = $vacante['nombre'];
            $historial['sucursal_nombre'] = $vacante['sucursal'];
            $historial['area'] = $vacante['area'];
            
            // Guardar el historial ANTES de enviar el mensaje
            guardarHistorialUsuario($phone_number, $historial);
            file_put_contents("whatsapp_log.txt", "💾 Guardado estado 'registro_datos' para usuario: $phone_number\n", FILE_APPEND);
            
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
        
        // Salir después de procesar la acción del botón
        return;
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
        
        // Salir después de procesar la acción del botón
        return;
    }
    
    // **3️⃣ Si el usuario envía "Hola", responde con el menú interactivo**
    elseif ($message_text === "hola") {
        enviarMensajeInteractivo($phone_number, 
            "😊 *¡Bienvenido! Soy Falco, tu asistente virtual 🤖.*\n\nEstoy aquí para resolver tus dudas y guiarte en lo que necesites. \n\n*¿Cómo puedo ayudarte hoy?*",
            [
                [
                    "title" => "Opciones de servicio",
                    "rows" => [
                        ["id" => "bolsa_trabajo", "title" => "Lista de vacantes"],
                        ["id" => "postularme_vacante", "title" => "Postularme a una vacante"],
                        ["id" => "quejas_gtrz", "title" => "Quejas Grupo Tractozone"],                        
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
        
        // Guardar
        // Guardar mensaje en el historial
        guardarMensajeChat($phone_number, null, 'respuesta', "Menú principal mostrado", "menu_principal");
    }

    // Añade este bloque ANTES del bloque que compartiste
    if (strpos($message_text, "ver_detalles_") === 0) {
        $vacante_id = intval(str_replace("ver_detalles_", "", $message_text));
        
        // Registrar en el log para depuración
        file_put_contents("whatsapp_log.txt", "🔍 Ver detalles para vacante ID: $vacante_id\n", FILE_APPEND);
        
        // Cargar historial del usuario
        $historial = cargarHistorialUsuario($phone_number);
        
        try {
            // Obtener detalles de la vacante
            $stmt = $pdo->prepare("SELECT * FROM vacantes WHERE id = ?");
            $stmt->execute([$vacante_id]);
            $vacante = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$vacante) {
                enviarMensajeTexto($phone_number, "⚠️ No se encontró la vacante solicitada.");
                return;
            }
            
            // Actualizar historial - guarda la vacante que el usuario está viendo
            $historial['vacante_actual'] = $vacante_id;
            $historial['estado'] = 'ver_detalles';
            guardarHistorialUsuario($phone_number, $historial);
            
            // Crear mensaje con todos los detalles
            $mensaje = "📋 *DETALLES DE LA VACANTE*\n\n"
                    . "📢 *{$vacante['nombre']}*\n"
                    . "📍 *Sucursal:* {$vacante['sucursal']}\n"
                    . "🏢 *Área:* {$vacante['area']}\n"
                    . "📝 *Descripción:* {$vacante['descripcion']}\n"
                    . "⏰ *Horario:* {$vacante['horario']}\n";
            
            // Si hay más campos en tu tabla de vacantes, agrégalos aquí
            if (!empty($vacante['requisitos'])) {
                $mensaje .= "✅ *Requisitos:* {$vacante['requisitos']}\n";
            }
            
            if (!empty($vacante['sueldo'])) {
                $mensaje .= "💰 *Sueldo:* {$vacante['sueldo']}\n";
            }
            
            if (!empty($vacante['beneficios'])) {
                $mensaje .= "🎁 *Beneficios:* {$vacante['beneficios']}\n";
            }
            
            $mensaje .= "\n¿Te interesa postularte para esta vacante?";
            
            // Enviar mensaje con botones para postularse o ver otras vacantes
            enviarMensajeConBotones($phone_number, $mensaje, [
                ["id" => "postularme_{$vacante_id}", "title" => "¡Sí, postularme!"],
                ["id" => "ver_otra", "title" => "Ver otras vacantes"]
            ]);
            
        } catch (PDOException $e) {
            file_put_contents("error_log_sql.txt", date('Y-m-d H:i:s') . " | Error al obtener detalles de vacante: " . $e->getMessage() . "\n", FILE_APPEND);
            enviarMensajeTexto($phone_number, "⚠️ Ocurrió un error al procesar tu solicitud. Por favor, intenta nuevamente.");
        }
        
        return; // Importante: termina la ejecución después de manejar esta acción
    }
    
    // Manejo de selección de área y mostrar vacantes
    elseif ($estado === "seleccion_area" || $estado === "mostrar_vacantes") {
        // Este bloque se ejecuta SOLO para áreas de trabajo reales, no para botones tipo "ver_detalles" o "postularme"
        // Verificar que no es una acción de botón especial que ya fue manejada arriba
        if (strpos($message_text, "ver_detalles_") === 0 || 
            strpos($message_text, "postularme_") === 0 || 
            strpos($message_text, "seleccionar_") === 0 || 
            $message_text === "ver_otra") {
            // Ya manejado en los bloques anteriores
            return;
        }

        $area = ucwords(str_replace('_', ' ', strtolower($message_text))); // Ejemplo: ventas → Ventas
        $historial = cargarHistorialUsuario($phone_number);
        $sucursal_nombre = $historial['sucursal_nombre'] ?? null;
        
        file_put_contents("whatsapp_log.txt", "🔍 Buscando vacantes para área: $area en sucursal: $sucursal_nombre\n", FILE_APPEND);

        if (!$sucursal_nombre) {
            enviarMensajeTexto($phone_number, "⚠️ Hubo un error al recuperar tu sucursal. Por favor, si quieres comenzar de nuevo, escribe 'Menú principal'.");
            return;
        }

        // Guardar el área seleccionada en el historial
        $historial['estado'] = 'mostrar_vacantes';
        $historial['area'] = $area;
        guardarHistorialUsuario($phone_number, $historial);
    
        // Consultar vacantes activas en la sucursal y área seleccionada
        $stmt = $pdo->prepare("SELECT id, nombre, descripcion, horario FROM vacantes WHERE status = 'activo' AND sucursal = ? AND area = ?");
        $stmt->execute([$sucursal_nombre, $area]);
        $vacantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($vacantes) === 0) {
            enviarMensajeTexto($phone_number, "😕 No hay vacantes activas en *$area* en *$sucursal_nombre*.");
            return;
        }
    
        // Enviar cada vacante en un mensaje separado con botones
        foreach ($vacantes as $v) {
            $mensaje = "📌 *Vacante Disponible:*\n\n"
                    . "📢 *{$v['nombre']}*\n"
                    . "📍 *Sucursal:* $sucursal_nombre\n"
                    . "📝 *Descripción:* {$v['descripcion']}\n"
                    . "⏰ *Horario:* {$v['horario']}\n";

            enviarMensajeConBotones($phone_number, $mensaje, [
                ["id" => "seleccionar_{$v['id']}", "title" => "Seleccionar"],
                ["id" => "ver_detalles_{$v['id']}", "title" => "Ver más detalles"]
            ]);
        }
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

    file_put_contents("whatsapp_log.txt", "📱 Teléfono corregido: $telefono\n", FILE_APPEND);
    return $telefono;
}

function guardarHistorialUsuario($telefono, $datos) {
    global $pdo;
    
    // Asegurar formato correcto del teléfono
    $telefono = corregirFormatoTelefono($telefono);
    
    file_put_contents("whatsapp_log.txt", "📝 Intentando guardar historial para: $telefono con datos: " . json_encode($datos) . "\n", FILE_APPEND);

    try {
        $stmt = $pdo->prepare("SELECT id FROM usuarios_historial WHERE telefono = ?");
        $stmt->execute([$telefono]);
        $existe = $stmt->fetch();

        if ($existe) {
            file_put_contents("whatsapp_log.txt", "🔄 Actualizando registro existente para: $telefono\n", FILE_APPEND);
            
            // Construir dinámicamente la consulta UPDATE
            $sql = "UPDATE usuarios_historial SET updated_at = NOW()";
            $params = [":telefono" => $telefono];
            
            foreach ($datos as $campo => $valor) {
                if ($campo != 'id' && $campo != 'telefono' && $campo != 'created_at' && $campo != 'updated_at') {
                    $sql .= ", $campo = :$campo";
                    $params[":$campo"] = $valor;
                }
            }
            
            $sql .= " WHERE telefono = :telefono";
        } else {
            file_put_contents("whatsapp_log.txt", "➕ Creando nuevo registro para: $telefono\n", FILE_APPEND);
            
            // Construir dinámicamente la consulta INSERT
            $campos = ["telefono"];
            $valores = [":telefono"];
            $params = [":telefono" => $telefono];
            
            foreach ($datos as $campo => $valor) {
                if ($campo != 'id' && $campo != 'telefono' && $campo != 'created_at' && $campo != 'updated_at') {
                    $campos[] = $campo;
                    $valores[] = ":$campo";
                    $params[":$campo"] = $valor;
                }
            }
            
            $sql = "INSERT INTO usuarios_historial (" . implode(", ", $campos) . ") 
                   VALUES (" . implode(", ", $valores) . ")";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        file_put_contents("whatsapp_log.txt", "✅ Historial guardado en BD para $telefono: " . json_encode($datos) . "\n", FILE_APPEND);
        return true;
    } catch (PDOException $e) {
        file_put_contents("error_log_sql.txt", date('Y-m-d H:i:s') . " | Error en guardarHistorialUsuario: " . $e->getMessage() . " | SQL: $sql | Params: " . json_encode($params) . "\n", FILE_APPEND);
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

// Función para enviar mensajes con botones:
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
    
    // Guardar mensaje del bot
    $estado = cargarHistorialUsuario($telefono)['estado'] ?? null;
    guardarMensajeChat($telefono, null, 'respuesta_botones', $mensaje, $estado);
}

// Función para procesar archivos recibidos
function procesarArchivo($phone_number, $media_id, $file_name, $mime_type, $historial) {
    global $pdo;

    file_put_contents("whatsapp_log.txt", "📄 INICIO PROCESAMIENTO DE ARCHIVO para $phone_number\n", FILE_APPEND);
    file_put_contents("whatsapp_log.txt", "🔢 Estado actual: {$historial['estado']}, Paso: {$historial['registro_paso']}\n", FILE_APPEND);

    $upload_dir = 'uploads/cv/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $file_content = descargarMediaWhatsApp($media_id);

    if ($file_content) {
        // Verificación de formato del archivo
        $first_bytes = substr($file_content, 0, 9);
        $is_valid_file = true;
        $formato = 'desconocido';

        if (strpos($file_content, '%PDF') === 0) {
            $formato = 'PDF';
        } elseif (strpos($file_content, "\xFF\xD8\xFF") === 0) {
            $formato = 'JPEG';
        } elseif (strpos($file_content, "\x89PNG") === 0) {
            $formato = 'PNG';
        } elseif (strpos($file_content, 'PK') === 0 && strpos($file_content, '[Content_Types].xml') !== false) {
            $formato = 'DOCX';
        } elseif (substr($first_bytes, 0, 9) === '<!DOCTYPE' || substr($first_bytes, 0, 1) === '{') {
            $is_valid_file = false;
            $formato = 'error-html';
        }

        file_put_contents("whatsapp_log.txt", "🔍 Verificación de formato - Detectado: $formato\n", FILE_APPEND);

        if (!$is_valid_file) {
            enviarMensajeTexto($phone_number, "❌ El archivo que enviaste no es válido o no pudimos procesarlo. Por favor, intenta enviarlo nuevamente en formato PDF, JPG o DOCX.");
            return;
        }

        // Definir extensión según formato detectado
        switch ($formato) {
            case 'PDF':  $ext = 'pdf'; break;
            case 'JPEG': $ext = 'jpg'; break;
            case 'PNG':  $ext = 'png'; break;
            case 'DOCX': $ext = 'docx'; break;
            default:     $ext = 'bin'; break;
        }

        $unique_file_name = uniqid("cv_{$phone_number}_") . '.' . $ext;
        $file_path = $upload_dir . $unique_file_name;

        $bytes_written = file_put_contents($file_path, $file_content);

        if ($bytes_written === false || $bytes_written === 0) {
            file_put_contents("whatsapp_log.txt", "❌ Error al guardar el archivo: $file_path\n", FILE_APPEND);
            enviarMensajeTexto($phone_number, "❌ Hubo un problema al guardar tu documento. Por favor, intenta nuevamente.");
            return;
        }

        chmod($file_path, 0644);
        $file_size = filesize($file_path);
        file_put_contents("whatsapp_log.txt", "✅ Archivo guardado, tamaño: $file_size bytes\n", FILE_APPEND);

        try {
            // Verifica si ya hay una postulación
            $stmt = $pdo->prepare("SELECT id FROM postulaciones WHERE telefono = ? AND vacante_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$phone_number, $historial['vacante_id']]);
            $postulacion = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($postulacion) {
                $stmt = $pdo->prepare("UPDATE postulaciones SET cv_path = ? WHERE id = ?");
                $stmt->execute([$file_path, $postulacion['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO postulaciones 
                    (telefono, nombre, edad, experiencia, email, vacante_id, cv_path, fecha_postulacion, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'pendiente')");

                $stmt->execute([
                    $phone_number,
                    $historial['nombre'],
                    $historial['edad'] ?? null,
                    $historial['experiencia'] ?? null,
                    $historial['email'] ?? null,
                    $historial['vacante_id'],
                    $file_path
                ]);
            }

            $historial['cv_path'] = $file_path;
            guardarHistorialUsuario($phone_number, $historial);

            $mensaje = "✅ *¡Tu CV ha sido recibido correctamente!*\n\n";
            $mensaje .= "Hemos adjuntado tu documento a tu postulación para *{$historial['vacante_nombre']}*.\n\n";
            $mensaje .= "Nuestro equipo de recursos humanos se pondrá en contacto contigo pronto para continuar con el proceso.";

            enviarMensajeTexto($phone_number, $mensaje);
            guardarMensajeChat($phone_number, null, 'respuesta', $mensaje, $historial['estado']);

            sleep(1);
            enviarMensajeConBotones($phone_number, "¿Qué te gustaría hacer ahora?", [
                ["id" => "ver_otra", "title" => "Ver otras vacantes"],
                ["id" => "menu_principal", "title" => "Volver al menú"]
            ]);

        } catch (PDOException $e) {
            file_put_contents("error_log_sql.txt", date('Y-m-d H:i:s') . " | Error al guardar CV: " . $e->getMessage() . "\n", FILE_APPEND);
            enviarMensajeTexto($phone_number, "❌ Hubo un error al procesar tu documento. Por favor, intenta nuevamente o contacta a soporte.");
        }

    } else {
        enviarMensajeTexto($phone_number, "❌ No pudimos descargar tu documento. Por favor, intenta enviarlo nuevamente.");
    }
}


function descargarMediaConCURL($url, $token) {
    // Intentar con librería cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    $file_content = curl_exec($ch);
    curl_close($ch);
    
    return $file_content;
}

function descargarMediaConFileGetContents($url, $token) {
    // Intentar con file_get_contents
    $context = stream_context_create([
        'http' => [
            'header' => "Authorization: Bearer $token"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    
    $file_content = @file_get_contents($url, false, $context);
    return $file_content;
}

function descargarMediaWhatsApp($media_id) {
    global $config;
    
    $token = $config['ACCESS_TOKEN'];
    
    // URL directa para descargar el archivo usando el endpoint /media
    $direct_url = "https://graph.facebook.com/v18.0/{$media_id}/media";
    
    file_put_contents("whatsapp_log.txt", "🔄 Usando endpoint directo para media ID: $media_id\n", FILE_APPEND);
    file_put_contents("whatsapp_log.txt", "🔗 URL: $direct_url\n", FILE_APPEND);
    
    // Configuración de cURL para hacer la descarga directa
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $direct_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, true); // Importante para archivos binarios
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    
    
    // Ejecutar la solicitud
    $file_content = curl_exec($ch);
    $file_error = curl_error($ch);
    $file_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    
    // Cerrar la conexión cURL
    curl_close($ch);
    
    // Registrar información para depuración
    file_put_contents("whatsapp_log.txt", "📊 Respuesta: status $file_status, tipo $content_type, tamaño " . strlen($file_content) . " bytes, error: $file_error\n", FILE_APPEND);
    
    // Verificar si la respuesta es un error en formato JSON
    $is_json_error = false;
    if ($content_type == "application/json") {
        $json_data = json_decode($file_content, true);
        if (isset($json_data['error'])) {
            $is_json_error = true;
            file_put_contents("whatsapp_log.txt", "❌ Error en respuesta JSON: " . json_encode($json_data['error']) . "\n", FILE_APPEND);
        }
    }
    
    // Devolver el contenido solo si la descarga fue exitosa y no es un error JSON
    if ($file_status == 200 && !empty($file_content) && !$is_json_error) {
        return $file_content;
    }
    
    // Si falló el método directo, intentar el método en dos pasos
    file_put_contents("whatsapp_log.txt", "⚠️ El método directo falló, intentando método en dos pasos\n", FILE_APPEND);
    
    // Paso 1: Obtener la URL del archivo
    $url = "https://graph.facebook.com/v18.0/{$media_id}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Verificar respuesta
    if ($status != 200) {
        file_put_contents("whatsapp_log.txt", "❌ Error obteniendo datos del archivo: $status\n", FILE_APPEND);
        return false;
    }
    
    $data = json_decode($response, true);
    if (!isset($data['url'])) {
        file_put_contents("whatsapp_log.txt", "❌ No se encontró URL en la respuesta\n", FILE_APPEND);
        return false;
    }
    
    $file_url = $data['url'];
    file_put_contents("whatsapp_log.txt", "🔗 URL recibida: $file_url\n", FILE_APPEND);
    
    // Descargar ahora con curl, sin enviar el token al servidor lookaside.fbsbx.com
    $file_ch = curl_init();
    curl_setopt($file_ch, CURLOPT_URL, $file_url);
    curl_setopt($file_ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($file_ch, CURLOPT_BINARYTRANSFER, true); // Importante para archivos binarios
    // IMPORTANTE: NO enviar header de autorización para lookaside.fbsbx.com
    curl_setopt($file_ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($file_ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($file_ch, CURLOPT_TIMEOUT, 120);
    
    $file_content = curl_exec($file_ch);
    $file_error = curl_error($file_ch);
    $file_status = curl_getinfo($file_ch, CURLINFO_HTTP_CODE);
    curl_close($file_ch);
    
    file_put_contents("whatsapp_log.txt", "📊 Descarga: status $file_status, tamaño " . strlen($file_content) . " bytes, error: $file_error\n", FILE_APPEND);
    
    if ($file_status == 200 && !empty($file_content)) {

        // Dentro de descargarMediaWhatsApp(), justo antes de retornar el contenido exitoso:

        // Verificar firma de bytes para detectar archivo válido
        $first_bytes = substr($file_content, 0, 8);
        $is_pdf = (substr($file_content, 0, 4) === '%PDF');
        $is_jpeg = (substr($file_content, 0, 2) === "\xFF\xD8");
        $is_png = (substr($file_content, 0, 8) === "\x89PNG\r\n\x1A\n");
        $is_docx = (substr($file_content, 0, 4) === 'PK' . chr(3) . chr(4));

        file_put_contents("whatsapp_log.txt", "🔍 Verificación de formato - PDF: " . ($is_pdf ? "Sí" : "No") . 
            ", JPEG: " . ($is_jpeg ? "Sí" : "No") . 
            ", PNG: " . ($is_png ? "Sí" : "No") . 
            ", DOCX: " . ($is_docx ? "Sí" : "No") . 
            ", Primeros bytes (hex): " . bin2hex($first_bytes) . "\n", FILE_APPEND);

        if (!$is_pdf && !$is_jpeg && !$is_png && !$is_docx && strpos($content_type, 'text/html') !== false) {
            file_put_contents("whatsapp_log.txt", "⚠️ El contenido no parece ser un archivo válido\n", FILE_APPEND);
            file_put_contents("error_content_sample.txt", substr($file_content, 0, 1000));
            return false;
        }

        return $file_content;
    }
    
    file_put_contents("whatsapp_log.txt", "❌ Ambos métodos fallaron para descargar el archivo\n", FILE_APPEND);
    return false;
}
?>