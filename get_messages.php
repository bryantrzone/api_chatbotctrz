<?php
// Incluir configuración de base de datos
require_once "db.php";

// Verificar si se proporcionó un número de teléfono
if (!isset($_GET['telefono']) || empty($_GET['telefono'])) {
    http_response_code(400);
    echo "Falta el número de teléfono";
    exit;
}

$telefono = $_GET['telefono'];
$lastId = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

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

// Consulta para obtener mensajes nuevos desde el último ID
$query = "
    SELECT 
        id, message, message_type, direction, timestamp, 
        media_url, media_id, additional_info, wamid, context_wamid
    FROM messages 
    WHERE phone_number = ? AND id > ?
    ORDER BY timestamp ASC
";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute([$telefono, $lastId]);
    $mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // No hay mensajes nuevos
    if (empty($mensajes)) {
        echo "";
        exit;
    }
    
    // Generar HTML para los nuevos mensajes
    $output = '';
    foreach ($mensajes as $msg) {
        $output .= '<div class="message ' . ($msg['direction'] == 'enviado' ? 'sent' : 'received') . ' ' . $msg['message_type'] . '" data-id="' . $msg['id'] . '">';
        $output .= '<div class="message-content">';
        
        switch ($msg['message_type']) {
            case 'text':
            case 'interactive':
            case 'button':
            case 'template':
                $output .= nl2br(htmlspecialchars($msg['message']));
                break;
                
            case 'image':
                if (!empty($msg['media_url'])) {
                    $output .= '<img src="' . htmlspecialchars($msg['media_url']) . '" alt="Imagen">';
                }
                if (!empty($msg['message']) && $msg['message'] != 'Imagen recibida') {
                    $output .= '<div>' . htmlspecialchars($msg['message']) . '</div>';
                }
                break;
                
            case 'video':
                if (!empty($msg['media_url'])) {
                    $output .= '<video controls><source src="' . htmlspecialchars($msg['media_url']) . '" type="video/mp4"></video>';
                }
                if (!empty($msg['message']) && $msg['message'] != 'Video recibido') {
                    $output .= '<div>' . htmlspecialchars($msg['message']) . '</div>';
                }
                break;
                
            case 'audio':
                if (!empty($msg['media_url'])) {
                    $output .= '<audio controls><source src="' . htmlspecialchars($msg['media_url']) . '" type="audio/ogg"></audio>';
                } else {
                    $output .= 'Nota de voz';
                }
                break;
                
            case 'document':
                if (!empty($msg['media_url'])) {
                    $output .= '<a href="' . htmlspecialchars($msg['media_url']) . '" target="_blank">';
                    $output .= '<i class="fas fa-file-alt"></i> ' . htmlspecialchars($msg['message']);
                    $output .= '</a>';
                } else {
                    $output .= htmlspecialchars($msg['message']);
                }
                break;
                
            case 'location':
                $output .= '<div class="location-preview"><i class="fas fa-map-marker-alt"></i></div>';
                $output .= htmlspecialchars($msg['message']);
                break;
                
            case 'reaction':
                // Extraer el emoji de additional_info
                $additionalInfo = json_decode($msg['additional_info'], true);
                $emoji = $additionalInfo['emoji'] ?? '👍';
                $output .= '<div style="font-size: 24px; text-align: center;">' . $emoji . '</div>';
                break;
                
            default:
                $output .= nl2br(htmlspecialchars($msg['message']));
                break;
        }
        
        $output .= '</div>';
        $output .= '<div class="message-info">';
        $output .= formatearFecha($msg['timestamp']);
        
        if ($msg['direction'] == 'enviado') {
            $output .= '<i class="fas fa-check-double" style="margin-left: 5px;"></i>';
        }
        
        $output .= '</div>';
        $output .= '</div>';
    }
    
    echo $output;
    
} catch (PDOException $e) {
    error_log("Error al obtener mensajes: " . $e->getMessage());
    http_response_code(500);
    echo "Error al obtener mensajes";
}