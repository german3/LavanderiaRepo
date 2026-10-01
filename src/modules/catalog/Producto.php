<?php

namespace App\Modules\Catalog;

use PDO;
use Exception;

class Producto {
    private $conn;
    private $table_name      = "productos";
    private $table_kits      = "productos_kits";

    public $id;
    public $codigo_barras;
    public $codigo_interno_sku;
    public $descripcion;
    public $tipo;
    public $unidad_medida;
    public $stock_cantidad;
    public $ropa_kg;
    public $costo;
    public $precio_venta;
    public $estado;
    public $usuario_registro_id;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Caso de uso: GenerarSKUConsecutivo (formato puramente numérico iniciando en 100000)
    public function obtenerSiguienteSku() {
        $query = "SELECT codigo_interno_sku FROM " . $this->table_name . "
                  WHERE codigo_interno_sku REGEXP '^[0-9]+$'
                  ORDER BY CAST(codigo_interno_sku AS UNSIGNED) DESC
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['codigo_interno_sku'])) {
            $ultimo = (int) $row['codigo_interno_sku'];
            if ($ultimo >= 100000) {
                return (string) ($ultimo + 1);
            }
        }
        return '100000';
    }

    // Obtener todos los productos de tipo NORMAL (para selector de insumos en Kit)
    public function obtenerProductosNormales() {
        $query = "SELECT id, descripcion, unidad_medida, precio_venta
                  FROM " . $this->table_name . "
                  WHERE tipo = 'NORMAL' AND estado = 'ACTIVO'
                  ORDER BY descripcion ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Caso de uso: CrearProducto
    public function crear() {
        $this->codigo_interno_sku = $this->obtenerSiguienteSku();

        $query = "INSERT INTO " . $this->table_name . " 
                  (id, codigo_barras, codigo_interno_sku, descripcion, tipo, unidad_medida, stock_cantidad, ropa_kg, costo, precio_venta, estado, usuario_registro_id) 
                  VALUES (:id, :codigo_barras, :codigo_interno_sku, :descripcion, :tipo, :unidad_medida, :stock_cantidad, :ropa_kg, :costo, :precio_venta, :estado, :usuario_registro_id)";

        $stmt = $this->conn->prepare($query);

        $this->id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        if (empty($this->codigo_barras)) {
            $this->codigo_barras = "EAN" . time() . rand(100, 999);
        }

        $ropaKgVal = isset($this->ropa_kg) ? floatval($this->ropa_kg) : 0.00;

        $stmt->bindParam(":id", $this->id);
        $stmt->bindParam(":codigo_barras", $this->codigo_barras);
        $stmt->bindParam(":codigo_interno_sku", $this->codigo_interno_sku);
        $stmt->bindParam(":descripcion", $this->descripcion);
        $stmt->bindParam(":tipo", $this->tipo);
        $stmt->bindParam(":unidad_medida", $this->unidad_medida);
        $stmt->bindParam(":stock_cantidad", $this->stock_cantidad);
        $stmt->bindParam(":ropa_kg", $ropaKgVal);
        $stmt->bindParam(":costo", $this->costo);
        $stmt->bindParam(":precio_venta", $this->precio_venta);
        $stmt->bindParam(":estado", $this->estado);
        $stmt->bindParam(":usuario_registro_id", $this->usuario_registro_id);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    // Caso de uso: GuardarInsumosKit — guarda los insumos de un kit en productos_kits
    public function guardarInsumosKit($kitId, array $insumos) {
        // Eliminar insumos previos (si existieran)
        $del = $this->conn->prepare("DELETE FROM " . $this->table_kits . " WHERE kit_id = :kit_id");
        $del->bindParam(":kit_id", $kitId);
        $del->execute();

        $query = "INSERT INTO " . $this->table_kits . " (kit_id, insumo_id, cantidad_consumida, unidad, ropa_kg)
                  VALUES (:kit_id, :insumo_id, :cantidad, :unidad, :ropa_kg)";
        $stmt = $this->conn->prepare($query);

        foreach ($insumos as $insumo) {
            $ropaKgInsumo = floatval($insumo['ropa_kg'] ?? ($this->ropa_kg ?? 0.00));
            $stmt->bindParam(":kit_id",    $kitId);
            $stmt->bindParam(":insumo_id", $insumo['id']);
            $stmt->bindParam(":cantidad",  $insumo['cantidad']);
            $stmt->bindParam(":unidad",    $insumo['unidad']);
            $stmt->bindParam(":ropa_kg",   $ropaKgInsumo);
            $stmt->execute();
        }
        return true;
    }

    public function obtenerTodos() {
        $query = "SELECT p.*, u.nombre as usuario_nombre 
                  FROM " . $this->table_name . " p 
                  LEFT JOIN usuarios u ON p.usuario_registro_id = u.id 
                  ORDER BY 
                    CASE 
                        WHEN p.codigo_interno_sku REGEXP '^[0-9]+$' THEN CAST(p.codigo_interno_sku AS UNSIGNED)
                        WHEN p.codigo_interno_sku REGEXP '^SKU-[0-9]+$' THEN CAST(SUBSTRING(p.codigo_interno_sku, 5) AS UNSIGNED)
                        ELSE 0 
                    END DESC, p.fecha_registro DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
