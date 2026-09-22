@echo off
pushd "%~dp0..\apps\api"
php -d extension=pdo_pgsql -d extension=pgsql artisan %*
popd
