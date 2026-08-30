<?php
// Script de instalación - Ejecutar una sola vez
require_once 'config.php';

echo "<h1>Instalación del Sistema Cuponera</h1>";

try {
    $database = new Database();
    $db = $database->connect();
    
    echo "<p style='color:green'>✓ Base de datos conectada correctamente</p>";
    
    // Verificar tablas
    $tables = ['alumnos', 'sorteos', 'beneficiados', 'cupones', 'uso_cupones'];
    $all_tables_exist = true;
    
    foreach ($tables as $table) {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name='$table'";
        $stmt = $db->query($sql);
        if (!$stmt->fetch()) {
            $all_tables_exist = false;
            break;
        }
    }
    
    if ($all_tables_exist) {
        echo "<p style='color:green'>✓ Todas las tablas existen</p>";
    } else {
        echo "<p style='color:orange'>⚠ Las tablas se crearán automáticamente al usar el sistema</p>";
    }
    
    echo "<h2>Instalación completada</h2>";
    echo "<p>Puedes comenzar a usar el sistema desde <a href='index.html'>la página principal</a></p>";
    echo "<p><strong>Security:</strong> configure administrator, cafeteria, QR and SMTP secrets through environment variables before signing in.</p>";
    echo "<p style='color:red'><strong>Importante:</strong> elimina o restringe install.php después de completar la instalación.</p>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
?>
