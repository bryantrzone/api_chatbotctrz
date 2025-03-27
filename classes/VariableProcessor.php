<?php
/**
 * VariableProcessor.php
 * 
 * Clase que maneja variables del sistema y de usuario para los flujos de conversación
 */
class VariableProcessor {
    private $db;
    private $logger;
    
    public function __construct(Database $db, Logger $logger) {
        $this->db = $db;
        $this->logger = $logger;
    }
    
    /**
     * Obtiene el valor de una variable
     */
    public function getVariable($name, $sessionId = null, $phone = null) {
        // Primero buscar como variable de usuario
        if ($phone) {
            $variable = $this->db->query(
                "SELECT uv.valor 
                 FROM usuario_variables uv
                 JOIN variables v ON uv.variable_id = v.id
                 WHERE v.nombre = ? AND uv.telefono = ?
                 LIMIT 1",
                [$name, $phone]
            )->fetch();
            
            if ($variable) {
                return $variable['valor'];
            }
        }
        
        // Buscar como variable global
        $variable = $this->db->query(
            "SELECT valor_default 
             FROM variables 
             WHERE nombre = ? AND es_global = 1
             LIMIT 1",
            [$name]
        )->fetch();
        
        if ($variable) {
            return $variable['valor_default'];
        }
        
        // Si no se encuentra, devolver null
        return null;
    }
    
    /**
     * Establece el valor de una variable
     */
    public function setVariable($name, $value, $sessionId = null, $phone = null) {
        // Verificar si la variable existe
        $variable = $this->db->query(
            "SELECT id, es_global FROM variables WHERE nombre = ? LIMIT 1",
            [$name]
        )->fetch();
        
        if (!$variable) {
            // Crear la variable si no existe
            $variableId = $this->db->insert(
                "INSERT INTO variables (nombre, valor_default, es_global, created_at, updated_at)
                 VALUES (?, ?, 0, NOW(), NOW())",
                [$name, $value]
            );
        } else {
            $variableId = $variable['id'];
            
            // Si es global, actualizar su valor por defecto
            if ($variable['es_global']) {
                $this->db->update(
                    "UPDATE variables SET valor_default = ?, updated_at = NOW() WHERE id = ?",
                    [$value, $variableId]
                );
                return true;
            }
        }
        
        // Si no es una variable global, guardar valor específico para este usuario
        if ($phone) {
            // Verificar si ya existe un valor para este usuario
            $userVar = $this->db->query(
                "SELECT id FROM usuario_variables 
                 WHERE telefono = ? AND variable_id = ?
                 LIMIT 1",
                [$phone, $variableId]
            )->fetch();
            
            if ($userVar) {
                // Actualizar valor existente
                $this->db->update(
                    "UPDATE usuario_variables SET valor = ?, updated_at = NOW() 
                     WHERE telefono = ? AND variable_id = ?",
                    [$value, $phone, $variableId]
                );
            } else {
                // Crear nuevo valor
                $this->db->insert(
                    "INSERT INTO usuario_variables (telefono, variable_id, valor, created_at, updated_at)
                     VALUES (?, ?, ?, NOW(), NOW())",
                    [$phone, $variableId, $value]
                );
            }
        }
        
        return true;
    }
    
    /**
     * Procesa una plantilla sustituyendo variables
     */
    public function processTemplate($template, $sessionId = null, $phone = null) {
        if (!$template) {
            return $template;
        }
        
        // Buscar variables en formato {{variable}}
        preg_match_all('/\{\{([^}]+)\}\}/', $template, $matches);
        
        if (empty($matches[1])) {
            return $template;
        }
        
        $replacements = [];
        foreach ($matches[1] as $varName) {
            $varValue = $this->getVariable($varName, $sessionId, $phone);
            $replacements[] = $varValue !== null ? $varValue : '';
        }
        
        // Realizar las sustituciones
        return str_replace($matches[0], $replacements, $template);
    }
    
    /**
     * Elimina todas las variables de un usuario
     */
    public function clearUserVariables($phone) {
        $this->db->delete(
            "DELETE FROM usuario_variables WHERE telefono = ?",
            [$phone]
        );
        return true;
    }
    
    /**
     * Copia variables entre usuarios (útil para transferencias)
     */
    public function copyUserVariables($sourcePhone, $targetPhone) {
        // Obtener todas las variables del usuario origen
        $sourceVars = $this->db->query(
            "SELECT variable_id, valor FROM usuario_variables WHERE telefono = ?",
            [$sourcePhone]
        )->fetchAll();
        
        // No hay nada que copiar
        if (empty($sourceVars)) {
            return true;
        }
        
        // Para cada variable, crear o actualizar en el usuario destino
        foreach ($sourceVars as $var) {
            $existingVar = $this->db->query(
                "SELECT id FROM usuario_variables 
                 WHERE telefono = ? AND variable_id = ?
                 LIMIT 1",
                [$targetPhone, $var['variable_id']]
            )->fetch();
            
            if ($existingVar) {
                $this->db->update(
                    "UPDATE usuario_variables SET valor = ?, updated_at = NOW() 
                     WHERE telefono = ? AND variable_id = ?",
                    [$var['valor'], $targetPhone, $var['variable_id']]
                );
            } else {
                $this->db->insert(
                    "INSERT INTO usuario_variables (telefono, variable_id, valor, created_at, updated_at)
                     VALUES (?, ?, ?, NOW(), NOW())",
                    [$targetPhone, $var['variable_id'], $var['valor']]
                );
            }
        }
        
        return true;
    }
    
    /**
     * Obtiene todas las variables de un usuario
     */
    public function getAllUserVariables($phone) {
        $variables = $this->db->query(
            "SELECT v.nombre, uv.valor 
             FROM usuario_variables uv
             JOIN variables v ON uv.variable_id = v.id
             WHERE uv.telefono = ?",
            [$phone]
        )->fetchAll();
        
        $result = [];
        foreach ($variables as $var) {
            $result[$var['nombre']] = $var['valor'];
        }
        
        return $result;
    }
    
    /**
     * Inicializa variables para un nuevo usuario
     */
    public function initializeUserVariables($phone) {
        // Obtener todas las variables globales con valores por defecto
        $globalVars = $this->db->query(
            "SELECT id, nombre, valor_default FROM variables WHERE es_global = 1"
        )->fetchAll();
        
        foreach ($globalVars as $var) {
            // Verificar si el usuario ya tiene esta variable
            $userVar = $this->db->query(
                "SELECT id FROM usuario_variables 
                 WHERE telefono = ? AND variable_id = ?
                 LIMIT 1",
                [$phone, $var['id']]
            )->fetch();
            
            if (!$userVar) {
                // Crear la variable para este usuario
                $this->db->insert(
                    "INSERT INTO usuario_variables (telefono, variable_id, valor, created_at, updated_at)
                     VALUES (?, ?, ?, NOW(), NOW())",
                    [$phone, $var['id'], $var['valor_default']]
                );
            }
        }
        
        return true;
    }
}