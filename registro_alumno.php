<?php
session_start();
require_once 'config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = trim($_POST['nombre']);
    $email = trim($_POST['email']);
    $matricula = trim($_POST['matricula']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    // Validaciones
    if (empty($nombre) || empty($email) || empty($matricula) || empty($password)) {
        $error = "Todos los campos son obligatorios";
    } elseif ($password !== $confirm_password) {
        $error = "Las contraseñas no coinciden";
    } elseif (strlen($password) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres";
    } else {
        try {
            $database = new Database();
            $db = $database->connect();
            
            // Verificar si el email o matrícula ya existen
            $sql = "SELECT id FROM alumnos WHERE email = ? OR matricula = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$email, $matricula]);
            
            if ($stmt->fetch()) {
                $error = "El email o la matrícula ya están registrados";
            } else {
                // Registrar nuevo alumno
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $sql = "INSERT INTO alumnos (nombre, email, matricula, password) VALUES (?, ?, ?, ?)";
                $stmt = $db->prepare($sql);
                $stmt->execute([$nombre, $email, $matricula, $hashed_password]);
                
                $success = "Registro exitoso. Ahora puedes iniciar sesión.";
                
                // Limpiar formulario
                $_POST = [];
            }
        } catch (PDOException $e) {
            $error = "Error en el registro: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Alumnos - Sistema Cuponera</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            width: 100%;
            max-width: 500px;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .form-container {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-weight: 500;
        }
        
        input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        button:hover {
            transform: translateY(-2px);
        }
        
        .error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        
        .success {
            background-color: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }
        
        .login-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .login-link a {
            color: #667eea;
            text-decoration: none;
        }
        
        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Registro de Alumnos</h1>
            <p>Sistema de Beneficio Alimenticio</p>
        </div>
        
        <div class="form-container">
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label>Nombre Completo:</label>
                    <input type="text" name="nombre" required 
                           value="<?php echo isset($_POST['nombre']) ? htmlspecialchars($_POST['nombre']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" name="email" required 
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label>Matrícula:</label>
                    <input type="text" name="matricula" required 
                           value="<?php echo isset($_POST['matricula']) ? htmlspecialchars($_POST['matricula']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label>Contraseña:</label>
                    <input type="password" name="password" required>
                </div>
                
                <div class="form-group">
                    <label>Confirmar Contraseña:</label>
                    <input type="password" name="confirm_password" required>
                </div>
                
                <button type="submit">Registrarse</button>
            </form>
            
            <div class="login-link">
                ¿Ya tienes cuenta? <a href="login_alumno.php">Iniciar Sesión</a>
            </div>
        </div>
    </div>
</body>
</html>