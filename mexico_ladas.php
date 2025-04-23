<?php
/**
 * Mapa de ladas de México con sus estados y ciudades correspondientes
 * Fuente: Instituto Federal de Telecomunicaciones (IFT)
 */

 require_once 'ladas.php';

/**
 * Identifica la ubicación basada en el número telefónico
 * 
 * @param string $phone Número telefónico
 * @return array Información de ubicación (estado, ciudad, region)
 */
function identificarUbicacionPorTelefono($phone) {
    global $MEXICO_LADAS;
    
    // Limpieza básica del número
    $phone = trim($phone);
    
    // Eliminar el prefijo internacional si existe
    if (strpos($phone, '+52') === 0) {
        $phone = substr($phone, 3);
    } elseif (strpos($phone, '52') === 0) {
        $phone = substr($phone, 2);
    }
    
    // Por defecto
    $info = [
        'lada' => 'Desconocida',
        'estado' => 'Desconocido',
        'ciudad' => 'Desconocida',
        'region' => 'Desconocida'
    ];
    
    // Buscar ladas de 3 dígitos primero (son más específicas)
    foreach (['3', '2'] as $digitLength) {
        if (strlen($phone) >= $digitLength) {
            $lada = substr($phone, 0, $digitLength);
            if (isset($MEXICO_LADAS[$lada])) {
                $info = array_merge($info, $MEXICO_LADAS[$lada]);
                $info['lada'] = $lada;
                return $info;
            }
        }
    }
    
    return $info;
}
?>