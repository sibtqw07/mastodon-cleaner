<?php
#####################################
#
# Web UI for Mastodon / Pleroma cleaner.
# Delete posts older than X days using
# the profile stored in config.sqlite,
# created via the web UI (index.php).
#
# Author: JD Watson <jd@jonw.zone>
#
#####################################

declare(strict_types=1);

$dbPath = __DIR__ . '/config.sqlite';
$dsn    = 'sqlite:' . $dbPath;

// Initialize database and table
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        base_url TEXT NOT NULL,
        account_id TEXT NOT NULL,
        auth_method TEXT NOT NULL,
        access_token TEXT,
        basic_user TEXT,
        basic_pass TEXT,
        age_days INTEGER NOT NULL,
        ignore_tags TEXT,
        ignore_ids TEXT,
        ignore_pin INTEGER NOT NULL,
        fetch_limit INTEGER NOT NULL,
        delete_limit INTEGER NOT NULL,
        pause_seconds INTEGER NOT NULL,
        user_agent TEXT NOT NULL,
        schedule_cron TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )'
);

// Ensure schedule_cron exists for older DBs
$colsStmt = $pdo->query("PRAGMA table_info(profiles)");
$hasSchedule = false;
foreach ($colsStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
    if (isset($col['name']) && $col['name'] === 'schedule_cron') {
        $hasSchedule = true;
        break;
    }
}
if (!$hasSchedule) {
    $pdo->exec('ALTER TABLE profiles ADD COLUMN schedule_cron TEXT');
}

// Load a single profile (by id if provided, otherwise the first one)
function loadProfile(PDO $pdo, ?int $id = null): ?array
{
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT * FROM profiles WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
    } else {
        $stmt = $pdo->query('SELECT * FROM profiles ORDER BY id ASC LIMIT 1');
    }
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

// Load all profiles for listing
function loadAllProfiles(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM profiles ORDER BY name ASC, id ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function saveProfile(PDO $pdo, array $data): int
{
    $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

    $id = $data['id'] ?? null;

    $existing = $id !== null ? loadProfile($pdo, (int)$id) : null;
    if ($existing === null) {
        $stmt = $pdo->prepare(
            'INSERT INTO profiles
                (name, base_url, account_id, auth_method, access_token, basic_user, basic_pass,
                 age_days, ignore_tags, ignore_ids, ignore_pin, fetch_limit, delete_limit,
                 pause_seconds, user_agent, schedule_cron, created_at, updated_at)
             VALUES
                (:name, :base_url, :account_id, :auth_method, :access_token, :basic_user, :basic_pass,
                 :age_days, :ignore_tags, :ignore_ids, :ignore_pin, :fetch_limit, :delete_limit,
                 :pause_seconds, :user_agent, :schedule_cron, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':name'         => $data['name'],
            ':base_url'     => $data['base_url'],
            ':account_id'   => $data['account_id'],
            ':auth_method'  => $data['auth_method'],
            ':access_token' => $data['access_token'],
            ':basic_user'   => $data['basic_user'],
            ':basic_pass'   => $data['basic_pass'],
            ':age_days'     => $data['age_days'],
            ':ignore_tags'  => $data['ignore_tags'],
            ':ignore_ids'   => $data['ignore_ids'],
            ':ignore_pin'   => $data['ignore_pin'],
            ':fetch_limit'  => $data['fetch_limit'],
            ':delete_limit' => $data['delete_limit'],
            ':pause_seconds'=> $data['pause_seconds'],
            ':user_agent'   => $data['user_agent'],
            ':schedule_cron'=> $data['schedule_cron'] ?? null,
            ':created_at'   => $now,
            ':updated_at'   => $now,
        ]);
        return (int)$pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare(
            'UPDATE profiles SET
                name = :name,
                base_url = :base_url,
                account_id = :account_id,
                auth_method = :auth_method,
                access_token = :access_token,
                basic_user = :basic_user,
                basic_pass = :basic_pass,
                age_days = :age_days,
                ignore_tags = :ignore_tags,
                ignore_ids = :ignore_ids,
                ignore_pin = :ignore_pin,
                fetch_limit = :fetch_limit,
                delete_limit = :delete_limit,
                pause_seconds = :pause_seconds,
                user_agent = :user_agent,
                schedule_cron = :schedule_cron,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':id'           => $existing['id'],
            ':name'         => $data['name'],
            ':base_url'     => $data['base_url'],
            ':account_id'   => $data['account_id'],
            ':auth_method'  => $data['auth_method'],
            ':access_token' => $data['access_token'],
            ':basic_user'   => $data['basic_user'],
            ':basic_pass'   => $data['basic_pass'],
            ':age_days'     => $data['age_days'],
            ':ignore_tags'  => $data['ignore_tags'],
            ':ignore_ids'   => $data['ignore_ids'],
            ':ignore_pin'   => $data['ignore_pin'],
            ':fetch_limit'  => $data['fetch_limit'],
            ':delete_limit' => $data['delete_limit'],
            ':pause_seconds'=> $data['pause_seconds'],
            ':user_agent'   => $data['user_agent'],
            ':schedule_cron'=> $data['schedule_cron'] ?? null,
            ':updated_at'   => $now,
        ]);
        return (int)$existing['id'];
    }
}

// --- Schedule presets (cron: min hour day month dow) ---
$SCHEDULE_PRESETS = [
    ''                  => ['label' => 'Do not schedule (run manually only)', 'cron' => ''],
    'daily_midnight'    => ['label' => 'Daily at midnight (00:00)', 'cron' => '0 0 * * *'],
    'daily_3am'         => ['label' => 'Daily at 3:00 AM', 'cron' => '0 3 * * *'],
    'daily_6am'         => ['label' => 'Daily at 6:00 AM', 'cron' => '0 6 * * *'],
    'every_6h'          => ['label' => 'Every 6 hours', 'cron' => '0 */6 * * *'],
    'weekly_sun_1am'    => ['label' => 'Weekly on Sunday at 1:00 AM', 'cron' => '0 1 * * 0'],
    'weekly_mon_1am'    => ['label' => 'Weekly on Monday at 1:00 AM', 'cron' => '0 1 * * 1'],
    'custom'            => ['label' => 'Custom (set minute, hour, day, etc.)', 'cron' => null],
];

function isCrontabSupported(): bool
{
    if (PHP_OS_FAMILY === 'Windows' || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return false;
    }
    $which = @shell_exec('which crontab 2>/dev/null');
    if ($which === null || trim($which) === '') {
        return false;
    }
    $test = @shell_exec('crontab -l 2>/dev/null');
    return $test !== null;
}

function buildCronFromCustom(int $min, int $hour, int $dayMonth, int $month, int $dayWeek): string
{
    $m   = $min >= 0 && $min <= 59 ? (string)$min : '*';
    $h   = $hour >= 0 && $hour <= 23 ? (string)$hour : '*';
    $dom = $dayMonth >= 1 && $dayMonth <= 31 ? (string)$dayMonth : '*';
    $mon = $month >= 1 && $month <= 12 ? (string)$month : '*';
    $dow = $dayWeek >= 0 && $dayWeek <= 7 ? (string)$dayWeek : '*';
    return trim("$m $h $dom $mon $dow");
}

function deleteProfile(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM profiles WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}

function presetKeyForCron(string $cron, array $presets): string
{
    if ($cron === '') {
        return '';
    }
    foreach ($presets as $key => $info) {
        if (isset($info['cron']) && $info['cron'] === $cron) {
            return $key;
        }
    }
    return 'custom';
}

function getCrontabLines(): array
{
    $out = @shell_exec('crontab -l 2>/dev/null');
    if ($out === null || trim($out) === '') {
        return [];
    }
    return array_filter(array_map('trim', explode("\n", $out)));
}

function updateCrontabForProfile(int $profileId, string $cronExpression, string $scriptPath, string $logPath): array
{
    $marker = '# mastodon_cleaner profile ' . $profileId;
    $lines = getCrontabLines();
    $newLines = [];
    $i = 0;
    $n = count($lines);
    while ($i < $n) {
        $line = $lines[$i];
        if ($line === $marker && $i + 1 < $n) {
            $i += 2;
            continue;
        }
        $newLines[] = $line;
        $i++;
    }
    if ($cronExpression !== '') {
        $phpBin = trim((string)@shell_exec('which php 2>/dev/null')) ?: (defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php');
        $cmd = sprintf('%s %s %s --profile-id=%d >> %s 2>&1', $cronExpression, $phpBin, escapeshellarg($scriptPath), $profileId, escapeshellarg($logPath));
        $newLines[] = $marker;
        $newLines[] = $cmd;
    }
    $content = implode("\n", $newLines) . "\n";
    $tmp = tempnam(sys_get_temp_dir(), 'cron');
    if ($tmp === false) {
        return [false, 'Could not create temp file'];
    }
    file_put_contents($tmp, $content);
    $err = [];
    exec('crontab ' . escapeshellarg($tmp) . ' 2>&1', $err);
    @unlink($tmp);
    return [empty($err) || trim(implode(' ', $err)) === '', implode(' ', $err)];
}

$crontabSupported = isCrontabSupported();
$crontabMessage   = null;

$errors         = [];
$saved          = false;
$deleted        = false;
$runOutput      = null;
$runDry         = true;
$currentProfileId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profileId   = isset($_POST['profile_id']) && ctype_digit((string)$_POST['profile_id'])
        ? (int)$_POST['profile_id']
        : null;

    // Handle delete profile
    if (isset($_POST['delete_profile']) && $profileId > 0) {
        $existing = loadProfile($pdo, $profileId);
        if ($existing !== null) {
            if ($crontabSupported) {
                $scriptPath = __DIR__ . '/mastodon_cleaner-exec.php';
                $unifiedLog = __DIR__ . '/mastodon_cleaner.log';
                updateCrontabForProfile($profileId, '', $scriptPath, $unifiedLog);
            }
            deleteProfile($pdo, $profileId);
            $deleted = true;
            $profilesList = loadAllProfiles($pdo);
            $currentProfileId = !empty($profilesList) ? (int)$profilesList[0]['id'] : null;
        }
    } else {
    $name        = trim($_POST['name'] ?? '');
    $baseUrl     = trim($_POST['base_url'] ?? '');
    $accountId   = trim($_POST['account_id'] ?? '');
    $authMethod  = $_POST['auth_method'] ?? 'token';
    $accessToken = trim($_POST['access_token'] ?? '');
    $basicUser   = trim($_POST['basic_user'] ?? '');
    $basicPass   = trim($_POST['basic_pass'] ?? '');
    $ageDays     = (int)($_POST['age_days'] ?? 30);
    $ignoreTags  = trim($_POST['ignore_tags'] ?? '');
    $ignoreIds   = trim($_POST['ignore_ids'] ?? '');
    $ignorePin   = isset($_POST['ignore_pin']) ? 1 : 0;
    $fetchLimit  = (int)($_POST['fetch_limit'] ?? 40);
    $deleteLimit = (int)($_POST['delete_limit'] ?? 5);
    $pauseSecs   = (int)($_POST['pause_seconds'] ?? 120);
    $userAgent   = trim($_POST['user_agent'] ?? 'Mastodon Cleaner');
    $schedulePreset = trim($_POST['schedule_preset'] ?? '');
    $scheduleCron   = trim($_POST['schedule_cron'] ?? '');
    if ($schedulePreset === 'custom') {
        if ($scheduleCron !== '') {
            // use raw cron when provided
        } else {
            $scheduleCron = buildCronFromCustom(
                (int)($_POST['schedule_min'] ?? -1),
                (int)($_POST['schedule_hour'] ?? -1),
                (int)($_POST['schedule_day'] ?? -1),
                (int)($_POST['schedule_month'] ?? -1),
                (int)($_POST['schedule_dow'] ?? -1)
            );
            if ($scheduleCron === '* * * * *') {
                $scheduleCron = '';
            }
        }
    } elseif (isset($SCHEDULE_PRESETS[$schedulePreset]) && $SCHEDULE_PRESETS[$schedulePreset]['cron'] !== null) {
        $scheduleCron = $SCHEDULE_PRESETS[$schedulePreset]['cron'];
    }

    if ($name === '') {
        $errors[] = 'Profile name is required.';
    }
    if ($baseUrl === '') {
        $errors[] = 'Instance base URL is required.';
    }
    if ($accountId === '') {
        $errors[] = 'Account identifier is required.';
    }
    if (!in_array($authMethod, ['token', 'basic'], true)) {
        $errors[] = 'Auth method must be token or basic.';
    }
    if ($authMethod === 'token' && $accessToken === '') {
        $errors[] = 'Access token is required for token auth.';
    }
    if ($authMethod === 'basic' && ($basicUser === '' || $basicPass === '')) {
        $errors[] = 'Username and password are required for basic auth.';
    }

    $runMode = $_POST['run_mode'] ?? 'dry';
    $runDry  = $runMode !== 'live';
    $runNow  = isset($_POST['run_cleaner']);

    if (empty($errors)) {
        $payload = [
            'id'           => $profileId,
            'name'         => $name,
            'base_url'     => $baseUrl,
            'account_id'   => $accountId,
            'auth_method'  => $authMethod,
            'access_token' => $accessToken !== '' ? $accessToken : null,
            'basic_user'   => $basicUser !== '' ? $basicUser : null,
            'basic_pass'   => $basicPass !== '' ? $basicPass : null,
            'age_days'     => $ageDays > 0 ? $ageDays : 1,
            'ignore_tags'  => $ignoreTags,
            'ignore_ids'   => $ignoreIds,
            'ignore_pin'   => $ignorePin,
            'fetch_limit'  => $fetchLimit > 0 ? $fetchLimit : 40,
            'delete_limit' => $deleteLimit > 0 ? $deleteLimit : 5,
            'pause_seconds'=> $pauseSecs >= 0 ? $pauseSecs : 0,
            'user_agent'   => $userAgent !== '' ? $userAgent : 'Mastodon Cleaner',
            'schedule_cron'=> $scheduleCron !== '' ? $scheduleCron : null,
        ];

        $savedId          = saveProfile($pdo, $payload);
        $saved            = true;
        $currentProfileId = $savedId;

        if ($crontabSupported && $savedId > 0) {
            $scriptPath = __DIR__ . '/mastodon_cleaner-exec.php';
            $unifiedLog = __DIR__ . '/mastodon_cleaner.log';
            list($ok, $err) = updateCrontabForProfile($savedId, $scheduleCron, $scriptPath, $unifiedLog);
            $crontabMessage = $ok ? 'Crontab updated for this profile.' : 'Crontab update failed: ' . $err;
        }

        if ($runNow) {
            $phpBin = trim((string)@shell_exec('which php 2>/dev/null')) ?: (defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php');
            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/mastodon_cleaner-exec.php')
                 . ' --profile-id=' . (int)$savedId;
            if ($runDry) {
                $cmd .= ' --dry-run';
            }
            $cmd .= ' 2>&1';
            $runOutput = shell_exec($cmd);
        }
    }
    }
} else {
    if (isset($_GET['profile']) && ctype_digit($_GET['profile'])) {
        $currentProfileId = (int)$_GET['profile'];
    } elseif (isset($_GET['new'])) {
        $currentProfileId = null;
    }
}

$profilesList = loadAllProfiles($pdo);

if ($currentProfileId !== null) {
    $profile = loadProfile($pdo, $currentProfileId);
} elseif (isset($_GET['new'])) {
    // Explicit new profile: use empty defaults
    $profile = null;
} else {
    $profile = loadProfile($pdo);
}

$runStatus = ($runOutput !== null) ? 'complete' : 'idle';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mastodon / Pleroma Cleaner</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #0f172a;
            --bg-alt: #020617;
            --fg: #e5e7eb;
            --accent: #38bdf8;
            --danger: #f97373;
            --border: #1f2937;
            --muted: #9ca3af;
            --input-bg: #020617;
        }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: radial-gradient(circle at top, #1e293b 0, #020617 55%, #000 100%);
            color: var(--fg);
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 3rem 1rem;
        }
        .card {
            width: 100%;
            max-width: 960px;
            background: rgba(15, 23, 42, 0.95);
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            box-shadow:
                0 40px 80px rgba(15, 23, 42, 0.7),
                0 0 0 1px rgba(15, 23, 42, 0.9);
            padding: 2rem 2.5rem 2.25rem;
            backdrop-filter: blur(18px);
        }
        h1 {
            font-size: 1.5rem;
            margin: 0 0 0.25rem;
            letter-spacing: 0.03em;
        }
        .subtitle {
            font-size: 0.9rem;
            color: var(--muted);
            margin-bottom: 1.75rem;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem 1.5rem;
        }
        .field {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            font-size: 0.9rem;
        }
        label {
            color: var(--muted);
        }
        input[type="text"],
        input[type="number"],
        input[type="password"],
        select,
        textarea {
            border-radius: 0.75rem;
            border: 1px solid var(--border);
            padding: 0.55rem 0.7rem;
            background: var(--input-bg);
            color: var(--fg);
            font: inherit;
        }
        textarea {
            resize: vertical;
            min-height: 3.2rem;
        }
        input:focus,
        select:focus,
        textarea:focus {
            outline: 2px solid rgba(56, 189, 248, 0.8);
            outline-offset: 0;
            border-color: rgba(56, 189, 248, 0.7);
        }
        .inline {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            margin-top: 0.2rem;
            font-size: 0.85rem;
            color: var(--muted);
        }
        .section-title {
            margin-top: 1.75rem;
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
        }
        .footer {
            margin-top: 1.75rem;
            font-size: 0.8rem;
            color: var(--muted);
        }
        .footer-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .footer-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .footer-run-mode {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-right: 0.25rem;
        }
        .footer-primary-buttons {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .footer-status {
            font-size: 0.85rem;
            color: var(--muted);
            min-width: 4rem;
        }
        .footer-delete-row {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
        }
        .btn-danger {
            border-radius: 999px;
            padding: 0.5rem 1rem;
            border: 1px solid rgba(248,113,113,0.4);
            background: transparent;
            color: var(--danger);
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .btn-danger:hover {
            background: rgba(249,115,115,0.12);
        }
        .btn-primary {
            border-radius: 999px;
            padding: 0.6rem 1.4rem;
            border: none;
            background: linear-gradient(135deg, #38bdf8, #22c55e);
            color: #0b1120;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            box-shadow: 0 18px 40px rgba(56, 189, 248, 0.4);
        }
        .btn-primary:hover {
            filter: brightness(1.05);
        }
        .badge {
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.4);
            padding: 0.15rem 0.6rem;
            font-size: 0.75rem;
            color: var(--muted);
        }
        .status {
            margin-bottom: 1rem;
            font-size: 0.85rem;
        }
        .status.error {
            color: var(--danger);
        }
        .status.ok {
            color: #4ade80;
        }
        @media (max-width: 640px) {
            .card {
                padding: 1.5rem 1.25rem 1.75rem;
            }
        }
    </style>
</head>
<body>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
        <div>
            <h1>Mastodon / Pleroma Cleaner</h1>
            <div class="subtitle">Configure your instances and retention rules. Profiles are stored locally in SQLite.</div>
        </div>
        <span class="badge"><?= count($profilesList) ?> profile<?= count($profilesList) === 1 ? '' : 's' ?></span>
    </div>

    <?php if (!empty($profilesList)): ?>
        <div class="profile-tabs" style="display:flex;flex-wrap:wrap;gap:0.4rem;margin-bottom:1rem;">
            <?php foreach ($profilesList as $p): ?>
                <?php
                $isActive = isset($profile['id']) && (int)$profile['id'] === (int)$p['id'];
                ?>
                <a href="?profile=<?= (int)$p['id'] ?>"
                   class="tab<?= $isActive ? ' active' : '' ?>"
                   style="text-decoration:none;padding:0.25rem 0.6rem;border-radius:999px;border:1px solid rgba(148,163,184,0.5);font-size:0.8rem;color:<?= $isActive ? '#e5e7eb' : '#9ca3af' ?>;background:<?= $isActive ? 'rgba(56,189,248,0.18)' : 'transparent' ?>;">
                    <?= e($p['name']) ?>
                </a>
            <?php endforeach; ?>
            <a href="?new=1"
               class="tab"
               style="text-decoration:none;padding:0.25rem 0.6rem;border-radius:999px;border:1px dashed rgba(148,163,184,0.5);font-size:0.8rem;color:#9ca3af;">
                + New profile
            </a>
        </div>
    <?php else: ?>
        <div class="status">No profiles yet – create one below.</div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="status error">
            <?php foreach ($errors as $err): ?>
                <div><?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($deleted): ?>
        <div class="status ok">Profile deleted.</div>
    <?php elseif ($saved && !$runOutput): ?>
        <div class="status ok">Profile saved.</div>
    <?php elseif ($saved && $runOutput): ?>
        <div class="status ok">Profile saved. Cleaner executed <?= $runDry ? '(dry run).' : '(live).' ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
        <input type="hidden" name="profile_id" value="<?= isset($profile['id']) ? (int)$profile['id'] : '' ?>">

        <div class="grid">
            <div class="field">
                <label for="name">Profile name</label>
                <input type="text" id="name" name="name" required
                       value="<?= e($profile['name'] ?? 'Default profile') ?>">
            </div>
            <div class="field">
                <label for="base_url">Instance base URL</label>
                <input type="text" id="base_url" name="base_url" required
                       placeholder="https://social.lol"
                       value="<?= e($profile['base_url'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="account_id">Account (handle or acct)</label>
                <input type="text" id="account_id" name="account_id" required
                       placeholder="jonw or jonw@social.lol"
                       value="<?= e($profile['account_id'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="auth_method">Auth method</label>
                <select id="auth_method" name="auth_method">
                    <?php
                    $auth = $profile['auth_method'] ?? 'token';
                    ?>
                    <option value="token" <?= $auth === 'token' ? 'selected' : '' ?>>Mastodon token</option>
                    <option value="basic" <?= $auth === 'basic' ? 'selected' : '' ?>>HTTP basic (Pleroma)</option>
                </select>
            </div>
            <div class="field">
                <label for="access_token">Access token (token auth)</label>
                <input type="password" id="access_token" name="access_token"
                       value="<?= e($profile['access_token'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="basic_user">Basic auth username (Pleroma)</label>
                <input type="text" id="basic_user" name="basic_user"
                       value="<?= e($profile['basic_user'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="basic_pass">Basic auth password (Pleroma)</label>
                <input type="password" id="basic_pass" name="basic_pass"
                       value="<?= e($profile['basic_pass'] ?? '') ?>">
            </div>
        </div>

        <div class="section-title">Retention & filtering</div>
        <div class="grid">
            <div class="field">
                <label for="age_days">Keep last N days</label>
                <input type="number" id="age_days" name="age_days" min="1"
                       value="<?= e((string)($profile['age_days'] ?? 30)) ?>">
                <div class="inline">Statuses older than this may be deleted.</div>
            </div>
            <div class="field">
                <label for="ignore_tags">Ignore tags</label>
                <input type="text" id="ignore_tags" name="ignore_tags"
                       placeholder="persist,important"
                       value="<?= e($profile['ignore_tags'] ?? '') ?>">
                <div class="inline">Comma-separated, no spaces.</div>
            </div>
            <div class="field">
                <label for="ignore_ids">Ignore specific status IDs</label>
                <textarea id="ignore_ids" name="ignore_ids"
                          placeholder="116148989615284553,116148989615284554"><?= e($profile['ignore_ids'] ?? '') ?></textarea>
            </div>
            <div class="field">
                <label>Pinned posts</label>
                <div class="inline">
                    <input type="checkbox" id="ignore_pin" name="ignore_pin"
                        <?= !empty($profile['ignore_pin']) ? 'checked' : '' ?>>
                    <label for="ignore_pin">Allow pinned posts to be deleted</label>
                </div>
            </div>
        </div>

        <div class="section-title">Throttling & client</div>
        <div class="grid">
            <div class="field">
                <label for="fetch_limit">Fetch page size</label>
                <input type="number" id="fetch_limit" name="fetch_limit" min="1"
                       value="<?= e((string)($profile['fetch_limit'] ?? 40)) ?>">
            </div>
            <div class="field">
                <label for="delete_limit">Deletes per batch</label>
                <input type="number" id="delete_limit" name="delete_limit" min="1"
                       value="<?= e((string)($profile['delete_limit'] ?? 5)) ?>">
            </div>
            <div class="field">
                <label for="pause_seconds">Pause between batches (seconds)</label>
                <input type="number" id="pause_seconds" name="pause_seconds" min="0"
                       value="<?= e((string)($profile['pause_seconds'] ?? 120)) ?>">
            </div>
            <div class="field">
                <label for="user_agent">User agent</label>
                <input type="text" id="user_agent" name="user_agent"
                       value="<?= e($profile['user_agent'] ?? 'Mastodon Cleaner') ?>">
            </div>
        </div>

        <?php
        $currentCron = isset($profile['schedule_cron']) ? trim((string)$profile['schedule_cron']) : '';
        $currentPreset = presetKeyForCron($currentCron, $SCHEDULE_PRESETS);
        $customParts = $currentPreset === 'custom' && $currentCron !== '' ? array_map('intval', array_pad(explode(' ', $currentCron, 5), 5, -1)) : [-1, -1, -1, -1, -1];
        ?>
        <div class="section-title">Automation – when to run the cleaner</div>
        <?php if (!$crontabSupported): ?>
            <div class="status error" style="margin-bottom:1rem;">
                <strong>Cron is not available on this system.</strong> This app cannot add or edit scheduled jobs here.
                You will need to run the cleaner manually (use “Save & run” below) or set up a scheduler on a Unix/Linux server that has <code>crontab</code>.
            </div>
        <?php else: ?>
            <div class="status ok" style="margin-bottom:1rem;">
                Scheduled runs are supported. Choose a schedule below and save the profile; the app will add or update the entry in your crontab.
            </div>
        <?php endif; ?>
        <div class="grid">
            <div class="field" style="grid-column:1/-1;">
                <label style="display:block;margin-bottom:0.5rem;">How often should this profile run?</label>
                <?php foreach ($SCHEDULE_PRESETS as $key => $info): ?>
                    <label class="inline" style="display:block;margin:0.25rem 0;">
                        <input type="radio" name="schedule_preset" value="<?= e($key) ?>" <?= $currentPreset === $key ? 'checked' : '' ?>
                               data-preset="<?= e($key) ?>" data-cron="<?= e(isset($info['cron']) && $info['cron'] !== null ? $info['cron'] : '') ?>">
                        <span><?= e($info['label']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div id="custom_schedule" class="field" style="grid-column:1/-1; <?= $currentPreset !== 'custom' ? 'display:none;' : '' ?>">
                <label style="display:block;margin-bottom:0.35rem;">Custom schedule (cron: minute, hour, day of month, month, day of week)</label>
                <div class="inline" style="margin-bottom:0.5rem;color:var(--muted);font-size:0.85rem;">Use numbers or leave blank for “every” (*). Day of week: 0–7 (0 and 7 = Sunday).</div>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem 1rem;">
                    <div>
                        <label for="schedule_min" style="font-size:0.8rem;">Minute (0–59)</label>
                        <input type="number" id="schedule_min" name="schedule_min" min="0" max="59" placeholder="*" value="<?= $customParts[0] >= 0 ? $customParts[0] : '' ?>">
                    </div>
                    <div>
                        <label for="schedule_hour" style="font-size:0.8rem;">Hour (0–23)</label>
                        <input type="number" id="schedule_hour" name="schedule_hour" min="0" max="23" placeholder="*" value="<?= $customParts[1] >= 0 ? $customParts[1] : '' ?>">
                    </div>
                    <div>
                        <label for="schedule_day" style="font-size:0.8rem;">Day of month (1–31)</label>
                        <input type="number" id="schedule_day" name="schedule_day" min="1" max="31" placeholder="*" value="<?= $customParts[2] >= 0 ? $customParts[2] : '' ?>">
                    </div>
                    <div>
                        <label for="schedule_month" style="font-size:0.8rem;">Month (1–12)</label>
                        <input type="number" id="schedule_month" name="schedule_month" min="1" max="12" placeholder="*" value="<?= $customParts[3] >= 0 ? $customParts[3] : '' ?>">
                    </div>
                    <div>
                        <label for="schedule_dow" style="font-size:0.8rem;">Day of week (0–7, Sun=0)</label>
                        <input type="number" id="schedule_dow" name="schedule_dow" min="0" max="7" placeholder="*" value="<?= $customParts[4] >= 0 ? $customParts[4] : '' ?>">
                    </div>
                </div>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label for="schedule_cron">Or enter a raw cron expression (overrides preset when non-empty)</label>
                <input type="text" id="schedule_cron" name="schedule_cron" placeholder="e.g. 0 1 * * *"
                       value="<?= $currentPreset === 'custom' ? e($currentCron) : '' ?>">
            </div>
        </div>
        <?php if ($crontabMessage !== null): ?>
            <div class="status <?= strpos($crontabMessage, 'failed') !== false ? 'error' : 'ok' ?>" style="margin-top:0.5rem;"><?= e($crontabMessage) ?></div>
        <?php endif; ?>
        <script>
        (function(){
            var presetRadios = document.querySelectorAll('input[name="schedule_preset"]');
            var customBlock = document.getElementById('custom_schedule');
            var rawCronInput = document.getElementById('schedule_cron');
            function toggle(){
                var custom = document.querySelector('input[name="schedule_preset"][value="custom"]');
                customBlock.style.display = custom && custom.checked ? '' : 'none';
            }
            function syncRawCron(){
                var checked = document.querySelector('input[name="schedule_preset"]:checked');
                if (checked && rawCronInput) {
                    var cron = checked.getAttribute('data-cron') || '';
                    rawCronInput.value = cron;
                }
            }
            presetRadios.forEach(function(r){
                r.addEventListener('change', function(){
                    toggle();
                    syncRawCron();
                });
            });
            toggle();
            syncRawCron();
        })();
        </script>

        <div class="footer">
            <div class="footer-row">
                <div>Settings are stored in a local SQLite file and used by the cleaner script.</div>
                <div class="footer-actions">
                    <div class="footer-primary-buttons">
                        <button type="submit" name="save_only" value="1" class="btn-primary">
                            <span>Save profile</span>
                        </button>
                        <div class="footer-run-mode">
                            <label class="inline" style="margin:0;">
                                <input type="radio" name="run_mode" value="dry" id="run_dry" <?= $runDry ? 'checked' : '' ?>>
                                <span>Dry run</span>
                            </label>
                            <label class="inline" style="margin:0;">
                                <input type="radio" name="run_mode" value="live" id="run_live" <?= !$runDry ? 'checked' : '' ?>>
                                <span>Live delete</span>
                            </label>
                        </div>
                        <button type="submit" name="run_cleaner" value="1" class="btn-primary" style="background:linear-gradient(135deg,#f97373,#fb923c);box-shadow:0 18px 40px rgba(248,113,113,0.4);">
                            <span>Save &amp; run</span>
                        </button>
                        <span id="exec_status" class="footer-status" data-state="<?= e($runStatus) ?>"><?= e($runStatus) ?></span>
                    </div>
                </div>
            </div>
            <?php if (isset($profile['id'])): ?>
            <div class="footer-delete-row">
                <button type="submit" name="delete_profile" value="1" class="btn-danger" onclick="return confirm('Permanently delete this profile?');">
                    Delete profile
                </button>
            </div>
            <?php endif; ?>
        </div>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form[method="post"]');
        var statusEl = document.getElementById('exec_status');
        if (!form || !statusEl) return;

        function setStatus(state) {
            statusEl.textContent = state;
            statusEl.setAttribute('data-state', state);
        }

        // Ensure we always start with server-provided state (idle/complete)
        setStatus(statusEl.getAttribute('data-state') || 'idle');

        // Switch to "running" as soon as the user initiates a submit.
        // Use pointerdown so the text updates before navigation begins.
        var submitButtons = form.querySelectorAll('button[type="submit"]');
        submitButtons.forEach(function (btn) {
            btn.addEventListener('pointerdown', function () {
                setStatus('running');
            });
            btn.addEventListener('click', function () {
                setStatus('running');
            });
        });
        form.addEventListener('submit', function () {
            setStatus('running');
        });

        // After a completed run, changing any input resets status back to idle
        // so the next run has a clean state.
        form.addEventListener('input', function () {
            if (statusEl.getAttribute('data-state') === 'complete') {
                setStatus('idle');
            }
        });
    });
    </script>

    <?php if ($runOutput !== null): ?>
        <div id="last-run-output" style="margin-top:1.75rem;">
            <div class="section-title">Last run output (scrollable)</div>
            <pre style="background:rgba(15,23,42,0.9);border-radius:0.75rem;border:1px solid var(--border);padding:0.75rem 0.9rem;font-size:0.8rem;max-height:320px;overflow:auto;white-space:pre-wrap;"><?= e($runOutput) ?></pre>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var el = document.getElementById('last-run-output');
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
        </script>
    <?php endif; ?>
</div>
</body>
</html>

