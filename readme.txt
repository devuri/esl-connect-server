=== ESL Connect Server ===
Contributors: icelayer
Tags: license, software-licensing, esl, edd
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Server-side plan enforcement for Easy Software License.

== Description ==

ESL Connect Server is the server-side component that enforces plan-based license limits for Easy Software License (ESL) customers.

**This plugin is for internal use only and should only be installed on the PremiumPocket license server.**

= Features =

* Real-time license limit enforcement
* Plan-based limits (Solo: 500, Studio/Agency: Unlimited)
* Secure HMAC-SHA256 request signing
* Rate limiting (60 requests/minute per store)
* Admin dashboard with connected stores list
* Health monitoring endpoint
* Audit logging of all operations

= Requirements =

* WordPress 6.0+
* PHP 7.4+
* Easy Software License plugin
* Easy Digital Downloads (for subscriptions)

== Installation ==

1. Upload to `/wp-content/plugins/` on your license server
2. Activate the plugin
3. Configure the ESL product ID via filter:

`
add_filter( 'ppk_esl_connect_server_esl_product_id', function() {
    return 123; // Your ESL product ID in EDD
});
`

4. Configure plan to price ID mapping if needed:

`
add_filter( 'ppk_esl_connect_server_price_plan_map', function( $map ) {
    return [
        1 => 'solo',
        2 => 'studio',
        3 => 'agency',
    ];
});
`

== Frequently Asked Questions ==

= Who should install this plugin? =

Only the PremiumPocket team. This plugin runs exclusively on our license server.

= How do customers connect? =

When customers activate their ESL license, Connect credentials are automatically included in the activation response.

== Changelog ==

= 0.1.0 =
* Initial release
* REST API endpoints: reserve, release, status, sync, health
* Connected stores admin dashboard
* EDD subscription integration
* Rate limiting and request signing
