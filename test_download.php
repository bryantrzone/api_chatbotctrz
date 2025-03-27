<?php
// Guardar como test_download.php y ejecutar desde línea de comandos

// Configuración
$media_id = "2443203396020313"; // Reemplaza con el media_id que quieres probar
$token = "EAASBWzT6HkkBO6gh4GXt01Lx9teE9X4AfMKv99UqT44d93w21hSNcLcljbu6NOV2t9moJZBuFCCNpes6KT6InLthupm1ZCuxrbuzLmyZCkHIJAWhL1rK0hFRbvr3ucfZCbZBsnpj9FbTNCU2JsLyP1FddOSZAa28g9QWLuQn6QQliXLeH4jgXjFrfbhxodVODLCDlVZAzrfQL9RNkqmgA5bNZBFrVOQlp90NIkMXDxyqKq4dKTck9dkZD"; // Tu token real

// Paso 1: Obtener información del media
echo "Obteniendo info del media ID: $media_id\n";

// / Obtener la URL del media
$url = "https://graph.facebook.com/v22.0/{$media_id}?access_token={$token}";
$headers = ["Authorization: Bearer {$token}"];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token
]);

// Agrega encabezado para simular navegador
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36'
]);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Sigue redirecciones

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status != 200) {
    http_response_code(500);
    die("Error obteniendo media info");
}

$data = json_decode($response, true);
if (!isset($data['url'])) {
    http_response_code(500);
    die("URL no encontrada en respuesta");
}

// Redirigir al cliente a la URL de descarga
// header("Location: " . $data['url']);

echo $data['url'];

$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => $data['url'],
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'GET',
  CURLOPT_HTTPHEADER => array(
    'Authorization: '.$token.''
  ),
));

$response = curl_exec($curl);

curl_close($curl);
echo $response;




exit;