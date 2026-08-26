# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Pestify is a PHP-based pest control service marketplace running on XAMPP. Seekers book pest control services; providers list and manage those services; admins oversee the platform.

**Stack:** Vanilla PHP (no framework), MySQL via PDO, PHPMailer (Composer), PayMongo payments. No build step, no test suite.

**Mobile API:** A Flutter app is being built against `api/v1/` — a dedicated REST layer with JWT auth (HS256, 30-day tokens). See `FLUTTER_PLAN.md` for the full endpoint map, booking flow, and Flutter implementation notes.

**Local URL:** `http://localhost/pestify` (XAMPP — project lives at `C:/xampp/htdocs/Pestify/`)

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

`availed_services` booking-flow columns of note: `status` (ENUM), `provider_control_number`, `seeker_control_number`, `provider_verified_at`, `seeker_verified_at`, `dual_verified_at`, `qr_token` (6-char, set on `starting`), `qr_scanned_at` (set when provider scans/enters the token).

### Booking workflow helper

`includes/booking_workflow_helper.php` is the canonical source for status transitions. Key functions:

| Function | Purpose |
|----------|---------|
| `transitionBookingStatus($pdo, $id, $newStatus, ...)` | Validates transition, writes history, sets timestamps |
| `generateQrToken($availedId)` | Returns a 6-char token from charset `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (no ambiguous chars) |
| `scanQrAndStartService($pdo, $token, $staffId)` | Looks up token on a `starting` booking, transitions to `on_going` |
| `isValidTransition($from, $to)` | Whitelist of allowed status moves |

Status constant `BK_ONGOING` = `'on_going'` (DB column value). The UI normalizes this to `'ongoing'` via `normalizeWorkflowStatus()` before array lookups.

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
