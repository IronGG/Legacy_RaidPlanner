@echo off
setlocal EnableExtensions EnableDelayedExpansion
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
  echo.
  echo PHP est introuvable sur ce PC.
  echo Installe-le avec cette commande, puis FERME et ROUVRE le terminal :
  echo.
  echo     winget install --id PHP.PHP.8.3 -e
  echo.
  echo Ensuite relance start.bat
  pause
  exit /b 1
)

rem --- Dossier reel de PHP (winget passe par un lien symbolique) ---
set "PHPDIR="
for /f "usebackq delims=" %%i in (`php -n -r "echo dirname(realpath(PHP_BINARY));"`) do set "PHPDIR=%%i"
if not exist "!PHPDIR!\ext\php_pdo_sqlite.dll" (
  for /d %%d in ("%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP*") do (
    if exist "%%d\ext\php_pdo_sqlite.dll" set "PHPDIR=%%d"
  )
)

rem --- Charge les extensions manquantes sans toucher a php.ini ---
rem pdo_sqlite et mbstring : indispensables ; curl et openssl : lecture du GS et du CP sur le site officiel
set "PATH=!PHPDIR!;%PATH%"
set "OPTS="
php -m | findstr /i /x "pdo_sqlite" >nul || set "OPTS=!OPTS! -d extension=pdo_sqlite"
php -m | findstr /i /x "mbstring" >nul || set "OPTS=!OPTS! -d extension=mbstring"
php -m | findstr /i /x "curl" >nul || set "OPTS=!OPTS! -d extension=curl"
php -m | findstr /i /x "openssl" >nul || set "OPTS=!OPTS! -d extension=openssl"
if defined OPTS set "OPTS=-d extension_dir="!PHPDIR!\ext"!OPTS!"

php !OPTS! -r "exit(extension_loaded('pdo_sqlite') && extension_loaded('mbstring') ? 0 : 1);"
if errorlevel 1 (
  echo.
  echo Impossible de charger les extensions pdo_sqlite et mbstring.
  echo Dossier PHP detecte : !PHPDIR!
  echo Verifie que ce dossier contient ext\php_pdo_sqlite.dll
  pause
  exit /b 1
)

set "URL=http://localhost:8080/"

rem --- Premier lancement : base de donnees + compte officier ---
if not exist "data\guildraid.sqlite" (
  echo.
  echo Premier lancement : creation de la base et de ton compte officier.
  set /p "PSEUDO=Ton pseudo en jeu : "
  php !OPTS! bin\install.php "!PSEUDO!"
  echo.
  echo Note le code d'activation ci-dessus : le navigateur va s'ouvrir sur la page d'activation.
  pause
  set "URL=http://localhost:8080/activate.php?pseudo=!PSEUDO!"
)

echo.
echo App lancee sur http://localhost:8080   (Ctrl+C pour arreter)
start "" "!URL!"
php !OPTS! -S localhost:8080 -t public
