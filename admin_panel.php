<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(0, '/cuponera-ver1.5/'); 
    session_start();
}

require_once 'config.php'; 
require_once 'NotificadorService.php'; 
require_once 'sorteo.php'; 

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.html'); 
    exit();
}

if (!isset($_SESSION['admin_logged'])) {
    header('Location: admin_login.php');
    exit();
}

$_SESSION['cafeteria_logged'] = true; 
$_SESSION['cafeteria_user'] = 'Administrador';

$database = new Database();
$db = $database->connect();
$notificador = new NotificadorService($db);
$sistema = new SistemaCuponera();

$mensaje_swal = null;

// Lógica de Convocatorias (Lunes a Viernes cada 2 semanas)
function determinarPeriodo($fecha_inicio) {
    $inicio_referencia = new DateTime('2026-05-11'); 
    $fecha_actual = new DateTime($fecha_inicio);
    
    if ($fecha_actual < $inicio_referencia) return "Fuera de rango";
    
    $intervalo = $inicio_referencia->diff($fecha_actual);
    $dias = $intervalo->days;
    
    $numero_convocatoria = floor($dias / 14) + 1;
    return "C" . $numero_convocatoria . " (Bloque " . $numero_convocatoria . ")";
}

// Lógica para la eliminación
if (isset($_POST['eliminar_alumno'])) {
    $id_borrar = $_POST['alumno_id'];
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM cupones WHERE alumno_id = ?")->execute([$id_borrar]);
        $db->prepare("DELETE FROM beneficiados WHERE alumno_id = ?")->execute([$id_borrar]);
        $db->prepare("DELETE FROM alumnos WHERE id = ?")->execute([$id_borrar]);
        $db->commit();
        $mensaje_swal = ['icon' => 'success', 'title' => 'Eliminado', 'text' => 'El alumno ha sido borrado.'];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $mensaje_swal = ['icon' => 'error', 'title' => 'Error', 'text' => $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['registrar_solo_db'])) {
    $nombre = $_POST['nombre'];
    $matricula = $_POST['matricula'];
    $email = $_POST['email'];
    $fecha_inicio = $_POST['fecha_inicio'];
    $fecha_fin = $_POST['fecha_fin'];

    try {
        $db->beginTransaction();

        // 1. Intentar encontrar al alumno por matrícula o por email para obtener su ID real
        $search = $db->prepare("SELECT id FROM alumnos WHERE matricula = ? OR email = ? LIMIT 1");
        $search->execute([$matricula, $email]);
        $alumnoExistente = $search->fetch(PDO::FETCH_ASSOC);

        if ($alumnoExistente) {
            $alumno_id = $alumnoExistente['id'];
            // Actualizamos todos los datos usando el ID, esto permite cambiar matricula o email sin errores de UNIQUE
            $stmt = $db->prepare("UPDATE alumnos SET nombre = ?, email = ?, matricula = ? WHERE id = ?");
            $stmt->execute([$nombre, $email, $matricula, $alumno_id]);
        } else {
            // Es un alumno totalmente nuevo
            $stmt = $db->prepare("INSERT INTO alumnos (nombre, email, matricula, password) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nombre, $email, $matricula, password_hash($matricula, PASSWORD_DEFAULT)]);
            $alumno_id = $db->lastInsertId();
        }

        // 2. Manejar la tabla 'beneficiados'
        $checkB = $db->prepare("SELECT correo_enviado, fecha_inicio, fecha_fin FROM beneficiados WHERE alumno_id = ?");
        $checkB->execute([$alumno_id]);
        $beneficiadoData = $checkB->fetch(PDO::FETCH_ASSOC);

        if ($beneficiadoData) {
            $nuevoEstadoCorreo = $beneficiadoData['correo_enviado'];
            // Si cambian las fechas, reseteamos el envío del correo
            if ($beneficiadoData['fecha_inicio'] !== $fecha_inicio || $beneficiadoData['fecha_fin'] !== $fecha_fin) {
                $nuevoEstadoCorreo = 0;
            }
            $updateB = $db->prepare("UPDATE beneficiados SET fecha_inicio = ?, fecha_fin = ?, correo_enviado = ? WHERE alumno_id = ?");
            $updateB->execute([$fecha_inicio, $fecha_fin, $nuevoEstadoCorreo, $alumno_id]);
        } else {
            $insertB = $db->prepare("INSERT INTO beneficiados (alumno_id, fecha_inicio, fecha_fin, estado, correo_enviado) VALUES (?, ?, ?, 'activo', 0)");
            $insertB->execute([$alumno_id, $fecha_inicio, $fecha_fin]);
        }

        // 3. Cupones: Solo generar si no tiene
        $checkC = $db->prepare("SELECT COUNT(*) FROM cupones WHERE alumno_id = ?");
        $checkC->execute([$alumno_id]);
        if ($checkC->fetchColumn() == 0) {
            for ($i = 1; $i <= 10; $i++) {
                $codigo = "BECA-" . $matricula . "-" . $i . "-" . bin2hex(random_bytes(2));
                $db->prepare("INSERT INTO cupones (alumno_id, codigo, dia, usado) VALUES (?, ?, ?, 0)")->execute([$alumno_id, $codigo, $i]);
            }
        }
        
        $db->commit();
        $mensaje_swal = ['icon' => 'success', 'title' => '¡Éxito!', 'text' => "Datos de $nombre guardados correctamente."];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        // Si el error persiste (por ejemplo, intentas poner un email que YA tiene otro alumno distinto)
        $msg = (strpos($e->getMessage(), 'UNIQUE') !== false) ? "Ese correo o matrícula ya pertenece a otro alumno." : $e->getMessage();
        $mensaje_swal = ['icon' => 'error', 'title' => 'Error de duplicado', 'text' => $msg];
    }
}

if (isset($_POST['confirmar_envio'])) {
    $sql_pendientes = "SELECT a.id, b.id as beneficiado_id FROM alumnos a JOIN beneficiados b ON a.id = b.alumno_id WHERE b.correo_enviado = 0 AND b.estado = 'activo' AND date('now') BETWEEN b.fecha_inicio AND b.fecha_fin";
    $pendientes = $db->query($sql_pendientes)->fetchAll(PDO::FETCH_ASSOC);
    $enviados = 0;
    foreach ($pendientes as $alum) {
        if ($notificador->enviarDocumentacionBeca($alum['id'])) {
            $db->prepare("UPDATE beneficiados SET correo_enviado = 1 WHERE id = ?")->execute([$alum['beneficiado_id']]);
            $enviados++;
        }
    }
    $mensaje_swal = ($enviados > 0) ? ['icon' => 'success', 'title' => 'Enviados', 'text' => "Se enviaron $enviados correos."] : ['icon' => 'info', 'title' => 'Sin Pendientes', 'text' => "Nada que enviar."];
}

$beneficiados = $sistema->getAlumnosBeneficiados();
$hoy = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Panel de Becas</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; min-height: 100vh; }
        .top-bar { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .container { display: grid; grid-template-columns: 380px 1fr; gap: 25px; max-width: 1600px; margin: 30px auto; padding: 0 20px; }
        .card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
        .card h2 { font-size: 22px; color: #333; margin-bottom: 20px; border-bottom: 2px solid #f0f2f5; padding-bottom: 10px; }
        .form-group { margin-bottom: 18px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 0.85em; color: #666; }
        input { width: 100%; padding: 12px; border: 2px solid #edf2f7; border-radius: 10px; outline: none; transition: border-color 0.3s; }
        input:focus { border-color: #f093fb; }
        .btn { border: none; padding: 14px; border-radius: 10px; cursor: pointer; font-weight: bold; width: 100%; color: white; transition: transform 0.2s, box-shadow 0.2s; margin-bottom: 10px; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .btn-warning { background: linear-gradient(135deg, #f6d365 0%, #fda085 100%); }
        .btn-danger-sm { background: #ff4d4d; padding: 8px 12px; font-size: 0.75em; border: none; border-radius: 8px; color: white; cursor: pointer; }
        .btn-edit-sm { background: #4a90e2; padding: 8px 12px; font-size: 0.75em; border: none; border-radius: 8px; color: white; cursor: pointer; margin-right: 5px; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: 15px; text-align: left; font-size: 0.85em; color: #718096; text-transform: uppercase; }
        td { padding: 15px; border-bottom: 1px solid #f0f2f5; color: #4a5568; }
        .badge { padding: 6px 12px; border-radius: 8px; font-size: 0.7em; font-weight: bold; }
        .bg-success { background: #c6f6d5; color: #22543d; }
        .bg-pending { background: #feebc8; color: #744210; }
        .periodo-tag { background: #e2e8f0; color: #4a5568; padding: 4px 10px; border-radius: 6px; font-weight: 600; font-size: 0.8em; }
        .logout-btn { color: white; text-decoration: none; border: 2px solid rgba(255,255,255,0.4); padding: 8px 18px; border-radius: 10px; }
    </style>
</head>
<body>
    <div class="top-bar">
        <h3 style="margin:0;">👨‍💼 PANEL DE ADMINISTRACIÓN</h3>
        <a href="#" onclick="confirmarSalida()" class="logout-btn">Cerrar Sesión</a>
    </div>

    <div class="container">
        <div class="card">
            <h2 id="titulo-form">Registrar Beneficiario</h2>
            <form method="POST" id="form-alumno">
                <div class="form-group"><label>Nombre Completo</label><input type="text" name="nombre" id="f-nombre" required></div>
                <div class="form-group"><label>Matrícula</label><input type="text" name="matricula" id="f-matricula" required></div>
                <div class="form-group"><label>Correo Institucional</label><input type="email" name="email" id="f-email" required></div>
                <div class="form-group"><label>Fecha Inicio (Lunes)</label><input type="date" name="fecha_inicio" id="f-inicio" required></div>
                <div class="form-group"><label>Fecha Fin (Viernes)</label><input type="date" name="fecha_fin" id="f-fin" required></div>
                <button type="submit" name="registrar_solo_db" class="btn btn-primary" id="btn-submit">Guardar Alumno</button>
                <button type="button" onclick="limpiarFormulario()" class="btn" style="background:#cbd5e0; color:#4a5568; display:none;" id="btn-cancelar">Cancelar Edición</button>
            </form>
            <div style="margin-top: 10px; border-top: 1px solid #eee; padding-top: 20px;">
                <button type="button" onclick="confirmarEnvioMasivo()" class="btn btn-warning">✉️ Enviar Correos Pendientes</button>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 25px;">Lista de Beneficiarios</h2>
            <div class="table-container">
                <table id="tablaReporte">
                    <thead>
                        <tr>
                            <th>Alumno</th>
                            <th>Vigencia</th>
                            <th>Convocatoria</th>
                            <th>Correo</th>
                            <th>Uso</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($beneficiados as $b): 
                            $es_actual = ($hoy >= $b['fecha_inicio'] && $hoy <= $b['fecha_fin']) ? '1' : '0';
                        ?>
                        <tr class="alumno-row" data-actual="<?php echo $es_actual; ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($b['nombre']); ?></strong><br>
                                <small style="color: #a0aec0;"><?php echo $b['matricula']; ?></small>
                            </td>
                            <td style="font-size: 0.85em;">
                                <?php echo date('d/m/y', strtotime($b['fecha_inicio'])); ?> al <?php echo date('d/m/y', strtotime($b['fecha_fin'])); ?>
                            </td>
                            <td><span class="periodo-tag"><?php echo determinarPeriodo($b['fecha_inicio']); ?></span></td>
                            <td><span class="badge <?php echo $b['correo_enviado'] ? 'bg-success' : 'bg-pending'; ?>"><?php echo $b['correo_enviado'] ? 'ENVIADO' : 'PENDIENTE'; ?></span></td>
                            <td><strong><?php echo $b['cupones_usados']; ?></strong>/10</td>
                            <td>
                                <button type="button" class="btn-edit-sm" 
                                    onclick="cargarDatos('<?php echo addslashes($b['nombre']); ?>', '<?php echo $b['matricula']; ?>', '<?php echo $b['email']; ?>', '<?php echo $b['fecha_inicio']; ?>', '<?php echo $b['fecha_fin']; ?>')">
                                    Editar ✏️
                                </button>
                                <button type="button" class="btn-danger-sm btn-eliminar" data-id="<?php echo $b['alumno_id']; ?>" data-nombre="<?php echo htmlspecialchars($b['nombre']); ?>">
                                    🗑️
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <form id="form-eliminar" method="POST" style="display:none;">
        <input type="hidden" name="alumno_id" id="input-eliminar-id">
        <input type="hidden" name="eliminar_alumno" value="1">
    </form>

    <form id="form-envio" method="POST" style="display:none;"><input type="hidden" name="confirmar_envio" value="1"></form>

    <script>
        function cargarDatos(nombre, matricula, email, inicio, fin) {
            document.getElementById('f-nombre').value = nombre;
            document.getElementById('f-matricula').value = matricula;
            document.getElementById('f-email').value = email;
            document.getElementById('f-inicio').value = inicio;
            document.getElementById('f-fin').value = fin;
            
            document.getElementById('titulo-form').innerText = "Modificar Alumno";
            document.getElementById('btn-submit').innerText = "Actualizar Datos";
            document.getElementById('btn-cancelar').style.display = "block";
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function limpiarFormulario() {
            document.getElementById('form-alumno').reset();
            document.getElementById('titulo-form').innerText = "Registrar Beneficiario";
            document.getElementById('btn-submit').innerText = "Guardar Alumno";
            document.getElementById('btn-cancelar').style.display = "none";
        }

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-eliminar');
            if (btn) {
                const id = btn.getAttribute('data-id');
                const nombre = btn.getAttribute('data-nombre');
                Swal.fire({
                    title: '¿Eliminar?',
                    text: `Borrarás permanentemente a ${nombre}`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#f5576c',
                    confirmButtonText: 'Sí, borrar'
                }).then((r) => {
                    if (r.isConfirmed) {
                        document.getElementById('input-eliminar-id').value = id;
                        document.getElementById('form-eliminar').submit();
                    }
                });
            }
        });

        function confirmarEnvioMasivo() {
            Swal.fire({ title: '¿Enviar correos?', text: "Se enviarán los cupones a quienes tengan estado PENDIENTE.", icon: 'question', showCancelButton: true }).then(r => { if(r.isConfirmed) document.getElementById('form-envio').submit(); });
        }

        function confirmarSalida() {
            Swal.fire({ title: '¿Cerrar sesión?', icon: 'warning', showCancelButton: true }).then(r => { if(r.isConfirmed) window.location.href = "?logout=1"; });
        }

        <?php if ($mensaje_swal): ?>
            Swal.fire({ icon: '<?php echo $mensaje_swal['icon']; ?>', title: '<?php echo $mensaje_swal['title']; ?>', text: '<?php echo $mensaje_swal['text']; ?>' });
        <?php endif; ?>
    </script>
</body>
</html>