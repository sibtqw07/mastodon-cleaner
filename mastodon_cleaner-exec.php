<?php
#####################################
#
# Mastodon / Pleroma cleaner.
# Delete posts older than X days using
# the profile stored in config.sqlite,
# created via the web UI (index.php).
#
# usage: php ./mastodon_cleaner-exec.php [--dry-run]
#
#####################################

declare(strict_types=1);

$dryRun      = false;
$profileId   = null;
$profileName = null;

// Parse CLI arguments: --dry-run, --profile-id=ID, --profile=NAME
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (strpos($arg, '--profile-id=') === 0) {
        $value = substr($arg, strlen('--profile-id='));
        if (ctype_digit($value)) {
            $profileId = (int)$value;
        }
    } elseif (strpos($arg, '--profile=') === 0) {
        $profileName = substr($arg, strlen('--profile='));
    }
}

if ($dryRun) {
    print "Doing dry run (will not actually delete anything).\n";
}

// Load profile from SQLite created by index.php
$dbPath = __DIR__ . '/config.sqlite';
if (!file_exists($dbPath)) {
    fwrite(STDERR, "No config.sqlite found. Visit the web UI (index.php) to create a profile first.\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($profileId !== null) {
        $stmt = $pdo->prepare('SELECT * FROM profiles WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $profileId]);
    } elseif ($profileName !== null) {
        $stmt = $pdo->prepare('SELECT * FROM profiles WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $profileName]);
    } else {
        $stmt = $pdo->query('SELECT * FROM profiles ORDER BY id ASC LIMIT 1');
    }

    $config = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to read configuration from SQLite: " . $e->getMessage() . "\n");
    exit(1);
}

if ($config === false) {
    fwrite(STDERR, "No matching profile found in config.sqlite. Use the web UI to save one.\n");
    exit(1);
}

$logPath = __DIR__ . '/mastodon_cleaner.log';
$logLabel = 'profile ' . ($profileId ?? $config['name'] ?? '?');
ob_start();

register_shutdown_function(function () use ($logPath, $logLabel) {
    $out = @ob_get_clean();
    if ($out !== false && $out !== '' && $logPath !== '') {
        @file_put_contents($logPath, date('c') . ' [' . $logLabel . ']' . "\n" . $out . "\n", FILE_APPEND | LOCK_EX);
    }
});

#####################################
## You should not need to change anything below this line
#####################################

$baseUrl      = rtrim((string)$config['base_url'], '/');
$accountId    = (string)$config['account_id'];
$authMethod   = (string)$config['auth_method']; // 'token' or 'basic'
$accessToken  = $config['access_token'] !== null ? (string)$config['access_token'] : '';
$basicUser    = $config['basic_user'] !== null ? (string)$config['basic_user'] : null;
$basicPass    = $config['basic_pass'] !== null ? (string)$config['basic_pass'] : null;
$ageDays      = (int)$config['age_days'];
$ignoreTags   = (string)$config['ignore_tags'];
$ignoreIds    = (string)$config['ignore_ids'];
$ignorePin    = (bool)$config['ignore_pin'];
$fetchLimit   = (int)$config['fetch_limit'];
$deleteLimit  = (int)$config['delete_limit'];
$pauseSeconds = (int)$config['pause_seconds'];
$userAgent    = (string)$config['user_agent'];

// Prepare ignore lists
$ignoreTagArray = [];
if (strlen($ignoreTags) > 0) {
    $ignoreTagArray = explode(',', $ignoreTags);
    print "Ignoring tags: $ignoreTags\n";
}

$ignoreIdArray = [];
if (strlen($ignoreIds) > 0) {
    $ignoreIdArray = explode(',', $ignoreIds);
    print "Ignoring toots: $ignoreIds\n";
}

print "Run time: " . date('Y-M-d:H:i:s') . "\n";
print "Using profile: " . ($config['name'] ?? 'default') . "\n";
print "Instance: $baseUrl\n";

// If accountId is not numeric, try to resolve it via /api/v1/accounts/lookup
if (!ctype_digit((string)$accountId)) {
    $lookupUrl = $baseUrl . '/api/v1/accounts/lookup?acct=' . urlencode($accountId);
    $lookupCh  = initCurl($lookupUrl, $authMethod, $accessToken, $basicUser, $basicPass, $userAgent);
    $lookupOut = curl_exec($lookupCh);

    $lookupJson = json_decode($lookupOut, true);
    if (is_array($lookupJson) && isset($lookupJson['id'])) {
        print "Resolved account identifier '$accountId' to numeric id " . $lookupJson['id'] . "\n";
        $accountId = $lookupJson['id'];
    } else {
        print "Warning: could not resolve account identifier '$accountId' via /api/v1/accounts/lookup. Raw response:\n";
        print $lookupOut . "\n";
    }
}

/**
 * Initialize a curl handle with common options for this profile.
 */
function initCurl(string $url, string $authMethod, ?string $accessToken, ?string $basicUser, ?string $basicPass, string $userAgent)
{
    $ch = curl_init($url);
    $headers = [];

    if ($authMethod === 'token') {
        if (!empty($accessToken)) {
            $headers[] = "Authorization: Bearer $accessToken";
        }
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    if ($authMethod === 'basic' && $basicUser !== null && $basicPass !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, $basicUser . ':' . $basicPass);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 0);

    return $ch;
}

/**
 * Fetch all statuses for the account, honoring pagination.
 * Returns an array of status objects (decoded JSON arrays).
 */
function fetchAllStatuses(
    string $baseUrl,
    string $accountId,
    int $fetchLimit,
    string $authMethod,
    ?string $accessToken,
    ?string $basicUser,
    ?string $basicPass,
    string $userAgent
) {
    $statusesAll = [];
    $maxId = null;
    $numStatusesReported = null;

    while (true) {
        $url = $baseUrl . '/api/v1/accounts/' . $accountId . '/statuses?limit=' . $fetchLimit;
        if ($maxId !== null) {
            $url .= '&max_id=' . urlencode($maxId);
        }

        $ch = initCurl($url, $authMethod, $accessToken, $basicUser, $basicPass, $userAgent);
        $output = curl_exec($ch);

        if ($output === false) {
            break;
        }

        $statuses = json_decode($output, true);
        if (!is_array($statuses) || count($statuses) === 0) {
            break;
        }

        if ($numStatusesReported === null && isset($statuses[0]['account']['statuses_count'])) {
            $numStatusesReported = $statuses[0]['account']['statuses_count'];
            print "Statuses found (reported by server): $numStatusesReported\n";
        }

        foreach ($statuses as $status) {
            $statusesAll[] = $status;
        }

        // Prepare for next page – use oldest ID in this page as max_id.
        $last = end($statuses);
        if (!isset($last['id']) || $last['id'] === $maxId) {
            break;
        }
        $maxId = $last['id'];
    }

    print "Total statuses fetched: " . count($statusesAll) . "\n";
    return $statusesAll;
}

/**
 * Determine whether a status should be considered for deletion
 * based on ID, tags, and pin state. Age is not considered here.
 */
function shouldConsiderForDeletion(array $status, array $ignoreIdArray, array $ignoreTagArray, bool $ignorePin): bool
{
    $delete = true;

    // Ignore specific IDs
    if (!empty($ignoreIdArray) && isset($status['id'])) {
        foreach ($ignoreIdArray as $ignoreId) {
            if ($status['id'] == $ignoreId) {
                $delete = false;
                break;
            }
        }
    }

    // Ignore specific tags
    if ($delete && !empty($ignoreTagArray) && !empty($status['tags']) && is_array($status['tags'])) {
        foreach ($ignoreTagArray as $ignoreTag) {
            foreach ($status['tags'] as $statusTag) {
                if (isset($statusTag['name']) && $ignoreTag == $statusTag['name']) {
                    $delete = false;
                    break 2;
                }
            }
        }
    }

    // Respect pinned statuses if configured
    if ($delete && !$ignorePin) {
        if (isset($status['pinned']) && $status['pinned']) {
            $delete = false;
            print "Skipping *pinned* status " . $status['id'] . " on " . $status['created_at'] . ": \"" . $status['content'] . "\"\n\n\n";
        }
    }

    return $delete;
}

// Fetch and filter statuses into a candidate list (ignoring age for now)
$allStatuses = fetchAllStatuses(
    $baseUrl,
    $accountId,
    $fetchLimit,
    $authMethod,
    $accessToken,
    $basicUser,
    $basicPass,
    $userAgent
);

$debugIndex = 0;
if ($dryRun && count($allStatuses) > 0) {
    print "Debug: raw statuses fetched (showing up to first 5):\n";
    foreach ($allStatuses as $st) {
        $debugIndex++;
        if ($debugIndex > 5) {
            break;
        }
        $tags = [];
        if (!empty($st['tags']) && is_array($st['tags'])) {
            foreach ($st['tags'] as $t) {
                if (isset($t['name'])) {
                    $tags[] = $t['name'];
                }
            }
        }
        print "- ID: " . ($st['id'] ?? 'N/A') .
              " | Date: " . ($st['created_at'] ?? 'N/A') .
              " | Pinned: " . ((isset($st['pinned']) && $st['pinned']) ? 'yes' : 'no') .
              " | Tags: " . (empty($tags) ? 'none' : implode(',', $tags)) . "\n";
    }
    print "\n";
}

$statusCandidates = [];

foreach ($allStatuses as $status) {
    if (!isset($status['id'], $status['created_at'])) {
        continue;
    }

    if (shouldConsiderForDeletion($status, $ignoreIdArray, $ignoreTagArray, $ignorePin)) {
        $id = $status['id'];
        $statusCandidates[$id] = [
            'id'      => $id,
            'date'    => $status['created_at'],
            'content' => $status['content'] ?? '',
        ];
    }
}

if ($dryRun) {
    print "Debug: candidate statuses after tag/ID/pin filtering (before age check): " . count($statusCandidates) . "\n";
    $debugIndex = 0;
    foreach ($statusCandidates as $st) {
        $debugIndex++;
        if ($debugIndex > 10) {
            break;
        }
        print "- Candidate ID: " . $st['id'] . " | Date: " . $st['date'] . "\n";
    }
    print "\n";
}

print "\n\n\n";

// Date cut-off
$rangeSeconds = $ageDays * 86400;
$today        = time();
$minDate      = $today - $rangeSeconds;

// Set up DELETE curl handle
$chDelete = curl_init();

if ($authMethod === 'token' && !empty($accessToken)) {
    curl_setopt($chDelete, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken"]);
}
if ($authMethod === 'basic' && $basicUser !== null && $basicPass !== null) {
    curl_setopt($chDelete, CURLOPT_USERPWD, $basicUser . ':' . $basicPass);
}

curl_setopt($chDelete, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($chDelete, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($chDelete, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($chDelete, CURLOPT_USERAGENT, $userAgent);
curl_setopt($chDelete, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($chDelete, CURLOPT_MAXREDIRS, 0);

// Delete (or report, in dry-run) all candidates older than the cutoff
$deletedCount = 0;
$inScopeCount = 0;
$totalCandidates = count($statusCandidates);

foreach ($statusCandidates as $status) {
    $statusTime = strtotime($status['date']);
    if ($statusTime === false) {
        continue;
    }

    if ($statusTime < $minDate) {
        $inScopeCount++;
        if ($dryRun) {
            print "Dry run: Status " . $status['id'] . " on " . $status['date'] . ": \"" . $status['content'] . "\" is in scope, but not actually being deleted due to dry run.\n\n\n";
        } else {
            print "Deleting " . $status['id'] . " on " . $status['date'] . ": \"" . $status['content'] . "\"\n";
            $deleteUrl = $baseUrl . '/api/v1/statuses/' . $status['id'];
            curl_setopt($chDelete, CURLOPT_URL, $deleteUrl);
            $resp = curl_exec($chDelete);
            $httpCode = curl_getinfo($chDelete, CURLINFO_HTTP_CODE);
            print "Delete response HTTP " . $httpCode . "\n";
            if ($resp !== false && strlen(trim($resp)) > 0) {
                print "Delete response body: " . $resp . "\n";
            }
            if ($httpCode >= 200 && $httpCode < 300) {
                $deletedCount++;
            } else {
                print "Warning: delete of status " . $status['id'] . " may have failed.\n\n";
            }
            print "\n";
        }

        // Pause periodically to avoid throttling
        if (!$dryRun && $deleteLimit > 0 && ($deletedCount % $deleteLimit) === 0) {
            print "Pausing between batches of deletes...$deletedCount of $totalCandidates\n\n\n";
            if ($pauseSeconds > 0) {
                sleep($pauseSeconds);
            }
        }
    }
}


if ($dryRun) {
    print "Done. Found $inScopeCount statuses in scope; would delete them in live mode.\n";
} else {
    print "Done. Deleted $deletedCount statuses.\n";
}

$out = ob_get_clean();
if ($out !== false) {
    echo $out;
    if ($logPath !== '') {
        file_put_contents($logPath, date('c') . ' [' . $logLabel . ']' . "\n" . $out . "\n", FILE_APPEND | LOCK_EX);
    }
}

