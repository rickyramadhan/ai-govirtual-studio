@echo off
rem Cek harian API key & model AI Guide (dijadwalkan Task Scheduler "AI Guide Health Daily")
"C:\xampp\php\php.exe" "C:\xampp\htdocs\ai-guide-hub\_ai_health_check.php" >> "C:\xampp\htdocs\ai-guide-hub\storage\ai-health.log" 2>&1
