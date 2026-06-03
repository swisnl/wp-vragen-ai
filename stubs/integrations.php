<?php

/**
 * Stubs for optional third-party integrations.
 *
 * These functions are only defined when the corresponding plugin (ACF,
 * Polylang) is active. They are guarded behind function_exists() checks in
 * the plugin code; the stubs exist purely so static analysis can resolve
 * their signatures.
 */

/**
 * Advanced Custom Fields: get all field objects for a post.
 *
 * @return array<string, array<string, mixed>>|false
 */
function get_field_objects(int|string $post_id): array|false {}

/**
 * Polylang: get the language of a post.
 */
function pll_get_post_language(int $post_id, string $field = 'slug'): string|false {}

/**
 * Polylang: get the post's translation group as [language => post_id].
 *
 * @return array<string, int>
 */
function pll_get_post_translations(int $post_id): array {}

/**
 * Polylang: get the default language of the site.
 */
function pll_default_language(string $field = 'slug'): string|false {}
