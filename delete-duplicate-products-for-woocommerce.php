<?php
/**
 * Plugin Name: Delete Duplicate Products for WooCommerce
 * Description: Find and delete duplicate products by title or SKU in WooCommerce.
 * Version: 1.0.0
 * Author: Luis Peel
 * License: GPL2
 * Text Domain: delete-duplicate-products-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 *
 * @package CPTSM2_Duplicate_Products
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main class for handling duplicate products
 */
class CPTSM2_Duplicate_Products {

    /**
     * Initialize the plugin
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'cptsm2_add_menu_page'));
        add_action('admin_enqueue_scripts', array($this, 'cptsm2_enqueue_admin_scripts'));
        add_action('plugins_loaded', array($this, 'cptsm2_load_textdomain'));
        
        // delete products handler
        add_action('admin_post_cptsm2_delete_products', array($this, 'cptsm2_handle_delete_products'));
        
        // save cache when products are modified
        add_action('save_post_product', array($this, 'cptsm2_clear_cache'));
        add_action('deleted_post', array($this, 'cptsm2_clear_cache'));
        add_action('woocommerce_save_product_variation', array($this, 'cptsm2_clear_cache'));
    }

    /**
     * Load plugin textdomain
     */
    public function cptsm2_load_textdomain() {
        load_plugin_textdomain(
            'delete-duplicate-products-for-woocommerce',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * Add menu page to WordPress admin
     */
    public function cptsm2_add_menu_page() {
        add_menu_page(
            esc_html__('Duplicate Products', 'delete-duplicate-products-for-woocommerce'),
            esc_html__('Duplicate Products', 'delete-duplicate-products-for-woocommerce'),
            'manage_woocommerce',
            'delete-duplicate-products-for-woocommerce',
            array($this, 'cptsm2_render_admin_page'),
            'dashicons-admin-generic',
            56
        );
    }

    /**
     * Get duplicate products with pagination
     *
     * @param array $args Filter arguments.
     * @return array Duplicate products and pagination data.
     */
    private function cptsm2_get_duplicate_products($args = array()) {
        $defaults = array(
            'group_by' => 'title',
            'paged'    => 1,
            'per_page' => 10
        );

        $args = wp_parse_args($args, $defaults);

        // Get cached duplicate identifiers
        $duplicate_identifiers = $this->cptsm2_get_duplicate_identifiers($args['group_by']);

        if (empty($duplicate_identifiers)) {
            return array(
                'items'        => array(),
                'total_items'  => 0,
                'total_pages'  => 0,
                'current_page' => 1
            );
        }

        // Calculate pagination
        $total_items = count($duplicate_identifiers);
        $total_pages = ceil($total_items / $args['per_page']);
        $offset = ($args['paged'] - 1) * $args['per_page'];

        // Get paginated identifiers
        $paginated_identifiers = array_slice($duplicate_identifiers, $offset, $args['per_page']);

        // Get all products for these identifiers
        $duplicate_groups = array();
        foreach ($paginated_identifiers as $identifier) {
            $query_args = array(
                'status' => 'publish',
                'limit'  => -1,
            );

            if ($args['group_by'] === 'title') {
                $query_args['title'] = $identifier;
            } else {
                $query_args['sku'] = $identifier;
            }

            $products = wc_get_products($query_args);

            if (!empty($products)) {
                $duplicate_groups[$identifier] = $products;
            }
        }

        return array(
            'items'        => $duplicate_groups,
            'total_items'  => $total_items,
            'total_pages'  => $total_pages,
            'current_page' => $args['paged']
        );
    }

    /**
     * Handle product deletion
     */
    public function cptsm2_handle_delete_products() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have sufficient permissions', 'delete-duplicate-products-for-woocommerce'));
        }

        $nonce = sanitize_text_field(isset($_POST['cptsm2_nonce']) ? 
            wp_unslash($_POST['cptsm2_nonce']) : '');
        if (!wp_verify_nonce($nonce, 'cptsm2_delete_products')) {
            wp_die(esc_html__('Invalid nonce', 'delete-duplicate-products-for-woocommerce'));
        }

        if (isset($_POST['products']) && is_array($_POST['products'])) {
            $products = array_map('absint', wp_unslash($_POST['products']));
            foreach ($products as $product_id) {
                wp_delete_post($product_id, true);
            }

            // Agregar nonce al redirect
            $redirect_args = array(
                'page' => 'delete-duplicate-products-for-woocommerce',
                'deleted' => 'true',
                '_wpnonce' => wp_create_nonce('cptsm2_delete_notice')
            );

            wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }
    }

    /**
     * Get request parameters
     *
     * @return array Basic parameters.
     */
    private function cptsm2_get_request_params() {
        // Verificar nonce
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'cptsm2_filter_action')) {
            return array(
                'group_by' => 'title',
                'paged'    => 1
            );
        }

        return array(
            'group_by' => sanitize_text_field(isset($_GET['group_by']) ? 
                wp_unslash($_GET['group_by']) : 'title'),
            'paged'    => sanitize_text_field(isset($_GET['paged']) ? 
                max(1, absint($_GET['paged'])) : 1)
        );
    }

    /**
     * Render the admin page
     */
    public function cptsm2_render_admin_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'delete-duplicate-products-for-woocommerce'));
        }

        // success message if products were deleted
        if (isset($_GET['deleted']) && $_GET['deleted'] === 'true') {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (wp_verify_nonce($nonce, 'cptsm2_delete_notice')) {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Selected products have been deleted successfully.', 'delete-duplicate-products-for-woocommerce'); ?></p>
                </div>
                <?php
            }
        }

        $params = $this->cptsm2_get_request_params();
        $results = $this->cptsm2_get_duplicate_products($params);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg(array(
                    'group_by' => 'title',
                    '_wpnonce' => wp_create_nonce('cptsm2_filter_action')
                ))); ?>" 
                   class="nav-tab <?php echo $params['group_by'] === 'title' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Group by Title', 'delete-duplicate-products-for-woocommerce'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(array(
                    'group_by' => 'sku',
                    '_wpnonce' => wp_create_nonce('cptsm2_filter_action')
                ))); ?>" 
                   class="nav-tab <?php echo $params['group_by'] === 'sku' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Group by SKU', 'delete-duplicate-products-for-woocommerce'); ?>
                </a>
            </div>

            <?php if (!empty($results['items'])) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="cptsm2-delete-form">
                    <input type="hidden" name="action" value="cptsm2_delete_products">
                    <?php wp_nonce_field('cptsm2_delete_products', 'cptsm2_nonce'); ?>
                    
                    <div class="tablenav top">
                        <div class="alignleft actions bulkactions">
                            <input type="submit" class="button action" value="<?php esc_attr_e('Delete Selected Products', 'delete-duplicate-products-for-woocommerce'); ?>" />
                        </div>
                    </div>

                    <?php foreach ($results['items'] as $identifier => $products) : ?>
                        <div class="cptsm2-duplicate-group">
                            <h3><?php echo esc_html(sprintf(
                                /* translators: %1$s: identifier type (Title or SKU), %2$s: the duplicate identifier */
                                __('Duplicate %1$s: %2$s', 'delete-duplicate-products-for-woocommerce'),
                                $params['group_by'] === 'title' ? 'Title' : 'SKU',
                                $identifier
                            )); ?></h3>

                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <td class="manage-column column-cb check-column">
                                            <label class="screen-reader-text" for="cb-select-all-<?php echo esc_attr($identifier); ?>">
                                                <?php esc_html_e('Select All', 'delete-duplicate-products-for-woocommerce'); ?>
                                            </label>
                                            <input id="cb-select-all-<?php echo esc_attr($identifier); ?>" 
                                                   type="checkbox" 
                                                   class="cb-select-all" />
                                        </td>
                                        <th><?php esc_html_e('Image', 'delete-duplicate-products-for-woocommerce'); ?></th>
                                        <th><?php esc_html_e('Title', 'delete-duplicate-products-for-woocommerce'); ?></th>
                                        <th><?php esc_html_e('SKU', 'delete-duplicate-products-for-woocommerce'); ?></th>
                                        <th><?php esc_html_e('Price', 'delete-duplicate-products-for-woocommerce'); ?></th>
                                        <th><?php esc_html_e('Categories', 'delete-duplicate-products-for-woocommerce'); ?></th>
                                        <th><?php esc_html_e('Actions', 'delete-duplicate-products-for-woocommerce'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $product) : ?>
                                        <tr>
                                            <th scope="row" class="check-column">
                                                <label class="screen-reader-text" for="cb-select-<?php echo esc_attr($product->get_id()); ?>">
                                                    <?php 
                                                    /* translators: %s: product title */
                                                    printf(esc_html__('Select %s', 'delete-duplicate-products-for-woocommerce'), 
                                                        esc_html($product->get_name())
                                                    ); 
                                                    ?>
                                                </label>
                                                <input id="cb-select-<?php echo esc_attr($product->get_id()); ?>" 
                                                       type="checkbox" 
                                                       name="products[]" 
                                                       value="<?php echo esc_attr($product->get_id()); ?>" />
                                            </th>
                                            <td>
                                                <?php echo wp_kses_post($product->get_image(array(50, 50))); ?>
                                            </td>
                                            <td>
                                                <?php echo esc_html($product->get_name()); ?>
                                            </td>
                                            <td><?php echo esc_html($product->get_sku()); ?></td>
                                            <td><?php echo wp_kses_post(wc_price($product->get_price())); ?></td>
                                            <td>
                                                <?php
                                                $categories = get_the_terms($product->get_id(), 'product_cat');
                                                if ($categories && !is_wp_error($categories)) {
                                                    echo esc_html(implode(', ', wp_list_pluck($categories, 'name')));
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <a href="<?php echo esc_url(get_edit_post_link($product->get_id())); ?>" 
                                                   class="button button-small">
                                                    <span class="dashicons dashicons-edit"></span>
                                                    <?php esc_html_e('Edit', 'delete-duplicate-products-for-woocommerce'); ?>
                                                </a>
                                                <a href="<?php echo esc_url(get_permalink($product->get_id())); ?>" 
                                                   target="_blank" 
                                                   class="button button-small">
                                                    <span class="dashicons dashicons-visibility"></span>
                                                    <?php esc_html_e('View', 'delete-duplicate-products-for-woocommerce'); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </form>

                <?php $this->cptsm2_render_pagination($results['total_pages'], $results['current_page']); ?>
            <?php else : ?>
                <p><?php esc_html_e('No duplicate products found.', 'delete-duplicate-products-for-woocommerce'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook.
     */
    public function cptsm2_enqueue_admin_scripts($hook) {
        if ('toplevel_page_delete-duplicate-products-for-woocommerce' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'cptsm2-admin-style',
            plugins_url('css/admin.css', __FILE__),
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'cptsm2-admin-script',
            plugins_url('js/admin.js', __FILE__),
            array('jquery'),
            '1.0.0',
            true
        );

        wp_localize_script('cptsm2-admin-script', 'cptsm2_vars', array(
            'nonce' => wp_create_nonce('cptsm2_ajax_nonce'),
            'confirm_delete' => esc_html__('Are you sure you want to delete the selected products?', 'delete-duplicate-products-for-woocommerce'),
            'no_products_selected' => esc_html__('Please select at least one product to delete.', 'delete-duplicate-products-for-woocommerce')
        ));
    }

    /**
     * Get duplicate identifiers with caching
     *
     * @param string $group_by Group by field (title or sku).
     * @return array Array of duplicate identifiers.
     */
    private function cptsm2_get_duplicate_identifiers($group_by) {
        global $wpdb;

        // Generate cache key based on group_by parameter
        $cache_key = 'cptsm2_duplicate_' . $group_by . '_identifiers';
        
        // Try to get cached results
        $duplicate_identifiers = wp_cache_get($cache_key);

        if (false === $duplicate_identifiers) {
            // Cache miss - fetch from database
            if ($group_by === 'title') {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $duplicate_identifiers = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT p.post_title as identifier
                        FROM {$wpdb->posts} p
                        WHERE p.post_type = %s
                        AND p.post_status = %s
                        GROUP BY p.post_title
                        HAVING COUNT(*) > 1",
                        'product',
                        'publish'
                    )
                );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $duplicate_identifiers = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT pm.meta_value as identifier
                        FROM {$wpdb->posts} p
                        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                        WHERE p.post_type = %s
                        AND p.post_status = %s
                        AND pm.meta_key = %s
                        AND pm.meta_value != ''
                        GROUP BY pm.meta_value
                        HAVING COUNT(*) > 1",
                        'product',
                        'publish',
                        '_sku'
                    )
                );
            }

            // Cache the results for 1 hour
            wp_cache_set($cache_key, $duplicate_identifiers, '', HOUR_IN_SECONDS);
        }

        return $duplicate_identifiers;
    }

    /**
     * Clear duplicate products cache
     */
    public function cptsm2_clear_cache() {
        wp_cache_delete('cptsm2_duplicate_title_identifiers');
        wp_cache_delete('cptsm2_duplicate_sku_identifiers');
    }

    /**
     * Render pagination controls
     *
     * @param int $total_pages Total number of pages.
     * @param int $current_page Current page number.
     */
    private function cptsm2_render_pagination($total_pages, $current_page) {
        if ($total_pages <= 1) {
            return;
        }

        echo '<div class="tablenav-pages">';
        echo wp_kses_post(paginate_links(array(
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'prev_text' => __('&laquo;', 'delete-duplicate-products-for-woocommerce'),
            'next_text' => __('&raquo;', 'delete-duplicate-products-for-woocommerce'),
            'total'     => $total_pages,
            'current'   => $current_page,
        )));
        echo '</div>';
    }
}

// Initialize the plugin.
new CPTSM2_Duplicate_Products(); 