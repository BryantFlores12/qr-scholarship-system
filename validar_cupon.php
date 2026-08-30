<?php
// 1. Configuración de zona horaria y sesión
date_default_timezone_set('America/Mexico_City');
session_start();

require_once 'config.php';
require_once 'QRGenerator.php';

// Salida de sesión
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.html'); 
    exit();
}

if (!isset($_SESSION['cafeteria_logged'])) {
    header('Location: cafeteria_login.php');
    exit();
}

$database = new Database();
$db = $database->connect();

$mensaje_swal = null; 
$reporte_alumnos = [];

// --- BLOQUE DE PROCESAMIENTO DE ESCANEO ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['codigo'])) {
    $codigo = preg_replace('/\s+/', '', trim($_POST['codigo']));
    
    // Validar si es fin de semana (bloqueo preventivo)
    $hoy_dt = new DateTime();
    $dia_semana = (int)$hoy_dt->format('N'); 

    if ($dia_semana >= 6) {
        $mensaje_swal = [
            'icon' => 'error', 
            'title' => 'SISTEMA CERRADO', 
            'text' => 'No se pueden canjear cupones los fines de semana.'
        ];
    } else {
        // Buscar cupón y datos del alumno en una sola consulta
        $sql = "SELECT c.*, a.nombre, a.matricula, b.fecha_inicio 
                FROM cupones c 
                JOIN alumnos a ON c.alumno_id = a.id 
                JOIN beneficiados b ON c.alumno_id = b.alumno_id 
                WHERE c.codigo = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$codigo]);
        $cupon = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cupon) {
            $mensaje_swal = ['icon' => 'error', 'title' => 'CÓDIGO INVÁLIDO', 'text' => 'El código no existe en el sistema.'];
        } else {
            // Calcular fecha programada para este cupón específico
            $fecha_inicio_beca = new DateTime($cupon['fecha_inicio']);
            $dias_a_sumar = $cupon['dia'] - 1;
            $fecha_programada = $fecha_inicio_beca->modify("+$dias_a_sumar day")->format('Y-m-d');
            $hoy = date('Y-m-d');

            // VALIDACIONES
            if ($cupon['usado'] == 1) {
                $mensaje_swal = ['icon' => 'error', 'title' => 'YA UTILIZADO', 'text' => 'Este código QR ya fue canjeado anteriormente.'];
            } 
            elseif ($hoy !== $fecha_programada) {
                $fecha_display = date('d/m/Y', strtotime($fecha_programada));
                $mensaje_swal = [
                    'icon' => 'info', 
                    'title' => 'FECHA INCORRECTA', 
                    'text' => "Este código corresponde al día {$fecha_display}. Por favor, usa el QR de hoy."
                ];
            } 
            else {
                // Verificar si el alumno ya registró algún consumo hoy (doble seguridad)
                $sql_check = "SELECT COUNT(*) FROM uso_cupones WHERE DATE(fecha_uso) = CURRENT_DATE AND cupon_id IN (SELECT id FROM cupones WHERE alumno_id = ?)";
                $stmt_check = $db->prepare($sql_check);
                $stmt_check->execute([$cupon['alumno_id']]);
                
                if ($stmt_check->fetchColumn() > 0) {
                    $mensaje_swal = ['icon' => 'warning', 'title' => 'LÍMITE ALCANZADO', 'text' => "El alumno {$cupon['nombre']} ya registró un consumo el día de hoy."];
                } else {
                    // PROCEDER AL CANJE
                    try {
                        $db->beginTransaction();
                        
                        // Marcar cupón como usado
                        $db->prepare("UPDATE cupones SET usado = 1, fecha_uso = datetime('now', 'localtime') WHERE id = ?")
                           ->execute([$cupon['id']]);
                        
                        // Insertar en tabla de historial
                        $db->prepare("INSERT INTO uso_cupones (cupon_id, verificado_por, fecha_uso) VALUES (?, ?, datetime('now', 'localtime'))")
                           ->execute([$cupon['id'], $_SESSION['cafeteria_user']]);
                        
                        $db->commit();
                        $mensaje_swal = ['icon' => 'success', 'title' => '¡CUPÓN VÁLIDO!', 'text' => "Buen provecho, {$cupon['nombre']}."];
                    } catch (Exception $e) {
                        $db->rollBack();
                        $mensaje_swal = ['icon' => 'error', 'title' => 'ERROR', 'text' => 'No se pudo procesar el canje.'];
                    }
                }
            }
        }
    }
}

// LÓGICA DEL REPORTE (Con columna 'dia' añadida)
if (isset($_POST['ver_reporte'])) {
    $sql_reporte = "SELECT a.nombre, a.matricula, uc.fecha_uso, b.fecha_inicio, b.fecha_fin, c.dia
                    FROM uso_cupones uc
                    JOIN cupones c ON uc.cupon_id = c.id
                    JOIN alumnos a ON c.alumno_id = a.id
                    JOIN beneficiados b ON a.id = b.alumno_id
                    WHERE DATE(uc.fecha_uso) = CURRENT_DATE
                    ORDER BY uc.fecha_uso DESC";
    $reporte_alumnos = $db->query($sql_reporte)->fetchAll(PDO::FETCH_ASSOC);

    if (empty($reporte_alumnos)) {
        $mensaje_swal = ['icon' => 'info', 'title' => 'Sin registros', 'text' => 'Aún no hay canjes registrados hoy.'];
    }
}
$hoy_check = date('Y-m-d');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validación Cafetería - ENES León</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; margin: 0; }
        .container { max-width: 750px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 15px 15px 0 0; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; }
        .content { background: white; padding: 25px; border-radius: 0 0 15px 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .user-badge { background: #667eea; color: white; padding: 6px 12px; border-radius: 20px; font-size: 13px; font-weight: bold; }
        .btn-scan { background: #28a745; color: white; padding: 18px; border: none; border-radius: 12px; width: 100%; font-size: 18px; font-weight: bold; cursor: pointer; margin-bottom: 15px; display: flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 4px 10px rgba(40, 167, 69, 0.3); transition: 0.3s; }
        .btn-report { background: #f8f9fa; color: #444; border: 1px solid #ddd; padding: 12px; width: 100%; border-radius: 10px; cursor: pointer; font-weight: 600; margin-bottom: 10px; }
        .btn-group-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
        .btn-action { padding: 10px; border-radius: 8px; border: none; font-size: 13px; font-weight: bold; cursor: pointer; color: white; transition: 0.2s; }
        .btn-excel { background: #1f7244; }
        .btn-filter { background: #6c757d; }
        
        #reader { width: 100%; border-radius: 12px; overflow: hidden; display: none; margin-bottom: 20px; border: 3px solid #667eea; }
        .logout-btn { color: #dc3545; text-decoration: none; font-size: 14px; font-weight: bold; padding: 5px 10px; border-radius: 5px; border: 1px solid #dc3545; transition: 0.2s; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #f4f7f6; padding: 12px 10px; text-align: left; font-size: 11px; color: #666; text-transform: uppercase; border-bottom: 2px solid #eee; }
        td { padding: 12px 10px; border-bottom: 1px solid #eee; font-size: 13px; vertical-align: middle; }
        .vigencia-text { font-size: 11px; color: #888; }
        .tag-dia { background: #edf2f7; color: #2d3748; padding: 2px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; display: inline-block; margin-bottom: 4px; }
        .row-hidden { display: none; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h3 style="margin:0;">🍽️ Cafetería ENES</h3>
        <div style="display:flex; align-items:center; gap:12px;">
            <span class="user-badge">👤 <?php echo $_SESSION['cafeteria_user']; ?></span>
            <a href="#" onclick="confirmarSalida()" class="logout-btn">Salir</a>
        </div>
    </div>

    <div class="content">
        <div id="reader"></div>

        <button type="button" id="btn-scan" class="btn-scan">📷 ESCANEAR QR</button>

        <form id="form-validador" method="POST" style="display:none;">
            <input type="hidden" name="codigo" id="codigo_input">
        </form>

        <form method="POST">
            <button type="submit" name="ver_reporte" class="btn-report">📋 Ver reporte de hoy</button>
        </form>

        <?php if (!empty($reporte_alumnos)): ?>
            <div class="btn-group-actions">
                <button type="button" class="btn-action btn-excel" onclick="exportarExcel()">📊 Exportar Excel</button>
                <button type="button" id="btnFilter" class="btn-action btn-filter" onclick="filtrarPeriodo()">Filtrar Periodo Actual</button>
            </div>

            <table id="tablaConsumo">
                <thead>
                    <tr>
                        <th>Alumno</th>
                        <th>Vigencia Beca</th>
                        <th>Cupón Validado</th>
                        <th>Hora Canje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reporte_alumnos as $r): 
                        $es_periodo_actual = ($hoy_check >= $r['fecha_inicio'] && $hoy_check <= $r['fecha_fin']) ? '1' : '0';
                        // Calcular fecha que correspondía al cupón
                        $f_inicio = new DateTime($r['fecha_inicio']);
                        $f_cup = $f_inicio->modify('+' . ($r['dia'] - 1) . ' days')->format('d/m/Y');
                    ?>
                    <tr class="fila-alumno" data-actual="<?= $es_periodo_actual ?>">
                        <td>
                            <strong><?php echo htmlspecialchars($r['nombre']); ?></strong><br>
                            <small style="color:#666;"><?php echo $r['matricula']; ?></small>
                        </td>
                        <td>
                            <span class="vigencia-text">
                                Del: <?php echo date('d/m/y', strtotime($r['fecha_inicio'])); ?><br>
                                Al: <?php echo date('d/m/y', strtotime($r['fecha_fin'])); ?>
                            </span>
                        </td>
                        <td>
                            <span class="tag-dia">Día <?php echo $r['dia']; ?></span><br>
                            <small style="color:#888;">Para: <?php echo $f_cup; ?></small>
                        </td>
                        <td>
                            <span style="color:#28a745; font-weight:bold;">
                                <?php echo date('H:i:s', strtotime($r['fecha_uso'])); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode"></script>
<script>
    // SweetAlert para mensajes del servidor
    <?php if ($mensaje_swal): ?>
        Swal.fire({
            icon: '<?= $mensaje_swal['icon'] ?>',
            title: '<?= $mensaje_swal['title'] ?>',
            text: '<?= $mensaje_swal['text'] ?>',
            confirmButtonColor: '#667eea'
        });
    <?php endif; ?>

    // Exportar a Excel básico
    function exportarExcel() {
        const table = document.getElementById("tablaConsumo");
        const url = 'data:application/vnd.ms-excel,' + encodeURIComponent(table.outerHTML);
        const link = document.createElement("a");
        link.download = "Reporte_Cafeteria_Hoy.xls";
        link.href = url;
        link.click();
    }

    // Filtro de filas
    let filtroActivo = false;
    function filtrarPeriodo() {
        const filas = document.querySelectorAll('.fila-alumno');
        const btn = document.getElementById('btnFilter');
        filtroActivo = !filtroActivo;

        filas.forEach(f => {
            if (filtroActivo && f.getAttribute('data-actual') === '0') {
                f.classList.add('row-hidden');
            } else {
                f.classList.remove('row-hidden');
            }
        });
        btn.textContent = filtroActivo ? "Ver Todos" : "Filtrar Periodo Actual";
        btn.style.background = filtroActivo ? "#e67e22" : "#6c757d";
    }

    function confirmarSalida() {
        Swal.fire({
            title: '¿Cerrar sesión?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Cancelar',
            confirmButtonText: 'Sí, salir'
        }).then((result) => {
            if (result.isConfirmed) window.location.href = "?logout=1";
        });
    }

    // Lógica de Cámara y QR
    const btnScan = document.getElementById('btn-scan');
    const readerDiv = document.getElementById('reader');
    const inputCodigo = document.getElementById('codigo_input');
    const formulario = document.getElementById('form-validador');
    let html5QrCode = new Html5Qrcode("reader");

    btnScan.addEventListener('click', () => {
        readerDiv.style.display = 'block';
        btnScan.style.display = 'none';
        
        html5QrCode.start(
            { facingMode: "environment" },
            { fps: 15, qrbox: { width: 250, height: 250 } },
            (decodedText) => {
                inputCodigo.value = decodedText;
                html5QrCode.stop().then(() => {
                    formulario.submit();
                });
            }
        ).catch(err => {
            Swal.fire('Error', 'No se pudo iniciar la cámara. Verifique los permisos.', 'error');
            btnScan.style.display = 'flex';
            readerDiv.style.display = 'none';
        });
    });
</script>
</body>
</html>