<?php

namespace App\Modules\Reports;

use PDO;

class ReporteStock {
    private $conn;
    private $table_name = "productos";

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Obtiene todos los productos cuyo stock actual es igual o menor al stock mínimo registrado.
     */
    public function obtenerProductosStockMinimo() {
        // Asegurar que la columna stock_minimo exista en la base de datos
        try {
            $this->conn->exec("ALTER TABLE " . $this->table_name . " ADD COLUMN stock_minimo DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER stock_cantidad");
        } catch (\Exception $e) {
            // Ya existe
        }

        $query = "SELECT p.*, u.nombre as usuario_nombre 
                  FROM " . $this->table_name . " p 
                  LEFT JOIN usuarios u ON p.usuario_registro_id = u.id 
                  WHERE p.stock_cantidad <= p.stock_minimo
                  ORDER BY (p.stock_minimo - p.stock_cantidad) DESC, p.descripcion ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $productos;
    }

    /**
     * Obtiene estadísticas de productos bajo o en stock mínimo.
     */
    public function obtenerEstadisticas() {
        $productos = $this->obtenerProductosStockMinimo();
        
        $totalAlerta = count($productos);
        $agotados = 0;
        $enMinimo = 0;

        foreach ($productos as $p) {
            $stock = floatval($p['stock_cantidad']);
            if ($stock <= 0) {
                $agotados++;
            } else {
                $enMinimo++;
            }
        }

        return [
            'total_alerta' => $totalAlerta,
            'agotados'     => $agotados,
            'en_minimo'    => $enMinimo
        ];
    }
}
