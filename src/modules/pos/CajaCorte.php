<?php

namespace App\Modules\Pos;

use PDO;
use Exception;

/**
 * Modelo CajaCorte — Spec 05-punto-venta-caja.md (Fase 13)
 *
 * Entidad: CajaCorte
 *   - id                   (UUID)
 *   - usuario_id           (Relación con Usuario)
 *   - fecha_apertura       (DateTime)
 *   - monto_inicial        (Decimal)
 *   - fecha_cierre         (DateTime, nullable)
 *   - total_efectivo       (Decimal)
 *   - total_tarjeta        (Decimal)
 *   - total_transferencia  (Decimal)
 *   - efectivo_declarado   (Decimal)
 *   - diferencia           (Decimal)
 *   - observaciones        (Text, nullable)
 *   - estado               (Enum: ABIERTA, CERRADA)
 */
class CajaCorte
{
    private $conn;

    public function __construct($db)
    {
        $this->conn = $db;
        $this->asegurarTabla();
    }

    private function asegurarTabla(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS caja_cortes (
            id                  VARCHAR(36) PRIMARY KEY,
            usuario_id          VARCHAR(36) NOT NULL,
            fecha_apertura      DATETIME DEFAULT CURRENT_TIMESTAMP,
            monto_inicial       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            fecha_cierre        DATETIME NULL,
            total_efectivo      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_tarjeta       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_transferencia DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            efectivo_declarado  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            diferencia          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            observaciones       TEXT NULL,
            estado              ENUM('ABIERTA', 'CERRADA') NOT NULL DEFAULT 'ABIERTA',
            INDEX (usuario_id),
            INDEX (estado),
            INDEX (fecha_apertura),
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        try {
            $this->conn->exec($sql);
        } catch (Exception $e) {
            error_log("CajaCorte::asegurarTabla — " . $e->getMessage());
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

    /**
     * Obtiene la caja abierta actual (del usuario o global)
     */
    public function obtenerCajaAbierta(?string $usuarioId = null): ?array
    {
        $sql = "SELECT c.*, u.nombre as usuario_nombre 
                FROM caja_cortes c
                JOIN usuarios u ON c.usuario_id = u.id
                WHERE c.estado = 'ABIERTA'";
        
        $params = [];
        if ($usuarioId) {
            $sql .= " AND c.usuario_id = :usuario_id";
            $params[':usuario_id'] = $usuarioId;
        }
        $sql .= " ORDER BY c.fecha_apertura DESC LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Abre un nuevo turno de caja
     */
    public function abrirCaja(string $usuarioId, float $montoInicial, ?string $observaciones = null): array
    {
        // Verificar si ya existe una caja abierta para este usuario
        $existente = $this->obtenerCajaAbierta($usuarioId);
        if ($existente) {
            return [
                'success' => false,
                'message' => 'Ya existe un turno de caja abierto para este usuario.',
                'caja'    => $existente
            ];
        }

        $id = $this->uuid();
        $stmt = $this->conn->prepare(
            "INSERT INTO caja_cortes (id, usuario_id, fecha_apertura, monto_inicial, observaciones, estado)
             VALUES (:id, :usuario_id, NOW(), :monto_inicial, :observaciones, 'ABIERTA')"
        );

        $stmt->execute([
            ':id'            => $id,
            ':usuario_id'    => $usuarioId,
            ':monto_inicial' => $montoInicial,
            ':observaciones' => $observaciones
        ]);

        return [
            'success' => true,
            'message' => 'Caja abierta correctamente con un fondo de $' . number_format($montoInicial, 2),
            'caja_id' => $id
        ];
    }

    /**
     * Calcula resumen de ventas y cobros del turno activo
     */
    public function obtenerResumenTurno(string $cajaId): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM caja_cortes WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $cajaId]);
        $caja = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$caja) {
            return ['success' => false, 'message' => 'Caja no encontrada'];
        }

        // Totales por método de pago de ventas ACTIVAS pertenecientes a esta caja (o fecha de apertura)
        $sqlVentas = "SELECT 
                        COALESCE(SUM(CASE WHEN metodo_pago = 'EFECTIVO' AND estado_pago = 'PAGADO' THEN total ELSE 0 END), 0) AS total_efectivo,
                        COALESCE(SUM(CASE WHEN metodo_pago = 'TARJETA' AND estado_pago = 'PAGADO' THEN total ELSE 0 END), 0) AS total_tarjeta,
                        COALESCE(SUM(CASE WHEN metodo_pago = 'TRANSFERENCIA' AND estado_pago = 'PAGADO' THEN total ELSE 0 END), 0) AS total_transferencia,
                        COALESCE(SUM(CASE WHEN estado_pago = 'PENDIENTE' THEN total ELSE 0 END), 0) AS total_pendiente,
                        COALESCE(COUNT(CASE WHEN estado = 'ACTIVA' THEN 1 END), 0) AS cantidad_ventas,
                        COALESCE(COUNT(CASE WHEN estado = 'CANCELADA' THEN 1 END), 0) AS cantidad_canceladas
                      FROM ventas 
                      WHERE (caja_id = :caja_id OR (caja_id IS NULL AND fecha_venta >= :fecha_apertura))
                        AND estado = 'ACTIVA'";

        $stmtVentas = $this->conn->prepare($sqlVentas);
        $stmtVentas->execute([
            ':caja_id'        => $cajaId,
            ':fecha_apertura' => $caja['fecha_apertura']
        ]);
        $resumen = $stmtVentas->fetch(PDO::FETCH_ASSOC);

        $montoInicial      = floatval($caja['monto_inicial']);
        $totalEfectivo     = floatval($resumen['total_efectivo']);
        $totalTarjeta      = floatval($resumen['total_tarjeta']);
        $totalTransf       = floatval($resumen['total_transferencia']);
        $totalPendiente    = floatval($resumen['total_pendiente']);
        $totalVentasPagadas = $totalEfectivo + $totalTarjeta + $totalTransf;
        $totalEsperadoEfectivo = $montoInicial + $totalEfectivo;

        return [
            'success'                => true,
            'caja'                   => $caja,
            'monto_inicial'          => $montoInicial,
            'total_efectivo'         => $totalEfectivo,
            'total_tarjeta'          => $totalTarjeta,
            'total_transferencia'    => $totalTransf,
            'total_pendiente'        => $totalPendiente,
            'total_ventas_pagadas'   => $totalVentasPagadas,
            'total_esperado_efectivo'=> $totalEsperadoEfectivo,
            'cantidad_ventas'        => (int) $resumen['cantidad_ventas'],
            'cantidad_canceladas'    => (int) $resumen['cantidad_canceladas']
        ];
    }

    /**
     * Cierra el turno de caja y calcula diferencia
     */
    public function cerrarCaja(string $cajaId, float $efectivoDeclarado, ?string $observaciones = null): array
    {
        $resumen = $this->obtenerResumenTurno($cajaId);
        if (!$resumen['success']) {
            return $resumen;
        }

        $totalEfectivo    = $resumen['total_efectivo'];
        $totalTarjeta     = $resumen['total_tarjeta'];
        $totalTransf      = $resumen['total_transferencia'];
        $esperadoEfectivo = $resumen['total_esperado_efectivo'];
        $diferencia       = $efectivoDeclarado - $esperadoEfectivo;

        $stmt = $this->conn->prepare(
            "UPDATE caja_cortes SET 
                fecha_cierre        = NOW(),
                total_efectivo      = :total_efectivo,
                total_tarjeta       = :total_tarjeta,
                total_transferencia = :total_transferencia,
                efectivo_declarado  = :efectivo_declarado,
                diferencia          = :diferencia,
                observaciones       = :observaciones,
                estado              = 'CERRADA'
             WHERE id = :id AND estado = 'ABIERTA'"
        );

        $stmt->execute([
            ':total_efectivo'      => $totalEfectivo,
            ':total_tarjeta'       => $totalTarjeta,
            ':total_transferencia' => $totalTransf,
            ':efectivo_declarado'  => $efectivoDeclarado,
            ':diferencia'          => $diferencia,
            ':observaciones'       => $observaciones,
            ':id'                  => $cajaId
        ]);

        return [
            'success'            => true,
            'message'            => 'Caja cerrada exitosamente.',
            'diferencia'         => $diferencia,
            'monto_inicial'      => $resumen['monto_inicial'],
            'total_efectivo'     => $totalEfectivo,
            'total_tarjeta'      => $totalTarjeta,
            'total_transferencia'=> $totalTransf,
            'efectivo_declarado' => $efectivoDeclarado,
            'esperado_efectivo'  => $esperadoEfectivo
        ];
    }
}
