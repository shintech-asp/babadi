# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Pestify is a PHP-based pest control service marketplace running on XAMPP. Seekers book pest control services; providers list and manage those services; admins oversee the platform.

**Stack:** Vanilla PHP (no framework), MySQL via PDO, PHPMailer (Composer), PayMongo payments. No build step, no test suite.

**Mobile API:** A Flutter app is being built against `api/v1/` — a dedicated REST layer with JWT auth (HS256, 30-day tokens). See `FLUTTER_PLAN.md` for the full endpoint map, booking flow, and Flutter implementation notes.

**Local URL:** `http://localhost/pestify`. The `C:/xampp/htdocs/Pestify/` path below is stale — on this machine (macOS) the project lives at `/Applications/XAMPP/xamppfiles/htdocs/pestify/` via XAMPP for macOS. (Original: project lives at `C:/xampp/htdocs/Pestify/`.)

## Running the App

Start Apache and MySQL in the XAMPP Control Panel, then open `http://localhost/pestify` in a browser.

**First-time setup:**
1. Import the database schema via phpMyAdmin or `mysql -u root pestify < "pestify (6).sql"` (the SQL dump file).
2. Visit `http://localhost/pestify/system/setup.php` and click "Run Migration" to create OTP-related columns.
3. Visit `http://localhost/pestify/system/check-db.php` to verify the connection.

**Database:** MySQL database named `pestify`, default credentials root/empty password (see `config/config.php`).

## Architecture

### Folder layout by role

| Directory | Purpose |
|-----------|---------|
| `auth/` | Registration, login, logout, OTP verification, password reset |
| `seeker/` | Seeker-facing pages: browse providers, avail/book services, payment flow, feedback |
| `provider/` | Provider portal: create listings, manage services, handle booking requests |
| `browse/` | Public listing pages (no login required) |
| `admin/` | Platform admin dashboard |
| `admin/hr/` | HR sub-module (attendance, payroll, recruitment, timekeeping) |
| `admin/finance/` | Finance sub-module (income, expenses, requests) |
| `api/` | Legacy thin JSON endpoints (`messages.php`, `cn_status.php`) |
| `api/v1/` | Mobile REST API — JWT Bearer auth, JSON in/out, CORS-enabled. Powers the Flutter app. See `FLUTTER_PLAN.md`. |
| `config/` | `config.php` (all constants + helpers), `database.php` (PDO wrapper), `send_email.php` |
| `includes/` | Shared partials: `header.php`, `footer.php`, helper classes |
| `assets/` | CSS (`style.css`, `seeker-unified.css`), images |
| `system/` | Setup/migration utilities, PayMongo webhook handler |
| `provider-portal/` | Standalone CRM portal for providers: staff, HR (attendance, payroll, recruitment), finance (income, expenses), CRM (bookings, requests, services), subscription management |

### URL routing

All pages use the `appUrl()` helper (defined in `config/config.php`) to build URLs. This helper maps legacy flat filenames like `my-requests.php` to their canonical subfolder paths (e.g. `seeker/my-requests.php`). The static map is inside `canonicalAppRoute()` in `config/config.php:129-164`.

Legacy root-level URLs (pre-refactor) are rewritten by `.htaccess` so old links keep working. All `.htaccess` rules use silent internal rewrites (`[L,NC]`) — there are **no** `R=302` external redirects. On XAMPP/Windows, `R=302` causes Apache to build redirect URLs from the filesystem path (`http://localhost/C:/xampp/...`) rather than the web root; all such rules have been removed.

### Session & auth

- `$_SESSION['user_id']`, `$_SESSION['user_type']` (`seeker` | `provider` | `admin`), `$_SESSION['first_name']`
- Helper guards: `requireAuth()`, `requireUserType($type)`, `isAdmin()`, `isProvider()`, `isSeeker()`
- Admin pages use a separate session key `$_SESSION['admin_id']` / `$_SESSION['admin_logged_in']`, enforced by `admin/includes/admin-auth.php`
- Admin roles: `super_admin`, `admin`, department-scoped (`hr`, `finance`)
- Provider portal staff use a separate session namespace: `$_SESSION['portal_staff_id']`, `portal_provider_id`, `portal_role`, `portal_dept`, `portal_full_name`, `portal_company`, `portal_must_change`, `portal_account_type`, `portal_employee_id`; enforced by `provider-portal/includes/portal-auth.php`

### Centralized login

**All user types share a single login page: `auth/login.php`.**

Authentication is checked in this order:
1. `admin_users` table — by username or email (admins of all roles)
2. `provider_staff` table — by username or email (portal staff)
3. `users` table — by email only (seekers and provider owners)

After login, each role is routed to its home:
| Role | Destination |
|------|-------------|
| `super_admin` | `admin/super-admin-dashboard.php` |
| `admin` | `admin/dashboard.php` |
| `hr` | `admin/hr/dashboard.php` |
| `finance` | `admin/finance/dashboard.php` |
| Portal staff | `provider-portal/dashboard.php` (or `change-password.php` if first login) |
| Provider owner | `providers-dashboard.php` |
| Seeker | `index.php` (or redirect param) |

**The mobile API now mirrors this exactly.** `api/v1/auth/login.php` was, until this Flutter session, `users`-only — no `admin_users`/`provider_staff` check at all, and the Flutter app had a separate `/admin/login` screen calling `api/v1/admin/auth/login.php` directly. Fixed to run the same three-tier cascade as this page (see "Recent Work Log" below). `api/v1/admin/auth/login.php` and `api/v1/portal/auth/login.php` still exist and still work as standalone endpoints — nothing about them changed — but the Flutter app no longer calls them.

`admin/admin-login.php` and `provider-portal/login.php` both redirect to `auth/login.php` — they are no longer standalone login pages.

### Admin RBAC

`admin/includes/admin-auth.php` enforces strict role isolation. Each admin page declares either:
- `$allowed_roles = ['admin']` — whitelist pattern (one or more roles)
- `$require_dept = 'hr'` — strict department match (no super_admin bypass)

Unauthorized access redirects to the role's home dashboard with a flash error. Role→home mapping is in `_adminRoleHome()` inside `admin-auth.php`.

`admin/includes/admin-sidebar.php` gates each nav section by role: super_admin, admin, hr, and finance each see only their own sections.

### Provider portal staff

Staff are created in `provider-portal/staff.php` (owner only). On creation:
- A random temp password is generated, stored plain-text in `provider_staff.temp_password` and as a bcrypt hash in `password_hash`
- `must_change_password = 1` is set; staff are emailed their credentials
- Staff log in via `auth/login.php` using username or email + temp password
- On first login they are redirected to `provider-portal/change-password.php`; the portal sidebar shows only **Change Password** and **Logout** until the password is changed

`provider_staff` table key columns: `id` (AUTO_INCREMENT), `provider_id`, `username`, `email`, `password_hash`, `temp_password`, `role` (`owner|hr|finance|crm`), `department` (`hr|finance|crm|all`), `must_change_password`, `status` (`active|inactive`).

The "Back to Provider" link in the portal sidebar is only shown for `owner` role accounts, not staff.

### Database

All queries use PDO prepared statements. The `Database` class in `config/database.php` returns a `PDO` connection; pages instantiate it directly (`$database = new Database(); $db = $database->getConnection();`). No ORM or query builder.

Key tables: `users`, `providers`, `service_listings`, `service_categories`, `availed_services`, `service_reviews`, `messages`, `admin_settings`, `admin_logs`, `admin_users`, `provider_staff`.

`availed_services` booking-flow columns of note: `status` (ENUM), `control_number` (seeker's code, `PCF-YYYY-XXXXXX`), `provider_control_number` (provider's code — `PCP-...` when generated by `ControlNumberService::generateCode()`, but `PCV-...` when backfilled by `provider/service-requests.php`'s own inline backfill block; both prefixes are valid, `hash_equals()` checks don't care), `provider_verified_at`, `seeker_verified_at`, `dual_verified_at`, `qr_token` (6-char, generated on the `→ starting` transition), `qr_scanned_at` (set when provider scans/enters the token). See "Dual control-number verification had two independently-drifting implementations" below before touching any of this.

### Booking workflow helper

`includes/booking_workflow_helper.php` is the canonical source for status transitions. Key functions:

| Function | Purpose |
|----------|---------|
| `transitionBookingStatus($pdo, $id, $newStatus, ...)` | Validates transition, writes history, sets timestamps |
| `generateQrToken($availedId)` | Returns a 6-char token from charset `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (no ambiguous chars) |
| `scanQrAndStartService($pdo, $token, $staffId)` | Looks up token on a `starting` booking, transitions to `on_going` |
| `isValidTransition($from, $to)` | Whitelist of allowed status moves |

Status constant `BK_ONGOING` = `'on_going'` (DB column value). The UI normalizes this to `'ongoing'` via `normalizeWorkflowStatus()` before array lookups.

**`includes/ControlNumberService.php` is a second, independent implementation of dual code verification** — it does not call `transitionBookingStatus()` or reuse `isValidTransition()`. It's used by the mobile JWT API (`api/v1/seeker/bookings/verify.php`, `api/v1/provider/requests/verify.php`); `provider/service-requests.php` has its *own third* inline implementation for the session-based web dashboard (`verify_control_number` POST handler, ~line 401). All the status-transition and QR-token-generation logic in these two places must be kept in sync by hand — see the "Recent Work Log" entry below for the bug this caused when they drifted apart.

### Status value gap: `on_going` vs `ongoing`

The database `availed_services.status` ENUM stores `on_going` (with underscore). UI code maps (e.g. `$allSteps`, `statusInfo()`) use `ongoing` (no underscore). Bridge pattern used throughout:

```php
$flowStatus = normalizeWorkflowStatus((string)$av['status']);
// normalizeWorkflowStatus converts 'on_going' → 'ongoing', others pass through
```

`statusInfo()` in `provider/service-requests.php` carries an `'on_going'` alias entry so raw DB values also render with the correct label and colour.

### Provider Subscription Tiers

The provider portal has a **Free / Pro / Grace** tier system that gates paid features.

**Tiers:**
| Tier | Condition | Access |
|------|-----------|--------|
| Free | Default permanent | Basic staff management only |
| Pro | Active paid subscription | HR, Finance, full CRM |
| Grace | 3 days after `expires_at` | Same as Pro (lazy-updated by `getProviderTier()`) |

**Key files:**

| File | Purpose |
|------|---------|
| `provider-portal/includes/portal-tier.php` | `getProviderTier()` helper; sets `$provider_tier` and `$tier_is_paid` globals; lazy-updates expired → grace |
| `provider-portal/includes/portal-sidebar.php` | Unified sidebar for all roles; shows PRO lock chips on gated nav items |
| `provider-portal/subscriptions.php` | Owner-only subscription purchase page (PayMongo Checkout Sessions) |
| `provider-portal/subscription-success.php` | PayMongo return URL; activates the pending subscription row |
| `admin/subscription-plans.php` | Super-admin pricing management + manual activate/expire |
| `system/migrations/subscription_tiers.sql` | Schema migration (already applied) |

**Schema:**
- `subscription_plans`: `id`, `name`, `monthly_price` (₱500), `yearly_price` (₱5 000)
- `provider_subscriptions`: `plan_id` FK, `billing_cycle` ENUM(`monthly`|`yearly`), `status` ENUM(`pending`|`active`|`grace`|`expired`|`cancelled`), `grace_ends_at`
- `providers`: `office_lat` DECIMAL(10,8), `office_lng` DECIMAL(11,8) (for geofencing)

**Gating pattern** — paid-only pages must include this near the top after `portal-tier.php`:
```php
if (!$tier_is_paid) {
    echo _tierLockedPage('Feature Name', 'subscriptions.php');
    exit;
}
```

**Grace period:** `getProviderTier()` detects expired-active rows, writes `status='grace'` and `grace_ends_at = expires_at + 3 days`, and returns `pro` tier. No cron needed.

**Geofencing:** Haversine formula, 10 km radius, browser Geolocation API. Owner role is exempt. Staff→employee mapping by matching email in `provider_staff` and `employees` tables (fragile — explicit FK not yet added).

**Known gaps:**
- No PayMongo webhook: if the user closes the tab before the redirect, the subscription stays `pending` forever.
- `direct-entry.php` sets `portal_staff_id` as the string `"owner_5"` which can corrupt integer columns.
- Staff→employee email matching is fragile; should add an explicit `employee_id` FK on `provider_staff`.

### Notification Bell (Seeker)

The notification bell lives entirely in `includes/header.php` so it appears on every seeker page that includes the shared header (index, providers, my-requests, provider-details, booking-details, etc.).

**How it works:**
- PHP at the top of `header.php` opens its own DB connection using prefixed variables (`$_hdr_db`, `$_hdr_db_inst`, `$_bell_items`, `$_bell_unread`, `$_buid`) to avoid clobbering the including page's `$db`/`$database`.
- `_hdrTimeAgo()` helper is declared with `if (!function_exists('_hdrTimeAgo'))` so multi-include pages don't trigger a fatal redeclaration.
- Bell HTML is a `<li>` rendered directly inside `<ul class="nav-menu">` — no JS DOM injection.
- CSS and JS (`toggleNotifPanel`, `switchNotifTab`, outside-click close) are inlined in `header.php`.
- Notification links use `appUrl()` (e.g. `appUrl('my-requests.php')`) — never bare relative paths, which would hit `.htaccess` rewrites.

**Mark-all-read pattern:** The `?mark_notif_read=1` GET handler runs the UPDATE queries and then **falls through** — no `header('Location:')` redirect. This avoids "headers already sent" errors on pages (like `seeker/my-requests.php`) that begin HTML output before including `header.php`. The notification queries immediately below re-fetch with all `is_read=1`, so the page renders with 0 unread.

**Two notification tabs:** Status updates (from `availed_services`) and messages (from `messages` table, linked to the provider via `appUrl('provider-details.php') . '?id=...'`).

**`index.php` compatibility:** The old duplicate bell block in `index.php` is wrapped in `<?php if (false): ?>` and the old `injectBellIntoNavbar()` JS call is removed, so the page doesn't double-render the bell.

### Payments

PayMongo integration. Keys and webhook handling are in `config/config.php` (`PAYMONGO_SECRET_KEY`, `PAYMONGO_PUBLIC_KEY`) and `system/paymongo-webhook.php`. Payment flow pages live in `seeker/payment-*.php`.

### Email

PHPMailer via Composer (`vendor/`). Configuration (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`) is in `config/config.php`. The `config/send_email.php` helper wraps PHPMailer; OTP emails are sent during registration/verify flow.

## Key config.php helpers

| Helper | What it does |
|--------|-------------|
| `appUrl($path)` | Returns a root-relative URL, resolving legacy filename aliases |
| `siteUrl($path)` | Returns a full absolute URL (uses `SITE_URL`) |
| `sanitize($input)` | XSS-safe input cleaning (htmlspecialchars + strip_tags) |
| `escape($string)` | Output escaping for display |
| `generateCSRFToken()` / `validateCSRFToken($t)` | Session-based CSRF |
| `flash($name, $message)` | Set/get one-shot session flash messages |
| `uploadFile($file, $dir)` | Validated file upload, returns path info |

## Changing configurations

All site-wide constants live in `config/config.php`. Update `SITE_URL` when deploying to a different domain/path — `APP_BASE_PATH` is derived from it automatically.

---

## Recent Work Log

### Dual Control Number Verification — Starting Flow Redesign
**Files:** `provider/service-requests.php`, `seeker/my-requests.php`

The "Advance to Starting" button was removed as a direct step. Code verification is now the **only gate** for the Starting status.

**Provider panel (`provider/service-requests.php`) — key behaviour:**
- `isBeforeServiceDay` (preferred_date strictly in the future): shows a **"Start Early"** button that opens the ctel modal with `early_start=1`, bypassing the service-day date check in the PHP handler.
- On or after service day: no Advance button shown — the **"Enter Seeker Code"** button handles Starting entirely.
- PHP ctrl handler: date check changed from `!== date('Y-m-d')` to `> date('Y-m-d')` so overdue (past-dated) bookings are no longer blocked. `$_POST['early_start'] === '1'` bypasses the check for early starts.
- `openCtel(availId, customerName, seekerVerified, isTestServiceDay, allowEarlyStart)` — 5th param added; sets `#ctelEarlyStart` hidden input and shows a blue info banner.
- Ctel modal has hidden input `name="early_start" id="ctelEarlyStart"` and `#ctelEarlyStartHint` div.

**Code hiding (both panels):**
- Provider panel: seeker's code (`seekerCtrl`) displayed as `••••••••••` with note "Get this from the seeker in person". Provider's own code (`providerCtrl`) shown with Copy button, labelled "Your Code (share with seeker)".
- Seeker panel (`seeker/my-requests.php`): provider's code (`provider_control_number`) shown as `••••••••••` with note "The technician will tell you this code in person." Value never sent to the browser.

**Seeker "Enter Provider Code" bypass:**
- When the provider has already verified (`provider_verified_at` is set), the seeker's "Enter Provider Code" input is shown regardless of whether it is the service day. This covers bookings that were started early via the provider side.
- Server-side: AJAX seeker-verify handler checks `$providerAlreadyVerified` and skips the date gate when true.
- On reschedule accept: `provider_verified_at`, `seeker_verified_at`, and `dual_verified_at` are all cleared to NULL so the dual-verification flow resets.

### Provider Service Requests — Table & Modal Redesign
**File:** `provider/service-requests.php`

- Table reduced from 12 columns to **6**: #, Customer, Service, Date & Time, Status, View Details button.
- All action buttons (Accept, Decline, Message, Advance, Enter Seeker Code, Archive, Emergency Accept) moved into the **View Details modal footer**.
- Modal is scrollable: `display:flex; flex-direction:column; max-height:90vh` on box; `flex:1; min-height:0; overflow-y:auto` on body; `flex-shrink:0` on header/footer.
- Modal body sections: Payment info, Control Numbers, Verification status, Workflow progress, Reschedule form.
- Footer onclick handlers reference `_vdData.field` (module-level JS var) to avoid inline string-escaping issues with addresses containing newlines.
- Each row computes a `$modalData` PHP array and encodes it with `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)` for safe embedding in single-quote `onclick` attributes.

### QR Token Flow — Starting → Ongoing Transition
**Files:** `seeker/my-requests.php`, `provider/service-requests.php`, `includes/booking_workflow_helper.php`

The old "Attach Photo" arrival proof system was replaced entirely with a QR token handshake.

**Flow:**
1. When a booking enters `starting` status, `transitionBookingStatus()` calls `generateQrToken()` and stores the result in `availed_services.qr_token`.
2. **Seeker side** (`seeker/my-requests.php`): a "Show QR" button appears on the booking card. Clicking opens a modal displaying:
   - A client-side QR canvas rendered by `qrcode.js` (cdnjs, inlined — no external API call).
   - The 6-char token formatted as `XXX-XXX` in 26px monospace with a Copy button.
3. **Provider side** (`provider/service-requests.php`): the View Details modal footer shows a **"Scan Seeker QR"** button for `starting` bookings. Clicking opens a separate QR scanner modal that offers:
   - Camera scanning via `html5-qrcode` library.
   - Manual token entry (input auto-uppercases, strips non-alphanumeric, `maxlength=7`).
4. Provider submits token via AJAX `action=scan_qr` POST → `scanQrAndStartService()` in the workflow helper validates the token, writes `qr_scanned_at`, and transitions to `on_going`.

**Token format:** 6 chars from charset `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (omits `0/O`, `1/I`, `L` to avoid transcription errors). Generated with `random_bytes(6)`.

**Backfill:** `seeker/my-requests.php` setup block backfills any `starting` booking whose `qr_token` is missing or not exactly 6 chars (handles old long-hex tokens from earlier iterations).

**Camera error handling:** If no camera is detected (`NotFoundError`), a clear message directs the provider to use manual entry instead.

**Removed:** The entire `upload_arrival_proof` POST handler, `$hasArrivalProof`/`$canUploadArrivalProof` variables, and the arrival proof strip from the booking card in `seeker/my-requests.php`. The QR scanner link was also removed from the provider portal sidebar (`provider-portal/includes/portal-sidebar.php`).

### Provider Details — Review Images
**File:** `seeker/provider-details.php`

- Review images now displayed under the review text. The `feedback_image` column already exists in `service_reviews`; the template was just not rendering it.
- Images cap at `max-height:240px`; clicking toggles to full height.
- Note: web UI feedback is inserted into `service_feedback` (via `seeker/submit-feedback.php`), while the display page reads from `service_reviews` (used by the Flutter API and web rating flow). These are two separate tables. The ratings/count stats and review list both read from `service_reviews`.

### Providers Listing — Description Label
**File:** `seeker/providers.php`

- Added a small "ABOUT" label (`font-size:10px; font-weight:700; text-transform:uppercase`) above the truncated provider description on each card.

### Seeker Bookings — Collapsible Cards
**File:** `seeker/my-requests.php`

- Each booking card has a **chevron toggle button** (top-right, next to the status badge).
- Clicking collapses everything below the header (strips, control widget, monitoring timeline, details grid, action buttons) using CSS `grid-template-rows: 1fr → 0fr` transition — animates without a fixed `max-height`.
- Collapsed state is persisted per booking ID in `localStorage` (`bcard_<id>`), restored on page load.
- Header bottom border removed when collapsed so the card looks compact.

### Booking Details — Rating Form Hide After Submission
**File:** `seeker/booking-details.php`

- Rating form is hidden once a review has been submitted to prevent duplicate reviews.
- Guard: `$alreadyReviewed = $existingReview !== null || !empty($_GET['reviewed'])` — checks both the DB row and the `?reviewed=1` query param that the submit handler appends on success.
- The review display card only renders when `$existingReview` is actually set (not just `$alreadyReviewed`), so no empty card appears before the DB row exists.
- QR code for the booking uses `qrcode.js` canvas (replaces the old external API image that was unreachable on localhost).

### Notification Bell — Global via Shared Header
**Files:** `includes/header.php`, `index.php`

The notification bell was previously rendered only in `index.php` and injected into the navbar via JS (`injectBellIntoNavbar()`). This broke on all other seeker pages (Providers, My Requests, etc.) because they use `includes/header.php` and the bell HTML was absent.

**Fix:** Bell fully moved into `includes/header.php`:
- PHP section queries `availed_services` (status notifications) and `messages` (provider messages) for the logged-in user before any HTML output.
- Bell HTML rendered as a `<li>` directly inside `<ul class="nav-menu">` — no JS DOM injection needed.
- Bell CSS lives in a `<style>` block inside `includes/header.php`'s `<head>`.
- Bell JS (`toggleNotifPanel`, `switchNotifTab`, outside-click close) lives in `includes/header.php`'s `<script>`.
- "Mark all read" updates the DB in the header.php PHP section and then **falls through** (no `header()` redirect) so the page renders fresh with all-read data. This avoids `headers already sent` errors on pages that start HTML output before including the header.
- In `index.php`, the old duplicate bell block is suppressed with `<?php if (false): ?>` and the `injectBellIntoNavbar()` call is removed.

**Notification link URLs:** Changed from bare relative paths (`'my-requests.php'`) to `appUrl('my-requests.php')`. Bare relative paths triggered `.htaccess` `R=302` redirects which on XAMPP/Windows built URLs from the filesystem path (`http://localhost/C:/xampp/...`).

### `.htaccess` — Full R=302 Removal
**File:** `.htaccess`

All `[R=302]` external redirect rules were removed. On XAMPP/Windows, Apache constructs the redirect destination URL from `DOCUMENT_ROOT` (a Windows filesystem path), producing broken URLs like `http://localhost/C:/xampp/htdocs/Pestify/seeker/my-requests.php`. The duplicate rule set without `R=302` (internal rewrites, `[L,NC]`) already handled routing correctly and remains. `seeker/payment-success.php` uses `siteUrl()` for its own absolute redirect.

### Flutter App — Login Screen Rewrite
**File:** `pestify_flutter/lib/features/auth/screens/login_screen.dart`

Complete rewrite — Clerk/Linear 2024 style:
- White scaffold with radial green decoration circles (top-right and bottom-left `Positioned` containers, `withValues(alpha:)` tints).
- Brand mark: 56×56px green rounded square (radius 16) with `Icons.pest_control_rounded`, green glow `BoxShadow`.
- Left-aligned "Sign in to\nPestify" heading (34px, w800, navy, letterSpacing -1.0).
- Field labels rendered **above** inputs via `_fieldLabel()` helper (not floating labels). Labels are 13px w600 navy.
- Filled inputs: `Color(0xFFF7F8FA)` background, `Color(0xFFE4E7EC)` border → `AppTheme.primary` on focus; `borderRadius: 12`.
- "Forgot password?" link sits in the same `Row` as the Password label, right-aligned.
- All auth logic (login → JWT → `authProvider.login()` → role-based routing) preserved unchanged.
- Key fix: method named `_fieldLabel` (lowerCamelCase) not `_FieldLabel` — Dart `non_constant_identifier_names` lint.

### Flutter App — Messages Screen Rewrite
**File:** `pestify_flutter/lib/features/seeker/screens/messages_screen.dart`

Complete rewrite — Telegram 2024 style:
- No `AppBar` widget — custom `Container` header with 30px bold "Messages" title + edit icon.
- Inline search `TextField` below the header with live client-side filtering of thread list.
- `_kAvatarGradients` palette: 8 gradient pairs (green, teal, purple, blue, orange, pink, red, indigo) cycled by provider id modulo.
- 56px `CircleAvatar` with gradient background and white initials letter — no separators between items.
- Smart timestamp: shows time (HH:mm) for today, weekday name for this week, or date for older messages.
- Key fix: `}).toList(),` → `}).toList();` inside `setState` — trailing comma caused a parse error at line 58.

### Flutter App — Profile Screen Rewrite
**File:** `pestify_flutter/lib/features/seeker/screens/profile_screen.dart`

Complete rewrite — Spotify/Airbnb style using a three-layer `Stack`:
- **Layer 1:** Navy gradient hero (`Color(0xFF1A1F3A)` → `Color(0xFF2C3E7A)`, height ~200px) with name + email in white.
- **Layer 2:** White card with `borderRadius: top 32` starting at `heroHeight - 32`, holding all form fields.
- **Layer 3:** `CircleAvatar` (radius 48) `Positioned` at `heroHeight - 48` to straddle the hero/card seam.
- Settings-style field rows: `Color(0xFFF8FAFC)` container, `AppTheme.border` border, icon + label + right-aligned `TextFormField`, bottom-border-only separators.
- Danger zone: logout button in a `Color(0xFFFFF5F5)` / `Color(0xFFFFD7D7)` bordered box with red text.
- Key fix: `_pendingAvatar` → `pendingAvatar` in `_AvatarPicker._content()` — the method is on the widget class, not the state, so state fields aren't in scope.

### Flutter App — Messaging & Booking Bug Fixes
**Files:** `api/v1/messages/send.php`, `pestify_flutter/lib/features/seeker/seeker_api.dart`, `api/v1/seeker/bookings/store.php`

Two bugs fixed that blocked messaging and booking from the Flutter app:

**Messaging ("Failed to send message"):**
- Flutter sent `receiver_id` but `send.php` reads `to` — fixed field name in `seeker_api.dart` `sendMessage()`.
- `messages.id` had no `AUTO_INCREMENT` — every INSERT got id=0, second message failed with duplicate key error. Fixed with `ALTER TABLE messages MODIFY id INT(11) NOT NULL AUTO_INCREMENT`.

**Booking ("name is required"):**
- `store.php` used `req_inp('full_name')` (required) but Flutter never sends that field — changed to `inp()` with server-side fallback from `require_seeker()` user profile.
- Flutter sent `payment_method: 'full'` but PHP only accepted `'full_payment'` — added normalization alias in `store.php`.

### Flutter App — Full UI Redesign (Home, Listing Detail, Booking)
**Files:** `pestify_flutter/lib/features/seeker/screens/home_screen.dart`, `listing_detail_screen.dart`, `book_service_screen.dart`, `pestify_flutter/lib/features/seeker/seeker_api.dart`

**Home Screen:**
- Replaced 2-column `SliverGrid` with `SliverList` of full-width horizontal row cards.
- Each `_ListingCard` shows: 100×100 image (left, rounded), title + company name, `RatingBarIndicator` with star count, price in green, ECO/URGENT badges, and "View Details →" `TextButton`.

**Listing Detail Screen (`/seeker/listing/:id`):**
- Complete rewrite — Facebook mobile profile style.
- `SliverAppBar` (expandedHeight 280) with `PageView` image gallery, page-dot indicator, gradient fade, and a frosted back button.
- Content sections: title + category/ECO/URGENT badges, price + star rating, provider row (logo avatar, name, city, "View Profile" shortcut to `/seeker/provider/:id`), "About this Service", "About the Provider" (description + address/city chips), "Customer Reviews" (up to 5 review cards with initials avatar, stars, date, feedback).
- Sticky bottom bar with price + "Book Now" button (pushes to `/seeker/book` with `listingId` extra).
- `seeker_api.dart` `getListingDetail()` updated to merge `body['reviews']` (top-level key in `show.php` response) into the returned listing map so a single API call populates the whole screen.

**Book Service Screen (`/seeker/book`):**
- Added **Name** field (pre-filled from `getProfile()` on `initState`).
- Added **Contact Number** field (pre-filled from profile `phone`).
- Added **Pick on Map** — opens a `ModalBottomSheet` with a `WebView` rendering inline HTML (OpenStreetMap + Leaflet CDN). User taps the map to drop a pin; the HTML calls `window.FlutterMapChannel.postMessage(JSON)` via `JavascriptChannel`; Flutter catches the coordinates and reverse-geocodes via Nominatim (`nominatim.openstreetmap.org/reverse`) to fill the address field.
- Payment section redesigned: two selectable cards (Full Payment / Down Payment) with icon, label, and amount breakdown note ("50% charged now, 50% after service").
- `seeker_api.dart` `createBooking()` updated to accept optional `fullName` and `contactNumber` params, sent as form fields to `store.php`.

### Dual control-number verification had two independently-drifting implementations
**Files:** `includes/ControlNumberService.php`, `provider/service-requests.php`, `api/v1/seeker/bookings/verify.php`, `api/v1/provider/requests/verify.php`, `config/database.php`, `config/config.php`, `control_number_audit` table

A user testing "provider and seeker exchange codes to start service early" on the Flutter mobile app hit an emoji-triggered crash that turned out to be one symptom of a much bigger drift: the two places in this codebase that implement dual code verification had quietly diverged on what happens when both sides' codes match.

- `ControlNumberService::unlockService()` (used by the mobile JWT API) wrote `status = 'in_progress'` directly the moment both codes matched — skipping `starting` entirely and never calling `generateQrToken()`.
- `provider/service-requests.php`'s own separate inline handler (`verify_control_number` POST, session-based, used by the actual web dashboard) wrote `status = 'starting'` via a raw `UPDATE` — but *also* never generated a `qr_token`.

Net effect: **the QR-scan step (`scanQrAndStartService()`) was unreachable through any code path a real user could hit**, and which status a booking landed on (`starting` vs `in_progress`) depended entirely on which side happened to submit their code *second* — a pure race condition, not a designed behavior. Fixed both to converge on the same outcome: advance to `starting` and generate a real `qr_token`, regardless of which side completes the pair. The intended design going forward: code verification ("Start Early") confirms both sides agreed to start, often before the technician is physically on-site — it is *not* itself proof of arrival — so a QR scan on arrival is still required to reach `on_going`. Verified live by testing all 4 call-order combinations (seeker/provider × JWT-API/web-dashboard-session) — all 4 now produce identical `starting` + `qr_token` results. `in_progress` is kept as a *recognized legacy value* (`normalizeWorkflowStatus()`, `statusInfo()`) for old rows that already have it, but no code path writes it anymore.

**Other bugs found and fixed while chasing this:**
- `statusInfo()` had no entry for `in_progress` (fell through to a raw `ucfirst()` fallback, rendering literally "In_progress" in the UI) and its unmapped-status fallback background was the literal invalid CSS value `'#err'`. Both fixed; `normalizeWorkflowStatus()` also gained an `in_progress → ongoing` mapping so the step-progress widget resolves it correctly instead of defaulting to index 0 ("Accepted").
- The step-progress widget (`$allSteps`/`$currentIdx`/`$nextStep`, ~line 3073) had the same "defaults to index 0" bug for `completed`/`cancelled` bookings too — a booking that was already `completed` would show "Accepted" as its current step and offer a bogus "Advance to Preparing" button, which a provider could actually click (see next point). Fixed with explicit terminal-state handling.
- The generic `update_status` POST handler had **no guard against reopening a terminal booking** — combined with the point above, a provider could click a stray "Preparing" button on an already-`completed` booking and the handler would silently accept it, write `preparing`, log it to history... and then the page's own "auto-heal" block (~line 1167, forces status back to `completed` whenever `seeker_satisfaction_confirmed_at` is set) would silently revert it on the very next page load, with no history entry for the revert. Looked like the status was "flashing" between values. Fixed by rejecting any status change on an already-`completed`/`cancelled` booking with a clear error message.
- The `is_read` "NEW" badge is a generic unread-activity flag, reused for *any* unactioned update (including a seeker confirming completion, which explicitly resets `is_read = 0` to re-flag the provider) — so a `completed` booking could show a pulsing "NEW" tag, reading as if it were a fresh incoming request. Now shows "UPDATED" instead of "NEW" whenever the underlying status isn't `pending`.
- **DB connection charset was `utf8`, not `utf8mb4`**, despite every table already being `utf8mb4` — `config/database.php` ran `set names utf8`. Any insert containing a 4-byte character (an emoji in a verification notification, in this case) failed with `SQLSTATE[22007]: Invalid datetime format: 1366 Incorrect string value...` — a misleading error, since that SQLSTATE covers both real datetime errors and string/charset truncation. Fixed to `set names utf8mb4`.
- **PHP and MySQL run on different timezones** — `php.ini`'s `date.timezone` was XAMPP's default `Europe/Berlin`, while MySQL follows the OS's real timezone (`Asia/Manila` — this app is Philippines/Cavite-only). Any PHP-side `date('Y-m-d')` check against a MySQL-stored date (e.g. "is it the service day yet?") could be off by several hours. Fixed with `date_default_timezone_set('Asia/Manila')` in `config/config.php`.
- `control_number_audit.id` was missing `AUTO_INCREMENT` (same bug class as `messages.id`/`service_reviews.id`, see `FLUTTER_PLAN.md`/mobile `CLAUDE.md` if present) — every verification attempt silently failed to write its audit row. Fixed with `ALTER TABLE control_number_audit MODIFY id INT(11) NOT NULL AUTO_INCREMENT;`.
- Both `api/v1/seeker/bookings/verify.php` and `api/v1/provider/requests/verify.php` returned a flat `ok(['message'=>...])` body instead of nesting under `'data'` — the Flutter client's `ApiClient.unwrap()` always reads `body['data']`, got `null`, and crashed on the subsequent map cast even when the verification itself succeeded server-side. Fixed both to nest under `'data'`.

### Mobile API — Provider (Phase 2) endpoint fixes
**Files:** `api/v1/provider/dashboard.php`, `api/v1/provider/listings/store.php`, `api/v1/provider/listings/update.php`, `api/v1/provider/listings/delete.php`, `api/v1/provider/requests/update-status.php`, `uploads/` (permissions)

Built the Flutter provider app (`pestify_flutter/lib/features/provider/`) against these already-existing endpoints and found several bugs along the way, all fixed:

- `dashboard.php`'s active-bookings count checked `status IN (..., 'ongoing')` — the DB-canonical spelling is `on_going` (see `booking_workflow_helper.php`'s `BK_ONGOING`). Kept both spellings in the `IN (...)` list rather than just fixing the typo, since the web app still writes the legacy `ongoing` spelling in places.
- `listings/store.php`'s pricing-type validation allowed `per_sqm` — `service_listings.pricing_type`'s real ENUM (confirmed via `DESCRIBE`) is `fixed|per_sqft|hourly|custom`. `per_sqm` isn't a valid value; inserting it 500'd under this DB's `STRICT_TRANS_TABLES` mode. Fixed the allowed list; added the same validation to `update.php`, which previously had none at all.
- `listings/update.php`, `listings/delete.php`, and `requests/update-status.php` all returned a flat `ok(['message' => ...])` body instead of nesting under `'data'` — same bug class as the `verify.php` fix above. Fixed all three.
- Local `uploads/*` subdirectories are owned by the dev user at mode `0755`; Apache runs as `daemon:daemon` (see `httpd.conf`'s `User`/`Group` on this macOS/XAMPP install) and falls under "other", which has no write bit — every `move_uploaded_file()` call was silently failing with "Failed to save uploaded image." This had never been hit before because no listing/provider image had ever actually been uploaded through a *running Apache process* (seed data has `images`/`logo_url` as `NULL` everywhere). Fixed with `chmod -R o+w uploads/`. If a fresh clone starts throwing the same upload error, re-run that chmod.

### Mobile API — DSS ("Find My Match") endpoint added
**Files (new):** `api/v1/seeker/recommend.php`, `api/v1/seeker/recommend-options.php`

`includes/dss_helper.php`'s top-of-file comment named `api/v1/seeker/recommend.php` as the intended-but-not-yet-built mobile counterpart to the web's `seeker/recommend.php` — built exactly that. The DSS pipeline itself (`dssRank()`'s Simple Additive Weighting, the rules-then-Groq free-text cascade, Bayesian-shrunk ratings, batched LLM explanations with a template fallback) needed **zero changes** — it was already complete and shared correctly. `recommend.php` just calls it and returns JSON instead of rendering a page, mirroring the web form's field defaults and rules→Groq cascade exactly. Unlike every other file under `api/v1/seeker/`, auth here is **optional** — guests can use it, same as the web version; an `Authorization` header, if present, is only used to attribute the query in `dss_queries` for later analysis. `recommend-options.php` is a small new companion returning the city list + budget bounds for the mobile form (categories are shared with the existing `/categories/index.php`, not duplicated).

Verified against real data: plain query (7 ranked candidates), Tagalog free text ("may anay sa kusina namin" → correctly parsed to Termite Control by the *rule* parser alone, no Groq needed), and `urgency=emergency`+`eco=1` correctly re-weighting results. **Groq is actually enabled on this machine** via a gitignored `config/secrets.php` (not the empty placeholder default in `config/config.php`) — returned explanations are real LLM prose, not just the template fallback.

### `api/v1/providers/show.php` — 500 for every caller, then empty Services/Reviews once fixed
**File:** `api/v1/providers/show.php`

Found while wiring the Flutter app's provider-detail screen up to this endpoint for the first time via a real end-to-end test (not just unit-testing the endpoint in isolation) — but both bugs predate that work and affect the web-consumable JSON shape for any caller, mobile or otherwise:

1. The SELECT referenced `p.phone` — `providers` has no `phone` column; the phone number lives on the linked `users` row (`p.user_id → users.id`, already joined as `u`). Every other file that needs a provider's phone gets this right (`seeker/bookings/show.php`, `admin/bookings/show.php` both correctly select `u_p.phone` from their own provider-side `users` join) — only this file had the wrong alias, and it made the *entire endpoint* throw a fatal `PDOException`, not just omit the phone field. Fixed by selecting `u.phone`.
2. The `listings`/`reviews` sub-queries were missing fields a consumer would reasonably expect: `service_listings.is_eco_friendly` wasn't selected (only `is_emergency_available` was), and `service_reviews.feedback_image` wasn't selected at all despite `seeker/provider-details.php` (the web equivalent) already rendering review photos from that same column (see "Provider Details — Review Images" above). Added both. Also cast `listings[].id` to `(int)` for consistency with the rest of this endpoint's numeric casting (`price`, `is_emergency_available`, etc. were already cast; `id` was the one left as a raw PDO string).

### Mobile API — `admin/providers/reject.php` 500'd for every call
**Files:** `api/v1/admin/providers/reject.php`, `api/v1/admin/providers/approve.php`

Found while building the Flutter admin panel's provider-approval screens, before ever wiring the Flutter side up to it — reproduced with a disposable test provider row first, confirming the bug was in this endpoint and not anything client-side.

`reject.php`'s UPDATE set `providers.status = 'rejected'` — but `providers.status` is `ENUM('pending','active','inactive','suspended')` (confirmed via `DESCRIBE`); there is no `'rejected'` value, so every call threw `SQLSTATE[01000]: ... Data truncated for column 'status'` under this DB's strict SQL mode. The intended "rejected" semantics were already correctly represented elsewhere via `verification_status`, a free-text `varchar(20)` column that `providers/index.php`/`show.php` actually key their pending/approved/rejected filtering and display off of — `status` never needed to change at all. Fixed by dropping `status = 'rejected'` from the UPDATE and leaving it untouched (stays whatever it was, typically `pending`), so a rejected provider isn't misrepresented as `active`/`inactive`/`suspended` either. (`providers/index.php`'s and `show.php`'s own `CASE ... WHEN p.status = 'rejected'` fallback expressions were already effectively dead code for the same reason — `status` can never hold that value — left as-is since they're harmless, just redundant.)

Also fixed the familiar flat-envelope bug in both `approve.php` and `reject.php` (`ok(['message' => ...])` not nested under `'data'` — same class as the `verify.php` fix noted earlier in this log).

### Mobile API — `auth/login.php` centralized to match the web's cascade
**File:** `api/v1/auth/login.php`

Until now, the mobile API's login endpoint only ever checked the `users` table — no `admin_users` or `provider_staff` lookup at all — while the Flutter app worked around this with a completely separate `/admin/login` screen calling `api/v1/admin/auth/login.php` directly, and had no portal-staff login at all. Flagged during the Flutter admin-panel work: the *web* app has centralized login (`auth/login.php`, this same file's web counterpart — see "Centralized login" above) for exactly this reason, and the mobile API should match it rather than fragment by role family.

Rewrote `api/v1/auth/login.php` to run the identical three-tier cascade the web page uses: `admin_users` → `provider_staff` (portal staff) → `users`, by username-or-email for the first two tiers and email-only for the last, falling through to the next tier on a password mismatch rather than failing immediately (so a wrong password never reveals which table, if any, the identifier matched — same as the web). Each tier issues a differently-shaped JWT (`user_type: 'admin'`/`'portal_staff'`/`'seeker'`/`'provider'`, with a `role` claim for the first two and `provider_id` additionally for portal staff) but the response envelope stays flat and role-agnostic at the top level (`token` always present; `admin`/`staff`/`user` and `must_change_password` vary by tier) — mirroring the request field name too (`email`, kept for backward compatibility, though every tier except `users` accepts a username in it).

Verified all three tiers live with real accounts, temp-password round-trips restored after each: admin login by email, portal-staff login *by username* specifically (to confirm the OR-email matching actually works, not just the email path), and — since this touches the most-used login path in the app — a positive seeker login (not just an already-known-working negative/401 case) to rule out any regression.

### Two-date Inspection → Agreement → Working Date flow (opt-in per service)
**Files:** `provider/services.php`, `seeker/provider-details.php`, `seeker/request-service.php`, `provider/service-requests.php`, `seeker/my-requests.php`, `includes/booking_workflow_helper.php`, `api/v1/provider/listings/{store,update}.php`, `api/v1/provider/requests/{index,show,submit-inspection}.php`, `api/v1/provider/dashboard.php`, `api/v1/seeker/bookings/{store,show,index,inspection-respond}.php`, `system/migrations/inspection_working_date.sql`

New opt-in feature (`services.requires_inspection` / `service_listings.requires_inspection`, both default `0`): a service can require an on-site inspection visit before the actual "Working Date" and final price are locked in.

**Flow:**
1. Seeker requests a service as usual, but for an inspection-required service the date they pick is stored as `availed_services.inspection_date` (mirrored into `preferred_date` too, so nothing downstream needs to know about the new column) — the price shown is only an estimate. Booking is created as `pending`, unpaid, exactly like today; **no payment is collected at this stage** (mirrors the web's existing "pay after accept" flow in `seeker/request-service.php`, and the equivalent mobile `store.php` skips its PayMongo checkout call entirely when the listing requires inspection).
2. Provider accepts the request through the existing Accept/Decline gate — nothing new here.
3. A field technician visits the site, then submits an **inspection report** (photo, findings/description, a final price, and a proposed Working Date) via a new "Submit Inspection Report" action in `provider/service-requests.php`'s View Details modal (web) or `POST api/v1/provider/requests/submit-inspection.php` (mobile, multipart). This moves the booking to a new status, `awaiting_agreement`, and stores the report on `inspection_report_image`/`inspection_report_notes`/`inspection_proposed_price`/`inspection_proposed_working_date`/`inspection_submitted_by`/`inspection_round`.
4. The seeker reviews the report on `seeker/my-requests.php` (or via `POST api/v1/seeker/bookings/inspection-respond.php`) and either:
   - **Agrees** — `working_date` and `preferred_date` are set to the proposed date, `total_amount` (and `downpayment_amount`/`remaining_amount`, recomputed) to the proposed price, `inspection_agreed_at` is stamped, and status goes back to plain **`accepted`**. Re-entering `accepted` means the booking is now indistinguishable from a normal accepted booking to every downstream system — Prepare Booking, the dual control-number/QR flow, and critically the *existing* "Pay Now" strip on `my-requests.php` (which is gated purely on `status`+`payment_status`, not on how the booking got there) all pick it up with **zero code changes**. This was a deliberate design choice to avoid adding a fourth parallel status-transition implementation on top of the three (`booking_workflow_helper.php`, `ControlNumberService.php`, `provider/service-requests.php`'s inline handler) already documented earlier in this log as a recurring source of drift bugs.
   - **Requests changes** — a required free-text note is stored in `inspection_change_notes`, status moves to **`revising`**, and the provider gets a notification + email to submit a new report (loops back to step 3; `inspection_round` increments each submission, unlimited rounds, no cap).
5. `statusInfo()`/`statusLabel()`/`statusBadge()`/`monitoringEventIcon()` all gained entries for `awaiting_agreement`/`revising` in both `provider/service-requests.php` and `seeker/my-requests.php`. Both files' step-progress/`$allSteps` fallback-to-index-0 logic (the same bug class already fixed for `completed`/`cancelled` earlier in this log) needed an explicit override for these two statuses too — otherwise a pre-agreement `accepted` booking would show a bogus "Prepare Booking" button before the seeker had even agreed to a price. The "active" status groupings used for seeker-side tab counts/filtering (`seeker/my-requests.php`) and the mobile `active` filter group (`api/v1/seeker/bookings/index.php`) and provider dashboard active-count (`api/v1/provider/dashboard.php`) all needed `awaiting_agreement`/`revising` added too — without it a booking in either status would silently vanish from every tab/count on the seeker side and undercount on the provider dashboard, a bug caught only because it was checked explicitly, not because anything errored.

**Inventory-style checkout-model precedent applied:** as with the equipment checkout/return model earlier in this log, the design deliberately reuses the *existing* payment/workflow machinery instead of writing new parallel logic — the only genuinely new pieces are the report-submission step and the agree/request-changes decision; everything downstream of `status = 'accepted'` is unmodified.

**Two independent catalogs, both needed the flag:** `services` (provider's own web-managed listings, `provider/services.php`) and `service_listings` (a *separate*, independently-managed catalog used only by the Flutter provider app's own listing endpoints, `api/v1/provider/listings/*` — confirmed via grep that nothing syncs one into the other) both needed their own `requires_inspection` column; a provider using the mobile app to manage listings sets it there independently of the web dashboard.

**Verified live end-to-end** with disposable `diag_*` data: service with `requires_inspection=1` → seeker request (inspection_date set, no payment triggered) → provider accept → inspection report submitted (real multipart image upload) → seeker "Request Changes" → provider resubmits (round 2, revised price) → seeker "Agree & Schedule" → booking lands back on `accepted` with the *final* price, and the pre-existing "Pay Now" strip on `my-requests.php` picked it up automatically showing the correct revised amount, confirming the re-entry into the normal payment pipeline works with no dedicated payment code written for this feature.

### Mobile API — provider-portal (HR/Finance/CRM/employee) parity, plus a critical pre-existing bug fix
**Files:** `api/v1/portal/_bootstrap.php`, `api/v1/portal/auth/{login,me,change-password}.php`, new `api/v1/portal/me/**`, `api/v1/portal/hr/leave-requests/**`, `api/v1/portal/hr/leave-balances/**`, `api/v1/portal/hr/payroll/{generate,approve,mark-paid}.php`, `api/v1/portal/finance/inventory/**`, `api/v1/portal/crm/outreach/**`; new `includes/hr_schedule_helper.php`, `includes/timekeeping_helper.php`, `includes/payroll_helper.php`; refactored `provider-portal/timekeeping.php`, `provider-portal/payroll.php`; fixed 14 existing endpoints (see below)

**Critical pre-existing bug found and fixed:** every endpoint under `api/v1/portal/{crm,hr}/**/*.php` two directories deep (e.g. `crm/bookings/index.php`, `hr/attendance/clock-in.php`, `hr/payroll/store.php` — 14 files total) loaded its bootstrap via `dirname(__DIR__, 3)`, which resolves to the *top-level* `api/v1/_bootstrap.php` instead of `api/v1/portal/_bootstrap.php` (should have been `dirname(__DIR__, 2)` — confirmed by comparing against `api/v1/portal/finance/expenses/index.php`, which already used the correct depth). Since these files then call `require_portal_role()`/`portal_require_pro()` (only defined in the portal bootstrap), every single one of them fataled with an uncaught `Error: Call to undefined function` on every call — a raw 500 with an empty body, silently swallowed by `display_errors=Off`. This affected **all of CRM bookings/services and all of HR attendance/recruitment/payroll** — the entire mobile HR/CRM surface was non-functional. Found by testing `hr/attendance/clock-in.php` directly (curl → empty-body 500) while building on top of it; confirmed the exact scope by grepping every portal API file's require-path depth against which ones actually call portal-only functions. Fixed all 14 by correcting the depth to `dirname(__DIR__, 2)`; verified live post-fix (was 500, now correctly 401 unauthenticated / 200 with a valid token). Caught the identical mistake in two of my own new files during this same pass (`me/bookings.php` needed `dirname(__DIR__)`, one level, not two) before it shipped — worth double-checking this depth explicitly on every new portal endpoint, since `dirname(__DIR__, N)` gives no error when N is wrong, just a bootstrap that silently lacks the functions you're about to call.

**Employee login didn't exist on mobile at all.** `api/v1/portal/auth/login.php` previously only checked `provider_staff` (promoted staff) — there was no way for a plain, non-promoted `employees`-table account (created via `provider-portal/employees.php`, not promoted to portal staff) to log in via the mobile API, even though the web's `auth/login.php` has supported this second login tier for a while (see "Provider portal staff" above). Added the matching employee tier to the mobile login (email or `employee_id` code, `temp_password` fallback with same auto-set-`must_change_password` behavior as the web), issuing a `user_type: 'portal_employee'` JWT. Also carried over the "promoted staff may have a linked employee record by email" behavior into the staff JWT (`employee_id` claim) so a promoted staff member can use self-service endpoints under their own staff login too — mirrors `$_SESSION['portal_employee_id']` on the web exactly. Added `require_portal_actor()` to `api/v1/portal/_bootstrap.php` as a unified identity resolver for both account kinds (returns a normalized `account_type`/`staff_id`/`employee_id`/`provider_id`/`role`/`staff_type` shape); `api/v1/portal/auth/me.php` and `change-password.php` now branch on it. The existing `require_portal()`/`require_portal_role()` (staff-only) are untouched and still gate the HR/Finance/CRM-role endpoints.

**New self-service endpoints** (`api/v1/portal/me/`, using `require_portal_actor()`, work for both employee and linked-staff accounts): `attendance/clock-in.php` + `clock-out.php` + `index.php` (own history + today's status), `leave-requests/index.php` (own requests + current-year balances) + `store.php` (own submission, balance-enforced), `payslips/index.php` (own payroll history, read-only), `bookings.php` (field-technician-only: bookings assigned via `assigned_employee_id`, mirrors `provider-portal/my-services.php`). No Pro-tier gate on self clock-in/out, matching the web's "self-service is a basic-tier feature" rule.

**Extracted shared business logic instead of re-implementing it**, to avoid the exact drift-bug class already documented earlier in this log (three independent dual-verification implementations, `employee_code` typo copied across six files, etc.):
- `includes/hr_schedule_helper.php`: `getScheduleForDate()` / `getDefaultWorkSchedule()` — previously duplicated verbatim in both `timekeeping.php` and `payroll.php`.
- `includes/timekeeping_helper.php`: `selfClockIn()` / `selfClockOut()` / `calcTimekeepingTimings()` — extracted from `timekeeping.php`'s inline self time-in/out POST handlers (late/overtime computation, the ±2h working-window gate, writes to both `timekeeping` and `attendance` tables). `timekeeping.php` itself now just calls these; behavior is byte-for-byte identical, verified live both via the web page and the new `api/v1/portal/me/attendance/*` endpoints producing matching output for the same action.
- `includes/payroll_helper.php`: `generatePayrollForPeriod()` (+ `computePayrollPeriodBounds()`, `countPayrollExpectedWorkDays()`, `countPayrollApprovedLeaveDays()`) — extracted from `payroll.php`'s inline `'generate'` action (the full attendance-based computation: daily/hourly rate, absent/late deductions, overtime, statutory deductions). `payroll.php`'s generate handler is now a two-line call into this; verified the web generate action still produces identical output post-refactor.

**New HR endpoints** (`api/v1/portal/hr/leave-requests/`, `hr/leave-balances/`): `index.php` (list + pending/approved/rejected counts), `approve.php` / `reject.php` (balance-enforced on approve, mirrors `provider-portal/leave-requests.php`), `grant.php` (HR directly grants paid leave, auto-approved), `leave-balances/index.php` (per-employee balance map) + `override.php` (per-employee per-leave-type override, mirrors `settings.php`'s "Per-Employee Leave Override" card).

**New payroll workflow endpoints** (`api/v1/portal/hr/payroll/`): `generate.php` (bulk attendance-based generation via the shared helper — the pre-existing `store.php` naive single-record manual-entry endpoint is left as-is for that narrower use case), `approve.php` (pending → processed, Finance-only), `mark-paid.php` (processed → paid, Finance-only). Role gates use `require_portal_role('owner','finance')` for the Finance-only actions and `require_portal_role('owner','hr')` for HR-only ones — note this checks `provider_staff.role`, distinct from the web's `$portal_dept === 'finance' || 'all'` department check; kept consistent with how the rest of the pre-existing portal API already gates (`hr/attendance/*`, `hr/payroll/store.php`) rather than introducing a third auth convention.

**New inventory endpoints** (`api/v1/portal/finance/inventory/`): `index.php` (live-computed `available_now`/`checked_out`/`is_low_stock` per item using the equipment checkout/return model from the section above), `store.php` / `update.php` (Reference No. and a positive unit price required, mirrors the web validation exactly), `archive.php` (soft delete).

**New CRM outreach endpoints** (`api/v1/portal/crm/outreach/`): `customers.php` (past-customers-with-a-completed-booking list + provider's service catalog, for building a compose screen), `send.php` (send to selected past customers with an optional featured-service clickable link; re-validates both the service id and every recipient id server-side against this provider's own data — never trusts either from the request body, mirrors the web's comment-documented defense exactly), `history.php` (recent send log).

**Verified live end-to-end** with disposable `diag_*` data covering every new area: employee login (temp-password path) → self clock-in (late-minute computation) → duplicate clock-in correctly rejected → self clock-out (undertime computation) → attendance history; self leave request submission → over-balance request correctly rejected → HR list/approve (balance deducted) → HR grant paid leave for a different employee → HR per-employee override (verified the new cap took effect); field-technician `me/bookings.php` correctly scoped (non-field employee correctly gets 403); payroll generate → HR-attempting-Finance-action correctly rejected (403) → Finance approve → Finance mark-paid → double-mark-paid correctly rejected; inventory add (missing reference_no correctly rejected) → list (availability/low-stock computed correctly) → update → archive; CRM outreach customer list → send (real email, logged to history) → spoofed non-customer recipient correctly rejected. Also re-verified all 14 previously-broken endpoints now return correct auth errors instead of raw 500s, and re-tested the refactored web `timekeeping.php`/`payroll.php` pages directly (not just the new API) to confirm the shared-helper extraction didn't change behavior.

### Inventory adds/restocks now log as Finance expenses
**Files:** `includes/inventory_expense_helper.php` (new), `provider-portal/inventory.php`, `api/v1/portal/finance/inventory/{store,update}.php`, new `api/v1/portal/finance/inventory/restock.php`

User noticed adding stock in the Finance inventory register never showed up in the actual expense ledger (`expense_records`, used by `provider-portal/expenses.php`) — the two features were completely disconnected, so a purchase had to be logged twice by hand with nothing enforcing that it actually was.

**Design decision (discussed with the user before building):** auto-expensing on every inventory **edit** was rejected in favor of a dedicated **Restock** action, separate from Edit. The reasoning: diffing old-vs-new `quantity_available` on save is implicit and fragile — an owner correcting a data-entry overcount downward, then fixing it back up later, would misfire as two "purchases." Splitting the actions makes the money-spent moment explicit and matches this feature's existing traceability posture (`reference_no` is already mandatory for the same reason).

- `includes/inventory_expense_helper.php`'s `recordInventoryPurchaseExpense()` inserts one `expense_records` row (category `Inventory`, `expense_type` `Inventory Purchase`, `amount = quantity × unit_price`, `paid_to` = supplier, `receipt_number` = the item's `reference_no`) — a no-op if quantity or price is 0/negative, so adding an item with a starting quantity of 0 (or one whose price wasn't set yet) correctly logs nothing.
- **Add** (`provider-portal/inventory.php`'s `'add'` action, `api/v1/portal/finance/inventory/store.php`): calls the helper with the item's starting `quantity_available` right after insert.
- **Restock** (new `'restock'` action on the web page, new `restock.php` on mobile): the *only* way to increase `quantity_available` now — takes just an `add_quantity`, increments the column, and expenses that increment at the item's *current* unit price (so a restock after a price change correctly costs more/less than the original purchase, verified live: item added at ₱1,200/unit, price edited to ₱1,300, then restocked — the restock expensed at ₱1,300, not the stale ₱1,200).
- **Edit** (`'edit'` action / `update.php`) no longer accepts `quantity_available` at all — the SQL `UPDATE` doesn't even reference the column anymore, and the web form hides the quantity field entirely when editing (shown only when adding). Verified live that POSTing a spoofed `quantity_available=999` on an edit is silently ignored — quantity stays untouched, no expense row created.
- Web UI: table gained a "Restock" icon button (truck icon) next to Edit, opening a small dedicated modal (item name display + quantity-to-add field only) that explicitly notes "This will be logged as an expense."

Verified live end-to-end on both web and mobile: add-with-quantity → one expense row appears, correctly filterable under `expenses.php?cat=Inventory`; add-with-zero-quantity → no expense row; edit (including an attempted quantity injection) → quantity and expense count both unchanged, price update still applies; restock at a since-changed price → quantity increases correctly and a second expense row appears at the new price, not the original.

### Pricing Model (Fixed/Hourly/Custom Quote) was collected but never saved or acted on
**Files:** `provider/services.php`, `api/v1/provider/listings/{store,update}.php`

User asked how the three pricing models work; investigation found the `pricing_type` dropdown on Add Service was decorative — collected, marked `required`, but **missing from the `INSERT INTO services (...)` column list entirely**, so every new service silently got the DB default (`fixed`) no matter what was picked. The Edit form didn't even have a pricing-type (or Category) field at all. `category` had the identical bug — required on Add, validated server-side, never written to either `INSERT` or `UPDATE`. Separately (found while fixing this), the page's own main listing query was missing `category`/`pricing_type`/`requires_inspection` from its `SELECT` — meaning the "Requires Inspection" badge added earlier in this log had been silently rendering as false for every row since it was built, because the column was never fetched.

**Design decision (discussed with the user):** rather than building real hourly-rate arithmetic (rate × hours) — meaningless here since this app has no hours-worked tracking — Hourly and Custom Quote both simply **force `requires_inspection = 1`**, reusing the Inspection → Agreement → Working Date flow documented earlier in this log to let the provider set the real price afterward. Fixed is the only model where the listed price is charged as-is. The only difference between "Hourly" and "Custom" is now purely the label shown to the seeker; both determine the final price the same way.

- Both `add_service` and `edit_service` handlers now validate `pricing_type` against the ENUM, write `category`/`pricing_type` to the DB, and compute `requires_inspection = ($pricing_type !== 'fixed') ? 1 : $checkbox_value` — server-side, so the client-side lock (below) can't be bypassed by a direct POST. `edit_category` also gained the same "required" validation Add already had (previously unvalidated *and* unsaved).
- Web UI: the Pricing Model `<select>` (now `id`'d on both Add and Edit) drives a new `onPricingTypeChange(prefix)` JS function — picking Hourly/Custom auto-checks and disables the "Requires On-Site Inspection First" checkbox (can't be unchecked while non-fixed is selected), relabels the price field to "Estimated Rate/Price," and swaps the inspection hint text to explain why it's locked. Switching back to Fixed re-enables the checkbox as an independent, optional choice (a Fixed-price service can still opt into inspection for unrelated reasons — that flag predates this feature).
- Edit form gained the previously-missing Category and Pricing Model fields entirely; the `.settings-btn` row buttons gained `data-category`/`data-pricing-type` attributes so the edit modal now populates them (and re-runs the lock) on open.
- Main listing query fixed to actually `SELECT` the three columns; added a small pricing-model label under the price badge in the table ("Hourly (estimate)", etc.) for visibility.
- Applied the identical `pricing_type !== 'fixed' → force requires_inspection` rule to the mobile API's `service_listings` catalog (`api/v1/provider/listings/store.php`/`update.php`) for parity — `update.php`'s version accounts for its partial-update pattern (computes the *effective* pricing_type as new-value-if-provided-else-existing-row's-value, so switching pricing_type on one request or leaving it untouched on another both still enforce correctly).

Verified live: adding an Hourly service with the inspection checkbox left unchecked still saved `requires_inspection=1`; editing it to Fixed with the checkbox off correctly saved `requires_inspection=0`; editing it back to Custom forced `requires_inspection=1` again regardless of checkbox state; omitting category on Edit is now correctly rejected (previously silently accepted and discarded); the listing table now correctly shows both the "Requires Inspection" badge and the pricing-model label.

### Chat became transaction-scoped (one thread per booking, closed when the booking ends) instead of lifetime-per-user
**Files:** `includes/transaction_chat_helper.php` (new), `seeker/messages-seeker.php`, `provider/messages-provider.php`, `provider-portal/portal-messages.php`, `provider/service-requests.php` (its inline send-message widget), `includes/header.php` (notification bell), `seeker/provider-details.php`, `seeker/my-requests.php`

Every chat surface previously grouped `messages` purely by `(sender_id, receiver_id)` pair — one open-ended thread per relationship for the life of the account, with no idea which booking (or whether any booking at all) a conversation was about. `messages.request_id` existed in the schema from the very start but was **never once written or read anywhere** — the exact same "collected but dead" pattern as `pricing_type` earlier in this log. User's ask: scope chat to one thread per transaction (booking), auto-close it once that booking is `completed`/`cancelled`, and always show the service + status in the thread so a seeker never lands in an unlabeled chat.

**Design decisions locked in before building:**
- No chat until a booking exists (no more pre-sale "message this provider" from a public profile — chat only opens once a request is submitted).
- A thread closes immediately when its booking reaches `completed` or `cancelled` — no grace period.
- A seeker with multiple bookings against the same provider gets one thread per booking, listed separately (not merged, not "only the newest").
- Scoped consistently everywhere: seeker↔provider AND the provider-portal CRM/technician surface, not just the primary flow.
- Real-time: the user initially asked for a true WebSocket server; a Node process (`system/realtime/server.js`, `ws` package, HMAC-token channel auth) was actually built and verified working end-to-end, but was then deliberately scrapped in favor of **short-interval polling** (`setInterval` calling each page's own `?poll=1` endpoint every 4s, paused via the Page Visibility API when the tab isn't focused) once the user reconsidered — a persistent Node process has no story on ordinary shared PHP hosting, which is this app's actual deployment target. All WS code, the `channel_secret.txt`/gitignore entries, and the running dev process were removed; nothing in the final implementation requires anything beyond what Apache+PHP already provides.

**`includes/transaction_chat_helper.php`** is now the one place every chat surface routes through: `chatIsOpenForBooking()`, `getBookingChatContext()` (status/price/payment/service for the header), `getSeekerBookingThreads()` / `getProviderBookingThreads()` (one row per `availed_services` booking — every booking is a selectable thread even with zero messages yet, not just ones that already have activity), `getBookingMessages()` / `getBookingMessagesSince()` (the latter powers polling), `sendBookingMessage()` (the single choke point that enforces "closed once completed/cancelled" and writes `request_id`), and `markBookingMessagesRead()`.

**Each of the three rewritten pages** now: lists booking-scoped threads with a status dot + service name + status label + last message; shows a header banner with live service/status/price/payment info; disables the input entirely (not just the send button) once `chatIsOpenForBooking()` is false, replaced with a "this conversation is closed because the service is X" bar; and polls its own `?poll=1&booking=X&since=Y` endpoint for new messages + status changes, reloading the panel only when status actually changes (so a stray poll tick doesn't interrupt an in-progress reply).

**In-chat actions reuse the real booking-mutation code, not new copies of it** (explicit user requirement — "will that automatically change what its actual status, price, or even if it's paid or not?"):
- **Seeker "Pay Now"** — shown whenever the same `needsInitialPayment`/`needsRemainingPayment` predicate `seeker/my-requests.php`'s pay-strip already uses is true; the button is a plain `target="_blank"` link straight to the existing `payment-redirect.php?booking_id=X`, so it opens the real PayMongo checkout in a new tab without ever leaving the chat tab or duplicating any payment logic.
- **Provider Accept/Decline** — shown as a banner on a `pending` booking's thread; the AJAX handler calls the exact same `acceptAvailedBooking()` helper the main Accept button on `provider/service-requests.php` calls, so accepting from chat is functionally identical to accepting from the table.
- **Anything requiring richer UI** (equipment picker, QR scan, inspection-report photo upload) is intentionally *not* re-embedded in chat — a "Manage Booking" link opens `provider/service-requests.php?open_booking=X` in a new tab, which now auto-clicks that booking's existing View Details button on load (new `data-avail-id` attribute + a small onload script) so the provider lands straight on the right modal instead of the plain table. Rebuilding the equipment/QR/photo UI a second time inside the chat panel was judged not worth the duplication risk for this pass.
- Provider-portal (CRM/technician/owner) chat deliberately has **no inline status actions** — portal staff other than the owner don't carry the `$_SESSION['user_id']`/`user_type='provider'` session `provider/service-requests.php` requires (only the separate `portal_staff_id` namespace), so a deep link there would just bounce non-owner staff to login. That surface stays a live, transaction-scoped, read/reply chat with full status/price/payment visibility, without the accept/decline shortcut.

**Bugs found and fixed while doing this (all pre-existing, unrelated to the chat feature itself, but directly in its path):**
- `provider-portal/portal-messages.php`'s AJAX send routed through a separate file, `api/messages.php`, which had **its own independent copy of the view/reply access-control check** — and that copy was missing the `$is_field_tech` clause that had been added to `portal-messages.php`'s own page-level check earlier in this session. Net effect: a field technician's chat page correctly *showed* them a reply box, but every message they tried to send through it silently 403'd from the separate file's stricter check. Consolidating both pages onto `sendBookingMessage()` — no `api/messages.php` dependency anymore — removed the second copy of this logic entirely rather than just patching the missing clause, so it can't drift out of sync again. Verified live: a field-technician account that previously would have failed to send now sends successfully.
- `includes/header.php`'s notification-bell message tab queried `messages.provider_id` and `messages.seeker_user_id` — **neither column exists on that table** (its real columns are `sender_id`/`receiver_id`/`request_id`). The query has been silently throwing and getting swallowed by a bare `catch (Exception $e) {}` since the bell was built, so the Messages tab has never once shown a real message notification. Fixed by joining through `messages.request_id → availed_services → providers` (now that `request_id` is finally populated) and pointing the link at the new `messages.php?booking=X` instead of the provider's public profile page. Verified live: the bell now correctly surfaces an unread message and links straight to that booking's thread.
- `seeker/messages-seeker.php` and `provider/messages-provider.php` both had a stray UTF-8 BOM before their opening `<?php` tag (pre-existing, present before any edits this session). Three bytes of body output before any `header()` call is exactly the kind of thing that silently corrupts an AJAX JSON response's `Content-Type` — found because the new `?booking=`/send endpoints on these exact two files are what finally exercised that code path with a real `header('Content-Type: application/json')` call. Stripped from both files.

Also found, left alone as clearly out of scope: `provider-portal/messages.php` (a *third*, differently-named file, distinct from `portal-messages.php`) is dead code — nothing in the app links to it (the sidebar links to `portal-messages.php`), and its own header comment ("seeker side — in Pestify root") doesn't even match what the file actually is, suggesting it's a stale leftover from before `portal-messages.php` existed. Not deleted (not asked to), but worth knowing it's inert if anyone goes looking at it.

**Verified live end-to-end** with disposable `diag_*` data: seeker messages a `pending` booking (no payment triggered) → provider sees it with an Accept/Decline banner → provider accepts inline from chat (real status change, confirmed in DB) → seeker's thread immediately shows a "Pay Now" banner with the correct amount, linking to the real PayMongo redirect → provider replies → seeker's poll endpoint correctly returns the new message with the right `mine`/status fields → booking marked `completed` → seeker's next send attempt is correctly rejected server-side, and reloading the thread shows the closed bar with the input fully removed (not just disabled) → CRM staff viewing the same closed booking correctly sees it as closed, and viewing a separate *active* booking correctly gets a working reply box → a field-technician account (the specific role that was silently broken before) successfully sends a message end-to-end.

### `services` and `service_listings` centralized into one table
**Files:** DB schema (`services`, `service_categories` FK, `service_listings` → `service_listings_archived`), `api/v1/provider/listings/{store,update,delete,index}.php`, `api/v1/listings/{index,show}.php`, `api/v1/providers/{index,show}.php`, `api/v1/admin/providers/show.php`, `api/v1/provider/dashboard.php`, `api/v1/provider/requests/{index,show}.php`, `api/v1/seeker/recommend-options.php`, `api/v1/seeker/bookings/{store,show}.php`, `api/v1/admin/bookings/{index,show}.php`, `api/v1/portal/crm/bookings/{index,show}.php`, `api/v1/portal/crm/services/{index,store,update}.php`, `admin/{dashboard,get_user_details,listings,providers,services}.php`, `browse/{listing-details,providers-listings}.php`, `includes/dss_helper.php`, `index.php`, `provider/{services,providers-dashboard}.php`, `seeker/{my-requests,provider-details,providers,recommend}.php`, `provider-portal/{services,crm-services}.php`

Pestify had two fully independent tables for the same real-world thing — a provider's service offering: `services` (the main web provider-management flow, seeker booking flow, provider-portal CRM) and `service_listings` (the homepage, browse pages, admin moderation, the DSS recommendation engine, and the entire mobile API). Nothing synced them. A service created through one channel was invisible to — and unbookable from — the other. Worse, `availed_services.service_id` was written by two different code paths with two different meanings (a web-originated booking stored a `services.id`, a mobile-originated booking stored a `service_listings.id`) with nothing on the row saying which; **13+ files** joined `service_id` back to only one side, each silently wrong for roughly half of all bookings depending on origin. `provider/providers-dashboard.php` already had a hand-rolled dual-`LEFT JOIN` band-aid for this — proof the bug had already been hit and patched locally once, not fixed at the root.

**Migration (one-time, non-destructive):** `services` gained `category_id` (FK → `service_categories`), `is_eco_friendly`, `is_emergency_available`, `images`, `views_count` (the columns `service_listings` had that `services` didn't), and `pricing_type` was widened to a strict superset ENUM. The 4 pre-existing `services` rows were backfilled a `category_id` from their old free-text `category` column via a fixed map. All 7 `service_listings` rows were migrated into `services` via individual `INSERT` statements (not `INSERT...SELECT`, specifically to capture each new auto-increment id for remapping). The one `availed_services` row whose `service_id` fell in the old `service_listings` id range was updated to point at the corresponding new `services.id`; the other bookings already pointed at real `services` ids and were untouched. `service_listings` was renamed to `service_listings_archived` (not dropped) as a reversible safety net. A full `mysqldump` backup was taken before any of this ran.

**Mobile API contract preserved deliberately.** The Flutter app (`~/Documents/babadimobi`) parses literal JSON keys like `listing['title']` from these endpoints. Since `services`' real column is `service_name`, every mobile-facing `SELECT` that used to read `service_listings.title` now aliases `service_name AS title` so the JSON response shape is byte-identical post-migration — confirmed by grepping the actual Flutter source for every literal key read off a listing-shaped response before touching each file, not by assumption.

**Web-side write path also updated:** `provider/services.php`'s Add/Edit forms previously wrote a free-text `category` column via a hardcoded 5-option `<select>` (see the "Pricing Model" entry above for the sibling bug where `pricing_type` itself was once collected-but-never-saved). Replaced with a real category picker sourced from `service_categories`, writing `category_id` server-side-validated on both Add and Edit. `provider-portal/services.php` / `crm-services.php`'s read-only category display was updated to `LEFT JOIN service_categories` on the new `category_id` instead of reading the now-stale free-text column.

**Band-aids from earlier in this session removed** now that there's only one catalog: the `$mobileOnlyServices`/"Contact to Book" fallback branch in `seeker/provider-details.php`, the `$providerServices` fallback-to-`service_listings` block + `source: 'mobile'` branching in `seeker/providers.php`, and the dual `LEFT JOIN services ... LEFT JOIN service_listings ...` in `provider/providers-dashboard.php` (collapsed to a single join).

**Verified live end-to-end** with disposable `diag_*` data (all cleaned up after): a service created via the mobile API (`api/v1/provider/listings/store.php`) showed up correctly on `browse/listings.php`, `seeker/providers.php`, `admin/services.php`, and `admin/listings.php` — then, the actual point of the whole migration, **was successfully booked through the web's own `seeker/request-service.php` flow**, landing in `availed_services` with `service_id` pointing at the same unified `services` row, and rendering correctly on both `seeker/my-requests.php` and `provider/service-requests.php`. Previously this cross-channel booking was structurally impossible. Also verified: the web category picker's Add Service write path saves the real `category_id`; no PHP fatals across the ~20 touched files (lint sweep + live curl smoke tests with real admin/provider/seeker sessions).

### Inspection-required services were asking the seeker to commit a payment plan before any inspection happened
**Files:** `seeker/provider-details.php` (web), `pestify_flutter/lib/features/seeker/screens/book_service_screen.dart` (mobile — path `~/Documents/babadimobi/lib/...`)

User noticed: for a non-fixed-price service (`pricing_type` Hourly/Custom, which per the "Pricing Model" log entry above always forces `requires_inspection=1`), both the web request modal and the Flutter booking screen still showed the Payment Method step (Full Payment / Downpayment, including a required peso amount for the latter) and required picking one before the request could be submitted — even though the price at that point is explicitly labeled an estimate, pending a technician's on-site inspection. Nothing was actually charged (`seeker/request-service.php` and `api/v1/seeker/bookings/store.php` both already correctly create the booking as `pending`/unpaid regardless), but locking in a payment plan against a number that's about to change is confusing/wrong UX and contradicts the documented Inspection → Agreement → Working Date design (payment is only supposed to be collected after the seeker agrees to the inspection report's real price and the booking re-enters plain `accepted`).

**Web fix:** the Payment Method block (buttons + downpayment fields) is now wrapped in `#paymentMethodSection` and hidden via `openModal()` whenever `requiresInspection` is true, replaced with a blue `#inspectionPaymentNotice` box ("No payment needed yet — a technician will inspect on-site..."). `validateForm()` no longer requires a payment method (or a downpayment amount) for these services — a new `formRequiresInspection` module-level flag, set in `openModal()`, gates both the missing-fields check and the downpayment-specific validation block.

**Mobile fix — this one had a real functional bug, not just UX:** `book_service_screen.dart` never knew about `requires_inspection` at all (only received `listingId` via GoRouter `extra`), so it always showed the payment cards. Worse, its `_submit()` handler treated `checkout_url == null` in the API response as an unconditional error ("Unexpected server response. Please try again.") — but `api/v1/seeker/bookings/store.php` **deliberately** returns `checkout_url: null` (with `requires_inspection: true`) for these bookings, since there's no PayMongo checkout to create yet. Net effect: submitting an inspection-required booking from the Flutter app always showed a false error message even though the booking had actually saved successfully server-side, with no way for the seeker to tell it had worked. Fixed by having the screen call `SeekerApi.getListingDetail()` on load to read the real `requires_inspection` flag (API returns it as the string `"1"`, not a bool — handled defensively), hide the Payment Method cards the same way the web does, and — the actual bug fix — branch the submit handler on `result['requires_inspection'] == true` to show a success snackbar and navigate to `/seeker/bookings` instead of erroring, while still correctly surfacing a real error for the *other* `checkout_url: null` case (an actual PayMongo failure, distinguishable by the response's `paymongo_error` key / absence of `requires_inspection`) so that failure mode isn't silently swallowed.

Verified: `flutter analyze` clean on the edited file (only pre-existing, unrelated style `info` lints remain); live curl against `api/v1/listings/show.php` confirmed `requires_inspection` comes back as the string `"1"`, matching the defensive type check added in `_loadListing()`.

### Field technicians couldn't see a booking until "Prepare Booking" ran, even when their service already had them as the default handler
**Files:** `provider-portal/my-services.php`, `provider-portal/includes/employee-dashboard.php` (the "My Services Calendar" widget), `api/v1/portal/me/bookings.php`

User's exact scenario: booking accepted by the provider, a field tech already set as `services.assigned_staff_id` (the service's default handler) for the service that booking is for — but the tech's own pages showed nothing. Root cause: `assigned_staff_id` is only a *default suggestion* — it only gets copied onto a specific booking's `availed_services.assigned_employee_id` when the provider explicitly runs **"Prepare Booking"** (`provider/service-requests.php`, `accepted → preparing`). Every tech-facing page queried strictly on `assigned_employee_id`, so a booking sitting at `accepted` — deliberately not yet Prepared — was invisible to the tech, correctly by the *original* design, but the user pushed back: a tech should see it's coming as soon as it's accepted, not only once the provider does the extra assignment step.

**Design decision:** rather than changing when `assigned_employee_id` gets set (would touch the Prepare Booking flow and its equipment-assignment semantics), widened every tech-facing read to match a booking two ways — confirmed (`assigned_employee_id` set) or tentative (`LEFT JOIN services s ON s.id = av.service_id`, `s.assigned_staff_id` matches) — with `pending` bookings excluded from both paths since the provider hasn't committed to the job yet. Applied identically to `my-services.php`, its mobile API mirror `api/v1/portal/me/bookings.php`, and — found while chasing the user's specific "why isn't it in my calendar" follow-up — `provider-portal/includes/employee-dashboard.php`'s "My Services Calendar" widget (a completely separate code path reached when `portal_account_type === 'employee'`, `provider-portal/dashboard.php` delegates to it and exits before ever reaching the owner/staff dashboard code that shares nothing with it), which had the exact same `assigned_employee_id`-only gap in both its calendar grid query and its "Upcoming Assigned Services" stat count.

Tentative-vs-confirmed is surfaced visually so a tech can't mistake one for the other (equipment/notes aren't finalized until Prepare Booking actually runs): an amber "Tentative" badge on `my-services.php`'s cards, and a dashed border + trailing `*` + explanatory legend line on the calendar widget's chips.

**Follow-up ask: "make it clickable."** The calendar chips (`my-services.php` already had full detail cards; the calendar widget only ever showed a truncated one-line chip with a hover tooltip, no click interaction at all) got a lightweight custom modal — `data-*` attributes carry the full row (service, client, contact, date/time, status, address, notes, tentative flag) so no extra request is needed; JS populates and opens a modal on click, with a "View in My Assigned Services" link and (for tentative entries) an explanatory note about Prepare Booking. Required widening the calendar query's SELECT list to include `contact_number`/`address`/`operations_notes`, which it hadn't needed before when it only rendered a time+name chip.

Verified live end-to-end with a disposable diag field-tech employee (provider 60) and a temporary reassignment of `services.assigned_staff_id` (both reverted/deleted after): confirmed the exact real booking that prompted this report (`accepted`, Sept 25, `assigned_employee_id` still NULL) now renders on both `my-services.php` and the employee-dashboard calendar with the correct data, the dashed/`*` tentative styling, and — via a raw HTML fetch of the logged-in tech's dashboard — the full modal markup and all `data-*` attributes populated correctly for that specific booking.

### Field technicians can now actually process their assigned bookings, not just view them
**Files (new shared logic):** `includes/booking_workflow_helper.php` (`verifyProviderSeekerCode()`, `submitProviderInspectionReport()`, `advanceAvailedServiceStatus()`)
**Files (refactored to use the above):** `provider/service-requests.php`
**Files (new tech-facing UI):** `provider-portal/my-services.php`

Direct follow-up to the two entries above: once a tech could *see* their assigned booking, the next question was "why can't they do anything with it — the assigned staff should be the one who processes the service." The provider's own View Details modal (`provider/service-requests.php`) has 13 action buttons, but none of them are reachable by a tech's session — that page requires `$_SESSION['user_id']`/`user_type='provider'` (the owner's own web login), while a field tech logs in through a structurally different `portal_employee_id` session. Linking to the page directly would just bounce them to login, the same reason chat had no inline actions for non-owner staff (see the chat entry earlier in this log).

**Scoped with the user first** (not "all 13 buttons blindly"): confirmed the tech should get the actual on-site processing actions — Enter Seeker Code / Scan QR (the dual-verification handshake that starts the service), Advance status (marking the on-site work done), and Submit/Resubmit Inspection Report — plus messaging (already fully working for field techs via `portal-messages.php`, see the chat entry — just needed a link added). Explicitly *not* in scope: Accept/Decline, Prepare Booking, Archive, Accept Emergency — business/dispatch decisions that stay the owner's call.

**Extracted rather than duplicated.** `provider/service-requests.php` had three of these as large inline POST handlers (`verify_control_number` ~115 lines, `submit_inspection_report` ~115 lines, `update_status` ~160 lines) — copying them into a second page would have been exactly the "independently-drifting third/fourth implementation" bug class this log has already hit twice before (dual control-number verification, and the `pricing_type`/`category` collected-but-never-saved pattern). Instead, each was lifted verbatim into a new shared function in `booking_workflow_helper.php` (in a new "Provider-portal action helpers" section), and `provider/service-requests.php`'s own three handlers were rewritten to just call them — confirmed behaviorally identical (same variable names/flow for the page's existing flash-message rendering) before moving on. `update_status`'s ad-hoc old/new-status gating logic (it predates `transitionBookingStatus()`/`isValidTransition()` above it in the same file and was never unified with them) was preserved exactly as-is rather than also unifying it during this pass — a real but separate, riskier refactor for another day.

**A genuinely tentative-vs-confirmed nuance surfaced here too:** for a `requires_inspection` service, the *first* action a tech takes (submitting the inspection report) happens while the booking is still only tentatively theirs (`assigned_employee_id` still NULL — only `services.assigned_staff_id` matches) — and that submission is itself what sets `assigned_employee_id`, confirming the assignment. So the ownership check used by every new POST handler in `my-services.php` matches the *same* confirmed-or-tentative condition as the page's own listing query, not just confirmed. Distinguishing "needs inspection" from "already agreed, ready for the code handshake" — both of which are the plain `accepted` status — required adding `inspection_agreed_at` to the query: NULL means show the inspection button, set means show Enter Code instead.

**A real, separate pre-existing bug found while testing this:** `submitProviderInspectionReport()`'s seeker-notification insert used `type='inspection'` — but `notifications.type` is a strict `ENUM('request','message','review','payment','system','promotion')` with no `'inspection'` value, so the insert silently failed under this DB's `STRICT_TRANS_TABLES` mode every single time, swallowed by a bare `try/catch`. This was already broken in the *original* inline handler before this extraction (faithfully preserved, not introduced) — caught only because verifying the extraction's behavior meant checking the notification actually landed. Fixed to `'request'`, the closest valid value.

**Verified live end-to-end** with disposable data, exercising the real page's own POST endpoints (not a bypass script) end to end: a confirmed-assignment booking on a `requires_inspection` service walked through Submit Inspection Report (real multipart image upload) → simulated seeker agreement → Enter Seeker Code (via `verify_control_number`, correctly advancing to `starting` with a fresh `qr_token`) → Scan Seeker QR (correctly advancing to `on_going`) → Mark Job Done (correctly advancing to `waiting_provider_confirmation`, self-correcting away from `waiting_remaining_payment` since the test booking wasn't a partial payment) — with `availed_service_status_history` correctly attributing every step to the technician (`changed_by_role='field_technician'`) rather than the generic `'provider'` the original handlers always used. All diagnostic rows and the disposable employee account deleted afterward; two tiny orphaned test-image files under `uploads/inspections/provider_60/` could not be removed (Apache/`daemon`-owned, permission denied for the dev shell user) — harmless, but worth a manual cleanup if anyone notices them.

### `.htaccess` — every legacy root-level URL rewrite was silently dead, app-wide
**File:** `.htaccess`

User reported `payment-success.php?booking_id=X` (PayMongo's own return-to-merchant URL, built via raw `SITE_URL . "/payment-success.php?..."` string concatenation in five different files — see `seeker/payment-success.php`'s own top-of-file comment) showing a plain Apache "Object not found!" page after paying for a booking that had gone through the Inspection → Agreement flow. That specific booking/flow turned out to be irrelevant — the bug is completely general.

**Root cause:** the very first rule in the file,
```
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization},L]
```
has the pattern `.*`, which matches literally every request, and carries the `[L]` ("last rule") flag. `[L]` stops rule processing for the current pass — and since this rule matches unconditionally, it fired first on *every single request* and immediately stopped Apache from ever evaluating any rule below it. Every other `RewriteRule` in the file — every legacy root-level page alias (`my-requests.php`, `payment-cancel.php`, `payment-redirect.php`, `services.php`, etc. — the entire "URL routing" scheme this CLAUDE.md documents above), and the static-asset-from-moved-subfolder rules — has been unreachable dead code for as long as this line existed in its current form. This had gone unnoticed because every single verification pass in this session's long history tested pages via their *real* subfolder path directly (`seeker/provider-details.php`, `provider/service-requests.php`, etc.), never once through a legacy root-level alias — the one thing that finally exercised this path was PayMongo's own hardcoded redirect, which has no choice but to use the root-level form.

**Confirmed methodically before touching anything:** verified `mod_rewrite` was loaded (`httpd -M`) and `.htaccess` itself was being read at all (a temporary `Header set` test directive took effect); ruled out `AllowOverride`/config-level causes (`apachectl configtest` clean, restarted Apache entirely — no change); proved the file's OWN rewrite rules were categorically inert by testing a brand-new trivial rule appended at the very end (still 404'd) versus the identical rule in a fresh, isolated test `.htaccess` in a scratch subdirectory (worked immediately) — isolating the bug to something specific to this file's rule *ordering*, not the server environment.

**Fix:** dropped the `,L` flag from that one rule — it only ever needed to set an environment variable via `[E=...]`, never to rewrite the URL or terminate the chain, so there was no reason for it to block anything after it.

**Verified live post-fix:** the exact reported URL (`payment-success.php?booking_id=72`) now correctly chains `payment-success.php` → (rewritten) `seeker/payment-success.php` → (302) `seeker/payment-success-result.php?booking_id=72` → (302, no session in the test) `auth/login.php` → 200 — matching the intended design exactly. Re-tested every other legacy root alias (`my-requests.php`, `payment-cancel.php`, `login.php`, `services.php`) and the static-asset subfolder rewrite — all now correctly reachable. Also re-confirmed the rule's *actual* original purpose still works: a real JWT sent as `Authorization: Bearer <token>` to a live `api/v1/` endpoint is still correctly received and validated (both a garbage token → 401 "Token invalid", and a real signed token → 200 with real data) — removing `[L]` didn't regress the one thing this rule exists for.

### `seeker/payment-success-result.php` leaked the provider's dual-verification code in plain text
**File:** `seeker/payment-success-result.php`

Direct follow-up to the `.htaccess` fix above — once the user could actually reach this page (previously 404ing), they immediately noticed it displays the **provider's** control number in plain text right on the payment receipt, labeled "Provider Code" with "Enter this when the technician arrives." This defeats the entire point of the dual-code handshake: the design (see "Dual Control Number Verification" earlier in this log) deliberately requires the seeker to receive the provider's code *verbally, from the technician, in person* — that's what proves it's genuinely that technician, not an impostor who already knows the code from the app. `seeker/my-requests.php` already gets this right: it fetches `provider_control_number` server-side (needed to know *whether* a code exists) but only ever renders a static `••••••••••` placeholder, never the real value, with copy reading "get this from your technician when they arrive."

`payment-success-result.php` is a completely separate page from `my-requests.php` (no shared template) and was never brought in line with this pattern — it `echo htmlspecialchars($providerCn)`'d the real value directly. Fixed to match `my-requests.php` exactly: same masked `••••••••••` display, same "get this from your technician when they arrive" copy. The seeker's *own* code (which they're supposed to give to the technician) is unaffected and still shown in plain text, correctly — only the provider-side code was leaking. `$providerCn` is still computed/held server-side (used for the `!== ''` existence check that decides whether to show the widget at all), it's just never echoed into the response anymore, same as the already-correct file.
