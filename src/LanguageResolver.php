<?php

namespace VragenAI;

class LanguageResolver
{
    public function getPostLanguage(\WP_Post $post): string
    {
        // WPML
        if (defined('ICL_LANGUAGE_CODE')) {
            $info = apply_filters('wpml_post_language_details', null, $post->ID);
            if (is_array($info) && !empty($info['language_code'])) {
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

    public function getCanonicalLanguage(\WP_Post $post): string
    {
        // WPML: resolve the original language of a translated post
        if (defined('ICL_LANGUAGE_CODE')) {
            $details = apply_filters('wpml_element_language_details', null, [
                'element_id'   => $post->ID,
                'element_type' => 'post_' . $post->post_type,
            ]);
            if (is_object($details) && !empty($details->original_language_code)) {
                return $details->original_language_code;
            }
        }

        return $this->getPostLanguage($post);
    }
}
