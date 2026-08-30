<?php
session_start();

// Configure comma-separated password hashes through APP_CAFETERIA_PASSWORD_HASHES.
$cafeteriaHashList = array_values(array_filter(array_map('trim', explode(',', getenv('APP_CAFETERIA_PASSWORD_HASHES') ?: ''))));
$cafeteria_users = [];
foreach ($cafeteriaHashList as $index => $hash) {
    $cafeteria_users['cafeteria' . ($index + 1)] = $hash;
}

$error = '';

/**
 * Determina qué cafetería tiene la exclusividad.
 * Si no hay convocatoria activa o es fin de semana, devuelve 'libre'.
 */
function obtenerValidacionTurno() {
    $inicio_referencia = new DateTime('2026-05-11'); 
    $hoy = new DateTime();
    
    // Si aún no empezamos el calendario oficial, acceso libre
    if ($hoy < $inicio_referencia) return 'libre';
    
    $intervalo = $inicio_referencia->diff($hoy);
    $dias = $intervalo->days;
    
    // Calculamos en qué día del ciclo de 14 días estamos (0 a 13)
    $dia_del_ciclo = $dias % 14;
    
    // Si estamos en fin de semana (día 10, 11, 12 y 13 del ciclo), acceso libre
    if ($dia_del_ciclo >= 10) return 'libre';

    // Si estamos en días hábiles, calculamos el turno
    $num_convocatoria = floor($dias / 14) + 1;
    $turno = (($num_convocatoria - 1) % 3) + 1;
    
    return 'cafeteria' . $turno;
}

// Definimos la variable que usaremos tanto en la lógica como en el HTML
$turno_actual = obtenerValidacionTurno();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // 1. Validar credenciales
    if (isset($cafeteria_users[$username]) && password_verify($password, $cafeteria_users[$username])) {
        
        // 2. Validar Turno: Entra si es su turno O si el acceso es libre
        if ($turno_actual === 'libre' || $username === $turno_actual) {
            $_SESSION['cafeteria_logged'] = true;
            $_SESSION['cafeteria_user'] = $username;
            header('Location: validar_cupon.php');
            exit();
        } else {
            $error = "Acceso restringido. En este periodo solo puede ingresar " . strtoupper($turno_actual);
        }
        
    } else {
        $error = "Usuario o contraseña incorrectos";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cafetería Login - Sistema Cuponera</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; }
        .container { background: white; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); width: 100%; max-width: 400px; padding: 30px; }
        h1 { text-align: center; color: #333; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #666; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; color: #333; }
        input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; }
        button { width: 100%; padding: 12px; background: #ff9800; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; }
        button:hover { background: #e68900; }
        .error { background-color: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px; margin-bottom: 20px; text-align: center; font-size: 14px; }
        .info { background-color: #d1ecf1; color: #0c5460; padding: 10px; border-radius: 5px; margin-top: 20px; font-size: 14px; text-align: center; }
        .turno-alert { font-weight: bold; color: #764ba2; display: block; margin-top: 5px; text-transform: uppercase; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Sistema de Cafetería</h1>
        <div class="subtitle">Validación de Cupones</div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Usuario:</label>
                <input type="text" name="username" placeholder="ej. cafeteria1" required>
            </div>
            
            <div class="form-group">
                <label>Contraseña:</label>
                <input type="password" name="password" required>
            </div>
            
            <button type="submit">Ingresar</button>
        </form>
        
        <div class="info">
            Turno actual: 
            <span class="turno-alert">
                <?php echo ($turno_actual === 'libre') ? 'Acceso Libre (Pruebas/Descanso)' : $turno_actual; ?>
            </span>
            <hr style="margin: 10px 0; border: 0; border-top: 1px solid #bee5eb;">
            <strong>Nota:</strong> 
            <?php echo ($turno_actual === 'libre') 
                ? "Cualquier sucursal puede ingresar en este momento." 
                : "Solo la cafetería asignada puede validar cupones en este bloque."; 
            ?>
        </div>
    </div>
</body>
</html>
