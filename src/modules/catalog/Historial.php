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
            kit_detalle TEXT NULL,
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

        // Agregar columna kit_detalle si aún no existe (migracion en caliente)
        try {
            $this->conn->exec("ALTER TABLE " . $this->table_name . " ADD COLUMN kit_detalle TEXT NULL AFTER motivo");
        } catch (Exception $e) {
            // Ya existe, ignorar
        }
    }

    public function registrar($datos) {
        $query = "INSERT INTO " . $this->table_name . " 
            (tipo_movimiento, producto_id, producto_sku, producto_descripcion, tipo_producto, cantidad, costo_unitario, stock_antes, stock_despues, motivo, kit_detalle, usuario_id, usuario_nombre) 
            VALUES (:tipo_movimiento, :producto_id, :producto_sku, :producto_descripcion, :tipo_producto, :cantidad, :costo_unitario, :stock_antes, :stock_despues, :motivo, :kit_detalle, :usuario_id, :usuario_nombre)";

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
        $kitDetalle         = isset($datos['kit_detalle']) ? json_encode($datos['kit_detalle'], JSON_UNESCAPED_UNICODE) : null;
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
        $stmt->bindParam(':kit_detalle',          $kitDetalle);
        $stmt->bindParam(':usuario_id',           $usuarioId);
        $stmt->bindParam(':usuario_nombre',       $usuarioNombre);

        return $stmt->execute();
    }

    public function obtenerTodos() {
        $query = "SELECT * FROM " . $this->table_name . " ORDER BY fecha_hora DESC, id DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Identificar movimientos de tipo KIT que necesiten sus insumos
        $kitIdsToFetch = [];
        foreach ($movimientos as $m) {
            $esKit = (strtoupper($m['tipo_producto'] ?? '') === 'KIT');
            if ($esKit && empty($m['kit_detalle']) && !empty($m['producto_id'])) {
                $kitIdsToFetch[$m['producto_id']] = true;
            }
        }

        // También verificar si algún producto_id en el historial corresponde a un KIT en la tabla productos
        if (!empty($movimientos)) {
            $prodIdsToCheck = [];
            foreach ($movimientos as $m) {
                if (empty($m['kit_detalle']) && !empty($m['producto_id']) && !isset($kitIdsToFetch[$m['producto_id']])) {
                    $prodIdsToCheck[$m['producto_id']] = true;
                }
            }
            if (!empty($prodIdsToCheck)) {
                $checkList = array_slice(array_keys($prodIdsToCheck), 0, 100);
                $ph = implode(',', array_fill(0, count($checkList), '?'));
                try {
                    $stCheck = $this->conn->prepare("SELECT id FROM productos WHERE id IN ($ph) AND tipo = 'KIT'");
                    $stCheck->execute($checkList);
                    $kitsEncontrados = $stCheck->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($kitsEncontrados as $kId) {
                        $kitIdsToFetch[$kId] = true;
                    }
                } catch (\Exception $e) {}
            }
        }

        // Cargar insumos de los kits encontrados
        $kitsInsumosMap = [];
        if (!empty($kitIdsToFetch)) {
            $idsList = array_keys($kitIdsToFetch);
            $placeholders = implode(',', array_fill(0, count($idsList), '?'));
            $sqlKits = "SELECT pk.kit_id, pk.insumo_id, pk.cantidad_consumida, pk.unidad, pk.ropa_kg, 
                               p.descripcion as insumo_nombre, p.codigo_interno_sku as insumo_sku
                        FROM productos_kits pk
                        JOIN productos p ON pk.insumo_id = p.id
                        WHERE pk.kit_id IN ($placeholders)
                        ORDER BY pk.kit_id, p.descripcion ASC";
            try {
                $stmtK = $this->conn->prepare($sqlKits);
                $stmtK->execute($idsList);
                $insumosDb = $stmtK->fetchAll(PDO::FETCH_ASSOC);
                foreach ($insumosDb as $ins) {
                    $kitsInsumosMap[$ins['kit_id']][] = [
                        'insumo_id'      => $ins['insumo_id'],
                        'insumo_nombre'  => $ins['insumo_nombre'],
                        'insumo_sku'     => $ins['insumo_sku'] ?? '',
                        'cantidad_total' => floatval($ins['cantidad_consumida']),
                        'unidad'         => $ins['unidad'] ?? 'PIEZA',
                        'ropa_kg'        => floatval($ins['ropa_kg'] ?? 0)
                    ];
                }
            } catch (\Exception $e) {
                error_log("Error cargando insumos de kits en Historial: " . $e->getMessage());
            }
        }

        // Asignar los insumos a cada movimiento correspondiente
        foreach ($movimientos as &$mov) {
            $prodId = $mov['producto_id'] ?? null;
            if (!empty($prodId) && isset($kitsInsumosMap[$prodId])) {
                $mov['tipo_producto'] = 'KIT';
                if (empty($mov['kit_detalle'])) {
                    $mov['kit_detalle'] = json_encode($kitsInsumosMap[$prodId], JSON_UNESCAPED_UNICODE);
                }
            }
        }
        unset($mov);

        return $movimientos;
    }
}
