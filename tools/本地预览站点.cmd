@echo off
chcp 65001 >nul
cd /d "%~dp0.."
if not exist ".dev\php\php.exe" (
  echo [提示] 没有找到 .dev\php\php.exe，本地预览需要开发用的便携 PHP。
  echo        运行发行版时不需要本地预览，直接部署到服务器即可。
  pause
  exit /b 1
)
echo 预览地址: http://127.0.0.1:8123  （关闭此窗口即可停止）
echo 注意: 需要本地 MariaDB 已在 3307 端口运行（见 README 的本地开发说明）
start "" http://127.0.0.1:8123/
".dev\php\php.exe" -S 127.0.0.1:8123 -t public
pause
