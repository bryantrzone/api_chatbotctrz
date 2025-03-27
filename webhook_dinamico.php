<?php

require 'config.php'; 

$PHONE_NUMBERID = $config['PHONE_NUMBERID'];
$VERIFY_TOKEN   = $config['VERIFY_TOKEN'];
$ACCESS_TOKEN   = $config['ACCESS_TOKEN'];
$API_URL        = "https://graph.facebook.com/v18.0/$PHONE_NUMBERID/messages";

function logDebug($mensaje) {
    file_put_contents("whatsapp_log_dinamico.txt", date('Y-m-d H:i:s') . " | $mensaje\n", FILE_APPEND);
}

// $input = json_decode(file_get_contents("php://input"), true);
$input = json_decode(file_get_contents("simulacion_mensaje.json"), true);

// header('Content-Type: application/json; charset=utf-8');


// var_dump($input);


file_put_contents("whatsapp_log.txt", "🔔 Webhook recibido: " . json_encode($input), FILE_APPEND);

// Verificación webhook de Facebook
// if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_mode'])) {
//     if ($_GET['hub_verify_token'] === $VERIFY_TOKEN) {
//         echo $_GET['hub_challenge'];
//     } else {
//         echo "Token inválido";
//     }
//     exit;
// }

if (isset($input['entry'][0]['changes'][0]['value']['messages'][0])) {
    $messageData = $input['entry'][0]['changes'][0]['value']['messages'][0];
    $phone = $messageData['from'];

    // Eliminar el '1' después del 52 si es número mexicano
    if (preg_match('/^521(\d{10})$/', $phone, $matches)) {
        $phone = '52' . $matches[1];
    }

    $text = $messageData['text']['body'] ?? '';
    logDebug("📥 Mensaje de $phone: '$text'");

    // echo $text;

    // Buscar o crear usuario
    $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // var_dump($user);

    if (!$user) {
        $stmt = $pdo->prepare("INSERT INTO users (phone, name) VALUES (?, '')");
        $stmt->execute([$phone]);
        $userId = $pdo->lastInsertId();
        logDebug("👤 Nuevo usuario creado con ID $userId");
    } else {
        $userId = $user['id'];
        logDebug("👤 Usuario existente con ID $userId");
    }


    // echo $userId;


    $stmt = $pdo->prepare("SELECT * FROM user_flow_history WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$userId]);
    $history = $stmt->fetch(PDO::FETCH_ASSOC);

    

    if (!$history) {

        // var_dump($history);    

        $stmt = $pdo->query("SELECT * FROM questions WHERE is_initial = 1 LIMIT 1");
        $initialQuestion = $stmt->fetch(PDO::FETCH_ASSOC);

        // var_dump($initialQuestion);

        if ($initialQuestion) {
            $stmt = $pdo->prepare("INSERT INTO user_flow_history (user_id, current_question_id, status) VALUES (?, ?, 'active')");
            $stmt->execute([$userId, $initialQuestion['id']]);
            logDebug("🚀 Enviando pregunta inicial ID {$initialQuestion['id']} a $phone");
            sendQuestion($phone, $initialQuestion, $pdo, $API_URL, $ACCESS_TOKEN);
        }
        exit;
    }

    // var_dump($history);

    $questionId = $history['current_question_id'];

    // echo $questionId;

    $stmt = $pdo->prepare("INSERT INTO user_responses (user_id, question_id, answer_text) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $questionId, $text]);
    logDebug("💾 Respuesta guardada: Usuario $userId respondió '$text' a la pregunta $questionId");



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
        logDebug("🤝 Usuario $userId asignado al agente ID $agentId ($agent)");

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
        logDebug("➡️ Usuario $userId avanza a la pregunta ID $nextQuestionId");

        sendQuestion($phone, $nextQuestion, $pdo, $API_URL, $ACCESS_TOKEN);
    } else {
        logDebug("❌ Respuesta no reconocida: '$text'");
        sendText($phone, "🤔 No entendí tu respuesta. Intenta de nuevo.", $API_URL, $ACCESS_TOKEN);
    }
}

function sendText($to, $message, $API_URL, $ACCESS_TOKEN) {
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => ['body' => $message]
    ];
    sendRequest($payload, $API_URL, $ACCESS_TOKEN);
}

function sendQuestion($to, $question, $pdo, $API_URL, $ACCESS_TOKEN) {
    $stmt = $pdo->prepare("SELECT * FROM answers WHERE question_id = ?");
    $stmt->execute([$question['id']]);
    $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($question['type'] === 'option' && count($answers) <= 3) {
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
        sendText($to, $question['question_text'], $API_URL, $ACCESS_TOKEN);
        return;
    }

    sendRequest($payload, $API_URL, $ACCESS_TOKEN);
}

function sendRequest($payload, $API_URL, $ACCESS_TOKEN) {
    $ch = curl_init($API_URL);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $ACCESS_TOKEN,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    logDebug("📤 Respuesta enviada: $response");
    curl_close($ch);
}
