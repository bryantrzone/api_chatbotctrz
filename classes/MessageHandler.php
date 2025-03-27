<?php
/**
 * MessageHandler.php
 * 
 * Clase que gestiona el envío y recepción de mensajes a la API de WhatsApp Cloud
 */
class MessageHandler {
    private $config;
    private $logger;
    private $apiUrl;
    
    public function __construct($config, $logger) {
        $this->config = $config;
        $this->logger = $logger;
        $this->apiUrl = "https://graph.facebook.com/v17.0/{$config['PHONE_NUMBERID']}/messages";
    }
    
    /**
     * Verifica si el mensaje recibido es válido y tiene la estructura esperada
     */
    public function isValidMessage($input) {
        if (!isset($input['entry']) ||
            !isset($input['entry'][0]['changes']) ||
            !isset($input['entry'][0]['changes'][0]['value']) ||
            !isset($input['entry'][0]['changes'][0]['value']['messages']) ||
            !isset($input['entry'][0]['changes'][0]['value']['messages'][0]['type'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Extrae los datos importantes del mensaje recibido
     */
    public function extractMessageData($input) {
        $message = $input['entry'][0]['changes'][0]['value']['messages'][0];
        $messageType = $message['type'];
        $phone = $message['from'];
        $messageId = $message['id'] ?? null;
        $username = $input['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? null;
        
        $data = [
            'phone' => $this->formatPhone($phone),
            'type' => $messageType,
            'message_id' => $messageId,
            'username' => $username,
            'content' => '',
            'original' => ''
        ];
        
        // Extraer el contenido según el tipo de mensaje
        switch ($messageType) {
            case 'text':
                $data['content'] = strtolower(trim($message['text']['body']));
                $data['original'] = $message['text']['body'];
                break;
                
            case 'interactive':
                if (isset($message['interactive']['type']) && $message['interactive']['type'] === 'list_reply') {
                    $data['content'] = strtolower(trim($message['interactive']['list_reply']['id']));
                    $data['original'] = $message['interactive']['list_reply']['title'] ?? '';
                    $data['interactive_type'] = 'list';
                } 
                elseif (isset($message['interactive']['type']) && $message['interactive']['type'] === 'button_reply') {
                    $data['content'] = strtolower(trim($message['interactive']['button_reply']['id']));
                    $data['original'] = $message['interactive']['button_reply']['title'] ?? '';
                    $data['interactive_type'] = 'button';
                }
                break;
                
            case 'document':
                $data['content'] = 'document';
                $data['document'] = [
                    'id' => $message['document']['id'],
                    'filename' => $message['document']['filename'] ?? null,
                    'mime_type' => $message['document']['mime_type'] ?? null
                ];
                break;
                
            case 'image':
                $data['content'] = 'image';
                $data['image'] = [
                    'id' => $message['image']['id'],
                    'mime_type' => $message['image']['mime_type'] ?? 'image/jpeg',
                    'caption' => $message['image']['caption'] ?? null
                ];
                break;
                
            case 'video':
                $data['content'] = 'video';
                $data['video'] = [
                    'id' => $message['video']['id'],
                    'mime_type' => $message['video']['mime_type'] ?? 'video/mp4',
                    'caption' => $message['video']['caption'] ?? null
                ];
                break;
                
            case 'audio':
                $data['content'] = 'audio';
                $data['audio'] = [
                    'id' => $message['audio']['id'],
                    'mime_type' => $message['audio']['mime_type'] ?? 'audio/mpeg'
                ];
                break;
                
            case 'location':
                $data['content'] = 'location';
                $data['location'] = [
                    'latitude' => $message['location']['latitude'],
                    'longitude' => $message['location']['longitude'],
                    'name' => $message['location']['name'] ?? null,
                    'address' => $message['location']['address'] ?? null
                ];
                break;
                
            case 'contacts':
                $data['content'] = 'contacts';
                $data['contacts'] = $message['contacts'];
                break;
        }
        
        return $data;
    }
    
    /**
     * Formatea correctamente un número de teléfono
     */
    private function formatPhone($phone) {
        // Asegurarse de que el formato es consistente (52xxxxxxxxxx)
        if (preg_match('/^521(\d{10})$/', $phone, $matches)) {
            return "52" . $matches[1]; // Elimina el "1" después del código de país
        }
        
        return $phone;
    }
    
    /**
     * Envía una solicitud a la API de WhatsApp
     */
    private function sendRequest($payload) {
        $this->logger->debug("Enviando a WhatsApp API: " . json_encode($payload));
        
        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->config['ACCESS_TOKEN'],
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logger->error("Error en solicitud cURL: $error");
            return false;
        }
        
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $this->logger->debug("Respuesta de WhatsApp: $response");
            return json_decode($response, true);
        } else {
            $this->logger->error("Error en API de WhatsApp: HTTP $httpCode - $response");
            return false;
        }
    }
    
    /**
     * Envía un mensaje de texto simple
     */
    public function sendTextMessage($phone, $message) {
        $phone = $this->formatPhone($phone);
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $phone,
            'type' => 'text',
            'text' => [
                'body' => $message
            ]
        ];
        
        return $this->sendRequest($payload);
    }
    
    /**
     * Envía un mensaje con botones
     */
    public function sendButtonsMessage($phone, $bodyText, $buttons) {
        $phone = $this->formatPhone($phone);
        
        // Transformar los botones al formato requerido por la API
        $apiButtons = [];
        foreach ($buttons as $button) {
            $apiButtons[] = [
                'type' => 'reply',
                'reply' => [
                    'id' => $button['id'],
                    'title' => $button['title']
                ]
            ];
        }
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $phone,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => [
                    'text' => $bodyText
                ],
                'action' => [
                    'buttons' => $apiButtons
                ]
            ]
        ];
        
        return $this->sendRequest($payload);
    }
    
    /**
     * Envía un mensaje con lista de opciones
     */
    public function sendListMessage($phone, $bodyText, $buttonText, $sections) {
        $phone = $this->formatPhone($phone);
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $phone,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => [
                    'text' => $bodyText
                ],
                'action' => [
                    'button' => $buttonText,
                    'sections' => $sections
                ]
            ]
        ];
        
        return $this->sendRequest($payload);
    }
    
    /**
     * Envía una imagen por URL
     */
    public function sendImageMessage($phone, $imageUrl, $caption = null) {
        $phone = $this->formatPhone($phone);
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $phone,
            'type' => 'image',
            'image' => [
                'link' => $imageUrl
            ]
        ];
        
        if ($caption) {
            $payload['image']['caption'] = $caption;
        }
        
        return $this->sendRequest($payload);
    }
    
    /**
     * Envía un documento por URL
     */
    public function sendDocumentMessage($phone, $documentUrl, $filename = null, $caption = null) {
        $phone = $this->formatPhone($phone);
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $phone,
            'type' => 'document',
            'document' => [
                'link' => $documentUrl
            ]
        ];
        
        if ($filename) {
            $payload['document']['filename'] = $filename;
        }
        
        if ($caption) {
            $payload['document']['caption'] = $caption;
        }
        
        return $this->sendRequest($payload);
    }
    
    /**
     * Envía un video por URL
     */
    public function sendVideoMessage($phone, $videoUrl, $caption = null) {
        $phone = $this->formatPhone($phone);
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $phone,
            'type' => 'video',
            'video' => [
                'link' => $videoUrl
            ]
        ];
        
        if ($caption) {
            $payload['video']['caption'] = $caption;
        }
        
        return $this->sendRequest($payload);
    }
    
    /**
     * Envía un mensaje de ubicación
     */
    public function sendLocationMessage($phone, $latitude, $longitude, $name = null, $address = null) {
        $phone = $this->formatPhone($phone);
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $phone,
            'type' => 'location',
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude
            ]
        ];
        
        if ($name) {
            $payload['location']['name'] = $name;
        }
        
        if ($address) {
            $payload['location']['address'] = $address;
        }
        
        return $this->sendRequest($payload);
    }
    
    /**
     * Descarga un archivo multimedia de WhatsApp
     */
    public function downloadMedia($mediaId) {
        $this->logger->debug("Descargando media ID: $mediaId");
        
        // Paso 1: Obtener la URL del archivo
        $mediaUrl = "https://graph.facebook.com/v17.0/{$mediaId}";
        
        $ch = curl_init($mediaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->config['ACCESS_TOKEN']
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch) || $httpCode != 200) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logger->error("Error al obtener URL del media: $error, HTTP: $httpCode");
            return false;
        }
        
        curl_close($ch);
        
        $mediaData = json_decode($response, true);
        if (!isset($mediaData['url'])) {
            $this->logger->error("URL de descarga no disponible en respuesta de WhatsApp");
            return false;
        }
        
        // Paso 2: Descargar el archivo usando la URL obtenida
        $downloadUrl = $mediaData['url'];
        $this->logger->debug("URL de descarga: $downloadUrl");
        
        $ch = curl_init($downloadUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->config['ACCESS_TOKEN'],
            'User-Agent: WhatsApp-Bot/1.0'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $fileContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorMsg = curl_error($ch);
        
        curl_close($ch);
        
        if ($httpCode != 200 || !$fileContent) {
            $this->logger->error("Error al descargar archivo: $errorMsg, HTTP: $httpCode");
            return false;
        }
        
        // Verificar que no se descargó HTML o un error
        $contentStart = substr($fileContent, 0, 20);
        if (stripos($contentStart, '<!DOCTYPE') !== false || 
            stripos($contentStart, '<html') !== false || 
            stripos($contentStart, '{') === 0) {
            $this->logger->error("El contenido descargado parece ser HTML o JSON, no un archivo binario");
            return false;
        }
        
        $this->logger->debug("Archivo descargado correctamente: " . strlen($fileContent) . " bytes");
        
        return $fileContent;
    }
}