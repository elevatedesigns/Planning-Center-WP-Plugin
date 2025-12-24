<?php
/**
 * Plugin Name: Planning Center Sync & Display
 * Description: Sync and display Planning Center events, sermons, or groups via shortcode.
 * Version: 0.1.0
 * Author: OpenAI
 * License: MIT
 */

if (!defined('ABSPATH')) {
    exit;
}

const PCWP_OPTION_KEY = 'pcwp_settings';
const PCWP_TRANSIENT_PREFIX = 'pcwp_cache_';

add_action('admin_menu', 'pcwp_register_settings_page');
add_action('admin_init', 'pcwp_register_settings');
add_shortcode('planning_center', 'pcwp_shortcode_handler');
add_shortcode('planning_center_events', 'pcwp_events_shortcode');
add_shortcode('planning_center_sermons', 'pcwp_sermons_shortcode');
add_shortcode('planning_center_groups', 'pcwp_groups_shortcode');

function pcwp_register_settings_page() {
    add_options_page(
        'Planning Center',
        'Planning Center',
        'manage_options',
        'planning-center-settings',
        'pcwp_render_settings_page'
    );
}

function pcwp_register_settings() {
    register_setting('pcwp_settings_group', PCWP_OPTION_KEY);

    add_settings_section(
        'pcwp_api_section',
        'API Credentials',
        '__return_false',
        'planning-center-settings'
    );

    add_settings_field(
        'pcwp_app_id',
        'Application ID',
        'pcwp_render_text_field',
        'planning-center-settings',
        'pcwp_api_section',
        [
            'label_for' => 'pcwp_app_id',
            'option_key' => 'app_id',
        ]
    );

    add_settings_field(
        'pcwp_app_secret',
        'Application Secret',
        'pcwp_render_text_field',
        'planning-center-settings',
        'pcwp_api_section',
        [
            'label_for' => 'pcwp_app_secret',
            'option_key' => 'app_secret',
            'type' => 'password',
        ]
    );
}

function pcwp_render_text_field($args) {
    $options = get_option(PCWP_OPTION_KEY, []);
    $value = isset($options[$args['option_key']]) ? $options[$args['option_key']] : '';
    $type = isset($args['type']) ? $args['type'] : 'text';

    printf(
        '<input type="%1$s" id="%2$s" name="%3$s[%4$s]" value="%5$s" class="regular-text" />',
        esc_attr($type),
        esc_attr($args['label_for']),
        esc_attr(PCWP_OPTION_KEY),
        esc_attr($args['option_key']),
        esc_attr($value)
    );
}

function pcwp_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Planning Center Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('pcwp_settings_group');
            do_settings_sections('planning-center-settings');
            submit_button();
            ?>
        </form>
        <p>
            Use <code>[planning_center]</code> with a <code>type</code> attribute to display Planning Center data.
            Example: <code>[planning_center type="events" limit="5"]</code>
        </p>
        <p>
            Or use a dedicated shortcode per type:
            <code>[planning_center_events]</code>,
            <code>[planning_center_sermons]</code>,
            <code>[planning_center_groups]</code>.
        </p>
    </div>
    <?php
}

function pcwp_shortcode_handler($atts) {
    $atts = shortcode_atts(
        [
            'type' => 'events',
            'limit' => 5,
        ],
        $atts,
        'planning_center'
    );

    $type = sanitize_key($atts['type']);
    $limit = max(1, intval($atts['limit']));

    $endpoint = pcwp_get_endpoint($type);
    if (!$endpoint) {
        return '<p>Unsupported Planning Center type. Use events, sermons, or groups.</p>';
    }

    $cache_key = PCWP_TRANSIENT_PREFIX . md5($type . '_' . $limit);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $response = pcwp_fetch_planning_center_data($endpoint, $limit);
    if (is_wp_error($response)) {
        return '<p>Unable to load Planning Center data.</p>';
    }

    $output = pcwp_render_items($type, $response);
    set_transient($cache_key, $output, HOUR_IN_SECONDS);

    return $output;
}

function pcwp_events_shortcode($atts) {
    $atts['type'] = 'events';
    return pcwp_shortcode_handler($atts);
}

function pcwp_sermons_shortcode($atts) {
    $atts['type'] = 'sermons';
    return pcwp_shortcode_handler($atts);
}

function pcwp_groups_shortcode($atts) {
    $atts['type'] = 'groups';
    return pcwp_shortcode_handler($atts);
}

function pcwp_get_endpoint($type) {
    $endpoints = [
        'events' => 'https://api.planningcenteronline.com/services/v2/events',
        'sermons' => 'https://api.planningcenteronline.com/sermons/v2/series',
        'groups' => 'https://api.planningcenteronline.com/groups/v2/groups',
    ];

    return isset($endpoints[$type]) ? $endpoints[$type] : null;
}

function pcwp_fetch_planning_center_data($endpoint, $limit) {
    $options = get_option(PCWP_OPTION_KEY, []);
    $app_id = isset($options['app_id']) ? $options['app_id'] : '';
    $app_secret = isset($options['app_secret']) ? $options['app_secret'] : '';

    if ($app_id === '' || $app_secret === '') {
        return new WP_Error('pcwp_missing_credentials', 'Missing Planning Center API credentials.');
    }

    $url = add_query_arg('per_page', $limit, $endpoint);

    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($app_id . ':' . $app_secret),
        ],
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $status = wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        return new WP_Error('pcwp_bad_response', 'Invalid response from Planning Center.');
    }

    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['data'])) {
        return new WP_Error('pcwp_invalid_payload', 'Unexpected Planning Center response.');
    }

    return $decoded['data'];
}

function pcwp_render_items($type, $items) {
    if (empty($items)) {
        return '<p>No Planning Center items found.</p>';
    }

    $output = '<ul class="pcwp-list pcwp-list-' . esc_attr($type) . '">';

    foreach ($items as $item) {
        $attributes = isset($item['attributes']) ? $item['attributes'] : [];
        $name = isset($attributes['name']) ? $attributes['name'] : 'Untitled';
        $item_url = isset($attributes['html_url']) ? $attributes['html_url'] : '';
        $date = '';

        if ($type === 'events' && isset($attributes['starts_at'])) {
            $date = mysql2date(get_option('date_format'), $attributes['starts_at']);
        }

        $title = esc_html($name);
        $link = $item_url ? '<a href="' . esc_url($item_url) . '">' . $title . '</a>' : $title;

        $output .= '<li>' . $link;
        if ($date) {
            $output .= ' <span class="pcwp-date">(' . esc_html($date) . ')</span>';
        }
        $output .= '</li>';
    }

    $output .= '</ul>';

    return $output;
}
