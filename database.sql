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
    '$2y$10$wO3l7V6.G.jE3zD5e5n/9.C1L4V0vI/1W9nE5z0O6D8m/4x3f7tP2', 
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
    costo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    precio_venta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    estado ENUM('ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO',
    usuario_registro_id VARCHAR(36) NOT NULL,
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_registro_id) REFERENCES usuarios(id)
);

CREATE TABLE IF NOT EXISTS productos_kits (
    kit_id VARCHAR(36) NOT NULL,
    insumo_id VARCHAR(36) NOT NULL,
    cantidad_consumida DECIMAL(10,2) NOT NULL,
    unidad VARCHAR(20) NOT NULL,
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
