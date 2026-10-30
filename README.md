# Boomy Appointments 

### What is this?
A super lightweight appointment booking system built with plain PHP, a simple API layer, and a modular JavaScript frontend styled with Pico.css.

---

### What's in the repo?
- **`functions.php`**: All the core PHP backend helpers (database connections, sessions, CSRF checks, user stuff).
- **`auth.php`**: The API endpoints for handling status checks, admin registration, logging in, and logging out.
- **`login.php`**: The login page with a progressive-enhanced form that talks to the API.
- **`admin.php`**: A protected admin dashboard page.
- **`assets/js/global.js`**: Modular JS utility functions for `fetch()` API calls and auto-handling forms marked with `data-api`.
- **`install.sql`**: The SQL schema and starting seed data.
- **`.github/workflows/deploy.yml`**: The automated deployment script that pushes code straight to the production server.

---

### Auth API Endpoints (`auth.php`)

- **`GET ?action=status`**  
  Returns JSON indicating if setup is needed: `{"setup_required": true}`.

- **`POST ?action=register_first_admin`**  
  Creates the very first admin account (only works if the users table is completely empty). Requires a JSON body with `email`, `password`, `full_name`, and `csrf_token`.

- **`POST ?action=login`**  
  Logs an existing user in using `email`, `password`, and `csrf_token`.

- **`POST ?action=logout`**  
  Clears the session.

---

### Client JS Utilities (`assets/js/global.js`)
- `apiPost(url, data)`: Sends a JSON POST request with credentials included.
- `formToObject(form)`: Turns any HTML form into a clean JavaScript object.
- `attachApiForms(root)`: Automatically hooks into any `<form data-api>` on the page so they submit via JSON `fetch()` without needing manual event listeners everywhere.

---

### First-Run Setup Flow
1. On a fresh database with zero users, hitting `GET /auth.php?action=status` returns `{"setup_required": true}`.
2. Load `login.php` in your browser to grab a session cookie and the hidden CSRF token.
3. POST your admin details + the token to `/auth.php?action=register_first_admin`. Once it succeeds, you're logged in and sent straight to `admin.php`.

---

### How to Deploy Automatically (GitHub Actions)

This repo includes a GitHub Actions workflow (`.github/workflows/deploy.yml`) that automatically syncs your files over to the production VM using `rsync` over SSH every time you push to `main` (or whenever you trigger it manually).

#### 1. Add these 5 Repository Secrets
Go to your GitHub repo **Settings → Secrets and variables → Actions**, click **New repository secret**, and add these:

| Secret Name | What to put in it |
| :--- | :--- |
| **`DEPLOY_SSH_KEY`** | The full contents of your private deploy key (including the `-----BEGIN...-----` lines). |
| **`DEPLOY_KNOWN_HOSTS`** | The server's host key line (from running `ssh-keyscan -t ed25519 YOUR_VM_IP`). |
| **`DEPLOY_HOST`** | Your server's public IP address or domain name. |
| **`DEPLOY_USER`** | `ubuntu` |
| **`DEPLOY_PATH`** | `/home/ubuntu/main-site/www/appointments` |

#### 2. Running a Deployment
- **Automatic:** Every time you push code to the `main` branch, it deploys automatically.
- **Manual:** Go to the **Actions** tab on GitHub, select **Deploy to Oracle VM**, click **Run workflow**, pick which branch you want to push, and toggle **Dry run** if you just want to test it safely without changing any files on the server first!

*Note: Server-only files like `config.php`, `adminer.php`, and database files are excluded from the upload script so they never get overwritten or accidentally exposed.*

---

### Setting up the Database & Cron
1. Edit `config.php` with your database credentials if needed.
2. Import the schema:
   ```bash
   mysql -u root -p < install.sql
   ```
3. For automated appointment reminders, point a cron job at `cron_reminders.php` every 30 minutes:
   ```cron
   */30 * * * * root /usr/local/bin/php /var/www/html/appointments/cron_reminders.php >> /var/log/cron_reminders.log 2>&1
   ```
