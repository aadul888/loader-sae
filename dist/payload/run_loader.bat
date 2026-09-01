@echo off
setlocal
cd /d "%~dp0"
set "PHP_EXE=%~dp0php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=php"
start "Loader SAE" http://localhost:4215
"%PHP_EXE%" -S localhost:4215 index.php
