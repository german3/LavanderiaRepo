<?php

namespace App\Modules\Auth;

use PDO;
use Exception;

class Usuario {
    private $conn;
    private $table_name = "usuarios";

    public $id;
    public $nombre;
    public $correo;
    public $password_hash;
    public $rol;
    public $estado;
    public $fecha_creacion;
    public $ultima_actividad;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Caso de uso: RegistrarUsuario (Solo administradores)
    public function crear() {
        $query = "INSERT INTO " . $this->table_name . " 
                  (id, nombre, correo, password_hash, rol, estado) 
                  VALUES (:id, :nombre, :correo, :password_hash, :rol, :estado)";

        $stmt = $this->conn->prepare($query);

        // Generar UUID simple para PHP si no usamos binarios
        $this->id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $stmt->bindParam(":id", $this->id);
        $stmt->bindParam(":nombre", $this->nombre);
        $stmt->bindParam(":correo", $this->correo);
        
        $hash = password_hash($this->password_hash, PASSWORD_BCRYPT);
        $stmt->bindParam(":password_hash", $hash);
        
        $stmt->bindParam(":rol", $this->rol);
        $stmt->bindParam(":estado", $this->estado);

        if($stmt->execute()) {
            return true;
        }
        return false;
    }

    // Buscar usuario por correo para Login
    public function buscarPorCorreo($correo) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE correo = :correo AND estado = 'ACTIVO' LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":correo", $correo);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Caso de uso: ActualizarActividad
    public function registrarActividad($id) {
        $query = "UPDATE " . $this->table_name . " SET ultima_actividad = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->execute();
    }

    // Obtener todos los usuarios activos
    public function obtenerTodos() {
        $query = "SELECT id, nombre, correo, rol, estado, ultima_actividad FROM " . $this->table_name . " WHERE estado = 'ACTIVO' ORDER BY fecha_creacion DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Caso de uso: DesactivarUsuario
    public function desactivar($id) {
        $query = "UPDATE " . $this->table_name . " SET estado = 'INACTIVO' WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);
        return $stmt->execute();
    }
}
