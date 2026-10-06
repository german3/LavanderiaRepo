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
    tipo ENUM('NORMAL', 'SERVICIO', 'KIT') NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS detalle_entradas (
    id              VARCHAR(36)   PRIMARY KEY,
    entrada_id      VARCHAR(36)   NOT NULL,
    producto_id     VARCHAR(36)   NOT NULL,
    cantidad        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    costo_unitario  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (entrada_id)  REFERENCES entradas_compras(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

