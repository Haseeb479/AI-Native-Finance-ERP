@echo off
pushd "%~dp0.."
"apps\ai\.venv\Scripts\python.exe" -m uvicorn apps.ai.src.main:app --host 127.0.0.1 --port 8001 --reload
popd
