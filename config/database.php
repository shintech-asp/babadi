<?php
// config/database.php

class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $conn;
    private $error;
    
    public function getConnection() {
        $this->conn = null;
        $this->error = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8");
        } catch(PDOException $exception) {
            $this->error = $exception->getMessage();
            error_log("Database Connection Error: " . $this->error);
        }
        
        return $this->conn;
    }
    
    public function getError() {
        return $this->error;
    }
    
    public function isConnected() {
        return $this->conn !== null;
    }
}