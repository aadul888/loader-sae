$ErrorActionPreference = 'Stop'
$out = $PSScriptRoot
$payload = Join-Path $out 'payload'
$zip = Join-Path $out 'payload.zip'
$exe = Join-Path $out 'LoaderSAE-Installer-Full.exe'
$ico = Join-Path $out 'sae.ico'
$logo = Join-Path $payload 'sae-logo.png'
$csc = 'C:\Windows\Microsoft.NET\Framework64\v4.0.30319\csc.exe'

. (Join-Path $out 'icon_helper.ps1')
New-IcoFromPng -PngPath $logo -IcoPath $ico

# Compile the shared uninstaller and launcher straight into the payload so they ship inside the zip.
& $csc /nologo /target:winexe /win32icon:"$ico" /out:"$(Join-Path $payload 'Uninstall.exe')" `
    /reference:System.dll,System.Core.dll,System.Windows.Forms.dll `
    "$(Join-Path $out 'Uninstall.cs')"
if ($LASTEXITCODE -ne 0) { throw 'Gagal mengompilasi Uninstall.exe' }

& $csc /nologo /target:winexe /win32icon:"$ico" /out:"$(Join-Path $payload 'LoaderSAE.exe')" `
    /reference:System.dll,System.Windows.Forms.dll `
    "$(Join-Path $out 'LoaderSAE.cs')"
if ($LASTEXITCODE -ne 0) { throw 'Gagal mengompilasi LoaderSAE.exe' }

Remove-Item $zip, $exe -Force -ErrorAction SilentlyContinue
Compress-Archive -Path (Join-Path $payload '*') -DestinationPath $zip -Force

& $csc /nologo /target:winexe /win32icon:"$ico" /out:"$exe" `
    /reference:System.dll,System.Windows.Forms.dll,System.IO.Compression.dll,System.IO.Compression.FileSystem.dll `
    /resource:"$zip",payload.zip `
    "$(Join-Path $out 'InstallerFull.cs')"
if ($LASTEXITCODE -ne 0) { throw 'Gagal mengompilasi LoaderSAE-Installer-Full.exe' }

Remove-Item $zip -Force
Get-Item $exe | Select-Object Name, Length, LastWriteTime
