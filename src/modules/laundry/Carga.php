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
        $sqlConFk = "CREATE TABLE IF NOT EXISTS cargas (
            id                    VARCHAR(36) PRIMARY KEY,
            numero_carga          VARCHAR(50) NOT NULL UNIQUE,
            venta_id              VARCHAR(36) NOT NULL,
            cliente_id            VARCHAR(36) NULL,
            estado                ENUM('RECIBIDA','EN_PROCESO','LISTA_CONCLUIDA','ENTREGADA','CANCELADA') NOT NULL DEFAULT 'RECIBIDA',
            estado_pago           ENUM('PENDIENTE', 'PAGADO') NOT NULL DEFAULT 'PAGADO',
            total                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            ropa_kg               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            fecha_recepcion       DATETIME DEFAULT CURRENT_TIMESTAMP,
            fecha_inicio_lavado   DATETIME NULL,
            fecha_conclusion      DATETIME NULL,
            fecha_entrega         DATETIME NULL,
            usuario_registro_id   VARCHAR(36) NOT NULL,
            usuario_inicio_lavado_id VARCHAR(36) NULL,
            usuario_conclusion_id VARCHAR(36) NULL,
            observaciones         TEXT NULL,
            INDEX (venta_id),
            INDEX (cliente_id),
            INDEX (estado),
            INDEX (estado_pago),
            FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_registro_id) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $sqlSinFk = "CREATE TABLE IF NOT EXISTS cargas (
            id                    VARCHAR(36) PRIMARY KEY,
            numero_carga          VARCHAR(50) NOT NULL UNIQUE,
            venta_id              VARCHAR(36) NOT NULL,
            cliente_id            VARCHAR(36) NULL,
            estado                ENUM('RECIBIDA','EN_PROCESO','LISTA_CONCLUIDA','ENTREGADA','CANCELADA') NOT NULL DEFAULT 'RECIBIDA',
            estado_pago           ENUM('PENDIENTE', 'PAGADO') NOT NULL DEFAULT 'PAGADO',
            total                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            ropa_kg               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            fecha_recepcion       DATETIME DEFAULT CURRENT_TIMESTAMP,
            fecha_inicio_lavado   DATETIME NULL,
            fecha_conclusion      DATETIME NULL,
            fecha_entrega         DATETIME NULL,
            usuario_registro_id   VARCHAR(36) NOT NULL,
            usuario_inicio_lavado_id VARCHAR(36) NULL,
            usuario_conclusion_id VARCHAR(36) NULL,
            observaciones         TEXT NULL,
            INDEX (venta_id),
            INDEX (cliente_id),
            INDEX (estado),
            INDEX (estado_pago)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        try {
            $this->conn->exec($sqlConFk);
        } catch (\Throwable $e) {
            error_log("Carga::asegurarTabla (con FK falló: " . $e->getMessage() . ") — intentando sin FK...");
            try {
                $this->conn->exec($sqlSinFk);
            } catch (\Throwable $e2) {
                error_log("Carga::asegurarTabla (sin FK falló): " . $e2->getMessage());
            }
        }

        // Migraciones incrementales
        $migraciones = [
            "ALTER TABLE cargas MODIFY COLUMN estado ENUM('RECIBIDA','EN_PROCESO','LISTA_CONCLUIDA','ENTREGADA','CANCELADA') NOT NULL DEFAULT 'RECIBIDA'",
            "ALTER TABLE cargas ADD COLUMN fecha_inicio_lavado DATETIME NULL AFTER fecha_recepcion",
            "ALTER TABLE cargas ADD COLUMN usuario_inicio_lavado_id VARCHAR(36) NULL AFTER usuario_registro_id",
        ];
        foreach ($migraciones as $m) {
            try { $this->conn->exec($m); } catch (\Throwable $e) { /* ya existe */ }
        }

        try {
            $this->conn->exec("ALTER TABLE cargas CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            // Silencioso
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

    // ─── Métodos de flujo de lavado ────────────────────────────

    /**
     * Retorna cargas activas agrupables para el panel de lavado:
     * estados RECIBIDA (Espera) y EN_PROCESO.
     */
    public function obtenerCargasActivas(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT cg.*, 
                    c.nombre AS cliente_nombre, 
                    c.telefono AS cliente_telefono,
                    u.nombre AS cajero_nombre,
                    ui.nombre AS inicio_lavado_por
             FROM cargas cg
             LEFT JOIN clientes c ON cg.cliente_id = c.id
             LEFT JOIN usuarios u ON cg.usuario_registro_id = u.id
             LEFT JOIN usuarios ui ON cg.usuario_inicio_lavado_id = ui.id
             WHERE cg.estado IN ('RECIBIDA', 'EN_PROCESO')
             ORDER BY cg.fecha_recepcion ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Cambia estado de RECIBIDA → EN_PROCESO (Inicio de Lavado).
     */
    public function iniciarLavado(string $cargaId, string $usuarioId): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE cargas 
             SET estado = 'EN_PROCESO', 
                 fecha_inicio_lavado = NOW(),
                 usuario_inicio_lavado_id = :uid
             WHERE id = :id AND estado = 'RECIBIDA'"
        );
        $stmt->execute(['id' => $cargaId, ':uid' => $usuarioId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cambia estado de EN_PROCESO → LISTA_CONCLUIDA (Finalizado).
     */
    public function finalizarCarga(string $cargaId, string $usuarioId): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE cargas 
             SET estado = 'LISTA_CONCLUIDA',
                 fecha_conclusion = NOW(),
                 usuario_conclusion_id = :uid
             WHERE id = :id AND estado = 'EN_PROCESO'"
        );
        $stmt->execute(['id' => $cargaId, ':uid' => $usuarioId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Busca una carga por su ID.
     */
    public function obtenerPorId(string $cargaId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT cg.*, c.nombre AS cliente_nombre, c.telefono AS cliente_telefono
             FROM cargas cg
             LEFT JOIN clientes c ON cg.cliente_id = c.id
             WHERE cg.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $cargaId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }


    /**
     * Cambia estado de LISTA_CONCLUIDA → ENTREGADA (Entrega de ropa al cliente).
     */
    public function entregarCarga(string $cargaId, string $usuarioId): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE cargas 
             SET estado = 'ENTREGADA',
                 fecha_entrega = NOW()
             WHERE id = :id AND estado = 'LISTA_CONCLUIDA'"
        );
        $stmt->execute([':id' => $cargaId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Retorna cargas entregadas recientemente.
     */
    public function obtenerCargasEntregadas(int $limite = 50): array
    {
        $stmt = $this->conn->prepare(
            "SELECT cg.*, 
                    c.nombre AS cliente_nombre, 
                    c.telefono AS cliente_telefono,
                    u.nombre AS cajero_nombre,
                    v.folio AS venta_folio
             FROM cargas cg
             LEFT JOIN clientes c ON cg.cliente_id = c.id
             LEFT JOIN usuarios u ON cg.usuario_registro_id = u.id
             LEFT JOIN ventas v ON cg.venta_id = v.id
             WHERE cg.estado = 'ENTREGADA'
             ORDER BY cg.fecha_entrega DESC
             LIMIT :limite"
        );
        $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

}
