@echo off
pushd "%~dp0..\apps\ai"
".venv\Scripts\pytest.exe" -v tests
popd
