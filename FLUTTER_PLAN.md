# Pestify Flutter — Full System Understanding & Implementation Plan

This file documents how Pestify works end-to-end and maps every flow to a concrete Flutter screen and API endpoint. Reference this when building the mobile app.

---

## What the System Does

Pestify is a two-sided pest control service marketplace. Seekers hire pest control providers. Every job passes through payment, a dual control-number verification on service day, and a formal completion flow.

**Three roles:** `seeker` (customer), `provider` (pest control company), `admin` (platform oversight)

**Stack for mobile API:** `api/v1/` — pure JSON, JWT Bearer auth, CORS-enabled. No session cookies.

---

## Section 1 — The Two Sides

### Seeker (Customer)
- Registers, verifies email via OTP
- Browses listings and provider profiles
- Books a service with a **digital signature** on a contract
- Pays upfront via PayMongo (GCash / Card / PayMaya)
- Receives the provider's control number (PCP-…)
- Tracks booking status in real time
- Verifies presence on service day with the CN
- Pays remaining balance if downpayment was chosen
- Leaves a star rating and written review

### Provider (Pest Control Company)
- Registers, verifies email via OTP
- Sets up company profile, submits docs for admin approval
- Creates service listings with photos and pricing
- Receives booking requests from seekers
- Accepts or rejects, then advances status step by step
- Receives the seeker's control number (PCF-…)
- Enters seeker's CN on service day to confirm identity
- Marks service end, triggering remaining payment if needed
- Confirmed complete after dual verification

---

## Section 2 — Booking Status Lifecycle

Every booking in `availed_services` passes through a strict state machine defined in `includes/booking_workflow_helper.php`. Transitions are one-way and validated server-side — no skipping.

```
pending → accepted → preparing → starting → ongoing
  └─ (full payment)     → waiting_for_seeker_confirmation → completed
  └─ (downpayment)      → waiting_for_remaining_payment
                                  → waiting_for_provider_confirmation → completed
```

`cancelled` can be reached from: `pending`, `accepted`, `waiting_for_provider_confirmation`.

**Status constants in `booking_workflow_helper.php`:**

| Constant | Value |
|---|---|
| `BK_PENDING` | `pending` |
| `BK_ACCEPTED` | `accepted` |
| `BK_PREPARING` | `preparing` |
| `BK_STARTING` | `starting` |
| `BK_ONGOING` | `ongoing` |
| `BK_WAITING_REMAINING` | `waiting_for_remaining_payment` |
| `BK_WAITING_SEEKER_CONFIRM` | `waiting_for_seeker_confirmation` |
| `BK_WAITING_PROVIDER_CONFIRM` | `waiting_for_provider_confirmation` |
| `BK_COMPLETED` | `completed` |
| `BK_CANCELLED` | `cancelled` |

---

## Section 3 — The Dual Control Number System (Critical)

This is the most important piece of the system. Two codes are generated per booking **after PayMongo confirms payment** via webhook. They are distributed in a cross-share pattern so each party must verify the other's presence on service day.

### Code Generation
- Source: `includes/ControlNumberService.php` — `generateAndDistribute($availedServiceId, $seekerUserId, $providerUserId)`
- Triggered by: `system/paymongo-webhook.php` after payment confirmed
- Seeker's code: `PCF-YYYY-XXXXXX` stored in `availed_services.control_number`
- Provider's code: `PCP-YYYY-XXXXXX` stored in `availed_services.provider_control_number`

### Cross-Share Distribution

| Who | Receives | Column |
|---|---|---|
| Seeker | Provider's code `PCP-…` | `availed_services.provider_control_number` |
| Provider | Seeker's code `PCF-…` | `availed_services.control_number` |

### Service Day Verification

| Who | Action | Required POST field | API |
|---|---|---|---|
| Seeker | Enters the `PCP-…` code they received → server validates against `provider_control_number` | `control_number` | `POST seeker/bookings/verify.php` |
| Provider | Enters the `PCF-…` code they received → server validates against `control_number` | `control_number` | `POST provider/requests/verify.php` |

Both endpoints also require `avail_id`. Validation is handled by `ControlNumberService::verifySeekerCode()` / `verifyProviderCode()` — wrong codes return a 422 with an error message. Attempts are audit-logged to `control_number_audit`.

When **both** sides verify → `dual_verified_at` is stamped → booking status advances automatically.

**Flutter implication:** After payment, call the booking detail API to fetch both codes. Display the provider's `PCP-…` code prominently to the seeker — they must verbally share it with the technician on service day.

### QR Token Handshake — Starting → Ongoing

Once a booking reaches `starting` status, the server automatically generates a 6-char QR token (`availed_services.qr_token`, charset `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` — no ambiguous characters). This token is the gate for advancing to `on_going`.

**Seeker side:**
- `GET seeker/bookings/show.php?id=X` returns `qr_token` in the `data` object.
- Flutter renders it as a QR code (e.g. using `qr_flutter`) **and** displays the formatted string `XXX-XXX` (insert a dash after char 3) for manual transcription.
- The seeker shows the QR / code to the technician on service day.

**Provider side:**
- Flutter opens a QR scanner or manual-entry screen on `starting` bookings.
- Submit the scanned/entered token to `POST api/v1/provider/requests/scan-qr.php`.
- Strip any dashes before sending — the endpoint expects the raw 6-char token.
- On success, the booking advances to `on_going` and `qr_scanned_at` is stamped.

> **Important:** `POST provider/requests/update-status.php` does **not** advance `starting → on_going`. That transition is gated behind the QR scan and must go through `scan-qr.php`.

---

## Section 4 — Seeker Flow (Step by Step)

### Step 1 — Register
Fill: first name, last name, email, password, `user_type = seeker`.
Account created with `email_verified = 0`, OTP sent to email.

**API:** `POST api/v1/auth/register.php`

---

### Step 2 — Verify Email (OTP)
6-digit OTP, expires in 15 minutes, max 5 attempts.
Without verification, login is blocked.

**API:** `POST api/v1/auth/verify-otp.php`, `POST api/v1/auth/resend-otp.php`

---

### Step 3 — Login
Email + password → server returns JWT (30-day TTL).
Store token in `FlutterSecureStorage`. Every request sends `Authorization: Bearer <token>`.

**API:** `POST api/v1/auth/login.php`

---

### Step 4 — Browse Listings & Providers
Home feed: active listings ordered by views.
Filter by category, keyword search, location filter.
Tap listing → full detail with photos and provider.
Tap provider → their profile, all services, reviews.

**APIs:**
- `GET api/v1/categories/index.php`
- `GET api/v1/listings/index.php`
- `GET api/v1/listings/show.php?id=X`
- `GET api/v1/providers/index.php`
- `GET api/v1/providers/show.php?id=X`

---

### Step 5 — Fill the Booking Form
Multi-step form. Fields sent to `store.php`:

| Field | Required | Notes |
|---|---|---|
| `listing_id` | Yes | |
| `preferred_date` | Yes | `Y-m-d` format, today or future |
| `preferred_time` | Yes | `H:i` or `H:i:s` format |
| `full_name` | Yes | Used for PayMongo billing |
| `contact_number` | Yes | Used for PayMongo billing |
| `payment_method` | Yes | `full_payment` or `downpayment` |
| `address` | Yes | Must contain "Cavite" (validated server-side) |
| `total_amount` | No | Falls back to listing price if omitted or 0 |
| `notes` | No | |

Downpayment is fixed at 50% of `total_amount`. Remaining balance is stored in `availed_services.remaining_amount`.

**Flutter:** Show the digital signature pad for the service contract UX, but the signature is a client-side record only — it is not sent to or stored by the server.

**API:** `GET api/v1/seeker/bookings/available-times.php` (fetch slots before showing the form)

> ⚠️ The Cavite-only restriction is validated server-side. Validate it client-side too and show an inline error before submitting — do not let the user reach the payment step with a non-Cavite address.

---

### Step 6 — Pay via PayMongo
`store.php` creates the booking record (`status = pending`), calls the PayMongo `/v1/checkout_sessions` API, logs the session to `payment_transactions`, and returns `checkout_url`. Flutter opens it in a WebView. User pays via GCash, card, or PayMaya.

**API:** `POST api/v1/seeker/bookings/store.php`

**Response:**
```json
{
  "ok": true,
  "data": {
    "id": 42,
    "status": "pending",
    "service_name": "Termite Control",
    "checkout_url": "https://checkout.paymongo.com/cs_..."
  }
}
```

If PayMongo fails, `checkout_url` is `null` and `paymongo_error` is set — Flutter should show a retry option in this case.

**Flutter:** Use `webview_flutter`. Listen for navigation to the success URL pattern `/payment-success.php?booking_id=`. On match: close WebView, navigate to booking detail screen.

---

### Step 7 — Receive Control Numbers
After PayMongo webhook fires, server auto-generates both CNs.
The seeker's booking detail shows the **provider's code (PCP-…)** — this is what they share verbally on service day.

**API:** `GET api/v1/seeker/bookings/show.php?id=X`

---

### Step 8 — Track Booking Status
"My Bookings" screen lists all bookings with status.
Status advances are provider-driven (preparing → starting → ongoing).
Poll notification count to detect changes.

**APIs:**
- `GET api/v1/seeker/bookings/index.php`
- `GET api/v1/seeker/bookings/show.php?id=X`
- `GET api/v1/notifications/count.php`

---

### Step 9 — Service Day: Enter Provider's Code
Seeker opens booking, taps "Verify Arrival", types in the `PCP-…` code they received. Server validates the code against `availed_services.provider_control_number`. If correct, stamps `seeker_verified_at`. If provider already verified → `dual_verified_at` stamped automatically.

**API:** `POST api/v1/seeker/bookings/verify.php`
**Body:** `avail_id`, `control_number` (the PCP-… code)

---

### Step 10 — Pay Remaining Balance (downpayment only)
If booking is `waiting_for_remaining_payment`, show "Pay Remaining" button. Endpoint creates a new PayMongo checkout session for `availed_services.remaining_amount` and logs a `payment_type = 'remaining'` transaction. The webhook uses this flag to advance the booking to `waiting_for_provider_confirmation` on payment success.

**API:** `POST api/v1/seeker/bookings/remaining-payment.php`
**Body:** `booking_id`
**Response:** `{ booking_id, amount, checkout_url }`

Same WebView flow as Step 6 — detect the success URL redirect, then refresh the booking detail screen.

---

### Step 11 — Leave Review
After `completed`, show "Leave a Review" on booking detail.
1–5 star rating + optional text. Reviews appear on provider's public profile immediately.

**API:** `POST api/v1/seeker/bookings/feedback.php`

---

## Section 5 — Provider Flow (Step by Step)

### Step 1 — Register as Provider
Same as seeker registration with `user_type = provider`.
OTP verification required. Provider status is `pending` until admin approves.

**APIs:** `POST api/v1/auth/register.php`, `POST api/v1/auth/verify-otp.php`

---

### Step 2 — Set Up Company Profile
On first login, detect incomplete `providers` row → redirect to setup screen.
Fields: company name, logo, service radius (km), business address, city, description.

**API:** `POST api/v1/user/profile.php`

---

### Step 3 — Create Service Listings
Fields: title, description, price, pricing type (`fixed` / `hourly` / `per_sqm`), category, images (up to multiple), emergency availability flag.

**APIs:**
- `GET api/v1/provider/listings/index.php`
- `POST api/v1/provider/listings/store.php`
- `POST api/v1/provider/listings/update.php`
- `POST api/v1/provider/listings/delete.php`

---

### Step 4 — Receive & Manage Booking Requests
Booking appears in request list with `status = pending`.
Provider must accept or reject. After accepting, advances status step by step.

**APIs:**
- `GET api/v1/provider/requests/index.php`
- `GET api/v1/provider/requests/show.php?id=X`
- `POST api/v1/provider/requests/update-status.php`

---

### Step 5 — View Dashboard
Summary: pending requests, active bookings, completed jobs, active listings, average rating, recent 5 requests. This is the provider's home screen.

**API:** `GET api/v1/provider/dashboard.php`

---

### Step 6 — Service Day: Enter Seeker's Code
Provider booking detail shows the **seeker's code (PCF-…)** returned by `show.php`. The seeker reads their code aloud; the technician enters it in the app. Server validates against `availed_services.control_number`. If correct, stamps `provider_verified_at`. If seeker already verified → `dual_verified_at` set.

**API:** `POST api/v1/provider/requests/verify.php`
**Body:** `avail_id`, `control_number` (the PCF-… code the seeker reads aloud)

---

### Step 7 — End Service & Confirm Completion
Provider taps "End Service":
- Full payment → status becomes `waiting_for_seeker_confirmation`
- Downpayment → status becomes `waiting_for_remaining_payment`

Once the seeker confirms (or pays remaining), booking becomes `completed`.

**API:** `POST api/v1/provider/requests/update-status.php`

---

## Section 6 — Flutter App Structure

One Flutter app, role-based routing. JWT payload contains `user_type` — app routes to seeker shell or provider shell on login.

```
lib/
  core/
    api_client.dart         # Dio instance, JWT interceptor
    auth_storage.dart       # FlutterSecureStorage wrapper
    constants.dart          # BASE_URL, etc.
  features/
    auth/                   # login, register, OTP, forgot-password
    seeker/
      browse/               # home feed, listing detail, provider profile
      booking/              # form, signature, available-times
      payment/              # PayMongo WebView
      my_bookings/          # list, detail, verify, feedback
    provider/
      dashboard/
      listings/             # list, create, edit
      requests/             # list, detail, update-status, verify
    messages/               # conversations, thread, send
    notifications/          # list, badge count
    profile/                # view, edit, avatar upload
  shared/
    widgets/                # status badges, loading, empty states, cards
```

---

## Section 7 — Screen Map

| Screen | Role | API Endpoint(s) | Notes |
|---|---|---|---|
| Splash / Onboarding | Both | — | Check stored token, route to home or login |
| Login | Both | `auth/login.php` | Save JWT to secure storage |
| Register | Both | `auth/register.php` | Role selector (seeker / provider) |
| OTP Verify | Both | `auth/verify-otp.php`, `auth/resend-otp.php` | 6-digit input, 60s resend cooldown |
| Forgot Password | Both | `auth/forgot-password.php`, `auth/reset-password.php` | Email → token → new password |
| Browse Listings | Seeker | `listings/index.php`, `categories/index.php` | Filter chips, search bar, infinite scroll |
| Listing Detail | Seeker | `listings/show.php` | Photo gallery, reviews, Book Now button |
| Provider Profile | Seeker | `providers/show.php` | All listings, rating, completed job count |
| Book Service Form | Seeker | `seeker/bookings/available-times.php`, `seeker/bookings/store.php` | Multi-step, Cavite validation, returns `checkout_url` |
| PayMongo Checkout | Seeker | PayMongo external | WebView — detect `/payment-success.php` redirect, then close |
| Confirm Payment | Seeker | `seeker/bookings/confirm-payment.php` | GET after WebView closes; poll until `verified:true`; returns CNs |
| My Bookings | Seeker | `seeker/bookings/index.php` | Status filter tabs, pull-to-refresh |
| Booking Detail | Seeker | `seeker/bookings/show.php` | Shows PCP-… code prominently, verify + cancel buttons |
| Service Day Verify | Seeker | `seeker/bookings/verify.php` | POST: `avail_id` + `control_number` (PCP-… code) |
| Pay Remaining Balance | Seeker | `seeker/bookings/remaining-payment.php` | Downpayment bookings only — same WebView flow as checkout |
| Leave Review | Seeker | `seeker/bookings/feedback.php` | Star picker + text, only for completed bookings |
| Provider Dashboard | Provider | `provider/dashboard.php` | Stat cards, recent requests list |
| My Listings | Provider | `provider/listings/index.php` | Active/inactive toggle, add new button |
| Create / Edit Listing | Provider | `provider/listings/store.php`, `provider/listings/update.php` | Multi-image upload, pricing type selector |
| Service Requests | Provider | `provider/requests/index.php` | Status filter, newest first |
| Request Detail | Provider | `provider/requests/show.php`, `provider/requests/update-status.php` | Seeker info, status action buttons, PCF-… code |
| Provider Verify | Provider | `provider/requests/verify.php` | POST: `avail_id` + `control_number` (PCF-… code from seeker) |
| QR Scanner / Token Entry | Provider | `provider/requests/scan-qr.php` | `starting` bookings only; strip dashes before POST; advances to `on_going` |
| Cancel Booking | Seeker | `seeker/bookings/cancel.php` | `pending` / `accepted` only; POST `booking_id` |
| Messages | Both | `messages/index.php`, `messages/thread.php`, `messages/send.php` | Poll thread every 5–10s, mark read on open |
| Notifications | Both | `notifications/index.php`, `notifications/count.php` | Works for both seeker and provider — poll count every 60s |
| Profile | Both | `user/profile.php` | Avatar upload, provider adds company info |

---

## Section 8 — Recommended Flutter Packages

| Package | Used For |
|---|---|
| `dio` | HTTP client with interceptors — auto-attach JWT Bearer token |
| `flutter_secure_storage` | Store JWT token (Keychain/Keystore) — never use SharedPreferences for tokens |
| `riverpod` | State management — auth state, booking lists, message threads |
| `go_router` | Declarative routing with role-based redirect guards |
| `webview_flutter` | PayMongo checkout WebView |
| `signature` | Digital signature pad on booking form — capture as PNG bytes, send as base64 |
| `image_picker` | Photo uploads for listing images and profile avatar |
| `cached_network_image` | Efficient listing image loading with disk cache and placeholder |
| `intl` | Date formatting (booking dates, timestamps), number formatting (₱ prices) |
| `flutter_rating_bar` | Star rating widget on feedback/review screen |

---

## Section 9 — Critical Things to Know Before Coding

### 1. PayMongo in Flutter = WebView + confirm-payment API

No official PayMongo Flutter SDK exists. **Do not rely on `payment-success-result.php` loading in the WebView** — that page requires a PHP session which Flutter users do not have. Use this two-step flow instead:

**Step A — Open the WebView:**
1. Call `store.php` (or `remaining-payment.php`) → response includes `checkout_url` and `booking_id`
2. Open `checkout_url` in `webview_flutter`
3. In the navigation delegate, watch for any URL containing `/payment-success.php` — this fires on the initial redirect before the page loads (no session needed)
4. On match: **close the WebView immediately**

**Step B — Confirm via API (JWT-authenticated):**
5. Call `GET api/v1/seeker/bookings/confirm-payment.php?booking_id=X` with the JWT Bearer header
6. Response: `{ verified: true/false, booking: { status, payment_status, control_number, provider_control_number } }`
7. If `verified: false` (PayMongo still processing), poll every 3s up to ~30s
8. On `verified: true`: navigate to booking detail screen — CNs are now set and ready

If `checkout_url` is `null` in the `store.php` response, PayMongo errored — show a retry option.

**Same flow for remaining-balance payment** — `remaining-payment.php` returns its own `checkout_url`; detect the same `/payment-success.php` redirect, then call `confirm-payment.php` again with the same `booking_id`.

### 2. No WebSockets — use polling
The web app polls for notifications. In Flutter:
- Notification badge count: `Timer.periodic(60s)` → `GET notifications/count.php`
- Chat messages: `Timer.periodic(5-10s)` → `GET messages/thread.php` (only when chat screen is open)
- Cancel timers in `dispose()`
- Both `notifications/index.php` and `notifications/count.php` are role-aware — they work correctly for both seeker and provider JWTs

### 3. Image uploads use multipart/form-data
Use `dio`'s `FormData` and `MultipartFile` — never base64 for images. Server-side code expects `$_FILES`. Example:
```dart
final formData = FormData.fromMap({
  'images': await MultipartFile.fromFile(filePath, filename: 'image.jpg'),
  'title': 'My Listing',
});
```

### 4. Service is limited to Cavite addresses only
Server validates: `stripos($address, 'cavite')` in `store.php`. In Flutter:
- Validate the address field before submitting
- If the address string does not contain "cavite" (case-insensitive), show an inline error immediately
- Do not let the user proceed to payment with a non-Cavite address

### 5. Digital signature is a client-side UX step only
The service contract signature (via `signature` package) is shown to the user for acknowledgment but is **not sent to the server** — the server does not store or validate it. Collect it for the in-app contract UX but do not include it in the POST body.

### 6. Control number entry is validated server-side
Both verify endpoints require a `control_number` POST field and validate it against the stored code using a constant-time `hash_equals()` comparison. Wrong codes return a 422. Attempts are logged to `control_number_audit`. Do not allow the user to skip the entry step — an empty code will be rejected.

### 8. Starting → Ongoing requires QR token scan, not a direct status update

`POST provider/requests/update-status.php` intentionally does **not** allow advancing to `on_going`. That transition is gated behind the QR handshake. The correct flow:
1. Booking enters `starting` → server sets `qr_token` automatically.
2. Seeker displays the QR / 6-char code (from `seeker/bookings/show.php` → `data.qr_token`).
3. Provider scans or types the token → `POST provider/requests/scan-qr.php` with `{ "token": "XXXXXX" }`.
4. On success the booking becomes `on_going`.

Calling `update-status.php` with `status=on_going` returns a 422.

### 9. JWT is stored on the client for 30 days
TTL is `60 * 60 * 24 * 30` seconds (defined in `api/v1/_bootstrap.php`). On app start, decode the stored token and check the `exp` claim. If expired, redirect to login. A 401 response from any endpoint also means the token is invalid — handle this in a Dio interceptor that clears storage and pushes to login.

### 10. Admin and portal JWTs carry different `user_type` claims
Each role family has its own login endpoint and its own `user_type` value baked into the JWT:

| Role | Login endpoint | `user_type` claim | `role` claim |
|---|---|---|---|
| Admin panel | `admin/auth/login.php` | `admin` | `super_admin` \| `admin` \| `hr` \| `finance` |
| Portal staff | `portal/auth/login.php` | `portal_staff` | `owner` \| `hr` \| `finance` \| `crm` |
| Seeker | `auth/login.php` | `seeker` | — |
| Provider owner | `auth/login.php` | `provider` | — |

Keep **three separate secure-storage keys** (or one key per app if you split into separate Flutter apps). Do not mix JWTs across role families — an admin JWT sent to a seeker endpoint returns 403.

### 11. Portal staff `must_change_password` gate
Both `portal/auth/login.php` and `admin/auth/login.php` return `must_change_password: true` in the response when an account has a temp password that has never been changed. In the Flutter app:
- If `must_change_password === true`: navigate immediately to the Change Password screen.
- Show **only** Change Password and Logout in the nav — no other tabs.
- After a successful `POST portal/auth/change-password.php`, return the user to the dashboard.

---

## Section 10 — Admin Panel API (`api/v1/admin/`)

All endpoints require `Authorization: Bearer <admin_jwt>`. The admin JWT is obtained from `POST admin/auth/login.php`.

### Auth
| Method | Endpoint | Roles | Notes |
|---|---|---|---|
| POST | `admin/auth/login.php` | — (public) | Body: `username_or_email`, `password`. Returns `token`, `must_change_password`, `admin` object |
| GET | `admin/auth/me.php` | all admin roles | Returns current admin profile |

### Dashboard
| Method | Endpoint | Roles | Notes |
|---|---|---|---|
| GET | `admin/dashboard.php` | super_admin, admin | Platform-wide stats: users, providers, bookings, revenue, recent activity |

### Users
| Method | Endpoint | Roles | Notes |
|---|---|---|---|
| GET | `admin/users/index.php` | super_admin, admin | Params: `search`, `user_type`, `status`, `page`, `limit` |
| GET | `admin/users/show.php` | super_admin, admin | Param: `id` — full user detail with booking count |
| POST | `admin/users/update.php` | super_admin, admin | Body: `id`, `status` (`active`\|`banned`\|`suspended`) |

### Providers
| Method | Endpoint | Roles | Notes |
|---|---|---|---|
| GET | `admin/providers/index.php` | super_admin, admin | Params: `status`, `search`, `page`, `limit` |
| GET | `admin/providers/show.php` | super_admin, admin | Param: `id` — full provider detail with owner info |
| POST | `admin/providers/approve.php` | super_admin, admin | Body: `id` — sets status to `approved` |
| POST | `admin/providers/reject.php` | super_admin, admin | Body: `id`, `reason` (optional) |

### Bookings
| Method | Endpoint | Roles | Notes |
|---|---|---|---|
| GET | `admin/bookings/index.php` | super_admin, admin | Params: `status`, `provider_id`, `seeker_id`, `date_from`, `date_to`, `page`, `limit` |
| GET | `admin/bookings/show.php` | super_admin, admin | Param: `id` — full booking detail with payment transactions |

### Subscription Plans
| Method | Endpoint | Roles | Notes |
|---|---|---|---|
| GET | `admin/subscription-plans/index.php` | super_admin | Lists all plans with active subscriber counts |
| POST | `admin/subscription-plans/store.php` | super_admin | Body: `name`, `monthly_price`, `yearly_price` |
| POST | `admin/subscription-plans/update.php` | super_admin | Body: `id`, `monthly_price`, `yearly_price` |
| POST | `admin/subscription-plans/activate.php` | super_admin | Body: `provider_id`, `plan_id`, `billing_cycle`, `months` |
| POST | `admin/subscription-plans/expire.php` | super_admin | Body: `provider_id` — immediately expires active subscription |

### Admin Logs
| Method | Endpoint | Roles | Notes |
|---|---|---|---|
| GET | `admin/logs/index.php` | super_admin, admin | Params: `admin_id`, `action`, `date_from`, `date_to`, `page`, `limit` |

### HR Module (`$role` must be `super_admin`, `admin`, or `hr`)
| Method | Endpoint | Notes |
|---|---|---|
| GET | `admin/hr/employees/index.php` | Params: `provider_id`, `status`, `page`, `limit` |
| GET | `admin/hr/attendance/index.php` | Params: `provider_id`, `employee_id`, `date_from`, `date_to`, `page`, `limit` |
| GET | `admin/hr/payroll/index.php` | Params: `provider_id`, `month`, `year`, `page`, `limit` |
| GET | `admin/hr/recruitment/index.php` | Params: `provider_id`, `status`, `page`, `limit` |

### Finance Module (`$role` must be `super_admin`, `admin`, or `finance`)
| Method | Endpoint | Notes |
|---|---|---|
| GET | `admin/finance/income/index.php` | Params: `provider_id`, `date_from`, `date_to`, `page`, `limit` |
| GET | `admin/finance/expenses/index.php` | Params: `provider_id`, `date_from`, `date_to`, `page`, `limit` |
| GET | `admin/finance/requests/index.php` | Params: `status`, `page`, `limit` |

---

## Section 11 — Provider Portal Staff API (`api/v1/portal/`)

All endpoints (except login) require `Authorization: Bearer <portal_jwt>`. The portal JWT is obtained from `POST portal/auth/login.php`. The JWT payload carries `provider_id` — all queries are automatically scoped to that provider.

**Tier rules:** Free-tier staff can access auth + staff management + dashboard + subscriptions. All HR, Finance, CRM, and service endpoints require an active Pro or Grace subscription, enforced server-side via `portal_require_pro()`.

### Auth
| Method | Endpoint | Roles | Tier | Notes |
|---|---|---|---|---|
| POST | `portal/auth/login.php` | — (public) | — | Body: `username_or_email`, `password`. Returns `token`, `must_change_password`, `staff` object |
| POST | `portal/auth/change-password.php` | all | free | Body: `current_password`, `new_password`. Clears `must_change_password` flag |
| GET | `portal/auth/me.php` | all | free | Returns staff profile + provider company name |

### Dashboard
| Method | Endpoint | Roles | Tier |
|---|---|---|---|
| GET | `portal/dashboard.php` | all | free |

### Staff Management
| Method | Endpoint | Roles | Tier | Notes |
|---|---|---|---|---|
| GET | `portal/staff/index.php` | owner | free | Lists all staff for this provider |
| POST | `portal/staff/store.php` | owner | free | Body: `username`, `email`, `role`, `department`. Returns temp password |
| POST | `portal/staff/update.php` | owner | free | Body: `id`, `role`?, `department`?, `status`? |
| POST | `portal/staff/delete.php` | owner | free | Body: `id` — soft-deactivates the account |

### HR — Attendance
| Method | Endpoint | Roles | Tier | Notes |
|---|---|---|---|---|
| GET | `portal/hr/attendance/index.php` | owner, hr | pro | Params: `employee_id`, `date_from`, `date_to`, `page`, `limit` |
| POST | `portal/hr/attendance/clock-in.php` | owner, hr | pro | Body: `employee_id`, `notes`? |
| POST | `portal/hr/attendance/clock-out.php` | owner, hr | pro | Body: `employee_id` |

### HR — Payroll
| Method | Endpoint | Roles | Tier |
|---|---|---|---|
| GET | `portal/hr/payroll/index.php` | owner, hr | pro |
| POST | `portal/hr/payroll/store.php` | owner, hr | pro |

### HR — Recruitment
| Method | Endpoint | Roles | Tier |
|---|---|---|---|
| GET | `portal/hr/recruitment/index.php` | owner, hr | pro |
| POST | `portal/hr/recruitment/store.php` | owner, hr | pro |

### Finance — Income
| Method | Endpoint | Roles | Tier |
|---|---|---|---|
| GET | `portal/finance/income/index.php` | owner, finance | pro |
| POST | `portal/finance/income/store.php` | owner, finance | pro |

### Finance — Expenses
| Method | Endpoint | Roles | Tier |
|---|---|---|---|
| GET | `portal/finance/expenses/index.php` | owner, finance | pro |
| POST | `portal/finance/expenses/store.php` | owner, finance | pro |

### Finance — Requests
| Method | Endpoint | Roles | Tier |
|---|---|---|---|
| GET | `portal/finance/requests/index.php` | owner, finance | pro |
| POST | `portal/finance/requests/store.php` | owner, finance | pro |

### CRM — Bookings
| Method | Endpoint | Roles | Tier | Notes |
|---|---|---|---|---|
| GET | `portal/crm/bookings/index.php` | owner, crm | pro | Params: `status`, `date_from`, `date_to`, `page`, `limit` |
| GET | `portal/crm/bookings/show.php` | owner, crm | pro | Param: `id` |
| POST | `portal/crm/bookings/update-status.php` | owner, crm | pro | Body: `id`, `status`, `notes`?. Same rules as provider `update-status.php` — `on_going` not allowed here |
| POST | `portal/crm/bookings/scan-qr.php` | owner, crm | pro | Body: `token` (6-char, dashes stripped automatically) — advances `starting → on_going` |

### CRM — Services
| Method | Endpoint | Roles | Tier |
|---|---|---|---|
| GET | `portal/crm/services/index.php` | owner, crm | pro |
| POST | `portal/crm/services/store.php` | owner, crm | pro |
| POST | `portal/crm/services/update.php` | owner, crm | pro |

### Subscriptions
| Method | Endpoint | Roles | Tier | Notes |
|---|---|---|---|---|
| GET | `portal/subscriptions/index.php` | owner | free | Returns current subscription + all available plans |
| POST | `portal/subscriptions/store.php` | owner | free | Body: `plan_id`, `billing_cycle`. Returns `checkout_url` for PayMongo WebView |

---

## API Base URL

```
http://localhost/pestify/api/v1/
```

For production, update to the live domain. Set this as a constant in `lib/core/constants.dart`.

All endpoints expect and return `application/json` except file upload endpoints which accept `multipart/form-data`.

All authenticated endpoints require the header:
```
Authorization: Bearer <jwt_token>
```
