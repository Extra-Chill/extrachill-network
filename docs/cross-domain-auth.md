# Cross-Domain Authentication

## Scope

This document describes current cross-domain authentication behavior.

## Overview

The Extra Chill Platform implements WordPress native multisite authentication across multiple domains (`.extrachill.com` subdomains and `extrachill.link` domain mapping). This document explains how cross-domain authentication works, enabling seamless login/logout across all network sites while maintaining security.

## Network Architecture

### Domains in the Network

**Primary Domain**: `extrachill.com`
- Subdomains for each site (community.extrachill.com, shop.extrachill.com, etc.)
- All subdomains share `.extrachill.com` cookie domain
- Native WordPress multisite authentication

**Mapped Domain**: `extrachill.link`
- Maps to artist.extrachill.com (Blog ID 4)
- URL preservation: URLs display as extrachill.link
- Backend operates on artist.extrachill.com
- Cookies set for both domains

**Cookie Configuration**: WordPress sets cookies for:
- `.extrachill.com` (wildcard, covers all subdomains)
- `extrachill.link` (domain mapping support)
- `www.extrachill.link` (www variant support)

## WordPress Multisite Authentication

### How Multisite Authentication Works

**User Database**: Single user table across all sites (`wp_users`)

**Single Login**: User authenticates once, authenticated on all sites

**Session Persistence**: WordPress session cookies set at network level

**Cookie Domain**: Single cookie domain enables access across subdomain sites

### Cookie Structure

**Session Cookies Set**:
```
wordpress_logged_in_<SITECOOKIEHASH>
- Domain: .extrachill.com
- Path: /
- Secure: true (HTTPS only)
- HttpOnly: true (no JavaScript access)
- SameSite: Lax (CSRF protection)
```

**Authentication Verification**: User ID stored in cookie, verified on page load

**Admin Cookies**: Separate cookies for network admin access

### Cookie Domain Configuration

**wp-config.php**:
```php
// Multisite domain cookie
define( 'COOKIE_DOMAIN', '.extrachill.com' );

// Ensure cookies work for subdomains
define( 'COOKIEPATH', '/' );
define( 'ADMIN_COOKIE_PATH', '/' );
```

**Result**: Single login session across all .extrachill.com subdomains

## Domain Mapping for extrachill.link

### Sunrise PHP Implementation

**File**: `.github/sunrise.php`

**Execution**: Runs before WordPress loads, during domain routing

**Responsibility**: Route extrachill.link to Blog ID 4 (artist.extrachill.com)

**Mapping Logic**:
```php
// extrachill.link → Blog ID 4
// extrachill.link/artist-slug/ displays at extrachill.link
// Backend operates on artist.extrachill.com
```

### URL Preservation

**Frontend Display**:
- User visits: `https://extrachill.link/artist/john-doe/`
- Browser address bar: Shows `extrachill.link`
- Content served from: Blog ID 4

**Backend Operation**:
- WordPress operates in Blog ID 4 context
- Database queries use Blog ID 4 prefix
- Relative links work normally
- Admin area accessible via: `https://extrachill.link/wp-admin/`

**Cross-Site Navigation**: Links between sites update domain appropriately

## Cross-Domain Cookie Handling

### Cookie Visibility

**extrachill.com Cookies**: Accessible on all .extrachill.com subdomains

**extrachill.link Cookies**: Separate but mapped to same Blog ID 4

**Cookie Scope**:
```
.extrachill.com cookie (set once, accessible everywhere):
- extrachill.com
- community.extrachill.com
- shop.extrachill.com
- artist.extrachill.com
- etc.

extrachill.link mapping:
- Routes to artist.extrachill.com (Blog ID 4)
- Uses .extrachill.com cookies (same session)
```

### Session Consistency

**Single Session**: User's WordPress session is one per user, not per domain

**Logout Behavior**: Logout from any domain clears session across all domains

**Blog Switching**: User remains logged in when visiting different sites

**Blog Context**: Current blog ID changes, but user ID remains constant

## Authentication Flow

### Login Process

**Step 1: User Accesses Login Form**
- Visits any site or `/wp-login.php`
- Form displays (theme or WordPress default)

**Step 2: Form Submission**
- Email/username and password submitted
- AJAX or traditional POST to `/wp-login.php`
- Password verified against `wp_users` table

**Step 3: Cookie Set**
```php
// WordPress sets cookie for .extrachill.com domain
setcookie(
    'wordpress_logged_in_[hash]',
    $cookie_value,
    $expire,
    '/',
    '.extrachill.com',
    true,  // secure (HTTPS)
    true   // httponly
);
```

**Step 4: Redirect**
- Successful: Redirect to referring page or dashboard
- Failed: Display error message, form persists
- 2FA: If enabled, redirect to verification

### Logout Process

**Step 1: User Clicks Logout Link**
- Link includes nonce for security
- Posts to `/wp-login.php?action=logout`

**Step 2: Cookie Cleared**
```php
// WordPress deletes authentication cookies
setcookie(
    'wordpress_logged_in_[hash]',
    '',
    time() - 3600,
    '/',
    '.extrachill.com'
);
```

**Step 3: Session Ended**
- User no longer authenticated on any site
- Accessing member-only content redirects to login
- Session data cleared

**Step 4: Redirect**
- Redirects to login page or home page
- User can login again if needed

## Multisite User Creation

### Where Users Register

**Primary Registration Site**: community.extrachill.com (Blog ID 2)

**User Creation Location**: WordPress `wp_users` table (network-wide)

**Registration Flow** (extrachill-users plugin):
1. User fills registration form
2. Email/username validated
3. User account created in `wp_users`
4. User automatically added to community site
5. User can access all sites with same credentials

### Blog Membership

**Automatic Membership**: New users added to community.extrachill.com automatically

**Other Sites**: User must be added by admin or self-add via integration

**Plugin Integration**: Plugins can add users to specific sites on registration

**Profile Access**: User profile accessible from any site (profile.extrachill.com or artist.extrachill.com)

## Cross-Site User Context

### Current User Verification

**Available Across Network**:
```php
// Works on any site
$user_id = get_current_user_id();
$user = wp_get_current_user();
$user_email = $user->user_email;
```

**User Blog Membership**: Verify user has access to current blog
```php
// Check if user is member of current blog
if ( ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
    // User doesn't have access to this blog
}
```

### User Data Access

**User Meta**: Stored in network table `wp_usermeta`

**Blog-Specific Meta**: Can store blog-specific data with user meta key pattern

**Access Pattern**:
```php
// Get user meta (works across blogs)
$meta_value = get_user_meta( $user_id, 'meta_key', true );

// Update user meta (works across blogs)
update_user_meta( $user_id, 'meta_key', 'new_value' );
```

**No Blog Switching Needed**: User meta accessible from any blog context

## Capability Verification

### User Capabilities

**Network Capabilities**: Some capabilities apply network-wide
- `manage_network` - Network administrator
- `manage_sites` - Can manage individual sites

**Blog Capabilities**: Some capabilities are per-blog
- `manage_options` - Can edit blog options (admin)
- `edit_posts` - Can edit blog posts

**Capability Checks**:
```php
// Network-wide
if ( current_user_can( 'manage_network' ) ) {
    // Network admin only
}

// Blog-specific
if ( current_user_can( 'manage_options' ) ) {
    // Current blog admin only
}

// Check specific user/blog
if ( user_can( $user_id, 'manage_options', $blog_id ) ) {
    // User can manage options on specific blog
}
```

### Role Hierarchy

**Network Roles**: Super admin (network-wide)

**Blog Roles**: Admin, Editor, Author, Contributor, Subscriber (per-blog)

**Custom Roles**: Plugins can define custom roles per blog

**Team Member System** (extrachill-users): Custom role/permission system for artist teams

## REST API Authentication

### API Token vs Cookie Auth

**REST API Endpoints**: Use WordPress native authentication

**Cookie Authentication**: Works for same-domain requests
- Browser requests include cookies automatically
- HTTPS required for security
- CORS issues possible for cross-domain

**REST Nonces**: Required for POST/PUT/DELETE requests
```php
// Generate nonce
wp_nonce_field( 'wp_rest' );

// Verify in REST endpoint
if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'wp_rest' ) ) {
    wp_send_json_error( 'Invalid nonce' );
}
```

**Authentication Methods**:
1. Cookie authentication (logged-in users)
2. REST nonces for same-origin browser requests
3. Bearer access + refresh tokens for first-party clients (mobile app and other non-browser clients)

## Security Considerations

### Cookie Security

**HTTPS Only**: Cookies only sent over HTTPS (secure flag)

**HttpOnly**: Cookies not accessible to JavaScript (prevents XSS attacks)

**SameSite Protection**: Cookies not sent for cross-site requests (prevents CSRF)

**Domain Scoping**: Cookies limited to `.extrachill.com` (not accessible to other domains)

### Session Hijacking Prevention

**Session Validation**: User agent and IP address can be validated per session

**Short Expiration**: Sessions expire after inactivity period

**Secure Transport**: HTTPS prevents man-in-the-middle attacks

**Nonce Verification**: One-time tokens for state-changing actions

### Password Security

**Hashing**: Passwords hashed with bcrypt/phpass (WordPress standard)

**No Plain Storage**: Passwords never stored or logged

**Reset Flow**: Secure password reset via email link

**2FA Support**: Two-factor authentication available (plugin dependent)

## Notes

### Cookie attribute handling

Cross-domain auth relies on:

- WordPress multisite cookies for `.extrachill.com` subdomains
- Domain mapping via `.github/sunrise.php` for `extrachill.link`
- For `extrachill.link` (a different registrable domain than `.extrachill.com`, so the auth cookie can never reach it), authenticated REST calls use a **wp-native bearer token** minted by `extrachill-api/inc/auth/extrachill-link-token-handoff.php` on the artist site and handed to the link page in a URL fragment. This replaced the former `SameSite=None; Secure` cookie patch, which modern browser privacy (Safari ITP, Chrome third-party-cookie phase-out) increasingly blocked.

Cookie domain configuration still lives in WordPress configuration (and the hosting/proxy layer).

### Cookie domain expectations

**Check 1: Cookie Domain Configuration**
```php
// Verify in wp-config.php
define( 'COOKIE_DOMAIN', '.extrachill.com' );
```

**Check 2: HTTPS Configuration**
- Ensure all sites use HTTPS
- Insecure connections don't transmit secure cookies

**Check 3: Site Registration**
- User must be registered/member of site
- Check `wp_users` table (exists)
- Check `wp_usermeta` for user capabilities

### Session consistency expectations

**Common Causes**:
- Cookie not being set (secure flag issue)
- Cookie domain mismatch
- User not member of current blog
- Session expired

**Debug Signals**:
- The browser stores `wordpress_logged_in_*` and `wordpress_sec_*` cookies for `.extrachill.com`.
- The request host matches the cookie domain and uses HTTPS.
- The current user is a member of the current blog where required (WordPress multisite).


### Logout behavior expectations

**Likely Cause**: User is member of multiple blogs with different cache

**Solution**:
1. Clear browser cookies manually
2. Wait for cache expiration
3. Login again

## Cross-References

- [extrachill-multisite CLAUDE.md - Blog ID Management](../CLAUDE.md#blog-id-management)
- [extrachill-users CLAUDE.md](../extrachill-users/CLAUDE.md) - Authentication system
- [.github/sunrise.php](../../../.github/sunrise.php) - Domain mapping implementation
- [WordPress Multisite Handbook](https://developer.wordpress.org/plugins/multisite/)
- [WordPress Security Handbook](https://developer.wordpress.org/plugins/security/)
