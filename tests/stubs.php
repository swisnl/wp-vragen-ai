<?php

/**
 * Minimal stand-ins for WordPress classes referenced by type-hints so the
 * unit tests can run without a full WordPress bootstrap.
 */
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
