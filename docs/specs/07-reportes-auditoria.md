# Especificación: Reportes, Dashboard y Auditoría (Fase 14, 15 y 16)

## 1. Modelos de Datos

**Entidad: LogAuditoria (Fase 16)**
- `id` (UUID)
- `usuario_id` (Relación con Usuario)
- `accion` (String - Ej. "Creación de producto", "Ajuste de inventario", "Cancelación")
- `modulo` (String - Ej. "Inventario", "Ventas")
- `registro_afectado_id` (String, nullable - ID del recurso)
- `fecha_hora` (DateTime)
- `info_anterior` (JSON, nullable)
- `info_nueva` (JSON, nullable)

## 2. Reglas de Negocio
- **Auditoría Estricta**: Se debe registrar quién realizó cada acción importante en el sistema. 
- **Dashboard en Tiempo Real (Regla 10)**: Las cargas activas y los productos con stock bajo deben aparecer directamente en el dashboard y actualizarse (Idealmente o recargarse al abrir).
- **Consistencia de Reportes**: Los reportes no modifican la base de datos, son únicamente de lectura (Queries complejas con filtros).

## 3. Casos de Uso
- `RegistrarAuditoria`: Función o Middleware interno utilizado por los demás servicios para guardar el Log de Auditoría.
- `GenerarReporteVentas`: Filtra por fechas, usuario, método de pago, cliente y estado.
- `GenerarReporteInventario`: Calcula el valor del inventario (Stock x Costo).
- `ObtenerMetricasDashboard`: 
  - `getCargasActivasCount`: (Estado RECIBIDA o LISTA_CONCLUIDA).
  - `getProductosStockBajo`: (cantidad_actual <= stock_minimo).
