<?php
$host = "localhost";
$dbname = "u106289951_bot_apirh";
$username = "u106289951_bot_apirh";
$password = "=rW#TnB4";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    file_put_contents("error_log_sql.txt", date('Y-m-d H:i:s') . " | Error en conexión: " . $e->getMessage() . "\n", FILE_APPEND);
    // die("❌ Error en la conexión a la base de datos.");
}

// Cargar variables de configuración
$config = [];

try {
    $stmt = $pdo->query("SELECT nombre_variable, valor FROM whatsapp_config");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $config[$row['nombre_variable']] = $row['valor'];
    }
} catch (PDOException $e) {
    file_put_contents("error_log_sql.txt", date('Y-m-d H:i:s') . " | Error al leer whatsapp_config: " . $e->getMessage() . "\n", FILE_APPEND);
    // die("❌ Error al cargar configuración de WhatsApp.");
}
?>
