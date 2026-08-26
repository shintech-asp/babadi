# File Structure Map

The application is now grouped by responsibility:

## Root

Only shared entry points stay at the top level:

- `index.php`
- `dashboard.php`
- `messages.php`
- `profile.php`

## Seeker

- `seeker/avail-service-process.php`
- `seeker/booking-details.php`
- `seeker/get-available-times.php`
- `seeker/messages-seeker.php`
- `seeker/my-requests.php`
- `seeker/payment-cancel.php`
- `seeker/payment-failed.php`
- `seeker/payment-redirect.php`
- `seeker/payment-success.php`
- `seeker/provider-details.php`
- `seeker/providers.php`
- `seeker/request-service.php`
- `seeker/seeker-booking-calendar.php`
- `seeker/setup-address.php`
- `seeker/submit-feedback.php`

## Provider

- `provider/create-listing.php`
- `provider/messages-provider.php`
- `provider/my-services.php`
- `provider/provider-payment-settings.php`
- `provider/provider-setup.php`
- `provider/providers-dashboard.php`
- `provider/service-requests.php`
- `provider/services.php`
- `provider/update-booking-status.php`
- `provider/verify-service.php`

## Auth

- `auth/forgot-password.php`
- `auth/login-backup.php`
- `auth/login.php`
- `auth/logout.php`
- `auth/register.php`
- `auth/reset-password.php`
- `auth/verify.php`

## Browse

- `browse/listing-details.php`
- `browse/listings.php`
- `browse/providers-listings.php`

## System

- `system/check-db.php`
- `system/install.php`
- `system/paymongo-webhook.php`
- `system/send_email.php`
- `system/setup.php`

## Shared Directories

- `admin/`
- `api/`
- `assets/`
- `config/`
- `includes/`
- `provider-portal/`
- `uploads/`
- `vendor/`

## Routing

Legacy root URLs are handled by [`.htaccess`](C:/xampp/htdocs/Pestify/.htaccess), which rewrites requests to the organized folders so existing links keep working.