<?php
if (!defined('ABSPATH')) exit;

class AISA_H_Admin {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'maybe_notice'));
    }

    public function add_menu() {
        add_menu_page('AI Hosting SEO', 'AI Hosting SEO', 'manage_options', 'aisa-h-settings', array($this, 'settings_page'),'dashicons-analytics', 58);
    }

    public function register_settings() {
        register_setting('aisa_h_group', 'aisa_h_settings', array($this, 'sanitize') );
        // default values on activation
        $opts = get_option('aisa_h_settings', array());
        $defaults = array(
            'brand' => 'TurnUpHosting',
            'openai_api_key' => '',
            'competitors' => "https://www.godaddy.com/blog/rss/
https://www.hostinger.com/blog/feed/
https://www.bluehost.com/blog/feed/
https://www.namecheap.com/blog/feed/
https://www.hosting.com/blog/rss/",
            'schedule' => 'daily',
            'post_status' => 'draft',
            'min_words' => 1000,
            'duplicate_threshold' => 80, // similarity percent
        );
        if ( empty($opts) ) {
            update_option('aisa_h_settings', $defaults);
        }
    }

    public function sanitize($input) {
        $out = get_option('aisa_h_settings', array());
        $out['brand'] = sanitize_text_field($input['brand'] ?? $out['brand']);
        $out['openai_api_key'] = sanitize_text_field($input['openai_api_key'] ?? $out['openai_api_key']);
        $out['competitors'] = sanitize_textarea_field($input['competitors'] ?? $out['competitors']);
        $out['schedule'] = sanitize_text_field($input['schedule'] ?? $out['schedule']);
        $out['post_status'] = sanitize_text_field($input['post_status'] ?? $out['post_status']);
        $out['min_words'] = max(300, intval($input['min_words'] ?? $out['min_words'] ?? 1000));
        $out['duplicate_threshold'] = max(50, min(95, intval($input['duplicate_threshold'] ?? $out['duplicate_threshold'] ?? 80)));
        update_option('aisa_h_settings', $out);
        return $out;
    }

    public function settings_page() {
        $s = get_option('aisa_h_settings', array());
        ?>
        <div class="wrap">
            <h1>AI SEO Automation — Hosting Edition (v2)</h1>
            <form method="post" action="options.php">
                <?php settings_fields('aisa_h_group'); do_settings_sections('aisa_h_group'); ?>
                <table class="form-table">
                    <tr><th>Brand name</th>
                        <td><input name="aisa_h_settings[brand]" value="<?php echo esc_attr($s['brand'] ?? ''); ?>" class="regular-text"/></td></tr>
                    <tr><th>OpenAI API Key</th>
                        <td><input name="aisa_h_settings[openai_api_key]" value="<?php echo esc_attr($s['openai_api_key'] ?? ''); ?>" class="regular-text"/></td></tr>
                    <tr><th>Competitor RSS feeds (one per line)</th>
                        <td><textarea name="aisa_h_settings[competitors]" rows="6" class="large-text code"><?php echo esc_textarea($s['competitors'] ?? ''); ?></textarea>
                        <p class="description">Provide RSS/feed URLs for competitor blogs. Used to fetch recent headlines and avoid duplicating topics.</p></td></tr>
                    <tr><th>Schedule</th>
                        <td><select name="aisa_h_settings[schedule]">
                            <option value="daily" <?php selected($s['schedule'] ?? 'daily','daily'); ?>>Daily</option>
                            <option value="twicedaily" <?php selected($s['schedule'],'twicedaily'); ?>>Twice Daily</option>
                            <option value="hourly" <?php selected($s['schedule'],'hourly'); ?>>Hourly</option>
                        </select></td></tr>
                    <tr><th>Default post status</th>
                        <td><select name="aisa_h_settings[post_status]">
                            <option value="draft" <?php selected($s['post_status']??'draft','draft'); ?>>Draft</option>
                            <option value="publish" <?php selected($s['post_status'],'publish'); ?>>Publish</option>
                        </select></td></tr>
                    <tr><th>Minimum words</th>
                        <td><input type="number" name="aisa_h_settings[min_words]" value="<?php echo esc_attr($s['min_words'] ?? 1000); ?>" min="300" max="5000" class="small-text"/></td></tr>
                    <tr><th>Duplicate similarity threshold (%)</th>
                        <td><input type="number" name="aisa_h_settings[duplicate_threshold]" value="<?php echo esc_attr($s['duplicate_threshold'] ?? 80); ?>" min="50" max="95" class="small-text"/></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <hr/>
            <p><strong>Run now:</strong> You can trigger the pipeline by calling the cron hook or using WP CLI: <code>do_action('aisa_h_run_pipeline');</code></p>
        </div>
        <?php
    }

    public function maybe_notice() {
        $s = get_option('aisa_h_settings', array());
        if ( empty($s['openai_api_key']) ) {
            echo '<div class="notice notice-warning"><p>AI SEO Automation: OpenAI API key not set. Please enter it in settings to enable generation.</p></div>';
        }
    }
}
