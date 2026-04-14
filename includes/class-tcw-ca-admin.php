<?php
if (!defined('ABSPATH')) {
    exit;
}

class TCW_CA_Admin {
    private $core;

    public function __construct($core) {
        $this->core = $core;
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_post_tcw_ca_save_settings', array($this, 'handle_save_settings'));
        add_action('admin_post_tcw_ca_add_topic', array($this, 'handle_add_topic'));
        add_action('admin_post_tcw_ca_update_topic', array($this, 'handle_update_topic'));
        add_action('admin_post_tcw_ca_delete_topic', array($this, 'handle_delete_topic'));
        add_action('admin_post_tcw_ca_import_csv', array($this, 'handle_import_csv'));
        add_action('admin_post_tcw_ca_publish_topic', array($this, 'handle_publish_topic'));
        add_action('admin_post_tcw_ca_publish_next', array($this, 'handle_publish_next'));
    }

    public function admin_menu() {
        add_menu_page('TCW Content Assistant', 'TCW Content', 'manage_options', 'tcw-ca-topics', array($this, 'render_topics_page'), 'dashicons-edit-page', 58);
        add_submenu_page('tcw-ca-topics', 'Topics', 'Topics', 'manage_options', 'tcw-ca-topics', array($this, 'render_topics_page'));
        add_submenu_page('tcw-ca-topics', 'Settings', 'Settings', 'manage_options', 'tcw-ca-settings', array($this, 'render_settings_page'));
        add_submenu_page('tcw-ca-topics', 'Logs', 'Logs', 'manage_options', 'tcw-ca-logs', array($this, 'render_logs_page'));
    }

    private function redirect($page, $args = array()) {
        $args['page'] = $page;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private function category_dropdown($name, $selected = '', $id = '') {
        $terms = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ));
        echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($id ?: $name) . '" class="regular-text">';
        echo '<option value="">— Select category —</option>';
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                echo '<option value="' . esc_attr($term->name) . '" ' . selected($selected, $term->name, false) . '>' . esc_html($term->name) . '</option>';
            }
        }
        echo '</select>';
    }

    private function helper($text) {
        echo '<p class="description" style="margin-top:6px;">' . wp_kses_post($text) . '</p>';
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tcw_ca_save_settings');
        $this->core->update_settings(array(
            'anthropic_api_key' => isset($_POST['anthropic_api_key']) ? sanitize_text_field(wp_unslash($_POST['anthropic_api_key'])) : '',
            'pexels_api_key' => isset($_POST['pexels_api_key']) ? sanitize_text_field(wp_unslash($_POST['pexels_api_key'])) : '',
            'anthropic_model' => isset($_POST['anthropic_model']) ? sanitize_text_field(wp_unslash($_POST['anthropic_model'])) : 'claude-sonnet-4-20250514',
            'max_tokens' => isset($_POST['max_tokens']) ? (int) $_POST['max_tokens'] : 4000,
            'default_author' => isset($_POST['default_author']) ? (int) $_POST['default_author'] : 0,
            'default_post_status' => isset($_POST['default_post_status']) ? sanitize_text_field(wp_unslash($_POST['default_post_status'])) : 'draft',
            'fallback_category' => isset($_POST['fallback_category']) ? sanitize_text_field(wp_unslash($_POST['fallback_category'])) : '',
            'site_name' => isset($_POST['site_name']) ? sanitize_text_field(wp_unslash($_POST['site_name'])) : '',
            'site_focus' => isset($_POST['site_focus']) ? sanitize_text_field(wp_unslash($_POST['site_focus'])) : '',
            'brand_voice' => isset($_POST['brand_voice']) ? sanitize_text_field(wp_unslash($_POST['brand_voice'])) : '',
            'enable_pexels' => !empty($_POST['enable_pexels']) ? 1 : 0,
            'retry_attempts' => isset($_POST['retry_attempts']) ? max(1, (int) $_POST['retry_attempts']) : 4,
            'scheduler_enabled' => !empty($_POST['scheduler_enabled']) ? 1 : 0,
            'scheduler_frequency' => isset($_POST['scheduler_frequency']) ? sanitize_key(wp_unslash($_POST['scheduler_frequency'])) : 'daily',
            'scheduler_time' => isset($_POST['scheduler_time']) ? sanitize_text_field(wp_unslash($_POST['scheduler_time'])) : '09:00',
        ));
        $this->redirect('tcw-ca-settings', array('updated' => 1));
    }

    public function handle_add_topic() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tcw_ca_add_topic');
        $this->core->add_topic(array(
            'topic' => isset($_POST['topic']) ? wp_unslash($_POST['topic']) : '',
            'category' => isset($_POST['category']) ? wp_unslash($_POST['category']) : '',
            'status' => isset($_POST['status']) ? wp_unslash($_POST['status']) : 'pending',
            'preserve_title' => !empty($_POST['preserve_title']) ? 1 : 0,
        ));
        $this->redirect('tcw-ca-topics', array('added' => 1));
    }

    public function handle_update_topic() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tcw_ca_update_topic');
        $id = isset($_POST['topic_id']) ? (int) $_POST['topic_id'] : 0;
        $this->core->update_topic($id, array(
            'topic' => isset($_POST['topic']) ? wp_unslash($_POST['topic']) : '',
            'category' => isset($_POST['category']) ? wp_unslash($_POST['category']) : '',
            'status' => isset($_POST['status']) ? wp_unslash($_POST['status']) : 'pending',
            'preserve_title' => !empty($_POST['preserve_title']) ? 1 : 0,
        ));
        $this->redirect('tcw-ca-topics', array('updated' => 1));
    }

    public function handle_delete_topic() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tcw_ca_delete_topic');
        $id = isset($_GET['topic_id']) ? (int) $_GET['topic_id'] : 0;
        $this->core->delete_topic($id);
        $this->redirect('tcw-ca-topics', array('deleted' => 1));
    }

    public function handle_import_csv() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tcw_ca_import_csv');
        if (empty($_FILES['csv_file']['tmp_name'])) {
            $this->redirect('tcw-ca-topics', array('imported' => 0, 'error' => 'missing_file'));
        }
        $result = $this->core->import_csv($_FILES['csv_file']['tmp_name']);
        if (is_wp_error($result)) {
            $this->redirect('tcw-ca-topics', array('imported' => 0, 'error' => rawurlencode($result->get_error_message())));
        }
        $this->redirect('tcw-ca-topics', array('imported' => (int) $result));
    }

    public function handle_publish_topic() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tcw_ca_publish_topic');
        $id = isset($_GET['topic_id']) ? (int) $_GET['topic_id'] : 0;
        $result = $this->core->publish_topic($id);
        if (is_wp_error($result)) {
            $this->redirect('tcw-ca-topics', array('publish_error' => rawurlencode($result->get_error_message())));
        }
        $this->redirect('tcw-ca-topics', array('published' => (int) $result));
    }

    public function handle_publish_next() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tcw_ca_publish_next');
        $result = $this->core->publish_next_pending();
        if (is_wp_error($result)) {
            $this->redirect('tcw-ca-topics', array('publish_error' => rawurlencode($result->get_error_message())));
        }
        $this->redirect('tcw-ca-topics', array('published' => (int) $result));
    }

    public function render_topics_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $topics = $this->core->get_topics();
        $edit_id = isset($_GET['edit_topic']) ? (int) $_GET['edit_topic'] : 0;
        $edit_topic = $edit_id ? $this->core->get_topic($edit_id) : null;
        ?>
        <div class="wrap">
            <h1>TCW Content Assistant</h1>
            <?php $this->render_notices(); ?>

            <h2><?php echo $edit_topic ? 'Edit Topic' : 'Add Topic'; ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php if ($edit_topic) : ?>
                    <input type="hidden" name="action" value="tcw_ca_update_topic" />
                    <input type="hidden" name="topic_id" value="<?php echo (int) $edit_topic['id']; ?>" />
                    <?php wp_nonce_field('tcw_ca_update_topic'); ?>
                <?php else : ?>
                    <input type="hidden" name="action" value="tcw_ca_add_topic" />
                    <?php wp_nonce_field('tcw_ca_add_topic'); ?>
                <?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label for="topic">Topic</label></th>
                        <td>
                            <textarea name="topic" id="topic" class="large-text" rows="3" required><?php echo esc_textarea($edit_topic ? $edit_topic['topic'] : ''); ?></textarea>
                            <?php $this->helper('<strong>Required.</strong> Enter the full article topic exactly as you want the content to be based on. Example: <code>TFSA contribution limit 2025 Canada — how much can you contribute and what happens if you over-contribute</code>. Default: none.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="category">Category</label></th>
                        <td>
                            <?php $this->category_dropdown('category', $edit_topic ? $edit_topic['category'] : '', 'category'); ?>
                            <?php $this->helper("<strong>Optional.</strong> Choose from the site's current categories. If left empty, the plugin will use the generated category from the model, and if that is empty too, it will fall back to the setting in <em>Fallback Category</em>."); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="status">Status</label></th>
                        <td>
                            <select name="status" id="status">
                                <?php $status = $edit_topic ? $edit_topic['status'] : 'pending'; ?>
                                <?php foreach (array('pending', 'failed', 'skipped', 'done') as $option) : ?>
                                    <option value="<?php echo esc_attr($option); ?>" <?php selected($status, $option); ?>><?php echo esc_html($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php $this->helper('<strong>Required.</strong> Usually use <code>pending</code> for new topics. Default: <code>pending</code>. Other values are mainly for workflow control and review.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Preserve Title</th>
                        <td>
                            <label><input type="checkbox" name="preserve_title" value="1" <?php checked($edit_topic ? (int) $edit_topic['preserve_title'] : 1, 1); ?> /> Keep the generated post title exactly the same as the topic text.</label>
                            <?php $this->helper('<strong>Optional.</strong> Leave checked when the topic text is already the exact title you want published. Default: checked.'); ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button($edit_topic ? 'Update Topic' : 'Add Topic'); ?>
            </form>

            <hr />
            <h2>Import CSV</h2>
            <p>Accepted columns: <code>topic,category,status,preserve_title</code></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="tcw_ca_import_csv" />
                <?php wp_nonce_field('tcw_ca_import_csv'); ?>
                <input type="file" name="csv_file" accept=".csv" required />
                <?php submit_button('Import CSV', 'secondary', 'submit', false); ?>
                <?php $this->helper('<strong>Required.</strong> Upload a UTF-8 CSV with the exact headers shown above. Missing <code>status</code> defaults to <code>pending</code>. Missing <code>preserve_title</code> defaults to unchecked in CSV import unless set to a truthy value such as <code>1</code>.'); ?>
            </form>

            <p>
                <a class="button button-primary tcw-ca-publish-action" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tcw_ca_publish_next'), 'tcw_ca_publish_next')); ?>" data-tcw-loading-text="Publishing...">Publish Next Pending Topic</a>
            </p>

            <h2>Topic Queue</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th><th>Topic</th><th>Category</th><th>Status</th><th>Post</th><th>Updated</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($topics)) : ?>
                    <tr><td colspan="7">No topics yet.</td></tr>
                <?php else : ?>
                    <?php foreach ($topics as $topic) : ?>
                        <tr>
                            <td><?php echo (int) $topic['id']; ?></td>
                            <td>
                                <strong><?php echo esc_html($topic['topic']); ?></strong>
                                <?php if (!empty($topic['rewritten_topic'])) : ?>
                                    <br /><span class="description">Rewritten: <?php echo esc_html($topic['rewritten_topic']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($topic['last_error'])) : ?>
                                    <br /><span style="color:#b32d2e;">Error: <?php echo esc_html($topic['last_error']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($topic['category']); ?></td>
                            <td><?php echo esc_html($topic['status']); ?></td>
                            <td>
                                <?php if (!empty($topic['published_post_id'])) : ?>
                                    <a href="<?php echo esc_url(get_edit_post_link((int) $topic['published_post_id'])); ?>">#<?php echo (int) $topic['published_post_id']; ?></a>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($topic['updated_at']); ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(add_query_arg(array('page' => 'tcw-ca-topics', 'edit_topic' => (int) $topic['id']), admin_url('admin.php'))); ?>">Edit</a>
                                <a class="button button-small tcw-ca-publish-action" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tcw_ca_publish_topic&topic_id=' . (int) $topic['id']), 'tcw_ca_publish_topic')); ?>" data-tcw-loading-text="Publishing...">Publish</a>
                                <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tcw_ca_delete_topic&topic_id=' . (int) $topic['id']), 'tcw_ca_delete_topic')); ?>" onclick="return confirm('Delete this topic?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php $this->render_publish_button_script(); ?>
        </div>
        <?php
    }


    private function render_publish_button_script() {
        ?>
        <script>
        (function(){
            if (window.tcwCaPublishBindingApplied) {
                return;
            }
            window.tcwCaPublishBindingApplied = true;

            function disablePublishButtons(clicked) {
                var buttons = document.querySelectorAll('.tcw-ca-publish-action');
                buttons.forEach(function(btn){
                    btn.classList.add('disabled');
                    btn.setAttribute('aria-disabled', 'true');
                    btn.style.pointerEvents = 'none';
                    btn.style.opacity = '0.6';
                    if (!btn.dataset.tcwOriginalText) {
                        btn.dataset.tcwOriginalText = btn.textContent;
                    }
                });
                if (clicked) {
                    clicked.textContent = clicked.getAttribute('data-tcw-loading-text') || 'Publishing...';
                }
            }

            document.addEventListener('click', function(e){
                var trigger = e.target.closest('.tcw-ca-publish-action');
                if (!trigger) {
                    return;
                }
                if (trigger.getAttribute('aria-disabled') === 'true') {
                    e.preventDefault();
                    return;
                }
                disablePublishButtons(trigger);
            });
        })();
        </script>
        <?php
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = $this->core->get_settings();
        ?>
        <div class="wrap">
            <h1>TCW Content Assistant Settings</h1>
            <?php $this->render_notices(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tcw_ca_save_settings" />
                <?php wp_nonce_field('tcw_ca_save_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="anthropic_api_key">Anthropic API Key</label></th>
                        <td>
                            <input type="password" class="regular-text" name="anthropic_api_key" id="anthropic_api_key" value="<?php echo esc_attr($settings['anthropic_api_key']); ?>" />
                            <?php $this->helper('<strong>Required.</strong> Paste your Anthropic API key here. Without it, article generation will fail. Default: empty.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="pexels_api_key">Pexels API Key</label></th>
                        <td>
                            <input type="password" class="regular-text" name="pexels_api_key" id="pexels_api_key" value="<?php echo esc_attr($settings['pexels_api_key']); ?>" />
                            <?php $this->helper('<strong>Optional.</strong> Only needed if you want the plugin to fetch a featured image automatically. Default: empty.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="anthropic_model">Anthropic Model</label></th>
                        <td>
                            <input type="text" class="regular-text" name="anthropic_model" id="anthropic_model" value="<?php echo esc_attr($settings['anthropic_model']); ?>" />
                            <?php $this->helper('<strong>Optional.</strong> Enter the exact Anthropic model name. Default: <code>claude-sonnet-4-20250514</code>.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="max_tokens">Max Tokens</label></th>
                        <td>
                            <input type="number" class="small-text" name="max_tokens" id="max_tokens" value="<?php echo (int) $settings['max_tokens']; ?>" min="500" max="8000" />
                            <?php $this->helper('<strong>Optional.</strong> Upper response size limit for article generation. Default: <code>4000</code>.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="retry_attempts">Retry Attempts</label></th>
                        <td>
                            <input type="number" class="small-text" name="retry_attempts" id="retry_attempts" value="<?php echo (int) $settings['retry_attempts']; ?>" min="1" max="8" />
                            <?php $this->helper('<strong>Optional.</strong> Number of retries for temporary Anthropic errors such as overload or rate limit. Default: <code>4</code>.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="default_author">Default Author User ID</label></th>
                        <td>
                            <input type="number" class="small-text" name="default_author" id="default_author" value="<?php echo (int) $settings['default_author']; ?>" min="0" />
                            <?php $this->helper('<strong>Optional.</strong> Enter a WordPress user ID. Use <code>0</code> to fall back to the current logged-in user when publishing manually. Default: <code>0</code>.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="default_post_status">Default Post Status</label></th>
                        <td>
                            <select name="default_post_status" id="default_post_status"><option value="draft" <?php selected($settings['default_post_status'], 'draft'); ?>>draft</option><option value="publish" <?php selected($settings['default_post_status'], 'publish'); ?>>publish</option></select>
                            <?php $this->helper('<strong>Optional.</strong> Choose whether newly generated posts are saved as drafts or published immediately. Default: <code>draft</code>.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="fallback_category">Fallback Category</label></th>
                        <td>
                            <?php $this->category_dropdown('fallback_category', $settings['fallback_category'], 'fallback_category'); ?>
                            <?php $this->helper('<strong>Optional.</strong> Used when the topic row category is empty and the model does not return a category. Default: <code>Personal Finance</code>.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="site_name">Site Name</label></th>
                        <td>
                            <input type="text" class="regular-text" name="site_name" id="site_name" value="<?php echo esc_attr($settings['site_name']); ?>" />
                            <?php $this->helper('<strong>Optional.</strong> Brand/site name to include in the AI writing instructions. Default: current WordPress site name.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="site_focus">Site Focus</label></th>
                        <td>
                            <input type="text" class="large-text" name="site_focus" id="site_focus" value="<?php echo esc_attr($settings['site_focus']); ?>" />
                            <?php $this->helper('<strong>Optional.</strong> Briefly describe the website niche and audience. Default: <code>Canadian personal finance and small business finance</code>.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="brand_voice">Brand Voice</label></th>
                        <td>
                            <input type="text" class="large-text" name="brand_voice" id="brand_voice" value="<?php echo esc_attr($settings['brand_voice']); ?>" />
                            <?php $this->helper('<strong>Optional.</strong> Short style description for generated content. Default: <code>clear, trustworthy, practical, content-forward</code>.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Enable Pexels</th>
                        <td>
                            <label><input type="checkbox" name="enable_pexels" value="1" <?php checked((int) $settings['enable_pexels'], 1); ?> /> Fetch a featured image from Pexels when possible.</label>
                            <?php $this->helper('<strong>Optional.</strong> Enable only if you entered a valid Pexels API key. Default: unchecked.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Enable Scheduler</th>
                        <td>
                            <label><input type="checkbox" name="scheduler_enabled" value="1" <?php checked((int) $settings['scheduler_enabled'], 1); ?> /> Automatically publish the next pending topic on the selected schedule.</label>
                            <?php $this->helper('<strong>Optional.</strong> When enabled, the plugin uses WordPress cron to publish the next pending topic automatically using the frequency and time below. Default: unchecked.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="scheduler_frequency">Scheduler Frequency</label></th>
                        <td>
                            <select name="scheduler_frequency" id="scheduler_frequency">
                                <option value="daily" <?php selected($settings['scheduler_frequency'], 'daily'); ?>>daily</option>
                                <option value="weekly" <?php selected($settings['scheduler_frequency'], 'weekly'); ?>>weekly</option>
                                <option value="monthly" <?php selected($settings['scheduler_frequency'], 'monthly'); ?>>monthly</option>
                            </select>
                            <?php $this->helper('<strong>Optional.</strong> Choose how often the scheduler should publish the next pending topic automatically. Default: <code>daily</code>.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="scheduler_time">Scheduler Time</label></th>
                        <td>
                            <input type="time" name="scheduler_time" id="scheduler_time" value="<?php echo esc_attr($settings['scheduler_time']); ?>" />
                            <?php $this->helper('<strong>Optional.</strong> Publish time in the site timezone. Used only when scheduler is enabled. Default: <code>09:00</code>.'); ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        <?php
    }

    public function render_logs_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $logs = $this->core->get_logs(100);
        ?>
        <div class="wrap">
            <h1>TCW Content Assistant Logs</h1>
            <table class="widefat striped">
                <thead><tr><th>Time</th><th>Level</th><th>Message</th><th>Context</th></tr></thead>
                <tbody>
                <?php if (empty($logs)) : ?>
                    <tr><td colspan="4">No logs yet.</td></tr>
                <?php else : ?>
                    <?php foreach ($logs as $log) : ?>
                        <tr>
                            <td><?php echo esc_html($log['created_at']); ?></td>
                            <td><?php echo esc_html($log['level']); ?></td>
                            <td><?php echo esc_html($log['message']); ?></td>
                            <td><code><?php echo esc_html($log['context']); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_notices() {
        $map = array(
            'updated' => 'Settings saved.',
            'added' => 'Topic added.',
            'deleted' => 'Topic deleted.',
        );
        foreach ($map as $key => $message) {
            if (isset($_GET[$key])) {
                echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
            }
        }
        if (isset($_GET['imported'])) {
            $count = (int) $_GET['imported'];
            if ($count > 0) {
                echo '<div class="notice notice-success"><p>' . esc_html($count) . ' topics imported.</p></div>';
            }
        }
        if (isset($_GET['published'])) {
            $post_id = (int) $_GET['published'];
            echo '<div class="notice notice-success"><p>Published successfully. <a href="' . esc_url(get_edit_post_link($post_id)) . '">Open post</a></p></div>';
        }
        if (isset($_GET['publish_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(wp_unslash($_GET['publish_error'])) . '</p></div>';
        }
        if (isset($_GET['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(wp_unslash($_GET['error'])) . '</p></div>';
        }
    }
}
