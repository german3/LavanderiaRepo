<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/reports/ReporteVentas.php';

use App\Config\Database;
use App\Modules\Reports\ReporteVentas;

$db = (new Database())->getConnection();
$rep = new ReporteVentas($db);
$rows = $rep->obtenerReporteVentas();
$kpis = $rep->calcularKpis($rows);

echo "Total rows: " . count($rows) . "\n";
print_r($kpis);

