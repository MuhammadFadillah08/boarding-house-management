<?php
define('SKIP_DAILY_JOB', true);
require_once __DIR__ . '/app/bootstrap.php';
logout();
start_session();
flash('Anda telah keluar. Sampai jumpa!', 'info');
redirect('login.php');
