@echo off
pushd "%~dp0"
php -d extension=pdo_pgsql -d extension=pgsql artisan %*
popd
