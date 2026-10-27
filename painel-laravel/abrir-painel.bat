@echo off
rem Abre o painel de aprovacao NEXUS. Feche esta janela para o desligar.
cd /d "%~dp0"
start "" http://localhost:8000
"C:\Users\dev01\tools\php83\php.exe" artisan serve --port=8000
