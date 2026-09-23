# slackai 워커를 작업 스케줄러에 "로그온 시(현재 사용자)" 작업으로 등록한다.
# 현재 사용자 계정으로 실행해야 claude CLI 의 OAuth 로그인을 그대로 쓴다(서비스 계정이면 CLAUDE_CODE_OAUTH_TOKEN 사용).
#   실행:  powershell -ExecutionPolicy Bypass -File install_task.ps1
#   해제:  powershell -ExecutionPolicy Bypass -File install_task.ps1 -Remove
param([switch]$Remove)

$TaskName = "slackai_worker"
$Here     = Split-Path -Parent $MyInvocation.MyCommand.Path
$Bat      = Join-Path $Here "start_worker.bat"

if ($Remove) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Write-Host "[slackai] 작업 제거: $TaskName"
    exit 0
}
if (-not (Test-Path (Join-Path $Here ".venv\Scripts\python.exe"))) {
    Write-Host "[slackai] .venv 가 없습니다. 먼저 python -m venv .venv; .venv\Scripts\python -m pip install -r requirements.txt"
    exit 1
}

$User     = "$env:USERDOMAIN\$env:USERNAME"
$Action   = New-ScheduledTaskAction -Execute "cmd.exe" -Argument "/c `"$Bat`"" -WorkingDirectory $Here
$Trigger  = New-ScheduledTaskTrigger -AtLogOn -User $User
$Settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 `
            -RestartInterval (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew -StartWhenAvailable
Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Settings $Settings `
    -User $User -RunLevel Limited -Force | Out-Null
Write-Host "[slackai] 등록 완료: $TaskName (로그온 시, $User). 지금 시작: Start-ScheduledTask -TaskName $TaskName"
