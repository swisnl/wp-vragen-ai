=== Vragen.ai ===
Contributors: swis
Tags: search, ai, knowledge base, sync, multilingual
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronises your published WordPress content with a vragen.ai knowledge base so it can be searched and answered through vragen.ai.

== Description ==

Vragen.ai keeps your WordPress content in sync with a [vragen.ai](https://vragen.ai) knowledge base. Whenever a post is published, updated, trashed or deleted, the plugin queues a background job (via Action Scheduler) that creates, updates or removes the matching document in vragen.ai.

Features:

* Automatic sync on publish/update/trash/delete for the post types you choose.
* Background processing through Action Scheduler — large sites stay responsive.
* Bulk synchronisation of all published content from the settings screen.
* WP-CLI command (`wp vragenai sync`) for scripted/initial imports.
* PDF attachments (directly attached media and ACF file fields) are passed to vragen.ai for server-side text extraction.
* Multilingual support for WPML and Polylang: all translations of a post are merged into a single document, tagged with every language it is available in.
* Filters (`vragenai_should_index_post`, `vragenai_document_attributes`) to customise what is indexed.

The admin UI is available in English and Dutch, following your site language.

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

== Frequently Asked Questions ==

= Where do I get a customer name and API token? =

From your vragen.ai account. See https://vragen.ai/docs.

= Does it extract text from PDFs itself? =

No. The plugin sends the file URL to vragen.ai, which crawls and extracts the text server-side.

= Does it support multiple languages? =

Yes. With WPML or Polylang active, all translations of a post are treated as one piece of content: they are merged into a single vragen.ai document keyed on the default-language (canonical) translation, with the document content taken from that translation and every available language listed in its metadata. Translations are assumed to be semantically equivalent, so only the canonical content is indexed.

== Changelog ==

= 2.0.0 =
* Initial public release.

== Upgrade Notice ==

= 2.0.0 =
Initial public release.
