<?php

/**
 * Plugin Name: sha Omnibus Lowest Price for WooCommerce
 * Description: Shows the lowest product price from the last 30 days for sale products across WooCommerce loops, single products, cart, checkout, Flatsome UX Builder products blocks, pagination, and AJAX load more results. Adds product edit price history.
 * Version: 1.1.0
 * Author: shahriar (sha)
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * Text Domain: sha-omnibus-lowest-price
 */

if (! defined('ABSPATH')) {
    exit;
}

final class sha_Omnibus_Lowest_Price_For_WooCommerce
{
    const VERSION       = '1.1.0';
    const OPTION_KEY    = 'sha_omnibus_lowest_price_options';
    const CRON_HOOK     = 'sha_omnibus_lowest_price_daily_record';
    const SEED_HOOK     = 'sha_omnibus_lowest_price_seed_prices';
    const NONCE_ACTION  = 'sha_omnibus_lowest_price_frontend';

    /**
     * @var sha_Omnibus_Lowest_Price_For_WooCommerce|null
     */
    private static $instance = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', array($this, 'init'));
    }

    public static function activate()
    {
        self::create_table();
        self::add_default_options();

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'daily', self::CRON_HOOK);
        }

        if (! wp_next_scheduled(self::SEED_HOOK)) {
            wp_schedule_single_event(time() + 60, self::SEED_HOOK);
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::SEED_HOOK);
    }

    public static function defaults()
    {
        return array(
            'enabled'           => 'yes',
            'days'              => 30,
            'label'             => 'Vorige laagste prijs (30 dagen):',
            'sale_only'         => 'yes',
            'ajax_fix_enabled'     => 'yes',
            'cart_checkout_enabled' => 'yes',
            'admin_history_enabled' => 'yes',
            'admin_history_limit'  => 10,
            'cleanup_after_days'   => 120,
        );
    }

    public static function add_default_options()
    {
        $existing = get_option(self::OPTION_KEY, array());
        update_option(self::OPTION_KEY, wp_parse_args($existing, self::defaults()));
    }

    public static function create_table()
    {
        global $wpdb;

        $table_name      = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            price DECIMAL(20,6) NOT NULL,
            regular_price DECIMAL(20,6) NULL DEFAULT NULL,
            sale_price DECIMAL(20,6) NULL DEFAULT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT '',
            recorded_at DATETIME NOT NULL,
            source VARCHAR(40) NOT NULL DEFAULT 'system',
            PRIMARY KEY  (id),
            KEY product_date (product_id, recorded_at),
            KEY product_price (product_id, price)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'sha_omnibus_price_history';
    }

    public function init()
    {
        if (! class_exists('WooCommerce') || ! function_exists('wc_get_product')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        add_filter('woocommerce_get_price_html', array($this, 'append_lowest_price_to_price_html'), 99, 2);
        add_filter('woocommerce_cart_item_price', array($this, 'append_lowest_price_to_cart_item_price'), 99, 3);
        add_filter('woocommerce_checkout_cart_item_quantity', array($this, 'append_lowest_price_to_checkout_quantity'), 99, 3);

        add_action('woocommerce_update_product', array($this, 'record_product_price_by_id'), 20, 1);
        add_action('woocommerce_update_product_variation', array($this, 'record_product_price_by_id'), 20, 1);
        add_action('save_post_product', array($this, 'maybe_record_on_product_save'), 20, 3);

        add_action(self::CRON_HOOK, array($this, 'daily_record_prices'));
        add_action(self::SEED_HOOK, array($this, 'daily_record_prices'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('wp_ajax_sha_omnibus_get_labels', array($this, 'ajax_get_labels'));
        add_action('wp_ajax_nopriv_sha_omnibus_get_labels', array($this, 'ajax_get_labels'));

        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('woocommerce_product_options_pricing', array($this, 'render_admin_simple_price_history'), 99);
        add_action('woocommerce_variation_options_pricing', array($this, 'render_admin_variation_price_history'), 99, 3);

        add_shortcode('sha_omnibus_lowest_price', array($this, 'shortcode_lowest_price'));
    }

    public function woocommerce_missing_notice()
    {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p><strong>sha Omnibus Lowest Price</strong> requires WooCommerce to be installed and active.</p></div>';
        }
    }

    private function options()
    {
        return wp_parse_args(get_option(self::OPTION_KEY, array()), self::defaults());
    }

    public function register_settings()
    {
        register_setting(
            'sha_omnibus_lowest_price_settings',
            self::OPTION_KEY,
            array(
                'type'              => 'array',
                'sanitize_callback' => array($this, 'sanitize_options'),
                'default'           => self::defaults(),
            )
        );
    }

    public function sanitize_options($input)
    {
        $defaults = self::defaults();
        $input    = is_array($input) ? $input : array();

        return array(
            'enabled'            => isset($input['enabled']) ? 'yes' : 'no',
            'days'               => max(1, min(365, absint($input['days'] ?? $defaults['days']))),
            'label'              => sanitize_text_field($input['label'] ?? $defaults['label']),
            'sale_only'          => isset($input['sale_only']) ? 'yes' : 'no',
            'ajax_fix_enabled'      => isset($input['ajax_fix_enabled']) ? 'yes' : 'no',
            'cart_checkout_enabled' => isset($input['cart_checkout_enabled']) ? 'yes' : 'no',
            'admin_history_enabled' => isset($input['admin_history_enabled']) ? 'yes' : 'no',
            'admin_history_limit'   => max(3, min(50, absint($input['admin_history_limit'] ?? $defaults['admin_history_limit']))),
            'cleanup_after_days'    => max(31, min(730, absint($input['cleanup_after_days'] ?? $defaults['cleanup_after_days']))),
        );
    }

    public function admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            'sha Omnibus Lowest Price',
            'sha Omnibus Price',
            'manage_woocommerce',
            'sha-omnibus-lowest-price',
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page()
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $seeded_count = null;

        if (isset($_POST['sha_omnibus_seed_prices'])) {
            check_admin_referer('sha_omnibus_seed_prices_action');
            $seeded_count = $this->record_all_prices('manual_seed');
        }

        $options = $this->options();
?>
        <div class="wrap">
            <h1>sha Omnibus Laagste Prijs</h1>

            <?php if (null !== $seeded_count) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html(sprintf('Prijsgeschiedenis opgeslagen/bijgewerkt voor %d productrecords.', $seeded_count)); ?></p>
                </div>
            <?php endif; ?>

            <p>
                Deze plugin registreert WooCommerce-productprijzen en toont de laagste geregistreerde prijs binnen de geselecteerde periode voor afgeprijsde producten.
                Het bevat ook een Flatsome/UX Builder AJAX-oplossing voor laad-meer productblokken.
            </p>

            <form method="post" action="options.php">
                <?php settings_fields('sha_omnibus_lowest_price_settings'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Weergave inschakelen</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enabled]" value="yes" <?php checked($options['enabled'], 'yes'); ?> />
                                Toon Omnibus laagste prijs op de frontend
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Registratieperiode</th>
                        <td>
                            <input type="number" min="1" max="365" name="<?php echo esc_attr(self::OPTION_KEY); ?>[days]" value="<?php echo esc_attr($options['days']); ?>" /> dagen
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Labeltekst</th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[label]" value="<?php echo esc_attr($options['label']); ?>" />
                            <p class="description">Voorbeeld: Laagste prijs in de afgelopen 30 dagen:</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Alleen afgeprijsde producten</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sale_only]" value="yes" <?php checked($options['sale_only'], 'yes'); ?> />
                                Alleen tonen wanneer het product momenteel in de aanbieding is
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Flatsome AJAX-oplossing</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ajax_fix_enabled]" value="yes" <?php checked($options['ajax_fix_enabled'], 'yes'); ?> />
                                Nieuw geladen producten opnieuw controleren na paginering/laad-meer AJAX
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Weergave in winkelwagen en afrekenen</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cart_checkout_enabled]" value="yes" <?php checked($options['cart_checkout_enabled'], 'yes'); ?> />
                                Toon de regel met laagste prijs in winkelwagen, mini-winkelwagen en afrekenregels
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Prijsgeschiedenis bij productbewerking</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[admin_history_enabled]" value="yes" <?php checked($options['admin_history_enabled'], 'yes'); ?> />
                                Toon prijswijzigingsgeschiedenis binnen het prijsgebied van het product
                            </label>
                            <p class="description">
                                Toon de laatste
                                <input type="number" min="3" max="50" name="<?php echo esc_attr(self::OPTION_KEY); ?>[admin_history_limit]" value="<?php echo esc_attr($options['admin_history_limit']); ?>" style="width:70px;" />
                                wijzigingsrecords per product of variatie.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Geschiedenis bewaren voor</th>
                        <td>
                            <input type="number" min="31" max="730" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cleanup_after_days]" value="<?php echo esc_attr($options['cleanup_after_days']); ?>" /> dagen
                            <p class="description">Oude geschiedenis ouder dan dit aantal dagen wordt opgeschoond tijdens de dagelijkse registratie.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Instellingen opslaan'); ?>
            </form>

            <hr />

            <h2>Huidige prijzen registreren</h2>
            <p>
                Gebruik dit één keer na het activeren van de plugin. Het registreert huidige product- en variatieprijzen als startpunt.
                De plugin kan geen historische prijzen van vóór de installatie herkennen, tenzij deze al door deze plugin waren geregistreerd.
            </p>

            <form method="post">
                <?php wp_nonce_field('sha_omnibus_seed_prices_action'); ?>
                <p>
                    <button type="submit" class="button button-secondary" name="sha_omnibus_seed_prices" value="1">
                        Huidige prijzen nu registreren
                    </button>
                </p>
            </form>
        </div>
    <?php
    }

    public function enqueue_frontend_assets()
    {
        $options = $this->options();

        wp_enqueue_style(
            'sha-omnibus-lowest-price',
            plugin_dir_url(__FILE__) . 'assets/css/frontend.css',
            array(),
            self::VERSION
        );

        if ('yes' !== $options['ajax_fix_enabled']) {
            return;
        }

        wp_enqueue_script(
            'sha-omnibus-lowest-price',
            plugin_dir_url(__FILE__) . 'assets/js/frontend.js',
            array('jquery'),
            self::VERSION,
            true
        );

        wp_localize_script(
            'sha-omnibus-lowest-price',
            'shaOmnibusLowestPrice',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            )
        );
    }

    public function append_lowest_price_to_price_html($price_html, $product)
    {
        if (is_admin() && ! wp_doing_ajax()) {
            return $price_html;
        }

        if (! $product instanceof WC_Product) {
            return $price_html;
        }

        if (false !== strpos((string) $price_html, 'sha-omnibus-lowest-price')) {
            return $price_html;
        }

        $label_html = $this->get_lowest_price_label_html($product);

        if ('' === $label_html) {
            return $price_html;
        }

        return $price_html . $label_html;
    }

    public function append_lowest_price_to_cart_item_price($price_html, $cart_item, $cart_item_key)
    {
        if (! $this->cart_checkout_display_enabled()) {
            return $price_html;
        }

        if (false !== strpos((string) $price_html, 'sha-omnibus-lowest-price')) {
            return $price_html;
        }

        $product = $this->get_product_from_cart_item($cart_item);
        if (! $product) {
            return $price_html;
        }

        $label_html = $this->get_lowest_price_label_html($product, 'cart');
        if ('' === $label_html) {
            return $price_html;
        }

        return $price_html . $label_html;
    }

    public function append_lowest_price_to_checkout_quantity($quantity_html, $cart_item, $cart_item_key)
    {
        if (! $this->cart_checkout_display_enabled() || ! function_exists('is_checkout') || ! is_checkout()) {
            return $quantity_html;
        }

        if (false !== strpos((string) $quantity_html, 'sha-omnibus-lowest-price')) {
            return $quantity_html;
        }

        $product = $this->get_product_from_cart_item($cart_item);
        if (! $product) {
            return $quantity_html;
        }

        $label_html = $this->get_lowest_price_label_html($product, 'checkout');
        if ('' === $label_html) {
            return $quantity_html;
        }

        return $quantity_html . $label_html;
    }

    private function cart_checkout_display_enabled()
    {
        $options = $this->options();
        return 'yes' === $options['enabled'] && 'yes' === $options['cart_checkout_enabled'];
    }

    private function get_product_from_cart_item($cart_item)
    {
        if (isset($cart_item['data']) && $cart_item['data'] instanceof WC_Product) {
            return $cart_item['data'];
        }

        $product_id = 0;

        if (! empty($cart_item['variation_id'])) {
            $product_id = absint($cart_item['variation_id']);
        } elseif (! empty($cart_item['product_id'])) {
            $product_id = absint($cart_item['product_id']);
        }

        return $product_id ? wc_get_product($product_id) : false;
    }

    private function should_display_for_product(WC_Product $product)
    {
        $options = $this->options();

        if ('yes' !== $options['enabled']) {
            return false;
        }

        if ('' === $product->get_price()) {
            return false;
        }

        if ('yes' === $options['sale_only'] && ! $product->is_on_sale()) {
            return false;
        }

        return true;
    }

    private function get_lowest_price_label_html(WC_Product $product, $context = 'frontend')
    {
        if (! $this->should_display_for_product($product)) {
            return '';
        }

        $record = $this->get_lowest_price_record($product);

        if (! $record) {
            $this->record_product_family_prices($product, 'first_view');
            $record = $this->get_lowest_price_record($product);
        }

        if (! $record || ! isset($record->price)) {
            return '';
        }

        $reference_product = wc_get_product(absint($record->product_id));
        if (! $reference_product) {
            $reference_product = $product;
        }

        $lowest_price = (float) $record->price;
        if ($lowest_price <= 0) {
            return '';
        }

        $display_price = wc_get_price_to_display($reference_product, array('price' => $lowest_price));
        $options       = $this->options();
        $label         = $options['label'] ?: self::defaults()['label'];

        $context = sanitize_html_class($context ?: 'frontend');

        return sprintf(
            '<small class="sha-omnibus-lowest-price sha-omnibus-lowest-price--%5$s" data-product-id="%1$d" data-record-product-id="%2$d"><span class="sha-omnibus-lowest-price__label">%3$s</span> <span class="sha-omnibus-lowest-price__amount">%4$s</span></small>',
            absint($product->get_id()),
            absint($record->product_id),
            esc_html($label),
            wp_kses_post(wc_price($display_price)),
            esc_attr($context)
        );
    }

    public function ajax_get_labels()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $raw_ids = isset($_POST['product_ids']) ? wp_unslash($_POST['product_ids']) : array();

        if (is_string($raw_ids)) {
            $raw_ids = explode(',', $raw_ids);
        }

        if (! is_array($raw_ids)) {
            wp_send_json_success(array());
        }

        $ids  = array_values(array_unique(array_filter(array_map('absint', $raw_ids))));
        $html = array();

        foreach ($ids as $product_id) {
            $product = wc_get_product($product_id);

            if (! $product) {
                continue;
            }

            $label = $this->get_lowest_price_label_html($product);

            if ('' !== $label) {
                $html[$product_id] = $label;
            }
        }

        wp_send_json_success($html);
    }

    public function shortcode_lowest_price($atts)
    {
        $atts = shortcode_atts(
            array(
                'id' => 0,
            ),
            $atts,
            'sha_omnibus_lowest_price'
        );

        $product_id = absint($atts['id']);

        if (! $product_id && function_exists('is_product') && is_product()) {
            global $product;
            if ($product instanceof WC_Product) {
                $product_id = $product->get_id();
            }
        }

        if (! $product_id) {
            return '';
        }

        $product = wc_get_product($product_id);
        if (! $product) {
            return '';
        }

        return $this->get_lowest_price_label_html($product);
    }

    public function render_admin_simple_price_history()
    {
        $options = $this->options();
        if ('yes' !== $options['admin_history_enabled']) {
            return;
        }

        global $post;
        if (! $post || 'product' !== $post->post_type) {
            return;
        }

        $product = wc_get_product($post->ID);
        if (! $product || $product->is_type('variable')) {
            return;
        }

        $this->render_admin_price_history_panel($product, 'simple');
    }

    public function render_admin_variation_price_history($loop, $variation_data, $variation)
    {
        $options = $this->options();
        if ('yes' !== $options['admin_history_enabled']) {
            return;
        }

        if (! $variation instanceof WP_Post) {
            return;
        }

        $product = wc_get_product($variation->ID);
        if (! $product) {
            return;
        }

        $this->render_admin_price_history_panel($product, 'variation');
    }

    private function render_admin_price_history_panel(WC_Product $product, $context = 'simple')
    {
        $options = $this->options();
        $limit   = max(3, min(50, absint($options['admin_history_limit'])));
        $rows    = $this->get_price_change_history_rows($product->get_id(), $limit);
        $lowest  = $this->get_lowest_price_record($product);
        $days    = max(1, absint($options['days']));

        $this->render_admin_history_styles_once();

        $context_class = 'variation' === $context ? 'sha-omnibus-admin-history--variation' : 'sha-omnibus-admin-history--simple';
    ?>
        <div class="sha-omnibus-admin-history <?php echo esc_attr($context_class); ?>">
            <details>
                <summary>
                    <strong>Omnibus price changes history</strong>
                    <?php if ($lowest && isset($lowest->price)) : ?>
                        <span class="sha-omnibus-admin-history__lowest">
                            Lowest in last <?php echo esc_html($days); ?> days: <?php echo wp_kses_post(wc_price((float) $lowest->price)); ?>
                        </span>
                    <?php endif; ?>
                </summary>

                <?php if (empty($rows)) : ?>
                    <p class="sha-omnibus-admin-history__empty">
                        No price history recorded yet. Update this product or use WooCommerce → sha Omnibus Price → Seed Current Prices Now.
                    </p>
                <?php else : ?>
                    <table class="widefat striped sha-omnibus-admin-history__table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Price</th>
                                <th>Regular</th>
                                <th>Sale</th>
                                <th>Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) : ?>
                                <tr>
                                    <td><?php echo esc_html($this->format_admin_datetime($row->recorded_at)); ?></td>
                                    <td><?php echo wp_kses_post(wc_price((float) $row->price)); ?></td>
                                    <td><?php echo '' !== (string) $row->regular_price && null !== $row->regular_price ? wp_kses_post(wc_price((float) $row->regular_price)) : '&mdash;'; ?></td>
                                    <td><?php echo '' !== (string) $row->sale_price && null !== $row->sale_price ? wp_kses_post(wc_price((float) $row->sale_price)) : '&mdash;'; ?></td>
                                    <td><?php echo esc_html($this->format_source_label($row->source)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </details>
        </div>
    <?php
    }

    private function render_admin_history_styles_once()
    {
        static $printed = false;

        if ($printed) {
            return;
        }

        $printed = true;
    ?>
        <style>
            .sha-omnibus-admin-history {
                clear: both;
                margin: 12px 12px 14px;
                padding: 10px 12px;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                background: #fff;
                box-sizing: border-box
            }

            .sha-omnibus-admin-history--simple {
                margin-left: 0;
                margin-right: 0
            }

            .sha-omnibus-admin-history summary {
                cursor: pointer;
                display: flex;
                gap: 10px;
                align-items: center;
                flex-wrap: wrap
            }

            .sha-omnibus-admin-history__lowest {
                font-size: 12px;
                color: #1d2327;
                font-weight: 500
            }

            .sha-omnibus-admin-history__empty {
                margin: 10px 0 0;
                color: #646970
            }

            .sha-omnibus-admin-history__table {
                margin-top: 10px
            }

            .sha-omnibus-admin-history__table th,
            .sha-omnibus-admin-history__table td {
                font-size: 12px;
                padding: 6px 8px
            }

            .woocommerce_variation .sha-omnibus-admin-history {
                margin-top: 8px;
                width: calc(100% - 24px)
            }
        </style>
<?php
    }

    private function get_price_change_history_rows($product_id, $limit = 10)
    {
        global $wpdb;

        $product_id = absint($product_id);
        $limit      = max(1, absint($limit));

        if (! $product_id) {
            return array();
        }

        $raw_rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, product_id, price, regular_price, sale_price, currency, recorded_at, source
                 FROM ' . self::table_name() . '
                 WHERE product_id = %d
                 ORDER BY recorded_at DESC, id DESC
                 LIMIT %d',
                $product_id,
                max($limit * 10, 50)
            )
        );

        if (empty($raw_rows)) {
            return array();
        }

        $changes = array();
        $last_key = null;

        foreach ($raw_rows as $row) {
            $key = $this->history_row_change_key($row);

            if (null === $last_key || $key !== $last_key) {
                $changes[] = $row;
                $last_key = $key;
            }

            if (count($changes) >= $limit) {
                break;
            }
        }

        return $changes;
    }

    private function history_row_change_key($row)
    {
        return implode(
            '|',
            array(
                $this->normalize_history_number($row->price),
                $this->normalize_history_number($row->regular_price),
                $this->normalize_history_number($row->sale_price),
            )
        );
    }

    private function normalize_history_number($value)
    {
        if (null === $value || '' === (string) $value || ! is_numeric($value)) {
            return '';
        }

        return wc_format_decimal($value, wc_get_price_decimals() + 2);
    }

    private function format_admin_datetime($mysql_datetime)
    {
        $timestamp = strtotime($mysql_datetime);
        if (! $timestamp) {
            return $mysql_datetime;
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    private function format_source_label($source)
    {
        $source = (string) $source;
        $labels = array(
            'manual_seed'    => 'Manual seed',
            'daily_cron'     => 'Daily record',
            'first_view'     => 'First view',
            'product_save'   => 'Product save',
            'product_update' => 'Product update',
            'system'         => 'System',
        );

        return isset($labels[$source]) ? $labels[$source] : ucwords(str_replace('_', ' ', $source));
    }

    public function maybe_record_on_product_save($post_id, $post, $update)
    {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        $this->record_product_price_by_id($post_id, 'product_save');
    }

    public function record_product_price_by_id($product_id, $source = 'product_update')
    {
        $product_id = absint($product_id);
        if (! $product_id || ! function_exists('wc_get_product')) {
            return false;
        }

        $product = wc_get_product($product_id);
        if (! $product) {
            return false;
        }

        $count = 0;

        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $child_id) {
                $child_product = wc_get_product($child_id);
                if ($child_product && $this->insert_price_record($child_product, $source)) {
                    $count++;
                }
            }
        }

        if ($this->insert_price_record($product, $source)) {
            $count++;
        }

        return $count;
    }

    private function record_product_family_prices(WC_Product $product, $source = 'system')
    {
        $count = 0;

        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $child_id) {
                $child = wc_get_product($child_id);
                if ($child && $this->insert_price_record($child, $source)) {
                    $count++;
                }
            }
        }

        if ($this->insert_price_record($product, $source)) {
            $count++;
        }

        return $count;
    }

    private function insert_price_record(WC_Product $product, $source = 'system')
    {
        global $wpdb;

        $product_id = absint($product->get_id());
        $price      = $product->get_price();

        if (! $product_id || '' === $price || ! is_numeric($price)) {
            return false;
        }

        $price         = (float) wc_format_decimal($price, wc_get_price_decimals() + 2);
        $regular_price = $product->get_regular_price();
        $sale_price    = $product->get_sale_price();
        $now           = current_time('mysql');

        $last = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT price, regular_price, sale_price, recorded_at FROM ' . self::table_name() . ' WHERE product_id = %d ORDER BY recorded_at DESC LIMIT 1',
                $product_id
            )
        );

        if ($last) {
            $last_time = strtotime($last->recorded_at);
            $now_time  = current_time('timestamp');

            $same_price = abs((float) $last->price - $price) < 0.000001;
            $same_regular = $this->nullable_float_same($last->regular_price, $regular_price);
            $same_sale = $this->nullable_float_same($last->sale_price, $sale_price);

            if ($same_price && $same_regular && $same_sale && $last_time && ($now_time - $last_time) < HOUR_IN_SECONDS) {
                return false;
            }
        }

        return false !== $wpdb->insert(
            self::table_name(),
            array(
                'product_id'    => $product_id,
                'price'         => $price,
                'regular_price' => '' !== $regular_price && is_numeric($regular_price) ? (float) wc_format_decimal($regular_price, wc_get_price_decimals() + 2) : null,
                'sale_price'    => '' !== $sale_price && is_numeric($sale_price) ? (float) wc_format_decimal($sale_price, wc_get_price_decimals() + 2) : null,
                'currency'      => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
                'recorded_at'   => $now,
                'source'        => sanitize_key($source),
            ),
            array('%d', '%f', '%f', '%f', '%s', '%s', '%s')
        );
    }

    private function nullable_float_same($stored, $current)
    {
        $stored_is_numeric  = is_numeric($stored);
        $current_is_numeric = '' !== $current && is_numeric($current);

        if (! $stored_is_numeric && ! $current_is_numeric) {
            return true;
        }

        if ($stored_is_numeric !== $current_is_numeric) {
            return false;
        }

        return abs((float) $stored - (float) $current) < 0.000001;
    }

    private function get_lowest_price_record(WC_Product $product)
    {
        global $wpdb;

        $ids = $this->get_product_family_ids($product);
        if (empty($ids)) {
            return null;
        }

        $options = $this->options();
        $days    = max(1, absint($options['days']));
        $since   = date('Y-m-d H:i:s', current_time('timestamp') - (DAY_IN_SECONDS * $days));

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $query_args   = array_merge($ids, array($since));

        $sql = $wpdb->prepare(
            "SELECT product_id, price, recorded_at
             FROM " . self::table_name() . "
             WHERE product_id IN ({$placeholders})
               AND recorded_at >= %s
               AND price > 0
             ORDER BY price ASC, recorded_at DESC
             LIMIT 1",
            $query_args
        );

        return $wpdb->get_row($sql);
    }

    private function get_product_family_ids(WC_Product $product)
    {
        $ids = array(absint($product->get_id()));

        if ($product->is_type('variable')) {
            $ids = array_merge($ids, array_map('absint', $product->get_children()));
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public function daily_record_prices()
    {
        $count = $this->record_all_prices('daily_cron');
        $this->cleanup_old_history();
        return $count;
    }

    public function record_all_prices($source = 'system')
    {
        $query = new WP_Query(
            array(
                'post_type'              => array('product', 'product_variation'),
                'post_status'            => array('publish', 'private'),
                'fields'                 => 'ids',
                'posts_per_page'         => -1,
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );

        $count = 0;

        if (! empty($query->posts)) {
            foreach ($query->posts as $product_id) {
                $product = wc_get_product($product_id);
                if ($product && $this->insert_price_record($product, $source)) {
                    $count++;
                }
            }
        }

        wp_reset_postdata();

        return $count;
    }

    private function cleanup_old_history()
    {
        global $wpdb;

        $options = $this->options();
        $days    = max(31, absint($options['cleanup_after_days']));
        $before  = date('Y-m-d H:i:s', current_time('timestamp') - (DAY_IN_SECONDS * $days));

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . self::table_name() . ' WHERE recorded_at < %s',
                $before
            )
        );
    }
}

register_activation_hook(__FILE__, array('sha_Omnibus_Lowest_Price_For_WooCommerce', 'activate'));
register_deactivation_hook(__FILE__, array('sha_Omnibus_Lowest_Price_For_WooCommerce', 'deactivate'));

sha_Omnibus_Lowest_Price_For_WooCommerce::instance();
