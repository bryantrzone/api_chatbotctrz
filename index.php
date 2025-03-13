<?php
header("Content-Type: application/json");
require 'config.php';
require 'jwt_helper.php';

$request_method = $_SERVER["REQUEST_METHOD"];
$request_uri = explode("/", trim($_SERVER["REQUEST_URI"], "/"));

// var_dump($request_uri);

if ($request_uri[1] == "vacantes" && $request_method == "GET") {
    if (!isset($request_uri[1])) {
        echo json_encode(["error" => "Debe especificar un área"]);
        exit;
    }

    // var_dump($request_uri[1]);

    $area = urldecode($request_uri[2]);
    getVacantesPorArea($area);
} elseif ($request_uri[1] == "contactar" && $request_method == "POST") {
    $data = json_decode(file_get_contents("php://input"), true);
    contactarAsesor($data);
} else {
    echo json_encode(["error" => "Ruta no encontrada"]);
    http_response_code(404);
}

function getVacantesPorArea($area) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM vacantes WHERE area = ? AND status = 'activo'");
    $stmt->execute([$area]);
    $vacantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(["vacantes" => $vacantes]);
}


function contactarAsesor($data) {
    if (!isset($data["nombre"]) || !isset($data["email"]) || !isset($data["area"])) {
        echo json_encode(["error" => "Faltan datos"]);
        return;
    }

    // Simulación de envío de mensaje a un asesor
    $mensaje = "Nueva solicitud de contacto:\n";
    $mensaje .= "Nombre: " . $data["nombre"] . "\n";
    $mensaje .= "Email: " . $data["email"] . "\n";
    $mensaje .= "Área de interés: " . $data["area"] . "\n";

    // Aquí podrías enviar un correo o integrarlo con Freshchat
    file_put_contents("contactos.log", $mensaje . "\n", FILE_APPEND);
    
    echo json_encode(["mensaje" => "Solicitud enviada a un asesor"]);
}
?>
