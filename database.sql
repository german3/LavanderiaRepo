CREATE DATABASE IF NOT EXISTS lavanderia_db;
USE lavanderia_db;

CREATE TABLE IF NOT EXISTS usuarios (
    id VARCHAR(36) PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    correo VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('ADMINISTRADOR', 'EMPLEADO_CAJERO') NOT NULL,
    estado ENUM('ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO',
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    ultima_actividad DATETIME NULL
);

CREATE TABLE IF NOT EXISTS sesiones (
    id VARCHAR(36) PRIMARY KEY,
    usuario_id VARCHAR(36) NOT NULL,
    fecha_inicio DATETIME DEFAULT CURRENT_TIMESTAMP,
    token VARCHAR(255) NOT NULL,
    activa BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- Insertar un administrador por defecto (password: admin123)
INSERT IGNORE INTO usuarios (id, nombre, correo, password_hash, rol, estado) 
VALUES (
    UUID(), 
    'Administrador Principal', 
    'admin@lavanderia.com', 
    '$2y$10$NPk8o6YgHBOCR1rJL1PdFe8P/eSU.dzwiL5.kr/FnOOHYyPGDl/q2', 
    'ADMINISTRADOR', 
    'ACTIVO'
);

CREATE TABLE IF NOT EXISTS productos (
    id VARCHAR(36) PRIMARY KEY,
    codigo_barras VARCHAR(50) UNIQUE,
    codigo_interno_sku VARCHAR(50),
    descripcion VARCHAR(255) NOT NULL,
    tipo ENUM('NORMAL', 'SERVICIO', 'KIT', 'INSUMO') NOT NULL,
    unidad_medida ENUM('PIEZA', 'KG', 'GRAMO', 'LITRO', 'MILILITRO', 'METRO', 'SERVICIO', 'CARGA') NOT NULL,
    stock_cantidad DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock_minimo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ropa_kg DECIMAL(10,2) DEFAULT 0.00,
    costo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    precio_venta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    estado ENUM('ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO',
    usuario_registro_id VARCHAR(36) NOT NULL,
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_registro_id) REFERENCES usuarios(id)
);

-- Migraciones manuales para BD existente:
-- ALTER TABLE productos ADD COLUMN stock_cantidad DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unidad_medida;
-- ALTER TABLE productos ADD COLUMN stock_minimo DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER stock_cantidad;
-- ALTER TABLE productos ADD COLUMN ropa_kg DECIMAL(10,2) DEFAULT 0.00 AFTER stock_cantidad;
-- ALTER TABLE productos_kits ADD COLUMN ropa_kg DECIMAL(10,2) DEFAULT 0.00 AFTER unidad;

CREATE TABLE IF NOT EXISTS productos_kits (
    kit_id VARCHAR(36) NOT NULL,
    insumo_id VARCHAR(36) NOT NULL,
    cantidad_consumida DECIMAL(10,2) NOT NULL,
    unidad VARCHAR(20) NOT NULL,
    ropa_kg DECIMAL(10,2) DEFAULT 0.00,
    PRIMARY KEY (kit_id, insumo_id),
    FOREIGN KEY (kit_id) REFERENCES productos(id) ON DELETE CASCADE,
    FOREIGN KEY (insumo_id) REFERENCES productos(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS clientes (
    id VARCHAR(36) PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    telefono VARCHAR(20) NOT NULL,
    whatsapp_disponible TINYINT(1) DEFAULT 0,
    correo VARCHAR(100) NULL,
    direccion VARCHAR(255) NULL,
    observaciones TEXT NULL,
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('ACTIVO','INACTIVO') DEFAULT 'ACTIVO'
);

-- ═══════════════════════════════════════════════════════════════════════════
-- Spec 04: Inventario y Compras
-- Las tablas se crean automáticamente via asegurarTablas() en los modelos PHP,
-- pero se documentan aquí para referencia y para bases de datos nuevas.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS entradas_compras (
    id          VARCHAR(36)   PRIMARY KEY,
    folio       VARCHAR(50)   NOT NULL UNIQUE,
    proveedor   VARCHAR(255)  NOT NULL,
    fecha       DATETIME      DEFAULT CURRENT_TIMESTAMP,
    usuario_id  VARCHAR(36)   NOT NULL,
    importe     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS detalle_entradas (
    id              VARCHAR(36)   PRIMARY KEY,
    entrada_id      VARCHAR(36)   NOT NULL,
    producto_id     VARCHAR(36)   NOT NULL,
    cantidad        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    costo_unitario  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (entrada_id)  REFERENCES entradas_compras(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS movimientos_inventario (
    id               VARCHAR(36)   PRIMARY KEY,
    producto_id      VARCHAR(36)   NOT NULL,
    cantidad         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tipo_movimiento  ENUM('COMPRA','VENTA','KIT_CONSUMO','AJUSTE','MERMA') NOT NULL,
    motivo           TEXT          NULL,
    usuario_id       VARCHAR(36)   NULL,
    fecha            DATETIME      DEFAULT CURRENT_TIMESTAMP,
    referencia_id    VARCHAR(36)   NULL,
    costo_unitario   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock_antes      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock_despues    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    INDEX (producto_id),
    INDEX (tipo_movimiento),
    INDEX (fecha),
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- Spec 05: Punto de Venta (POS), Cortes de Caja y Cargas de Lavandería
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS caja_cortes (
    id                  VARCHAR(36) PRIMARY KEY,
    usuario_id          VARCHAR(36) NOT NULL,
    fecha_apertura      DATETIME DEFAULT CURRENT_TIMESTAMP,
    monto_inicial       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fecha_cierre        DATETIME NULL,
    total_efectivo      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_tarjeta       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_transferencia DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    efectivo_declarado  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    diferencia          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    observaciones       TEXT NULL,
    estado              ENUM('ABIERTA', 'CERRADA') NOT NULL DEFAULT 'ABIERTA',
    INDEX (usuario_id),
    INDEX (estado),
    INDEX (fecha_apertura)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ventas (
    id             VARCHAR(36) PRIMARY KEY,
    folio          VARCHAR(50) NOT NULL UNIQUE,
    cliente_id     VARCHAR(36) NULL,
    usuario_id     VARCHAR(36) NOT NULL,
    caja_id        VARCHAR(36) NULL,
    total          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estado         ENUM('ACTIVA', 'CANCELADA') NOT NULL DEFAULT 'ACTIVA',
    metodo_pago    ENUM('EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'PENDIENTE') NOT NULL DEFAULT 'EFECTIVO',
    estado_pago    ENUM('PAGADO', 'PENDIENTE') NOT NULL DEFAULT 'PAGADO',
    monto_recibido DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cambio         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fecha_venta    DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_pago     DATETIME NULL,
    observaciones  TEXT NULL,
    INDEX (cliente_id),
    INDEX (usuario_id),
    INDEX (caja_id),
    INDEX (estado),
    INDEX (estado_pago),
    INDEX (fecha_venta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS detalle_ventas (
    id              VARCHAR(36) PRIMARY KEY,
    venta_id        VARCHAR(36) NOT NULL,
    producto_id     VARCHAR(36) NOT NULL,
    cantidad        DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    precio_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ropa_kg         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notas           TEXT NULL,
    INDEX (venta_id),
    INDEX (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cancelaciones_ventas (
    id         VARCHAR(36) PRIMARY KEY,
    venta_id   VARCHAR(36) NOT NULL UNIQUE,
    motivo     TEXT NOT NULL,
    usuario_id VARCHAR(36) NOT NULL,
    fecha      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (venta_id),
    INDEX (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cargas (
    id                    VARCHAR(36) PRIMARY KEY,
    numero_carga          VARCHAR(50) NOT NULL UNIQUE,
    venta_id              VARCHAR(36) NOT NULL,
    cliente_id            VARCHAR(36) NULL,
    estado                ENUM('RECIBIDA', 'LISTA_CONCLUIDA', 'ENTREGADA', 'CANCELADA') NOT NULL DEFAULT 'RECIBIDA',
    estado_pago           ENUM('PENDIENTE', 'PAGADO') NOT NULL DEFAULT 'PAGADO',
    total                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ropa_kg               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    fecha_recepcion       DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_conclusion      DATETIME NULL,
    fecha_entrega         DATETIME NULL,
    usuario_registro_id   VARCHAR(36) NOT NULL,
    usuario_conclusion_id VARCHAR(36) NULL,
    observaciones         TEXT NULL,
    INDEX (venta_id),
    INDEX (cliente_id),
    INDEX (estado),
    INDEX (estado_pago)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS historial_movimientos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
    tipo_movimiento VARCHAR(50) NOT NULL,
    producto_id VARCHAR(50) NULL,
    producto_sku VARCHAR(100) NULL,
    producto_descripcion VARCHAR(255) NOT NULL,
    tipo_producto VARCHAR(50) DEFAULT 'NORMAL',
    cantidad DECIMAL(10,2) DEFAULT 0.00,
    costo_unitario DECIMAL(10,2) DEFAULT 0.00,
    stock_antes DECIMAL(10,2) DEFAULT 0.00,
    stock_despues DECIMAL(10,2) DEFAULT 0.00,
    motivo TEXT NULL,
    kit_detalle TEXT NULL,
    usuario_id INT NULL,
    usuario_nombre VARCHAR(255) NULL,
    INDEX (fecha_hora),
    INDEX (tipo_movimiento),
    INDEX (producto_sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



