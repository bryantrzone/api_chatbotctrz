<?php
// Incluir configuración de base de datos
require_once "db.php";

// Función para obtener todas las conversaciones (números de teléfono únicos)
function obtenerConversaciones($pdo) {
    $query = "
        SELECT u.phone_number, u.name, 
            MAX(m.timestamp) as ultimo_mensaje,
            (SELECT message FROM messages WHERE phone_number = u.phone_number ORDER BY timestamp DESC LIMIT 1) as ultimo_texto
        FROM users u
        JOIN messages m ON u.phone_number = m.phone_number
        GROUP BY u.phone_number, u.name
        ORDER BY ultimo_mensaje DESC
    ";
    
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al obtener conversaciones: " . $e->getMessage());
        return [];
    }
}

// Función para obtener mensajes de una conversación específica
function obtenerMensajesConversacion($pdo, $phoneNumber) {
    $query = "
        SELECT 
            id, message, message_type, direction, timestamp, 
            media_url, media_id, additional_info, wamid, context_wamid
        FROM messages 
        WHERE phone_number = ?
        ORDER BY timestamp ASC
    ";
    
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute([$phoneNumber]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al obtener mensajes: " . $e->getMessage());
        return [];
    }
}

// Obtener conversaciones para el listado
$conversaciones = obtenerConversaciones($pdo);

// Obtener mensajes de la conversación seleccionada (si existe)
$mensajesConversacion = [];
$telefonoSeleccionado = '';
$nombreContacto = '';

if (isset($_GET['telefono']) && !empty($_GET['telefono'])) {
    $telefonoSeleccionado = $_GET['telefono'];
    $mensajesConversacion = obtenerMensajesConversacion($pdo, $telefonoSeleccionado);
    
    // Obtener nombre del contacto
    $queryNombre = "SELECT name FROM users WHERE phone_number = ? LIMIT 1";
    $stmtNombre = $pdo->prepare($queryNombre);
    $stmtNombre->execute([$telefonoSeleccionado]);
    $resultNombre = $stmtNombre->fetch(PDO::FETCH_ASSOC);
    $nombreContacto = $resultNombre['name'] ?? $telefonoSeleccionado;
}

// Formatear la fecha en español
function formatearFecha($fecha) {
    $timestamp = strtotime($fecha);
    $hoy = strtotime(date('Y-m-d'));
    $ayer = strtotime('-1 day', $hoy);
    
    if ($timestamp >= $hoy) {
        return 'Hoy ' . date('H:i', $timestamp);
    } elseif ($timestamp >= $ayer) {
        return 'Ayer ' . date('H:i', $timestamp);
    } else {
        return date('d/m/Y H:i', $timestamp);
    }
}

// Función para mostrar una previsualización del tipo de mensaje
function previewMensaje($mensaje, $tipo) {
    if ($tipo == 'text') {
        return $mensaje;
    } else if ($tipo == 'reaction') {
        return "Reacción: " . $mensaje;
    } else if ($tipo == 'image') {
        return "📷 " . ($mensaje != 'Imagen recibida' ? $mensaje : 'Imagen');
    } else if ($tipo == 'audio') {
        return "🔊 Nota de voz";
    } else if ($tipo == 'video') {
        return "🎥 " . ($mensaje != 'Video recibido' ? $mensaje : 'Video');
    } else if ($tipo == 'document') {
        return "📄 " . $mensaje;
    } else if ($tipo == 'location') {
        return "📍 Ubicación";
    } else if ($tipo == 'sticker') {
        return "Sticker";
    } else if ($tipo == 'template') {
        return "📝 " . $mensaje;
    } else {
        return $tipo . ": " . $mensaje;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visor de Conversaciones WhatsApp</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --wa-green: #075E54;
            --wa-light-green: #128C7E;
            --wa-background: #ECE5DD;
            --sent-bubble: #DCF8C6;
            --received-bubble: #FFFFFF;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #F5F5F5;
        }
        
        .container {
            display: flex;
            height: 100vh;
        }
        
        /* Lista de conversaciones */
        .conversations-list {
            width: 30%;
            background-color: #FFFFFF;
            overflow-y: auto;
            border-right: 1px solid #E0E0E0;
        }
        
        .list-header {
            background-color: var(--wa-green);
            color: white;
            padding: 15px;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        
        .conversation-item {
            padding: 15px;
            border-bottom: 1px solid #E0E0E0;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .conversation-item:hover {
            background-color: #F5F5F5;
        }
        
        .conversation-item.active {
            background-color: #E0E0E0;
        }
        
        .contact-name {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .last-message {
            color: #666;
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }
        
        .message-time {
            font-size: 12px;
            color: #888;
            text-align: right;
            margin-top: 5px;
        }
        
        /* Área de chat */
        .chat-area {
            width: 70%;
            display: flex;
            flex-direction: column;
        }
        
        .chat-header {
            background-color: var(--wa-green);
            color: white;
            padding: 15px;
            display: flex;
            align-items: center;
        }
        
        .contact-info {
            margin-left: 10px;
        }
        
        .messages-container {
            flex: 1;
            background-color: var(--wa-background);
            padding: 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        
        .message {
            max-width: 75%;
            padding: 10px 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            position: relative;
            word-wrap: break-word;
        }
        
        .message.sent {
            background-color: var(--sent-bubble);
            align-self: flex-end;
            border-top-right-radius: 0;
        }
        
        .message.received {
            background-color: var(--received-bubble);
            align-self: flex-start;
            border-top-left-radius: 0;
        }
        
        .message-info {
            display: flex;
            justify-content: flex-end;
            font-size: 11px;
            color: #888;
            margin-top: 5px;
        }
        
        .message-content {
            margin-bottom: 5px;
        }
        
        .message.reaction .message-content {
            font-size: 24px;
            text-align: center;
        }
        
        .message.image img, .message.video video {
            max-width: 100%;
            border-radius: 5px;
        }
        
        .message.audio audio {
            width: 100%;
        }
        
        .message.document a {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: #075E54;
        }
        
        .message.document i {
            margin-right: 10px;
        }
        
        .message.location .location-preview {
            display: block;
            width: 100%;
            height: 150px;
            background-color: #f0f0f0;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 5px;
        }
        
        .no-messages {
            text-align: center;
            color: #888;
            margin-top: 50px;
        }
        
        .loading {
            text-align: center;
            margin: 20px 0;
        }
        
        /* Estilo para la paginación */
        .pagination {
            display: flex;
            justify-content: center;
            margin: 20px 0;
        }
        
        .pagination button {
            background-color: var(--wa-light-green);
            color: white;
            border: none;
            padding: 8px 15px;
            margin: 0 5px;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .pagination button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        
        /* Media queries para pantallas pequeñas */
        @media screen and (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            
            .conversations-list, .chat-area {
                width: 100%;
                height: 50vh;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Lista de conversaciones -->
        <div class="conversations-list">
            <div class="list-header">
                <h2>Conversaciones</h2>
            </div>
            <?php if (empty($conversaciones)): ?>
                <div class="no-conversations">
                    <p>No hay conversaciones disponibles</p>
                </div>
            <?php else: ?>
                <?php foreach ($conversaciones as $conv): ?>
                    <div class="conversation-item <?php echo ($telefonoSeleccionado == $conv['phone_number']) ? 'active' : ''; ?>" data-phone="<?php echo $conv['phone_number']; ?>">
                        <div class="contact-name">
                            <?php echo !empty($conv['name']) ? htmlspecialchars($conv['name']) : htmlspecialchars($conv['phone_number']); ?>
                        </div>
                        <div class="last-message">
                            <?php echo htmlspecialchars(substr($conv['ultimo_texto'], 0, 50)) . (strlen($conv['ultimo_texto']) > 50 ? '...' : ''); ?>
                        </div>
                        <div class="message-time">
                            <?php echo formatearFecha($conv['ultimo_mensaje']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Área de chat -->
        <div class="chat-area">
            <?php if (!empty($telefonoSeleccionado)): ?>
                <div class="chat-header">
                    <div class="contact-info">
                        <h2><?php echo htmlspecialchars($nombreContacto); ?></h2>
                        <div class="phone-number"><?php echo htmlspecialchars($telefonoSeleccionado); ?></div>
                    </div>
                </div>
                <div class="messages-container" id="messagesContainer">
                    <?php if (empty($mensajesConversacion)): ?>
                        <div class="no-messages">
                            <p>No hay mensajes en esta conversación</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($mensajesConversacion as $msg): ?>
                            <div class="message <?php echo $msg['direction'] == 'enviado' ? 'sent' : 'received'; ?> <?php echo $msg['message_type']; ?>">
                                <div class="message-content">
                                    <?php 
                                    switch ($msg['message_type']) {
                                        case 'text':
                                        case 'interactive':
                                        case 'button':
                                        case 'template':
                                            echo nl2br(htmlspecialchars($msg['message']));
                                            break;
                                            
                                        case 'image':
                                            if (!empty($msg['media_url'])) {
                                                echo '<img src="' . htmlspecialchars($msg['media_url']) . '" alt="Imagen">';
                                            }
                                            if (!empty($msg['message']) && $msg['message'] != 'Imagen recibida') {
                                                echo '<div>' . htmlspecialchars($msg['message']) . '</div>';
                                            }
                                            break;
                                            
                                        case 'video':
                                            if (!empty($msg['media_url'])) {
                                                echo '<video controls><source src="' . htmlspecialchars($msg['media_url']) . '" type="video/mp4"></video>';
                                            }
                                            if (!empty($msg['message']) && $msg['message'] != 'Video recibido') {
                                                echo '<div>' . htmlspecialchars($msg['message']) . '</div>';
                                            }
                                            break;
                                            
                                        case 'audio':
                                            if (!empty($msg['media_url'])) {
                                                echo '<audio controls><source src="' . htmlspecialchars($msg['media_url']) . '" type="audio/ogg"></audio>';
                                            } else {
                                                echo 'Nota de voz';
                                            }
                                            break;
                                            
                                        case 'document':
                                            if (!empty($msg['media_url'])) {
                                                echo '<a href="' . htmlspecialchars($msg['media_url']) . '" target="_blank">';
                                                echo '<i class="fas fa-file-alt"></i> ' . htmlspecialchars($msg['message']);
                                                echo '</a>';
                                            } else {
                                                echo htmlspecialchars($msg['message']);
                                            }
                                            break;
                                            
                                        case 'location':
                                            echo '<div class="location-preview"><i class="fas fa-map-marker-alt"></i></div>';
                                            echo htmlspecialchars($msg['message']);
                                            break;
                                            
                                        case 'reaction':
                                            // Extraer el emoji de additional_info
                                            $additionalInfo = json_decode($msg['additional_info'], true);
                                            $emoji = $additionalInfo['emoji'] ?? '👍';
                                            echo '<div style="font-size: 24px; text-align: center;">' . $emoji . '</div>';
                                            break;
                                            
                                        default:
                                            echo nl2br(htmlspecialchars($msg['message']));
                                            break;
                                    }
                                    ?>
                                </div>
                                <div class="message-info">
                                    <?php echo formatearFecha($msg['timestamp']); ?>
                                    <?php if ($msg['direction'] == 'enviado'): ?>
                                        <i class="fas fa-check-double" style="margin-left: 5px;"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="no-chat-selected">
                    <div style="text-align: center; margin-top: 100px; color: #888;">
                        <i class="fas fa-comments" style="font-size: 80px; margin-bottom: 20px;"></i>
                        <h2>Selecciona una conversación para ver los mensajes</h2>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            // Hacer scroll hacia abajo al cargar los mensajes
            var messagesContainer = document.getElementById('messagesContainer');
            if (messagesContainer) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
            
            // Manejar clic en conversación
            $('.conversation-item').on('click', function() {
                var phoneNumber = $(this).data('phone');
                window.location.href = '?telefono=' + phoneNumber;
            });
            
            // Actualizar mensajes cada 30 segundos si hay una conversación abierta
            <?php if (!empty($telefonoSeleccionado)): ?>
            setInterval(function() {
                $.ajax({
                    url: 'get_messages.php',
                    type: 'GET',
                    data: { 
                        telefono: '<?php echo $telefonoSeleccionado; ?>',
                        last_id: $('.message:last').data('id') || 0
                    },
                    success: function(data) {
                        if (data.length > 0) {
                            // Agregar nuevos mensajes y hacer scroll
                            $('#messagesContainer').append(data);
                            messagesContainer.scrollTop = messagesContainer.scrollHeight;
                        }
                    }
                });
            }, 3000);
            <?php endif; ?>
        });
    </script>
</body>
</html>