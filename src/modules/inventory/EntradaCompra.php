<?php

namespace App\Modules\Inventory;

use PDO;
use Exception;

/**
 * Modelo EntradaCompra — Spec 04-inventario-compras.md
 *
 * Entidad: EntradaCompra
 *   - id         (UUID)
 *   - folio      (String — generado automáticamente)
 *   - proveedor  (String)
 *   - fecha      (DateTime)
 *   - usuario_id (Relación con Usuario)
 *   - importe    (Decimal — suma de costos)
 *   - total      (Decimal — total final)
 *
 * Entidad: DetalleEntrada
 *   - entrada_id     (Relación con EntradaCompra)
 *   - producto_id    (Relación con Producto)
 *   - cantidad       (Decimal)
 *   - costo_unitario (Decimal)
 */
class EntradaCompra
{
    private $conn;

    public function __construct($db)
    {
        $this->conn = $db;
        $this->asegurarTablas();
    }

    // ─── DDL ────────────────────────────────────────────────────────────────

    private function asegurarTablas(): void
    {
        $sentences = [
            "CREATE TABLE IF NOT EXISTS entradas_compras (
                id          VARCHAR(36)   PRIMARY KEY,
                folio       VARCHAR(50)   NOT NULL UNIQUE,
                proveedor   VARCHAR(255)  NOT NULL,
                fecha       DATETIME      DEFAULT CURRENT_TIMESTAMP,
                usuario_id  VARCHAR(36)   NOT NULL,
                importe     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS detalle_entradas (
                id              VARCHAR(36)   PRIMARY KEY,
                entrada_id      VARCHAR(36)   NOT NULL,
                producto_id     VARCHAR(36)   NOT NULL,
                cantidad        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                costo_unitario  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                FOREIGN KEY (entrada_id)  REFERENCES entradas_compras(id) ON DELETE CASCADE,
                FOREIGN KEY (producto_id) REFERENCES productos(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];

        foreach ($sentences as $sql) {
            try {
                $this->conn->exec($sql);
            } catch (Exception $e) {
                error_log("EntradaCompra::asegurarTablas — " . $e->getMessage());
            }
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

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

    private function generarFolio(): string
    {
        $stmt = $this->conn->query("SELECT COUNT(*) FROM entradas_compras");
        $count = (int) $stmt->fetchColumn();
        return 'EC-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);
    }

    // ─── Caso de Uso: RegistrarEntradaCompra ────────────────────────────────

    /**
     * Registra una entrada de compra completa.
     * - Inserta cabecera EntradaCompra con folio y proveedor
     * - Inserta DetalleEntrada por cada ítem
     * - Incrementa stock: stock_cantidad + cantidad (Regla de negocio §2)
     * - Registra MovimientoInventario tipo COMPRA por cada ítem
     *
     * @param array $datos {
     *   proveedor:  string,
     *   usuario_id: string,
     *   items: array[ { producto_id, cantidad, costo_unitario } ]
     * }
     * @return array { ok: bool, folio?: string, entrada_id?: string, error?: string }
     */
    public function registrarEntrada(array $datos): array
    {
        try {
            $this->conn->beginTransaction();

            $proveedor  = trim($datos['proveedor'] ?? '');
            $usuarioId  = $datos['usuario_id'] ?? null;
            $items      = $datos['items'] ?? [];

            if (empty($proveedor)) {
                $this->conn->rollBack();
                return ['ok' => false, 'error' => 'El nombre del proveedor es obligatorio.'];
            }
            if (empty($items)) {
                $this->conn->rollBack();
                return ['ok' => false, 'error' => 'Debe agregar al menos un producto a la entrada.'];
            }

            $entradaId = $this->uuid();
            $folio     = $this->generarFolio();

            // Calcular importe / total
            $importe = 0.0;
            foreach ($items as $item) {
                $importe += floatval($item['cantidad']) * floatval($item['costo_unitario']);
            }
            $total = $importe; // extendible con IVA u otros cargos

            // Insertar cabecera
            $stmtEC = $this->conn->prepare(
                "INSERT INTO entradas_compras (id, folio, proveedor, usuario_id, importe, total)
                 VALUES (:id, :folio, :proveedor, :usuario_id, :importe, :total)"
            );
            $stmtEC->execute([
                ':id'         => $entradaId,
                ':folio'      => $folio,
                ':proveedor'  => $proveedor,
                ':usuario_id' => $usuarioId,
                ':importe'    => $importe,
                ':total'      => $total,
            ]);

            $stmtDet  = $this->conn->prepare(
                "INSERT INTO detalle_entradas (id, entrada_id, producto_id, cantidad, costo_unitario)
                 VALUES (:id, :entrada_id, :producto_id, :cantidad, :costo_unitario)"
            );
            $stmtStock = $this->conn->prepare(
                "UPDATE productos
                 SET stock_cantidad = stock_cantidad + :cant,
                     costo          = :costo
                 WHERE id = :id"
            );
            $stmtStockAntes = $this->conn->prepare(
                "SELECT stock_cantidad FROM productos WHERE id = :id"
            );
            $movModel = new MovimientoInventario($this->conn);

            foreach ($items as $item) {
                $productoId    = $item['producto_id'];
                $cantidad      = floatval($item['cantidad']);
                $costoUnitario = floatval($item['costo_unitario']);

                // Stock antes del movimiento
                $stmtStockAntes->execute([':id' => $productoId]);
                $stockAntes = floatval($stmtStockAntes->fetchColumn() ?? 0);

                // DetalleEntrada
                $stmtDet->execute([
                    ':id'             => $this->uuid(),
                    ':entrada_id'     => $entradaId,
                    ':producto_id'    => $productoId,
                    ':cantidad'       => $cantidad,
                    ':costo_unitario' => $costoUnitario,
                ]);

                // Actualizar stock + costo en productos (Regla §2 — Entradas)
                $stmtStock->execute([
                    ':cant'  => $cantidad,
                    ':costo' => $costoUnitario,
                    ':id'    => $productoId,
                ]);

                // MovimientoInventario tipo COMPRA
                $movModel->registrar([
                    'producto_id'     => $productoId,
                    'cantidad'        => $cantidad,
                    'tipo_movimiento' => 'COMPRA',
                    'motivo'          => "Compra — Folio {$folio} | Proveedor: {$proveedor}",
                    'usuario_id'      => $usuarioId,
                    'referencia_id'   => $entradaId,
                    'costo_unitario'  => $costoUnitario,
                    'stock_antes'     => $stockAntes,
                    'stock_despues'   => $stockAntes + $cantidad,
                ]);
            }

            $this->conn->commit();
            return ['ok' => true, 'folio' => $folio, 'entrada_id' => $entradaId];

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("EntradaCompra::registrarEntrada — " . $e->getMessage());
            return ['ok' => false, 'error' => 'Error al registrar entrada: ' . $e->getMessage()];
        }
    }

    // ─── Consultas ──────────────────────────────────────────────────────────

    /** Lista todas las entradas más recientes primero. */
    public function listarEntradas(int $limite = 50): array
    {
        $stmt = $this->conn->prepare(
            "SELECT ec.*, u.nombre AS usuario_nombre
             FROM entradas_compras ec
             JOIN usuarios u ON u.id = ec.usuario_id
             ORDER BY ec.fecha DESC
             LIMIT :limite"
        );
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Detalle completo de una entrada con sus ítems. */
    public function obtenerDetalle(string $entradaId): array
    {
        $stmtEC = $this->conn->prepare(
            "SELECT ec.*, u.nombre AS usuario_nombre
             FROM entradas_compras ec
             JOIN usuarios u ON u.id = ec.usuario_id
             WHERE ec.id = :id"
        );
        $stmtEC->execute([':id' => $entradaId]);
        $cabecera = $stmtEC->fetch(PDO::FETCH_ASSOC);
        if (!$cabecera) return [];

        $stmtDet = $this->conn->prepare(
            "SELECT de.*, p.descripcion AS producto_descripcion,
                    p.unidad_medida, p.codigo_interno_sku
             FROM detalle_entradas de
             JOIN productos p ON p.id = de.producto_id
             WHERE de.entrada_id = :entrada_id"
        );
        $stmtDet->execute([':entrada_id' => $entradaId]);
        $cabecera['items'] = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
        return $cabecera;
    }
}
