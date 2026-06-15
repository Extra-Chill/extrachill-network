# Changelog

## [1.17.0] - 2026-06-15

### Added
- register cross-site Coverage section on artist profile hub

### Changed
- clear pre-existing phpcs errors in legacy-path-redirects

### Fixed
- validate festival-wire slug before forwarding to wire subsite

## [1.16.1] - 2026-06-15

### Fixed
- resolve cross-site term counts in-process to stop nginx loopback rate-limiting

## [1.16.0] - 2026-06-06

### Added
- render Turnstile widgets explicitly per-widget so one bad widget cannot break siblings

### Changed
- add cross-widget Turnstile browser smoke
- add PHPUnit coverage for Cloudflare Turnstile integration
- clear phpcs lint debt (release preflight)

### Fixed
- remove dead 'stream' and 'chat' entries from site labels

## [1.15.0] - 2026-05-30

### Added
- add comment editor abilities and editor helpers (#33)

### Changed
- cache cross-site term links in the object cache

## [1.14.1] - 2026-05-18

### Fixed
- single owner for extrachill-multisite ability category (closes #31)

## [1.14.0] - 2026-05-18

### Added
- EC email templates + ec_send_email wrapper (closes #26)

## [1.13.2] - 2026-05-18

### Fixed
- anchor README.md exclusion to repo root only

## [1.13.1] - 2026-05-18

### Fixed
- graceful activation in non-interactive contexts (Playground, CLI)

## [1.13.0] - 2026-05-17

### Added
- add reusable request-check + permission_callback helpers

## [1.12.4] - 2026-05-12

### Fixed
- guard admin-only function calls in activation hook for Playground bootstrap
- unblock the VPS's own IPv6 + CF origin path

## [1.12.3] - 2026-05-10

### Fixed
- make ec_cross_site_rest_request a universal cross-site REST primitive

## [1.12.2] - 2026-05-10

### Fixed
- fix(og-cards): consume data-machine-events public integration API

## [1.12.1] - 2026-05-10

### Changed
- dispatch cross-site REST in-process via switch_to_blog

## [1.12.0] - 2026-04-26

### Added
- add network-media-list and network-media-upload abilities for cross-site main media library access

## [1.11.0] - 2026-04-25

### Added
- feat(og-cards): generate branded OG cards for posts without featured images

### Changed
- Remove stream.extrachill.com (Blog ID 8) from network config

## [1.10.0] - 2026-04-04

### Added
- add blog/main route affinity to cross-site map

## [1.9.0] - 2026-04-03

### Added
- add universal cross-site REST request helper

## [1.8.5] - 2026-04-02

### Changed
- align TaxonomyCountAbilities with core WP_Ability contract

## [1.8.4] - 2026-03-29

### Changed
- Remove duplicate taxonomy-badges.css — theme already provides all badge styles

## [1.8.3] - 2026-03-28

### Changed
- Add ec-surface-card class to community activity sidebar widget
- Document why delete-after-create is used for default content
- Remove default WordPress content from new network sites
- Add ec_get_all_site_ids() for dynamic site discovery

## [1.8.2] - 2026-03-25

### Changed
- remove chat.extrachill.com (blog 5) from network registry

## [1.8.1] - 2026-03-24

### Changed
- show Posts menu in wp-admin on studio site

## [1.8.0] - 2026-03-23

### Added
- team-gated Studio in network dropdown and footer, add filter hooks

### Fixed
- sync taxonomy-badges.css with @extrachill/tokens@0.4.1 (232 badges, fix washington split)

## [1.7.0] - 2026-03-23

### Added
- sync taxonomy badge CSS from @extrachill/tokens (99 → 134 badges)

## [1.6.0] - 2026-03-23

### Added
- add taxonomy count ability and badge count cron warmer
- add minimum threshold for cross-site link count display

### Changed
- remove Studio from public multisite navigation
- add Studio as native multisite platform site
- replace hardcoded badge colors with CSS custom properties

### Fixed
- Fix Festival Wire footer link to point to wire.extrachill.com instead of extrachill.com/festival-wire
- remove double underline on sidebar activity card links
- enqueue community activity CSS on singular pages
- Restore original community activity CSS styling 1:1

## [1.5.0] - 2026-02-22

### Changed
- Move community activity from theme to multisite plugin

## [1.4.11] - 2026-01-28

### Changed
- Update cross-site link label to Festival Wire

## [1.4.10] - 2026-01-28

### Changed
- Migrate cross-site-links functions from ec_ to extrachill_ prefix

## [1.4.9] - 2026-01-28

### Changed
- Add community site to location taxonomy cross-site linking

## [1.4.8] - 2026-01-21

- Add descriptive cross-site taxonomy link button labels with term name and content type

## [1.4.7] - 2026-01-21

- Register social links data via extrachill_social_links_data filter for footer icons

## [1.4.5] - 2026-01-19

### Added
- Added theme integration hooks for 404 content, admin menu, DNS prefetch, footer main menu, network dropdown, and site title
- Updated cross-site links and canonical authority system
- Improved admin settings pages (OAuth, payments, security, shipping)
- Updated blog IDs and Turnstile integration
- Removed vendor directory from git tracking (now gitignored)

## [1.4.4] - 2026-01-19

### Added
- Added music-specific taxonomy badge colors (festivals, locations, venues, artists)
- Added artist dropdown filter for song-meanings and music-history categories
- Added EC footer bottom menu links via theme filter

## [1.4.3] - 2026-01-08
### Added
- Object Cache Pro configuration via `objectcache_config` filter to mark `co-authors-plus` as a non-prefetchable group.
- Canonical authority resolution for shared taxonomy archives across the multisite network.

### Changed
- Plugin initialization now loads the Object Cache Pro config module and canonical authority resolver.

## [1.4.2] - 2026-01-05
### Added
- Dynamic lookup for `EC_PLATFORM_ARTIST_ID` from network options with fallback to production ID.
- User-managed artist profile links in cross-site user link resolution via `ec_get_artists_for_user()`.

### Changed
- Refined cross-site user link resolution to include links to published artist profiles managed by the user.

## [1.4.1] - 2026-01-05
### Added
- REST API integration for cross-site taxonomy counts (Main, Events, Shop, Wire)
- Internal REST API calls via `rest_do_request()` for zero-overhead data retrieval
- Support for `events` site in artist taxonomy mapping

### Changed
- Refactored `ec_get_cross_site_artist_links` to use REST API for upcoming events and shop products
- Optimized `ec_get_cross_site_term_links` to use REST APIs for accurate cross-site content counts
- Removed `extrachill_archive_header_actions` hook for artist archive profile links (now handled via REST-backed resolution)
- Updated artist profile link resolution to use CPT matching on artist site instead of taxonomy

## [1.4.0] - 2026-01-05
### Added
- Unified Cross-Site Links system (`inc/cross-site-links/`) for network-wide navigation
- Taxonomy archive cross-linking with content existence verification
- User profile and artist profile cross-site link resolution
- Support for Blog ID 12 (Horoscope) as an active site
- Enhanced documentation for cross-domain authentication and REST nonces

### Changed
- Updated network site count to 11 active sites (IDs 1-5, 7-12)
- Refined Blog ID helper documentation with recommended patterns

## [1.3.1] - 2025-12-23
### Added
- `ec_get_allowed_redirect_hosts()` function to retrieve all network domains as allowed redirect hosts from domain map
- `ec_filter_allowed_redirect_hosts()` function to register network domains as allowed redirect targets for wp_safe_redirect()
- Automatic registration of all network subdomains as allowed redirect hosts via allowed_redirect_hosts filter
- `ec_allowed_redirect_hosts` filter for extensibility of allowed redirect hosts list

## [1.3.0] - 2025-12-22
### Added
- Support for wire.extrachill.com site (Blog ID 11) with EC_BLOG_ID_WIRE constant
- Legacy path redirects module (`inc/core/legacy-path-redirects.php`) for `/festival-wire` URLs to wire.extrachill.com
- Updated site count and blog ID mappings in documentation

### Changed
- Moved EC_BLOG_ID_HOROSCOPE from 11 to 12 to accommodate wire site
- Updated blog ID arrays and helper functions to include wire site
- Updated README.md site count description (9 active sites, IDs 1-5,7-11)
- Updated docs/blog-id-helpers.md with wire site documentation and horoscope ID correction

## [1.2.1] - 2025-12-20
### Added
- Network Shipping Settings admin page for Shippo API key configuration and shipping label integration
- `EC_PLATFORM_ARTIST_ID` constant (value: 12114) for internal artist profile identification
- Automatic Turnstile verification bypass for local development environments (WP_ENVIRONMENT_TYPE = 'local')

### Changed
- Enhanced `ec_verify_turnstile_response()` with environment-aware bypass logic for streamlined local testing

## [1.2.0] - 2025-12-20
### Added
- iOS OAuth Client ID field in network OAuth settings page for native iOS app authentication
- Android OAuth Client ID field in network OAuth settings page for native Android app authentication
- `ec_get_google_ios_client_id()` helper function to retrieve iOS OAuth client identifier
- `ec_get_google_android_client_id()` helper function to retrieve Android OAuth client identifier
- Setup instructions for iOS and Android OAuth client creation in network settings

### Changed
- Updated README.md to document OAuth and Payment Provider Settings features
- Enhanced network OAuth settings page with iOS and Android configuration fields
- Improved OAuth settings form layout with aligned variable declarations

## [1.1.1] - 2025-12-19
### Changed
- Refactored OAuth helper functions to dedicated `inc/core/oauth-helpers.php` module for better code organization
- Moved `ec_is_google_oauth_configured()` and `ec_is_apple_oauth_configured()` from `admin/network-oauth-settings.php` to core module

## [1.1.0] - 2025-12-19
### Added
- Network OAuth settings page for Google Sign-In and Apple Sign-In configuration
- Network Payments settings page for Stripe Connect configuration
- Documentation for blog ID helper functions and usage patterns
- Documentation for cross-domain authentication patterns
- Documentation for Cloudflare Turnstile integration in registration forms

## [1.0.3] - 2025-12-11
### Added
- `ec_site_url_override` filter hook in `ec_get_site_url()` for development environment URL overrides

## [1.0.2] - 2025-12-08
### Added
- `ec_get_site_url()` helper function for logical site URL resolution

### Changed
- Corrected extrachill.link domain mapping to artist.extrachill.com
- Fixed Turnstile verification failures affecting registration and contact forms
- Added avatar menu for cross-platform reusability

### Technical
- Updated composer vendor package references

## [1.0.1] - 2025-12-08
### Added
- Blog ID mapping system (`inc/core/blog-ids.php`) with comprehensive site routing
- Domain mapping support for extrachill.link → artist.extrachill.com
- Helper functions for cross-site blog ID resolution

### Changed
- Updated plugin description to reflect network administration focus
- Improved README with current architecture and site count
- Fixed plugin reference in security settings (community → users)

### Technical
- Added blog-ids.php include to plugin initialization
- Updated composer dependencies
