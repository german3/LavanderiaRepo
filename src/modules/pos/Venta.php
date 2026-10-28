<?php

namespace App\Modules\Pos;

require_once __DIR__ . '/../inventory/MovimientoInventario.php';
require_once __DIR__ . '/../laundry/Carga.php';
require_once __DIR__ . '/../catalog/Historial.php';

use PDO;
use Exception;
use App\Modules\Inventory\MovimientoInventario;
use App\Modules\Laundry\Carga;
use App\Modules\Catalog\Historial;

/**
 * Modelo Venta — Spec 05-punto-venta-caja.md & 06-cargas-lavanderia.md
 *
 * Entidades:
 *   - Venta
 *   - DetalleVenta
 *   - CancelacionVenta
 */
class Venta
{
    private $conn;
    private $movimientoModel;
    private $cargaModel;
    private $historialModel;

    public function __construct($db)
    {
        $this->conn = $db;
        $this->asegurarTablas();
        $this->movimientoModel = new MovimientoInventario($db);
        $this->cargaModel = new Carga($db);
        $this->historialModel = new Historial($db);
    }

    private function asegurarTablas(): void
    {
        $tablas = [
            'ventas' => [
                'con_fk' => "CREATE TABLE IF NOT EXISTS ventas (
                    id             VARCHAR(36) PRIMARY KEY,
                    folio          VARCHAR(50) NOT NULL UNIQUE,
                    cliente_id     VARCHAR(36) NULL,
                    usuario_id     VARCHAR(36) NOT NULL,
                    caja_id        VARCHAR(36) NULL,
                    total          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    estado         ENUM('ACTIVA', 'CANCELADA') NOT NULL DEFAULT 'ACTIVA',
                    metodo_pago    ENUM('EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'PENDIENTE') NOT NULL DEFAULT 'EFECTIVO',
                    estado_pago    ENUM('PAGADO', 'PENDIENTE') NOT NULL DEFAULT 'PAGADO',
                    monto_recibido DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    cambio         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    fecha_venta    DATETIME DEFAULT CURRENT_TIMESTAMP,
                    fecha_pago     DATETIME NULL,
                    observaciones  TEXT NULL,
                    INDEX (cliente_id),
                    INDEX (usuario_id),
                    INDEX (caja_id),
                    INDEX (estado),
                    INDEX (estado_pago),
                    INDEX (fecha_venta),
                    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
                    FOREIGN KEY (cliente_id) REFERENCES clientes(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
                'sin_fk' => "CREATE TABLE IF NOT EXISTS ventas (
                    id             VARCHAR(36) PRIMARY KEY,
                    folio          VARCHAR(50) NOT NULL UNIQUE,
                    cliente_id     VARCHAR(36) NULL,
                    usuario_id     VARCHAR(36) NOT NULL,
                    caja_id        VARCHAR(36) NULL,
                    total          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    estado         ENUM('ACTIVA', 'CANCELADA') NOT NULL DEFAULT 'ACTIVA',
                    metodo_pago    ENUM('EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'PENDIENTE') NOT NULL DEFAULT 'EFECTIVO',
                    estado_pago    ENUM('PAGADO', 'PENDIENTE') NOT NULL DEFAULT 'PAGADO',
                    monto_recibido DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    cambio         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    fecha_venta    DATETIME DEFAULT CURRENT_TIMESTAMP,
                    fecha_pago     DATETIME NULL,
                    observaciones  TEXT NULL,
                    INDEX (cliente_id),
                    INDEX (usuario_id),
                    INDEX (caja_id),
                    INDEX (estado),
                    INDEX (estado_pago),
                    INDEX (fecha_venta)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],
            'detalle_ventas' => [
                'con_fk' => "CREATE TABLE IF NOT EXISTS detalle_ventas (
                    id              VARCHAR(36) PRIMARY KEY,
                    venta_id        VARCHAR(36) NOT NULL,
                    producto_id     VARCHAR(36) NOT NULL,
                    cantidad        DECIMAL(10,2) NOT NULL DEFAULT 1.00,
                    precio_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    ropa_kg         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    notas           TEXT NULL,
                    INDEX (venta_id),
                    INDEX (producto_id),
                    FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
                    FOREIGN KEY (producto_id) REFERENCES productos(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
                'sin_fk' => "CREATE TABLE IF NOT EXISTS detalle_ventas (
                    id              VARCHAR(36) PRIMARY KEY,
                    venta_id        VARCHAR(36) NOT NULL,
                    producto_id     VARCHAR(36) NOT NULL,
                    cantidad        DECIMAL(10,2) NOT NULL DEFAULT 1.00,
                    precio_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    ropa_kg         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    notas           TEXT NULL,
                    INDEX (venta_id),
                    INDEX (producto_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],
            'cancelaciones_ventas' => [
                'con_fk' => "CREATE TABLE IF NOT EXISTS cancelaciones_ventas (
                    id         VARCHAR(36) PRIMARY KEY,
                    venta_id   VARCHAR(36) NOT NULL UNIQUE,
                    motivo     TEXT NOT NULL,
                    usuario_id VARCHAR(36) NOT NULL,
                    fecha      DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX (venta_id),
                    INDEX (usuario_id),
                    FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
                    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
                'sin_fk' => "CREATE TABLE IF NOT EXISTS cancelaciones_ventas (
                    id         VARCHAR(36) PRIMARY KEY,
                    venta_id   VARCHAR(36) NOT NULL UNIQUE,
                    motivo     TEXT NOT NULL,
                    usuario_id VARCHAR(36) NOT NULL,
                    fecha      DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX (venta_id),
                    INDEX (usuario_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ]
        ];

        foreach ($tablas as $nombre => $queries) {
            try {
                $this->conn->exec($queries['con_fk']);
            } catch (\Throwable $e) {
                error_log("Venta::asegurarTablas ($nombre con FK falló: " . $e->getMessage() . ") — intentando sin FK...");
                try {
                    $this->conn->exec($queries['sin_fk']);
                } catch (\Throwable $e2) {
                    error_log("Venta::asegurarTablas ($nombre sin FK falló): " . $e2->getMessage());
                }
            }

            try {
                $this->conn->exec("ALTER TABLE $nombre CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (\Throwable $e) {
                // Silencioso
            }
        }

        // Asegurar columnas requeridas en ventas si la tabla ya existía previamente
        $columnasVentas = [
            "ALTER TABLE ventas ADD COLUMN folio VARCHAR(50) NOT NULL UNIQUE AFTER id",
            "ALTER TABLE ventas ADD COLUMN cliente_id VARCHAR(36) NULL AFTER folio",
            "ALTER TABLE ventas ADD COLUMN usuario_id VARCHAR(36) NOT NULL AFTER cliente_id",
            "ALTER TABLE ventas ADD COLUMN caja_id VARCHAR(36) NULL AFTER usuario_id",
            "ALTER TABLE ventas ADD COLUMN total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER caja_id",
            "ALTER TABLE ventas ADD COLUMN estado ENUM('ACTIVA', 'CANCELADA') NOT NULL DEFAULT 'ACTIVA' AFTER total",
            "ALTER TABLE ventas ADD COLUMN metodo_pago ENUM('EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'PENDIENTE') NOT NULL DEFAULT 'EFECTIVO' AFTER estado",
            "ALTER TABLE ventas ADD COLUMN estado_pago ENUM('PAGADO', 'PENDIENTE') NOT NULL DEFAULT 'PAGADO' AFTER metodo_pago",
            "ALTER TABLE ventas ADD COLUMN monto_recibido DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER estado_pago",
            "ALTER TABLE ventas ADD COLUMN cambio DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER monto_recibido",
            "ALTER TABLE ventas ADD COLUMN fecha_venta DATETIME DEFAULT CURRENT_TIMESTAMP AFTER cambio",
            "ALTER TABLE ventas ADD COLUMN fecha_pago DATETIME NULL AFTER fecha_venta",
            "ALTER TABLE ventas ADD COLUMN observaciones TEXT NULL AFTER fecha_pago",
        ];

        foreach ($columnasVentas as $colSql) {
            try {
                $this->conn->exec($colSql);
            } catch (\Throwable $e) {
                // Silencioso si la columna ya existe
            }
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

    public function generarFolio(): string
    {
        $stmt = $this->conn->query("SELECT COUNT(*) FROM ventas");
        $count = (int) $stmt->fetchColumn();
        return 'VTA-' . str_pad($count + 1, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Procesa y registra una venta en el FrontOffice (POS)
     */
    public function procesarVenta(array $datos): array
    {
        $items = $datos['items'] ?? [];
        if (empty($items)) {
            return ['success' => false, 'message' => 'No hay artículos en la orden de venta.'];
        }

        $usuarioId     = $datos['usuario_id'];
        $clienteId     = !empty($datos['cliente_id']) ? $datos['cliente_id'] : null;
        $cajaId        = !empty($datos['caja_id']) ? $datos['caja_id'] : null;
        $metodoPago    = $datos['metodo_pago'] ?? 'EFECTIVO';
        $estadoPago    = ($metodoPago === 'PENDIENTE' || !empty($datos['pago_posterior'])) ? 'PENDIENTE' : 'PAGADO';
        $montoRecibido = floatval($datos['monto_recibido'] ?? 0);
        $observaciones = $datos['observaciones'] ?? '';

        try {
            $this->conn->beginTransaction();

            $ventaId = $this->uuid();
            $folio = $this->generarFolio();

            // Calcular total acumulando subtotales
            $totalVenta = 0.0;
            $ropaKgTotal = 0.0;
            $tieneServicioLavanderia = false;

            // Obtener nombre del cliente principal si existe
            $clienteNombreGlobal = 'Público General';
            if (!empty($clienteId)) {
                $stmtC = $this->conn->prepare("SELECT nombre, telefono FROM clientes WHERE id = :cid");
                $stmtC->execute([':cid' => $clienteId]);
                if ($cRow = $stmtC->fetch(PDO::FETCH_ASSOC)) {
                    $clienteNombreGlobal = $cRow['nombre'];
                }
            }

            // Obtener nombre del usuario que realiza el cobro
            $usuarioNombre = $datos['usuario_nombre'] ?? null;
            if (empty($usuarioNombre) && !empty($usuarioId)) {
                $stmtU = $this->conn->prepare("SELECT nombre FROM usuarios WHERE id = :uid");
                $stmtU->execute([':uid' => $usuarioId]);
                if ($uRow = $stmtU->fetch(PDO::FETCH_ASSOC)) {
                    $usuarioNombre = $uRow['nombre'];
                }
            }
            if (empty($usuarioNombre)) {
                $usuarioNombre = 'Cajero';
            }

            // Validar y procesar cada ítem
            $detallesParaInsertar = [];
            foreach ($items as $item) {
                $productoId = $item['producto_id'];
                $cantidad = floatval($item['cantidad'] ?? 1);
                $precio = floatval($item['precio_unitario'] ?? 0);
                $subtotal = round($cantidad * $precio, 2);
                $ropaKg = floatval($item['ropa_kg'] ?? 0);
                $cliItemNombre = !empty($item['cliente_nombre']) ? $item['cliente_nombre'] : $clienteNombreGlobal;
                $cliItemId = !empty($item['cliente_id']) ? $item['cliente_id'] : $clienteId;
                
                $notasRaw = trim($item['notas'] ?? '');
                $notas = "Cliente: " . $cliItemNombre . ($notasRaw !== '' ? " | " . $notasRaw : "");

                $totalVenta += $subtotal;
                $ropaKgTotal += ($ropaKg * $cantidad);

                // Obtener datos del producto para inventario y tipo
                $stmtP = $this->conn->prepare("SELECT id, descripcion, tipo, stock_cantidad, costo, codigo_barras, codigo_interno_sku, unidad_medida FROM productos WHERE id = :id FOR UPDATE");
                $stmtP->execute([':id' => $productoId]);
                $productoInfo = $stmtP->fetch(PDO::FETCH_ASSOC);

                if (!$productoInfo) {
                    throw new Exception("El producto con ID {$productoId} no existe.");
                }

                if ($productoInfo['tipo'] === 'SERVICIO' || $ropaKg > 0) {
                    $tieneServicioLavanderia = true;
                }

                $detallesParaInsertar[] = [
                    'id'              => $this->uuid(),
                    'venta_id'        => $ventaId,
                    'producto_id'     => $productoId,
                    'cantidad'        => $cantidad,
                    'precio_unitario' => $precio,
                    'subtotal'        => $subtotal,
                    'ropa_kg'         => $ropaKg,
                    'notas'           => $notas,
                    'cliente_id'      => $cliItemId,
                    'cliente_nombre'  => $cliItemNombre,
                    'producto_info'   => $productoInfo
                ];
            }

            // Calcular cambio
            $cambio = 0.0;
            if ($estadoPago === 'PAGADO') {
                if ($metodoPago === 'EFECTIVO') {
                    if ($montoRecibido < $totalVenta) {
                        $montoRecibido = $totalVenta; // Default si viene exacto
                    }
                    $cambio = round(max(0, $montoRecibido - $totalVenta), 2);
                } else {
                    $montoRecibido = $totalVenta;
                    $cambio = 0.0;
                }
            } else {
                $montoRecibido = 0.0;
                $cambio = 0.0;
            }

            // Insertar cabecera de Venta
            $stmtV = $this->conn->prepare(
                "INSERT INTO ventas (
                    id, folio, cliente_id, usuario_id, caja_id, total, estado,
                    metodo_pago, estado_pago, monto_recibido, cambio, fecha_venta,
                    fecha_pago, observaciones
                ) VALUES (
                    :id, :folio, :cliente_id, :usuario_id, :caja_id, :total, 'ACTIVA',
                    :metodo_pago, :estado_pago, :monto_recibido, :cambio, NOW(),
                    " . ($estadoPago === 'PAGADO' ? 'NOW()' : 'NULL') . ", :observaciones
                )"
            );

            $stmtV->execute([
                ':id'             => $ventaId,
                ':folio'          => $folio,
                ':cliente_id'     => $clienteId,
                ':usuario_id'     => $usuarioId,
                ':caja_id'        => $cajaId,
                ':total'          => $totalVenta,
                ':metodo_pago'    => $metodoPago,
                ':estado_pago'    => $estadoPago,
                ':monto_recibido' => $montoRecibido,
                ':cambio'         => $cambio,
                ':observaciones'  => $observaciones
            ]);

            // Insertar detalles y descontar inventario
            $stmtDet = $this->conn->prepare(
                "INSERT INTO detalle_ventas (
                    id, venta_id, producto_id, cantidad, precio_unitario, subtotal, ropa_kg, notas
                ) VALUES (
                    :id, :venta_id, :producto_id, :cantidad, :precio_unitario, :subtotal, :ropa_kg, :notas
                )"
            );

            foreach ($detallesParaInsertar as $det) {
                $stmtDet->execute([
                    ':id'              => $det['id'],
                    ':venta_id'        => $det['venta_id'],
                    ':producto_id'     => $det['producto_id'],
                    ':cantidad'        => $det['cantidad'],
                    ':precio_unitario' => $det['precio_unitario'],
                    ':subtotal'        => $det['subtotal'],
                    ':ropa_kg'         => $det['ropa_kg'],
                    ':notas'           => $det['notas']
                ]);

                $pInfo = $det['producto_info'];
                $cant = $det['cantidad'];
                $stockAntes = floatval($pInfo['stock_cantidad']);
                $stockDespues = $stockAntes;

                // Regla Spec 05 §3: Si tipo = NORMAL, descontar y registrar MovimientoInventario VENTA
                if ($pInfo['tipo'] === 'NORMAL') {
                    $stockDespues = $stockAntes - $cant;

                    $stmtUpd = $this->conn->prepare("UPDATE productos SET stock_cantidad = :nuevo_stock WHERE id = :id");
                    $stmtUpd->execute([':nuevo_stock' => $stockDespues, ':id' => $pInfo['id']]);

                    $this->movimientoModel->registrar([
                        'producto_id'     => $pInfo['id'],
                        'cantidad'        => $cant,
                        'tipo_movimiento' => 'VENTA',
                        'motivo'          => "Venta en mostrador Folio: {$folio} - Cliente: {$det['cliente_nombre']}",
                        'usuario_id'      => $usuarioId,
                        'referencia_id'   => $ventaId,
                        'costo_unitario'  => floatval($pInfo['costo']),
                        'stock_antes'     => $stockAntes,
                        'stock_despues'   => $stockDespues
                    ]);
                }

                // Regla Spec 05 §3: Si tipo = KIT, descontar insumos del Kit y registrar KIT_CONSUMO
                $kitDetalleParaHistorial = null;
                if ($pInfo['tipo'] === 'KIT') {
                    $stmtKits = $this->conn->prepare(
                        "SELECT pk.insumo_id, pk.cantidad_consumida, pk.unidad, p.stock_cantidad, p.costo, p.descripcion, p.unidad_medida
                         FROM productos_kits pk
                         JOIN productos p ON pk.insumo_id = p.id
                         WHERE pk.kit_id = :kit_id"
                    );
                    $stmtKits->execute([':kit_id' => $pInfo['id']]);
                    $insumos = $stmtKits->fetchAll(PDO::FETCH_ASSOC);

                    $kitDetalleParaHistorial = [];
                    foreach ($insumos as $insumo) {
                        $cantConsumidaTotal = floatval($insumo['cantidad_consumida']) * $cant;
                        $stockInsumoAntes = floatval($insumo['stock_cantidad']);
                        $stockInsumoDespues = $stockInsumoAntes - $cantConsumidaTotal;

                        $stmtUpdInsumo = $this->conn->prepare("UPDATE productos SET stock_cantidad = :nuevo_stock WHERE id = :id");
                        $stmtUpdInsumo->execute([':nuevo_stock' => $stockInsumoDespues, ':id' => $insumo['insumo_id']]);

                        $this->movimientoModel->registrar([
                            'producto_id'     => $insumo['insumo_id'],
                            'cantidad'        => $cantConsumidaTotal,
                            'tipo_movimiento' => 'KIT_CONSUMO',
                            'motivo'          => "Consumo por Kit {$pInfo['descripcion']} en Venta {$folio} - Cliente: {$det['cliente_nombre']}",
                            'usuario_id'      => $usuarioId,
                            'referencia_id'   => $ventaId,
                            'costo_unitario'  => floatval($insumo['costo']),
                            'stock_antes'     => $stockInsumoAntes,
                            'stock_despues'   => $stockInsumoDespues
                        ]);

                        // Acumular detalle para historial
                        $kitDetalleParaHistorial[] = [
                            'insumo_nombre'   => $insumo['descripcion'],
                            'cantidad_total'  => $cantConsumidaTotal,
                            'unidad'          => $insumo['unidad'] ?: $insumo['unidad_medida'],
                        ];
                    }
                }

                // ═════════════════════════════════════════════════════════════
                // REGISTRO GLOBAL EN HISTORIAL DE MOVIMIENTOS (Spec FrontOffice)
                // ═════════════════════════════════════════════════════════════
                $cantHistorial = $det['ropa_kg'] > 0 ? $det['ropa_kg'] : $cant;
                $unidadHistorial = $det['ropa_kg'] > 0 ? "kg" : ($pInfo['unidad_medida'] ?? 'pza');
                $motivoHistorial = $folio;

                $this->historialModel->registrar([
                    'tipo_movimiento'      => 'VENTA POS',
                    'producto_id'          => $pInfo['id'],
                    'producto_sku'         => $pInfo['codigo_interno_sku'] ?: ($pInfo['codigo_barras'] ?: 'S/C'),
                    'producto_descripcion' => $pInfo['descripcion'],
                    'tipo_producto'        => $pInfo['tipo'],
                    'cantidad'             => $cantHistorial,
                    'costo_unitario'       => $det['precio_unitario'],
                    'stock_antes'          => $stockAntes,
                    'stock_despues'        => $stockDespues,
                    'motivo'               => $motivoHistorial,
                    'kit_detalle'          => $kitDetalleParaHistorial,
                    'usuario_id'           => null,
                    'usuario_nombre'       => $usuarioNombre
                ]);

            }

            // Regla Spec 05 §3 & 06 §3: Generar Carga automáticamente para servicios de lavandería
            $numeroCarga = null;
            if ($tieneServicioLavanderia) {
                $numeroCarga = $this->cargaModel->crear([
                    'venta_id'            => $ventaId,
                    'cliente_id'          => $clienteId,
                    'estado'              => 'RECIBIDA',
                    'estado_pago'         => $estadoPago,
                    'total'               => $totalVenta,
                    'ropa_kg'             => $ropaKgTotal,
                    'usuario_registro_id' => $usuarioId,
                    'observaciones'       => $observaciones
                ]);
            }

            $this->conn->commit();

            return [
                'success'        => true,
                'message'        => 'Venta procesada exitosamente.',
                'venta_id'       => $ventaId,
                'folio'          => $folio,
                'total'          => $totalVenta,
                'metodo_pago'    => $metodoPago,
                'estado_pago'    => $estadoPago,
                'monto_recibido' => $montoRecibido,
                'cambio'         => $cambio,
                'numero_carga'   => $numeroCarga
            ];

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error en Venta::procesarVenta — " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al procesar la venta: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Registra el pago posterior de una venta pendiente (Spec 05 §3)
     */
    public function registrarPagoPosterior(string $ventaId, string $metodoPago, float $montoRecibido = 0.0): array
    {
        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("SELECT * FROM ventas WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $ventaId]);
            $venta = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$venta) {
                throw new Exception("Venta no encontrada.");
            }

            if ($venta['estado'] === 'CANCELADA') {
                throw new Exception("No se puede cobrar una venta que ha sido cancelada.");
            }

            if ($venta['estado_pago'] === 'PAGADO') {
                throw new Exception("Esta venta ya ha sido liquidada.");
            }

            $total = floatval($venta['total']);
            $cambio = 0.0;
            if ($metodoPago === 'EFECTIVO') {
                if ($montoRecibido < $total) {
                    $montoRecibido = $total;
                }
                $cambio = round(max(0, $montoRecibido - $total), 2);
            } else {
                $montoRecibido = $total;
                $cambio = 0.0;
            }

            $stmtUpd = $this->conn->prepare(
                "UPDATE ventas SET 
                    estado_pago    = 'PAGADO',
                    metodo_pago    = :metodo_pago,
                    monto_recibido = :monto_recibido,
                    cambio         = :cambio,
                    fecha_pago     = NOW()
                 WHERE id = :id"
            );

            $stmtUpd->execute([
                ':metodo_pago'    => $metodoPago,
                ':monto_recibido' => $montoRecibido,
                ':cambio'         => $cambio,
                ':id'             => $ventaId
            ]);

            // Actualizar Carga vinculada si existe
            $this->cargaModel->actualizarEstadoPago($ventaId, 'PAGADO');

            $this->conn->commit();

            return [
                'success'        => true,
                'message'        => 'Pago registrado correctamente.',
                'venta_id'       => $ventaId,
                'folio'          => $venta['folio'],
                'total'          => $total,
                'metodo_pago'    => $metodoPago,
                'monto_recibido' => $montoRecibido,
                'cambio'         => $cambio
            ];

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Cancela una venta, registra motivo y revierte inventario (Spec 05 §2, §3, Fase 12)
     */
    public function cancelarVenta(string $ventaId, string $motivo, string $usuarioId): array
    {
        if (trim($motivo) === '') {
            return ['success' => false, 'message' => 'El motivo de la cancelación es obligatorio.'];
        }

        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("SELECT * FROM ventas WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $ventaId]);
            $venta = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$venta) {
                throw new Exception("Venta no encontrada.");
            }

            if ($venta['estado'] === 'CANCELADA') {
                throw new Exception("Esta venta ya se encuentra cancelada.");
            }

            // Cambiar estado a CANCELADA (la venta se conserva)
            $stmtCancel = $this->conn->prepare("UPDATE ventas SET estado = 'CANCELADA' WHERE id = :id");
            $stmtCancel->execute([':id' => $ventaId]);

            // Registrar motivo en cancelaciones_ventas
            $stmtCancLog = $this->conn->prepare(
                "INSERT INTO cancelaciones_ventas (id, venta_id, motivo, usuario_id, fecha)
                 VALUES (:id, :venta_id, :motivo, :usuario_id, NOW())"
            );
            $stmtCancLog->execute([
                ':id'         => $this->uuid(),
                ':venta_id'   => $ventaId,
                ':motivo'     => $motivo,
                ':usuario_id' => $usuarioId
            ]);

            // Cancelar carga asociada si existe
            $this->cargaModel->cancelarPorVenta($ventaId);

            // Revertir inventario
            $stmtDet = $this->conn->prepare(
                "SELECT dv.*, p.tipo, p.descripcion, p.stock_cantidad, p.costo 
                 FROM detalle_ventas dv
                 JOIN productos p ON dv.producto_id = p.id
                 WHERE dv.venta_id = :venta_id"
            );
            $stmtDet->execute([':venta_id' => $ventaId]);
            $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

            foreach ($detalles as $det) {
                $cant = floatval($det['cantidad']);

                if ($det['tipo'] === 'NORMAL') {
                    $stockAntes = floatval($det['stock_cantidad']);
                    $stockDespues = $stockAntes + $cant;

                    $stmtUpd = $this->conn->prepare("UPDATE productos SET stock_cantidad = :nuevo_stock WHERE id = :id");
                    $stmtUpd->execute([':nuevo_stock' => $stockDespues, ':id' => $det['producto_id']]);

                    $this->movimientoModel->registrar([
                        'producto_id'     => $det['producto_id'],
                        'cantidad'        => $cant,
                        'tipo_movimiento' => 'AJUSTE',
                        'motivo'          => "Reversión por Cancelación de Venta {$venta['folio']}: {$motivo}",
                        'usuario_id'      => $usuarioId,
                        'referencia_id'   => $ventaId,
                        'costo_unitario'  => floatval($det['costo']),
                        'stock_antes'     => $stockAntes,
                        'stock_despues'   => $stockDespues
                    ]);
                }

                if ($det['tipo'] === 'KIT') {
                    $stmtKits = $this->conn->prepare(
                        "SELECT pk.insumo_id, pk.cantidad_consumida, p.stock_cantidad, p.costo 
                         FROM productos_kits pk
                         JOIN productos p ON pk.insumo_id = p.id
                         WHERE pk.kit_id = :kit_id"
                    );
                    $stmtKits->execute([':kit_id' => $det['producto_id']]);
                    $insumos = $stmtKits->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($insumos as $insumo) {
                        $cantConsumidaTotal = floatval($insumo['cantidad_consumida']) * $cant;
                        $stockInsumoAntes = floatval($insumo['stock_cantidad']);
                        $stockInsumoDespues = $stockInsumoAntes + $cantConsumidaTotal;

                        $stmtUpdInsumo = $this->conn->prepare("UPDATE productos SET stock_cantidad = :nuevo_stock WHERE id = :id");
                        $stmtUpdInsumo->execute([':nuevo_stock' => $stockInsumoDespues, ':id' => $insumo['insumo_id']]);

                        $this->movimientoModel->registrar([
                            'producto_id'     => $insumo['insumo_id'],
                            'cantidad'        => $cantConsumidaTotal,
                            'tipo_movimiento' => 'AJUSTE',
                            'motivo'          => "Reversión Insumo por Cancelación de Venta {$venta['folio']}: {$motivo}",
                            'usuario_id'      => $usuarioId,
                            'referencia_id'   => $ventaId,
                            'costo_unitario'  => floatval($insumo['costo']),
                            'stock_antes'     => $stockInsumoAntes,
                            'stock_despues'   => $stockInsumoDespues
                        ]);
                    }
                }
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => "La venta {$venta['folio']} fue cancelada exitosamente y el inventario fue reincorporado."
            ];

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Obtiene una venta completa con detalles para ticket o consulta
     */
    public function obtenerVentaConDetalle(string $ventaId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT v.*, 
                    u.nombre AS cajero_nombre,
                    c.nombre AS cliente_nombre,
                    c.telefono AS cliente_telefono,
                    c.direccion AS cliente_direccion,
                    cg.numero_carga,
                    cg.estado AS carga_estado,
                    cv.motivo AS motivo_cancelacion,
                    uc.nombre AS cancelado_por
             FROM ventas v
             JOIN usuarios u ON v.usuario_id = u.id
             LEFT JOIN clientes c ON v.cliente_id = c.id
             LEFT JOIN cargas cg ON cg.venta_id = v.id
             LEFT JOIN cancelaciones_ventas cv ON cv.venta_id = v.id
             LEFT JOIN usuarios uc ON cv.usuario_id = uc.id
             WHERE v.id = :id OR v.folio = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $ventaId]);
        $venta = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$venta) {
            return null;
        }

        // Obtener detalles
        $stmtDet = $this->conn->prepare(
            "SELECT dv.*, p.descripcion, p.codigo_barras, p.codigo_interno_sku, p.tipo, p.unidad_medida
             FROM detalle_ventas dv
             JOIN productos p ON dv.producto_id = p.id
             WHERE dv.venta_id = :venta_id"
        );
        $stmtDet->execute([':venta_id' => $venta['id']]);
        $venta['detalles'] = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

        return $venta;
    }

    /**
     * Obtiene ventas recientes para el historial del POS
     */
    public function obtenerVentasRecientes(int $limite = 40): array
    {
        $stmt = $this->conn->prepare(
            "SELECT v.*, 
                    u.nombre AS cajero_nombre, 
                    COALESCE(c.nombre, 'Público General') AS cliente_nombre,
                    c.telefono AS cliente_telefono,
                    cg.numero_carga
             FROM ventas v
             JOIN usuarios u ON v.usuario_id = u.id
             LEFT JOIN clientes c ON v.cliente_id = c.id
             LEFT JOIN cargas cg ON cg.venta_id = v.id
             ORDER BY v.fecha_venta DESC
             LIMIT :limite"
        );
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene ventas pendientes de cobro (Pago Posterior)
     */
    public function obtenerVentasPendientes(?string $busqueda = null): array
    {
        $sql = "SELECT v.*, 
                       u.nombre AS cajero_nombre,
                       COALESCE(c.nombre, 'Sin asignar') AS cliente_nombre,
                       c.telefono AS cliente_telefono,
                       cg.numero_carga,
                       cg.ropa_kg,
                       cg.estado AS carga_estado
                FROM ventas v
                JOIN usuarios u ON v.usuario_id = u.id
                LEFT JOIN clientes c ON v.cliente_id = c.id
                LEFT JOIN cargas cg ON cg.venta_id = v.id
                WHERE v.estado = 'ACTIVA' AND v.estado_pago = 'PENDIENTE'";

        $params = [];
        if ($busqueda && trim($busqueda) !== '') {
            $sql .= " AND (v.folio LIKE :b OR c.nombre LIKE :b OR c.telefono LIKE :b OR cg.numero_carga LIKE :b)";
            $params[':b'] = '%' . trim($busqueda) . '%';
        }

        $sql .= " ORDER BY v.fecha_venta ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
