param(
    [int]$Port = 0,
    [string]$Php = 'C:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'
$source = Split-Path -Parent $PSScriptRoot
$temp = Join-Path $env:TEMP ('EasySched-Smoke-' + [guid]::NewGuid().ToString('N'))
$server = $null

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "FAIL: $Message" }
}

function Assert-ApiFailure {
    param(
        [string]$Action,
        [ValidateSet('GET', 'POST')][string]$Method = 'GET',
        [hashtable]$Body = @{},
        [string]$Csrf = '',
        [string]$Expected = '401|403|409|419|422'
    )
    $failed = $false
    try {
        Invoke-Api -Action $Action -Method $Method -Body $Body -Csrf $Csrf | Out-Null
    } catch {
        $failed = $true
        Assert-True ($_.Exception.Message -match $Expected) "Action '$Action' failed for an unexpected reason: $($_.Exception.Message)"
    }
    Assert-True $failed "Action '$Action' unexpectedly succeeded."
}

function Invoke-Api {
    param(
        [string]$Action,
        [ValidateSet('GET', 'POST')][string]$Method = 'GET',
        [hashtable]$Body = @{},
        [string]$Csrf = ''
    )
    $uri = "http://127.0.0.1:$Port/api.php?action=$Action"
    $headers = @{}
    if ($Csrf) { $headers['X-CSRF-Token'] = $Csrf }
    $params = @{ Uri = $uri; Method = $Method; WebSession = $script:Session; UseBasicParsing = $true }
    if ($headers.Count) { $params.Headers = $headers }
    if ($Method -eq 'POST') {
        $params.ContentType = 'application/json'
        $params.Body = ($Body | ConvertTo-Json -Depth 10)
    }
    try {
        $response = Invoke-WebRequest @params
        try {
            $payload = $response.Content | ConvertFrom-Json
        } catch {
            $preview = [string]$response.Content
            if ($preview.Length -gt 500) { $preview = $preview.Substring(0, 500) }
            throw "Action '$Action' returned non-JSON output: $preview"
        }
    } catch {
        $response = $_.Exception.Response
        if ($null -ne $response) {
            $content = [string]$_.ErrorDetails.Message
            if (-not $content) {
                $reader = New-Object IO.StreamReader($response.GetResponseStream())
                $content = $reader.ReadToEnd()
                $reader.Dispose()
            }
            try {
                $payload = $content | ConvertFrom-Json
                throw "HTTP $([int]$response.StatusCode): $($payload.error)"
            } catch [System.ArgumentException] {
                throw "HTTP $([int]$response.StatusCode): $content"
            }
        }
        throw
    }
    Assert-True ([bool]$payload.ok) "API action '$Action' returned an error: $($payload.error)"
    return $payload.data
}

try {
    Assert-True (Test-Path -LiteralPath $Php) "PHP executable was not found at $Php"
    if ($Port -le 0) {
        $listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback, 0)
        $listener.Start()
        $Port = $listener.LocalEndpoint.Port
        $listener.Stop()
    }
    New-Item -ItemType Directory -Path $temp -Force | Out-Null
    Copy-Item -Path (Join-Path $source '*') -Destination $temp -Recurse -Force
    $sessionPath = Join-Path $temp 'sessions'
    New-Item -ItemType Directory -Path $sessionPath -Force | Out-Null
    $database = Join-Path $temp 'data\easysched.sqlite'
    if (Test-Path -LiteralPath $database) { Remove-Item -LiteralPath $database -Force }

    $server = Start-Process -FilePath $Php -ArgumentList @('-d', "session.save_path=$sessionPath", '-S', "127.0.0.1:$Port", '-t', $temp) -WorkingDirectory $temp -PassThru -WindowStyle Hidden
    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        try {
            $health = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/index.php" -UseBasicParsing -TimeoutSec 2
            if ($health.StatusCode -eq 200) { $ready = $true; break }
        } catch { Start-Sleep -Milliseconds 250 }
    }
    Assert-True $ready 'PHP local server did not become ready.'

    $script:Session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    Assert-ApiFailure -Action 'bootstrap' -Expected '401|Authentication'

    $login = Invoke-Api -Action 'login' -Method POST -Body @{ username = 'admin'; password = 'Admin123!' }
    Assert-True ($login.user.role -eq 'admin') 'Admin login did not return the admin role.'
    Assert-True ($login.sections.Count -gt 0) 'Bootstrap did not return seeded sections.'
    $csrf = [string]$login.csrf
    $adminSession = $script:Session
    Assert-ApiFailure -Action 'generate' -Method POST -Body @{ term_id = [int]$login.active_term_id } -Expected '419|token'

    $programCode = 'QA' + [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
    $program = Invoke-Api -Action 'save_master' -Method POST -Csrf $csrf -Body @{ entity = 'programs'; data = @{ code = $programCode; name = 'Smoke Test Program' } }
    Assert-True (@($program.snapshot.programs | Where-Object { $_.code -eq $programCode }).Count -eq 1) 'Program creation did not appear in bootstrap data.'
    $programId = [int]$program.id
    $deactivated = Invoke-Api -Action 'save_master' -Method POST -Csrf ([string]$program.snapshot.csrf) -Body @{ entity = 'programs'; id = $programId; delete = $true }
    Assert-True (@($deactivated.snapshot.programs | Where-Object { [int]$_.id -eq $programId }).Count -eq 0) 'Program deactivation did not remove it from active bootstrap data.'

    $newUser = Invoke-Api -Action 'save_master' -Method POST -Csrf ([string]$deactivated.snapshot.csrf) -Body @{
        entity = 'users'
        data = @{
            username = 'defense_test'
            display_name = 'Defense Test Student'
            email = 'defense.test@example.invalid'
            role = 'student'
            instructor_id = ''
            section_id = [int]$login.sections[0].id
            password = 'Defense123!'
        }
    }
    Assert-True (@($newUser.snapshot.users | Where-Object { $_.username -eq 'defense_test' }).Count -eq 1) 'Administrator could not create a user account.'
    $newUserId = [int]$newUser.id
    $csrf = [string]$newUser.snapshot.csrf

    $generated = Invoke-Api -Action 'generate' -Method POST -Csrf $csrf -Body @{ term_id = [int]$login.active_term_id }
    Assert-True ($generated.diagnostics.assigned_tasks -eq $generated.diagnostics.total_tasks) 'Generation did not assign every task.'
    Assert-True ($generated.snapshot.active_run.status -eq 'PUBLISHED') 'A successful generation was not published.'
    Assert-True ($generated.snapshot.schedules.Count -gt 0) 'Published schedule is empty.'
    $publishedRunId = [int]$generated.snapshot.active_run.id

    $entries = @($generated.snapshot.schedules)
    Assert-True ($entries.Count -ge 2) 'The seeded schedule did not contain enough entries for conflict testing.'
    $target = $entries[0]
    $blocker = $entries[1]
    Assert-ApiFailure -Action 'save_schedule' -Method POST -Csrf ([string]$generated.snapshot.csrf) -Body @{
        entry_id = [int]$target.id
        room_id = [int]$blocker.room_id
        day_of_week = [int]$blocker.day_of_week
        slot_id = [int]$blocker.slot_id
    } -Expected '409|constraint|conflict'
    $afterRejectedEdit = Invoke-Api -Action 'bootstrap'
    $unchanged = @($afterRejectedEdit.schedules | Where-Object { [int]$_.id -eq [int]$target.id })[0]
    Assert-True ($null -ne $unchanged) 'Rejected manual edit removed the schedule entry.'
    Assert-True ([int]$unchanged.room_id -eq [int]$target.room_id -and [int]$unchanged.day_of_week -eq [int]$target.day_of_week -and [int]$unchanged.slot_id -eq [int]$target.slot_id) 'Rejected manual edit changed the published entry.'

    $export = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/api.php?action=export" -WebSession $script:Session -UseBasicParsing
    Assert-True ($export.Content -match 'Subject Code') 'CSV export did not contain the expected header.'

    & $Php (Join-Path $source 'tests\assert_database.php') $database
    if ($LASTEXITCODE -ne 0) { throw 'Database integrity assertions failed.' }

    $instructorSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $script:Session = $instructorSession
    $instructor = Invoke-Api -Action 'login' -Method POST -Body @{ username = 'instructor'; password = 'Instructor123!' }
    Assert-True ($instructor.user.role -eq 'instructor') 'Instructor login did not return the instructor role.'
    Assert-True (@($instructor.schedules | Where-Object { [int]$_.instructor_id -ne [int]$instructor.user.instructor_id }).Count -eq 0) 'Instructor schedule included another instructor.'

    $studentSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $script:Session = $studentSession
    $student = Invoke-Api -Action 'login' -Method POST -Body @{ username = 'student'; password = 'Student123!' }
    Assert-True ($student.user.role -eq 'student') 'Student login did not return the student role.'
    Assert-True ($student.schedules.Count -gt 0) 'Student did not receive the section-scoped schedule.'
    Assert-True (@($student.schedules | Where-Object { [int]$_.section_id -ne [int]$student.user.section_id }).Count -eq 0) 'Student schedule included another section.'
    Assert-True (@($student.sections | Where-Object { [int]$_.id -ne [int]$student.user.section_id }).Count -eq 0) 'Student bootstrap exposed another section.'
    Assert-True (@($student.offerings | Where-Object { [int]$_.section_id -ne [int]$student.user.section_id }).Count -eq 0) 'Student bootstrap exposed another offering.'
    Assert-ApiFailure -Action 'generate' -Method POST -Csrf ([string]$student.csrf) -Body @{ term_id = [int]$student.active_term_id } -Expected '403|permission'
    Assert-ApiFailure -Action 'save_master' -Method POST -Csrf ([string]$student.csrf) -Body @{ entity = 'programs'; id = 1; data = @{ code = 'HACK'; name = 'Unauthorized' } } -Expected '403|permission'
    Assert-ApiFailure -Action 'save_schedule' -Method POST -Csrf ([string]$student.csrf) -Body @{ entry_id = [int]$target.id; room_id = [int]$target.room_id; day_of_week = [int]$target.day_of_week; slot_id = [int]$target.slot_id } -Expected '403|permission'
    Assert-ApiFailure -Action 'sync_cloud' -Method POST -Csrf ([string]$student.csrf) -Body @{} -Expected '403|permission'

    $createdStudentSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $script:Session = $createdStudentSession
    $createdStudent = Invoke-Api -Action 'login' -Method POST -Body @{ username = 'defense_test'; password = 'Defense123!' }
    Assert-True ($createdStudent.user.role -eq 'student') 'The administrator-created account could not sign in.'
    Assert-True (@($createdStudent.schedules | Where-Object { [int]$_.section_id -ne [int]$createdStudent.user.section_id }).Count -eq 0) 'The administrator-created student account escaped its section scope.'

    $script:Session = $adminSession
    $admin = Invoke-Api -Action 'bootstrap'
    $csrf = [string]$admin.csrf
    $disabledUser = Invoke-Api -Action 'save_master' -Method POST -Csrf $csrf -Body @{ entity = 'users'; id = $newUserId; delete = $true }
    Assert-True (@($disabledUser.snapshot.users | Where-Object { [int]$_.id -eq $newUserId }).Count -eq 0) 'User deactivation did not remove the account from active users.'

    $schedulerSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $script:Session = $schedulerSession
    $scheduler = Invoke-Api -Action 'login' -Method POST -Body @{ username = 'scheduler'; password = 'Scheduler123!' }
    Assert-ApiFailure -Action 'save_master' -Method POST -Csrf ([string]$scheduler.csrf) -Body @{ entity = 'users'; data = @{ username = 'escalation'; display_name = 'Unauthorized'; role = 'admin'; password = 'Defense123!' } } -Expected '403|permission'
    Assert-ApiFailure -Action 'save_settings' -Method POST -Csrf ([string]$scheduler.csrf) -Body @{ academic_year = '2027-2028'; semester = 'First Semester' } -Expected '403|permission'

    $script:Session = $adminSession

    $impossibleSubject = Invoke-Api -Action 'save_master' -Method POST -Csrf ([string]$disabledUser.snapshot.csrf) -Body @{
        entity = 'subjects'
        data = @{ code = 'QA-IMPOSSIBLE'; name = 'Impossible Facility Test'; units = 3; hours_per_week = 2; duration_slots = 1; room_type = 'SPECIAL'; required_features = @() }
    }
    $impossibleOffering = Invoke-Api -Action 'save_master' -Method POST -Csrf ([string]$impossibleSubject.snapshot.csrf) -Body @{
        entity = 'offerings'
        data = @{
            term_id = [int]$impossibleSubject.snapshot.active_term_id
            subject_id = [int]$impossibleSubject.id
            section_id = [int]$impossibleSubject.snapshot.sections[0].id
            instructor_id = [int]$impossibleSubject.snapshot.instructors[0].id
            enrollment = 1
            required_meetings = 1
        }
    }
    Assert-ApiFailure -Action 'generate' -Method POST -Csrf ([string]$impossibleOffering.snapshot.csrf) -Body @{ term_id = [int]$impossibleOffering.snapshot.active_term_id } -Expected '422|eligible room|SPECIAL|complete conflict-free'
    $preserved = Invoke-Api -Action 'bootstrap'
    Assert-True ([int]$preserved.active_run.id -eq $publishedRunId) 'Failed generation replaced the previously published schedule.'

    Write-Output 'PASS: EasySched HTTP smoke test'
} finally {
    if ($server) { Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue }
    if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue }
}
