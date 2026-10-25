@echo off
rem Cek kesehatan AI Guide semua lokasi (dijadwalkan Task Scheduler "AI Guide Health Lokasi").
"C:\xampp\php\php.exe" "C:\xampp\htdocs\ai-guide-hub\tools\health_check.php" --all --ui >> "C:\xampp\htdocs\ai-guide-hub\storage\health-check.log" 2>&1
