=== Vragen.ai: AI search and visitor insights ===
Contributors: vragenai
Tags: ai search, semantic search, chatbot, ai assistant, analytics
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.1.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visitors get answers from your own content, with the source. You see what they ask and where your content falls short.

== Description ==

**Every conversation gives you insight.**

People don't visit your website to find a page. They want to know something, arrange something or get something done. With vragen.ai they ask in their own words and get an answer straight from your own content, with the source underneath so they can check it.

You stay in control. You decide which content the AI uses, how it behaves and when a person needs to step in.

But that's only the beginning.

You see which questions visitors ask, what they're trying to achieve, where information is missing and which answers could be better. So you don't just learn where people click, but what they actually need.

From searching to asking. From information to action.

= For your visitors: answers, not result lists =

* **One answer from your own content.** Visitors ask a question in their own words and get one clear answer, built only from the sources you choose.
* **Always with the source.** The source is shown under every answer, so visitors can check it themselves.
* **Honest when it doesn't know.** If the answer isn't in your content, vragen.ai says so and points the way, for example to your contact page.
* **Where you want it.** Place vragen.ai on a page with the Vragen.ai block, or site-wide as a page, pop-up or popover.
* **A smarter WordPress search.** Optionally replace the built-in search with semantic search: results that match what people mean, not just the words they type.
* **Related content.** Show posts that are related in meaning, without tagging or linking them by hand.
* **Accessible.** The vragen.ai widget is built to WCAG 2.1 level AA.

= For you: insight into what your visitors need =

The plugin keeps vragen.ai fed with your content. In your vragen.ai environment you then see what visitors ask and how well your content answers them.

* **Every question, word for word.** Read back each question with its exact wording and context.
* **Themes at a glance.** The Analysis groups all questions into themes, so you see right away what most questions are about.
* **Feedback over time.** See how visitors rate the answers, and spot peaks and changes.
* **Evaluation scores.** Each theme is scored on reliability, relevance and source relevance, so you know which answers need attention.
* **Gaps in your content.** Questions without a good answer show you what to write, fix or clarify next.

= For your WordPress site: set up once, then it runs =

* **Always up to date.** When you publish, update, trash or delete a post, the plugin updates vragen.ai straight away. No exports, no waiting for a crawl.
* **No developer needed.** Install the plugin, enter your customer name and API token, choose your post types and run a bulk sync once.
* **Your content as it really is.** Titles, metadata, taxonomies, languages, ACF fields and attached PDFs come along.
* **Multilingual.** Works with WPML and Polylang.
* **Built for large sites.** Syncing runs in the background, so your site stays fast.

= Safe and under your control =

Vragen.ai is built by SWIS, a digital agency in Leiden, the Netherlands. SWIS is certified to ISO 27001, NEN 7510 and ISO 9001.

* Your data is stored in the Netherlands.
* Conversations are never used to train AI models.
* You choose the language model. The whole chain can run in the Netherlands.

Read more on [security and data protection](https://www.vragen.ai/en/security/).

= What you need =

A vragen.ai account. Plans start at €49 per month, see [pricing](https://www.vragen.ai/en/pricing/). No account yet? [Create a demo](https://www.vragen.ai/en/request-a-demo/) or [try vragen.ai yourself](https://www.vragen.ai/en/try-it/).

= Features in detail =

* Automatic sync on publish/update/trash/delete for the post types you choose.
* Background processing through Action Scheduler, large sites stay responsive.
* Bulk synchronisation of all published content from the settings screen.
* WP-CLI command (`wp vragenai sync`) for scripted/initial imports.
* Native ACF content support for supported regular fields and stored ACF block fields.
* PDF attachments (directly attached media and ACF file fields) are passed to vragen.ai for server-side text extraction.
* Multilingual support for WPML and Polylang: all translations of a post are merged into a single document, tagged with every language it is available in.
* Embed a vragen.ai deployment (page, popup or popover) with the Vragen.ai block, or load one site-wide from the settings screen.
* Replace the native WordPress search with semantic search from vragen.ai (optional), with automatic fallback to the built-in search if the API is unavailable.
* Show related content for the current post with the Vragen.ai related-content block or the `[vragenai_related]` shortcode.

The admin UI is available in English and Dutch, following your site language.

= For developers =

Filters to customise indexing and search:

* `vragenai_should_index_post`
* `vragenai_post_is_visible`
* `vragenai_document_render_content_source`
* `vragenai_document_content_fragments`
* `vragenai_include_acf_field`
* `vragenai_include_acf_block`
* `vragenai_acf_block_denylist`
* `vragenai_document_attributes`
* `vragenai_native_search_options`

Credentials and the API domain can be set as constants in `wp-config.php`, see Installation.

== External services ==

This plugin connects to the vragen.ai knowledge base API. This connection is the sole purpose of the plugin: it is required to index your content so it can be searched and answered through vragen.ai. The service is operated by SWIS (https://swis.nl) on behalf of Vragen.ai (https://vragen.ai).

The API is reached at the endpoint configured for your account, `https://{customer}.vragen.ai/api/v1` by default (the root domain can be changed with the `VRAGENAI_API_DOMAIN` constant).

When you publish, update, trash or delete content of an enabled post type and when you run a bulk synchronisation the plugin sends the following to that endpoint:

* the post title, public URL, rendered content, supported ACF field content and any additional content fragments supplied through filters;
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

= Do I need a vragen.ai account, and what does it cost? =

Yes. The plugin connects your site to your own vragen.ai knowledge base. Plans start at €49 per month; all plans are listed on https://www.vragen.ai/en/pricing/. You can create a demo first at https://www.vragen.ai/en/request-a-demo/.

= Can the AI make up answers? =

Vragen.ai only answers from the sources you choose and has no access to the internet. If the answer isn't in those sources, it says so and points the way. That is a design choice, not a guarantee that a language model never summarises something wrongly. That's why every answer shows its source, and why you test the answers in a separate environment before anything goes live.

= What insights do I get, and where? =

In your vragen.ai environment, not in WordPress. You see every question visitors ask, the Analysis groups them into themes, and each theme shows visitor feedback and evaluation scores for reliability, relevance and source relevance. Read more about the [Analysis](https://www.vragen.ai/en/docs/analysis/).

= Where is my data stored? =

In the Netherlands. You choose the language model; the whole chain can run in the Netherlands. Conversations are never used to train AI models. See https://www.vragen.ai/en/security/ for the details, including the data processing agreement.

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

= What content gets indexed, and how is visibility handled? =

Only publicly visible posts are indexed and shown: the plugin skips draft, pending, scheduled, private, trashed, non-public custom statuses and password-protected posts, on both the sync and the read paths. For content a plugin gates by role or membership, add a `vragenai_post_is_visible` filter returning false for those posts, and they are kept out of indexing, related content and search together. Returning false to hide a post is the safe use; returning true on the read side can also reveal an otherwise-hidden post, so only do that when you mean to.

One limitation to know: a post is indexed whole. The plugin renders a post's content in a background job, without a logged-in visitor, so if you hide only **part** of an otherwise public post (a members-only block or section, a conditional field), that part can still be rendered at index time even though a visitor does not see it on the page. If you use section-level gating, strip the restricted content before indexing with the `vragenai_document_render_content_source` or `vragenai_document_content_fragments` filters, exclude specific ACF fields with `vragenai_include_acf_field`, or keep the whole post out with `vragenai_post_is_visible` or `vragenai_should_index_post`.

= The embed doesn't appear on a site with a Content Security Policy (CSP)? =

If you use the Vragen.ai embed block or the site-wide embed, it loads a script from your vragen.ai instance and makes API calls to it, so a strict CSP must allow your vragen.ai domain:

* script-src 'self' https://vragen.ai https://*.vragen.ai;
* connect-src 'self' https://vragen.ai https://*.vragen.ai;
* img-src 'self' data: https://vragen.ai https://*.vragen.ai;
* style-src 'self' 'unsafe-inline';

Note that `*.vragen.ai` does not match the bare `vragen.ai`, so include both. If you set the `VRAGENAI_API_DOMAIN` constant, allow that domain instead.

== Screenshots ==

1. The settings screen: connect your vragen.ai account and choose which post types are synchronised.
2. Add the Vragen.ai block to a page or template and point it at a deployment.
3. Bulk synchronisation of existing published content from the settings screen.
4. Your synced content as processed knowledge items in the vragen.ai knowledge base.
5. A visitor's question answered from your own content, with the source underneath.
6. The Analysis in your vragen.ai environment: all questions grouped into themes, with feedback and evaluation scores.

== Changelog ==

= 2.1.3 =
* Fix: the related content block, the `[vragenai_related]` shortcode and semantic native search no longer list posts a visitor may not see: scheduled, private, pending, a non-public custom status, or password-protected.
* Fix: a published post that is rescheduled, moved to a non-public custom status or password-protected is removed from the vragen.ai knowledge base, alongside the existing draft, private, pending and trashed transitions.
* Fix: when a post is unpublished, trashed or password-protected, the documents for its attached media are re-evaluated and removed too, instead of lingering until the next full sync.
* Fix: a media-library attachment with its own password is no longer indexed, nor is a password-protected PDF pulled into an otherwise public post, whether directly attached or referenced through an ACF file field.
* Fix: a document now advertises only the translations a visitor can see, so an unpublished or password-protected translation is no longer announced in the knowledge base.
* Fix: `wp vragenai sync` now honours the `vragenai_should_index_post` filter, so content a site excludes is not re-indexed by a full sync.
* Dev: new `vragenai_post_is_visible` filter, applied to indexing and the read paths (attachments included) from one shared check, so membership or paywall plugins can keep gated content out of the knowledge base, related content and search in one place.

Thanks to Marish Pasco for finding and reporting these.

= 2.1.2 =
* Fix: password-protected posts are no longer indexed in the vragen.ai knowledge base.

Thanks to Marish Pasco for finding and reporting this.

= 2.1.1 =
* New: native ACF content support for supported regular fields and stored ACF block fields.
* New: ACF file fields can contribute PDF URLs for server-side extraction by vragen.ai.
* Dev: added filters for customising included ACF fields and blocks.

= 2.1.0 =
* New: embed a vragen.ai deployment with the Vragen.ai block, or load a popup/popover deployment site-wide from the settings screen.
* New: optional semantic native search that replaces the built-in WordPress search with vragen.ai results, with automatic fallback to the native search on error or timeout.
* New: related-content block and `[vragenai_related]` shortcode that show semantically related posts as a list or cards.
* New: search tuning on the settings screen — maximum semantic distance and hybrid alpha (semantic vs. keyword), plus a language-fallback toggle.

= 2.0.0 =
* Initial public release.

== Upgrade Notice ==

= 2.1.3 =
Security fix: related content and native search no longer list posts a visitor cannot open, including password-protected posts.

= 2.1.2 =
Security fix: password-protected content is no longer indexed in the knowledge base.

= 2.1.1 =
Adds native ACF content support and ACF PDF file-field indexing.

= 2.1.0 =
Adds embedding blocks, optional semantic native search and a related-content block.

= 2.0.0 =
Initial public release.
