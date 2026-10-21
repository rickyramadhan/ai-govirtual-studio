<?php
declare(strict_types=1);

// Hanya untuk CLI: dijalankan di latar belakang oleh Studio, atau manual.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Worker antrian pekerjaan: memproses semua job "pending" (Generate / Deploy / Resync) satu per satu.
 *
 *   php tools/worker.php
 */

ob_start();
require_once dirname(__DIR__) . '/index.php';
ob_end_clean();

$n = job_worker_run();
echo $n . " pekerjaan diproses.\n";
