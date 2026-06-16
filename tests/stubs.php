<?php

/**
 * Minimal stand-ins for WordPress classes and constants referenced by the
 * plugin so the unit tests can run without a full WordPress bootstrap.
 */
defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        /** @param array<string, mixed> $data */
        public function __construct(public string $code = '', public string $message = '', public array $data = []) {}
    }
}

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;

        public string $post_type = 'post';

        public string $post_title = '';

        public string $post_content = '';

        public string $post_status = 'publish';

        public string $post_date = '';

        public string $post_modified = '';

        public int $post_author = 0;

        /** @param array<string, mixed> $data */
        public function __construct(array $data = [])
        {
            foreach ($data as $key => $value) {
                $this->$key = $value;
            }
        }
    }
}

if (! class_exists('WP_Query')) {
    class WP_Query
    {
        public int $found_posts = 0;

        public int $max_num_pages = 0;

        public bool $mainQuery = true;

        public bool $searchQuery = true;

        /** @var array<string, mixed> */
        public array $vars = [];

        /** @param array<string, mixed> $vars */
        public function __construct(array $vars = [])
        {
            $this->vars = $vars;
        }

        public function get(string $key, mixed $default = ''): mixed
        {
            return $this->vars[$key] ?? $default;
        }

        public function is_main_query(): bool
        {
            return $this->mainQuery;
        }

        public function is_search(): bool
        {
            return $this->searchQuery;
        }
    }
}
