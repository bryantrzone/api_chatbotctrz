<?php
/**
 * FlowEngine.php
 * 
 * Clase que maneja toda la lógica de procesamiento de flujos dinámicos
 */
class FlowEngine {
    private $db;
    private $messageHandler;
    private $logger;
    private $variableProcessor;
    
    public function __construct(Database $db, MessageHandler $messageHandler, Logger $logger) {
        $this->db = $db;
        $this->messageHandler = $messageHandler;
        $this->logger = $logger;
        $this->variableProcessor = new VariableProcessor($db, $logger);
    }
    
    /**
     * Obtiene o crea una sesión para el usuario
     */
    public function getOrCreateSession($phone) {
        $this->logger->debug("Buscando sesión para: $phone");
        
        // Buscar sesión existente
        $session = $this->db->query(
            "SELECT * FROM sesiones WHERE telefono = ? AND estado IN ('activo', 'esperando') ORDER BY id DESC LIMIT 1",
            [$phone]
        )->fetch();
        
        // Si no existe, crear nueva sesión
        if (!$session) {
            $this->logger->info("Creando nueva sesión para: $phone");
            
            // Buscar flujo por defecto para iniciar
            $defaultFlow = $this->db->query(
                "SELECT id FROM flujos WHERE es_default = 1 AND activo = 1 LIMIT 1"
            )->fetch();
            
            if (!$defaultFlow) {
                throw new Exception("No hay flujo por defecto configurado");
            }
            
            // Buscar nodo inicial del flujo
            $initialNode = $this->db->query(
                "SELECT id FROM nodos WHERE flujo_id = ? AND es_inicial = 1 LIMIT 1",
                [$defaultFlow['id']]
            )->fetch();
            
            if (!$initialNode) {
                throw new Exception("El flujo por defecto no tiene nodo inicial");
            }
            
            // Crear nueva sesión
            $sessionId = $this->db->insert(
                "INSERT INTO sesiones (telefono, flujo_actual_id, nodo_actual_id, estado, created_at, updated_at) 
                 VALUES (?, ?, ?, 'activo', NOW(), NOW())",
                [$phone, $defaultFlow['id'], $initialNode['id']]
            );
            
            $session = $this->db->query(
                "SELECT * FROM sesiones WHERE id = ?",
                [$sessionId]
            )->fetch();
        }
        
        return $session;
    }
    
    /**
     * Actualiza los datos de la sesión con el mensaje recibido
     */
    public function updateSessionData($session, $messageData) {
        $this->logger->debug("Actualizando datos de sesión: {$session['id']}");
        
        // Actualizar los campos de la sesión
        $this->db->update(
            "UPDATE sesiones SET 
             ultimo_mensaje_recibido = ?,
             fecha_ultimo_mensaje = NOW(),
             tiempo_inactividad = 0,
             nombre_usuario = COALESCE(?, nombre_usuario),
             updated_at = NOW()
             WHERE id = ?",
            [
                $messageData['content'],
                $messageData['username'] ?? null,
                $session['id']
            ]
        );
        
        // Guardar mensaje en el historial
        $this->db->insert(
            "INSERT INTO mensajes (sesion_id, telefono, flujo_id, nodo_id, tipo, tipo_contenido, contenido, whatsapp_message_id, created_at)
             VALUES (?, ?, ?, ?, 'recibido', ?, ?, ?, NOW())",
            [
                $session['id'],
                $messageData['phone'],
                $session['flujo_actual_id'],
                $session['nodo_actual_id'],
                $messageData['type'],
                $messageData['content'],
                $messageData['message_id'] ?? null
            ]
        );
        
        // Recargar la sesión actualizada
        return $this->db->query(
            "SELECT * FROM sesiones WHERE id = ?",
            [$session['id']]
        )->fetch();
    }
    
    /**
     * Procesa un mensaje según el flujo actual
     */
    public function processMessage($session, $messageData) {
        $this->logger->info("Procesando mensaje para sesión: {$session['id']}");
        
        // Obtener el nodo actual
        $currentNode = $this->db->query(
            "SELECT * FROM nodos WHERE id = ?",
            [$session['nodo_actual_id']]
        )->fetch();
        
        if (!$currentNode) {
            throw new Exception("No se encontró el nodo actual: {$session['nodo_actual_id']}");
        }
        
        // Determinar el siguiente nodo basado en el mensaje y las transiciones
        $nextNode = $this->determineNextNode($currentNode, $messageData, $session);
        
        if (!$nextNode) {
            // Si no hay un nodo siguiente, buscamos la transición por defecto
            $defaultTransition = $this->db->query(
                "SELECT * FROM transiciones 
                 WHERE nodo_origen_id = ? AND es_default = 1 
                 ORDER BY orden DESC LIMIT 1",
                [$currentNode['id']]
            )->fetch();
            
            if ($defaultTransition) {
                $nextNode = $this->db->query(
                    "SELECT * FROM nodos WHERE id = ?",
                    [$defaultTransition['nodo_destino_id']]
                )->fetch();
            }
        }
        
        if (!$nextNode) {
            // Si aún no hay nodo siguiente, enviar mensaje de no comprensión
            $this->messageHandler->sendTextMessage(
                $messageData['phone'],
                "Lo siento, no he podido entender tu respuesta. Por favor, intenta de nuevo."
            );
            return;
        }
        
        // Actualizar la sesión con el nuevo nodo
        $this->db->update(
            "UPDATE sesiones SET 
             nodo_actual_id = ?,
             flujo_actual_id = ?,
             estado = ?,
             updated_at = NOW()
             WHERE id = ?",
            [
                $nextNode['id'],
                $nextNode['flujo_id'],
                $nextNode['tipo'] === 'pregunta' ? 'esperando' : 'activo',
                $session['id']
            ]
        );
        
        // Procesar el nodo actual
        $this->processNode($nextNode, $messageData['phone'], $session);
    }
    
    /**
     * Determina el siguiente nodo basado en el mensaje y las transiciones definidas
     */
    private function determineNextNode($currentNode, $messageData, $session) {
        $this->logger->debug("Determinando siguiente nodo para: {$currentNode['id']}");
        
        // Obtener todas las transiciones posibles para este nodo
        $transitions = $this->db->query(
            "SELECT * FROM transiciones 
             WHERE nodo_origen_id = ? AND es_default = 0
             ORDER BY orden DESC",
            [$currentNode['id']]
        )->fetchAll();
        
        foreach ($transitions as $transition) {
            // Si hay un valor esperado específico
            if (!empty($transition['valor_esperado'])) {
                $expectedValue = $this->variableProcessor->processTemplate(
                    $transition['valor_esperado'], 
                    $session['id'], 
                    $messageData['phone']
                );
                
                if (strtolower($messageData['content']) === strtolower($expectedValue)) {
                    return $this->db->query(
                        "SELECT * FROM nodos WHERE id = ?",
                        [$transition['nodo_destino_id']]
                    )->fetch();
                }
            }
            
            // Si hay una condición más compleja
            else if (!empty($transition['condicion'])) {
                // Procesar la condición (puede ser una regex o una expresión más compleja)
                if ($this->evaluateCondition($transition['condicion'], $messageData, $session)) {
                    return $this->db->query(
                        "SELECT * FROM nodos WHERE id = ?",
                        [$transition['nodo_destino_id']]
                    )->fetch();
                }
            }
        }
        
        return null;
    }
    
    /**
     * Evalúa una condición para determinar si una transición debe ser seguida
     */
    private function evaluateCondition($condition, $messageData, $session) {
        // Verificar si es una expresión regular
        if (substr($condition, 0, 1) === '/' && substr($condition, -1) === '/') {
            // Es una regex, intentar matchear
            return preg_match($condition, $messageData['content']) === 1;
        }
        
        // Podría ser una condición en formato JSON para lógica más compleja
        if (substr($condition, 0, 1) === '{') {
            $conditionData = json_decode($condition, true);
            
            if ($conditionData && isset($conditionData['type'])) {
                switch ($conditionData['type']) {
                    case 'contains':
                        return stripos($messageData['content'], $conditionData['value']) !== false;
                        
                    case 'starts_with':
                        return stripos($messageData['content'], $conditionData['value']) === 0;
                        
                    case 'ends_with':
                        return substr(strtolower($messageData['content']), -strlen($conditionData['value'])) === strtolower($conditionData['value']);
                        
                    case 'equals_any':
                        if (isset($conditionData['values']) && is_array($conditionData['values'])) {
                            foreach ($conditionData['values'] as $value) {
                                if (strtolower($messageData['content']) === strtolower($value)) {
                                    return true;
                                }
                            }
                        }
                        return false;
                        
                    case 'variable_equals':
                        if (isset($conditionData['variable']) && isset($conditionData['value'])) {
                            $variableValue = $this->variableProcessor->getVariable(
                                $conditionData['variable'],
                                $session['id'],
                                $messageData['phone']
                            );
                            return $variableValue == $conditionData['value'];
                        }
                        return false;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Procesa un nodo y envía la respuesta correspondiente
     */
    private function processNode($node, $phone, $session) {
        $this->logger->info("Procesando nodo: {$node['id']} de tipo: {$node['tipo']}");
        
        switch ($node['tipo']) {
            case 'mensaje':
                $this->sendNodeContent($node, $phone, $session);
                break;
                
            case 'pregunta':
                $this->sendNodeContent($node, $phone, $session);
                break;
                
            case 'decision':
                // Evaluar condiciones y moverse automáticamente al siguiente nodo
                $this->processDecisionNode($node, $phone, $session);
                break;
                
            case 'operacion':
                // Ejecutar script y luego ir al siguiente nodo
                $this->processOperationNode($node, $phone, $session);
                break;
                
            case 'redirect':
                // Redireccionar a otro flujo
                $this->processRedirectNode($node, $phone, $session);
                break;
                
            case 'finalizar':
                // Finalizar la conversación
                $this->processFinalizeNode($node, $phone, $session);
                break;
        }
    }
    
    /**
     * Envía el contenido de un nodo al usuario
     */
    private function sendNodeContent($node, $phone, $session) {
        // Obtener todos los contenidos de este nodo en orden
        $contents = $this->db->query(
            "SELECT * FROM nodo_contenido 
             WHERE nodo_id = ? 
             ORDER BY orden ASC",
            [$node['id']]
        )->fetchAll();
        
        if (empty($contents)) {
            $this->logger->warning("El nodo {$node['id']} no tiene contenido definido");
            return;
        }
        
        foreach ($contents as $content) {
            // Procesar cualquier variable en el contenido
            $processedContent = $this->variableProcessor->processTemplate(
                $content['contenido'],
                $session['id'],
                $phone
            );
            
            // Enviar según el tipo de contenido
            switch ($content['tipo_contenido']) {
                case 'texto':
                    $this->messageHandler->sendTextMessage($phone, $processedContent);
                    break;
                    
                case 'imagen':
                    $this->messageHandler->sendImageMessage($phone, $processedContent);
                    break;
                    
                case 'documento':
                    $data = json_decode($processedContent, true);
                    $this->messageHandler->sendDocumentMessage($phone, $data['url'], $data['filename'] ?? null, $data['caption'] ?? null);
                    break;
                    
                case 'video':
                    $data = json_decode($processedContent, true);
                    $this->messageHandler->sendVideoMessage($phone, $data['url'], $data['caption'] ?? null);
                    break;
                    
                case 'botones':
                    $buttonsData = json_decode($processedContent, true);
                    $this->messageHandler->sendButtonsMessage($phone, $buttonsData['body'], $buttonsData['buttons']);
                    break;
                    
                case 'lista':
                    $listData = json_decode($processedContent, true);
                    $this->messageHandler->sendListMessage($phone, $listData['body'], $listData['button'], $listData['sections']);
                    break;
            }
            
            // Guardar mensaje en el historial
            $this->db->insert(
                "INSERT INTO mensajes (sesion_id, telefono, flujo_id, nodo_id, tipo, tipo_contenido, contenido, created_at)
                 VALUES (?, ?, ?, ?, 'enviado', ?, ?, NOW())",
                [
                    $session['id'],
                    $phone,
                    $node['flujo_id'],
                    $node['id'],
                    $content['tipo_contenido'],
                    $processedContent
                ]
            );
            
            // Si hay que esperar entre mensajes
            if ($content['espera'] > 0) {
                sleep($content['espera']);
            }
        }
        
        // Si es un nodo final, continuar automáticamente al siguiente
        if ($node['es_final']) {
            $this->processFinalizeNode($node, $phone, $session);
        }
    }
    
    /**
     * Procesa un nodo de tipo "decisión"
     */
    private function processDecisionNode($node, $phone, $session) {
        $this->logger->debug("Procesando nodo de decisión: {$node['id']}");
        
        // Si tiene un script, ejecutarlo
        if (!empty($node['script'])) {
            $result = $this->executeScript($node['script'], $session, $phone);
            
            // Buscar transición basada en el resultado
            $transition = $this->db->query(
                "SELECT * FROM transiciones 
                 WHERE nodo_origen_id = ? AND valor_esperado = ?
                 LIMIT 1",
                [$node['id'], $result]
            )->fetch();
            
            if ($transition) {
                $nextNode = $this->db->query(
                    "SELECT * FROM nodos WHERE id = ?",
                    [$transition['nodo_destino_id']]
                )->fetch();
                
                if ($nextNode) {
                    // Actualizar la sesión
                    $this->db->update(
                        "UPDATE sesiones SET 
                         nodo_actual_id = ?,
                         updated_at = NOW()
                         WHERE id = ?",
                        [$nextNode['id'], $session['id']]
                    );
                    
                    // Procesar el siguiente nodo
                    $this->processNode($nextNode, $phone, $session);
                    return;
                }
            }
        }
        
        // Si no hay resultado o no hay transición, usar la transición por defecto
        $defaultTransition = $this->db->query(
            "SELECT * FROM transiciones 
             WHERE nodo_origen_id = ? AND es_default = 1
             LIMIT 1",
            [$node['id']]
        )->fetch();
        
        if ($defaultTransition) {
            $nextNode = $this->db->query(
                "SELECT * FROM nodos WHERE id = ?",
                [$defaultTransition['nodo_destino_id']]
            )->fetch();
            
            if ($nextNode) {
                // Actualizar la sesión
                $this->db->update(
                    "UPDATE sesiones SET 
                     nodo_actual_id = ?,
                     updated_at = NOW()
                     WHERE id = ?",
                    [$nextNode['id'], $session['id']]
                );
                
                // Procesar el siguiente nodo
                $this->processNode($nextNode, $phone, $session);
            }
        }
    }
    
    /**
     * Procesa un nodo de tipo "operación"
     */
    private function processOperationNode($node, $phone, $session) {
        $this->logger->debug("Procesando nodo de operación: {$node['id']}");
        
        // Ejecutar el script
        if (!empty($node['script'])) {
            $result = $this->executeScript($node['script'], $session, $phone);
            
            // Guardar el resultado como variable
            if ($result !== null) {
                $this->variableProcessor->setVariable(
                    'last_operation_result',
                    $result,
                    $session['id'],
                    $phone
                );
            }
        }
        
        // Buscar la transición por defecto para este nodo
        $defaultTransition = $this->db->query(
            "SELECT * FROM transiciones 
             WHERE nodo_origen_id = ? AND es_default = 1
             LIMIT 1",
            [$node['id']]
        )->fetch();
        
        if ($defaultTransition) {
            $nextNode = $this->db->query(
                "SELECT * FROM nodos WHERE id = ?",
                [$defaultTransition['nodo_destino_id']]
            )->fetch();
            
            if ($nextNode) {
                // Actualizar la sesión
                $this->db->update(
                    "UPDATE sesiones SET 
                     nodo_actual_id = ?,
                     updated_at = NOW()
                     WHERE id = ?",
                    [$nextNode['id'], $session['id']]
                );
                
                // Procesar el siguiente nodo
                $this->processNode($nextNode, $phone, $session);
            }
        }
    }
    
    /**
     * Procesa un nodo de tipo "redirección"
     */
    private function processRedirectNode($node, $phone, $session) {
        $this->logger->debug("Procesando nodo de redirección: {$node['id']}");
        
        // Obtener información de redirección de la base de datos
        // Aquí podría estar almacenado el flujo destino y el nodo destino
        $redirectData = json_decode($node['script'] ?? '{}', true);
        
        if (!isset($redirectData['flow_id']) || !isset($redirectData['node_id'])) {
            $this->logger->error("Datos de redirección incompletos en nodo: {$node['id']}");
            return;
        }
        
        // Verificar que el flujo y nodo destino existen
        $targetNode = $this->db->query(
            "SELECT n.* FROM nodos n
             JOIN flujos f ON n.flujo_id = f.id
             WHERE n.id = ? AND f.id = ? AND f.activo = 1",
            [$redirectData['node_id'], $redirectData['flow_id']]
        )->fetch();
        
        if (!$targetNode) {
            $this->logger->error("Flujo o nodo destino no encontrado: flow={$redirectData['flow_id']}, node={$redirectData['node_id']}");
            return;
        }
        
        // Actualizar la sesión con el nuevo flujo y nodo
        $this->db->update(
            "UPDATE sesiones SET 
             flujo_actual_id = ?,
             nodo_actual_id = ?,
             updated_at = NOW()
             WHERE id = ?",
            [$redirectData['flow_id'], $redirectData['node_id'], $session['id']]
        );
        
        // Procesar el nodo destino
        $this->processNode($targetNode, $phone, $session);
    }
    
    /**
     * Procesa un nodo de tipo "finalizar"
     */
    private function processFinalizeNode($node, $phone, $session) {
        $this->logger->debug("Procesando nodo de finalización: {$node['id']}");
        
        // Enviar el contenido final si lo hay
        $this->sendNodeContent($node, $phone, $session);
        
        // Verificar si se necesita transferir a un agente
        $transferData = json_decode($node['script'] ?? '{}', true);
        
        if (isset($transferData['transfer_to_agent']) && $transferData['transfer_to_agent']) {
            // Iniciar proceso de transferencia a agente
            $this->transferToAgent($session, $phone, $transferData['department_id'] ?? null);
        } else {
            // Finalizar la sesión
            $this->db->update(
                "UPDATE sesiones SET 
                 estado = 'finalizado',
                 updated_at = NOW()
                 WHERE id = ?",
                [$session['id']]
            );
            
            // Si hay un flujo de reinicio configurado, establecerlo
            if (isset($transferData['restart_flow_id'])) {
                $restartFlow = $this->db->query(
                    "SELECT id FROM flujos WHERE id = ? AND activo = 1",
                    [$transferData['restart_flow_id']]
                )->fetch();
                
                if ($restartFlow) {
                    // Buscar nodo inicial del flujo
                    $initialNode = $this->db->query(
                        "SELECT id FROM nodos WHERE flujo_id = ? AND es_inicial = 1 LIMIT 1",
                        [$restartFlow['id']]
                    )->fetch();
                    
                    if ($initialNode) {
                        // Crear nueva sesión
                        $this->db->insert(
                            "INSERT INTO sesiones (telefono, flujo_actual_id, nodo_actual_id, estado, created_at, updated_at) 
                             VALUES (?, ?, ?, 'activo', NOW(), NOW())",
                            [$phone, $restartFlow['id'], $initialNode['id']]
                        );
                    }
                }
            }
        }
    }
    
    /**
     * Transfiere una conversación a un agente humano
     */
    private function transferToAgent($session, $phone, $departmentId = null) {
        $this->logger->info("Transfiriendo conversación a agente: sesión={$session['id']}, departamento=$departmentId");
        
        // Actualizar estado de la sesión
        $this->db->update(
            "UPDATE sesiones SET 
             estado = 'transferido',
             updated_at = NOW()
             WHERE id = ?",
            [$session['id']]
        );
        
        // Encontrar un agente disponible
        $agentQuery = "SELECT a.id 
                       FROM agentes a
                       JOIN agente_departamento ad ON a.id = ad.agente_id
                       WHERE a.estado = 'disponible'";
        
        $params = [];
        
        if ($departmentId) {
            $agentQuery .= " AND ad.departamento_id = ?";
            $params[] = $departmentId;
        }
        
        $agentQuery .= " AND (
                           SELECT COUNT(*) FROM asignaciones 
                           WHERE agente_id = a.id AND estado IN ('asignado', 'en_chat')
                         ) < a.capacidad_max
                         ORDER BY (
                           SELECT COUNT(*) FROM asignaciones 
                           WHERE agente_id = a.id AND estado IN ('asignado', 'en_chat')
                         ) ASC
                         LIMIT 1";
        
        $agent = $this->db->query($agentQuery, $params)->fetch();
        
        if (!$agent) {
            $this->logger->warning("No hay agentes disponibles para transferir la sesión: {$session['id']}");
            
            // Notificar al usuario que no hay agentes disponibles
            $this->messageHandler->sendTextMessage(
                $phone,
                "En este momento no hay agentes disponibles. Tu consulta ha sido registrada y un agente te contactará lo antes posible."
            );
            
            return;
        }
        
        // Crear asignación
        $this->db->insert(
            "INSERT INTO asignaciones (sesion_id, agente_id, estado, fecha_asignacion, created_at, updated_at)
             VALUES (?, ?, 'asignado', NOW(), NOW(), NOW())",
            [$session['id'], $agent['id']]
        );
        
        // Notificar al usuario sobre la transferencia
        $this->messageHandler->sendTextMessage(
            $phone,
            "Tu conversación ha sido transferida a un agente. En breve continuarás la atención con uno de nuestros representantes."
        );
        
        // TODO: Notificar al agente sobre la nueva asignación
        // Esto podría ser mediante WebSockets, email, SMS, etc.
    }
    
    /**
     * Procesa mensajes de tipo multimedia (imágenes, documentos, etc.)
     */
    public function processMediaMessage($session, $messageData) {
        $this->logger->info("Procesando mensaje multimedia: {$messageData['type']} para sesión: {$session['id']}");
        
        // Obtener el nodo actual
        $currentNode = $this->db->query(
            "SELECT * FROM nodos WHERE id = ?",
            [$session['nodo_actual_id']]
        )->fetch();
        
        if (!$currentNode) {
            throw new Exception("No se encontró el nodo actual: {$session['nodo_actual_id']}");
        }
        
        // Verificar si el nodo actual está esperando un archivo
        $waitingForFile = $this->isWaitingForFile($currentNode, $messageData['type']);
        
        if ($waitingForFile) {
            // Procesar el archivo según la lógica específica del nodo
            $fileProcessed = $this->processFile($currentNode, $messageData, $session);
            
            if ($fileProcessed) {
                // Determinar el siguiente nodo después de procesar el archivo
                $nextNode = $this->getNextNodeAfterFileProcessing($currentNode, $session);
                
                if ($nextNode) {
                    // Actualizar la sesión
                    $this->db->update(
                        "UPDATE sesiones SET 
                         nodo_actual_id = ?,
                         updated_at = NOW()
                         WHERE id = ?",
                        [$nextNode['id'], $session['id']]
                    );
                    
                    // Procesar el siguiente nodo
                    $this->processNode($nextNode, $messageData['phone'], $session);
                }
            }
        } else {
            // Si no está esperando un archivo, manejar como un mensaje normal
            $this->processMessage($session, $messageData);
        }
    }
    
    /**
     * Verifica si un nodo está esperando un archivo
     */
    private function isWaitingForFile($node, $fileType) {
        // Comprobar si el nodo está configurado para esperar un archivo
        if ($node['tipo'] !== 'pregunta') {
            return false;
        }
        
        // Verificar en el script del nodo si espera un archivo
        $nodeConfig = json_decode($node['script'] ?? '{}', true);
        
        if (isset($nodeConfig['wait_for_file']) && $nodeConfig['wait_for_file']) {
            // Si espera cualquier tipo de archivo
            if (isset($nodeConfig['file_types']) && is_array($nodeConfig['file_types'])) {
                // Verificar si el tipo de archivo recibido está entre los aceptados
                return in_array($fileType, $nodeConfig['file_types']);
            }
            
            // Si no especifica tipos, aceptar cualquier archivo
            return true;
        }
        
        return false;
    }
    
    /**
     * Procesa un archivo recibido
     */
    private function processFile($node, $messageData, $session) {
        $this->logger->info("Procesando archivo en nodo: {$node['id']}");
        
        // Configuración del nodo
        $nodeConfig = json_decode($node['script'] ?? '{}', true);
        
        // Directorio para guardar archivos
        $uploadDir = $nodeConfig['upload_dir'] ?? 'uploads/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Obtener media_id y otros datos según el tipo de archivo
        $mediaId = null;
        $fileName = null;
        $mimeType = null;
        
        switch ($messageData['type']) {
            case 'document':
                $documentData = $messageData['document'] ?? [];
                $mediaId = $documentData['id'] ?? null;
                $fileName = $documentData['filename'] ?? null;
                $mimeType = $documentData['mime_type'] ?? null;
                break;
                
            case 'image':
                $imageData = $messageData['image'] ?? [];
                $mediaId = $imageData['id'] ?? null;
                $mimeType = $imageData['mime_type'] ?? null;
                $fileName = "image_" . time() . "." . $this->getExtensionFromMimeType($mimeType);
                break;
                
            case 'video':
                $videoData = $messageData['video'] ?? [];
                $mediaId = $videoData['id'] ?? null;
                $mimeType = $videoData['mime_type'] ?? null;
                $fileName = "video_" . time() . "." . $this->getExtensionFromMimeType($mimeType);
                break;
                
            case 'audio':
                $audioData = $messageData['audio'] ?? [];
                $mediaId = $audioData['id'] ?? null;
                $mimeType = $audioData['mime_type'] ?? null;
                $fileName = "audio_" . time() . "." . $this->getExtensionFromMimeType($mimeType);
                break;
        }
        
        if (!$mediaId) {
            $this->logger->error("No se pudo obtener el media_id del archivo");
            return false;
        }
        
        // Generar nombre de archivo único
        $uniqueFileName = uniqid(pathinfo($fileName, PATHINFO_FILENAME) . '_') . '.' . pathinfo($fileName, PATHINFO_EXTENSION);
        $filePath = $uploadDir . $uniqueFileName;
        
        // Descargar el archivo de WhatsApp
        $fileContent = $this->messageHandler->downloadMedia($mediaId);
        
        if (!$fileContent) {
            $this->logger->error("No se pudo descargar el archivo desde WhatsApp");
            return false;
        }
        
        // Guardar el archivo
        file_put_contents($filePath, $fileContent);
        chmod($filePath, 0644);
        
        $this->logger->info("Archivo guardado en: $filePath");
        
        // Guardar la ruta del archivo como variable
        $this->variableProcessor->setVariable(
            'last_uploaded_file',
            $filePath,
            $session['id'],
            $messageData['phone']
        );
        
        // Si hay un campo específico para guardar (como en el flujo de CV)
        if (isset($nodeConfig['save_to_field']) && isset($nodeConfig['save_to_table'])) {
            $field = $nodeConfig['save_to_field'];
            $table = $nodeConfig['save_to_table'];
            $idField = $nodeConfig['id_field'] ?? 'id';
            $idValue = $nodeConfig['id_value'] ?? null;
            
            // Si no hay valor explícito, intentar obtenerlo de variables
            if ($idValue === null) {
                $idValue = $this->variableProcessor->getVariable(
                    $nodeConfig['id_variable'] ?? 'current_record_id',
                    $session['id'],
                    $messageData['phone']
                );
            }
            
            if ($idValue) {
                // Actualizar el registro
                $this->db->update(
                    "UPDATE $table SET $field = ? WHERE $idField = ?",
                    [$filePath, $idValue]
                );
                
                $this->logger->info("Actualizado $table.$field = '$filePath' donde $idField = $idValue");
            }
        }
        
        // Ejecutar cualquier script personalizado
        if (isset($nodeConfig['after_upload_script'])) {
            $script = $nodeConfig['after_upload_script'];
            $script = str_replace('{{file_path}}', $filePath, $script);
            $script = str_replace('{{file_name}}', $fileName, $script);
            $script = str_replace('{{mime_type}}', $mimeType, $script);
            
            $this->executeScript($script, $session, $messageData['phone']);
        }
        
        return true;
    }
    
    /**
     * Determina el siguiente nodo después de procesar un archivo
     */
    private function getNextNodeAfterFileProcessing($currentNode, $session) {
        // Verificar si hay una transición específica para archivos
        $fileTransition = $this->db->query(
            "SELECT * FROM transiciones 
             WHERE nodo_origen_id = ? AND condicion LIKE '%file_processed%'
             ORDER BY orden DESC LIMIT 1",
            [$currentNode['id']]
        )->fetch();
        
        if ($fileTransition) {
            return $this->db->query(
                "SELECT * FROM nodos WHERE id = ?",
                [$fileTransition['nodo_destino_id']]
            )->fetch();
        }
        
        // Si no hay transición específica, usar la transición por defecto
        $defaultTransition = $this->db->query(
            "SELECT * FROM transiciones 
             WHERE nodo_origen_id = ? AND es_default = 1
             ORDER BY orden DESC LIMIT 1",
            [$currentNode['id']]
        )->fetch();
        
        if ($defaultTransition) {
            return $this->db->query(
                "SELECT * FROM nodos WHERE id = ?",
                [$defaultTransition['nodo_destino_id']]
            )->fetch();
        }
        
        return null;
    }
    
    /**
     * Ejecuta un script PHP de forma segura
     */
    private function executeScript($script, $session, $phone) {
        $this->logger->debug("Ejecutando script en sesión: {$session['id']}");
        
        // Crear contexto seguro para el script
        $db = $this->db;
        $logger = $this->logger;
        $variableProcessor = $this->variableProcessor;
        $sessionId = $session['id'];
        
        // Extraer variables disponibles para el script
        $userVars = [];
        $variables = $this->db->query(
            "SELECT uv.variable_id, v.nombre, uv.valor
             FROM usuario_variables uv
             JOIN variables v ON uv.variable_id = v.id
             WHERE uv.telefono = ?",
            [$phone]
        )->fetchAll();
        
        foreach ($variables as $var) {
            $userVars[$var['nombre']] = $var['valor'];
        }
        
        // Ejecutar el script en un contexto controlado
        try {
            return eval($script);
        } catch (Exception $e) {
            $this->logger->error("Error al ejecutar script: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtiene extensión de archivo desde MIME type
     */
    private function getExtensionFromMimeType($mimeType) {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'
        ];
        
        return $map[$mimeType] ?? 'bin';
    }
}