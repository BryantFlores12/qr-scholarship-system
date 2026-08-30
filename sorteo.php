<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
require_once 'QRGenerator.php';

class SistemaCuponera {
    private $db;
    private $qr;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->connect();
        $this->qr = new QRGenerator();
    }
    
    public function realizarSorteo($tipo = 'aleatorio', $alumnos_dirigidos = [], $realizado_por = 'admin') {
        $sql = "SELECT a.id, a.nombre, a.email, a.matricula 
                FROM alumnos a 
                WHERE a.id NOT IN (
                    SELECT b.alumno_id 
                    FROM beneficiados b 
                    WHERE b.estado = 'activo' 
                    AND date('now') BETWEEN b.fecha_inicio AND b.fecha_fin
                )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $alumnos_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($tipo == 'aleatorio' && count($alumnos_disponibles) < 10) {
            return ['error' => 'No hay suficientes alumnos disponibles (mínimo 10)'];
        }
        
        $seleccionados = [];
        if ($tipo == 'aleatorio') {
            $indices = array_rand($alumnos_disponibles, 10);
            foreach ((array)$indices as $index) {
                $seleccionados[] = $alumnos_disponibles[$index];
            }
        } else {
            foreach ($alumnos_dirigidos as $alumno_id) {
                $alumno = $this->getAlumnoById($alumno_id);
                if ($alumno) $seleccionados[] = $alumno;
            }
        }

        if (empty($seleccionados)) {
            return ['error' => 'No se seleccionaron alumnos validos'];
        }
        
        $sql_sorteo = "INSERT INTO sorteos (tipo, realizado_por) VALUES (?, ?)";
        $stmt = $this->db->prepare($sql_sorteo);
        $stmt->execute([$tipo, $realizado_por]);
        $sorteo_id = $this->db->lastInsertId();
        
        $fecha_inicio = date('Y-m-d');
        $fecha_fin = date('Y-m-d', strtotime('+10 days'));
        
        foreach ($seleccionados as $alumno) {
            $this->db->prepare("DELETE FROM beneficiados WHERE alumno_id = ? AND estado = 'activo' AND fecha_inicio = ?")
                     ->execute([$alumno['id'], $fecha_inicio]);
            
            $this->db->prepare("DELETE FROM cupones WHERE alumno_id = ? AND usado = 0")
                     ->execute([$alumno['id']]);

            $sql_beneficiado = "INSERT INTO beneficiados (alumno_id, sorteo_id, fecha_inicio, fecha_fin, estado, correo_enviado) 
                               VALUES (?, ?, ?, ?, 'activo', 0)";
            $stmt = $this->db->prepare($sql_beneficiado);
            $stmt->execute([$alumno['id'], $sorteo_id, $fecha_inicio, $fecha_fin]);
            
            for ($dia = 1; $dia <= 10; $dia++) {
                $codigo = $this->qr->generateSecureCode($alumno['id'], $dia, $fecha_inicio);
                $sql_cupon = "INSERT OR IGNORE INTO cupones (alumno_id, codigo, dia, usado) VALUES (?, ?, ?, 0)";
                $stmt = $this->db->prepare($sql_cupon);
                $stmt->execute([$alumno['id'], $codigo, $dia]);
            }
        }
        
        return ['success' => true, 'seleccionados' => $seleccionados];
    }
    
    public function validarCupon($codigo, $verificado_por = 'cafeteria') {
        $sql = "SELECT c.*, a.nombre, a.matricula 
                FROM cupones c 
                JOIN alumnos a ON c.alumno_id = a.id 
                WHERE c.codigo = ? AND c.usado = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$codigo]);
        $cupon = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cupon) {
            return ['error' => 'Cupón inválido o ya utilizado'];
        }
        
        $sql_beneficio = "SELECT * FROM beneficiados 
                         WHERE alumno_id = ? AND estado = 'activo' 
                         AND date('now') BETWEEN fecha_inicio AND fecha_fin";
        $stmt = $this->db->prepare($sql_beneficio);
        $stmt->execute([$cupon['alumno_id']]);
        $beneficio = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$beneficio) {
            return ['error' => 'El alumno no tiene un beneficio activo hoy'];
        }
        
        $sql_update = "UPDATE cupones SET usado = 1, fecha_uso = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $this->db->prepare($sql_update);
        $stmt->execute([$cupon['id']]);
        
        return [
            'success' => true, 
            'mensaje' => 'Cupón válido para ' . $cupon['nombre'],
            'alumno' => $cupon['nombre']
        ];
    }
    
    private function getAlumnoById($id) {
        $sql = "SELECT id, nombre, email, matricula FROM alumnos WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAlumnosBeneficiados() {
    // Se añadió a.id as alumno_id para que el botón de eliminar funcione
    $sql = "SELECT a.id as alumno_id, a.nombre, a.email, a.matricula, b.fecha_inicio, b.fecha_fin, b.correo_enviado,
                   (SELECT COUNT(*) FROM cupones WHERE alumno_id = a.id AND usado = 1) as cupones_usados
            FROM alumnos a
            JOIN beneficiados b ON a.id = b.alumno_id
            WHERE b.estado = 'activo'
            GROUP BY a.id, b.fecha_inicio
            ORDER BY b.fecha_inicio DESC";
    $stmt = $this->db->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
}