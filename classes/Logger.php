<?php
/**
 * Logger.php
 * 
 * Clase para registro de eventos y mensajes de depuración
 */
class Logger {
    private $logFile;
    private $errorLogFile;
    
    public function __construct($logFile = 'whatsapp_log.txt', $errorLogFile = 'error_log.txt') {
        $this->logFile = $logFile;
        $this->errorLogFile = $errorLogFile;
    }
    
    /**
     * Registra un mensaje de depuración
     */
    public function debug($message, $data = null) {
        $this->log('DEBUG', $message, $data);
    }
    
    /**
     * Registra un mensaje informativo
     */
    public function info($message, $data = null) {
        $this->log('INFO', $message, $data);
    }
    
    /**
     * Registra un mensaje de advertencia
     */
    public function warning($message, $data = null) {
        $this->log('WARNING', $message, $data);
    }
    
    /**
     * Registra un mensaje de error
     */
    public function error($message, $data = null) {
        $this->log('ERROR', $message, $data, $this->errorLogFile);
    }
    
    /**
     * Registra un mensaje crítico
     */
    public function critical($message, $data = null) {
        $this->log('CRITICAL', $message, $data, $this->errorLogFile);
    }
    
    /**
     * Función base para registrar mensajes
     */
    private function log($level, $message, $data = null, $file = null) {
        $file = $file ?? $this->logFile;
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message";
        
        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                $logMessage .= "\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                $logMessage .= " $data";
            }
        }
        
        $logMessage .= "\n";
        
        file_put_contents($file, $logMessage, FILE_APPEND);
    }
    
    /**
     * Limpia el archivo de registro
     */
    public function clearLog($file = null) {
        $file = $file ?? $this->logFile;
        file_put_contents($file, '');
    }
    
    /**
     * Rota los archivos de log (útil para logs grandes)
     */
    public function rotateLog($file = null, $maxSize = 5242880) { // 5MB por defecto
        $file = $file ?? $this->logFile;
        
        if (!file_exists($file)) {
            return;
        }
        
        $fileSize = filesize($file);
        
        if ($fileSize > $maxSize) {
            $backupFile = $file . '.' . date('Y-m-d-H-i-s') . '.bak';
            rename($file, $backupFile);
            
            // Opcional: comprimir el archivo de respaldo
            if (function_exists('gzopen')) {
                $gzFile = $backupFile . '.gz';
                $fp = gzopen($gzFile, 'w9');
                gzwrite($fp, file_get_contents($backupFile));
                gzclose($fp);
                unlink($backupFile);
            }
        }
    }
}