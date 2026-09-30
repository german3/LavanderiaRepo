# Especificación: Alta de Productos (Fase 2 y 6)

## 1. Modelos de Datos

**Entidad: Producto**

- `id` (UUID)
- `codigo_barras` (String, único, autogenerable)
- `codigo_interno_sku` (String)
- `descripcion` (String)
- `tipo` (Enum: NORMAL, SERVICIO, KIT)
- `unidad_medida` (Enum: PIEZA, KG, GRAMO, LITRO, MILILITRO, METRO, SERVICIO, CARGA)
- `costo` (Decimal)
- `precio_venta` (Decimal)
- `estado` (Enum: ACTIVO, INACTIVO)

**Entidad: ProductoKit (Relación muchos a muchos para Insumos)**

- `kit_id` (Relación con Producto tipo KIT)
- `insumo_id` (Relación con Producto tipo NORMAL)
- `cantidad_consumida` (Decimal)
- `unidad` (String)

## 2. Reglas de Negocio

- **Código de Barras**: Debe poder autogenerarse si no se provee. Sirve para búsqueda, ventas e inventarios.
- **Edición**: Solo administradores pueden modificar descripciones, códigos, costos y precios.
- **Kits (Fase 6)**: Los productos tipo KIT consumen automáticamente varios productos del inventario (Regla 5).
  - Ejemplo: Al vender una "Carga grande" (Kit), se descuentan 100ml de Sunitel, 150ml Jabón, etc.

## 3. Casos de Uso

- `CrearProducto`: Alta de nuevo producto.
- `ConfigurarKit`: Asociar insumos (ProductoNormal) y cantidades a un ProductoKit.
- `GenerarCodigoBarras`: Generación automática de un EAN-13 o similar.
