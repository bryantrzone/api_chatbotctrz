<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "botapi123"; // Cambia esto por una clave segura

function generate_jwt($user_data) {
    global $secret_key;
    $payload = [
        "iat" => time(),
        "exp" => time() + (60 * 60), // 1 hora
        "data" => $user_data
    ];
    return JWT::encode($payload, $secret_key, 'HS256');
}

function validate_jwt($jwt) {
    global $secret_key;
    try {
        $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
        return (array) $decoded->data;
    } catch (Exception $e) {
        return null;
    }
}
?>
