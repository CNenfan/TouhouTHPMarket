@echo off
chcp 65001 >nul
cd /d "%~dp0.."
if not exist ".dev\php\php.exe" (
  echo [提示] 没有找到 .dev\php\php.exe。
  pause
  exit /b 1
)
echo 生成「今天」的走势并刷新兜底文件...
".dev\php\php.exe" tools\gen-trend.php
".dev\php\php.exe" tools\gen-trend.php --fallback
pause
