@echo off
rem Editor de posts NEXUS: abre no browser. Feche esta janela para o desligar.
cd /d "%~dp0"
start "" http://127.0.0.1:8020/
"C:\Users\dev01\tools\php83\php.exe" -d upload_max_filesize=20M -d post_max_size=25M -S 127.0.0.1:8020 -t . modelos\editor\servidor.php
