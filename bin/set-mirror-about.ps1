# set-mirror-about.ps1
# Sets description and topics on all 20 LombokClarion mirror repos.
# Requires: gh CLI installed and authenticated (gh auth login)
#
# Usage: .\set-mirror-about.ps1

$repos = @{
    "active-record"  = @{ desc = "Optional ActiveRecord pattern (opt-in, isolated from domain layer)"; topics = @("php","lombokclarion","active-record","orm","opt-in") }
    "auth"           = @{ desc = "Stateless HMAC auth, RBAC policies, Authenticate/Authorize middleware"; topics = @("php","lombokclarion","authentication","authorization","rbac") }
    "bus"            = @{ desc = "CommandBus, QueryBus, EventBus — one handler per command, explicitly registered"; topics = @("php","lombokclarion","cqrs","event-bus","command-bus") }
    "config"         = @{ desc = "Typed config compiler — schema + environment to readonly PHP classes"; topics = @("php","lombokclarion","configuration") }
    "console"        = @{ desc = "CLI kernel with built-in migrate, optimize, audit, and seeder commands"; topics = @("php","lombokclarion","cli","console") }
    "container"      = @{ desc = "Compile-checked DI container — zero reflection at request time once compiled"; topics = @("php","lombokclarion","dependency-injection","ioc-container") }
    "facades"        = @{ desc = "Optional static-accessor facades (opt-in, isolated from domain layer)"; topics = @("php","lombokclarion","facades","opt-in") }
    "framework"      = @{ desc = "Metapackage — one require for the full LombokClarion runtime stack"; topics = @("php","lombokclarion","framework","metapackage","full-stack") }
    "http"           = @{ desc = "Immutable Request/Response, Middleware contract, RequestContext, multi-tenancy"; topics = @("php","lombokclarion","http","middleware","request","response") }
    "i18n"           = @{ desc = "Internationalization — locale catalogs, interpolation, pluralization (24 locales)"; topics = @("php","lombokclarion","i18n","internationalization","localization") }
    "laravel-flavor" = @{ desc = "Optional preset enabling facades + ActiveRecord + default middleware in one call"; topics = @("php","lombokclarion","laravel","migration","opt-in") }
    "log"            = @{ desc = "Structured logging — channels, handlers, formatters, built-in context redaction"; topics = @("php","lombokclarion","logging","structured-logging") }
    "persistence"    = @{ desc = "QueryBuilder (bound params only), SchemaBuilder, MigrationRunner, SeederRunner"; topics = @("php","lombokclarion","database","query-builder","migrations") }
    "phpstan-rules"  = @{ desc = "PHPStan extension — SQL injection detection + domain boundary enforcement"; topics = @("php","lombokclarion","phpstan","static-analysis","security") }
    "routing"        = @{ desc = "Route table, middleware pipeline, Kernel, adapters (FPM/Function/Swoole)"; topics = @("php","lombokclarion","routing","swoole","serverless") }
    "security"       = @{ desc = "Argon2id hashing, stateless CSRF, RateLimit, SecurityHeaders, Encrypted fields"; topics = @("php","lombokclarion","security","csrf","encryption") }
    "storage"        = @{ desc = "File storage with traversal-validated paths (local filesystem)"; topics = @("php","lombokclarion","storage","filesystem") }
    "testing"        = @{ desc = "InMemoryRepository, FakeCommandBus/EventBus, HttpTestCase, ColdStartTest"; topics = @("php","lombokclarion","testing","test-helpers") }
    "validation"     = @{ desc = "FormRequest validation — typed Rule vocabulary, DTO mapping, i18n (24 locales)"; topics = @("php","lombokclarion","validation","form-request") }
    "view"           = @{ desc = "Blade-like template compiler — auto-escaping by default"; topics = @("php","lombokclarion","template-engine","blade","view") }
}

$org = "codinglombok"
$failed = @()

foreach ($name in $repos.Keys | Sort-Object) {
    $info = $repos[$name]
    $fullName = "$org/$name"

    Write-Host "Setting $fullName ... " -NoNewline

    # Set description + homepage
    try {
        gh repo edit $fullName `
            --description "[READ-ONLY] $($info.desc)" `
            --homepage "https://github.com/codinglombok/LombokClarion" `
            2>&1 | Out-Null

        # Set topics
        $topicArgs = $info.topics | ForEach-Object { "--add-topic=$_" }
        gh repo edit $fullName @topicArgs 2>&1 | Out-Null

        Write-Host "OK" -ForegroundColor Green
    }
    catch {
        Write-Host "FAILED: $_" -ForegroundColor Red
        $failed += $name
    }
}

Write-Host ""
if ($failed.Count -gt 0) {
    Write-Host "Failed repos: $($failed -join ', ')" -ForegroundColor Red
} else {
    Write-Host "All $($repos.Count) repos updated." -ForegroundColor Green
}
