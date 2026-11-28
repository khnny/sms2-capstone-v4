@echo off
rem Double-click to get the latest SMS2 code from GitHub (pull-only, never pushes).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\update-local.ps1"
