<?php

namespace App\Config;

use PDO;
use PDOException;

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $port;
    public $conn;

    public function __construct() {
        $get = function($key, $default = null) {
            $val = getenv($key);
            if ($val !== false && $val !== '') return $val;
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
            if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
            return $default;
        };

        // Soporte para URL completa de conexión (por ejemplo Service URI de Aiven o Render DATABASE_URL / MYSQL_URL)
        $dbUrl = $get('DATABASE_URL') ?: $get('MYSQL_URL') ?: $get('MYSQL_URI');
        if ($dbUrl) {
            $parsed = parse_url($dbUrl);
            if ($parsed) {
                $this->host     = $parsed['host'] ?? 'localhost';
                $this->port     = isset($parsed['port']) ? (string)$parsed['port'] : '3306';
                $this->username = isset($parsed['user']) ? urldecode($parsed['user']) : 'root';
                $this->password = isset($parsed['pass']) ? urldecode($parsed['pass']) : '';
                $this->db_name  = isset($parsed['path']) ? ltrim($parsed['path'], '/') : 'lavanderia_db';
            }
        } else {
            // Parámetros individuales (con sinónimos comunes)
            $this->host     = $get('DB_HOST', "localhost");
            $this->port     = $get('DB_PORT', "3306");
            $this->username = $get('DB_USER', $get('DB_USERNAME', "root"));
            $this->password = $get('DB_PASS', $get('DB_PASSWORD', ""));
            $this->db_name  = $get('DB_NAME', $get('DB_DATABASE', "lavanderia_db"));
        }
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];

            // Si es conexión a la nube (como Aiven), configurar SSL
            if ($this->host !== 'localhost' && $this->host !== '127.0.0.1') {
                // Deshabilitar la verificación estricta de certificado para compatibilidad con la nube
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;

                // Buscar certificado CA si se especificó explícitamente o si existe un archivo físico
                $get = function($key) {
                    $val = getenv($key);
                    if ($val !== false && $val !== '') return $val;
                    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
                    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
                    return null;
                };

                $caPath = $get('DB_SSL_CA');
                if (!$caPath) {
                    $candidates = [
                        __DIR__ . '/ca.pem',
                        dirname(__DIR__, 2) . '/ca.pem',
                        '/etc/ssl/certs/ca-certificates.crt',
                    ];
                    foreach ($candidates as $candidate) {
                        if (file_exists($candidate)) {
                            $caPath = $candidate;
                            break;
                        }
                    }
                }

                // IMPORTANTE: PDO::MYSQL_ATTR_SSL_CA debe ser una ruta string existente, NUNCA booleano true
                if ($caPath && is_string($caPath) && file_exists($caPath)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
                }
            }

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            
        } catch(PDOException $exception) {
            $errMsg = $exception->getMessage();
            error_log("Error de conexión BD: " . $errMsg);

            // Si es una petición JSON/API, responder JSON 500
            if (
                (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
                (isset($_SERVER['REQUEST_URI']) && str_contains($_SERVER['REQUEST_URI'], '/api/'))
            ) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'error'   => 'Error de conexión a la base de datos: ' . $errMsg
                ]);
                exit;
            }

            // Mostrar pantalla amigable con diagnóstico claro
            http_response_code(500);
            echo "
            <!DOCTYPE html>
            <html lang='es'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Error de Base de Datos</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #f8fafc; color: #1e293b; padding: 30px 15px; margin: 0; }
                    .card { max-width: 650px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
                    .header { display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 16px; margin-bottom: 20px; }
                    .header h2 { margin: 0; color: #b91c1c; font-size: 20px; }
                    .details { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 8px; font-family: monospace; font-size: 13px; word-break: break-all; margin-bottom: 20px; }
                    .info-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px; }
                    .info-table td { padding: 6px 0; }
                    .info-table td:first-child { font-weight: 600; width: 120px; color: #64748b; }
                    .tips { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 16px; border-radius: 8px; font-size: 13px; }
                    .tips ul { margin: 8px 0 0 20px; padding: 0; }
                    .tips li { margin-bottom: 6px; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='header'>
                        <h2>⚠️ Error de Conexión a la Base de Datos</h2>
                    </div>
                    <p>No se pudo establecer la conexión con el servidor MySQL.</p>
                    <div class='details'>" . htmlspecialchars($errMsg) . "</div>
                    <table class='info-table'>
                        <tr><td>Host:</td><td>" . htmlspecialchars($this->host) . "</td></tr>
                        <tr><td>Puerto:</td><td>" . htmlspecialchars($this->port) . "</td></tr>
                        <tr><td>Base de Datos:</td><td>" . htmlspecialchars($this->db_name) . "</td></tr>
                        <tr><td>Usuario:</td><td>" . htmlspecialchars($this->username) . "</td></tr>
                    </table>
                    <div class='tips'>
                        <strong>Pasos para verificar en Render:</strong>
                        <ul>
                            <li>Revisa en tu servicio de Render (pestaña <strong>Environment</strong>) que las variables <code>DB_HOST</code>, <code>DB_PORT</code>, <code>DB_USER</code>, <code>DB_PASS</code> y <code>DB_NAME</code> (o <code>DATABASE_URL</code>) estén configuradas.</li>
                            <li>En <strong>Aiven</strong>, confirma que el servicio MySQL esté <strong>RUNNING</strong> y revisa el nombre de la base de datos (generalmente <code>defaultdb</code>).</li>
                        </ul>
                    </div>
                </div>
            </body>
            </html>";
            exit;
        }

        return $this->conn;
    }
}
