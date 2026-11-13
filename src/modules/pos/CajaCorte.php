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
        $sqlConFk = "CREATE TABLE IF NOT EXISTS caja_cortes (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $sqlSinFk = "CREATE TABLE IF NOT EXISTS caja_cortes (
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
            INDEX (fecha_apertura)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        try {
            $this->conn->exec($sqlConFk);
        } catch (\Throwable $e) {
            error_log("CajaCorte::asegurarTabla (con FK falló: " . $e->getMessage() . ") — intentando crear sin FK...");
            try {
                $this->conn->exec($sqlSinFk);
            } catch (\Throwable $e2) {
                error_log("CajaCorte::asegurarTabla (sin FK también falló): " . $e2->getMessage());
            }
        }

        // Homogeneizar collation para evitar error 1267 (Illegal mix of collations)
        try {
            $this->conn->exec("ALTER TABLE caja_cortes CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            // Ignorar si no se tienen permisos ALTER
        }

        // Migraciones para control de cargas no entregadas, cobros pendientes y trazabilidad de usuario
        $migraciones = [
            "ALTER TABLE caja_cortes ADD COLUMN usuario_cierre_id VARCHAR(36) NULL AFTER usuario_id",
            "ALTER TABLE caja_cortes ADD COLUMN cargas_pendientes_entrega INT NOT NULL DEFAULT 0",
            "ALTER TABLE caja_cortes ADD COLUMN cargas_pendientes_pago INT NOT NULL DEFAULT 0",
            "ALTER TABLE caja_cortes ADD COLUMN monto_pendiente_cobro DECIMAL(12,2) NOT NULL DEFAULT 0.00",
            "ALTER TABLE caja_cortes ADD COLUMN cargas_pendientes_detalle LONGTEXT NULL",
            "ALTER TABLE caja_cortes ADD COLUMN cargas_recibidas_entrega INT NOT NULL DEFAULT 0",
            "ALTER TABLE caja_cortes ADD COLUMN cargas_recibidas_pago INT NOT NULL DEFAULT 0",
            "ALTER TABLE caja_cortes ADD COLUMN monto_recibido_pendiente DECIMAL(12,2) NOT NULL DEFAULT 0.00",
            "ALTER TABLE caja_cortes ADD COLUMN total_cobros_turnos_anteriores DECIMAL(12,2) NOT NULL DEFAULT 0.00"
        ];
        foreach ($migraciones as $m) {
            try { $this->conn->exec($m); } catch (\Throwable $e) { /* ya existe */ }
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
     * Obtiene el inventario actual de cargas en custodia pendientes de entrega y/o cobro
     */
    public function obtenerCargasPendientes(?string $cajaId = null, ?string $fechaApertura = null): array
    {
        $sql = "SELECT 
                    cg.id,
                    cg.numero_carga,
                    cg.venta_id,
                    cg.cliente_id,
                    cg.estado,
                    cg.estado_pago,
                    cg.total,
                    cg.ropa_kg,
                    cg.fecha_recepcion,
                    cg.fecha_inicio_lavado,
                    cg.fecha_conclusion,
                    cg.observaciones,
                    COALESCE(c.nombre, 'Público General') AS cliente_nombre,
                    c.telefono AS cliente_telefono,
                    v.folio AS venta_folio,
                    v.metodo_pago AS venta_metodo_pago,
                    v.estado AS venta_estado,
                    v.estado_pago AS venta_estado_pago
                FROM cargas cg
                LEFT JOIN clientes c ON cg.cliente_id COLLATE utf8mb4_unicode_ci = c.id COLLATE utf8mb4_unicode_ci
                LEFT JOIN ventas v ON cg.venta_id COLLATE utf8mb4_unicode_ci = v.id COLLATE utf8mb4_unicode_ci
                WHERE cg.estado IN ('RECIBIDA', 'EN_PROCESO', 'LISTA_CONCLUIDA')
                  AND (v.estado IS NULL OR v.estado != 'CANCELADA')";

        $params = [];
        if ($cajaId) {
            $sql .= " AND (v.caja_id = :caja_id OR (v.caja_id IS NULL AND cg.fecha_recepcion >= :fecha_apertura))";
            $params[':caja_id'] = $cajaId;
            $params[':fecha_apertura'] = $fechaApertura ?: '1970-01-01 00:00:00';
        }

        $sql .= " ORDER BY cg.fecha_recepcion ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $totalEntrega = count($items);
        $totalPendientePago = 0;
        $montoPendienteCobro = 0.0;
        $totalPagadasNoEntregadas = 0;
        $montoPagadasNoEntregadas = 0.0;

        $porEstado = [
            'espera'     => 0,
            'proceso'    => 0,
            'finalizado' => 0
        ];

        foreach ($items as $item) {
            $total = floatval($item['total'] ?? 0);
            $isPendiente = ($item['estado_pago'] === 'PENDIENTE' || ($item['venta_estado_pago'] ?? '') === 'PENDIENTE');
            if ($isPendiente) {
                $totalPendientePago++;
                $montoPendienteCobro += $total;
            } else {
                $totalPagadasNoEntregadas++;
                $montoPagadasNoEntregadas += $total;
            }

            if ($item['estado'] === 'RECIBIDA') {
                $porEstado['espera']++;
            } elseif ($item['estado'] === 'EN_PROCESO') {
                $porEstado['proceso']++;
            } elseif ($item['estado'] === 'LISTA_CONCLUIDA') {
                $porEstado['finalizado']++;
            }
        }

        return [
            'total_pendientes_entrega'    => $totalEntrega,
            'total_pendientes_pago'       => $totalPendientePago,
            'monto_pendiente_cobro'       => round($montoPendienteCobro, 2),
            'total_pagadas_no_entregadas' => $totalPagadasNoEntregadas,
            'monto_pagadas_no_entregadas' => round($montoPagadasNoEntregadas, 2),
            'por_estado'                  => $porEstado,
            'items'                       => $items
        ];
    }

    /**
     * Obtiene los cobros realizados durante este turno que corresponden a ventas/cargas de turnos anteriores
     */
    public function obtenerCobrosTurnosAnteriores(string $cajaId, string $fechaApertura, ?string $fechaCierre = null): array
    {
        $sql = "SELECT 
                    v.id,
                    v.folio,
                    v.total,
                    v.metodo_pago,
                    v.fecha_venta,
                    v.fecha_pago,
                    COALESCE(c.nombre, 'Público General') AS cliente_nombre,
                    c.telefono AS cliente_telefono,
                    cg.numero_carga
                FROM ventas v
                LEFT JOIN clientes c ON v.cliente_id = c.id
                LEFT JOIN cargas cg ON cg.venta_id = v.id
                WHERE (
                    v.caja_cobro_id = :caja_id
                    OR (
                        (v.caja_id != :caja_id_diff OR v.caja_id IS NULL)
                        AND v.fecha_pago >= :fecha_apertura
                        AND (:fecha_cierre IS NULL OR v.fecha_pago <= :fecha_cierre_2)
                    )
                )
                AND v.estado = 'ACTIVA'
                AND v.estado_pago = 'PAGADO'
                AND (v.caja_id IS NULL OR v.caja_id != :caja_id_mismo)
                ORDER BY v.fecha_pago ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':caja_id'        => $cajaId,
            ':caja_id_diff'   => $cajaId,
            ':fecha_apertura' => $fechaApertura,
            ':fecha_cierre'   => $fechaCierre,
            ':fecha_cierre_2' => $fechaCierre,
            ':caja_id_mismo'  => $cajaId
        ]);
        $items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $totEfectivo = 0.0;
        $totTarjeta = 0.0;
        $totTransf = 0.0;

        foreach ($items as $item) {
            $monto = floatval($item['total']);
            if ($item['metodo_pago'] === 'EFECTIVO') {
                $totEfectivo += $monto;
            } elseif ($item['metodo_pago'] === 'TARJETA') {
                $totTarjeta += $monto;
            } elseif ($item['metodo_pago'] === 'TRANSFERENCIA') {
                $totTransf += $monto;
            }
        }

        return [
            'total_efectivo'      => round($totEfectivo, 2),
            'total_tarjeta'       => round($totTarjeta, 2),
            'total_transferencia' => round($totTransf, 2),
            'total_general'       => round($totEfectivo + $totTarjeta + $totTransf, 2),
            'cantidad'            => count($items),
            'items'               => $items
        ];
    }

    /**
     * Obtiene los datos del último turno de caja cerrado con los usuarios de apertura y cierre
     */
    public function obtenerUltimoCorte(): ?array
    {
        try {
            $sql = "SELECT c.*, 
                           uA.nombre as usuario_nombre,
                           uA.nombre as usuario_apertura_nombre,
                           uC.nombre as usuario_cierre_nombre
                    FROM caja_cortes c
                    LEFT JOIN usuarios uA ON c.usuario_id COLLATE utf8mb4_unicode_ci = uA.id COLLATE utf8mb4_unicode_ci
                    LEFT JOIN usuarios uC ON c.usuario_cierre_id COLLATE utf8mb4_unicode_ci = uC.id COLLATE utf8mb4_unicode_ci
                    WHERE c.estado = 'CERRADA'
                    ORDER BY c.fecha_cierre DESC LIMIT 1";
            $stmt = $this->conn->query($sql);
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Obtiene la caja abierta actual (del usuario o global del establecimiento)
     */
    public function obtenerCajaAbierta(?string $usuarioId = null): ?array
    {
        $sql = "SELECT c.*, 
                       u.nombre as usuario_nombre,
                       u.nombre as usuario_apertura_nombre
                FROM caja_cortes c
                LEFT JOIN usuarios u ON c.usuario_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
                WHERE c.estado = 'ABIERTA'";
        
        $params = [];
        if ($usuarioId) {
            $sql .= " AND c.usuario_id = :usuario_id";
            $params[':usuario_id'] = $usuarioId;
        }
        $sql .= " ORDER BY c.fecha_apertura DESC LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

        return $row ?: null;
    }

    /**
     * Abre un nuevo turno de caja registrando al usuario autenticado y la custodia de prendas
     */
    public function abrirCaja(string $usuarioId, float $montoInicial, ?string $observaciones = null): array
    {
        if (empty($usuarioId)) {
            return [
                'success' => false,
                'message' => 'El usuario que inicia el turno es requerido.'
            ];
        }

        // Verificar si ya existe una caja abierta en el sistema
        $existente = $this->obtenerCajaAbierta(null);
        if ($existente) {
            return [
                'success' => false,
                'message' => 'Ya existe un turno de caja abierto en el sistema (iniciado por ' . ($existente['usuario_nombre'] ?? 'otro cajero') . '). Debe realizar el corte y cierre de ese turno antes de abrir uno nuevo.',
                'caja'    => $existente
            ];
        }

        // Obtener inventario actual de cargas y pagos pendientes que recibe este nuevo turno
        $custodia = $this->obtenerCargasPendientes();

        $id = $this->uuid();
        $stmt = $this->conn->prepare(
            "INSERT INTO caja_cortes (
                id, usuario_id, fecha_apertura, monto_inicial, observaciones, estado,
                cargas_recibidas_entrega, cargas_recibidas_pago, monto_recibido_pendiente
            ) VALUES (
                :id, :usuario_id, NOW(), :monto_inicial, :observaciones, 'ABIERTA',
                :cargas_recibidas_entrega, :cargas_recibidas_pago, :monto_recibido_pendiente
            )"
        );

        $stmt->execute([
            ':id'                       => $id,
            ':usuario_id'               => $usuarioId,
            ':monto_inicial'            => $montoInicial,
            ':observaciones'            => $observaciones,
            ':cargas_recibidas_entrega' => $custodia['total_pendientes_entrega'],
            ':cargas_recibidas_pago'    => $custodia['total_pendientes_pago'],
            ':monto_recibido_pendiente' => $custodia['monto_pendiente_cobro']
        ]);

        return [
            'success'               => true,
            'message'               => 'Caja abierta correctamente con un fondo de $' . number_format($montoInicial, 2),
            'caja_id'               => $id,
            'custodia_recibida'     => $custodia
        ];
    }

    /**
     * Calcula resumen de ventas, cobros y custodia del turno activo
     */
    public function obtenerResumenTurno(string $cajaId): array
    {
        $sqlCaja = "SELECT c.*, 
                           uA.nombre AS usuario_nombre,
                           uA.nombre AS usuario_apertura_nombre,
                           uC.nombre AS usuario_cierre_nombre
                    FROM caja_cortes c
                    LEFT JOIN usuarios uA ON c.usuario_id COLLATE utf8mb4_unicode_ci = uA.id COLLATE utf8mb4_unicode_ci
                    LEFT JOIN usuarios uC ON c.usuario_cierre_id COLLATE utf8mb4_unicode_ci = uC.id COLLATE utf8mb4_unicode_ci
                    WHERE c.id = :id LIMIT 1";
        $stmt = $this->conn->prepare($sqlCaja);
        $stmt->execute([':id' => $cajaId]);
        $caja = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$caja) {
            return ['success' => false, 'message' => 'Caja no encontrada'];
        }

        // 1. Ventas originadas en este turno
        $sqlVentas = "SELECT 
                        COALESCE(SUM(CASE WHEN metodo_pago = 'EFECTIVO' AND estado_pago = 'PAGADO' AND estado = 'ACTIVA' THEN total ELSE 0 END), 0) AS total_efectivo,
                        COALESCE(SUM(CASE WHEN metodo_pago = 'TARJETA' AND estado_pago = 'PAGADO' AND estado = 'ACTIVA' THEN total ELSE 0 END), 0) AS total_tarjeta,
                        COALESCE(SUM(CASE WHEN metodo_pago = 'TRANSFERENCIA' AND estado_pago = 'PAGADO' AND estado = 'ACTIVA' THEN total ELSE 0 END), 0) AS total_transferencia,
                        COALESCE(SUM(CASE WHEN estado_pago = 'PENDIENTE' AND estado = 'ACTIVA' THEN total ELSE 0 END), 0) AS total_pendiente,
                        COALESCE(COUNT(CASE WHEN estado = 'ACTIVA' THEN 1 END), 0) AS cantidad_ventas,
                        COALESCE(COUNT(CASE WHEN estado = 'CANCELADA' THEN 1 END), 0) AS cantidad_canceladas
                      FROM ventas 
                      WHERE (caja_id = :caja_id OR (caja_id IS NULL AND fecha_venta >= :fecha_apertura))";

        $stmtVentas = $this->conn->prepare($sqlVentas);
        $stmtVentas->execute([
            ':caja_id'        => $cajaId,
            ':fecha_apertura' => $caja['fecha_apertura']
        ]);
        $resumen = $stmtVentas->fetch(PDO::FETCH_ASSOC);

        // 2. Cobros de cargas/ventas pendientes de turnos anteriores liquidadas durante este turno
        $cobrosAnteriores = $this->obtenerCobrosTurnosAnteriores(
            $cajaId, 
            $caja['fecha_apertura'], 
            $caja['fecha_cierre']
        );

        $montoInicial       = floatval($caja['monto_inicial']);
        $ventasNuevasEfec   = floatval($resumen['total_efectivo']);
        $ventasNuevasTarj   = floatval($resumen['total_tarjeta']);
        $ventasNuevasTransf = floatval($resumen['total_transferencia']);
        $totalPendiente     = floatval($resumen['total_pendiente']);

        // Sumar cobros recaudados en el turno
        $totalEfectivo      = $ventasNuevasEfec + $cobrosAnteriores['total_efectivo'];
        $totalTarjeta       = $ventasNuevasTarj + $cobrosAnteriores['total_tarjeta'];
        $totalTransf        = $ventasNuevasTransf + $cobrosAnteriores['total_transferencia'];
        $totalVentasPagadas = $totalEfectivo + $totalTarjeta + $totalTransf;
        $totalEsperadoEfectivo = $montoInicial + $totalEfectivo;

        // 3. Inventario actual de cargas del turno que quedarán pendientes para el siguiente turno
        $custodia = $this->obtenerCargasPendientes($cajaId, $caja['fecha_apertura']);

        // 4. Cargas entregadas durante este turno
        $cantEntregadasTurno = 0;
        try {
            $sqlEntregadas = "SELECT COUNT(*) FROM cargas 
                              WHERE estado = 'ENTREGADA' 
                                AND fecha_entrega >= :fecha_apertura
                                AND (:fecha_cierre IS NULL OR fecha_entrega <= :fecha_cierre_2)";
            $stmtEntregadas = $this->conn->prepare($sqlEntregadas);
            $stmtEntregadas->execute([
                ':fecha_apertura' => $caja['fecha_apertura'],
                ':fecha_cierre'   => $caja['fecha_cierre'],
                ':fecha_cierre_2' => $caja['fecha_cierre']
            ]);
            $cantEntregadasTurno = (int) $stmtEntregadas->fetchColumn();
        } catch (\Throwable $e) {
            // Ignorar
        }

        // 5. Detalle de cancelaciones en este turno
        $cancelaciones = [];
        try {
            $sqlCancel = "SELECT v.folio, v.total, v.metodo_pago, v.fecha_venta,
                                 cv.motivo AS motivo_cancelacion,
                                 cv.fecha AS fecha_cancelacion,
                                 COALESCE(c.nombre, 'Público General') AS cliente_nombre,
                                 u.nombre AS cajero_nombre,
                                 uc.nombre AS cancelado_por
                          FROM ventas v
                          JOIN usuarios u ON v.usuario_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
                          LEFT JOIN clientes c ON v.cliente_id COLLATE utf8mb4_unicode_ci = c.id COLLATE utf8mb4_unicode_ci
                          LEFT JOIN cancelaciones_ventas cv ON cv.venta_id COLLATE utf8mb4_unicode_ci = v.id COLLATE utf8mb4_unicode_ci
                          LEFT JOIN usuarios uc ON cv.usuario_id COLLATE utf8mb4_unicode_ci = uc.id COLLATE utf8mb4_unicode_ci
                          WHERE (v.caja_id = :caja_id OR (v.caja_id IS NULL AND v.fecha_venta >= :fecha_apertura))
                            AND v.estado = 'CANCELADA'
                          ORDER BY COALESCE(cv.fecha, v.fecha_venta) DESC";
            $stmtCancel = $this->conn->prepare($sqlCancel);
            $stmtCancel->execute([
                ':caja_id'        => $cajaId,
                ':fecha_apertura' => $caja['fecha_apertura']
            ]);
            $cancelaciones = $stmtCancel->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("Error obteniendo cancelaciones corte: " . $e->getMessage());
        }

        return [
            'success'                 => true,
            'caja'                    => $caja,
            'monto_inicial'           => $montoInicial,
            'total_efectivo'          => $totalEfectivo,
            'total_tarjeta'           => $totalTarjeta,
            'total_transferencia'     => $totalTransf,
            'total_pendiente'         => $totalPendiente,
            'total_ventas_pagadas'    => $totalVentasPagadas,
            'total_esperado_efectivo' => $totalEsperadoEfectivo,
            'cantidad_ventas'         => (int) $resumen['cantidad_ventas'],
            'cantidad_canceladas'     => (int) $resumen['cantidad_canceladas'],
            'ventas_nuevas'           => [
                'efectivo'      => $ventasNuevasEfec,
                'tarjeta'       => $ventasNuevasTarj,
                'transferencia' => $ventasNuevasTransf,
                'total'         => round($ventasNuevasEfec + $ventasNuevasTarj + $ventasNuevasTransf, 2)
            ],
            'cobros_turnos_anteriores' => $cobrosAnteriores,
            'cargas_entregadas_turno'  => $cantEntregadasTurno,
            'custodia_cargas'          => $custodia,
            'cancelaciones'            => $cancelaciones
        ];
    }

    /**
     * Cierra el turno de caja, calcula diferencia, registra usuario de cierre y guarda snapshot de cargas en custodia
     */
    public function cerrarCaja(string $cajaId, float $efectivoDeclarado, ?string $observaciones = null, ?string $usuarioCierreId = null): array
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

        $custodia         = $resumen['custodia_cargas'];
        $cobrosAnteriores = $resumen['cobros_turnos_anteriores']['total_general'];

        $stmt = $this->conn->prepare(
            "UPDATE caja_cortes SET 
                fecha_cierre                    = NOW(),
                usuario_cierre_id               = :usuario_cierre_id,
                total_efectivo                  = :total_efectivo,
                total_tarjeta                   = :total_tarjeta,
                total_transferencia             = :total_transferencia,
                efectivo_declarado              = :efectivo_declarado,
                diferencia                      = :diferencia,
                observaciones                   = :observaciones,
                estado                          = 'CERRADA',
                total_cobros_turnos_anteriores  = :cobros_anteriores,
                cargas_pendientes_entrega       = :pendientes_entrega,
                cargas_pendientes_pago          = :pendientes_pago,
                monto_pendiente_cobro           = :monto_pendiente_cobro,
                cargas_pendientes_detalle       = :detalle_cargas
             WHERE id = :id AND estado = 'ABIERTA'"
        );

        $stmt->execute([
            ':usuario_cierre_id'     => $usuarioCierreId,
            ':total_efectivo'        => $totalEfectivo,
            ':total_tarjeta'         => $totalTarjeta,
            ':total_transferencia'   => $totalTransf,
            ':efectivo_declarado'    => $efectivoDeclarado,
            ':diferencia'            => $diferencia,
            ':observaciones'         => $observaciones,
            ':cobros_anteriores'     => $cobrosAnteriores,
            ':pendientes_entrega'    => $custodia['total_pendientes_entrega'],
            ':pendientes_pago'       => $custodia['total_pendientes_pago'],
            ':monto_pendiente_cobro' => $custodia['monto_pendiente_cobro'],
            ':detalle_cargas'        => json_encode($custodia['items'], JSON_UNESCAPED_UNICODE),
            ':id'                    => $cajaId
        ]);

        return [
            'success'                   => true,
            'message'                   => 'Caja cerrada exitosamente.',
            'diferencia'                => $diferencia,
            'monto_inicial'             => $resumen['monto_inicial'],
            'total_efectivo'            => $totalEfectivo,
            'total_tarjeta'             => $totalTarjeta,
            'total_transferencia'       => $totalTransf,
            'efectivo_declarado'        => $efectivoDeclarado,
            'esperado_efectivo'         => $esperadoEfectivo,
            'cargas_pendientes_entrega' => $custodia['total_pendientes_entrega'],
            'cargas_pendientes_pago'    => $custodia['total_pendientes_pago'],
            'monto_pendiente_cobro'     => $custodia['monto_pendiente_cobro'],
            'custodia_cargas'           => $custodia
        ];
    }
}
