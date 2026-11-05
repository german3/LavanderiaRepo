<?php
require_once __DIR__ . '/../src/config/Database.php';
$db = (new App\Config\Database())->getConnection();

$stmt = $db->query("
    SELECT 
        p.id, p.descripcion, p.tipo, p.costo as costo_directo,
        (SELECT SUM(pk.cantidad_consumida * ins.costo) 
         FROM productos_kits pk 
         JOIN productos ins ON pk.insumo_id = ins.id 
         WHERE pk.kit_id = p.id) as costo_kit_calculado
    FROM productos p
    WHERE p.tipo = 'KIT'
");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

