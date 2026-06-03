<?php

namespace VragenAI;

class LanguageResolver
{
    public function getPostLanguage(\WP_Post $post): string
    {
        // WPML
        if (defined('ICL_LANGUAGE_CODE')) {
            $info = apply_filters('wpml_post_language_details', null, $post->ID); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML-defined integration hook.
            if (is_array($info) && ! empty($info['language_code'])) {
                return $info['language_code'];
            }
        }

        // Polylang
        if (function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($post->ID, 'slug');
            if ($lang) {
                return $lang;
            }
        }

        return get_locale();
    }

    /**
     * The site's default language. The
     * document content is sourced from this language's translation.
     */
    public function getDefaultLanguage(): string
    {
        // WPML
        if (defined('ICL_LANGUAGE_CODE')) {
            $default = apply_filters('wpml_default_language', null); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML-defined integration hook.
            if (is_string($default) && $default !== '') {
                return $default;
            }
        }

        // Polylang
        if (function_exists('pll_default_language')) {
            $default = pll_default_language('slug');
            if (is_string($default) && $default !== '') {
                return $default;
            }
        }

        return get_locale();
    }

    /**
     * Map of language code => post ID for every translation in the post's
     * translation group, including the post itself. Monolingual sites (or
     * untranslated posts) return a single entry.
     *
     * @return array<string, int>
     */
    public function getTranslations(\WP_Post $post): array
    {
        // WPML
        if (defined('ICL_LANGUAGE_CODE')) {
            $type = 'post_'.$post->post_type;
            $trid = apply_filters('wpml_element_trid', null, $post->ID, $type); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML-defined integration hook.
            if ($trid) {
                $translations = apply_filters('wpml_get_element_translations', null, $trid, $type); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML-defined integration hook.
                if (is_array($translations)) {
                    $map = [];
                    foreach ($translations as $translation) {
                        if (isset($translation->language_code, $translation->element_id)) {
                            $map[(string) $translation->language_code] = (int) $translation->element_id;
                        }
                    }
                    if ($map !== []) {
                        return $map;
                    }
                }
            }
        }

        // Polylang
        if (function_exists('pll_get_post_translations')) {
            $map = pll_get_post_translations($post->ID);
            if ($map !== []) {
                return array_map('intval', $map);
            }
        }

        return [$this->getPostLanguage($post) => $post->ID];
    }

    /**
     * The post ID whose content represents the translation group. Prefers the
     * default-language translation; otherwise falls back to the lowest post ID
     * so the choice is stable and language-independent.
     */
    public function getCanonicalPostId(\WP_Post $post): int
    {
        $translations = $this->getTranslations($post);
        $default = $this->getDefaultLanguage();

        return $translations[$default] ?? (int) min($translations);
    }
}
