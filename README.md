# moodle-attendance-reports

**Non-invasive Moodle extension for institutional compliance reporting**  
**Extensión no invasiva de Moodle para reportes de cumplimiento institucional**

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Moodle](https://img.shields.io/badge/Moodle-5.0%2B-orange)](https://moodle.org)
[![ORCID](https://img.shields.io/badge/ORCID-0009--0004--6535--8772-green)](https://orcid.org/0009-0004-6535-8772)

---

## English

### Overview

This project provides two PDF report generators for Moodle's [Attendance plugin](https://github.com/danmarsden/moodle-mod_attendance):

- **Lesson Log** (`libro_temas.php`): A chronological list of class topics derived from session descriptions. Designed to replace paper-based lesson registers required by educational authorities.
- **Attendance Sheet** (`planilla_asistencia.php`): A landscape-format matrix showing each student's attendance status per session, with a percentage column calculated from the actual grade values defined in each course.

Both reports are generated as downloadable PDFs directly from the Moodle interface, triggered by buttons injected into the Attendance activity page via a lightweight JavaScript file.

### Key features

- No Moodle core modifications
- No additional plugins required beyond `mod_attendance`
- Survives Moodle and plugin updates
- Works across multiple courses and installations
- Attendance percentage respects each course's custom grading scale
- Enrollment date aware: percentage is calculated from the student's enrollment date, not the course start date
- Academic year cutoff: reports are scoped to the current academic year (March 1 – February 28/29)
- Buttons visible only to teachers and managers
- PDF filename includes course name and generation date

### Architecture

```
[Button in Attendance page]
        ↓
[libro-temas-btn.js — injected via Moodle additional HTML]
        ↓
[PHP script on hosting server]
  - Reads Moodle config.php for DB credentials
  - Verifies session token and user role
  - Queries mdl_attendance_sessions / mdl_attendance_log
  - Generates PDF via TCPDF
        ↓
[Download: ReportName_CourseName_YYYYMMDD.pdf]
```

### Requirements

- Moodle 4.x or 5.x
- PHP 8.1+
- [TCPDF](https://github.com/tecnickcom/tcpdf) (place in `public_html/libs/tcpdf/`)
- `mod_attendance` plugin installed and configured
- Shared hosting or server with direct DB access

### Installation

1. **Download TCPDF** and place it at `public_html/libs/tcpdf/tcpdf.php`

2. **Edit configuration** in both PHP files — search for the `CONFIGURATION` section:
   ```php
   // ── CONFIGURATION ────────────────────────────────────────────────────────
   $moodle_config  = '/home/youruser/yourmoodle/config.php';
   $allowed_origins = ['https://yourmoodle.example.com'];
   $campus_url      = 'https://yourmoodle.example.com';
   $db_prefix_fallback = 'mdl_'; // fallback if regex fails
   $logo_url        = 'https://yoursite.com/logo.png';
   $institution     = 'Your Institution Name';
   ```

3. **Upload** `libro_temas.php`, `planilla_asistencia.php`, and `libro-temas-btn.js` to `public_html/js/`

4. **Edit** `libro-temas-btn.js` — set your base URL:
   ```javascript
   var BASE_URL = 'https://yoursite.com/js/';
   ```

5. **Add** to Moodle's additional HTML (Site administration → Appearance → Themes → [your theme] → "When BODY is opened"):
   ```html
   <script src="https://yoursite.com/js/libro-temas-btn.js"></script>
   ```

6. Open any Attendance activity as a teacher — the download buttons will appear at the bottom of the page.

### Security

- Requests are validated against an allowlist of origins (`HTTP_REFERER`)
- Session token is verified against `mdl_sessions` (valid for 2 hours)
- User role is checked against `mdl_role_assignments` for the specific course
- Site admins are also allowed

### Contributing

Pull requests are welcome. If you'd like to see this functionality integrated into the official Attendance plugin, please refer to the open feature request at [danmarsden/moodle-mod_attendance](https://github.com/danmarsden/moodle-mod_attendance/issues).

### Author

**Luis Lanza**  
ORCID: [0009-0004-6535-8772](https://orcid.org/0009-0004-6535-8772)

### License

GNU General Public License v3.0 — see [LICENSE](LICENSE)

---

## Español

### Descripción

Este proyecto provee dos generadores de reportes PDF para el [plugin Attendance de Moodle](https://github.com/danmarsden/moodle-mod_attendance):

- **Libro de Temas** (`libro_temas.php`): Lista cronológica de temas de clase extraídos de las descripciones de sesión. Diseñado para reemplazar el libro de temas en papel requerido por las autoridades educativas.
- **Planilla de Asistencia** (`planilla_asistencia.php`): Matriz en formato horizontal que muestra el estado de asistencia de cada estudiante por sesión, con una columna de porcentaje calculada a partir de los valores de grade definidos en cada curso.

Ambos reportes se generan como PDFs descargables directamente desde la interfaz de Moodle, activados por botones inyectados en la página de la actividad Attendance mediante un archivo JavaScript liviano.

### Características principales

- Sin modificaciones al core de Moodle
- Sin plugins adicionales más allá de `mod_attendance`
- Sobrevive actualizaciones de Moodle y del plugin
- Funciona en múltiples cursos e instalaciones
- El porcentaje de asistencia respeta la escala de calificación personalizada de cada curso
- Consciente de la fecha de matriculación: el porcentaje se calcula desde la fecha de inscripción del estudiante, no desde el inicio del curso
- Corte por año lectivo: los reportes se limitan al año lectivo en curso (1° de marzo – 28/29 de febrero)
- Botones visibles solo para docentes y gestores
- El nombre del PDF incluye el nombre del curso y la fecha de generación

### Arquitectura

```
[Botón en página de Attendance]
        ↓
[libro-temas-btn.js — inyectado via HTML adicional de Moodle]
        ↓
[Script PHP en el servidor de hosting]
  - Lee config.php de Moodle para credenciales DB
  - Verifica token de sesión y rol del usuario
  - Consulta mdl_attendance_sessions / mdl_attendance_log
  - Genera PDF via TCPDF
        ↓
[Descarga: NombreReporte_NombreCurso_YYYYMMDD.pdf]
```

### Requisitos

- Moodle 4.x o 5.x
- PHP 8.1+
- [TCPDF](https://github.com/tecnickcom/tcpdf) (colocar en `public_html/libs/tcpdf/`)
- Plugin `mod_attendance` instalado y configurado
- Hosting compartido o servidor con acceso directo a la DB

### Instalación

1. **Descargar TCPDF** y ubicarlo en `public_html/libs/tcpdf/tcpdf.php`

2. **Editar la configuración** en ambos archivos PHP — buscar la sección `CONFIGURATION`:
   ```php
   // ── CONFIGURATION ────────────────────────────────────────────────────────
   $moodle_config  = '/home/youruser/yourmoodle/config.php';
   $allowed_origins = ['https://yourmoodle.example.com'];
   $campus_url      = 'https://yourmoodle.example.com';
   $db_prefix_fallback = 'mdl_'; // fallback si el regex falla
   $logo_url        = 'https://yoursite.com/logo.png';
   $institution     = 'Nombre de su institución';
   ```

3. **Subir** `libro_temas.php`, `planilla_asistencia.php` y `libro-temas-btn.js` a `public_html/js/`

4. **Editar** `libro-temas-btn.js` — configurar la URL base:
   ```javascript
   var BASE_URL = 'https://yoursite.com/js/';
   ```

5. **Agregar** al HTML adicional de Moodle (Administración del sitio → Apariencia → Temas → [su tema] → "Cuando BODY está abierto"):
   ```html
   <script src="https://yoursite.com/js/libro-temas-btn.js"></script>
   ```

6. Abrir cualquier actividad Attendance como docente — los botones de descarga aparecerán al final de la página.

### Seguridad

- Las solicitudes se validan contra una lista de orígenes permitidos (`HTTP_REFERER`)
- El token de sesión se verifica contra `mdl_sessions` (válido por 2 horas)
- El rol del usuario se verifica contra `mdl_role_assignments` para el curso específico
- Los administradores del sitio también tienen acceso

### Contribuciones

Se aceptan Pull Requests. Si desea ver esta funcionalidad integrada en el plugin oficial de Attendance, consulte el feature request abierto en [danmarsden/moodle-mod_attendance](https://github.com/danmarsden/moodle-mod_attendance/issues).

### Autor

**Luis Lanza**  
ORCID: [0009-0004-6535-8772](https://orcid.org/0009-0004-6535-8772)

### Licencia

GNU General Public License v3.0 — ver [LICENSE](LICENSE)
