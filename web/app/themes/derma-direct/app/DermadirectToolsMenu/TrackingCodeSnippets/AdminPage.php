<?php
namespace App\DermadirectToolsMenu\TrackingCodeSnippets;

class AdminPage
{
    /**
     * Initialize CPT + submenu + meta boxes
     */
    public static function init(): void
    {
        // Register CPT
        self::registerCPT();

        // Add meta boxes
        add_action('add_meta_boxes', [self::class, 'addMetaBoxes']);

        // Save meta box data
        add_action('save_post_tc-snippet', [self::class, 'saveMetaBoxes']);

        // Add custom admin columns
        add_filter('manage_tc-snippet_posts_columns', [self::class, 'addAdminColumns']);
        add_action('manage_tc-snippet_posts_custom_column', [self::class, 'renderAdminColumns'], 10, 2);

        Manager::init();
    }

    /**
     * Register CPT 'tc-snippet'
     */
    protected static function registerCPT(): void
    {
        if (post_type_exists('tc-snippet')) {
            return;
        }

        register_post_type('tc-snippet', [
            'labels' => [
                'name'               => 'Tracking Code Snippets',
                'singular_name'      => 'Tracking Code Snippet',
                'add_new'            => 'Add New',
                'add_new_item'       => 'Add New Tracking Code Snippet',
                'edit_item'          => 'Edit Tracking Code Snippet',
                'new_item'           => 'New Tracking Code Snippet',
                'view_item'          => 'View Tracking Code Snippet',
                'search_items'       => 'Search Tracking Code Snippets',
                'not_found'          => 'No tracking code snippets found',
                'not_found_in_trash' => 'No tracking code snippets found in Trash',
            ],
            'public'              => false,   // Not public
            'publicly_queryable'  => false,   // Cannot be queried on front-end
            'show_ui'             => true,    // Show in admin
            'show_in_menu'        => 'dermadirect-tools', // Submenu
            'supports'            => ['title'], // Title only
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'exclude_from_search' => true,
            'has_archive'         => false,   // No archive
            'rewrite'             => false,   // No URL rewrite
            'show_in_rest'        => false,   // Disable REST API
        ]);
    }

    /**
     * Add custom meta boxes
     */
    public static function addMetaBoxes(): void
    {
        add_meta_box(
            'tc-snippet-details',
            'Tracking Code Details',
            [self::class, 'renderMetaBox'],
            'tc-snippet',
            'normal',
            'default'
        );
    }

    /**
     * Render meta box HTML
     */
    public static function renderMetaBox($post): void
    {
        // Nonce field for security
        wp_nonce_field('tc_snippet_meta', 'tc_snippet_nonce');

        // Retrieve existing values
        $code_snippet    = get_post_meta($post->ID, '_tc_code_snippet', true);
        $render_location = get_post_meta($post->ID, '_tc_render_location', true);
        $status          = get_post_meta($post->ID, '_tc_status', true);
        ?>
        <p>
            <label for="tc_code_snippet"><strong>Code Snippet</strong></label><br>
            <textarea id="tc_code_snippet" name="tc_code_snippet" rows="6" style="width:100%;"><?php echo esc_textarea($code_snippet); ?></textarea>
        </p>

        <p>
            <label for="tc_render_location"><strong>Render Location</strong></label><br>
            <select id="tc_render_location" name="tc_render_location">
                <option value="head" <?php selected($render_location, 'head'); ?>>Head</option>
                <option value="footer" <?php selected($render_location, 'footer'); ?>>Footer</option>
            </select>
        </p>

        <p>
            <label for="tc_status"><strong>Status</strong></label><br>
            <input type="checkbox" id="tc_status" name="tc_status" value="1" <?php checked($status, '1'); ?>>
            Active
        </p>
        <?php
    }

    /**
     * Save meta box values
    */
    public static function saveMetaBoxes($post_id): void
    {
        // Verify nonce
        if (!isset($_POST['tc_snippet_nonce']) || !wp_verify_nonce($_POST['tc_snippet_nonce'], 'tc_snippet_meta')) return;

        // Prevent autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) return;

        // Save code snippet
        if (isset($_POST['tc_code_snippet'])) {
            update_post_meta($post_id, '_tc_code_snippet', wp_unslash($_POST['tc_code_snippet']));
        }

        // Save render location
        if (isset($_POST['tc_render_location'])) {
            update_post_meta($post_id, '_tc_render_location', sanitize_text_field($_POST['tc_render_location']));
        }

        // Save status
        $status_value = isset($_POST['tc_status']) && $_POST['tc_status'] == '1' ? '1' : '0';
        update_post_meta($post_id, '_tc_status', $status_value);
    }

    public static function addAdminColumns($columns): array
    {
        $new_columns = [];
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = $columns['title'];
        $new_columns['render_location'] = 'Render Location';
        $new_columns['status'] = 'Status';
        $new_columns['date'] = $columns['date'];
        return $new_columns;
    }

    /**
     * Fill custom columns content
     */
    public static function renderAdminColumns($column, $post_id): void
    {
        switch ($column) {
            case 'render_location':
                $render_location = get_post_meta($post_id, '_tc_render_location', true);
                echo $render_location ? ucfirst($render_location) : '-';
                break;

            case 'status':
                $status = get_post_meta($post_id, '_tc_status', true);

                if ($status === '1') {
                    echo '<span style="
                        display:inline-block;
                        background-color:#28a745;
                        color:#fff;
                        padding:2px 8px;
                        border-radius:12px;
                        font-size:12px;
                        font-weight:600;
                    ">Active</span>';
                } else {
                    echo '<span style="
                        display:inline-block;
                        background-color:#dc3545;
                        color:#fff;
                        padding:2px 8px;
                        border-radius:12px;
                        font-size:12px;
                        font-weight:600;
                    ">Inactive</span>';
                }
                break;
        }
    }

}
