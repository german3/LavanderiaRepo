<?php

namespace App\Modules\Customers;

use PDO;

class Cliente {
    private $conn;
    private $table_name = "clientes";

    public $id;
    public $nombre;
    public $telefono;
    public $whatsapp_disponible;
    public $correo;
    public $direccion;
    public $observaciones;
    public $fecha_registro;
    public $estado;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Caso de uso: RegistrarCliente (Cajeros y Admins)
    public function crear() {
        $query = "INSERT INTO " . $this->table_name . " 
                  (id, nombre, telefono, whatsapp_disponible, correo, direccion, observaciones, estado) 
                  VALUES (:id, :nombre, :telefono, :whatsapp_disponible, :correo, :direccion, :observaciones, :estado)";

        $stmt = $this->conn->prepare($query);

        $this->id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $whatsapp = $this->whatsapp_disponible ? 1 : 0;

        $stmt->bindParam(":id", $this->id);
        $stmt->bindParam(":nombre", $this->nombre);
        $stmt->bindParam(":telefono", $this->telefono);
        $stmt->bindParam(":whatsapp_disponible", $whatsapp);
        $stmt->bindParam(":correo", $this->correo);
        $stmt->bindParam(":direccion", $this->direccion);
        $stmt->bindParam(":observaciones", $this->observaciones);
        $stmt->bindParam(":estado", $this->estado);

        if($stmt->execute()) {
            return true;
        }
        return false;
    }

    // Caso de uso: BuscarCliente (por nombre o teléfono)
    public function buscar($termino) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE estado = 'ACTIVO' AND (nombre LIKE :termino OR telefono LIKE :termino)
                  ORDER BY nombre ASC";
        $stmt = $this->conn->prepare($query);
        $like = "%" . $termino . "%";
        $stmt->bindParam(":termino", $like);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerTodos() {
        $query = "SELECT * FROM " . $this->table_name . " WHERE estado = 'ACTIVO' ORDER BY fecha_registro DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Caso de uso: DarDeBajaCliente
    public function desactivar($id) {
        $query = "UPDATE " . $this->table_name . " SET estado = 'INACTIVO' WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);
        return $stmt->execute();
    }

    public function obtenerPorId($id) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Caso de uso: ActualizarCliente
    public function actualizar() {
        $query = "UPDATE " . $this->table_name . " SET 
                  nombre = :nombre,
                  telefono = :telefono,
                  whatsapp_disponible = :whatsapp_disponible,
                  correo = :correo,
                  direccion = :direccion,
                  observaciones = :observaciones
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $whatsapp = $this->whatsapp_disponible ? 1 : 0;

        $stmt->bindParam(":nombre", $this->nombre);
        $stmt->bindParam(":telefono", $this->telefono);
        $stmt->bindParam(":whatsapp_disponible", $whatsapp);
        $stmt->bindParam(":correo", $this->correo);
        $stmt->bindParam(":direccion", $this->direccion);
        $stmt->bindParam(":observaciones", $this->observaciones);
        $stmt->bindParam(":id", $this->id);

        return $stmt->execute();
    }
}
