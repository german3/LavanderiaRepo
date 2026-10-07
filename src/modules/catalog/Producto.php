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
    public $stock_minimo;
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

    // Obtener todos los productos de tipo NORMAL (para selector de insumos en Kit y movimientos)
    public function obtenerProductosNormales() {
        $query = "SELECT id, descripcion, unidad_medida, costo, precio_venta
                  FROM " . $this->table_name . "
                  WHERE tipo = 'NORMAL' AND estado = 'ACTIVO'
                  ORDER BY descripcion ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Verificar si un código de barras ya existe en la base de datos
    public function existeCodigoBarras($codigoBarras, $excluirId = null) {
        $codigoBarras = trim((string)$codigoBarras);
        if ($codigoBarras === '') {
            return false;
        }

        $query = "SELECT id FROM " . $this->table_name . " WHERE codigo_barras = :codigo_barras";
        if ($excluirId !== null && $excluirId !== '') {
            $query .= " AND id != :excluir_id";
        }
        $query .= " LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":codigo_barras", $codigoBarras);
        if ($excluirId !== null && $excluirId !== '') {
            $stmt->bindParam(":excluir_id", $excluirId);
        }
        $stmt->execute();

        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Caso de uso: CrearProducto
    public function crear() {
        $this->codigo_interno_sku = $this->obtenerSiguienteSku();

        // Asegurar que la columna stock_minimo exista en la base de datos
        try {
            $this->conn->exec("ALTER TABLE " . $this->table_name . " ADD COLUMN stock_minimo DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER stock_cantidad");
        } catch (\Exception $e) {
            // Ya existe o no se requiere alteración
        }

        if (empty($this->codigo_barras)) {
            do {
                $this->codigo_barras = "EAN" . time() . rand(100, 999);
            } while ($this->existeCodigoBarras($this->codigo_barras));
        } else {
            if ($this->existeCodigoBarras($this->codigo_barras)) {
                throw new Exception("El código de barras '{$this->codigo_barras}' ya está registrado.");
            }
        }

        $query = "INSERT INTO " . $this->table_name . " 
                  (id, codigo_barras, codigo_interno_sku, descripcion, tipo, unidad_medida, stock_cantidad, stock_minimo, ropa_kg, costo, precio_venta, estado, usuario_registro_id) 
                  VALUES (:id, :codigo_barras, :codigo_interno_sku, :descripcion, :tipo, :unidad_medida, :stock_cantidad, :stock_minimo, :ropa_kg, :costo, :precio_venta, :estado, :usuario_registro_id)";

        $stmt = $this->conn->prepare($query);

        $this->id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $ropaKgVal = isset($this->ropa_kg) ? floatval($this->ropa_kg) : 0.00;
        $stockMinimoVal = ($this->tipo === 'NORMAL') ? floatval($this->stock_minimo ?? 0) : 0.00;

        // Validar y asegurar que usuario_registro_id exista en la tabla usuarios
        if (!empty($this->usuario_registro_id)) {
            $checkUser = $this->conn->prepare("SELECT id FROM usuarios WHERE id = :id LIMIT 1");
            $checkUser->execute([':id' => $this->usuario_registro_id]);
            if (!$checkUser->fetch()) {
                $this->usuario_registro_id = null;
            }
        }

        if (empty($this->usuario_registro_id)) {
            if (isset($_SESSION['usuario_id'])) {
                $checkSession = $this->conn->prepare("SELECT id FROM usuarios WHERE id = :id LIMIT 1");
                $checkSession->execute([':id' => $_SESSION['usuario_id']]);
                if ($checkSession->fetch()) {
                    $this->usuario_registro_id = $_SESSION['usuario_id'];
                }
            }
            if (empty($this->usuario_registro_id)) {
                $userStmt = $this->conn->query("SELECT id FROM usuarios WHERE estado = 'ACTIVO' ORDER BY fecha_creacion ASC LIMIT 1");
                $userRow = $userStmt ? $userStmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($userRow) {
                    $this->usuario_registro_id = $userRow['id'];
                }
            }
        }

        $stmt->bindParam(":id", $this->id);
        $stmt->bindParam(":codigo_barras", $this->codigo_barras);
        $stmt->bindParam(":codigo_interno_sku", $this->codigo_interno_sku);
        $stmt->bindParam(":descripcion", $this->descripcion);
        $stmt->bindParam(":tipo", $this->tipo);
        $stmt->bindParam(":unidad_medida", $this->unidad_medida);
        $stmt->bindParam(":stock_cantidad", $this->stock_cantidad);
        $stmt->bindParam(":stock_minimo", $stockMinimoVal);
        $stmt->bindParam(":ropa_kg", $ropaKgVal);
        $stmt->bindParam(":costo", $this->costo);
        $stmt->bindParam(":precio_venta", $this->precio_venta);
        $stmt->bindParam(":estado", $this->estado);
        $stmt->bindParam(":usuario_registro_id", $this->usuario_registro_id);

        try {
            if ($stmt->execute()) {
                return true;
            }
            return false;
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000 || strpos($e->getMessage(), '1062') !== false) {
                throw new Exception("El código de barras '{$this->codigo_barras}' ya está registrado.");
            }
            throw $e;
        }
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

    public function obtenerInsumosKit($kitId) {
        $query = "SELECT pk.*, p.descripcion as insumo_descripcion, p.unidad_medida as insumo_unidad_base, p.costo as insumo_costo
                  FROM " . $this->table_kits . " pk
                  JOIN " . $this->table_name . " p ON pk.insumo_id = p.id
                  WHERE pk.kit_id = :kit_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":kit_id", $kitId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerPorId($id) {
        $query = "SELECT p.*, u.nombre as usuario_nombre 
                  FROM " . $this->table_name . " p 
                  LEFT JOIN usuarios u ON p.usuario_registro_id = u.id 
                  WHERE p.id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->execute();
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($prod && $prod['tipo'] === 'KIT') {
            $prod['insumos'] = $this->obtenerInsumosKit($id);
        }
        return $prod;
    }

    public function actualizar($id, array $datos) {
        $codigoBarras = trim($datos['codigo_barras'] ?? '');
        if (!empty($codigoBarras) && $this->existeCodigoBarras($codigoBarras, $id)) {
            throw new Exception("El código de barras '{$codigoBarras}' ya está registrado.");
        }

        $query = "UPDATE " . $this->table_name . " 
                  SET descripcion = :descripcion,
                      codigo_barras = :codigo_barras,
                      unidad_medida = :unidad_medida,
                      stock_minimo = :stock_minimo,
                      costo = :costo,
                      precio_venta = :precio_venta,
                      ropa_kg = :ropa_kg
                  WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stockMinimo = floatval($datos['stock_minimo'] ?? 0);
        $ropaKg      = floatval($datos['ropa_kg'] ?? 0);
        $costo       = floatval($datos['costo'] ?? 0);
        $precioVenta = floatval($datos['precio_venta'] ?? 0);
        $descripcion = trim($datos['descripcion'] ?? '');
        $unidadMedida = trim($datos['unidad_medida'] ?? 'PIEZA');

        $stmt->bindParam(":descripcion", $descripcion);
        $stmt->bindParam(":codigo_barras", $codigoBarras);
        $stmt->bindParam(":unidad_medida", $unidadMedida);
        $stmt->bindParam(":stock_minimo", $stockMinimo);
        $stmt->bindParam(":costo", $costo);
        $stmt->bindParam(":precio_venta", $precioVenta);
        $stmt->bindParam(":ropa_kg", $ropaKg);
        $stmt->bindParam(":id", $id);

        try {
            return $stmt->execute();
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000 || strpos($e->getMessage(), '1062') !== false) {
                throw new Exception("El código de barras '{$codigoBarras}' ya está registrado.");
            }
            throw $e;
        }
    }

    public function eliminar($id) {
        try {
            $this->conn->beginTransaction();

            // 1. Eliminar relaciones de kit asociadas
            $delKits = $this->conn->prepare("DELETE FROM " . $this->table_kits . " WHERE kit_id = :id OR insumo_id = :id");
            $delKits->bindParam(":id", $id);
            $delKits->execute();

            // 2. Eliminar detalles de entradas de compra asociados
            $delEntradas = $this->conn->prepare("DELETE FROM detalle_entradas WHERE producto_id = :id");
            $delEntradas->bindParam(":id", $id);
            $delEntradas->execute();

            // 3. Eliminar movimientos de inventario asociados
            $delMovs = $this->conn->prepare("DELETE FROM movimientos_inventario WHERE producto_id = :id");
            $delMovs->bindParam(":id", $id);
            $delMovs->execute();

            // 4. Eliminar el producto
            $query = "DELETE FROM " . $this->table_name . " WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $id);
            $res = $stmt->execute();

            $this->conn->commit();
            return $res;
        } catch (\Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
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
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($productos as &$p) {
            if ($p['tipo'] === 'KIT') {
                $p['insumos'] = $this->obtenerInsumosKit($p['id']);
            } else {
                $p['insumos'] = [];
            }
        }
        return $productos;
    }
}
