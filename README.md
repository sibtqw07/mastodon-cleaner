Mastodon / Pleroma Cleaner
==========================

A small PHP tool to help you automatically prune old posts from a Mastodon / Pleroma account, with:

- A web UI for configuring profiles stored in a local SQLite database.
- A runner script that can be invoked from the UI or directly from the command line.
- Optional cron integration so your cleaner runs on a schedule.


Overview
--------

This app is intended to be run from a private system; it has no auth so can't be made public or shared between users.

Basic testing with this app has been done, but the author provides no warranty that this script is suitable for any purpose whatsoever, and informs you that that the author accepts no liability for the function of this scipt.

The app consists of:

- `index.php` — the main configuration UI. It stores profiles in `config.sqlite`, lets you run the cleaner immediately, and (on Unix-like systems) can manage crontab entries for each profile.
- `mastodon_cleaner-exec.php` — the worker script that actually talks to your Mastodon / Pleroma instance and performs the clean-up for a given profile.
- `config.sqlite` — a local SQLite database containing all profiles and their settings. It is created automatically next to `index.php`.
- `mastodon_cleaner.log` — single log file for all runs (UI and cron); the executor appends each run’s output with a timestamp and profile label.


Requirements
------------

- PHP 8.0 or newer, with:
  - SQLite (`pdo_sqlite`) enabled.
  - Permission to run shell commands (for `crontab` integration and the worker script).
- A Mastodon or Pleroma account with:
  - A valid access token (for token-based auth), **or**
  - Basic-auth credentials (for some Pleroma deployments).
- For scheduled runs:
  - A Unix-like environment (Linux, macOS, BSD, etc.) with `crontab` installed and usable by the user running PHP.

You can still use the UI and run the cleaner manually even if `crontab` is not available (for example on Windows).


Installation
------------

1. **Fetch the project**

   Clone or download the repository into a directory on your machine or server, for example:

   ```bash
   git clone https://github.com/mastodon-cleaner.git
   cd mastodon-cleaner
   ```


2. **Serve `index.php`**

   You can either:

   - Use PHP’s built-in server while testing:

     ```bash
     php -S 127.0.0.1:8080
     ```

     Then open `http://127.0.0.1:8080/index.php` in your browser.

   - Or configure Apache / Nginx / Caddy to serve this directory with PHP-FPM or mod_php, and browse to the URL where you have deployed it.

3. **First run**

   On first load, `index.php` will:

   - Create `config.sqlite` if it does not exist.
   - Create the `profiles` table (and add any missing columns over time).
   - Show an empty state inviting you to create your first profile.


Profiles and configuration
--------------------------

Each profile represents one account on one instance, with its own retention and throttling rules.
Profiles are stored in the `profiles` table inside `config.sqlite`.

In the UI (`index.php`), you can:

- Switch between existing profiles via the tabs at the top.
- Create a new profile with the **“+ New profile”** tab.

### Basic connection settings

In the first grid of fields:

- **Profile name**: A human-readable label for this set of rules (e.g. “Personal account on social.lol”).
- **Instance base URL**: The full base URL of your instance, e.g. `https://social.lol`.
- **Account (handle or acct)**: Your local handle or full `acct`, e.g. `ilovecats` or `ilovecats@social.lol`.
- **Auth method**:
  - **Mastodon token** — uses a personal access token from Mastodon.
  - **HTTP basic (Pleroma)** — uses HTTP basic authentication; for some Pleroma setups this may be preferable.
- **Access token (token auth)**: Your Mastodon API token when using token auth.
- **Basic auth username / password (Pleroma)**: Credentials for HTTP basic auth, when using that mode.

> Security tip: Only host this UI somewhere you control and trust. The credentials are stored in `config.sqlite` on disk.

### Retention & filtering

These fields control what is eligible for deletion:

- **Keep last N days**: Any status newer than this many days is preserved. Older statuses are candidates for deletion, subject to filters below.
- **Ignore tags**: Comma-separated list of tags (without the `#`) that should prevent a post from being deleted. For example: `persist,important`.
- **Ignore specific status IDs**: A list of status IDs that should never be deleted. You can paste them as comma-separated values or one per line.
- **Pinned posts**:
  - When the checkbox is **checked**, pinned posts are *allowed* to be deleted.
  - When **unchecked**, pinned posts are preserved even if they are older than your retention period.

### Throttling & client

These fields tune how aggressively the cleaner talks to the server:

- **Fetch page size**: How many statuses to request per API page.
- **Deletes per batch**: How many deletions to perform before pausing.
- **Pause between batches (seconds)**: How long to sleep between batches to avoid hammering the instance.
- **User agent**: The HTTP `User-Agent` header sent with requests. Defaults to `Mastodon Cleaner`.


Running the cleaner from the UI
-------------------------------

At the bottom of the form is a footer with run controls.

### Dry run vs Live delete

Two radio buttons let you choose how to execute:

- **Dry run**:
  - The cleaner simulates which posts *would* be deleted.
  - No deletions are actually sent to the server.
  - Use this the first few times to confirm your filters and retention window behave as expected.

- **Live delete**:
  - The cleaner performs real deletions according to your settings.
  - Make sure you are comfortable with your configuration before using this mode.

### Save-only vs Save & run

There are two primary actions:

- **Save profile**:
  - Saves the configuration to `config.sqlite`.
  - Uses your chosen cron settings (if any) to update crontab.
  - **Does not run** the cleaner immediately.

- **Save & run**:
  - Saves the profile as above.
  - Immediately invokes `mastodon_cleaner-exec.php` for the current profile.
  - Uses the selected run mode (**Dry run** or **Live delete**) when calling the worker script.

### Running status indicator and output

When you click **“Save & run”**:

- A small overlay appears on top of the card showing:
  - A spinner.
  - A “Running cleaner…” message.
  - A reminder to keep the tab open.
- The footer shows a **status line** indicating whether the cleaner is:
  - Idle.
  - Running in dry-run or live-delete mode.
  - Finished (and whether it was a dry run or live delete).
- The buttons are temporarily disabled to avoid double-submissions.

After the run completes and the page reloads:

- The **Last run output** section appears, showing the captured output from `mastodon_cleaner-exec.php`.
- The footer status text will say:
  - `Last run: completed as a dry run.` or
  - `Last run: completed and deleted statuses (live).`

This gives you confidence that the cleaner is actually running and finishing as expected.


Running the cleaner from the command line
-----------------------------------------

You can invoke the worker script directly if you prefer:

```bash
php mastodon_cleaner-exec.php --profile-id=1 --dry-run
```

Options:

- `--profile-id=<id>`: Required. The numeric profile ID from the `profiles` table.
- `--dry-run`: Optional. When present, runs in dry-run mode; otherwise runs live.

This is useful for:

- Testing from a cron job without using the web UI.
- Running the cleaner on a schedule via a system-level scheduler other than `crontab` (for example, systemd timers).


Scheduling with cron
--------------------

If the environment supports `crontab`, `index.php` can manage per-profile cron entries for you.
On page load the app tests:

- Whether `crontab` is installed and callable.
- Whether `crontab -l` works for the current user.

If cron is **not** available, the UI will show a red warning and skip all crontab changes. You can still use **Save & run** to trigger manual runs.

If cron **is** available, an informational message appears, and the **Automation – when to run the cleaner** section becomes active.

### Presets and raw cron expression

The automation section offers a set of presets, for example:

- **Daily at midnight (00:00)** → `0 0 * * *`
- **Daily at 3:00 AM** → `0 3 * * *`
- **Daily at 6:00 AM** → `0 6 * * *`
- **Every 6 hours** → `0 */6 * * *`
- **Weekly on Sunday at 1:00 AM** → `0 1 * * 0`
- **Weekly on Monday at 1:00 AM** → `0 1 * * 1`
- **Custom (set minute, hour, day, etc.)`** → lets you define your own schedule.

There is also a **“Do not schedule (run manually only)”** option which clears scheduling for that profile.

#### Live sync between presets and the custom cron field

To give you confidence about the schedule:

- When you select any **non-custom** preset in the UI, the **raw cron expression text field is immediately filled** with the exact cron string for that preset.
- For example:
  - Clicking *Daily at midnight* updates the text box to `0 0 * * *`.
  - Clicking *Every 6 hours* updates it to `0 */6 * * *`.
- If you switch back to **Custom**, the app will not overwrite whatever you type manually in the cron text field.

On the server side:

- If the raw cron field is non-empty, it is used as the schedule.
- Otherwise, the selected preset’s cron value (if any) is used.
- If the final cron expression is empty, no cron job is installed for that profile.

#### Custom cron builder

When you choose **Custom**, an additional block appears with numeric fields:

- Minute (0–59)
- Hour (0–23)
- Day of month (1–31)
- Month (1–12)
- Day of week (0–7, with Sunday = 0 or 7)

You can:

- Leave a field blank to mean “every” (`*`).
- Fill in specific numbers to restrict the schedule.

The app combines these into a 5-part cron expression like:

```text
0 3 * * 0   # Every Sunday at 03:00
*/15 * * * *  # Every 15 minutes (if you set the minute field accordingly)
```

If you also type a value into the raw cron field, that raw value takes precedence over the numeric builder.

#### How crontab is updated

When you click **Save profile** or **Save & run** and a schedule is defined:

- The app builds a small two-line snippet in your user crontab:

  ```text
  # mastodon_cleaner profile <id>
  <cron expression> /path/to/php /path/to/mastodon_cleaner-exec.php --profile-id=<id> >> /path/to/mastodon_cleaner.log 2>&1
  ```

- It removes any existing block for that profile and inserts the new one.
- Output and errors from the worker script are appended to `mastodon_cleaner.log` in the same directory. The app uses the full path to `php` when installing crontab entries so cron can find it; each log entry is prefixed with a timestamp and the profile id/name.

If a profile’s schedule is cleared:

- The existing cron block for that profile (if any) is removed from your crontab.


Troubleshooting
---------------

### Cron not available

Symptoms:

- The automation section shows a red message about cron not being available.
- Saving a profile does not create any new cron entries.

Causes:

- You are running on Windows.
- `crontab` is not installed, or the user running PHP does not have permission to use it.

What to do:

- Run the cleaner manually using **Save & run** whenever you want to prune posts, **or**
- Deploy the app on a Unix-like system with `crontab`, or configure another scheduler (like systemd timers) to call `mastodon_cleaner-exec.php` directly.

### Authentication failures

Symptoms:

- The last run output shows HTTP 401/403 errors or messages about invalid credentials.

What to check:

- That your access token has the required scopes for deleting statuses.
- That your instance URL and account ID are correct.
- If using basic auth:
  - Verify username and password.
  - Confirm your Pleroma deployment actually uses basic auth for the API.

### Permission issues with `config.sqlite`

Symptoms:

- PHP warnings about being unable to open or write the SQLite database.
- Profiles do not save or changes are not persisted.

What to do:

- Ensure the user running PHP has **read/write** permission on `config.sqlite` and on the directory containing it.
- If necessary, adjust permissions or ownership:

  ```bash
  chown www-data:www-data config.sqlite
  chmod 600 config.sqlite
  ```

  (Adjust user / group according to your web server.)

### PHP errors or blank page

Symptoms:

- Blank page on `index.php` or cryptic error messages.

What to do:

- Enable error logging for PHP and check the web server logs.
- Run the worker script directly in a terminal:

  ```bash
  php mastodon_cleaner-exec.php --profile-id=1 --dry-run
  ```

  to see any immediate errors.


Security notes
--------------

- **Keep credentials safe**:
  - Access tokens and basic auth passwords are stored in `config.sqlite`.
  - Ensure this file is not world-readable and is backed up only to locations you trust.
- **Restrict access to the UI**:
  - Host the UI on a private machine or behind authentication if running on a shared server.
  - Avoid exposing `index.php` to the public internet without additional protections.
- **Test with dry runs first**:
  - Always confirm your filters and retention period with dry runs before enabling live deletes, especially when first configuring a new profile.


License
-------

GPL v 3.0

