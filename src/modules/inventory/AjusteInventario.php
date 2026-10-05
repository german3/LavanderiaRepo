<?php

namespace App\Modules\Inventory;

use PDO;
use Exception;

/**
 * Caso de Uso: AjusteInventarioManual — Spec 04-inventario-compras.md §3
 *
 * Regla 6: Los descuentos manuales de inventario están restringidos
 *          al ADMINISTRADOR. Los cajeros NO pueden hacer ajustes.
 *
 * Tipos permitidos: AJUSTE (entrada/corrección) | MERMA (salida/pérdida)
 */
class AjusteInventario
{
    private $conn;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    // ─── Helper UUID ────────────────────────────────────────────────────────

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

    // ─── Caso de Uso: AjusteInventarioManual ────────────────────────────────

    /**
     * Realiza un ajuste manual de inventario.
     *
     * Tipos aceptados (Spec §2 Enum MovimientoInventario):
     *   - AJUSTE  → puede ser positivo (entrada/corrección) o negativo (salida)
     *   - MERMA   → descuento por pérdida/daño, siempre negativo en stock
     *
     * @param string $rolUsuario   Se debe pasar $_SESSION['rol']
     * @param array  $datos {
     *   producto_id:     string,
     *   tipo:            'AJUSTE'|'MERMA',
     *   subtipo:         'ENTRADA'|'SALIDA'   (solo para AJUSTE),
     *   cantidad:        float,
     *   motivo:          string,
     *   usuario_id:      string,
     * }
     * @return array { ok: bool, mensaje?: string, error?: string }
     */
    public function realizar(string $rolUsuario, array $datos): array
    {
        // Regla 6: Solo Administrador
        if ($rolUsuario !== 'ADMINISTRADOR') {
            return ['ok' => false, 'error' => 'Acceso denegado. Solo el Administrador puede realizar ajustes manuales de inventario.'];
        }

        try {
            $productoId = trim($datos['producto_id'] ?? '');
            $tipo       = trim($datos['tipo'] ?? 'AJUSTE');
            $subtipo    = trim($datos['subtipo'] ?? 'SALIDA'); // ENTRADA | SALIDA
            $cantidad   = abs(floatval($datos['cantidad'] ?? 0));
            $motivo     = trim($datos['motivo'] ?? '');

            if (empty($productoId)) {
                return ['ok' => false, 'error' => 'Producto no especificado.'];
            }
            if ($cantidad <= 0) {
                return ['ok' => false, 'error' => 'La cantidad debe ser mayor a 0.'];
            }
            if (!in_array($tipo, ['AJUSTE', 'MERMA'])) {
                return ['ok' => false, 'error' => 'Tipo de ajuste inválido.'];
            }

            // Obtener stock actual
            $stmtProd = $this->conn->prepare(
                "SELECT id, descripcion, stock_cantidad, costo FROM productos WHERE id = :id"
            );
            $stmtProd->execute([':id' => $productoId]);
            $prod = $stmtProd->fetch(PDO::FETCH_ASSOC);

            if (!$prod) {
                return ['ok' => false, 'error' => 'Producto no encontrado.'];
            }

            $stockAntes = floatval($prod['stock_cantidad']);

            // Determinar signo del movimiento
            // MERMA siempre descuenta; AJUSTE depende del subtipo
            $esDescuento = ($tipo === 'MERMA') || ($tipo === 'AJUSTE' && $subtipo === 'SALIDA');

            if ($esDescuento && $stockAntes < $cantidad) {
                return [
                    'ok'    => false,
                    'error' => "Stock insuficiente para el ajuste. Existencias actuales: " . number_format($stockAntes, 2),
                ];
            }

            $stockDespues = $esDescuento
                ? $stockAntes - $cantidad
                : $stockAntes + $cantidad;

            $this->conn->beginTransaction();

            // Actualizar stock en productos
            $delta = $esDescuento ? -$cantidad : $cantidad;
            $stmtUpd = $this->conn->prepare(
                "UPDATE productos SET stock_cantidad = stock_cantidad + :delta WHERE id = :id"
            );
            $stmtUpd->execute([':delta' => $delta, ':id' => $productoId]);

            // Registrar MovimientoInventario
            $movModel = new MovimientoInventario($this->conn);
            $movModel->registrar([
                'producto_id'     => $productoId,
                'cantidad'        => $cantidad,
                'tipo_movimiento' => $tipo,  // AJUSTE o MERMA
                'motivo'          => $motivo ?: ($tipo === 'MERMA' ? 'Merma de inventario' : 'Ajuste manual'),
                'usuario_id'      => $datos['usuario_id'] ?? null,
                'referencia_id'   => null,
                'costo_unitario'  => floatval($prod['costo']),
                'stock_antes'     => $stockAntes,
                'stock_despues'   => $stockDespues,
            ]);

            $this->conn->commit();

            $signo = $esDescuento ? '-' : '+';
            return [
                'ok'      => true,
                'mensaje' => "Ajuste registrado exitosamente ({$signo}" . number_format($cantidad, 2) . " {$tipo}).",
            ];

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("AjusteInventario::realizar — " . $e->getMessage());
            return ['ok' => false, 'error' => 'Error al realizar ajuste: ' . $e->getMessage()];
        }
    }
}
