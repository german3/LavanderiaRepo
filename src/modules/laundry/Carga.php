<?php

namespace App\Modules\Laundry;

use PDO;
use Exception;

/**
 * Modelo Carga — Spec 06-cargas-lavanderia.md
 *
 * Entidad: Carga
 *   - id                     (UUID)
 *   - numero_carga           (String, secuencial e.g. CRG-00001)
 *   - venta_id               (Relación con Venta)
 *   - cliente_id             (Relación con Cliente, nullable)
 *   - estado                 (Enum: RECIBIDA, LISTA_CONCLUIDA, ENTREGADA, CANCELADA)
 *   - estado_pago            (Enum: PENDIENTE, PAGADO)
 *   - total                  (Decimal)
 *   - ropa_kg                (Decimal)
 *   - fecha_recepcion        (DateTime)
 *   - fecha_conclusion       (DateTime, nullable)
 *   - fecha_entrega          (DateTime, nullable)
 *   - usuario_registro_id    (Relación con Usuario)
 *   - usuario_conclusion_id  (Relación con Usuario, nullable)
 *   - observaciones          (Text, nullable)
 */
class Carga
{
    private $conn;

    public function __construct($db)
    {
        $this->conn = $db;
        $this->asegurarTabla();
    }

    private function asegurarTabla(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS cargas (
            id                    VARCHAR(36) PRIMARY KEY,
            numero_carga          VARCHAR(50) NOT NULL UNIQUE,
            venta_id              VARCHAR(36) NOT NULL,
            cliente_id            VARCHAR(36) NULL,
            estado                ENUM('RECIBIDA', 'LISTA_CONCLUIDA', 'ENTREGADA', 'CANCELADA') NOT NULL DEFAULT 'RECIBIDA',
            estado_pago           ENUM('PENDIENTE', 'PAGADO') NOT NULL DEFAULT 'PAGADO',
            total                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            ropa_kg               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            fecha_recepcion       DATETIME DEFAULT CURRENT_TIMESTAMP,
            fecha_conclusion      DATETIME NULL,
            fecha_entrega         DATETIME NULL,
            usuario_registro_id   VARCHAR(36) NOT NULL,
            usuario_conclusion_id VARCHAR(36) NULL,
            observaciones         TEXT NULL,
            INDEX (venta_id),
            INDEX (cliente_id),
            INDEX (estado),
            INDEX (estado_pago),
            FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_registro_id) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        try {
            $this->conn->exec($sql);
        } catch (Exception $e) {
            error_log("Carga::asegurarTabla — " . $e->getMessage());
        }
    }

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

    public function generarNumeroCarga(): string
    {
        $stmt = $this->conn->query("SELECT COUNT(*) FROM cargas");
        $count = (int) $stmt->fetchColumn();
        return 'CRG-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Crea una carga asociada a una venta de servicios de lavandería
     */
    public function crear(array $datos): string
    {
        $id = $this->uuid();
        $numeroCarga = $this->generarNumeroCarga();

        $stmt = $this->conn->prepare(
            "INSERT INTO cargas (
                id, numero_carga, venta_id, cliente_id, estado, estado_pago,
                total, ropa_kg, fecha_recepcion, usuario_registro_id, observaciones
            ) VALUES (
                :id, :numero_carga, :venta_id, :cliente_id, :estado, :estado_pago,
                :total, :ropa_kg, NOW(), :usuario_registro_id, :observaciones
            )"
        );

        $stmt->execute([
            ':id'                  => $id,
            ':numero_carga'        => $numeroCarga,
            ':venta_id'            => $datos['venta_id'],
            ':cliente_id'          => $datos['cliente_id'] ?? null,
            ':estado'              => $datos['estado'] ?? 'RECIBIDA',
            ':estado_pago'         => $datos['estado_pago'] ?? 'PAGADO',
            ':total'               => floatval($datos['total'] ?? 0),
            ':ropa_kg'             => floatval($datos['ropa_kg'] ?? 0),
            ':usuario_registro_id' => $datos['usuario_registro_id'],
            ':observaciones'       => $datos['observaciones'] ?? null,
        ]);

        return $numeroCarga;
    }

    public function actualizarEstadoPago(string $ventaId, string $nuevoEstado): bool
    {
        $stmt = $this->conn->prepare("UPDATE cargas SET estado_pago = :estado_pago WHERE venta_id = :venta_id");
        return $stmt->execute([
            ':estado_pago' => $nuevoEstado,
            ':venta_id'    => $ventaId
        ]);
    }

    public function cancelarPorVenta(string $ventaId): bool
    {
        $stmt = $this->conn->prepare("UPDATE cargas SET estado = 'CANCELADA' WHERE venta_id = :venta_id");
        return $stmt->execute([':venta_id' => $ventaId]);
    }
}
