<?php

namespace App\Modules\Inventory;

use PDO;
use Exception;

/**
 * Modelo MovimientoInventario — Spec 04-inventario-compras.md
 *
 * Entidad: MovimientoInventario
 *   - id              (UUID)
 *   - producto_id     (Relación con Producto)
 *   - cantidad        (Decimal)
 *   - tipo_movimiento (Enum: COMPRA, VENTA, KIT_CONSUMO, AJUSTE, MERMA)
 *   - motivo          (String, nullable)
 *   - usuario_id      (Relación con Usuario)
 *   - fecha           (DateTime)
 *   - referencia_id   (UUID, nullable — Ej. ID de Venta o Entrada)
 */
class MovimientoInventario
{
    private $conn;

    public function __construct($db)
    {
        $this->conn = $db;
        $this->asegurarTabla();
    }

    // ─── DDL ────────────────────────────────────────────────────────────────

    private function asegurarTabla(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS movimientos_inventario (
            id               VARCHAR(36)   PRIMARY KEY,
            producto_id      VARCHAR(36)   NOT NULL,
            cantidad         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            tipo_movimiento  ENUM('COMPRA','VENTA','KIT_CONSUMO','AJUSTE','MERMA') NOT NULL,
            motivo           TEXT          NULL,
            usuario_id       VARCHAR(36)   NULL,
            fecha            DATETIME      DEFAULT CURRENT_TIMESTAMP,
            referencia_id    VARCHAR(36)   NULL,
            costo_unitario   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stock_antes      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stock_despues    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            INDEX (producto_id),
            INDEX (tipo_movimiento),
            INDEX (fecha),
            FOREIGN KEY (producto_id) REFERENCES productos(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        try {
            $this->conn->exec($sql);
        } catch (Exception $e) {
            error_log("MovimientoInventario::asegurarTabla — " . $e->getMessage());
        }
    }

    // ─── Helper UUID ────────────────────────────────────────────────────────

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // ─── Casos de Uso ───────────────────────────────────────────────────────

    /**
     * Registra un movimiento de inventario.
     *
     * @param array $datos {
     *   producto_id:     string  (requerido),
     *   cantidad:        float   (requerido),
     *   tipo_movimiento: string  COMPRA|VENTA|KIT_CONSUMO|AJUSTE|MERMA,
     *   motivo:          string  (nullable),
     *   usuario_id:      string  (nullable),
     *   referencia_id:   string  (nullable),
     *   costo_unitario:  float,
     *   stock_antes:     float,
     *   stock_despues:   float,
     * }
     */
    public function registrar(array $datos): bool
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO movimientos_inventario
                (id, producto_id, cantidad, tipo_movimiento, motivo, usuario_id, referencia_id,
                 costo_unitario, stock_antes, stock_despues)
             VALUES
                (:id, :producto_id, :cantidad, :tipo_movimiento, :motivo, :usuario_id, :referencia_id,
                 :costo_unitario, :stock_antes, :stock_despues)"
        );

        return $stmt->execute([
            ':id'              => $this->uuid(),
            ':producto_id'     => $datos['producto_id'],
            ':cantidad'        => floatval($datos['cantidad']        ?? 0),
            ':tipo_movimiento' => $datos['tipo_movimiento']          ?? 'AJUSTE',
            ':motivo'          => $datos['motivo']                   ?? null,
            ':usuario_id'      => $datos['usuario_id']               ?? null,
            ':referencia_id'   => $datos['referencia_id']            ?? null,
            ':costo_unitario'  => floatval($datos['costo_unitario']  ?? 0),
            ':stock_antes'     => floatval($datos['stock_antes']     ?? 0),
            ':stock_despues'   => floatval($datos['stock_despues']   ?? 0),
        ]);
    }

    /** Lista movimientos de un producto, orden descendente. */
    public function porProducto(string $productoId, int $limite = 100): array
    {
        $stmt = $this->conn->prepare(
            "SELECT m.*, p.descripcion AS producto_descripcion, p.codigo_interno_sku,
                    u.nombre AS usuario_nombre
             FROM movimientos_inventario m
             JOIN productos p ON p.id = m.producto_id
             LEFT JOIN usuarios u ON u.id = m.usuario_id
             WHERE m.producto_id = :producto_id
             ORDER BY m.fecha DESC, m.id DESC
             LIMIT :limite"
        );
        $stmt->bindValue(':producto_id', $productoId, PDO::PARAM_STR);
        $stmt->bindValue(':limite',      $limite,     PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Lista todos los movimientos (para historial global). */
    public function listarTodos(int $limite = 200, string $tipo = ''): array
    {
        $where = $tipo !== '' ? "WHERE m.tipo_movimiento = :tipo" : '';
        $stmt = $this->conn->prepare(
            "SELECT m.*, p.descripcion AS producto_descripcion, p.codigo_interno_sku,
                    u.nombre AS usuario_nombre
             FROM movimientos_inventario m
             JOIN productos p ON p.id = m.producto_id
             LEFT JOIN usuarios u ON u.id = m.usuario_id
             {$where}
             ORDER BY m.fecha DESC, m.id DESC
             LIMIT :limite"
        );
        if ($tipo !== '') {
            $stmt->bindValue(':tipo', $tipo, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
