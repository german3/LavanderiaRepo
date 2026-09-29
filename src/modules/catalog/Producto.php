<?php

namespace App\Modules\Catalog;

use PDO;
use Exception;

class Producto {
    private $conn;
    private $table_name = "productos";

    public $id;
    public $codigo_barras;
    public $codigo_interno_sku;
    public $descripcion;
    public $tipo;
    public $unidad_medida;
    public $costo;
    public $precio_venta;
    public $estado;
    public $usuario_registro_id;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Caso de uso: CrearProducto
    public function crear() {
        $query = "INSERT INTO " . $this->table_name . " 
                  (id, codigo_barras, codigo_interno_sku, descripcion, tipo, unidad_medida, costo, precio_venta, estado, usuario_registro_id) 
                  VALUES (:id, :codigo_barras, :codigo_interno_sku, :descripcion, :tipo, :unidad_medida, :costo, :precio_venta, :estado, :usuario_registro_id)";

        $stmt = $this->conn->prepare($query);

        $this->id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        // Si no hay código de barras, generamos uno interno simple
        if(empty($this->codigo_barras)) {
            $this->codigo_barras = "EAN" . time() . rand(100,999);
        }

        $stmt->bindParam(":id", $this->id);
        $stmt->bindParam(":codigo_barras", $this->codigo_barras);
        $stmt->bindParam(":codigo_interno_sku", $this->codigo_interno_sku);
        $stmt->bindParam(":descripcion", $this->descripcion);
        $stmt->bindParam(":tipo", $this->tipo);
        $stmt->bindParam(":unidad_medida", $this->unidad_medida);
        $stmt->bindParam(":costo", $this->costo);
        $stmt->bindParam(":precio_venta", $this->precio_venta);
        $stmt->bindParam(":estado", $this->estado);
        $stmt->bindParam(":usuario_registro_id", $this->usuario_registro_id);

        if($stmt->execute()) {
            return true;
        }
        return false;
    }

    public function obtenerTodos() {
        // Obtenemos los productos incluyendo el nombre del usuario que los registró
        $query = "SELECT p.*, u.nombre as usuario_nombre 
                  FROM " . $this->table_name . " p 
                  LEFT JOIN usuarios u ON p.usuario_registro_id = u.id 
                  ORDER BY p.fecha_registro DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
