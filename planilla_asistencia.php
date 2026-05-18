<?php
/**
 * planilla_asistencia.php
 * Generates an "Attendance Sheet" PDF from Moodle's Attendance plugin.
 * Landscape format: one row per student, one column per session, percentage column.
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
$mes_actual    = intval(date('n'));
$anio_actual   = intval(date('Y'));
$anio_lectivo  = ($mes_actual >= 3) ? $anio_actual : $anio_actual - 1;
$inicio_lectivo = mktime(0, 0, 0, 3, 1, $anio_lectivo);

// ── Sessions (columns) ───────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT sess.id, DATE_FORMAT(FROM_UNIXTIME(sess.sessdate), '%d/%m') AS fecha,
           sess.sessdate, att.id AS attendanceid
    FROM {$db_prefix}attendance_sessions sess
    JOIN {$db_prefix}attendance att ON sess.attendanceid = att.id
    WHERE att.course = ?
      AND sess.sessdate >= ?
      AND sess.sessdate <= UNIX_TIMESTAMP()
    ORDER BY sess.sessdate ASC
");
$stmt->execute([$courseid, $inicio_lectivo]);
$sesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($sesiones)) { http_response_code(404); die('No sessions found'); }

$session_ids  = array_column($sesiones, 'id');
$attendanceid = $sesiones[0]['attendanceid'];
$fecha_inicio = date('d/m/Y', $sesiones[0]['sessdate']);
$fecha_fin    = date('d/m/Y', $sesiones[count($sesiones)-1]['sessdate']);

// ── Max grade for this attendance ────────────────────────────────────────────
// Grade scale is defined per course — no hardcoded values
$stmt = $pdo->prepare("
    SELECT MAX(grade) as maxgrade FROM {$db_prefix}attendance_statuses
    WHERE attendanceid = ? AND deleted = 0
");
$stmt->execute([$attendanceid]);
$max_row  = $stmt->fetch(PDO::FETCH_ASSOC);
$maxgrade = floatval($max_row['maxgrade'] ?? 1);

// ── Enrolled students ─────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.lastname, u.firstname,
           MIN(ue.timecreated) AS fecha_matriculacion
    FROM {$db_prefix}user u
    JOIN {$db_prefix}user_enrolments ue ON ue.userid = u.id
    JOIN {$db_prefix}enrol e ON e.id = ue.enrolid
    JOIN {$db_prefix}role_assignments ra ON ra.userid = u.id
    JOIN {$db_prefix}context ctx ON ctx.id = ra.contextid
    JOIN {$db_prefix}role r ON r.id = ra.roleid
    WHERE e.courseid = ? AND ctx.instanceid = ?
      AND ctx.contextlevel = 50 AND r.shortname = 'student'
      AND ue.status = 0
    GROUP BY u.id, u.lastname, u.firstname
    ORDER BY u.lastname, u.firstname
");
$stmt->execute([$courseid, $courseid]);
$estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($estudiantes)) { http_response_code(404); die('No students enrolled'); }

// ── Attendance log ────────────────────────────────────────────────────────────
$placeholders = implode(',', array_fill(0, count($session_ids), '?'));
$stmt = $pdo->prepare("
    SELECT al.studentid, al.sessionid, ats.acronym, ats.grade
    FROM {$db_prefix}attendance_log al
    JOIN {$db_prefix}attendance_statuses ats ON al.statusid = ats.id
    WHERE al.sessionid IN ($placeholders)
");
$stmt->execute($session_ids);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$log_index = [];
foreach ($logs as $log) {
    $log_index[$log['studentid']][$log['sessionid']] = $log['acronym'];
}

// ── TCPDF ─────────────────────────────────────────────────────────────────────
$tcpdf_path = __DIR__ . '/../libs/tcpdf/tcpdf.php';
if (file_exists($tcpdf_path)) require_once $tcpdf_path;
else {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;
    else { http_response_code(500); die('TCPDF not found. See installation instructions.'); }
}

// ── PDF class ─────────────────────────────────────────────────────────────────
class AsistenciaPDF extends TCPDF {
    public $asignatura = '';
    public $campus_url = '';

    public function Header() {
        $this->SetFont('helvetica', 'I', 7);
        $this->SetTextColor(100, 100, 100);
        $this->SetY(5);
        $this->Cell(0, 5, $this->asignatura . ' — Attendance Sheet', 0, 0, 'L');
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(180, 180, 180);
        $this->Line(10, 11, 287, 11);
        $this->SetDrawColor(0, 0, 0);
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer() {
        $this->SetY(-10);
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(180, 180, 180);
        $this->Line(10, $this->GetY(), 287, $this->GetY());
        $this->Ln(1);
        $this->SetFont('helvetica', 'I', 7);
        $this->SetTextColor(0, 80, 180);
        $this->Cell(0, 5, $this->campus_url, 0, 0, 'L', false, $this->campus_url);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

$pdf = new AsistenciaPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->asignatura = $course['fullname'];
$pdf->campus_url = $campus_url;
$pdf->SetCreator($institution);
$pdf->SetTitle('Attendance - ' . $course['fullname']);
$pdf->SetMargins(10, 13, 10);
$pdf->SetHeaderMargin(3);
$pdf->SetFooterMargin(8);
$pdf->SetAutoPageBreak(true, 14);
$pdf->AddPage();

// ── Logo ──────────────────────────────────────────────────────────────────────
$logo_tmp  = tempnam(sys_get_temp_dir(), 'logo_') . '.png';
$logo_data = @file_get_contents($logo_url);
if ($logo_data) {
    file_put_contents($logo_tmp, $logo_data);
    $pdf->Image($logo_tmp, 10, 14, 18, 0, 'PNG');
}

// ── Institutional header ──────────────────────────────────────────────────────
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetXY(0, 14);
$pdf->Cell(297, 6, $institution, 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetX(0);
$pdf->Cell(297, 5, 'ATTENDANCE SHEET / PLANILLA DE ASISTENCIA', 0, 1, 'C');

$pdf->SetXY(10, 34);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell(20, 5, 'Course:', 0, 0);
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(70, 5, $course['fullname'], 0, 0);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell(16, 5, 'Teacher/s:', 0, 0);
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(70, 5, $docente, 0, 0);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell(14, 5, 'Period:', 0, 0);
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(0, 5, $fecha_inicio . ' — ' . $fecha_fin, 0, 1);

$pdf->SetX(10);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell(20, 5, 'Generated:', 0, 0);
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(0, 5, date('d/m/Y H:i'), 0, 1);

$pdf->SetXY(10, 48);
$pdf->Ln(2);
$pdf->SetLineWidth(0.4);
$pdf->Line(10, $pdf->GetY(), 287, $pdf->GetY());
$pdf->Ln(3);

// ── Column widths ─────────────────────────────────────────────────────────────
$col_nombre = 52;
$col_porc   = 14;
$available  = 277 - $col_nombre - $col_porc;
$col_sesion = round($available / count($sesiones), 2);
if ($col_sesion < 5) $col_sesion = 5;
$row_h = 6;

// ── Table header function ─────────────────────────────────────────────────────
function drawAttendanceHeader($pdf, $sesiones, $col_nombre, $col_sesion, $col_porc, $row_h) {
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->SetLineWidth(0.2);
    $pdf->setCellPaddings(1, 1, 1, 1);
    $pdf->Cell($col_nombre, $row_h, 'Last name, First name', 1, 0, 'L', true);
    foreach ($sesiones as $sess) {
        $pdf->Cell($col_sesion, $row_h, $sess['fecha'], 1, 0, 'C', true);
    }
    $pdf->Cell($col_porc, $row_h, '%', 1, 1, 'C', true);
}

drawAttendanceHeader($pdf, $sesiones, $col_nombre, $col_sesion, $col_porc, $row_h);

// ── Student rows ──────────────────────────────────────────────────────────────
$pdf->SetFont('helvetica', '', 7);
$pdf->setCellPaddings(1, 1, 1, 1);
$fill = false;

foreach ($estudiantes as $est) {
    if ($pdf->GetY() + $row_h > $pdf->getPageHeight() - 16) {
        $pdf->AddPage();
        drawAttendanceHeader($pdf, $sesiones, $col_nombre, $col_sesion, $col_porc, $row_h);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->setCellPaddings(1, 1, 1, 1);
    }

    if ($fill) $pdf->SetFillColor(220, 220, 220);
    else       $pdf->SetFillColor(255, 255, 255);

    $pdf->Cell($col_nombre, $row_h, $est['lastname'] . ', ' . $est['firstname'], 1, 0, 'L', $fill);

    // Session cells — grey out sessions before enrollment date
    foreach ($sesiones as $sess) {
        if (date('Y-m-d', $sess['sessdate']) < date('Y-m-d', $est['fecha_matriculacion'])) {
            $pdf->SetFillColor(180, 180, 180);
            $pdf->Cell($col_sesion, $row_h, '', 1, 0, 'C', true);
            if ($fill) $pdf->SetFillColor(220, 220, 220);
            else       $pdf->SetFillColor(255, 255, 255);
        } else {
            $acronym = $log_index[$est['id']][$sess['id']] ?? '';
            $pdf->Cell($col_sesion, $row_h, $acronym, 1, 0, 'C', $fill);
        }
    }

    // Percentage — calculated from enrollment date, using course's actual grade scale
    $sesiones_est = array_filter($sesiones, function($s) use ($est) {
        return date('Y-m-d', $s['sessdate']) >= date('Y-m-d', $est['fecha_matriculacion']);
    });
    $total_est   = count($sesiones_est);
    $grade_sum   = 0;
    foreach ($sesiones_est as $s) {
        foreach ($logs as $log) {
            if ($log['studentid'] == $est['id'] && $log['sessionid'] == $s['id']) {
                $grade_sum += floatval($log['grade']);
                break;
            }
        }
    }
    $max_total = $total_est * $maxgrade;
    $porc = $max_total > 0 ? round($grade_sum / $max_total * 100, 1) : 0;

    if ($porc >= 75)      $pdf->SetTextColor(0, 120, 0);
    elseif ($porc >= 50)  $pdf->SetTextColor(180, 100, 0);
    else                  $pdf->SetTextColor(180, 0, 0);

    $pdf->Cell($col_porc, $row_h, $porc . '%', 1, 1, 'C', $fill);
    $pdf->SetTextColor(0, 0, 0);
    $fill = !$fill;
}

if (isset($logo_tmp) && file_exists($logo_tmp)) @unlink($logo_tmp);

// ── Output ────────────────────────────────────────────────────────────────────
$slug     = str_replace(' ', '_', trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $course['fullname'])));
$filename = "Attendance_{$slug}_" . date('Ymd') . ".pdf";
$pdf->Output($filename, 'D');
