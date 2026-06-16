@echo off
mkdir C:\VBScript >nul 2>&1
echo Set objShell = CreateObject("WScript.Shell") > C:\VBScript\abrir_diarq.vbs
echo objShell.Run "explorer.exe ""\\MDS.NET\MDS\SE\SAA\CGLA\CDA\DIARQ\""" >> C:\VBScript\abrir_diarq.vbs
echo Windows Registry Editor Version 5.00 > %temp%\config_diarq.reg
echo [HKEY_CURRENT_USER\Software\Classes\diarq] >> %temp%\config_diarq.reg
echo "URL Protocol"="" >> %temp%\config_diarq.reg
echo [HKEY_CURRENT_USER\Software\Classes\diarq\shell\open\command] >> %temp%\config_diarq.reg
echo @="wscript.exe \"C:\\VBScript\\abrir_diarq.vbs\"" >> %temp%\config_diarq.reg
reg import %temp%\config_diarq.reg
del %temp%\config_diarq.reg
echo INSTALADO COM SUCESSO!
pause
