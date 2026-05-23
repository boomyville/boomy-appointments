Act as a senior backend PHP developer. I am building a self-hosted backend component for an "Easy Appointments" clone. Please review the architecture outlined below and generate the required codebase files based on these specifications.

### 1. Database Schema (`initial.sql`)
Generate a clean MySQL schema that includes:
- A `users` table for administrators (storing id, email, password_hash, created_at).
- A `settings` table (key-value pair structure) to hold dynamic configuration like `site_name`, `organization_email`, and `date_format`.
- Insert initial default rows for the settings, with the date format defaulting to the European/Australian standard (`d/m/Y`).

### 2. Configuration (`config.php`)
Provide a configuration file defining global constants or an environment-style array for:
- Database connection details (Host, DB Name, User, Password).
- Default PHP timezone setup, explicitly setting it to 'Australia/Melbourne'.

### 3. Core Utilities (`functions.php`)
Create helper functions including:
- `get_time()`: Returns the current server time in `Y-m-d H:i:s` format.
- `get_date_format($db_connection)`: Queries the database settings table to retrieve the saved date format preference.

### 4. Authentication Engine (`login.php`)
Implement a secure login script:
- **First-Run Registration:** Check the `users` table. If it is completely empty, automatically display an "Initial Admin Setup" registration form instead of the login form. Once this first admin is created, disable this route entirely.
- **Standard Login:** A clean form validating email and hashed passwords (using PHP's `password_verify()`). Use basic session handling (`session_start()`) to protect subsequent pages.

### 5. Control Panel (`settings.php`)
Create a protected administration dashboard (requires a valid session) that renders a form to update the database settings:
- Site/Website Name (text input).
- Organization Email (email input).
- Date Format Choice: A dropdown menu presenting common formats, with `DD/MM/YYYY` (d/m/Y) pre-selected/marked as default.

Please provide the code for each file using modern PHP best practices, including PDO for secure database interactions with prepared statements to prevent SQL injection. Keep the code clean, modular, and easy to read.