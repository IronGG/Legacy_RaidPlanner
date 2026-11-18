@echo off
rem Fabrique le zip a envoyer sur Infomaniak, a partir du dernier commit git.
rem Seuls les fichiers suivis par git y entrent : jamais data\ (base, sauvegardes, cle de poivre),
rem ni seed\membres.json, ni les icones envoyees depuis le site. Les fichiers marques
rem export-ignore dans .gitattributes (start.bat, Docker, ce script) sont aussi laisses de cote.
setlocal
cd /d "%~dp0"

where git >nul 2>nul
if errorlevel 1 (
  echo Git est introuvable sur ce PC.
  pause
  exit /b 1
)

set "STATUS=%TEMP%\guildraid-status.txt"
git status --porcelain > "%STATUS%"
for %%A in ("%STATUS%") do set "SIZE=%%~zA"
if not "%SIZE%"=="0" (
  echo Attention : ces modifications ne sont pas commitees, elles ne seront PAS dans le zip :
  echo.
  type "%STATUS%"
  echo.
  choice /m "Continuer quand meme"
  if errorlevel 2 exit /b 1
)

set "ZIP=%~dp0..\guildraid-app-infomaniak.zip"
git archive --format=zip --worktree-attributes --prefix=guildraid-app/ -o "%ZIP%" HEAD
if errorlevel 1 (
  echo La creation du zip a echoue.
  pause
  exit /b 1
)
for /f %%c in ('git rev-parse --short HEAD') do set "REV=%%c"
echo.
echo Zip cree : %ZIP%
echo Version  : commit %REV%
pause
