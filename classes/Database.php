<?php
/**
 * Database.php
 * 
 * Clase para manejar conexiones y operaciones con la base de datos
 */
class Database {
    private $pdo;
    
    public function __construct($host, $dbname, $username, $password) {
        try {
            $this->pdo = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            error_log("Error de conexión a base de datos: " . $e->getMessage());
            throw new Exception("Error de conexión a la base de datos");
        }
    }
    
    /**
     * Ejecuta una consulta SQL y retorna el objeto statement
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Error en la consulta SQL: " . $e->getMessage() . " - SQL: $sql");
            throw new Exception("Error en la consulta a la base de datos");
        }
    }
    
    /**
     * Inserta un registro y retorna el ID generado
     */
    public function insert($sql, $params = []) {
        $this->query($sql, $params);
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Actualiza registros y retorna el número de filas afectadas
     */
    public function update($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Elimina registros y retorna el número de filas afectadas
     */
    public function delete($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Inicia una transacción
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Confirma una transacción
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * Revierte una transacción
     */
    public function rollBack() {
        return $this->pdo->rollBack();
    }
    
    /**
     * Verifica si una tabla existe
     */
    public function tableExists($tableName) {
        $result = $this->query(
            "SELECT COUNT(*) as count FROM information_schema.tables 
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$tableName]
        )->fetch();
        
        return $result['count'] > 0;
    }
    
    /**
     * Obtiene el objeto PDO interno
     */
    public function getPDO() {
        return $this->pdo;
    }
    
    /**
     * Cierra la conexión explícitamente
     */
    public function close() {
        $this->pdo = null;
    }
}