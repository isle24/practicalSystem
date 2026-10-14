param([string]$Operation, [string]$Source, [string]$Destination, [string]$Ownership)
$ErrorActionPreference = 'Stop'
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class PracticalWordWindow {
    [DllImport("user32.dll")]
    public static extern uint GetWindowThreadProcessId(IntPtr hwnd, out uint processId);
}
'@
if ($Operation -eq 'stop') {
    if (Test-Path -LiteralPath $Ownership) {
        $owned = Get-Content -LiteralPath $Ownership -Raw | ConvertFrom-Json
        $process = Get-Process -Id ([int]$owned.pid) -ErrorAction SilentlyContinue
        if ($process -and $process.ProcessName -eq 'WINWORD' -and
            $process.StartTime.ToUniversalTime().Ticks.ToString() -eq $owned.started) {
            Stop-Process -Id $process.Id -Force
        }
    }
    exit 0
}
if ($Operation -ne 'convert') { exit 2 }
$existing = @(Get-Process -Name WINWORD -ErrorAction SilentlyContinue | ForEach-Object { $_.Id })
$word = $null
$document = $null
$ownedInstance = $false
$cancelPath = Join-Path (Split-Path -Parent $Ownership) 'cancel-word.flag'
try {
    if (Test-Path -LiteralPath $cancelPath) { throw 'Conversion cancelled' }
    $word = New-Object -ComObject Word.Application
    [uint32]$wordProcess = 0
    [void][PracticalWordWindow]::GetWindowThreadProcessId([IntPtr]$word.Hwnd, [ref]$wordProcess)
    if ($wordProcess -eq 0 -or $existing -contains [int]$wordProcess) {
        throw 'Word cannot provide a dedicated conversion instance'
    }
    if ($word.Documents.Count -ne 0) { throw 'Conversion instance is not empty' }
    $ownedInstance = $true
    $process = Get-Process -Id $wordProcess
    $ownershipTemporary = $Ownership + '.part'
    @{pid = $wordProcess; started = $process.StartTime.ToUniversalTime().Ticks.ToString()} |
        ConvertTo-Json -Compress | Set-Content -LiteralPath $ownershipTemporary -Encoding UTF8
    [System.IO.File]::Move($ownershipTemporary, $Ownership)
    if ((Test-Path -LiteralPath $cancelPath) -or !(Test-Path -LiteralPath $Source)) { throw 'Conversion cancelled' }
    $originalLinksAtOpen = $word.Options.UpdateLinksAtOpen
    $originalFieldsAtPrint = $word.Options.UpdateFieldsAtPrint
    $originalLinksAtPrint = $word.Options.UpdateLinksAtPrint
    $word.AutomationSecurity = 3
    $word.DisplayAlerts = 0
    $word.Visible = $false
    $word.Options.UpdateLinksAtOpen = $false
    $word.Options.UpdateFieldsAtPrint = $false
    $word.Options.UpdateLinksAtPrint = $false
    if (Test-Path -LiteralPath $cancelPath) { throw 'Conversion cancelled' }
    $document = $word.Documents.Open($Source, $false, $true, $false, '', '', $false, '', '', 0, [System.Type]::Missing, $false, $false, $false, [System.Type]::Missing, $true)
    if (Test-Path -LiteralPath $cancelPath) { throw 'Conversion cancelled' }
    $document.ExportAsFixedFormat($Destination, 17, $false)
} catch {
    [Console]::Error.WriteLine('WORD_CONVERSION_FAILED')
    exit 1
} finally {
    if ($document) {
        try { $document.Close(0) } catch {}
        [void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($document)
    }
    if ($word) {
        if ($ownedInstance) {
            try {
                $word.Options.UpdateLinksAtOpen = $originalLinksAtOpen
                $word.Options.UpdateFieldsAtPrint = $originalFieldsAtPrint
                $word.Options.UpdateLinksAtPrint = $originalLinksAtPrint
            } catch {}
            try { $word.Quit(0) } catch {}
        }
        [void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($word)
    }
}
