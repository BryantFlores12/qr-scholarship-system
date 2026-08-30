<?php
use PHPMailer\PHPMailer\PHPMailer;
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/vendor/autoload.php';

class NotificadorService {
    private PDO $db;

    public function __construct(PDO $dbConnection) {
        $this->db = $dbConnection;
    }

    private function qrDataUri(string $value): string {
        $generator = new \SimpleSoftwareIO\QrCode\Generator();
        $svg = $generator->size(300)->margin(1)->generate($value);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function enviarDocumentacionBeca(int $studentId): bool {
        $student = $this->studentWithCoupons($studentId);
        if (!$student || !$student['cupones']) {
            return false;
        }

        $html = '<style>
            body{font-family:Arial,sans-serif;margin:0}.coupon{text-align:center;padding-top:45px}
            .break{page-break-before:always}.card{border:2px dashed #111;padding:30px;display:inline-block;width:80%}
            .title{color:#1a237e;font-size:22px}.date{font-size:26px;color:#c62828;font-weight:bold;margin:20px 0}
            .meta{font-size:12px;color:#666;margin-top:15px}
        </style><body>';

        foreach ($student['cupones'] as $index => $coupon) {
            $date = (new DateTime($student['fecha_inicio']))
                ->modify('+' . ((int)$coupon['dia'] - 1) . ' day')->format('d / m / Y');
            $name = htmlspecialchars($student['nombre'], ENT_QUOTES, 'UTF-8');
            $html .= "<div class='coupon " . ($index ? 'break' : '') . "'><div class='card'>
                <div class='title'>MEAL COUPON / CUPÓN DE ALIMENTACIÓN</div><hr>
                <p>Beneficiary / Beneficiario: <b>{$name}</b></p>
                <div class='date'>VALID / VÁLIDO: {$date}</div>
                <img src='" . $this->qrDataUri($coupon['codigo']) . "' width='280'>
                <div class='meta'>Security ID: " . htmlspecialchars(substr($coupon['codigo'], -12)) . "</div>
            </div></div>";
        }
        $html .= '</body>';

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        return $this->sendEmail($student, $pdf->output());
    }

    private function studentWithCoupons(int $id): ?array {
        $statement = $this->db->prepare('SELECT a.nombre,a.email,a.matricula,b.fecha_inicio
            FROM alumnos a JOIN beneficiados b ON a.id=b.alumno_id
            WHERE a.id=? AND b.estado=\'activo\'');
        $statement->execute([$id]);
        $student = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            return null;
        }
        $statement = $this->db->prepare('SELECT codigo,dia FROM cupones WHERE alumno_id=? ORDER BY dia');
        $statement->execute([$id]);
        $student['cupones'] = $statement->fetchAll(PDO::FETCH_ASSOC);
        return $student;
    }

    private function sendEmail(array $student, string $pdf): bool {
        $required = ['SMTP_HOST','SMTP_USERNAME','SMTP_PASSWORD','SMTP_FROM_ADDRESS'];
        foreach ($required as $name) {
            if (!getenv($name)) {
                error_log("Missing environment variable: {$name}");
                return false;
            }
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = getenv('SMTP_USERNAME');
            $mail->Password = getenv('SMTP_PASSWORD');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
            $mail->CharSet = 'UTF-8';
            $mail->setFrom(getenv('SMTP_FROM_ADDRESS'), getenv('SMTP_FROM_NAME') ?: 'QR Scholarship System');
            $mail->addAddress($student['email'], $student['nombre']);
            $mail->addStringAttachment($pdf, 'meal-coupons.pdf');
            $mail->isHTML(true);
            $mail->Subject = 'Your QR meal coupons / Tus cupones QR';
            $safeName = htmlspecialchars($student['nombre'], ENT_QUOTES, 'UTF-8');
            $mail->Body = "<h2>Hello {$safeName}</h2><p>Your dated QR meal coupons are attached as a PDF.</p>";
            $mail->send();
            return true;
        } catch (Throwable $error) {
            error_log('Email error: ' . $error->getMessage());
            return false;
        }
    }
}
?>
