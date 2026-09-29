# Especificación: Gestión de Cargas (Fase 7, 11, 17 y Reglas)

## 1. Modelos de Datos

**Entidad: Carga**
- `id` (UUID)
- `numero_carga` (String, secuencial)
- `venta_id` (Relación con Venta)
- `cliente_id` (Relación con Cliente)
- `estado` (Enum: RECIBIDA, LISTA_CONCLUIDA, ENTREGADA, CANCELADA)
- `estado_pago` (Copia referencial o alias a Venta.estado_pago: PENDIENTE, PAGADO)
- `total` (Decimal)
- `fecha_recepcion` (DateTime)
- `fecha_conclusion` (DateTime, nullable)
- `fecha_entrega` (DateTime, nullable)
- `usuario_registro_id` (Relación con Usuario)
- `usuario_conclusion_id` (Relación con Usuario, nullable)
- `observaciones` (String, nullable)

## 2. Reglas de Negocio
- **Independencia de Pago y Lavado (Regla 7)**: Una carga puede estar pagada o pendiente independientemente de su estado de lavado.
- **Ticket Inicial (Regla 8)**: Una carga pendiente de pago puede generar ticket y continuar su proceso.
- **Verificación en Entrega (Regla 9)**: Antes de entregar una carga (pasar a ENTREGADA), se deberá verificar el estado de pago. Si está pendiente, forzar el cobro.
- **Registro de Conclusión (Regla 11)**: Al concluir una carga se debe registrar el usuario, fecha y hora.
- **Notificaciones (Fase 10, Regla 12)**: Si el cliente tiene `whatsapp_disponible`, se le notifica automáticamente en RECIBIDA y en LISTA_CONCLUIDA.

## 3. Casos de Uso
- `CrearCargaDesdeVenta`: Ocurre de forma automática desde `ProcesarVenta` si la venta incluye servicios de lavandería.
- `ActualizarEstadoCarga`: 
  - RECIBIDA -> LISTA_CONCLUIDA (Registra usuario_conclusion y fecha, dispara WhatsApp).
  - LISTA_CONCLUIDA -> ENTREGADA (Valida pago previo o cobra en el momento).
- `DashboardCargas`: Consulta rápida de cargas activas (RECIBIDA, LISTA) para el Dashboard (Fase 11 y Regla 10).
