<?php

namespace VragenAI;

/**
 * Weglot support.
 *
 * Weglot differs fundamentally from WPML and Polylang: it translates on the fly at the edge
 * rather than storing a post per language. There is no translated post to look up — only a
 * configured language list and a URL scheme, which by default prefixes the path with the
 * language code (https://example.com/fr/about).
 *
 * So instead of resolving a translation group from the database, this derives the group from
 * configuration: every enabled destination language is available for every synced post.
 */
class WeglotLanguageResolver
{
    /**
     * Weglot stores everything in this one option.
     */
    private const OPTION = 'weglot_settings';

    public function isActive(): bool
    {
        if (! defined('WEGLOT_VERSION') && ! function_exists('weglot_get_service')) {
            return false;
        }

        return $this->originalLanguage() !== null && $this->destinationLanguages() !== [];
    }

    /**
     * The language the site is authored in — Weglot's "original" language, which is the one the
     * stored post content is actually in.
     */
    public function originalLanguage(): ?string
    {
        $settings = $this->settings();

        $original = $settings['language_from'] ?? null;

        return is_string($original) && $original !== '' ? $original : null;
    }

    /**
     * Every enabled destination language.
     *
     * Weglot has used a few shapes for this over the years; `languages` is the current one and
     * `destination_language` the older, so both are read. A language with a `custom_code` is
     * served under that code, which is what appears in URLs.
     *
     * @return array<int, string>
     */
    public function destinationLanguages(): array
    {
        $settings = $this->settings();
        $raw = $settings['languages'] ?? $settings['destination_language'] ?? [];

        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }

        if (! is_array($raw)) {
            return [];
        }

        $languages = [];

        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $languages[] = $entry;

                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            // Explicitly disabled languages are configured but not served, so linking to them
            // would produce URLs that do not resolve.
            if (array_key_exists('enabled', $entry) && ! $entry['enabled']) {
                continue;
            }

            $code = $entry['custom_code'] ?? $entry['language_to'] ?? null;

            if (is_string($code) && $code !== '') {
                $languages[] = $code;
            }
        }

        return array_values(array_unique(array_filter($languages)));
    }

    /**
     * Every language the post is available in, original first.
     *
     * @return array<int, string>
     */
    public function languagesFor(\WP_Post $post): array
    {
        $original = $this->originalLanguage();

        if ($original === null) {
            return [];
        }

        return array_values(array_unique([$original, ...$this->destinationLanguages()]));
    }

    /**
     * The language the current request is being served in.
     *
     * Weglot serves the original language on the unprefixed URL and every other language under
     * a path prefix, so the prefix is the signal. Weglot's own helper is preferred when it is
     * available, since a site can reconfigure the URL scheme.
     */
    public function currentLanguage(): ?string
    {
        if (function_exists('weglot_get_current_language')) {
            $current = weglot_get_current_language();

            if (is_string($current) && $current !== '') {
                return $current;
            }
        }

        $requestUri = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
            : '/';

        $path = (string) wp_parse_url($requestUri, PHP_URL_PATH);
        $segment = strtok(ltrim($path, '/'), '/');

        if (is_string($segment) && in_array($segment, $this->destinationLanguages(), true)) {
            return $segment;
        }

        return $this->originalLanguage();
    }

    /**
     * The translated URL for a language, derived from the permalink by inserting the language
     * code as the first path segment — Weglot's default scheme.
     *
     * Returns null for the original language, which is served on the unprefixed URL.
     */
    public function translatedUrl(string $permalink, string $language): ?string
    {
        if ($language === $this->originalLanguage()) {
            return null;
        }

        $parts = wp_parse_url($permalink);

        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        $url = sprintf(
            '%s://%s%s/%s%s',
            $parts['scheme'] ?? 'https',
            $parts['host'],
            isset($parts['port']) ? ':'.$parts['port'] : '',
            ltrim($language, '/'),
            $path === '/' ? '' : $path
        );

        if (! empty($parts['query'])) {
            $url .= '?'.$parts['query'];
        }

        return $url;
    }

    /**
     * Translate a single string through Weglot, used for post titles.
     *
     * Titles are short and change rarely, so the result is cached in post meta keyed by a hash
     * of the source text: a save that does not touch the title costs no API calls at all.
     */
    public function translateTitle(\WP_Post $post, string $title, string $language): ?string
    {
        if ($title === '' || $language === $this->originalLanguage()) {
            return null;
        }

        $metaKey = '_vragenai_weglot_title_'.$language;
        $cached = get_post_meta($post->ID, $metaKey, true);
        $hash = md5($title);

        if (is_array($cached) && ($cached['hash'] ?? null) === $hash && ! empty($cached['value'])) {
            return (string) $cached['value'];
        }

        $translated = $this->requestTranslation($title, $language);

        if ($translated === null) {
            return null;
        }

        update_post_meta($post->ID, $metaKey, ['hash' => $hash, 'value' => $translated]);

        return $translated;
    }

    private function requestTranslation(string $text, string $language): ?string
    {
        $settings = $this->settings();
        $apiKey = $settings['api_key_private'] ?? $settings['api_key'] ?? null;
        $original = $this->originalLanguage();

        if (! is_string($apiKey) || $apiKey === '' || $original === null) {
            return null;
        }

        $response = wp_remote_post(
            'https://api.weglot.com/translate?api_key='.rawurlencode($apiKey),
            [
                'timeout' => 10,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode([
                    'l_from' => $original,
                    'l_to' => $language,
                    // 1 = plain text. Titles carry no markup.
                    'request_url' => home_url('/'),
                    'words' => [['w' => $text, 't' => 1]],
                ]),
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
            // A failed translation is not fatal: the document simply advertises the language
            // without a translated title, and vragen.ai falls back to the original.
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $translated = $body['to_words'][0] ?? null;

        return is_string($translated) && $translated !== '' ? $translated : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return (array) get_option(self::OPTION, []);
    }
}
