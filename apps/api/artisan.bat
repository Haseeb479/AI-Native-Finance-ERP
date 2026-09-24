@echo off
pushd "%~dp0"
php artisan %*
popd
