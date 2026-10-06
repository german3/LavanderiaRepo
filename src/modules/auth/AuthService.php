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
        if(isset($_SESSION['token'])) {
            $query = "UPDATE sesiones SET activa = FALSE WHERE token = :token";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':token' => $_SESSION['token']]);
        }
        session_destroy();
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
}
