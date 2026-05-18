<?php
/**
 * libro_temas.php
 * Generates a "Lesson Log" PDF from Moodle's Attendance plugin session descriptions.
 * 
 * Part of: moodle-attendance-reports
 * Author:  Luis Lanza — https://orcid.org/0009-0004-6535-8772
 * License: GNU GPL v3
 * Repo:    https://github.com/luislanza/moodle-attendance-reports
 */

// ── CONFIGURATION ─────────────────────────────────────────────────────────────
$moodle_config       = '/home/youruser/yourmoodle/config.php'; // absolute path to Moodle's config.php
$allowed_origins     = [
    'https://yourmoodle.example.com',  // Moodle URL
];
$campus_url          = 'https://yourmoodle.example.com'; // shown in PDF footer
$db_prefix_fallback  = 'mdl_';                           // fallback if regex fails
$logo_url            = 'https://yoursite.com/logo.png';  // institution logo URL
$institution         = 'Your Institution Name';           // shown in PDF header
// ── END CONFIGURATION ─────────────────────────────────────────────────────────

// ── Security ──────────────────────────────────────────────────────────────────
$origin = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$allowed = false;
foreach ($allowed_origins as $ao) {
    if (strpos($origin, $ao) === 0) { $allowed = true; break; }
}
if (!$allowed) { http_response_code(403); die('Access denied'); }

// ── Parameters ────────────────────────────────────────────────────────────────
$courseid = isset($_GET['courseid']) ? intval($_GET['courseid']) : 0;
$token    = isset($_GET['token'])    ? trim($_GET['token'])      : '';
if (!$courseid) { http_response_code(400); die('courseid required'); }

// ── Read Moodle config ────────────────────────────────────────────────────────
if (!file_exists($moodle_config)) { http_response_code(500); die('config.php not found'); }
$config_content = file_get_contents($moodle_config);
preg_match("/\\\$CFG->dbhost\s*=\s*'([^']+)'/",  $config_content, $m_host);
preg_match("/\\\$CFG->dbname\s*=\s*'([^']+)'/",  $config_content, $m_name);
preg_match("/\\\$CFG->dbuser\s*=\s*'([^']+)'/",  $config_content, $m_user);
preg_match("/\\\$CFG->dbpass\s*=\s*'([^']+)'/",  $config_content, $m_pass);
preg_match("/\\\$CFG->prefix\s*=\s*'([^']+)'/",  $config_content, $m_pref);

$db_host   = $m_host[1] ?? 'localhost';
$db_name   = $m_name[1] ?? '';
$db_user   = $m_user[1] ?? '';
$db_pass   = $m_pass[1] ?? '';
$db_prefix = $m_pref[1] ?? $db_prefix_fallback;

// ── DB Connection ─────────────────────────────────────────────────────────────
$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// ── Verify session ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT userid FROM {$db_prefix}sessions WHERE sid = ? AND timemodified > ?");
$stmt->execute([$token, time() - 7200]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) { http_response_code(401); die('Invalid session'); }
$userid = $session['userid'];

// ── Verify role ───────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT ra.id FROM {$db_prefix}role_assignments ra
    JOIN {$db_prefix}context ctx ON ra.contextid = ctx.id
    JOIN {$db_prefix}role r ON ra.roleid = r.id
    WHERE ctx.instanceid = ? AND ctx.contextlevel = 50
      AND ra.userid = ? AND r.shortname IN ('editingteacher','teacher','manager')
    LIMIT 1
");
$stmt->execute([$courseid, $userid]);
$has_role = $stmt->fetch();

$stmt2 = $pdo->prepare("SELECT value FROM {$db_prefix}config WHERE name = 'siteadmins'");
$stmt2->execute();
$siteadmins = $stmt2->fetch(PDO::FETCH_ASSOC);
$is_admin = $siteadmins && in_array($userid, explode(',', $siteadmins['value']));
if (!$has_role && !$is_admin) { http_response_code(403); die('Permission denied'); }

// ── Course data ───────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT fullname FROM {$db_prefix}course WHERE id = ?");
$stmt->execute([$courseid]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$course) { http_response_code(404); die('Course not found'); }

// ── Teacher(s) ────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT DISTINCT u.lastname, u.firstname
    FROM {$db_prefix}user u
    JOIN {$db_prefix}role_assignments ra ON ra.userid = u.id
    JOIN {$db_prefix}context ctx ON ctx.id = ra.contextid
    JOIN {$db_prefix}role r ON r.id = ra.roleid
    WHERE ctx.instanceid = ? AND ctx.contextlevel = 50
      AND r.shortname = 'editingteacher'
    ORDER BY u.lastname, u.firstname
");
$stmt->execute([$courseid]);
$docentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$docente = implode('; ', array_map(function($d) {
    return $d['lastname'] . ', ' . $d['firstname'];
}, $docentes));
if (!$docente) $docente = '—';

// ── Academic year cutoff (March 1) ───────────────────────────────────────────
$mes_actual  = intval(date('n'));
$anio_actual = intval(date('Y'));
$anio_lectivo  = ($mes_actual >= 3) ? $anio_actual : $anio_actual - 1;
$inicio_lectivo = mktime(0, 0, 0, 3, 1, $anio_lectivo);

// ── Attendance sessions ───────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT sess.id,
           DATE_FORMAT(FROM_UNIXTIME(sess.sessdate), '%d/%m/%Y') AS fecha,
           sess.description AS tema,
           sess.sessdate
    FROM {$db_prefix}attendance_sessions sess
    JOIN {$db_prefix}attendance att ON sess.attendanceid = att.id
    WHERE att.course = ?
      AND sess.description IS NOT NULL AND sess.description <> ''
      AND sess.sessdate >= ?
      AND sess.sessdate <= UNIX_TIMESTAMP()
    ORDER BY sess.sessdate ASC
");
$stmt->execute([$courseid, $inicio_lectivo]);
$sesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($sesiones)) { http_response_code(404); die('No sessions with topics found'); }

// ── TCPDF ─────────────────────────────────────────────────────────────────────
$tcpdf_path = __DIR__ . '/../libs/tcpdf/tcpdf.php';
if (file_exists($tcpdf_path)) require_once $tcpdf_path;
else {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;
    else { http_response_code(500); die('TCPDF not found. See installation instructions.'); }
}

// ── PDF class with header/footer ──────────────────────────────────────────────
class LibroTemasPDF extends TCPDF {
    public $asignatura = '';
    public $campus_url = '';

    public function Header() {
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        $this->SetY(8);
        $this->Cell(0, 5, $this->asignatura, 0, 0, 'L');
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(180, 180, 180);
        $this->Line(15, 14, 195, 14);
        $this->SetDrawColor(0, 0, 0);
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer() {
        $this->SetY(-12);
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(180, 180, 180);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(2);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(0, 80, 180);
        $this->Cell(0, 5, $this->campus_url, 0, 0, 'L', false, $this->campus_url);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

$pdf = new LibroTemasPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->asignatura = $course['fullname'];
$pdf->campus_url = $campus_url;
$pdf->SetCreator($institution);
$pdf->SetTitle('Lesson Log - ' . $course['fullname']);
$pdf->SetMargins(15, 18, 15);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

// ── Logo ──────────────────────────────────────────────────────────────────────
$logo_tmp  = tempnam(sys_get_temp_dir(), 'logo_') . '.png';
$logo_data = @file_get_contents($logo_url);
if ($logo_data) {
    file_put_contents($logo_tmp, $logo_data);
    $pdf->Image($logo_tmp, 15, 18, 25, 0, 'PNG');
}

// ── Header ────────────────────────────────────────────────────────────────────
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetXY(45, 18);
$pdf->Cell(150, 7, $institution, 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetX(45);
$pdf->Cell(150, 6, 'LESSON LOG / LIBRO DE TEMAS', 0, 1, 'C');

$pdf->SetXY(15, 46);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(32, 6, 'Course / Asignatura:', 0, 0);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 6, $course['fullname'], 0, 1);

$pdf->SetX(15);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(32, 6, 'Teacher/s / Docente/s:', 0, 0);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 6, $docente, 0, 1);

$pdf->SetX(15);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(32, 6, 'Generated / Emisión:', 0, 0);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 6, date('d/m/Y H:i'), 0, 1);

$pdf->Ln(3);
$pdf->SetLineWidth(0.5);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(4);

// ── Table ─────────────────────────────────────────────────────────────────────
$pdf->SetLineWidth(0.2);

function drawTableHeader($pdf) {
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->setCellPaddings(2, 3, 2, 3);
    $pdf->Cell(35, 8, 'Date / Fecha', 1, 0, 'L', true);
    $pdf->Cell(145, 8, 'Topic / Tema dado', 1, 1, 'L', true);
}

drawTableHeader($pdf);
$pdf->SetFont('helvetica', '', 10);
$pdf->setCellPaddings(2, 3, 2, 3);
$fill = false;

foreach ($sesiones as $s) {
    $tema = trim(strip_tags($s['tema']));
    if ($fill) $pdf->SetFillColor(235, 245, 255);
    else       $pdf->SetFillColor(255, 255, 255);

    $h = max(8, $pdf->getStringHeight(145, $tema) + 4);

    if ($pdf->GetY() + $h > $pdf->getPageHeight() - 20) {
        $pdf->AddPage();
        drawTableHeader($pdf);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->setCellPaddings(2, 3, 2, 3);
    }

    $x = $pdf->GetX(); $y = $pdf->GetY();
    $pdf->MultiCell(35,  $h, $s['fecha'], 1, 'L', $fill, 0, $x,      $y);
    $pdf->MultiCell(145, $h, $tema,       1, 'L', $fill, 1, $x + 35, $y);
    $fill = !$fill;
}

if (isset($logo_tmp) && file_exists($logo_tmp)) @unlink($logo_tmp);

// ── Output ────────────────────────────────────────────────────────────────────
$slug     = str_replace(' ', '_', trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $course['fullname'])));
$filename = "LessonLog_{$slug}_" . date('Ymd') . ".pdf";
$pdf->Output($filename, 'D');
