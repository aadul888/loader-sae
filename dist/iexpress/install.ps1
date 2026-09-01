$ErrorActionPreference = 'Stop'
$target = 'C:\LoaderSAE'
New-Item -ItemType Directory -Path $target -Force | Out-Null
$src = $PSScriptRoot
Get-ChildItem $src -Force | Where-Object { $_.Name -notin @('install.ps1','install.bat') } | Copy-Item -Destination $target -Recurse -Force
$php = Join-Path $target 'php'
$path = [Environment]::GetEnvironmentVariable('Path','Machine')
if ($path -notlike "*$php*") {
  try { [Environment]::SetEnvironmentVariable('Path', ($path.TrimEnd(';') + ';' + $php), 'Machine') }
  catch { [Environment]::SetEnvironmentVariable('Path', (([Environment]::GetEnvironmentVariable('Path','User')).TrimEnd(';') + ';' + $php), 'User') }
}
$desktop = [Environment]::GetFolderPath('DesktopDirectory')
$shortcut = Join-Path $desktop 'Loader SAE.url'
$exe = Join-Path $target 'LoaderSAE.exe'
$ico = Join-Path $target 'sae.ico'
Set-Content $shortcut "[InternetShortcut]`r`nURL=file:///$($exe.Replace('\','/'))`r`nIconFile=$ico`r`nIconIndex=0`r`n"
Start-Process $exe
Add-Type -AssemblyName System.Windows.Forms
[System.Windows.Forms.MessageBox]::Show("Loader SAE terinstall di $target`nShortcut dibuat di Desktop.`nPHP portable disiapkan otomatis.", 'Loader SAE Installer') | Out-Null
