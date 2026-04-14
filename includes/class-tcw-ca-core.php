<?php
if (!defined('ABSPATH')) {
    exit;
}

class TCW_CA_Core {
    const TABLE_TOPICS = 'tcw_ca_topics';
    const TABLE_LOGS = 'tcw_ca_logs';
    const OPTION_KEY = 'tcw_ca_settings';

    public function table_topics() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_TOPICS;
    }

    public function table_logs() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_LOGS;
    }

    public function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sql_topics = "CREATE TABLE {$this->table_topics()} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            topic text NOT NULL,
            category varchar(190) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            preserve_title tinyint(1) NOT NULL DEFAULT 1,
            rewritten_topic text NULL,
            published_post_id bigint(20) unsigned NULL,
            last_error longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $charset;";

        $sql_logs = "CREATE TABLE {$this->table_logs()} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            context longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $charset;";

        dbDelta($sql_topics);
        dbDelta($sql_logs);
    }

    public function register_default_settings() {
        $defaults = array(
            'anthropic_api_key' => '',
            'pexels_api_key' => '',
            'anthropic_model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 4000,
            'default_author' => 0,
            'default_post_status' => 'draft',
            'fallback_category' => 'Personal Finance',
            'site_name' => get_bloginfo('name'),
            'site_focus' => 'Canadian personal finance and small business finance',
            'brand_voice' => 'clear, trustworthy, practical, content-forward',
            'enable_pexels' => 0,
            'retry_attempts' => 4,
            'scheduler_enabled' => 0,
            'scheduler_frequency' => 'daily',
            'scheduler_time' => '09:00',
        );
        $current = get_option(self::OPTION_KEY, array());
        update_option(self::OPTION_KEY, wp_parse_args($current, $defaults));
    }

    public function get_settings() {
        $defaults = array(
            'anthropic_api_key' => '',
            'pexels_api_key' => '',
            'anthropic_model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 4000,
            'default_author' => 0,
            'default_post_status' => 'draft',
            'fallback_category' => 'Personal Finance',
            'site_name' => get_bloginfo('name'),
            'site_focus' => 'Canadian personal finance and small business finance',
            'brand_voice' => 'clear, trustworthy, practical, content-forward',
            'enable_pexels' => 0,
            'retry_attempts' => 4,
            'scheduler_enabled' => 0,
            'scheduler_frequency' => 'daily',
            'scheduler_time' => '09:00',
        );
        return wp_parse_args(get_option(self::OPTION_KEY, array()), $defaults);
    }

    public function update_settings($settings) {
        $current = $this->get_settings();
        $merged = array_merge($current, $settings);
        update_option(self::OPTION_KEY, $merged);
        $this->sync_schedule($merged);
    }

    public function sync_schedule($settings = null) {
        if ($settings === null) {
            $settings = $this->get_settings();
        }
        wp_clear_scheduled_hook('tcw_ca_publish_next_scheduled');
        if (empty($settings['scheduler_enabled'])) {
            return;
        }
        $time_str = isset($settings['scheduler_time']) ? (string) $settings['scheduler_time'] : '09:00';
        if (!preg_match('/^(\d{2}):(\d{2})$/', $time_str, $m)) {
            $time_str = '09:00';
            $m = array('', '09', '00');
        }
        $hour = max(0, min(23, (int) $m[1]));
        $minute = max(0, min(59, (int) $m[2]));
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $now = new DateTime('now', $tz);
        $next = new DateTime('now', $tz);
        $next->setTime($hour, $minute, 0);
        if ($next <= $now) {
            $next->modify('+1 day');
        }
        $frequency = isset($settings['scheduler_frequency']) ? sanitize_key($settings['scheduler_frequency']) : 'daily';
        if (!in_array($frequency, array('daily', 'weekly', 'monthly'), true)) {
            $frequency = 'daily';
        }
        wp_schedule_event($next->getTimestamp(), $frequency, 'tcw_ca_publish_next_scheduled');
    }

    public function run_scheduled_publish() {
        $result = $this->publish_next_pending(true);
        if (is_wp_error($result) && $result->get_error_code() !== 'no_pending') {
            $this->log('error', 'Scheduled publish failed.', array('error' => $result->get_error_message()));
        }
    }

    public function log($level, $message, $context = array()) {
        global $wpdb;
        $wpdb->insert(
            $this->table_logs(),
            array(
                'level' => sanitize_text_field($level),
                'message' => wp_strip_all_tags($message),
                'context' => wp_json_encode($context),
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s')
        );
    }

    public function get_logs($limit = 50) {
        global $wpdb;
        $limit = max(1, intval($limit));
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_logs()} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
    }

    public function get_topics() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->table_topics()} ORDER BY id DESC", ARRAY_A);
    }

    public function get_topic($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_topics()} WHERE id = %d", $id), ARRAY_A);
    }

    public function add_topic($data) {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert(
            $this->table_topics(),
            array(
                'topic' => sanitize_textarea_field($data['topic']),
                'category' => sanitize_text_field($data['category']),
                'status' => sanitize_text_field($data['status']),
                'preserve_title' => !empty($data['preserve_title']) ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s')
        );
        return (int) $wpdb->insert_id;
    }

    public function update_topic($id, $data) {
        global $wpdb;
        $update = array(
            'topic' => sanitize_textarea_field($data['topic']),
            'category' => sanitize_text_field($data['category']),
            'status' => sanitize_text_field($data['status']),
            'preserve_title' => !empty($data['preserve_title']) ? 1 : 0,
            'updated_at' => current_time('mysql'),
        );
        $wpdb->update($this->table_topics(), $update, array('id' => (int) $id));
    }

    public function delete_topic($id) {
        global $wpdb;
        $wpdb->delete($this->table_topics(), array('id' => (int) $id), array('%d'));
    }

    public function import_csv($file_path) {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return new WP_Error('csv_open_failed', 'Could not open CSV file.');
        }
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return new WP_Error('csv_header_missing', 'CSV header row is missing.');
        }
        $header = array_map('trim', $header);
        $count = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $row = array_pad($row, count($header), '');
            $assoc = array_combine($header, $row);
            $topic = isset($assoc['topic']) ? trim($assoc['topic']) : '';
            if ($topic === '') {
                continue;
            }
            $this->add_topic(array(
                'topic' => $topic,
                'category' => isset($assoc['category']) ? trim($assoc['category']) : '',
                'status' => isset($assoc['status']) && trim($assoc['status']) !== '' ? trim($assoc['status']) : 'pending',
                'preserve_title' => !empty($assoc['preserve_title']) ? 1 : 0,
            ));
            $count++;
        }
        fclose($handle);
        return $count;
    }

    public function publish_topic($topic_id) {
        $topic = $this->get_topic($topic_id);
        if (!$topic) {
            return new WP_Error('missing_topic', 'Topic not found.');
        }

        $this->mark_topic_processing($topic_id);
        $this->log('info', 'Started publishing topic.', array('topic_id' => (int) $topic_id, 'topic' => $topic['topic']));

        $article = $this->generate_article($topic);
        if (is_wp_error($article)) {
            $this->mark_topic_failed($topic_id, $article->get_error_message());
            $this->log('error', 'Publishing failed.', array('topic_id' => (int) $topic_id, 'error' => $article->get_error_message()));
            return $article;
        }

        $featured_media_id = 0;
        if (!empty($article['image_search_query'])) {
            $featured_media_id = $this->maybe_attach_pexels_image($article['image_search_query'], $article['title']);
        }

        $post_id = $this->create_post_from_article($topic, $article, $featured_media_id);
        if (is_wp_error($post_id)) {
            $this->mark_topic_failed($topic_id, $post_id->get_error_message());
            $this->log('error', 'Publishing failed.', array('topic_id' => (int) $topic_id, 'error' => $post_id->get_error_message()));
            return $post_id;
        }

        $this->mark_topic_done($topic_id, $post_id, isset($article['rewritten_topic']) ? $article['rewritten_topic'] : '');
        $this->log('info', 'Published topic.', array('topic_id' => (int) $topic_id, 'post_id' => (int) $post_id));
        return $post_id;
    }

    public function publish_next_pending($scheduled = false) {
        global $wpdb;
        $topic_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table_topics()} WHERE status = %s ORDER BY id ASC LIMIT 1", 'pending'));
        if (!$topic_id) {
            $this->log('info', $scheduled ? 'No pending topic found for scheduled run.' : 'No pending topic found for manual run.', array());
            return new WP_Error('no_pending', 'No pending topic found.');
        }
        return $this->publish_topic((int) $topic_id);
    }

    private function mark_topic_processing($id) {
        global $wpdb;
        $wpdb->update($this->table_topics(), array('status' => 'processing', 'updated_at' => current_time('mysql')), array('id' => (int) $id));
    }

    private function mark_topic_failed($id, $error) {
        global $wpdb;
        $wpdb->update($this->table_topics(), array('status' => 'failed', 'last_error' => wp_strip_all_tags($error), 'updated_at' => current_time('mysql')), array('id' => (int) $id));
    }

    private function mark_topic_done($id, $post_id, $rewritten_topic = '') {
        global $wpdb;
        $wpdb->update($this->table_topics(), array(
            'status' => 'done',
            'published_post_id' => (int) $post_id,
            'rewritten_topic' => $rewritten_topic,
            'last_error' => '',
            'updated_at' => current_time('mysql'),
        ), array('id' => (int) $id));
    }

    private function generate_article($topic_row) {
        $settings = $this->get_settings();
        if (empty($settings['anthropic_api_key'])) {
            return new WP_Error('missing_key', 'Anthropic API key is missing.');
        }

        $topic = trim($topic_row['topic']);
        $category = trim($topic_row['category']);
        $preserve = !empty($topic_row['preserve_title']);

        $system = "You are a senior content writer for {$settings['site_name']}. "
            . "Site focus: {$settings['site_focus']}. "
            . "Brand voice: {$settings['brand_voice']}. "
            . "Write only in English. Return only valid minified JSON with keys: title, slug, meta_description, focus_keyword, tags, category, image_search_query, content. "
            . "Content must be HTML, 900-1400 words, content-forward, trustworthy, practical, with at least 2 H2 headings and a short FAQ section at the end. "
            . "Do not include markdown fences.";

        $user = "Create a high-quality article for this topic: {$topic}. "
            . ($category !== '' ? "Primary category: {$category}. " : '')
            . ($preserve ? "The article title must remain exactly this topic text. " : "You may improve the title slightly if needed. ")
            . "The meta description must be 140-160 characters. Use a realistic SEO slug. image_search_query should be 2-4 words.";

        $body = array(
            'model' => $settings['anthropic_model'],
            'max_tokens' => (int) $settings['max_tokens'],
            'system' => $system,
            'messages' => array(
                array('role' => 'user', 'content' => $user),
            ),
        );

        $attempts = max(1, (int) $settings['retry_attempts']);
        $response = null;
        $last_error = '';
        for ($i = 1; $i <= $attempts; $i++) {
            $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
                'timeout' => 90,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'x-api-key' => $settings['anthropic_api_key'],
                    'anthropic-version' => '2023-06-01',
                ),
                'body' => wp_json_encode($body),
            ));

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                $resp_body = wp_remote_retrieve_body($response);
                if ($code >= 200 && $code < 300) {
                    $decoded = json_decode($resp_body, true);
                    $text = $this->extract_anthropic_text($decoded);
                    $article = $this->parse_article_json($text);
                    if (!is_wp_error($article)) {
                        if ($preserve) {
                            $article['title'] = $topic;
                        }
                        return $article;
                    }
                    $last_error = $article->get_error_message();
                } else {
                    $msg = $this->extract_error_message($resp_body);
                    $last_error = $msg !== '' ? $msg : ('Anthropic HTTP ' . $code);
                    if (!in_array($code, array(429, 500, 502, 503, 504, 529), true) && stripos($last_error, 'overload') === false) {
                        break;
                    }
                    $this->log('warning', 'Anthropic temporary error, retrying.', array('attempt' => $i, 'error' => $last_error));
                    sleep(min(8, $i * 2));
                }
            }
        }

        return new WP_Error('anthropic_failed', $last_error !== '' ? $last_error : 'Article generation failed.');
    }

    private function extract_anthropic_text($decoded) {
        if (!is_array($decoded) || empty($decoded['content']) || !is_array($decoded['content'])) {
            return '';
        }
        foreach ($decoded['content'] as $block) {
            if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                return (string) $block['text'];
            }
        }
        return '';
    }

    private function extract_error_message($body) {
        $decoded = json_decode($body, true);
        if (isset($decoded['error']['message'])) {
            return (string) $decoded['error']['message'];
        }
        return is_string($body) ? wp_strip_all_tags($body) : '';
    }

    private function parse_article_json($raw) {
        $raw = trim((string) $raw);
        $raw = preg_replace('/^```json\s*/', '', $raw);
        $raw = preg_replace('/```$/', '', $raw);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            if (preg_match('/\{.*\}/s', $raw, $m)) {
                $decoded = json_decode($m[0], true);
            }
        }
        if (!is_array($decoded)) {
            return new WP_Error('bad_json', 'Model returned invalid JSON.');
        }
        $required = array('title', 'slug', 'meta_description', 'focus_keyword', 'content');
        foreach ($required as $field) {
            if (empty($decoded[$field])) {
                return new WP_Error('missing_field', 'Model response missing field: ' . $field);
            }
        }
        $decoded['tags'] = isset($decoded['tags']) && is_array($decoded['tags']) ? array_values(array_filter(array_map('sanitize_text_field', $decoded['tags']))) : array();
        $decoded['category'] = isset($decoded['category']) ? sanitize_text_field($decoded['category']) : '';
        $decoded['image_search_query'] = isset($decoded['image_search_query']) ? sanitize_text_field($decoded['image_search_query']) : '';
        $decoded['title'] = sanitize_text_field($decoded['title']);
        $decoded['slug'] = sanitize_title($decoded['slug']);
        $decoded['meta_description'] = sanitize_text_field($decoded['meta_description']);
        $decoded['focus_keyword'] = sanitize_text_field($decoded['focus_keyword']);
        $decoded['content'] = wp_kses_post($decoded['content']);
        return $decoded;
    }

    private function create_post_from_article($topic_row, $article, $featured_media_id) {
        $settings = $this->get_settings();
        $author = (int) $settings['default_author'];
        if ($author <= 0) {
            $author = get_current_user_id();
        }
        $category_name = $article['category'] !== '' ? $article['category'] : ($topic_row['category'] !== '' ? $topic_row['category'] : $settings['fallback_category']);
        $category_ids = $this->resolve_category_ids(array($category_name));
        $tag_ids = $this->resolve_tag_ids($article['tags']);

        $post_id = wp_insert_post(array(
            'post_type' => 'post',
            'post_status' => in_array($settings['default_post_status'], array('draft', 'publish'), true) ? $settings['default_post_status'] : 'draft',
            'post_title' => $article['title'],
            'post_name' => $article['slug'],
            'post_excerpt' => $article['meta_description'],
            'post_content' => $article['content'],
            'post_author' => $author,
            'post_category' => $category_ids,
            'tags_input' => array(),
            'meta_input' => array(
                'rank_math_focus_keyword' => $article['focus_keyword'],
                'rank_math_title' => $article['title'] . ' | ' . get_bloginfo('name'),
                'rank_math_description' => $article['meta_description'],
            ),
        ), true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }
        if (!empty($tag_ids)) {
            wp_set_post_terms($post_id, $tag_ids, 'post_tag', false);
        }
        if ($featured_media_id > 0) {
            set_post_thumbnail($post_id, $featured_media_id);
        }
        return (int) $post_id;
    }

    private function resolve_category_ids($names) {
        $ids = array();
        foreach ($names as $name) {
            $name = sanitize_text_field($name);
            if ($name === '') {
                continue;
            }
            $term = term_exists($name, 'category');
            if (!$term) {
                $term = wp_insert_term($name, 'category');
            }
            if (!is_wp_error($term)) {
                $ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
            }
        }
        return $ids;
    }

    private function resolve_tag_ids($tags) {
        $ids = array();
        foreach ($tags as $tag) {
            $tag = sanitize_text_field($tag);
            if ($tag === '') {
                continue;
            }
            $term = term_exists($tag, 'post_tag');
            if (!$term) {
                $term = wp_insert_term($tag, 'post_tag');
            }
            if (!is_wp_error($term)) {
                $ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
            }
        }
        return $ids;
    }

    private function maybe_attach_pexels_image($query, $title) {
        $settings = $this->get_settings();
        if (empty($settings['enable_pexels']) || empty($settings['pexels_api_key'])) {
            return 0;
        }

        $response = wp_remote_get(add_query_arg(array(
            'query' => $query,
            'per_page' => 1,
            'orientation' => 'landscape',
        ), 'https://api.pexels.com/v1/search'), array(
            'timeout' => 45,
            'headers' => array('Authorization' => $settings['pexels_api_key']),
        ));

        if (is_wp_error($response)) {
            $this->log('warning', 'Pexels lookup failed.', array('error' => $response->get_error_message()));
            return 0;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['photos'][0]['src']['large2x'])) {
            return 0;
        }
        $image_url = $data['photos'][0]['src']['large2x'];
        $tmp = download_url($image_url, 60);
        if (is_wp_error($tmp)) {
            return 0;
        }
        $file = array(
            'name' => sanitize_title($title) . '.jpg',
            'tmp_name' => $tmp,
        );
        $attachment_id = media_handle_sideload($file, 0, $title);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return 0;
        }
        return (int) $attachment_id;
    }
}
