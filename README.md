# Convention Registration

A Laravel registration app that stores multiple attendee submissions, keeps proof-of-payment uploads private, emails the submitter and administrator, and exports registrations to an Excel workbook.

## Requirements

- PHP 8.3 or newer with the extensions required by Laravel and PhpSpreadsheet (`mbstring`, `xml`, `zip`, and `gd`, among others)
- Composer

On Windows, make sure `extension=gd` is enabled in the `php.ini` used by the command line (`php --ini` shows its location), then open a new terminal and confirm `php -m` lists `gd`.

## Setup

1. Install dependencies and create the local environment:

   ```sh
   composer install
   copy .env.example .env
   php artisan key:generate
   ```

2. Set `ADMIN_EMAIL` to the address that should receive new registrations. Set `REGISTRATION_ALLOWED_EMAILS` to a comma-separated list of email addresses allowed to register (for example, `REGISTRATION_ALLOWED_EMAILS=attendee@example.com,another@example.com`). Only those addresses can pass the email step; the address is also checked on the server when registration is submitted. This allowlist does not send an email verification code. After signing in to the admin dashboard, use **Add access email** to add more approved addresses without changing `.env`.

   Configure Google Sheets access:

   - In Google Cloud, enable the Google Sheets API and create an OAuth client ID with application type **Web application**.
   - Add the exact value of `GOOGLE_SHEETS_REDIRECT_URI` (`http://127.0.0.1:8000/admin/google-sheets/callback` locally) as an **Authorized redirect URI** for the OAuth client. OAuth requires an exact match; do not substitute `localhost` for `127.0.0.1`.
   - Configure the OAuth consent screen and, if it is in testing mode, add the Google account you will use as a test user.
   - Save the downloaded OAuth client JSON as `storage/app/private/google-sheets/oauth-client.json`. This Laravel private-storage folder is excluded from Git; never store credentials in `storage/app/public` or commit the JSON.
   - `GOOGLE_SHEETS_SPREADSHEET_ID` is prefilled with the supplied spreadsheet ID; `GOOGLE_SHEETS_TAB_ID=0` selects the tab from the provided `gid=0` link.
   - The app writes 14 columns in this order: District, Surname, Given name, Middle initial, Nickname, Age, Gender, Shirt size, Division, Ministry, Email, Proof of payment, Submitted at, and Birthdate. If row 1 is blank, the app adds these headers. For an existing sheet, it adds Birthdate to column N without changing existing columns.
   - Connect from the admin dashboard using a Google account that has Editor access to the sheet. OAuth refresh tokens are encrypted and stored in Laravel private storage.

   Create an `ADMIN_EXPORT_KEY` as a long, random secret; for example:

   ```sh
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

   Keep the generated key private and put it in `.env`.

3. Configure a real mail provider in `.env` (`MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, and `MAIL_FROM_ADDRESS`). The default Laravel mailer is `log`, which writes messages to the application log instead of delivering them.

4. Create the SQLite database and tables, then start the app:

   ```sh
   php artisan migrate
   php artisan serve
   ```

   Open `http://127.0.0.1:8000`.

## Run with Docker

1. Configure `.env` as described above. For local use, keep `APP_URL=http://127.0.0.1:8000`; make sure `APP_KEY` is generated and `APP_DEBUG=false` before using the container beyond local development.
2. Build and start the container with persistent storage:

   ```sh
   docker build -t convention-registration .
   docker run -d --name convention-registration -p 8000:80 --env-file .env -e GOOGLE_SHEETS_REDIRECT_URI=http://127.0.0.1:8000/admin/google-sheets/callback -v convention-storage:/var/www/html/storage -v convention-database:/var/www/html/database convention-registration
   docker exec convention-registration php artisan migrate --force
   ```

   Open `http://127.0.0.1:8000`. The named volumes keep the SQLite database, uploaded payment proofs, and Google OAuth files across container replacements. The `.env` file, local credentials, uploads, database, and other private storage are excluded from the Docker build context.
3. If using Google Sheets, copy the OAuth client JSON into the persistent storage volume:

   ```sh
   docker exec convention-registration mkdir -p /var/www/html/storage/app/private/google-sheets
   docker cp storage/app/private/google-sheets/oauth-client.json convention-registration:/var/www/html/storage/app/private/google-sheets/oauth-client.json
   ```

   For a deployed hostname or HTTPS URL, update `APP_URL` and set `GOOGLE_SHEETS_REDIRECT_URI` to that host's exact `/admin/google-sheets/callback` URL; register the same URL in Google Cloud.
4. To stop the app, run `docker stop convention-registration`. To start it again, run `docker start convention-registration`. Keep the named volumes; removing them deletes the database and uploaded files.

## Using the app

- Choose one district and attach one PDF, JPEG, or PNG proof of payment (up to 10 MB) for the whole submission. Uploads stay on Laravel's private local disk and are not publicly accessible.
- Enter an email address from `REGISTRATION_ALLOWED_EMAILS` on Section 1. After it is approved, the app opens Section 2 on a separate page for attendee registration. Each attendee must be at least 16 years old; birthdate and age are validated when submitting. Delegate details are saved to the server session as you edit them and restored after a page refresh; uploaded payment proof files must be selected again after refreshing. Use **ADD ANOTHER DELEGATE** to add attendees to one submission (up to 50 at a time). All attendees share the selected district, payment proof, and approved email address.
- Each attendee is saved as a separate registration and appended as a new row to the configured Google spreadsheet. The batch email address receives one confirmation email with all submitted attendee data, including birthdates, in `convention-registrations.xlsx`. `ADMIN_EMAIL` receives an individual notification with that attendee's payment proof and a link to the shared spreadsheet.
- If a registration was saved while Google Sheets was disconnected, configure OAuth and sign in at `/admin/login`. Choose **Connect Google Sheets**, approve access with an account that can edit the sheet, then use **Sync pending** without submitting registrations again.
- Open `/admin/login` and enter `ADMIN_EXPORT_KEY` to view registrations, download payment proofs, and export all rows as `convention-registrations.xlsx`. Uploaded JPEG and PNG proofs are embedded as images in the workbook; PDF proofs remain listed by filename.
- Configure a durable production database and private object storage before deploying to multiple application servers.

## Tests

```sh
php artisan test
```
