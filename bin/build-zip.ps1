# ==============================================================================
# Script de build et packaging de l'extension Woo Search Intelligence by SOYOO
# SOYOO - Julien Vanwinsberghe
# ==============================================================================

$ErrorActionPreference = "Stop"

$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
$PluginSlug  = "woo-search-intelligence-soyoo"
$ZipName     = "$PluginSlug.zip"
$ZipPath     = Join-Path $ProjectRoot $ZipName
$StagingRoot = Join-Path $env:TEMP "soyoo-build-$PluginSlug"
$StagingDir  = Join-Path $StagingRoot $PluginSlug

Write-Host "--- Démarrage du build : $PluginSlug ---" -ForegroundColor Cyan

# 1. Vérification syntaxique PHP de tous les fichiers
Write-Host "1. Analyse syntaxique PHP (php -l)..." -ForegroundColor Yellow
$PhpFiles = Get-ChildItem -Path $ProjectRoot -Filter "*.php" -Recurse | Where-Object {
    $_.FullName -notmatch "\\\.git\\" -and $_.FullName -notmatch "\\vendor\\"
}

$HasError = $false
foreach ($File in $PhpFiles) {
    $RelPath = $File.FullName.Substring($ProjectRoot.Path.Length + 1)
    $Result = & php -l $File.FullName 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Host "  [ERREUR] $RelPath : $Result" -ForegroundColor Red
        $HasError = $true
    }
}

if ($HasError) {
    Write-Error "Échec du build : erreurs de syntaxe PHP détectées."
}
Write-Host "  -> Tous les fichiers PHP sont syntaxiquement valides." -ForegroundColor Green

# 2. Préparation du répertoire de staging temporaire
Write-Host "2. Préparation du dossier de packaging..." -ForegroundColor Yellow
if (Test-Path $StagingRoot) {
    Remove-Item -Path $StagingRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $StagingDir -Force | Out-Null

# 3. Copie des fichiers éligibles
$ItemsToCopy = @(
    "woo-search-intelligence-soyoo.php",
    "README.md",
    "includes",
    "assets",
    "languages",
    "plugin-update-checker"
)

foreach ($Item in $ItemsToCopy) {
    $SourcePath = Join-Path $ProjectRoot $Item
    if (Test-Path $SourcePath) {
        Copy-Item -Path $SourcePath -Destination $StagingDir -Recurse -Force
    }
}

# 4. Suppression de l'ancienne archive si existante
if (Test-Path $ZipPath) {
    Remove-Item -Path $ZipPath -Force
}

# 5. Création de l'archive ZIP (via tar pour garantir les forward slashes / compatibles Linux/WordPress)
Write-Host "3. Compression de l'archive $ZipName..." -ForegroundColor Yellow
Push-Location $StagingRoot
tar -a -cf $ZipPath $PluginSlug
Pop-Location

# 6. Nettoyage du staging
Remove-Item -Path $StagingRoot -Recurse -Force

# 7. Résumé
$ZipInfo = Get-Item $ZipPath
$ZipSizeKb = [math]::Round($ZipInfo.Length / 1KB, 2)

Write-Host "=== Build réussi ! ===" -ForegroundColor Green
Write-Host "Archive générée : $ZipPath ($ZipSizeKb Ko)" -ForegroundColor Cyan
