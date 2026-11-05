<?php
date_default_timezone_set('America/Mexico_City');
require_once __DIR__ . '/../src/config/Database.php';
use App\Config\Database;

$db = (new Database())->getConnection();
$query = "
    SELECT 
        dv.id AS detalle_id,
        v.id AS venta_id,
        v.folio AS numero_ticket,
        v.fecha_venta,
        v.estado AS venta_estado,
        v.estado_pago,
        COALESCE(c.nombre, 'Público General') AS cliente_nombre,
        p.id AS producto_id,
        p.codigo_interno_sku AS sku,
        p.codigo_barras,
        p.descripcion,
        p.tipo AS producto_tipo,
        dv.cantidad,
        COALESCE(p.costo, 0) AS costo_compra,
        dv.precio_unitario AS precio_venta,
        dv.subtotal AS total,
        (dv.precio_unitario - COALESCE(p.costo, 0)) AS margen_unitario,
        ((dv.precio_unitario - COALESCE(p.costo, 0)) * dv.cantidad) AS utilidad_neta,
        CASE 
            WHEN dv.precio_unitario > 0 THEN 
                ROUND(((dv.precio_unitario - COALESCE(p.costo, 0)) / dv.precio_unitario) * 100, 2)
            ELSE 0 
        END AS porcentaje_utilidad
    FROM detalle_ventas dv
    JOIN ventas v ON dv.venta_id = v.id
    JOIN productos p ON dv.producto_id = p.id
    LEFT JOIN clientes c ON v.cliente_id = c.id
    ORDER BY v.fecha_venta DESC, dv.id DESC
    LIMIT 10
";
$stmt = $db->query($query);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Filas obtenidas: " . count($rows) . "\n";
print_r($rows);

