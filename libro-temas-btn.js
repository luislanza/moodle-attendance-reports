/**
 * libro-temas-btn.js
 * Injects "Lesson Log" and "Attendance Sheet" download buttons
 * into Moodle's Attendance activity page. Visible only to teachers/managers.
 *
 * Part of: moodle-attendance-reports
 * Author:  Luis Lanza — https://orcid.org/0009-0004-6535-8772
 * License: GNU GPL v3
 * Repo:    https://github.com/luislanza/moodle-attendance-reports
 *
 * CONFIGURATION: set BASE_URL to the URL where your PHP scripts are hosted.
 */

(function () {
    'use strict';

    // ── CONFIGURATION ─────────────────────────────────────────────────────────
    var BASE_URL = 'https://yoursite.com/js/'; // trailing slash required
    // ── END CONFIGURATION ─────────────────────────────────────────────────────

    // Only act on Attendance pages
    if (window.location.pathname.indexOf('/mod/attendance/') === -1) return;

    // Only for teachers / managers / admins
    var body = document.body;
    var hasRole = body.classList.contains('role-editingteacher') ||
                  body.classList.contains('role-manager') ||
                  body.classList.contains('role-admin');
    var canEdit = !!document.querySelector('#page-mod-attendance-manage, [data-action="turn-editing-on"]');
    if (!hasRole && !canEdit) return;

    // Get courseid from body class (most reliable)
    var courseid = '';
    body.className.split(' ').forEach(function(c) {
        if (c.indexOf('course-') === 0) courseid = c.replace('course-', '');
    });
    if (!courseid) {
        courseid = new URLSearchParams(window.location.search).get('id') ||
                   new URLSearchParams(window.location.search).get('course');
    }
    if (!courseid) return;

    // Get Moodle session cookie
    var token = '';
    document.cookie.split(';').forEach(function(c) {
        c = c.trim();
        if (c.indexOf('MoodleSession') === 0) token = c.split('=')[1];
    });
    if (!token && window.M && M.cfg && M.cfg.sesskey) token = M.cfg.sesskey;

    // Create button
    function crearBoton(label, endpoint, color) {
        var btn = document.createElement('a');
        btn.href = BASE_URL + endpoint + '?courseid=' + courseid + '&token=' + encodeURIComponent(token);
        btn.target = '_blank';
        btn.style.cssText = [
            'display:inline-flex', 'align-items:center', 'gap:6px',
            'padding:8px 16px', 'background-color:' + color, 'color:#fff',
            'border-radius:4px', 'text-decoration:none', 'font-size:0.9em',
            'font-weight:600', 'margin:10px 6px 10px 0', 'cursor:pointer',
            'box-shadow:0 2px 4px rgba(0,0,0,0.15)'
        ].join(';');
        btn.innerHTML = label;
        var dark = color === '#0f6fc5' ? '#0a5aa0' : '#1a7a3a';
        btn.addEventListener('mouseenter', function() { this.style.backgroundColor = dark; });
        btn.addEventListener('mouseleave', function() { this.style.backgroundColor = color; });
        btn.addEventListener('click', function() {
            var orig = btn.innerHTML;
            btn.innerHTML = '&#9203; Generating PDF...';
            btn.style.backgroundColor = '#555';
            setTimeout(function() { btn.innerHTML = orig; btn.style.backgroundColor = color; }, 4000);
        });
        return btn;
    }

    // Insert buttons at the bottom of the main content area
    function insertarBotones() {
        if (document.getElementById('wrapper-attendance-reports')) return;
        var wrapper = document.createElement('div');
        wrapper.id = 'wrapper-attendance-reports';
        wrapper.style.margin = '16px 0';
        wrapper.appendChild(crearBoton('&#128203; Lesson Log / Libro de Temas',         'libro_temas.php',         '#0f6fc5'));
        wrapper.appendChild(crearBoton('&#128101; Attendance Sheet / Planilla de Asistencia', 'planilla_asistencia.php', '#217a3c'));
        var main = document.querySelector('#region-main, [role="main"]');
        if (main) main.appendChild(wrapper);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', insertarBotones);
    } else {
        insertarBotones();
    }

})();
