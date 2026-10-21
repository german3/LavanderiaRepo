<?php

namespace App\Config;

use PDO;
use PDOException;

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $port;
    public $conn;

    public function __construct() {
        $get = function($key, $default = null) {
            $val = getenv($key);
            if ($val !== false && $val !== '') return $val;
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
            if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
            return $default;
        };

        // En local conecta a localhost por defecto.
        // En Render u otro servidor en la nube, lee las variables de entorno (DB_HOST, DB_USER, DB_PASS, etc.)
        $this->host     = $get('DB_HOST', "localhost");
        $this->port     = $get('DB_PORT', "3306");
        $this->username = $get('DB_USER', "root");
        $this->password = $get('DB_PASS', "");
        $this->db_name  = $get('DB_NAME', "lavanderia_db");
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];

            // Si es conexión a la nube (como Aiven), requerir SSL
            if ($this->host !== 'localhost' && $this->host !== '127.0.0.1') {
                $options[PDO::MYSQL_ATTR_SSL_CA] = true;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            
        } catch(PDOException $exception) {
            error_log("Error de conexión BD: " . $exception->getMessage());
        }

        return $this->conn;
    }
}
