<?php

namespace App\Modules\Reports;

use PDO;

class ReporteVentas {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Obtiene el listado detallado de productos vendidos con costos, utilidades y datos de ticket.
     *
     * @param array $filtros ['fecha_inicio' => '', 'fecha_fin' => '', 'estado_venta' => 'ACTIVA', 'tipo_producto' => '', 'buscar' => '']
     * @return array
     */
    public function obtenerReporteVentas($filtros = []) {
        $sql = "
            SELECT 
                dv.id AS detalle_id,
                v.id AS venta_id,
                v.folio AS numero_ticket,
                v.fecha_venta,
                v.estado AS venta_estado,
                v.estado_pago,
                v.metodo_pago,
                COALESCE(c.id, 'MOSTRADOR') AS cliente_id,
                COALESCE(c.nombre, 'Público General') AS cliente_nombre,
                COALESCE(c.telefono, '') AS cliente_telefono,
                p.id AS producto_id,
                COALESCE(p.codigo_interno_sku, 'S/SKU') AS sku,
                COALESCE(p.codigo_barras, 'S/CÓDIGO') AS codigo_barras,
                p.descripcion,
                p.tipo AS producto_tipo,
                p.unidad_medida,
                dv.cantidad,
                COALESCE(
                    NULLIF(p.costo, 0),
                    (SELECT SUM(pk.cantidad_consumida * ins.costo)
                     FROM productos_kits pk
                     JOIN productos ins ON pk.insumo_id = ins.id
                     WHERE pk.kit_id = p.id),
                    p.costo,
                    0
                ) AS costo_compra,
                dv.precio_unitario AS precio_venta,
                dv.subtotal AS total,
                dv.ropa_kg,
                COALESCE(u.nombre, 'Cajero') AS cajero_nombre
            FROM detalle_ventas dv
            JOIN ventas v ON dv.venta_id = v.id
            JOIN productos p ON dv.producto_id = p.id
            LEFT JOIN clientes c ON v.cliente_id = c.id
            LEFT JOIN usuarios u ON v.usuario_id = u.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($filtros['fecha_inicio'])) {
            $sql .= " AND DATE(v.fecha_venta) >= :fecha_inicio";
            $params[':fecha_inicio'] = $filtros['fecha_inicio'];
        }

        if (!empty($filtros['fecha_fin'])) {
            $sql .= " AND DATE(v.fecha_venta) <= :fecha_fin";
            $params[':fecha_fin'] = $filtros['fecha_fin'];
        }

        if (isset($filtros['estado_venta']) && $filtros['estado_venta'] !== '') {
            $sql .= " AND v.estado = :estado_venta";
            $params[':estado_venta'] = $filtros['estado_venta'];
        }

        if (!empty($filtros['estado_pago'])) {
            $sql .= " AND v.estado_pago = :estado_pago";
            $params[':estado_pago'] = $filtros['estado_pago'];
        }

        if (!empty($filtros['tipo_producto'])) {
            $sql .= " AND p.tipo = :tipo_producto";
            $params[':tipo_producto'] = $filtros['tipo_producto'];
        }

        if (!empty($filtros['buscar'])) {
            $termino = '%' . trim($filtros['buscar']) . '%';
            $sql .= " AND (
                p.codigo_interno_sku LIKE :b1 OR
                p.codigo_barras LIKE :b2 OR
                p.descripcion LIKE :b3 OR
                v.folio LIKE :b4 OR
                c.nombre LIKE :b5
            )";
            $params[':b1'] = $termino;
            $params[':b2'] = $termino;
            $params[':b3'] = $termino;
            $params[':b4'] = $termino;
            $params[':b5'] = $termino;
        }

        $sql .= " ORDER BY v.fecha_venta DESC, dv.id DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $resultado = [];
        foreach ($rows as $r) {
            $cantidad     = (float)$r['cantidad'];
            $costoUnit    = (float)$r['costo_compra'];
            $precioUnit   = (float)$r['precio_venta'];
            $total        = (float)$r['total'];
            $costoTotal   = $costoUnit * $cantidad;
            $utilidadNeta = $total - $costoTotal;

            $porcentajeUtilidad = 0.0;
            if ($precioUnit > 0) {
                $porcentajeUtilidad = (($precioUnit - $costoUnit) / $precioUnit) * 100;
            } elseif ($costoUnit > 0) {
                $porcentajeUtilidad = -100.0;
            }

            $r['cantidad']            = $cantidad;
            $r['costo_compra']        = $costoUnit;
            $r['precio_venta']        = $precioUnit;
            $r['costo_total']         = $costoTotal;
            $r['total']               = $total;
            $r['utilidad_neta']       = $utilidadNeta;
            $r['porcentaje_utilidad'] = round($porcentajeUtilidad, 2);

            $resultado[] = $r;
        }

        return $resultado;
    }

    /**
     * Calcula métricas globales de resumen sobre un conjunto de filas
     */
    public function calcularKpis($rows) {
        $totalVentas    = 0.0;
        $totalCosto     = 0.0;
        $utilidadNeta   = 0.0;
        $ticketsUnicos  = [];
        $unidadesVendidas = 0.0;

        foreach ($rows as $r) {
            if ($r['venta_estado'] === 'ACTIVA') {
                $totalVentas      += (float)$r['total'];
                $totalCosto       += (float)$r['costo_total'];
                $utilidadNeta     += (float)$r['utilidad_neta'];
                $unidadesVendidas += (float)$r['cantidad'];
                $ticketsUnicos[$r['numero_ticket']] = true;
            }
        }

        $margenPromedio = 0.0;
        if ($totalVentas > 0) {
            $margenPromedio = ($utilidadNeta / $totalVentas) * 100;
        }

        return [
            'total_ventas'       => $totalVentas,
            'total_costo'        => $totalCosto,
            'utilidad_neta'      => $utilidadNeta,
            'margen_promedio'    => round($margenPromedio, 2),
            'total_tickets'      => count($ticketsUnicos),
            'total_renglones'    => count($rows),
            'unidades_vendidas'  => $unidadesVendidas
        ];
    }
}

