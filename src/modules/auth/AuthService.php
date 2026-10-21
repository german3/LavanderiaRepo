<?php

namespace App\Modules\Auth;

use PDO;
use Exception;

class AuthService {
    private $conn;
    private $usuarioModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->usuarioModel = new Usuario($db);
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    // Caso de uso: AutenticarUsuario
    public function login($correo, $password) {
        $userData = $this->usuarioModel->buscarPorCorreo($correo);

        if ($userData && password_verify($password, $userData['password_hash'])) {
            
            // Generar sesión en Base de Datos
            $token = bin2hex(random_bytes(32));
            $sessionId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
            
            $query = "INSERT INTO sesiones (id, usuario_id, token) VALUES (:id, :usuario_id, :token)";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':id' => $sessionId,
                ':usuario_id' => $userData['id'],
                ':token' => $token
            ]);

            // Actualizar última actividad
            $this->usuarioModel->registrarActividad($userData['id']);

            // Guardar en sesión PHP
            $_SESSION['usuario_id'] = $userData['id'];
            $_SESSION['rol'] = $userData['rol'];
            $_SESSION['nombre'] = $userData['nombre'];
            $_SESSION['token'] = $token;

            return true;
        }
        return false;
    }

    public function logout() {
        if (isset($_SESSION['token'])) {
            try {
                $query = "UPDATE sesiones SET activa = FALSE WHERE token = :token";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([':token' => $_SESSION['token']]);
            } catch (Exception $e) {}
        }
        unset($_SESSION['usuario_id'], $_SESSION['rol'], $_SESSION['nombre'], $_SESSION['token']);
        if (!isset($_SESSION['pos_usuario_id'])) {
            session_destroy();
        }
    }

    public function checkAuth() {
        if (!isset($_SESSION['usuario_id'])) {
            return false;
        }

        try {
            $query = "SELECT id FROM usuarios WHERE id = :id AND estado = 'ACTIVO' LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':id' => $_SESSION['usuario_id']]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->logout();
                return false;
            }
        } catch (Exception $e) {
            // Si la consulta falla por conexión u otro problema, mantenemos el comportamiento básico
            return isset($_SESSION['usuario_id']);
        }

        return true;
    }

    public function isAdmin() {
        return isset($_SESSION['rol']) && $_SESSION['rol'] === 'ADMINISTRADOR';
    }

    // ─────────────────────────────────────────────────────────────
    // Autenticación FrontOffice POS (Independiente del BackOffice)
    // ─────────────────────────────────────────────────────────────
    public function loginPos($correo, $password) {
        $userData = $this->usuarioModel->buscarPorCorreo($correo);

        if ($userData && password_verify($password, $userData['password_hash'])) {
            if ($userData['estado'] !== 'ACTIVO') {
                return false;
            }

            // Generar sesión en Base de Datos
            $token = bin2hex(random_bytes(32));
            $sessionId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
            
            $query = "INSERT INTO sesiones (id, usuario_id, token) VALUES (:id, :usuario_id, :token)";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':id' => $sessionId,
                ':usuario_id' => $userData['id'],
                ':token' => $token
            ]);

            // Actualizar última actividad
            $this->usuarioModel->registrarActividad($userData['id']);

            // Guardar en sesión PHP con namespace propio de POS
            $_SESSION['pos_usuario_id'] = $userData['id'];
            $_SESSION['pos_rol']        = $userData['rol'];
            $_SESSION['pos_nombre']     = $userData['nombre'];
            $_SESSION['pos_token']      = $token;

            return true;
        }
        return false;
    }

    public function logoutPos() {
        if (isset($_SESSION['pos_token'])) {
            try {
                $query = "UPDATE sesiones SET activa = FALSE WHERE token = :token";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([':token' => $_SESSION['pos_token']]);
            } catch (Exception $e) {}
        }
        unset($_SESSION['pos_usuario_id'], $_SESSION['pos_rol'], $_SESSION['pos_nombre'], $_SESSION['pos_token']);
        if (!isset($_SESSION['usuario_id'])) {
            session_destroy();
        }
    }

    public function checkAuthPos() {
        if (!isset($_SESSION['pos_usuario_id'])) {
            return false;
        }

        try {
            $query = "SELECT id FROM usuarios WHERE id = :id AND estado = 'ACTIVO' LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':id' => $_SESSION['pos_usuario_id']]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->logoutPos();
                return false;
            }
        } catch (Exception $e) {
            return isset($_SESSION['pos_usuario_id']);
        }

        return true;
    }

    public function isPosAdmin() {
        return isset($_SESSION['pos_rol']) && $_SESSION['pos_rol'] === 'ADMINISTRADOR';
    }
}

