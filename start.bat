@echo off
chcp 65001 >nul
title FastMasseg Telegram Bot (PHP)
cd /d "%~dp0"
:loop
php bot.php
echo.
echo [!] Бот остановился. Перезапуск через 5 секунд (или закройте окно)...
timeout /t 5
goto loop
