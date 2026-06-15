=== Vragen.ai ===
Contributors: vragenai
Tags: search, ai, knowledge base, sync, multilingual
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronises your published WordPress content with a vragen.ai knowledge base so it can be searched and answered through vragen.ai.

== Description ==

Vragen.ai keeps your WordPress content in sync with a [vragen.ai](https://vragen.ai) knowledge base. Whenever a post is published, updated, trashed or deleted, the plugin queues a background job (via Action Scheduler) that creates, updates or removes the matching document in vragen.ai.

Features:

* Automatic sync on publish/update/trash/delete for the post types you choose.
* Background processing through Action Scheduler, large sites stay responsive.
* Bulk synchronisation of all published content from the settings screen.
* WP-CLI command (`wp vragenai sync`) for scripted/initial imports.
* PDF attachments (directly attached media and ACF file fields) are passed to vragen.ai for server-side text extraction.
* Multilingual support for WPML and Polylang: all translations of a post are merged into a single document, tagged with every language it is available in.
* Embed a vragen.ai deployment (page, popup or popover) with the Vragen.ai block, or load one site-wide from the settings screen.
* Replace the native WordPress search with semantic search from vragen.ai (optional), with automatic fallback to the built-in search if the API is unavailable.
* Show related content for the current post with the Vragen.ai related-content block or the `[vragenai_related]` shortcode.
* Filters (`vragenai_should_index_post`, `vragenai_document_attributes`, `vragenai_native_search_options`) to customise indexing and search.

The admin UI is available in English and Dutch, following your site language.

== External services ==

This plugin connects to the vragen.ai knowledge base API. This connection is the sole purpose of the plugin: it is required to index your content so it can be searched and answered through vragen.ai. The service is operated by SWIS (https://swis.nl) on behalf of Vragen.ai (https://vragen.ai).

The API is reached at the endpoint configured for your account, `https://{customer}.vragen.ai/api/v1` by default (the root domain can be changed with the `VRAGENAI_API_DOMAIN` constant).

When you publish, update, trash or delete content of an enabled post type and when you run a bulk synchronisation the plugin sends the following to that endpoint:

* the post title, public URL and rendered content;
* metadata: author display name, post type, post format, language(s), publish/modified dates, taxonomy terms and the featured-image URL;
* the URLs and MIME types of attached PDF files, so vragen.ai can fetch and extract their text server-side (the file contents themselves are not uploaded by the plugin);
* your configured customer name and API token (sent as a Bearer authorization header).

In addition, while the settings screen is open the plugin contacts the API at most once per hour to verify the connection.

No data is sent until you enter a customer name and API token. The plugin makes no other external requests.

* Service homepage: https://vragen.ai/
* Privacy Policy: https://vragen.ai/privacy-statement

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings → Vragen.ai**.
3. Enter your customer name (the `{customer}` in `{customer}.vragen.ai`) and API token. A success notice confirms the connection.
4. Select the post types to synchronise and save.
5. Optionally run **Bulk synchronisation** to index existing content.

For production, you can keep secrets out of the database by defining them in `wp-config.php`:

`define( 'VRAGENAI_CUSTOMER', 'your-organisation' );`
`define( 'VRAGENAI_TOKEN', 'your-api-token' );`

These constants take precedence over the values stored in the settings screen.

The API is reached at `{customer}.vragen.ai` by default. For staging or self-hosted environments you can change the root domain (the customer name stays the subdomain) by defining:

`define( 'VRAGENAI_API_DOMAIN', 'example.com' );`

With customer `your-organisation` this targets `https://your-organisation.example.com/api/v1`. There is no settings-screen field for this on purpose.

== Frequently Asked Questions ==

= Where do I get a customer name and API token? =

From your vragen.ai account. See https://vragen.ai/docs.

= Does it extract text from PDFs itself? =

No. The plugin sends the file URL to vragen.ai, which crawls and extracts the text server-side.

= Does it support multiple languages? =

Yes. With WPML or Polylang active, all translations of a post are treated as one piece of content: they are merged into a single vragen.ai document keyed on the default-language (canonical) translation, with the document content taken from that translation and every available language listed in its metadata. Translations are assumed to be semantically equivalent, so only the canonical content is indexed.

= Can I use vragen.ai for the site's own search? =

Yes. On **Settings → Vragen.ai**, enable replacing the native search. Your theme's existing search page, results template and search widget then return semantic results from vragen.ai, ordered by relevance, with pagination preserved. Only synced content is searchable. If the API is unavailable or times out, the plugin falls back to WordPress' built-in search so the site never loses search.

= How do I show related content? =

Add the **Vragen.ai gerelateerde content** block to a post or template, or use the `[vragenai_related]` shortcode. It lists content semantically related to the current post (excluding the post itself). The post must already be synced to vragen.ai.

= The embed doesn't appear on a site with a Content Security Policy (CSP)? =

If you use the Vragen.ai embed block or the site-wide embed, it loads a script from your vragen.ai instance and makes API calls to it, so a strict CSP must allow your vragen.ai domain:

* script-src 'self' https://vragen.ai https://*.vragen.ai;
* connect-src 'self' https://vragen.ai https://*.vragen.ai;
* img-src 'self' data: https://vragen.ai https://*.vragen.ai;
* style-src 'self' 'unsafe-inline';

Note that `*.vragen.ai` does not match the bare `vragen.ai`, so include both. If you set the `VRAGENAI_API_DOMAIN` constant, allow that domain instead.

== Screenshots ==

1. The settings screen: connect your vragen.ai account and choose which post types are synchronised.
2. Bulk synchronisation of existing published content from the settings screen.
3. Your synced content, searchable with instant answers on vragen.ai.

== Changelog ==

= 2.0.0 =
* Initial public release.

== Upgrade Notice ==

= 2.0.0 =
Initial public release.
