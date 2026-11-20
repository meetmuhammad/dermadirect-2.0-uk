<?php
/**
 * Plugin Name:  DermaDirect Test Print Marker
 * Description:  Marks every warehouse document printed from a non-production site as a TEST, and
 *               blocks print paths that cannot be marked. See docs/print-pick-runbook.md.
 * Version:      1.0.0
 *
 * During the warehouse test, real staff receive real printouts from this non-production copy.
 * Nothing it prints may be mistaken for a live order, so every document carries a bold banner
 * and a diagonal watermark, PrintNode jobs without the TEST title prefix are refused, and
 * Flexible Printing (carrier label PDFs we cannot mark) is cut off.
 *
 * Environment-gated and fails CLOSED: never active on production, and on by default everywhere
 * else unless DD_PRINT_TEST_BANNER is explicitly off.
 */

namespace DermaDirect\TestPrintMarker;

defined('ABSPATH') || exit;

const TEXT = 'TEST ORDER - DERMADIRECT 2.0 - DO NOT PICK OR SHIP';

/** Every PrintNode job title sent while marking is on must start with this. */
const PRINTJOB_TITLE_PREFIX = 'TEST DD2.0 - ';

/** Idempotency marker: present once the CSS, banner and watermark have been inserted. */
const MARKER = 'dd-test-print-marker';

const FLAG_NAME = 'DD_PRINT_TEST_BANNER';

const PRINTNODE_HOST = 'api.printnode.com';

/** PickingPal print methods dispatched from $_REQUEST['method'] on admin_init. */
const PICKINGPAL_PRINT_METHODS = [
    'pickingpal_print_barcode',
    'pickingpal_print_exact_barcode',
    'pickingpal_print_pick_ticket',
    'pickingpal_print_packing_slip',
    'pickingpal_print_pick_list',
    'pickingpal_print_unpicked_slip',
    'pickingpal_print_pick_reset_ovr_sku_barcode',
];

/* ------------------------------------------------------------------------
 * Decision
 * ---------------------------------------------------------------------- */

/**
 * Whether printed documents are marked. Never on production, whatever the flag. Elsewhere on
 * unless the flag is explicitly off (boolean false, or 'false', '0', 'off', 'no').
 *
 * @param mixed $flag Raw flag value, null when unset.
 */
function is_enabled(?string $env, $flag): bool
{
    if ($env === 'production') {
        return false;
    }

    return !is_flag_off($flag);
}

/**
 * Whether the flag value explicitly turns marking off.
 *
 * @param mixed $flag
 */
function is_flag_off($flag): bool
{
    if ($flag === false) {
        return true;
    }

    return is_string($flag) && in_array(strtolower(trim($flag)), ['false', '0', 'off', 'no'], true);
}

/**
 * Raw flag: the constant if defined, otherwise getenv(), otherwise null.
 *
 * @return mixed
 */
function flag_raw()
{
    if (defined(FLAG_NAME)) {
        return constant(FLAG_NAME);
    }

    $value = getenv(FLAG_NAME);

    return $value === false ? null : $value;
}

function current_env(): ?string
{
    return defined('WP_ENV') ? (string) WP_ENV : null;
}

/**
 * Whether marking is on for this request.
 */
function enabled(): bool
{
    return is_enabled(current_env(), flag_raw());
}

/* ------------------------------------------------------------------------
 * HTML and CSS
 *
 * Dompdf compatible: no flexbox or grid. The watermark is position:fixed so Dompdf and browsers
 * repeat it on every page. When a document repeats the markup (one copy per order), only the
 * first watermark among siblings is shown so the opacity does not stack up over the content.
 * ---------------------------------------------------------------------- */

function css(): string
{
    return '.dd-test-print-banner{display:block;margin:0 0 8px 0;padding:8px 6px;background:#000000;color:#ffffff;'
        . 'border:4px solid #d00000;text-align:center;font-family:"DejaVu Sans",Arial,sans-serif;font-size:20px;'
        . 'font-weight:bold;line-height:1.3;letter-spacing:1px;page-break-inside:avoid;'
        . '-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;}'
        . '.dd-test-print-banner span{display:block;color:#ffffff;}'
        . '.dd-test-print-watermark{position:fixed;top:38%;left:-15%;width:130%;text-align:center;'
        . 'font-family:"DejaVu Sans",Arial,sans-serif;font-size:52px;font-weight:bold;line-height:1.25;color:#d00000;'
        . 'opacity:0.2;transform:rotate(-38deg);-webkit-transform:rotate(-38deg);z-index:9999;pointer-events:none;'
        . '-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;}'
        . '.dd-test-print-watermark ~ .dd-test-print-watermark{display:none;}';
}

function style_block(): string
{
    return '<style type="text/css" id="dd-test-print-style">' . css() . '</style>';
}

function banner_html(): string
{
    $text = htmlspecialchars(TEXT, ENT_QUOTES, 'UTF-8');

    return '<div class="dd-test-print-banner" id="' . MARKER . '">'
        . '<span>' . $text . '</span><span>' . $text . '</span></div>';
}

function watermark_html(): string
{
    return '<div class="dd-test-print-watermark">TEST ORDER<br>DO NOT PICK OR SHIP<br>DERMADIRECT 2.0</div>';
}

/**
 * CSS, banner and watermark, with the idempotency marker comment first.
 */
function markup(): string
{
    return '<!-- ' . MARKER . ' -->' . style_block() . banner_html() . watermark_html();
}

/**
 * Inserts the marking right after the opening <body ...> tag, or prepends it when there is no
 * body tag (template fragments). Does nothing if the marking is already present.
 */
function mark_html(string $html): string
{
    if (strpos($html, MARKER) !== false) {
        return $html;
    }

    if (preg_match('/<body\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
        $at = $match[0][1] + strlen($match[0][0]);

        return substr($html, 0, $at) . markup() . substr($html, $at);
    }

    return markup() . $html;
}

/* ------------------------------------------------------------------------
 * PrintNode boundary
 * ---------------------------------------------------------------------- */

/**
 * Whether a request is a PrintNode job submission (POST to api.printnode.com .../printjobs).
 */
function is_printnode_printjob(string $url, string $method): bool
{
    if (strtoupper(trim($method)) !== 'POST') {
        return false;
    }

    $parts = parse_url($url);

    if (!is_array($parts) || strtolower($parts['host'] ?? '') !== PRINTNODE_HOST) {
        return false;
    }

    $path = rtrim(strtolower($parts['path'] ?? ''), '/');

    return $path === '/printjobs' || substr($path, -10) === '/printjobs';
}

/**
 * Why a request must not be sent, or null if it may go. Only PrintNode job submissions are
 * checked; their title must start with PRINTJOB_TITLE_PREFIX. The body may be a JSON string or
 * an array (WordPress form-encodes array bodies). Any other body is refused.
 *
 * @param mixed $body
 */
function printjob_block_reason(string $url, string $method, $body): ?string
{
    if (!is_printnode_printjob($url, $method)) {
        return null;
    }

    if (is_string($body)) {
        $body = json_decode($body, true);
    }

    if (!is_array($body)) {
        return 'print job body is not JSON';
    }

    $title = $body['title'] ?? null;

    if (!is_string($title) || strpos($title, PRINTJOB_TITLE_PREFIX) !== 0) {
        return 'print job title does not carry the TEST prefix';
    }

    return null;
}

/**
 * Title with the TEST prefix, added once.
 */
function prefix_title(string $title): string
{
    return strpos($title, PRINTJOB_TITLE_PREFIX) === 0 ? $title : PRINTJOB_TITLE_PREFIX . $title;
}

/**
 * Adds the TEST prefix to the job titles WebToffee's PrintNode add-on is about to send. Mirrors
 * its own fallback title (document key and order id) when a module sets none.
 *
 * @param mixed $docs Output of the wt_pklist_do_remote_printing filter.
 * @param mixed $order_id
 * @return mixed
 */
function prefix_remote_print_titles($docs, $order_id)
{
    if (!is_array($docs)) {
        return $docs;
    }

    foreach ($docs as $key => $doc) {
        if (!is_array($doc) || (empty($doc['pdf_file']) && empty($doc['html']))) {
            continue;
        }

        $title = isset($doc['title']) && is_string($doc['title']) ? $doc['title'] : $key . '_' . $order_id;
        $docs[$key]['title'] = prefix_title($title);
    }

    return $docs;
}

/* ------------------------------------------------------------------------
 * Flexible Printing
 * ---------------------------------------------------------------------- */

/**
 * Flexible Printing settings with the PrintNode API key blanked, so it cannot send anything.
 * Every other setting is returned unchanged.
 *
 * @param mixed $settings
 * @return mixed
 */
function blank_flexible_printing_key($settings)
{
    if (is_array($settings)) {
        $settings['print_node_api_key'] = '';
    }

    return $settings;
}

/**
 * Whether this request renders the Flexible Printing settings screen or saves its settings
 * through options.php. Only there is the stored key left visible, so the field shows it and the
 * plugin's sanitize merge keeps it.
 */
function is_flexible_printing_settings_request(): bool
{
    if (!is_admin()) {
        return false;
    }

    if (($_GET['page'] ?? null) === 'flexible-printing-settings') {
        return true;
    }

    return ($GLOBALS['pagenow'] ?? '') === 'options.php'
        && ($_POST['option_page'] ?? null) === 'flexible_printing_settings';
}

/**
 * Blanks the key everywhere except the settings screen and its save.
 *
 * @param mixed $settings
 * @return mixed
 */
function filter_flexible_printing_settings($settings)
{
    return is_flexible_printing_settings_request() ? $settings : blank_flexible_printing_key($settings);
}

/**
 * Renders the PrintNode API key field as a password input.
 *
 * @param mixed $registered Output of the flexible_printing_registered_settings filter.
 * @return mixed
 */
function mask_flexible_printing_key_field($registered)
{
    if (!isset($registered['print_node']['print_node']) || !is_array($registered['print_node']['print_node'])) {
        return $registered;
    }

    foreach ($registered['print_node']['print_node'] as $index => $field) {
        if (is_array($field) && ($field['id'] ?? null) === 'print_node_api_key') {
            $registered['print_node']['print_node'][$index]['type'] = 'password';
        }
    }

    return $registered;
}

/**
 * The stored Flexible Printing settings straight from the database, bypassing the option filters
 * (and so the key blanking).
 *
 * @return mixed
 */
function stored_flexible_printing_settings()
{
    global $wpdb;

    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        'flexible_printing_settings'
    ));

    return $raw === null ? null : maybe_unserialize($raw);
}

/**
 * Never lets a save replace a stored PrintNode key with an empty one.
 *
 * @param mixed $value New value about to be saved.
 * @return mixed
 */
function keep_flexible_printing_key($value)
{
    if (!is_array($value) || !empty($value['print_node_api_key'])) {
        return $value;
    }

    $stored = stored_flexible_printing_settings();

    if (is_array($stored) && !empty($stored['print_node_api_key'])) {
        $value['print_node_api_key'] = $stored['print_node_api_key'];
    }

    return $value;
}

/* ------------------------------------------------------------------------
 * PickingPal
 * ---------------------------------------------------------------------- */

/**
 * Whether the request method parameter names a PickingPal print template.
 *
 * @param mixed $method Raw $_REQUEST['method'].
 */
function is_pickingpal_print_method($method): bool
{
    return is_string($method) && in_array($method, PICKINGPAL_PRINT_METHODS, true);
}

/* ------------------------------------------------------------------------
 * WordPress wiring
 * ---------------------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
    \WP_CLI::add_command('dd print-marker', function () {
        $raw = flag_raw();

        if ($raw === null) {
            $flag = 'unset';
        } elseif (is_flag_off($raw)) {
            $flag = 'false (marking switched off)';
        } else {
            $flag = 'set (' . substr(var_export($raw, true), 0, 20) . ')';
        }

        \WP_CLI::log('WP_ENV:   ' . (current_env() ?? 'undefined'));
        \WP_CLI::log(FLAG_NAME . ': ' . $flag);
        \WP_CLI::log('Enabled:  ' . (enabled() ? 'yes' : 'no'));
    });
}

if (!enabled()) {
    return;
}

// a. WebToffee (base and Pro add-ons). The template HTML here is a body-less fragment that is
// rendered once per order, so the marking is prepended to it. The print CSS is appended for
// header templates that output it.
add_filter('wf_pklist_alter_template_html', function ($html) {
    return is_string($html) && $html !== '' ? mark_html($html) : $html;
}, PHP_INT_MAX);

add_filter('wf_pklist_alter_print_css', function ($print_css) {
    return is_string($print_css) && strpos($print_css, 'dd-test-print-banner') === false ? $print_css . css() : $print_css;
}, PHP_INT_MAX);

// WebToffee PrintNode add-on: prefix the job titles so the boundary guard below lets them through.
add_filter('wt_pklist_do_remote_printing', __NAMESPACE__ . '\prefix_remote_print_titles', PHP_INT_MAX, 2);

// b. PickingPal prints on admin_init (priority 10) and exits. Buffer only those requests from an
// earlier priority; PHP flushes the buffer through the callback on exit.
add_action('admin_init', function () {
    if (is_pickingpal_print_method($_REQUEST['method'] ?? null)) {
        ob_start(function ($buffer) {
            return is_string($buffer) && trim($buffer) !== '' ? mark_html($buffer) : $buffer;
        });
    }
}, 1);

// c. PrintNode boundary: refuse any job submission whose title does not carry the TEST prefix.
add_filter('pre_http_request', function ($preempt, $args, $url) {
    if ($preempt !== false) {
        return $preempt;
    }

    $reason = printjob_block_reason((string) $url, (string) ($args['method'] ?? 'GET'), $args['body'] ?? null);

    if ($reason === null) {
        return $preempt;
    }

    error_log('dd-test-print-marker: blocked PrintNode print job, ' . $reason);

    return new \WP_Error('dd_test_print_unmarked', 'Print job blocked: ' . $reason . ' (TEST print marking is on).');
}, 1, 3);

// d. Flexible Printing talks to PrintNode through its own client, bypassing pre_http_request,
// and prints carrier PDFs that cannot be marked. Its print path reads the key only through the
// get_option filter below, which always returns '' here. The settings array is blanked in memory
// too, except on the settings screen and its options.php save, so the (password) field shows the
// stored key and re-saving keeps it. A save never replaces a stored key with an empty one.
add_filter('option_flexible_printing_settings', __NAMESPACE__ . '\filter_flexible_printing_settings', PHP_INT_MAX);
add_filter('flexible_printing_get_option_print_node_api_key', '__return_empty_string', PHP_INT_MAX);
add_filter('flexible_printing_registered_settings', __NAMESPACE__ . '\mask_flexible_printing_key_field', PHP_INT_MAX);
add_filter('pre_update_option_flexible_printing_settings', __NAMESPACE__ . '\keep_flexible_printing_key', PHP_INT_MAX);

add_action('admin_notices', function () {
    if (($_GET['page'] ?? null) !== 'flexible-printing-settings' || ($_GET['tab'] ?? null) !== 'print_node') {
        return;
    }

    echo '<div class="notice notice-info"><p>'
        . esc_html('Saved. Printing from this plugin is disabled on the 2.0 test site.')
        . '</p></div>';
});
