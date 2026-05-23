# Optometry Booking System — AI Prompt Helper

> **Stack:** Vanilla PHP 8.1+ · MySQL · Vanilla JS · Pico CSS v2
> **Goal:** A lean Easy Appointments clone purpose-built for an optometry clinic.
> Paste relevant sections into your AI assistant when building each feature.

---

## Project Snapshot

A single PHP project with no framework. The frontend is one HTML page styled with Pico CSS and driven by vanilla JS. PHP files handle routing and DB access directly using PDO. No build tools, no npm, no API layer.

**Design rule:** Every UI component must use Pico CSS semantic HTML. No extra CSS frameworks. Custom overrides only where Pico falls short. Always include Pico from CDN: `https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css`

---

## Database Schema

> Paste this section when asking the AI to write any SQL or DB logic.

Seven tables total. Keep the schema flat — avoid joining more than two tables in any single query.

**users** — Admins and providers share one table, distinguished by a `role` ENUM (`admin`, `provider`). Each provider has a `working_plan` stored as a native MySQL JSON column. The JSON shape is a map of weekday keys (`mon`–`sun`), each containing `start`, `end`, `lunch_start`, and `lunch_end` time strings. Include an `is_active` flag so providers can be soft-disabled without deleting records.

**appointment_types** — Stores a name, a duration in minutes, and a hex display colour.

**provider_appointment_types** — Pivot table linking providers to the appointment types they personally offer. Composite primary key on both columns.

**customers** — Base columns are `full_name`, `email`, `mobile`, and `created_at`. Admins may later add custom columns to this table via the admin panel — the AI must not assume a fixed column set when querying customers.

**appointments** — Links a provider, customer, and appointment type together. Stores explicit `start_time` and `end_time` as DATETIME. Status is an ENUM with six values: `booked`, `unconfirmed`, `confirmed`, `present`, `completed`, `no_show`. Default is `booked`. Includes a `hash_key` CHAR(64) unique column — a random token used in email links to allow unauthenticated client self-service.

**unavailabilities** — Blocks of time that are off-limits. `provider_id` may be NULL to indicate a clinic-wide closure rather than a provider-specific one.

**settings** — Simple key-value table. Important keys include `reminder_time` (e.g. `08:00`), `clinic_name`, and `booking_deadline_hours`.

---

## Availability Engine

> Paste this section when asking the AI to write or debug time slot generation.

This is a single PHP function. Its inputs are a provider ID, a date string (YYYY-MM-DD), and a duration in minutes. It returns an array of available start times as strings (e.g. `['09:00', '09:30']`).

**Step 1 — Load the provider's day schedule.** Read the `working_plan` JSON from the users table. Find the weekday key matching the given date. Extract the day start, day end, lunch start, and lunch end times.

**Step 2 — Collect all busy blocks in one query.** Use a single `UNION ALL` query — no multiple round-trips. The three sources are: active appointments for that provider on that date (exclude `no_show` and `completed` statuses), manual unavailabilities for that provider or clinic-wide on that date, and the lunch block treated as a plain time range.

**Step 3 — Iterate and check.** Loop from day start to day end in increments of the requested duration. For each candidate slot, check it against every busy block using this overlap formula:

```
slot_start < busy_end  AND  slot_end > busy_start
```

If no busy block overlaps, the slot is free. Append it to the results.

**Step 4 — Return.** Output the free slot strings. Do not include any slot whose `slot_end` would exceed the day end time.

---

## Dynamic Custom Fields

> Paste this section when asking the AI to build the custom field admin panel.

Admins can add or remove custom input fields (text, radio, checkbox) that appear on the customer record and the booking wizard's final step. These fields are stored as actual columns on the `customers` table, not in a separate EAV table.

**How it works:**
- Schema changes happen only when the admin explicitly clicks Save — never on a polling schedule.
- To discover existing custom fields, query `DESCRIBE customers` and filter for columns with the `cf_` prefix (e.g. `cf_health_fund`). All admin-added columns must use this prefix.
- When the admin saves the custom field list, diff the submitted fields against the existing `cf_` columns. Run `ALTER TABLE customers ADD COLUMN` for new ones and `ALTER TABLE customers DROP COLUMN` for removed ones.
- Supported field types map to SQL types as follows: `text` uses `VARCHAR(255)`, `radio` and `checkbox` use `VARCHAR(100)`.
- Before any `ALTER TABLE`, sanitise column names strictly — allow only lowercase letters, digits, and underscores, and always enforce the `cf_` prefix.
- Dropping a column permanently deletes historical data. This is intentional and by design.

---

## 4-Step Booking Wizard

> Paste this section when asking the AI to build or extend the customer-facing booking flow.

The wizard is a single HTML page. JS shows and hides each step — nothing is a separate page load. Use Pico CSS `<article>` with `<header>` and `<footer>` as the card shell. The header contains a numbered step indicator. The footer contains Back and Next buttons.

**Step 1 — Select Provider.** Fetch active providers from the backend and render them as a list of selectable cards. Clicking one stores the `provider_id` in JS state and advances to Step 2.

**Step 2 — Select Appointment Type.** Fetch only the types linked to the chosen provider via the pivot table. Render as selectable cards. Clicking one stores `type_id` and duration, then advances.

**Step 3 — Select Date and Time.** Show a date picker. On date selection, fetch available slots for the chosen provider, date, and duration. Render slots as clickable time buttons. Selecting one stores `start_time`.

**Step 4 — Customer Details.** Collect email and mobile first. On blur or on a Continue tap, send those two fields to the backend to resolve the customer's identity. Three scenarios:

- **0 matches:** Treat as a new registration. Show Full Name plus any `cf_` custom fields. On submit, create the customer record then the appointment.
- **1 match:** Identity confirmed silently. Do not show the name field. Proceed straight to booking with the matched `customer_id`.
- **2+ matches (family plan):** The backend returns a list of `{id, full_name}` pairs. Render a `<select>` dropdown prompting: *"Which family member is this appointment for?"* Wait for selection before finalising.

---

## Appointment Status Lifecycle

> Paste this section when asking the AI to handle status logic, admin controls, or the cron job.

Every appointment moves through a strict sequence:

`booked` → `unconfirmed` or `confirmed` → `present` → `completed` or `no_show`

- **booked** is the default state set automatically when a customer completes the wizard.
- **confirmed** is set manually by a receptionist or admin in the admin portal.
- **present** is set manually when the patient physically arrives at the clinic.
- **completed** is set manually when the appointment finishes.
- **no_show** is set automatically by the cron job when `end_time` has passed and the status is still `booked`, `unconfirmed`, or `confirmed`.

**Client self-service via hash key.** Confirmation emails include a URL containing the appointment's `hash_key`. That URL lets the customer reschedule or cancel without logging in. Before allowing any change, the backend must check that `start_time` is still further in the future than the `booking_deadline_hours` setting allows. Reject changes that are too close to the appointment time.

---

## Cron Script

> Paste this section when asking the AI to write the scheduled background task.

One PHP script handles two jobs. It should run every 5 minutes via server cron.

**Job 1 — Auto no-show.** Run a single UPDATE query: set status to `no_show` for any appointment whose `end_time` is in the past and whose status is still `booked`, `unconfirmed`, or `confirmed`.

**Job 2 — Morning reminders.** Read `reminder_time` from the settings table. If the current server time matches that value (compare at HH:MM granularity), fetch all appointments for today with status `confirmed` and send each one a reminder email via PHPMailer.

The script must use the server's configured timezone — set it explicitly at the top of the script. Do not create a separate table to track which reminders have been sent; the `confirmed` status plus the time-match gate is sufficient.

---

## Backup & Restore

> Paste this section when asking the AI to build the admin backup tab.

**Export.** A single button in the admin panel triggers a PHP script that reads every table with `SELECT *`, encodes the rows as JSON, and streams the combined result as a file download using the `Content-Disposition: attachment` header. Do not write the file to disk.

**Restore.** An upload form accepts the JSON backup file. The restore script disables foreign key checks, truncates each table, inserts the rows from the JSON, then re-enables foreign key checks. This order avoids constraint failures caused by insert sequencing.

---

## Pico CSS Conventions

> Include this in any prompt where you are asking for frontend HTML or UI components.

- Use semantic HTML — Pico automatically styles `<article>`, `<header>`, `<footer>`, `<nav>`, `<table>`, `<form>`, `<dialog>`, `<details>`.
- Wrap `<input>` elements inside their `<label>` for automatic Pico form layout.
- For modals, use the native `<dialog>` element — Pico styles it out of the box.
- For loading states, set `aria-busy="true"` on the button that triggered the action.
- Set colour accents via the CSS custom property `--pico-primary` in `:root`. Never hardcode colours inline.
- Do not fight Pico's dark/light mode — it switches automatically. Do not hardcode `color` or `background-color` on any element Pico already styles.
- Use `class="secondary"` on secondary buttons, `class="outline"` for ghost buttons.
- Use `class="container"` on `<main>` to constrain page width.

---

## Prompt Tips

- **Schema questions:** Always paste the Database Schema section. The AI needs the full table structure to write correct queries.
- **Slot bugs:** Always paste the Availability Engine section. The overlap formula (`slot_start < busy_end AND slot_end > busy_start`) is the most common source of errors.
- **Status questions:** Paste the Lifecycle section. Be explicit about which transitions are manual vs automatic.
- **Frontend components:** Always paste the Pico CSS Conventions section so the AI doesn't reach for Bootstrap or inline styles.
- **Use PDO only.** Specify `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` in every DB connection. Never use `mysqli`.
- **No framework.** Remind the AI there is no router, no ORM, and no templating engine. PHP files are included directly.