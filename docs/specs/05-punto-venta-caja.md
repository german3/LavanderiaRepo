# Especificación: Punto de Venta y Caja (Fase 5, 8, 9, 12, 13)

## 1. Modelos de Datos

**Entidad: Venta**
- `id` (UUID)
- `folio` (String, secuencial autogenerado)
- `cliente_id` (Relación con Cliente, nullable)
- `usuario_id` (Relación con Usuario)
- `total` (Decimal)
- `estado` (Enum: ACTIVA, CANCELADA)
- `metodo_pago` (Enum: EFECTIVO, TARJETA, TRANSFERENCIA, PENDIENTE)
- `estado_pago` (Enum: PAGADO, PENDIENTE)
- `fecha_venta` (DateTime)

**Entidad: DetalleVenta**
- `venta_id` (Relación con Venta)
- `producto_id` (Relación con Producto)
- `cantidad` (Decimal)
- `precio_unitario` (Decimal)
- `subtotal` (Decimal)

**Entidad: CancelacionVenta (Fase 12)**
- `venta_id` (Relación con Venta)
- `motivo` (String)
- `usuario_id` (Relación con Usuario)
- `fecha` (DateTime)

**Entidad: CajaCorte (Fase 13)**
- `id` (UUID)
- `usuario_id` (Relación con Usuario)
- `fecha_apertura` (DateTime)
- `monto_inicial` (Decimal)
- `fecha_cierre` (DateTime, nullable)
- `total_efectivo` (Decimal)
- `total_tarjeta` (Decimal)
- `total_transferencia` (Decimal)
- `diferencia` (Decimal)

## 2. Reglas de Negocio
- **Cancelaciones (Regla 2, 3)**: Toda venta debe conservarse aunque sea cancelada. Toda cancelación debe tener motivo.
- **Permisos**: Los cajeros pueden cancelar solo con autorización o, dependiendo de la política, solo el admin puede cancelar (Fase 12.2).
- **Pago Posterior (Regla 8 y Fase 8.2)**: El cliente puede dejar la ropa sin pagar (ESTADO DE PAGO: PENDIENTE). El método de pago queda pendiente hasta que recoja la ropa.
- **Generación de Ticket**: Se genera ticket sin importar si el pago es inmediato o posterior.

## 3. Casos de Uso
- `ProcesarVenta`: Crea la venta. Si incluye Kits, dispara `DescontarInsumosKit`. Genera `Carga` automáticamente para los servicios de lavandería.
- `CancelarVenta`: Cambia el estado a CANCELADA, pide motivo, y revierte inventario.
- `RegistrarPagoPosterior`: Cuando el cliente regresa, se cobra y se actualiza `estado_pago`.
- `AperturaCorteCaja`: Control de flujo de dinero diario/por turno.
