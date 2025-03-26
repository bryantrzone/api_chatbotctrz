<?php

require 'config.php'; 

$PHONE_NUMBERID = $config['PHONE_NUMBERID'];
$VERIFY_TOKEN = $config['VERIFY_TOKEN'];
$ACCESS_TOKEN = $config['ACCESS_TOKEN'];
$API_URL = "https://graph.facebook.com/v22.0/$PHONE_NUMBERID/messages";

// Recibir evento
$input = json_decode(file_get_contents("php://input"), true);
file_put_contents("whatsapp_log.txt", json_encode($input, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

// Verificación webhook de Facebook
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_mode'])) {
    if ($_GET['hub_verify_token'] === $VERIFY_TOKEN) {
        echo $_GET['hub_challenge'];
    } else {
        echo "Token inválido";
    }
    exit;
}

// Procesar mensaje entrante
if (isset($input['entry'][0]['changes'][0]['value']['messages'][0])) {
    $messageData = $input['entry'][0]['changes'][0]['value']['messages'][0];
    $phone = $input['entry'][0]['changes'][0]['value']['messages'][0]['from'];
    $text = $messageData['text']['body'] ?? '';

    // Buscar o crear usuario
    $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $stmt = $pdo->prepare("INSERT INTO users (phone, name) VALUES (?, '')");
        $stmt->execute([$phone]);
        $userId = $pdo->lastInsertId();
    } else {
        $userId = $user['id'];
    }

    // Obtener pregunta actual
    $stmt = $pdo->prepare("SELECT * FROM user_flow_history WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$userId]);
    $history = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$history) {
        // No tiene historial → mostrar pregunta inicial
        $stmt = $pdo->query("SELECT * FROM questions WHERE is_initial = 1 LIMIT 1");
        $initialQuestion = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($initialQuestion) {
            $stmt = $pdo->prepare("INSERT INTO user_flow_history (user_id, current_question_id, status) VALUES (?, ?, 'active')");
            $stmt->execute([$userId, $initialQuestion['id']]);

            sendQuestion($phone, $initialQuestion, $pdo, $API_URL, $ACCESS_TOKEN);
        }

        exit;
    }

    $questionId = $history['current_question_id'];

    // Guardar respuesta del usuario
    $stmt = $pdo->prepare("INSERT INTO user_responses (user_id, question_id, answer_text) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $questionId, $text]);

    // Buscar siguiente paso basado en la respuesta
    $stmt = $pdo->prepare("SELECT * FROM answers WHERE question_id = ?");
    $stmt->execute([$questionId]);
    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $nextQuestionId = null;
    $agentId = null;

    foreach ($options as $opt) {
        if (stripos($text, $opt['answer_text']) !== false) {
            $nextQuestionId = $opt['next_question_id'];
            $agentId = $opt['agent_id'];
            break;
        }
    }

    if ($agentId) {
        $stmt = $pdo->prepare("SELECT name FROM agents WHERE id = ?");
        $stmt->execute([$agentId]);
        $agent = $stmt->fetchColumn();

        sendText($phone, "✅ Te asignaré con $agent, en breve te contactará.", $API_URL, $ACCESS_TOKEN);
        $stmt = $pdo->prepare("UPDATE user_flow_history SET status = 'finished' WHERE user_id = ?");
        $stmt->execute([$userId]);
        exit;
    }

    if ($nextQuestionId) {
        $stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ?");
        $stmt->execute([$nextQuestionId]);
        $nextQuestion = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("UPDATE user_flow_history SET current_question_id = ? WHERE user_id = ?");
        $stmt->execute([$nextQuestionId, $userId]);

        sendQuestion($phone, $nextQuestion, $pdo, $API_URL, $ACCESS_TOKEN);
    } else {
        sendText($phone, "🤔 No entendí tu respuesta. Intenta de nuevo.", $API_URL, $ACCESS_TOKEN);
    }
}

// Función para enviar texto
function sendText($to, $message, $API_URL, $ACCESS_TOKEN) {
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => ['body' => $message]
    ];
    sendRequest($payload, $API_URL, $ACCESS_TOKEN);
}

// Función para enviar pregunta con botones o lista
function sendQuestion($to, $question, $pdo, $API_URL, $ACCESS_TOKEN) {
    $stmt = $pdo->prepare("SELECT * FROM answers WHERE question_id = ?");
    $stmt->execute([$question['id']]);
    $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($question['type'] === 'option' && count($answers) <= 3) {
        // Enviar como botones
        $buttons = array_map(function($ans) {
            return ['type' => 'reply', 'reply' => ['id' => $ans['id'], 'title' => $ans['answer_text']]];
        }, $answers);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $question['question_text']],
                'action' => ['buttons' => $buttons]
            ]
        ];
    } elseif ($question['type'] === 'list') {
        // Enviar como lista
        $rows = array_map(function($ans) {
            return ['id' => $ans['id'], 'title' => $ans['answer_text']];
        }, $answers);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $question['question_text']],
                'action' => [
                    'button' => 'Seleccionar',
                    'sections' => [[
                        'title' => 'Opciones',
                        'rows' => $rows
                    ]]
                ]
            ]
        ];
    } else {
        // Enviar solo como texto
        sendText($to, $question['question_text'], $API_URL, $ACCESS_TOKEN);
        return;
    }

    sendRequest($payload, $API_URL, $ACCESS_TOKEN);
}

// Enviar petición a la API de WhatsApp
function sendRequest($payload, $API_URL, $ACCESS_TOKEN) {
    $ch = curl_init($API_URL);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $ACCESS_TOKEN,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    file_put_contents("log_respuestas.txt", print_r($response, true), FILE_APPEND);
    curl_close($ch);
}
