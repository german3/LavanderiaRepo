# Especificación: Seguridad y Usuarios (Fase 1)

## 1. Modelos de Datos

**Entidad: Usuario**
- `id` (UUID)
- `nombre` (String)
- `correo` (String, único)
- `password_hash` (String)
- `rol` (Enum: ADMINISTRADOR, EMPLEADO_CAJERO)
- `estado` (Enum: ACTIVO, INACTIVO)
- `fecha_creacion` (DateTime)
- `ultima_actividad` (DateTime, nullable)

**Entidad: Sesion**
- `id` (UUID)
- `usuario_id` (Relación con Usuario)
- `fecha_inicio` (DateTime)
- `token` (String)
- `activa` (Boolean)

## 2. Reglas de Negocio
- **Login Requerido**: El sistema debe saber en todo momento qué usuario está realizando una operación.
- **Auditoría (Regla 1 y 4)**: Toda venta debe tener un usuario responsable. Toda modificación importante debe quedar registrada en auditoría.
- **Restricciones de Rol (Cajero)**: 
  - No puede modificar inventario manualmente.
  - No puede eliminar productos ni modificar costos.
  - No puede cancelar operaciones sin autorización.
  - No puede acceder a configuraciones administrativas.
- **Privilegios (Administrador)**: Acceso completo, puede crear usuarios, realizar ajustes de inventario, etc.

## 3. Casos de Uso
- `AutenticarUsuario`: Login con correo y contraseña. Registra la sesión y fecha de inicio.
- `RegistrarUsuario`: Solo el administrador puede crear usuarios.
- `ActualizarActividad`: Registrar la última actividad del usuario en el sistema.
- `DesactivarUsuario`: Solo el administrador puede desactivar usuarios.
