# Pestify Flutter App — Development Plan

> **For any Claude session starting work on the Flutter app:** Read this file first, then read `FLUTTER_PLAN.md` (API endpoint reference). Everything you need to know is in these two files. Do NOT start coding without reading both.

---

## Engineering Process — Always Follow This

**Loop engineering is mandatory on every build:**
1. **Sonnet** writes all code (default model, no override needed)
2. **Opus** reviews all built files (`model: 'opus'` in workflow agent)
3. **Sonnet** fixes every issue Opus found (parallel, one agent per file)
4. Repeat from step 2 until Opus returns 0 issues
5. Cap at 5 loops; if still failing on round 5, use Opus for the fix too

Use the **Workflow tool** for any multi-file build. Single-file changes can be done inline.

---

## Codebase Context

| Item | Value |
|---|---|
| PHP backend | `C:/xampp/htdocs/Pestify/` |
| All API docs | `FLUTTER_PLAN.md` in project root (Sections 1–9: seeker/provider, 10: admin, 11: portal staff) |
| API base (Android emulator) | `http://10.0.2.2/pestify/api/v1` |
| API base (real device on LAN) | `http://192.168.x.x/pestify/api/v1` — use PC's LAN IP |
| API base (production) | Update `SITE_URL` in `config/config.php`, use that domain |
| Flutter project | `C:/Users/Isystem/pestify_flutter/` |
| Create command | `flutter create pestify_flutter --org com.pestify --platforms android,ios` |

---

## Architecture Decisions (do not re-litigate these)

- **One Flutter app, all roles** — role-based routing via go_router reads `user_type` from JWT
- **Riverpod** for state management
- **dio** for HTTP with a JWT interceptor that auto-attaches `Authorization: Bearer`
- **flutter_secure_storage** for JWT storage (never SharedPreferences)
- **go_router** for routing with redirect guard
- **One login screen** — works for all roles; JWT `user_type` determines where to route

### Role → Home mapping

```dart
if (userType == 'seeker')       return '/seeker/home';
if (userType == 'provider')     return '/provider/home';
if (userType == 'admin')        return '/admin/dashboard';
if (userType == 'portal_staff') return '/portal/dashboard';
return '/login';
```

### JWT families (3 separate login endpoints)

| Login endpoint | `user_type` in JWT | `role` values |
|---|---|---|
| `auth/login.php` | `seeker` or `provider` | — |
| `admin/auth/login.php` | `admin` | `super_admin`, `admin`, `hr`, `finance` |
| `portal/auth/login.php` | `portal_staff` | `owner`, `hr`, `finance`, `crm` |

Store under different secure-storage keys: `jwt_main`, `jwt_admin`, `jwt_portal`.

---

## Folder Structure

```
pestify_flutter/lib/
├── core/
│   ├── api/
│   │   ├── api_client.dart        ← dio singleton, JWT interceptor, 401 → logout
│   │   └── api_endpoints.dart     ← all endpoint paths as constants
│   ├── auth/
│   │   ├── auth_state.dart        ← Riverpod: token, user_type, role, is_logged_in
│   │   └── auth_storage.dart      ← flutter_secure_storage wrapper
│   ├── router/
│   │   └── app_router.dart        ← go_router with role redirect
│   └── theme/
│       └── app_theme.dart
├── shared/
│   └── widgets/
│       ├── loading_button.dart
│       ├── error_banner.dart
│       └── booking_status_chip.dart
├── features/
│   ├── auth/                      ← shared across ALL roles
│   │   ├── screens/
│   │   │   ├── splash_screen.dart
│   │   │   ├── login_screen.dart
│   │   │   ├── register_screen.dart
│   │   │   ├── otp_screen.dart
│   │   │   └── forgot_password_screen.dart
│   │   └── auth_api.dart
│   ├── seeker/                    ← Phase 1 (current)
│   │   ├── screens/
│   │   │   ├── home_screen.dart
│   │   │   ├── providers_screen.dart
│   │   │   ├── provider_detail_screen.dart
│   │   │   ├── listing_detail_screen.dart
│   │   │   ├── book_service_screen.dart
│   │   │   ├── payment_webview_screen.dart
│   │   │   ├── payment_confirm_screen.dart
│   │   │   ├── my_bookings_screen.dart
│   │   │   ├── booking_detail_screen.dart
│   │   │   ├── qr_display_screen.dart
│   │   │   ├── enter_cn_screen.dart
│   │   │   ├── remaining_payment_screen.dart
│   │   │   ├── submit_review_screen.dart
│   │   │   ├── notifications_screen.dart
│   │   │   ├── messages_screen.dart
│   │   │   ├── message_thread_screen.dart
│   │   │   └── profile_screen.dart
│   │   ├── seeker_api.dart
│   │   └── seeker_providers.dart
│   ├── provider/                  ← Phase 2 (add later, same structure)
│   ├── admin/                     ← Phase 3
│   └── portal/                    ← Phase 4
└── main.dart
```

---

## Required Packages (pubspec.yaml)

```yaml
dependencies:
  dio: ^5.4.0
  flutter_secure_storage: ^9.0.0
  flutter_riverpod: ^2.5.0
  go_router: ^13.0.0
  webview_flutter: ^4.7.0
  qr_flutter: ^4.1.0
  mobile_scanner: ^5.0.0
  image_picker: ^1.0.7
  cached_network_image: ^3.3.1
  intl: ^0.19.0
  flutter_rating_bar: ^4.0.1
  jwt_decoder: ^2.0.1
```

---

## Phase 1 — Seeker (BUILD THIS FIRST)

### Auth screens

| Screen | File | API endpoint | Key notes |
|---|---|---|---|
| Splash | `splash_screen.dart` | none | Decode stored JWT, check `exp`, route or go to login |
| Login | `login_screen.dart` | `POST auth/login.php` | Fields: `username_or_email`, `password` |
| Register | `register_screen.dart` | `POST auth/register.php` | Fields: `first_name`, `last_name`, `email`, `password`, `phone`, `user_type=seeker` → OTP screen |
| OTP Verify | `otp_screen.dart` | `POST auth/verify-otp.php` | Fields: `email`, `otp`. Add resend button. |
| Forgot Password | `forgot_password_screen.dart` | `POST auth/forgot-password.php` | Email only |

### Browse screens

| Screen | File | API endpoint | Key notes |
|---|---|---|---|
| Home / Listings | `home_screen.dart` | `GET seeker/listings/index.php` | Params: `search`, `category_id`, `page`. Infinite scroll. |
| Providers List | `providers_screen.dart` | `GET seeker/providers/index.php` | Params: `search`, `category`, `city`, `page` |
| Provider Detail | `provider_detail_screen.dart` | `GET seeker/providers/show.php?id=X` | Shows listings, reviews, rating |
| Listing Detail | `listing_detail_screen.dart` | `GET seeker/listings/show.php?id=X` | "Book Now" → book form |

### Booking flow

| Screen | File | API endpoint | Key notes |
|---|---|---|---|
| Book Service Form | `book_service_screen.dart` | `POST seeker/bookings/store.php` | Address MUST contain "cavite" (validate before POST). Returns `checkout_url` + `booking_id`. |
| Payment WebView | `payment_webview_screen.dart` | — (WebView only) | Open `checkout_url`. Watch for `/payment-success.php` in URL → close WebView immediately, push to confirm screen. |
| Confirm Payment | `payment_confirm_screen.dart` | `GET seeker/bookings/confirm-payment.php?booking_id=X` | Poll every 3s up to 30s until `verified: true`. Response includes `control_number` (PCF-…) and `provider_control_number` (PCP-…). |
| My Bookings | `my_bookings_screen.dart` | `GET seeker/bookings/index.php` | Tab bar: Active / Completed / Cancelled. Pull-to-refresh. |
| Booking Detail | `booking_detail_screen.dart` | `GET seeker/bookings/show.php?id=X` | Conditionally show: QR button (status=starting), Enter CN (provider_verified_at set), Remaining Payment button, Review form |
| Cancel Booking | dialog in booking detail | `POST seeker/bookings/cancel.php` | Only when status is `pending` or `accepted`. Confirm dialog first. |

### Service day screens

| Screen | File | API endpoint | Key notes |
|---|---|---|---|
| Show QR Code | `qr_display_screen.dart` | — (data from booking) | Render `booking.qr_token` with `qr_flutter`. Show `XXX-XXX` formatted text too. Full brightness. Only when `status=starting`. |
| Enter Provider CN | `enter_cn_screen.dart` | `POST seeker/bookings/verify.php` | Fields: `avail_id`, `control_number` (the PCP-… code). Show when `provider_verified_at` is set. |
| Remaining Payment | `remaining_payment_screen.dart` | `GET seeker/bookings/remaining-payment.php?id=X` → WebView → `confirm-payment.php` | Same PayMongo WebView pattern as initial payment |
| Submit Review | `submit_review_screen.dart` | `POST seeker/bookings/review.php` | Fields: `avail_id`, `rating`, `comment`, optional `image` (multipart). Hide form after submission. |

### Comms & profile

| Screen | File | API endpoint | Notes |
|---|---|---|---|
| Notifications | `notifications_screen.dart` | `GET notifications/index.php`, `POST notifications/mark-read.php` | Badge: poll `notifications/count.php` every 60s. Cancel timer in dispose(). |
| Messages List | `messages_screen.dart` | `GET messages/index.php` | Threads by provider |
| Message Thread | `message_thread_screen.dart` | `GET messages/thread.php?provider_id=X`, `POST messages/send.php` | Poll every 8s when open. Cancel in dispose(). |
| Profile | `profile_screen.dart` | `GET/POST user/profile.php` | Avatar = multipart upload. Logout button clears token. |

---

## Phase 2 — Provider

Add `lib/features/provider/`. Uses same `auth/login.php`. JWT `user_type=provider` routes to `/provider/home`.

Key screens: Provider Home, My Listings (index/create/edit), Service Requests (list/detail/accept/reject), Enter Seeker CN (`provider/requests/verify.php`), QR Scanner for on_going (`provider/requests/scan-qr.php`), Messages, Profile.

---

## Phase 3 — Admin Panel

Separate login: `POST admin/auth/login.php`. JWT `user_type=admin`, `role` = `super_admin|admin|hr|finance`. Show/hide screens by role. Key screens map to `api/v1/admin/` endpoints (see FLUTTER_PLAN.md Section 10).

---

## Phase 4 — Portal Staff

Separate login: `POST portal/auth/login.php`. JWT `user_type=portal_staff`, `role` = `owner|hr|finance|crm`, `provider_id` in payload. Check `must_change_password` on login — gate to change-password screen if true. Pro-gated endpoints return 403 for free tier — show upgrade prompt. See FLUTTER_PLAN.md Section 11.

---

## Known API Gaps (build these before the corresponding Flutter screen)

| Missing endpoint | Priority | What it blocks |
|---|---|---|
| `portal/subscriptions/confirm.php` | **Critical** | Portal subscription PayMongo WebView confirm flow |
| `portal/employees/` (index, store, update) | High | HR employee management screens |
| `portal/hr/leave-requests/` | High | Leave request management |
| `portal/hr/timekeeping/` (self clock-in/out) | High | Employee time tracking |
| `portal/crm/requests/` | Medium | Inventory request management |
| `admin/super-dashboard.php` | Medium | Super admin verification queue |
| `portal/settings/` | Medium | Company info, office location, payroll config |

---

## Production Checklist (before go-live)

1. Update `SITE_URL` in `config/config.php` from localhost to live domain
2. Update API base URL in Flutter `api_endpoints.dart`
3. Set real PayMongo keys in `config/config.php`
4. Deploy PHP behind HTTPS (required by PayMongo and Android release builds)
5. Change `JWT_SECRET` in `config/config.php` to a long random string
6. Verify assumed DB table names in HR/Finance endpoints (look for `// TABLE: assumed_name` comments)
