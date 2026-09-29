# Especificación: Clientes (Fase 3 y 10)

## 1. Modelos de Datos

**Entidad: Cliente**
- `id` (UUID)
- `nombre` (String)
- `telefono` (String)
- `whatsapp_disponible` (Boolean)
- `correo` (String, nullable)
- `direccion` (String, nullable)
- `observaciones` (Text, nullable)
- `fecha_registro` (DateTime)
- `estado` (Enum: ACTIVO, INACTIVO)

## 2. Reglas de Negocio
- **WhatsApp**: El número telefónico se utilizará para notificaciones. La notificación solo se envía si `whatsapp_disponible` es `true` (Regla 12).
- **Registro rápido**: Debe ser fácil buscar y registrar clientes al momento de la venta.

## 3. Casos de Uso
- `RegistrarCliente`: Alta de cliente (Cajeros y Admins).
- `BuscarCliente`: Búsqueda por nombre o teléfono en el POS.
- `ActualizarCliente`: Edición de datos del cliente.
