<?php
/**
 * Satu-satunya file yang perlu di-require oleh halaman:
 *   require_once __DIR__ . '/../app/bootstrap.php';
 */
declare(strict_types=1);

// Berkas ini hanya boleh di-include, tidak dibuka langsung lewat browser.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(403);
    exit('403 - Akses ditolak.');
}
// Penanda bahwa berkas di folder app/ dimuat lewat jalur yang benar.
define('BHM_APP', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/wa.php';
require_once __DIR__ . '/sheets.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/kas.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/render.php';

start_session();

// Pengganti cron di hosting tanpa scheduler (dijalankan maks 1x/hari).
if (!defined('SKIP_DAILY_JOB')) {
    maybe_run_daily();
}
