# Provider Subscription Tiers — Design Spec
**Date:** 2026-08-13  
**Status:** Approved

---

## Overview

Providers start on a permanent **Free tier** and can upgrade to a **Pro (Paid) tier** via monthly or yearly subscription. Super admins control pricing. The provider portal gets a centralized single sidebar, a redesigned subscription page, and a unified design system across all pages.

---

## 1. Tier Feature Map

| Feature | Free | Pro (Paid) |
|---|---|---|
| Dashboard | Limited stats (headcount, booking count) | Full stats |
| Staff & Employees | Full access | Full access |
| Leave Requests | Submit/approve basic | Full workflow |
| Attendance | Locked | Geofenced biometrics button + monitoring |
| Timekeeping | Locked | Full |
| Recruitment | Locked | Full |
| Payroll | Locked | Full |
| CRM Bookings/Requests | View + basic accept/decline/complete | Full + bulk actions |
| CRM Services | View-only | Create/edit/delete |
| QR Scan, Schedules, Archive | Locked | Full |
| Messaging | Full | Full |
| Finance Dashboard | Booking revenue only (auto-calculated) | Full stats |
| Income Records | Auto-calculated view only | Full manual + categories |
| Expenses, Budget Requests | Locked | Full |
| Subscription Page | Upgrade prompt | Billing management |

---

## 2. Database Schema

### New: `subscription_plans`
```sql
CREATE TABLE subscription_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL DEFAULT 'Pro Plan',
    description TEXT,
    monthly_price DECIMAL(10,2) NOT NULL DEFAULT 500.00,
    yearly_price DECIMAL(10,2) NOT NULL DEFAULT 5000.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
-- Seed with default plan
INSERT INTO subscription_plans (name, monthly_price, yearly_price) VALUES ('Pro Plan', 500.00, 5000.00);
```

### Modified: `provider_subscriptions`
Add columns to existing table (old rows remain, ignored by new code which filters on plan_id IS NOT NULL):
```sql
ALTER TABLE provider_subscriptions
    ADD COLUMN plan_id INT DEFAULT NULL AFTER provider_id,
    ADD COLUMN billing_cycle ENUM('monthly','yearly') DEFAULT 'monthly' AFTER plan_id,
    ADD COLUMN grace_ends_at DATETIME DEFAULT NULL AFTER expires_at;
```

Status enum values used by new code: `pending`, `active`, `grace`, `expired`  
(Old rows with status='active' and no plan_id are ignored by new tier logic.)

### Modified: `providers`
```sql
ALTER TABLE providers
    ADD COLUMN office_lat DECIMAL(10,8) DEFAULT NULL,
    ADD COLUMN office_lng DECIMAL(11,8) DEFAULT NULL;
```

---

## 3. Tier Helper — `provider-portal/includes/portal-tier.php`

Central function: `getProviderTier($db, $provider_id)` returns:
- `'paid'` — active subscription (status='active', expires_at > NOW())
- `'grace'` — in 3-day grace period (expires_at < NOW() but grace_ends_at > NOW(), status='grace')
- `'free'` — everything else

Also exports: `$provider_tier`, `$tier_expires_at`, `$tier_grace_ends_at`

---

## 4. Unified Sidebar — `provider-portal/includes/portal-sidebar.php`

One file, role-based visibility, tier-aware locking:
- All roles see the same sidebar structure
- Paid-only items shown with lock icon + "Pro" badge for free tier users
- Tier pill shown in sidebar header (FREE / PRO / GRACE)
- Owner sees "Back to Provider" link; staff do not
- Password-change gate still enforced

### Role visibility rules:
| Section | Owner | HR | Finance | CRM |
|---|---|---|---|---|
| Dashboard | ✓ | ✓ | ✓ | ✓ |
| HR section | ✓ (gated) | ✓ | ✗ | ✗ |
| Finance section | ✓ (gated) | ✗ | ✓ | ✗ |
| CRM section | ✓ (gated) | ✗ | ✗ | ✓ |
| Management (staff, schedules) | ✓ only | ✗ | ✗ | ✗ |
| Subscriptions | ✓ only | ✗ | ✗ | ✗ |
| Messages | ✓ | ✓ | ✓ | ✓ |
| Change Password | ✓ | ✓ | ✓ | ✓ |

---

## 5. Subscription Page — `provider-portal/subscriptions.php`

Redesigned with:
- Current tier status card (Free/Pro/Grace)
- Two pricing cards: Monthly and Yearly (prices from `subscription_plans`)
- Yearly shows savings badge (yearly_price vs monthly × 12)
- PayMongo Checkout Sessions flow (same as existing)
- Payment history table
- Grace period warning banner if applicable

---

## 6. Admin Plan Management — `admin/subscription-plans.php`

Super admin only. Features:
- Edit plan name, monthly price, yearly price
- View all provider subscriptions (paginated): provider name, status, billing cycle, expiry
- Manual override: admin can activate/deactivate a provider's subscription
- Accessible from admin sidebar under "Platform Settings"

---

## 7. Geofenced Attendance — `provider-portal/attendance.php`

- `providers.office_lat` / `providers.office_lng` used as center point
- Paid-only feature; free tier sees locked state
- Owner is exempt from geofence check (can clock in from anywhere)
- All other staff: browser `navigator.geolocation` → Haversine distance check → if ≤ 10km, clock-in AJAX recorded
- Outside range: error message shown, button disabled
- Manual attendance entry (admin/owner) bypasses geofence

---

## 8. Design System

All portal pages use:
- CSS variables: `--primary:#2E8B57`, `--dark:#1a2744`, `--bg:#f5f7fa`, `--border:#e2e8f0`
- Font: DM Sans
- Locked feature overlay: semi-transparent with lock icon + "Upgrade to Pro" CTA
- Consistent card/table/alert components
- Same sidebar width (250px), same main content padding (30px)

---

## 9. Files Created/Modified

| File | Action |
|---|---|
| `provider-portal/includes/portal-tier.php` | CREATE — tier helper |
| `provider-portal/includes/portal-sidebar.php` | REWRITE — unified sidebar |
| `provider-portal/includes/portal-subscription.php` | UPDATE — use new tier helper |
| `provider-portal/includes/portal-auth.php` | UPDATE — include tier helper |
| `provider-portal/subscriptions.php` | REWRITE — new design |
| `provider-portal/subscription-success.php` | UPDATE — new plan structure |
| `provider-portal/attendance.php` | UPDATE — geofencing |
| `provider-portal/dashboard.php` | UPDATE — tier gating |
| `provider-portal/hr-dashboard.php` | UPDATE — tier check |
| `provider-portal/employees.php` | UPDATE — free tier allowed |
| `provider-portal/staff.php` | UPDATE — free tier allowed |
| `provider-portal/leave-requests.php` | UPDATE — basic free / full paid |
| `provider-portal/timekeeping.php` | UPDATE — paid only |
| `provider-portal/payroll.php` | UPDATE — paid only |
| `provider-portal/recruitment.php` | UPDATE — paid only |
| `provider-portal/finance-dashboard.php` | UPDATE — partial free |
| `provider-portal/income.php` | UPDATE — read-only free |
| `provider-portal/expenses.php` | UPDATE — paid only |
| `provider-portal/budget-requests.php` | UPDATE — paid only |
| `provider-portal/crm-dashboard.php` | UPDATE — free with limits |
| `provider-portal/crm-bookings.php` | UPDATE — basic free |
| `provider-portal/crm-requests.php` | UPDATE — basic free |
| `provider-portal/crm-services.php` | UPDATE — view free / edit paid |
| `provider-portal/schedules.php` | UPDATE — paid only |
| `provider-portal/scan-qr.php` | UPDATE — paid only |
| `provider-portal/archive.php` | UPDATE — paid only |
| `admin/subscription-plans.php` | CREATE — plan management |
| `admin/includes/admin-sidebar.php` | UPDATE — add new menu item |
| `system/migrations/subscription_tiers.sql` | CREATE — DB migrations |
