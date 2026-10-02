<?php

namespace App\Modules\Catalog;

use PDO;
use Exception;

class Historial {
    private $conn;
    private $table_name = "historial_movimientos";

    public function __construct($db) {
        $this->conn = $db;
        $this->asegurarTabla();
    }

    public function asegurarTabla() {
        $sql = "CREATE TABLE IF NOT EXISTS " . $this->table_name . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
            tipo_movimiento VARCHAR(50) NOT NULL,
            producto_id VARCHAR(50) NULL,
            producto_sku VARCHAR(100) NULL,
            producto_descripcion VARCHAR(255) NOT NULL,
            tipo_producto VARCHAR(50) DEFAULT 'NORMAL',
            cantidad DECIMAL(10,2) DEFAULT 0.00,
            costo_unitario DECIMAL(10,2) DEFAULT 0.00,
            stock_antes DECIMAL(10,2) DEFAULT 0.00,
            stock_despues DECIMAL(10,2) DEFAULT 0.00,
            motivo TEXT NULL,
            usuario_id INT NULL,
            usuario_nombre VARCHAR(255) NULL,
            INDEX (fecha_hora),
            INDEX (tipo_movimiento),
            INDEX (producto_sku)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        try {
            $this->conn->exec($sql);
        } catch (Exception $e) {
            error_log("Error asegurando tabla historial_movimientos: " . $e->getMessage());
        }
    }

    public function registrar($datos) {
        $query = "INSERT INTO " . $this->table_name . " 
            (tipo_movimiento, producto_id, producto_sku, producto_descripcion, tipo_producto, cantidad, costo_unitario, stock_antes, stock_despues, motivo, usuario_id, usuario_nombre) 
            VALUES (:tipo_movimiento, :producto_id, :producto_sku, :producto_descripcion, :tipo_producto, :cantidad, :costo_unitario, :stock_antes, :stock_despues, :motivo, :usuario_id, :usuario_nombre)";

        $stmt = $this->conn->prepare($query);

        $tipoMovimiento     = $datos['tipo_movimiento'] ?? 'MOVIMIENTO';
        $productoId         = $datos['producto_id'] ?? null;
        $productoSku        = $datos['producto_sku'] ?? null;
        $productoDescripcion= $datos['producto_descripcion'] ?? 'Sin descripción';
        $tipoProducto       = $datos['tipo_producto'] ?? 'NORMAL';
        $cantidad           = floatval($datos['cantidad'] ?? 0);
        $costoUnitario      = floatval($datos['costo_unitario'] ?? 0);
        $stockAntes         = floatval($datos['stock_antes'] ?? 0);
        $stockDespues       = floatval($datos['stock_despues'] ?? 0);
        $motivo             = $datos['motivo'] ?? '';
        $usuarioId          = isset($datos['usuario_id']) ? intval($datos['usuario_id']) : null;
        $usuarioNombre      = $datos['usuario_nombre'] ?? 'Sistema';

        $stmt->bindParam(':tipo_movimiento',      $tipoMovimiento);
        $stmt->bindParam(':producto_id',          $productoId);
        $stmt->bindParam(':producto_sku',         $productoSku);
        $stmt->bindParam(':producto_descripcion', $productoDescripcion);
        $stmt->bindParam(':tipo_producto',        $tipoProducto);
        $stmt->bindParam(':cantidad',             $cantidad);
        $stmt->bindParam(':costo_unitario',      $costoUnitario);
        $stmt->bindParam(':stock_antes',          $stockAntes);
        $stmt->bindParam(':stock_despues',        $stockDespues);
        $stmt->bindParam(':motivo',               $motivo);
        $stmt->bindParam(':usuario_id',           $usuarioId);
        $stmt->bindParam(':usuario_nombre',       $usuarioNombre);

        return $stmt->execute();
    }

    public function obtenerTodos() {
        $query = "SELECT * FROM " . $this->table_name . " ORDER BY fecha_hora DESC, id DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
