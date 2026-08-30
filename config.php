<?php
class Database {
    private $db_file = 'cuponera.db';
    private $connection;
    
    public function connect() {
        try {
            $this->connection = new PDO("sqlite:" . $this->db_file);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->initializeDatabase();
            return $this->connection;
        } catch(PDOException $e) {
            die("Error de conexión: " . $e->getMessage());
        }
    }
    
    private function initializeDatabase() {
        $tables = [ 
            "CREATE TABLE IF NOT EXISTS alumnos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nombre TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                matricula TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS sorteos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                fecha_sorteo DATETIME DEFAULT CURRENT_TIMESTAMP,
                tipo TEXT DEFAULT 'aleatorio',
                realizado_por TEXT
            )",
            "CREATE TABLE IF NOT EXISTS beneficiados (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                alumno_id INTEGER,
                sorteo_id INTEGER,
                fecha_inicio DATE,
                fecha_fin DATE,
                estado TEXT DEFAULT 'activo',
                correo_enviado INTEGER DEFAULT 0,
                FOREIGN KEY (alumno_id) REFERENCES alumnos(id),
                FOREIGN KEY (sorteo_id) REFERENCES sorteos(id),
                UNIQUE(alumno_id, sorteo_id)
            )",
            "CREATE TABLE IF NOT EXISTS cupones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                alumno_id INTEGER,
                codigo TEXT UNIQUE NOT NULL,
                dia INTEGER,
                fecha_uso DATETIME,
                usado INTEGER DEFAULT 0,
                FOREIGN KEY (alumno_id) REFERENCES alumnos(id)
            )",
            "CREATE TABLE IF NOT EXISTS uso_cupones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cupon_id INTEGER,
                fecha_uso DATETIME DEFAULT CURRENT_TIMESTAMP,
                verificado_por TEXT,
                FOREIGN KEY (cupon_id) REFERENCES cupones(id)
            )"
        ];
        
        foreach ($tables as $table) {
            $this->connection->exec($table);
        }
    }
}
?>

