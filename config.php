<?php
$host = "localhost";
$dbname = "u106289951_bot_api";
$username = "u106289951_bot_api"; // Cambia si usas otro usuario
$password = "5Awc>Iv?vM"; // Cambia si usas otro password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error en la conexión: " . $e->getMessage());
}
?>
