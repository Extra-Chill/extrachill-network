# ExtraChill Network - Agent Development Guide

**Network-activated WordPress plugin providing the network administration foundation for the Extra Chill Platform multisite network.**

## Plugin Information

 - **Name**: Extra Chill Network
 - **Version**: 1.4.5
 - **Text Domain**: `extrachill-network`
- **Author**: Chris Huber
- **Author URI**: https://chubes.net
- **License**: GPL v2 or later
- **Network**: true (network-activated)
- **Requires at least**: 5.0
- **Tested up to**: 6.4
- **Requires PHP**: 7.4

## Overview

**Extra Chill Network** is the network foundation plugin for the Extra Chill Platform, providing centralized infrastructure for all 10 active WordPress multisite sites. It manages network-wide configuration, authentication, security, site discovery, and cross-site linking patterns while remaining lightweight and performant across the entire network.

**Core Purpose**: Single source of truth for blog ID management, Cloudflare Turnstile integration, network admin menu structure, and cross-site coordination patterns (including `ec_get_artist_profile_by_slug`).

## Current Status

**Production Status**: Active network foundation plugin  
**Architecture**: Procedural WordPress pattern with network-wide functionality
**Scope**: Network administration infrastructure for all 10 active sites
**Build System**: Use `homeboy build extrachill-network` for production builds

## Architecture

### Network Activation Pattern

**Single Plugin, Network Scope**: Activated once at network level, serves all sites via `Network: true` header

**Network Options Storage**: Uses `get_site_option()` for network-wide configuration (not per-site)

**Cross-Site Access**: Uses `switch_to_blog()` / `restore_current_blog()` for operations requiring blog-specific context

**Key Principle**: Plugin operates at network level, loaded once, providing shared infrastructure to all sites

### File Organization

```
extrachill-network/
├── extrachill-network.php            # Main plugin file
├── inc/
│   ├── core/
│   │   ├── blog-ids.php                # Blog ID constants and helper functions
│   │   ├── extrachill-turnstile.php    # Turnstile integration and validation
│   │   ├── legacy-path-redirects.php   # Legacy URL redirects
│   │   ├── oauth-helpers.php           # OAuth helper functions for Google OAuth
│   │   └── object-cache-config.php     # Object cache configuration
 │   ├── cross-site-links/               # Cross-site linking system
 │   │   ├── canonical-authority.php      # Canonical URL resolution for taxonomies
 │   │   ├── cross-site-links.php        # Loader + mapping/labels + hook registration
 │   │   ├── entity-links.php            # User profile + artist profile resolution
 │   │   ├── renderers.php               # Button renderers for theme hooks
 │   │   └── taxonomy-links.php          # Taxonomy archive linking
│   ├── theme/                          # Theme integration hooks
│   │   ├── 404-content.php             # Custom 404 page content
│   │   ├── admin-menu.php              # Admin menu customizations
│   │   ├── dns-prefetch.php            # DNS prefetch hints
│   │   ├── filter-bar.php              # Filter bar artist dropdown for music categories
│   │   ├── footer-links.php            # EC-specific footer bottom menu links
│   │   ├── footer-main-menu.php        # Footer main menu items
│   │   ├── network-dropdown.php        # Network site dropdown
│   │   └── site-title.php              # Site title customizations
│   └── assets.php                      # Asset enqueuing (404 styles, taxonomy badges)
├── assets/
│   └── css/
│       ├── 404.css                     # 404 page styles
│       └── taxonomy-badges.css         # Music-specific taxonomy badge colors
├── admin/
│   ├── network-menu.php                # Network admin menu structure
│   ├── network-security-settings.php   # Security settings page (Turnstile)
│   ├── network-oauth-settings.php      # OAuth provider settings page (Google OAuth)
│   ├── network-payments-settings.php   # Payment provider settings page (Stripe)
│   └── network-shipping-settings.php   # Shipping provider settings page
├── docs/
│   └── CHANGELOG.md                    # Version history
└── .buildignore                        # Build exclusion patterns
```

### Loading Pattern

**Plugins_loaded Hook**: Plugin initializes at priority 10 via `plugins_loaded` action

**Network-Only Validation**: Activation hook checks `is_multisite()`, deactivates if not network

**Admin-Only Features**: Network menu and security settings only load in network admin via `is_network_admin()`

**Conditional Loading**:
```php
add_action( 'plugins_loaded', 'extrachill_network_init' );

function extrachill_network_init() {
    // Always load blog IDs and Turnstile
    require_once 'inc/core/blog-ids.php';
    require_once 'inc/core/extrachill-turnstile.php';

    // Only in network admin
    if ( is_admin() && is_network_admin() ) {
        require_once 'admin/network-menu.php';
        require_once 'admin/network-security-settings.php';
    }
}
```

## Core Features

### 1. Blog ID Management

**Purpose**: Centralized, performance-optimized blog ID system for all multisite sites

**Constants Defined** (in `inc/core/blog-ids.php`):
- `EC_BLOG_ID_MAIN` = 1 (extrachill.com)
- `EC_BLOG_ID_COMMUNITY` = 2 (community.extrachill.com)
- `EC_BLOG_ID_SHOP` = 3 (shop.extrachill.com)
- `EC_BLOG_ID_ARTIST` = 4 (artist.extrachill.com + extrachill.link)
- `EC_BLOG_ID_EVENTS` = 7 (events.extrachill.com)
- `EC_BLOG_ID_NEWSLETTER` = 9 (newsletter.extrachill.com)
- `EC_BLOG_ID_DOCS` = 10 (docs.extrachill.com)
- `EC_BLOG_ID_WIRE` = 11 (wire.extrachill.com)
- `EC_BLOG_ID_STUDIO` = 12 (studio.extrachill.com)

**Note**: Blog IDs 5–6 are unused (historical artifacts; chat.extrachill.com was archived). Blog ID 8 (`stream.extrachill.com`, `EC_BLOG_ID_STREAM`) was decommissioned in April 2026 — the constant, slug, and domain entry have all been removed from the code.

### Blog ID Helper Functions

**`ec_get_blog_ids()`** - Returns associative array of all blog IDs

**Returns**:
```php
array(
    'main'       => 1,
    'community'  => 2,
    'shop'       => 3,
    'artist'     => 4,
    'events'     => 7,
    'newsletter' => 9,
    'docs'       => 10,
    'wire'       => 11,
    'studio'     => 12
)
```

**`ec_get_blog_id( $key )`** - Get blog ID by logical slug

**Parameters**: `$key` (string) - Logical site key (e.g., 'artist', 'newsletter')

**Returns**: `int|null` - Blog ID or null if unknown

**Usage**:
```php
$blog_id = ec_get_blog_id( 'newsletter' ); // Returns 9
$blog_id = ec_get_blog_id( 'unknown' );    // Returns null
```

**`ec_get_domain_map()`** - Returns mapping of domains to blog IDs

**Returns**:
```php
array(
    'extrachill.com'         => 1,
    'community.extrachill.com' => 2,
    'shop.extrachill.com'    => 3,
    'artist.extrachill.com'  => 4,
    'events.extrachill.com'  => 7,
    'newsletter.extrachill.com' => 9,
    'docs.extrachill.com'    => 10,
    'wire.extrachill.com'    => 11,
    'studio.extrachill.com' => 12,
    'extrachill.link'        => 4,  // Domain mapping for artist link pages
    'www.extrachill.link'    => 4
)
```

**`ec_get_blog_slug_by_id( $blog_id )`** - Reverse lookup: slug by blog ID

**Parameters**: `$blog_id` (int) - Numeric blog ID

**Returns**: `string|null` - Slug (e.g., 'artist') or null if unknown

**Usage**:
```php
$slug = ec_get_blog_slug_by_id( 4 ); // Returns 'artist'
```

**`ec_get_site_url( $key )`** - Get production site URL by logical slug

**Parameters**: `$key` (string) - Logical site key (e.g., 'artist', 'newsletter')

**Returns**: `string|null` - Full site URL (e.g., `https://artist.extrachill.com`) or null if unknown

**Overridable**: Fires `ec_site_url_override` filter for dev environment URLs

**Usage**:
```php
$url = ec_get_site_url( 'newsletter' ); // Returns 'https://newsletter.extrachill.com'
```

### Domain Mapping for extrachill.link

**Implementation**: `.github/sunrise.php` (executes before WordPress loads)

**Mapping**: `extrachill.link` (and `www.extrachill.link`) → Blog ID 4 (artist.extrachill.com)

**URL Preservation**: 
- Frontend URLs display as `extrachill.link/artist-slug/`
- Backend operates on `artist.extrachill.com`
- WordPress multisite native cookies set for both domains

**Blog ID Helper Support**: Both domains resolve to Blog ID 4 via `ec_get_blog_id( 'artist' )`

### 2. Cloudflare Turnstile Integration

**Purpose**: Network-wide bot prevention via Cloudflare Turnstile CAPTCHA service

**Location**: `inc/core/extrachill-turnstile.php`

**Network Options Storage**:
- `ec_turnstile_site_key` - Client-side widget identifier
- `ec_turnstile_secret_key` - Server-side verification token

### Turnstile Configuration Functions

**`ec_get_turnstile_site_key()`** - Retrieve client-side site key

**Returns**: `string` - Site key or empty string if not configured

**`ec_get_turnstile_secret_key()`** - Retrieve server-side secret key

**Returns**: `string` - Secret key or empty string if not configured

**`ec_update_turnstile_site_key( $site_key )`** - Store client-side site key

**Parameters**: `$site_key` (string) - Cloudflare-provided site key

**Security**: Input sanitized via `sanitize_text_field()`

**`ec_update_turnstile_secret_key( $secret_key )`** - Store server-side secret key

**Parameters**: `$secret_key` (string) - Cloudflare-provided secret key

**Security**: Input sanitized via `sanitize_text_field()`

**`ec_is_turnstile_configured()`** - Check if Turnstile is properly configured

**Returns**: `bool` - true if both site key and secret key exist

**Usage**:
```php
if ( ec_is_turnstile_configured() ) {
    // Render widget and validate responses
}
```

### Turnstile Verification

**`ec_verify_turnstile_response( $response )`** - Verify Turnstile token via Cloudflare API

**Parameters**: `$response` (string) - Token from frontend widget

**Returns**: `bool` - true if verified, false if invalid or error

**Verification Process**:
1. Sanitize response token via `sanitize_text_field()`
2. Retrieve secret key from network options
3. POST to Cloudflare verification endpoint with secret and token
4. Parse JSON response, verify success flag
5. Log detailed errors if verification fails

**Error Logging**: Comprehensive error logging for debugging:
- Empty response token
- Missing secret key
- HTTP errors from Cloudflare
- JSON decode failures
- Verification failures with error codes

**Usage**:
```php
if ( ec_verify_turnstile_response( $_POST['cf-turnstile-response'] ) ) {
    // Proceed with subscription/registration
} else {
    // Reject form submission
}
```

### Turnstile Widget Rendering

**`ec_render_turnstile_widget( $args = array() )`** - Generate HTML for Turnstile widget

**Parameters** (optional): `$args` (array) - Widget options

**Customizable Attributes**:
- `data-sitekey` - Client-side site key (required)
- `data-size` - Widget size: 'normal' (default), 'compact'
- `data-theme` - Theme: 'light', 'dark', 'auto' (default)
- `data-appearance` - Appearance: 'always' (default), 'interaction-only'
- `class` - CSS classes (default: 'cf-turnstile')

**Returns**: `string` - HTML div with widget attributes or empty string if not configured

**Example**:
```php
echo ec_render_turnstile_widget( array(
    'data-size' => 'compact',
    'data-theme' => 'dark'
) );
```

**`ec_enqueue_turnstile_script( $handle = 'cloudflare-turnstile' )`** - Enqueue Turnstile JavaScript library

**Parameters** (optional): `$handle` (string) - Script handle

**Script Source**: `https://challenges.cloudflare.com/turnstile/v0/api.js`

**Conditional**: Only enqueues if Turnstile is configured via `ec_is_turnstile_configured()`

**Usage**:
```php
add_action( 'wp_enqueue_scripts', function() {
    ec_enqueue_turnstile_script();
});
```

### 3. Network Admin Menu

**Location**: `admin/network-menu.php`

**Access**: Network administrators only (checked via `current_user_can( 'manage_network' )`)

**Menu Structure**: Organized top-level menu for platform settings

**Subpages**:
- Security Settings (Turnstile configuration)
- OAuth Settings (Google OAuth configuration)
- Payment Settings (Stripe configuration)

**Integration Points**: Extends WordPress network admin interface with Extra Chill specific tools

### 4. Network Security Settings

**Location**: `admin/network-security-settings.php`

**Purpose**: Centralized interface for network-wide security configuration

**Features**:
- Cloudflare Turnstile API key management
- Access control configuration
- Security policy settings

**UI Integration**: Submenu under network admin menu

**Capability Checks**: Network administrator capability verification

### 5. Network OAuth Settings

**Location**: `admin/network-oauth-settings.php`

**Purpose**: Centralized OAuth provider configuration for the multisite network

**Features**:
- Google OAuth client ID and secret management
- OAuth redirect URI configuration
- Provider enable/disable toggles

**Helper Functions** (`inc/core/oauth-helpers.php`):
- `ec_get_google_client_id()` - Retrieve Google OAuth client ID
- `ec_get_google_client_secret()` - Retrieve Google OAuth client secret
- `ec_is_google_oauth_configured()` - Check if Google OAuth is properly configured

**Integration**: Used by extrachill-users plugin for Google sign-in functionality

### 6. Network Payments Settings

**Location**: `admin/network-payments-settings.php`

**Purpose**: Centralized payment provider configuration for the multisite network

**Features**:
- Stripe API key management (publishable and secret keys)
- Stripe Connect configuration
- Payment provider enable/disable toggles

**Integration**: Used by extrachill-shop plugin for Stripe Connect and payment processing

### 7. Cross-Site Linking (`inc/cross-site-links/`)

**Purpose**: Provide unified cross-site navigation patterns (taxonomy archives, user profiles, artist profiles) across the 10-site network.

**Core Modules**:
- `inc/cross-site-links/canonical-authority.php`  canonical URL resolution for shared taxonomy archives.
- `inc/cross-site-links/cross-site-links.php`  loader + mapping/labels + hook registration.
- `inc/cross-site-links/taxonomy-links.php`  taxonomy archive linking (REST-backed counts for main/events/shop/wire).
- `inc/cross-site-links/entity-links.php`  user profile linking + artist profile resolution.
- `inc/cross-site-links/renderers.php`  button renderers hooked into the theme.

**Core Functions**:
- `ec_get_cross_site_term_links( $term, $taxonomy )`  Returns links to other sites where the term has published content.
- `ec_get_taxonomy_site_map()`  Defines taxonomy  site-key mapping (filterable: `ec_taxonomy_site_map`).
- `ec_get_site_labels()`  Defines site labels used in UI (filterable: `ec_site_labels`).
- `ec_get_cross_site_user_links( $user_id )`  Returns links to community profile, author archive, and artist profiles.
- `ec_get_artist_profile_by_slug( $slug )`  Resolves published `artist_profile` CPT by slug on the artist site.
- `ec_render_cross_site_taxonomy_links()` (hook: `extrachill_archive_below_description`)  renders taxonomy link buttons on `is_tax()`.
- `ec_render_cross_site_user_links( $user_id )` (hook: `extrachill_after_author_bio`)  renders user link buttons on author pages.

**Canonical Authority Functions** (`inc/cross-site-links/canonical-authority.php`):
- `ec_get_canonical_authority_url( $term, $taxonomy )`  Returns canonical URL for taxonomy archive across sites.
- `ec_get_taxonomy_canonical_config()`  Defines which site is canonical for each shared taxonomy.
- `ec_artist_profile_has_image( $slug )`  Checks if artist profile has a featured image.

**Integration**: This system centralizes cross-site navigation so plugins and the theme do not duplicate blog switching and link resolution logic.

### 8. Theme Integration (`inc/theme/`)

**Purpose**: Provide EC-specific content and styling hooks that keep platform-specific logic in the plugin while allowing the theme to remain generic.

**Filter Bar Integration** (`inc/theme/filter-bar.php`):
- Adds artist dropdown filter for music-specific categories (song-meanings, music-history)
- Hook: `extrachill_filter_bar_category_items` filter
- Conditionally displays only on relevant category archives
- Depends on theme's `extrachill_build_artist_dropdown()` function

**Footer Links** (`inc/theme/footer-links.php`):
- Provides EC-specific footer bottom menu links (Affiliate Disclosure, Privacy Policy)
- Hook: `extrachill_footer_bottom_menu_items` filter
- Theme provides empty default; plugin adds EC-specific links
- Uses `ec_get_site_url( 'main' )` for consistent URL generation

**Taxonomy Badge Colors** (`assets/css/taxonomy-badges.css`):
- Music-specific badge colors for festivals, locations, venues, and artists
- Extends theme's base taxonomy badge styling
- Loaded via `inc/assets.php` after theme's `extrachill-taxonomy-badges` dependency
- Provides branded colors for specific festivals (Bonnaroo, Coachella, etc.) and locations

**Other Theme Integrations**:
- `inc/theme/404-content.php` - Custom 404 page content
- `inc/theme/admin-menu.php` - Admin menu customizations
- `inc/theme/dns-prefetch.php` - DNS prefetch hints for performance
- `inc/theme/footer-main-menu.php` - Footer main menu items
- `inc/theme/network-dropdown.php` - Network site dropdown
- `inc/theme/site-title.php` - Site title customizations

## Network-Wide Integration

### How Plugins Access Blog IDs

**Direct Function Calls**:
```php
// From any plugin on any site
if ( function_exists( 'ec_get_blog_id' ) ) {
    $newsletter_blog_id = ec_get_blog_id( 'newsletter' );
    // Operates on newsletter.extrachill.com (Blog ID 9)
}
```

**Graceful Degradation**: Plugins check function existence before using

**No Cross-Site Data Pollution**: Each plugin receives only network-wide configuration

### Cross-Site Data Access Pattern

**Pattern**: `switch_to_blog()` / `restore_current_blog()` with try/finally

**Why**: WordPress requires explicit blog context switching for per-site data

**Example**:
```php
$blog_id = ec_get_blog_id( 'newsletter' );
try {
    switch_to_blog( $blog_id );
    // Operate in newsletter site context
    $newsletters = get_posts( array( 'post_type' => 'newsletter' ) );
} finally {
    restore_current_blog();
}
```

## File Interactions

### Main Plugin File (`extrachill-network.php`)

**Responsibility**: Plugin initialization and loading orchestration

**Activation Hook**: Validates multisite installation

**Plugins_loaded Action**: Triggers conditional loading at priority 10

**Load Sequence**:
1. Blog IDs and constants first (all contexts)
2. Turnstile functions second (all contexts)
3. Network admin files last (network admin only)

### Blog IDs Module (`inc/core/blog-ids.php`)

**Responsibility**: Canonical blog ID definitions and helper functions

**Exports**:
- 10 constants (EC_BLOG_ID_*)
- 4 functions (ec_get_blog_ids, ec_get_blog_id, ec_get_blog_slug_by_id, ec_get_site_url)
- 1 filter hook (ec_site_url_override)

**Single Source of Truth**: All blog ID knowledge centralized here

### Turnstile Module (`inc/core/extrachill-turnstile.php`)

**Responsibility**: Cloudflare Turnstile API integration and validation

**Exports**:
- 2 getter functions (site key, secret key)
- 2 setter functions (site key, secret key)
- 1 status function (is_configured)
- 2 validation functions (verify response, render widget)
- 1 asset function (enqueue script)

**Network Options Driven**: All configuration via network options

## Security Implementation

### Access Control

**Network Admin Only**: Features requiring admin access verify `is_network_admin()` and `current_user_can( 'manage_network' )`

**Capability Checks**: All settings pages use WordPress capability system

**Input Validation**: All user input sanitized via WordPress sanitization functions

### Turnstile Security

**Token Verification**: Server-side verification prevents spoofing

**Secure Storage**: API keys stored in network options (database protected)

**Error Handling**: Comprehensive logging without exposing secrets

**Graceful Degradation**: Forms work without Turnstile, prevents blocking legitimate users

### No Hardcoded Secrets

**API Keys**: Never hardcoded in source (stored in network options)

**Configuration**: Managed via network admin UI

**Environment Compatibility**: Works across dev, staging, and production environments

## Development Patterns

### Adding New Blog Site

**Steps**:
1. Create WordPress site at subdomain
2. Assign blog ID from available pool
3. Add constant to `inc/core/blog-ids.php`:
   ```php
   if ( ! defined( 'EC_BLOG_ID_NEWSITE' ) ) {
       define( 'EC_BLOG_ID_NEWSITE', 12 );
   }
   ```
4. Add to `ec_get_blog_ids()` function array
5. Add to `ec_get_domain_map()` for domain routing
6. Activate appropriate plugins on new site
7. Configure network options if site-specific settings needed

### Integrating Turnstile in Other Plugins

**Steps**:
1. Check if configured: `ec_is_turnstile_configured()`
2. Enqueue script: `ec_enqueue_turnstile_script()`
3. Render widget: `ec_render_turnstile_widget( $args )`
4. Verify response: `ec_verify_turnstile_response( $response )`

**Example**:
```php
// In extrachill-users registration form
add_action( 'wp_enqueue_scripts', function() {
    if ( ec_is_turnstile_configured() ) {
        ec_enqueue_turnstile_script();
    }
});

// In form template
if ( ec_is_turnstile_configured() ) {
    echo ec_render_turnstile_widget();
}

// In AJAX handler
if ( ! ec_verify_turnstile_response( $_POST['cf-turnstile-response'] ) ) {
    return wp_send_json_error( 'Verification failed' );
}
```

## Forbidden Patterns

**Do NOT**:
- Hardcode blog IDs in plugin code (use helper functions)
- Store Sendy/Turnstile credentials in source code (use network options)
- Assume blog context in network-level code (use blog context detection)
- Create per-site copies of network configuration (use `get_site_option()`)
- Bypass capability checks on admin pages
- Log sensitive API keys to error logs
- Fail silently on Turnstile errors (log with context)

## Planning Standards

### Code Review Checklist

- [ ] Uses `ec_get_blog_id()` instead of hardcoded IDs (where applicable)
- [ ] Network options used for network-wide data
- [ ] Blog switching uses try/finally pattern
- [ ] Capability checks on all admin pages
- [ ] Input sanitization on all user input
- [ ] Error logging includes debugging context
- [ ] No API keys in source code
- [ ] Functions gracefully handle missing configuration

## Dependencies

### Required
- **WordPress**: 5.0+ (multisite network)
- **PHP**: 7.4+
- **Multisite**: WordPress must be configured for multisite

### Optional
- **Cloudflare Turnstile**: Bot prevention service
- **Extra Chill Theme**: For full integration experience

## Build & Deployment

### Production Build

**Build System**: Use `homeboy build extrachill-network` for production builds

**Build Output**: `/build/extrachill-network.zip` file only.

**File Exclusions**: vendor/, docs/, tests/, .git/, .buildignore, build.sh

**Deployment**:
1. Build ZIP via `./build.sh` (creates `/build/extrachill-network.zip`)
2. Deploy ZIP via Homeboy (`homeboy deploy ...`) or your preferred deploy pipeline
3. Network activate in network admin
4. Configure Turnstile keys if using bot prevention
5. Verify blog ID helpers accessible to other plugins

## Related Documentation

**Component Documentation**:
- [extrachill-newsletter CLAUDE.md](../extrachill-newsletter/CLAUDE.md) - Uses blog IDs and Turnstile
- [extrachill-users CLAUDE.md](../extrachill-users/CLAUDE.md) - Uses blog IDs and Turnstile
- [Root CLAUDE.md](../../CLAUDE.md) - Platform architecture and hardcoded blog ID reference

**Related Files**:
- `.github/sunrise.php` - Domain mapping for extrachill.link
- `.github/NETWORK-ARCHITECTURE.md` - Network structure documentation
- `composer.json` - Development dependencies
- `.buildignore` - Build exclusions

**External Resources**:
- [Cloudflare Turnstile Docs](https://developers.cloudflare.com/turnstile/)
- [WordPress Multisite Handbook](https://developer.wordpress.org/plugins/multisite/)
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
