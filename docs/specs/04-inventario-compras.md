# Especificación: Inventario y Compras (Fase 4 y Regla 6)

## 1. Modelos de Datos

**Entidad: Inventario**
- `producto_id` (Relación con Producto)
- `cantidad_actual` (Decimal)
- `stock_minimo` (Decimal)

**Entidad: EntradaCompra**
- `id` (UUID)
- `folio` (String)
- `proveedor` (String)
- `fecha` (DateTime)
- `usuario_id` (Relación con Usuario)
- `importe` (Decimal)
- `total` (Decimal)

**Entidad: DetalleEntrada**
- `entrada_id` (Relación con EntradaCompra)
- `producto_id` (Relación con Producto)
- `cantidad` (Decimal)
- `costo_unitario` (Decimal)

**Entidad: MovimientoInventario**
- `id` (UUID)
- `producto_id` (Relación con Producto)
- `cantidad` (Decimal)
- `tipo_movimiento` (Enum: COMPRA, VENTA, KIT_CONSUMO, AJUSTE, MERMA)
- `motivo` (String, nullable)
- `usuario_id` (Relación con Usuario)
- `fecha` (DateTime)
- `referencia_id` (UUID, nullable - Ej. ID de Venta o Entrada
## 2. Reglas de Negocio
- **Ajustes Manuales (Regla 6)**: Los descuentos manuales de inventario estarán restringidos al Administrador. Los cajeros no pueden hacer ajustes ni eliminar productos.
- **Entradas**: Inventario = Inventario actual + cantidad recibida.
- **Consumo Kits**: Al vender un Kit, el sistema inserta registros de MovimientoInventario (tipo KIT_CONSUMO) descontando los insumos (Fase 6.2).

## 3. Casos de Uso
- `RegistrarEntradaCompra`: Aumenta el stock.
- `AjusteInventarioManual`: Realizado solo por admin (entrada, salida, corrección, merma).
- `DescontarInsumosKit`: Proceso interno al confirmar una venta de un producto Kit.
