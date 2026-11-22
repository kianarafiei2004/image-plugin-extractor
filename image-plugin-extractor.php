<?php
/**
 * Plugin Name: Image Plugin Extractor
 * Plugin URI: https://github.com/kianarafiei2004
 * Description: افزونه اختصاصی استخراج کاتالوگ و تصاویر محصولات ووکامرس، ممیزی هوشمند لینک‌سازی داخلی مقالات و کشف فرصت‌های سئو با اتصال مستقیم به گوتنبرگ.
 * Version: 1.0.1
 * Author: kiana
 * Author URI: https://github.com/kianarafiei2004
 * License: GPLv2 or later
 * Text Domain: image-plugin-extractor
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 *
 * @package Image_Plugin_Extractor
 */

defined('ABSPATH') || exit;

/**
 * Technical Plugin Constants
 */
define('IPE_VERSION', '1.0.1');
define('IPE_PREFIX', 'ipe_');
define('IPE_FILE', __FILE__);
define('IPE_DIR', plugin_dir_path(__FILE__));
define('IPE_URL', plugin_dir_url(__FILE__));
define('IPE_RESULTS_TRANSIENT', 'ipe_scan_results');
define('IPE_TEMP_RESULTS_TRANSIENT', 'ipe_scan_temp_results');
define('IPE_TEMP_IDS_TRANSIENT', 'ipe_scan_all_ids');
define('IPE_TEMP_POSTS_TRANSIENT', 'ipe_scan_indexed_posts');
define('IPE_CACHE_EXPIRATION', 7 * DAY_IN_SECONDS);
define('IPE_BATCH_SIZE', 25);

/**
 * Class IPE_Plugin
 * Main Singleton Bootstrap for Image Plugin Extractor.
 */
final class IPE_Plugin {

    /**
     * Singleton instance.
     *
     * @var IPE_Plugin|null
     */
    private static $instance = null;

    /**
     * Get Singleton Instance.
     *
     * @return IPE_Plugin
     */
    public static function get_instance(): IPE_Plugin {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}

    /**
     * Prevent unserializing.
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }

    /**
     * Initialize WordPress hooks.
     */
    private function init_hooks(): void {
        add_action('plugins_loaded', [$this, 'on_plugins_loaded']);
        add_action('admin_menu', ['IPE_Admin', 'register_menu']);
        add_action('admin_enqueue_scripts', ['IPE_Admin', 'enqueue_assets']);

        // Register AJAX handlers
        add_action('wp_ajax_ipe_init_scan', ['IPE_Scanner', 'ajax_init_scan']);
        add_action('wp_ajax_ipe_process_batch', ['IPE_Scanner', 'ajax_process_batch']);
        add_action('wp_ajax_ipe_finalize_scan', ['IPE_Scanner', 'ajax_finalize_scan']);
        add_action('wp_ajax_ipe_reset_data', ['IPE_Scanner', 'ajax_reset_data']);

        // Register Admin Post Export handlers
        add_action('admin_post_ipe_export_csv', ['IPE_Exporter', 'export_csv']);
        add_action('admin_post_ipe_export_json', ['IPE_Exporter', 'export_json']);
    }

    /**
     * Handle plugins_loaded: Verify WooCommerce dependency.
     */
    public function on_plugins_loaded(): void {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'notice_woocommerce_missing']);
        }
    }

    /**
     * Display admin notice when WooCommerce is inactive.
     */
    public function notice_woocommerce_missing(): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <strong><?php echo esc_html__('افزونه Image Plugin Extractor:', 'image-plugin-extractor'); ?></strong>
                <?php echo esc_html__('برای کارکرد صحیح این افزونه، باید ووکامرس (WooCommerce) فعال باشد.', 'image-plugin-extractor'); ?>
            </p>
        </div>
        <?php
    }
}

/**
 * Class IPE_Scanner
 * Handles Catalog & Media Extraction, Persian Normalization, Product Tags (Synonyms), and Cross-Referencing.
 */
class IPE_Scanner {

    /**
     * Normalize Persian and Arabic text:
     * - Convert Arabic 'ي' to 'ی' and 'ك' to 'ک'
     * - Strip Arabic Harakat/Tashkeel/Tanween
     * - Remove Zero-Width Non-Joiner (ZWNJ \x{200C}), ZWJ, and BOM
     * - Collapse extra whitespaces
     *
     * @param string $text Raw input text.
     * @return string Clean normalized text.
     */
    public static function normalize_persian_text(string $text): string {
        if (empty($text)) {
            return '';
        }

        // 1. Unify Arabic characters to standard Persian equivalents
        $arabic_to_persian = [
            'ي' => 'ی',
            'ك' => 'ک',
            'ى' => 'ی',
            'ئ' => 'ی',
            'ة' => 'ه',
            'ؤ' => 'و',
            'إ' => 'ا',
            'أ' => 'ا',
            'آ' => 'ا',
            'ء' => '',
        ];
        $text = strtr($text, $arabic_to_persian);

        // 2. Remove Arabic Harakat, Tashkeel, Tanween, etc.
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);

        // 3. Remove Zero-Width Non-Joiner (ZWNJ), ZWJ, and BOM
        $text = preg_replace('/[\x{200C}\x{200D}\x{FEFF}]/u', '', $text);

        // 4. Normalize multiple whitespaces and Unicode spacing to single standard space
        $text = preg_replace('/[\s\p{Z}]+/u', ' ', $text);

        // 5. Trim and convert English characters to lowercase
        return mb_strtolower(trim($text), 'UTF-8');
    }

    /**
     * Extract a surrounding text snippet around the matched term.
     *
     * @param string $text Full plain text of the post.
     * @param string $term Matched search term.
     * @param int $radius Number of characters before and after.
     * @return string Truncated snippet.
     */
    public static function extract_snippet(string $text, string $term, int $radius = 60): string {
        if (empty($text) || empty($term)) {
            return '';
        }

        $clean = preg_replace('/[\s\p{Z}]+/u', ' ', trim($text));

        // 1. Direct search
        $pos = mb_stripos($clean, $term, 0, 'UTF-8');
        $match_len = mb_strlen($term, 'UTF-8');

        // 2. Normalized fallback if exact spelling differs (ZWNJ, Arabic Kaf/Yeh)
        if ($pos === false) {
            $norm_text = self::normalize_persian_text($clean);
            $norm_term = self::normalize_persian_text($term);
            $pos = mb_stripos($norm_text, $norm_term, 0, 'UTF-8');
            $match_len = mb_strlen($norm_term, 'UTF-8');
        }

        if ($pos === false) {
            return '';
        }

        $start   = max(0, $pos - $radius);
        $length  = $match_len + ($radius * 2);
        $snippet = mb_substr($clean, $start, $length, 'UTF-8');
        $snippet = preg_replace('/\s+/u', ' ', trim($snippet));

        if ($start > 0) {
            $snippet = '...' . $snippet;
        }
        if ($start + $length < mb_strlen($clean, 'UTF-8')) {
            $snippet .= '...';
        }

        return $snippet;
    }

    /**
     * AJAX Step 1: Initialize Scan and extract all published WooCommerce product IDs.
     */
    public static function ajax_init_scan(): void {
        check_ajax_referer('ipe_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('دسترسی غیرمجاز است.', 'image-plugin-extractor')], 403);
        }

        // Memory and cache optimization
        wp_suspend_cache_addition(true);

        $product_ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        wp_suspend_cache_addition(false);

        if (empty($product_ids)) {
            wp_send_json_error([
                'message' => __('هیچ محصول منتشرشده‌ای در ووکامرس یافت نشد.', 'image-plugin-extractor'),
            ]);
        }

        // Reset temporary transient stores
        delete_transient(IPE_TEMP_RESULTS_TRANSIENT);
        delete_transient(IPE_TEMP_POSTS_TRANSIENT);
        set_transient(IPE_TEMP_IDS_TRANSIENT, $product_ids, HOUR_IN_SECONDS);
        set_transient(IPE_TEMP_RESULTS_TRANSIENT, [], HOUR_IN_SECONDS);

        wp_send_json_success([
            'total'       => count($product_ids),
            'batch_size'  => IPE_BATCH_SIZE,
            'product_ids' => $product_ids,
            'message'     => sprintf(__('تعداد %d محصول برای پردازش آماده شد.', 'image-plugin-extractor'), count($product_ids)),
        ]);
    }

    /**
     * Get or build pre-cached index of published blog posts for cross-referencing.
     * Caches stripped & normalized text and links for 1 hour to handle 5000+ posts efficiently.
     *
     * @return array Pre-indexed posts list.
     */
    public static function get_indexed_blog_posts(): array {
        $cached = get_transient(IPE_TEMP_POSTS_TRANSIENT);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        global $wpdb;

        // Fetch published blog posts lightweight
        $raw_posts = $wpdb->get_results(
            "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'",
            ARRAY_A
        );

        $indexed_posts = [];
        if (!empty($raw_posts)) {
            foreach ($raw_posts as $p) {
                $post_id = (int) $p['ID'];
                $content = $p['post_content'];

                // Extract all href URLs and anchor texts in the blog post
                $links = [];
                if (preg_match_all('/<a\s+[^>]*?href=[\'"]([^\'"]+)[\'"][^>]*>(.*?)<\/a>/is', $content, $link_matches, PREG_SET_ORDER)) {
                    foreach ($link_matches as $lm) {
                        $href   = trim($lm[1]);
                        $anchor = trim(wp_strip_all_tags($lm[2]));
                        $links[] = [
                            'url'    => $href,
                            'anchor' => $anchor,
                        ];
                    }
                }

                // Strip all HTML tags to avoid false matching inside image alt/src/class
                $clean_text      = wp_strip_all_tags($content);
                $normalized_text = self::normalize_persian_text($clean_text);

                $indexed_posts[] = [
                    'id'                 => $post_id,
                    'title'              => $p['post_title'],
                    'permalink'          => get_permalink($post_id),
                    'edit_url'           => admin_url('post.php?post=' . $post_id . '&action=edit'),
                    'links'              => $links,
                    'clean_text'         => $clean_text,
                    'normalized_content' => $normalized_text,
                ];
            }
        }

        unset($raw_posts);

        // Pre-cache in transient for 1 hour so subsequent batches don't hit the DB or re-normalize
        set_transient(IPE_TEMP_POSTS_TRANSIENT, $indexed_posts, HOUR_IN_SECONDS);

        return $indexed_posts;
    }

    /**
     * AJAX Step 2: Process a batch of 25 products.
     */
    public static function ajax_process_batch(): void {
        check_ajax_referer('ipe_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('دسترسی غیرمجاز است.', 'image-plugin-extractor')], 403);
        }

        $raw_ids   = isset($_POST['product_ids']) ? (array) $_POST['product_ids'] : [];
        $batch_ids = array_map('absint', $raw_ids);
        $offset    = isset($_POST['offset']) ? absint($_POST['offset']) : 0;

        if (empty($batch_ids)) {
            wp_send_json_error(['message' => __('شناسه محصولی برای پردازش ارسال نشده است.', 'image-plugin-extractor')]);
        }

        // Disable WordPress object cache addition to prevent RAM bloat
        wp_suspend_cache_addition(true);

        // Retrieve pre-cached index of published blog posts (1-hour transient)
        $indexed_posts = self::get_indexed_blog_posts();

        // Process each product in the batch
        $batch_results = [];

        foreach ($batch_ids as $product_id) {
            $product_title = get_the_title($product_id);

            // Ignore titles with fewer than 3 characters to prevent accidental matching
            if (mb_strlen(trim($product_title), 'UTF-8') < 3) {
                continue;
            }

            $normalized_product_title = self::normalize_persian_text($product_title);
            if (mb_strlen($normalized_product_title, 'UTF-8') < 3) {
                continue;
            }

            // Construct Unicode Word Boundary RegEx:
            // Ensures words like "لوپ" never match inside "تلسکوپ"
            $escaped_title = preg_quote($normalized_product_title, '/');
            $regex_pattern = '/(?<![\p{L}\p{N}])' . $escaped_title . '(?![\p{L}\p{N}])/u';

            $product_permalink = get_permalink($product_id);
            $product_slug      = get_post_field('post_name', $product_id);

            // Featured Image URLs
            $thumb_id = get_post_thumbnail_id($product_id);
            if ($thumb_id) {
                $thumb_url      = wp_get_attachment_image_url($thumb_id, 'thumbnail');
                $full_image_url = wp_get_attachment_image_url($thumb_id, 'full');
            } else {
                $placeholder    = function_exists('wc_placeholder_img_src') ? wc_placeholder_img_src('thumbnail') : '';
                $thumb_url      = $placeholder;
                $full_image_url = function_exists('wc_placeholder_img_src') ? wc_placeholder_img_src('full') : $placeholder;
            }

            // Categories
            $categories = [];
            $terms = get_the_terms($product_id, 'product_cat');
            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $categories[] = $term->name;
                }
            }
            $categories_str = !empty($categories) ? implode('، ', $categories) : '—';

            // Product Tags as Synonyms (Secondary search terms)
            $tag_search_terms = [];
            $product_tags = get_the_terms($product_id, 'product_tag');
            if (!empty($product_tags) && !is_wp_error($product_tags)) {
                foreach ($product_tags as $tag_term) {
                    $raw_tag = trim($tag_term->name);
                    if (mb_strlen($raw_tag, 'UTF-8') < 3) {
                        continue;
                    }
                    $normalized_tag = self::normalize_persian_text($raw_tag);
                    if (mb_strlen($normalized_tag, 'UTF-8') >= 3 && $normalized_tag !== $normalized_product_title) {
                        $tag_search_terms[$raw_tag] = [
                            'normalized' => $normalized_tag,
                            'pattern'    => '/(?<![\p{L}\p{N}])' . preg_quote($normalized_tag, '/') . '(?![\p{L}\p{N}])/u',
                        ];
                    }
                }
            }

            // Cross-reference blog posts
            $linked_posts   = [];
            $unlinked_posts = [];

            foreach ($indexed_posts as $post) {
                $is_matched      = false;
                $matched_term    = '';
                $matched_keyword = '';

                // 1. Primary check: Full Product Title
                if (mb_strpos($post['normalized_content'], $normalized_product_title) !== false) {
                    if (preg_match($regex_pattern, $post['normalized_content'])) {
                        $is_matched      = true;
                        $matched_term    = __('نام کامل محصول', 'image-plugin-extractor');
                        $matched_keyword = $product_title;
                    }
                }

                // 2. Secondary check: Product Tags (Synonyms) if full title was not found
                if (!$is_matched && !empty($tag_search_terms)) {
                    foreach ($tag_search_terms as $original_tag_name => $tag_info) {
                        if (mb_strpos($post['normalized_content'], $tag_info['normalized']) !== false) {
                            if (preg_match($tag_info['pattern'], $post['normalized_content'])) {
                                $is_matched      = true;
                                $matched_term    = sprintf(__('برچسب: %s', 'image-plugin-extractor'), $original_tag_name);
                                $matched_keyword = $original_tag_name;
                                break; // Stop checking further tags for this post (enforce max 1 match per article)
                            }
                        }
                    }
                }

                if ($is_matched) {
                    // Check if already linked to this product & extract anchor text
                    $is_linked      = false;
                    $matched_anchor = '';

                    foreach ($post['links'] as $link_item) {
                        $href   = is_array($link_item) ? $link_item['url'] : $link_item;
                        $anchor = is_array($link_item) && !empty($link_item['anchor']) ? $link_item['anchor'] : '';

                        if (
                            $href === $product_permalink ||
                            rtrim($href, '/') === rtrim($product_permalink, '/') ||
                            (!empty($product_slug) && strpos($href, '/' . $product_slug) !== false) ||
                            strpos($href, 'p=' . $product_id) !== false
                        ) {
                            $is_linked      = true;
                            $matched_anchor = $anchor;
                            break;
                        }
                    }

                    // Extract surrounding context snippet
                    $snippet_target = !empty($matched_anchor) ? $matched_anchor : $matched_keyword;
                    $raw_content    = !empty($post['clean_text']) ? $post['clean_text'] : (!empty($post['normalized_content']) ? $post['normalized_content'] : '');
                    $snippet        = self::extract_snippet($raw_content, $snippet_target, 60);
                    if (empty($snippet) && !empty($matched_keyword) && $snippet_target !== $matched_keyword) {
                        $snippet = self::extract_snippet($raw_content, $matched_keyword, 60);
                    }

                    $post_data = [
                        'id'           => $post['id'],
                        'title'        => $post['title'],
                        'url'          => $post['permalink'],
                        'edit_url'     => $post['edit_url'],
                        'matched_term' => $matched_term,
                        'keyword'      => $matched_keyword,
                        'anchor'       => $matched_anchor,
                        'snippet'      => $snippet,
                    ];

                    // Strictly enforce maximum 1 opportunity per article per product
                    if ($is_linked) {
                        $linked_posts[] = $post_data;
                    } else {
                        $unlinked_posts[] = $post_data;
                    }
                }
            }

            $batch_results[] = [
                'id'              => $product_id,
                'title'           => $product_title,
                'permalink'       => $product_permalink,
                'thumb_url'       => $thumb_url,
                'full_image_url'  => $full_image_url,
                'categories'      => $categories_str,
                'linked_posts'    => $linked_posts,
                'linked_count'    => count($linked_posts),
                'unlinked_posts'  => $unlinked_posts,
                'unlinked_count'  => count($unlinked_posts),
                'has_opportunity' => count($unlinked_posts) > 0,
            ];
        }

        // Append to temporary transient
        $temp_results = get_transient(IPE_TEMP_RESULTS_TRANSIENT);
        if (!is_array($temp_results)) {
            $temp_results = [];
        }
        $temp_results = array_merge($temp_results, $batch_results);
        set_transient(IPE_TEMP_RESULTS_TRANSIENT, $temp_results, HOUR_IN_SECONDS);

        // Memory cleanup
        unset($indexed_posts, $batch_results);
        wp_suspend_cache_addition(false);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        wp_send_json_success([
            'processed' => count($batch_ids),
            'offset'    => $offset + count($batch_ids),
        ]);
    }

    /**
     * AJAX Step 3: Finalize scan and persist results to 7-day transient in wp_options.
     */
    public static function ajax_finalize_scan(): void {
        check_ajax_referer('ipe_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('دسترسی غیرمجاز است.', 'image-plugin-extractor')], 403);
        }

        $temp_results = get_transient(IPE_TEMP_RESULTS_TRANSIENT);
        if (!is_array($temp_results)) {
            $temp_results = [];
        }

        $total_products      = count($temp_results);
        $opportunities_count = 0;
        $linked_count        = 0;

        foreach ($temp_results as $item) {
            if (!empty($item['has_opportunity'])) {
                $opportunities_count++;
            }
            if (!empty($item['linked_count']) && $item['linked_count'] > 0) {
                $linked_count++;
            }
        }

        $final_data = [
            'scanned_at'          => current_time('mysql'),
            'timestamp'           => time(),
            'total_products'      => $total_products,
            'opportunities_count' => $opportunities_count,
            'linked_count'        => $linked_count,
            'items'               => $temp_results,
        ];

        // Store with 7-day expiration
        set_transient(IPE_RESULTS_TRANSIENT, $final_data, IPE_CACHE_EXPIRATION);

        // Clean temporary transients
        delete_transient(IPE_TEMP_RESULTS_TRANSIENT);
        delete_transient(IPE_TEMP_IDS_TRANSIENT);
        delete_transient(IPE_TEMP_POSTS_TRANSIENT);

        wp_send_json_success([
            'total'               => $total_products,
            'opportunities_count' => $opportunities_count,
            'linked_count'        => $linked_count,
            'message'             => __('اسکن کامل محصولات با موفقیت به پایان رسید و ذخیره شد.', 'image-plugin-extractor'),
        ]);
    }

    /**
     * AJAX: Reset scan results and clear transients.
     */
    public static function ajax_reset_data(): void {
        check_ajax_referer('ipe_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('دسترسی غیرمجاز است.', 'image-plugin-extractor')], 403);
        }

        delete_transient(IPE_RESULTS_TRANSIENT);
        delete_transient(IPE_TEMP_RESULTS_TRANSIENT);
        delete_transient(IPE_TEMP_IDS_TRANSIENT);
        delete_transient(IPE_TEMP_POSTS_TRANSIENT);

        wp_send_json_success([
            'message' => __('کلیه اطلاعات اسکن با موفقیت بازنشانی شد.', 'image-plugin-extractor'),
        ]);
    }
}

/**
 * Class IPE_Admin
 * Manages Admin Dashboard UI with Nordic Teal & Crimson UI based on media reference.
 */
class IPE_Admin {

    /**
     * Register Admin Menu.
     */
    public static function register_menu(): void {
        add_menu_page(
            __('استخراج تصاویر و تحلیل سئو', 'image-plugin-extractor'),
            __('استخراج تصاویر و لینک‌ها', 'image-plugin-extractor'),
            'manage_options',
            'image-plugin-extractor',
            [self::class, 'render_dashboard'],
            'dashicons-images-alt2',
            30
        );
    }

    /**
     * Enqueue Admin Assets.
     *
     * @param string $hook_suffix Current admin screen hook.
     */
    public static function enqueue_assets(string $hook_suffix): void {
        if ($hook_suffix !== 'toplevel_page_image-plugin-extractor') {
            return;
        }

        wp_enqueue_script('jquery');

        // Pass server data to JavaScript
        wp_localize_script('jquery', 'ipeData', [
            'ajax_url'   => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('ipe_ajax_nonce'),
            'batch_size' => IPE_BATCH_SIZE,
            'messages'   => [
                'confirm_reset' => __('آیا مطمئن هستید که می‌خواهید تمام داده‌های اسکن‌شده را بازنشانی و پاک کنید؟', 'image-plugin-extractor'),
                'clearing'      => __('در حال پاکسازی...', 'image-plugin-extractor'),
                'reset_btn'     => __('بازنشانی داده‌ها', 'image-plugin-extractor'),
            ],
        ]);

        // Add inline JavaScript for Batch Loop and AJAX
        $custom_js = <<<'JAVASCRIPT'
jQuery(document).ready(function($) {
    var totalProducts = 0;
    var productIds = [];
    var batchSize = (typeof ipeData !== 'undefined' && ipeData.batch_size) ? ipeData.batch_size : 25;
    var processedCount = 0;
    var isScanning = false;

    function updateProgress(processed, total, statusText) {
        var percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
        $('#ipe-progress-fill').css('width', percent + '%');
        $('#ipe-progress-percentage').text(percent + '%');
        $('#ipe-progress-status').text(statusText);
    }

    function processBatch(startIndex) {
        if (!isScanning) return;

        if (startIndex >= totalProducts) {
            finalizeScan();
            return;
        }

        var chunk = productIds.slice(startIndex, startIndex + batchSize);
        var currentNum = Math.min(startIndex + batchSize, totalProducts);
        updateProgress(startIndex, totalProducts, 'اسکن محصول ' + (startIndex + 1) + ' تا ' + currentNum + ' از ' + totalProducts + '...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ipe_process_batch',
                nonce: ipeData.nonce,
                product_ids: chunk,
                offset: startIndex
            },
            success: function(res) {
                if (res.success) {
                    processedCount += chunk.length;
                    updateProgress(processedCount, totalProducts, 'اسکن محصول ' + processedCount + ' از ' + totalProducts + '...');
                    processBatch(startIndex + batchSize);
                } else {
                    alert(res.data && res.data.message ? res.data.message : 'خطا در پردازش دسته محصولات.');
                    isScanning = false;
                    $('#ipe-scan-modal').hide();
                }
            },
            error: function(xhr, status, error) {
                setTimeout(function() {
                    processBatch(startIndex);
                }, 2000);
            }
        });
    }

    function finalizeScan() {
        updateProgress(totalProducts, totalProducts, 'در حال نهایی‌سازی، دسته‌بندی و کش نتایج...');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ipe_finalize_scan',
                nonce: ipeData.nonce
            },
            success: function(res) {
                if (res.success) {
                    updateProgress(totalProducts, totalProducts, 'تکمیل شد! در حال بارگذاری مجدد...');
                    setTimeout(function() {
                        window.location.reload();
                    }, 800);
                } else {
                    alert(res.data && res.data.message ? res.data.message : 'خطا در ذخیره نتایج.');
                    isScanning = false;
                    $('#ipe-scan-modal').hide();
                }
            },
            error: function() {
                alert('خطا در نهایی‌سازی اسکن.');
                isScanning = false;
                $('#ipe-scan-modal').hide();
            }
        });
    }

    // Start / Restart Scan Button
    $('#ipe-start-scan-btn, #ipe-empty-scan-btn, #ipe-hero-scan-trigger').on('click', function(e) {
        if ($(e.target).closest('a, input').length && !$(this).is(e.target)) return;
        e.preventDefault();
        if (isScanning) return;

        isScanning = true;
        processedCount = 0;
        $('#ipe-scan-modal').fadeIn(200);
        updateProgress(0, 0, 'در حال آماده‌سازی و استخراج شناسه‌های محصولات ووکامرس...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ipe_init_scan',
                nonce: ipeData.nonce
            },
            success: function(res) {
                if (res.success) {
                    productIds = res.data.product_ids;
                    totalProducts = res.data.total;
                    batchSize = res.data.batch_size || batchSize;
                    updateProgress(0, totalProducts, 'شروع اسکن ناهمگام محصولات در دسته‌های ' + batchSize + ' تایی...');
                    processBatch(0);
                } else {
                    alert(res.data && res.data.message ? res.data.message : 'خطا در مقداردهی اسکن.');
                    isScanning = false;
                    $('#ipe-scan-modal').hide();
                }
            },
            error: function() {
                alert('خطای اتصال به سرور در گام اول.');
                isScanning = false;
                $('#ipe-scan-modal').hide();
            }
        });
    });

    // Reset Data Button
    $('#ipe-reset-data-btn, #ipe-side-reset-btn').on('click', function(e) {
        e.preventDefault();
        var confirmMsg = (typeof ipeData !== 'undefined' && ipeData.messages && ipeData.messages.confirm_reset) 
            ? ipeData.messages.confirm_reset 
            : 'آیا مطمئن هستید که می‌خواهید تمام داده‌های اسکن‌شده را بازنشانی و پاک کنید؟';

        if (!confirm(confirmMsg)) {
            return;
        }

        var $btn = $(this);
        $btn.addClass('disabled');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ipe_reset_data',
                nonce: ipeData.nonce
            },
            success: function(res) {
                window.location.reload();
            },
            error: function() {
                alert('خطا در بازنشانی داده‌ها.');
                $btn.removeClass('disabled');
            }
        });
    });
});
JAVASCRIPT;

        wp_add_inline_script('jquery', $custom_js);
    }

    /**
     * Render Admin Dashboard Screen with Nordic Teal & Crimson UI.
     */
    public static function render_dashboard(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('شما اجازه دسترسی به این بخش را ندارید.', 'image-plugin-extractor'));
        }

        $scan_data   = get_transient(IPE_RESULTS_TRANSIENT);
        $has_results = !empty($scan_data) && !empty($scan_data['items']);

        $export_csv_nonce = wp_create_nonce('ipe_export_nonce');
        $csv_url = add_query_arg([
            'action'   => 'ipe_export_csv',
            '_wpnonce' => $export_csv_nonce,
        ], admin_url('admin-post.php'));

        $json_url = add_query_arg([
            'action'   => 'ipe_export_json',
            '_wpnonce' => $export_csv_nonce,
        ], admin_url('admin-post.php'));

        // Query parameters
        $current_filter = isset($_GET['filter']) ? sanitize_key($_GET['filter']) : 'all';
        $search_query   = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $current_page   = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $per_page       = 25;

        // Process data if available
        $items               = $has_results ? (array) $scan_data['items'] : [];
        $total_scanned       = $has_results ? (int) $scan_data['total_products'] : 0;
        $total_opportunities = $has_results ? (int) $scan_data['opportunities_count'] : 0;
        $total_linked        = $has_results ? (int) $scan_data['linked_count'] : 0;
        $scanned_time        = $has_results && !empty($scan_data['scanned_at']) ? $scan_data['scanned_at'] : '—';

        // Filtering
        $filtered_items = [];
        foreach ($items as $item) {
            // Filter by opportunities
            if ($current_filter === 'opportunities' && empty($item['has_opportunity'])) {
                continue;
            }
            // Filter by linked only
            if ($current_filter === 'linked' && empty($item['linked_count'])) {
                continue;
            }
            // Search keyword
            if (!empty($search_query)) {
                if (mb_stripos($item['title'], $search_query) === false && mb_stripos($item['categories'], $search_query) === false) {
                    continue;
                }
            }
            $filtered_items[] = $item;
        }

        $total_filtered = count($filtered_items);
        $total_pages    = ceil($total_filtered / $per_page);
        $offset         = ($current_page - 1) * $per_page;
        $page_items     = array_slice($filtered_items, $offset, $per_page);

        ?>
        <div class="wrap ipe-dashboard-wrap">
            <!-- Dedicated Isolated Stylesheet (Nordic Teal & Crimson UI) -->
            <style id="ipe-nordic-teal-crimson-style">
                .ipe-dashboard-wrap {
                    background-color: #EAEAE8;
                    border-radius: 28px;
                    padding: 26px;
                    margin: 20px 20px 20px 0;
                    box-sizing: border-box;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'IRANSans', 'Vazirmatn', Tahoma, sans-serif;
                    color: #0E2931;
                    box-shadow: 0 15px 40px rgba(14, 41, 49, 0.06);
                }
                .ipe-dashboard-wrap * {
                    box-sizing: border-box;
                }
                .ipe-app-shell {
                    display: flex;
                    gap: 22px;
                    align-items: stretch;
                }
                /* 1. Left Vertical Capsule Sidebar */
                .ipe-sidebar-pill {
                    width: 66px;
                    background: #0E2931;
                    border-radius: 34px;
                    padding: 24px 0;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: space-between;
                    position: relative;
                    overflow: hidden;
                    box-shadow: 0 10px 25px rgba(14, 41, 49, 0.25);
                    flex-shrink: 0;
                }
                .ipe-sidebar-top-wave {
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    height: 90px;
                    background: radial-gradient(circle at 50% 0%, #1a565a 0%, #0E2931 75%);
                    opacity: 0.6;
                    pointer-events: none;
                }
                .ipe-sidebar-icons-top, .ipe-sidebar-icons-bottom {
                    display: flex;
                    flex-direction: column;
                    gap: 16px;
                    align-items: center;
                    position: relative;
                    z-index: 2;
                }
                .ipe-side-icon {
                    width: 42px;
                    height: 42px;
                    border-radius: 21px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: #A3B5B8;
                    text-decoration: none;
                    transition: all 0.2s ease;
                    position: relative;
                    background: transparent;
                    border: none;
                    cursor: pointer;
                }
                .ipe-side-icon .dashicons {
                    font-size: 19px;
                    width: 19px;
                    height: 19px;
                }
                .ipe-side-icon:hover {
                    color: #FFFFFF;
                    background: rgba(43, 117, 116, 0.25);
                }
                .ipe-side-icon.ipe-side-active {
                    background: #12484C;
                    color: #FFFFFF;
                    box-shadow: 0 4px 12px rgba(18, 72, 76, 0.4);
                }
                .ipe-side-icon.ipe-side-alert .ipe-side-badge {
                    position: absolute;
                    top: 8px;
                    right: 8px;
                    width: 7px;
                    height: 7px;
                    background: #861211;
                    border-radius: 50%;
                    border: 1px solid #0E2931;
                }
                .ipe-side-icon.ipe-side-reset:hover {
                    background: rgba(134, 18, 17, 0.3);
                    color: #ff9d9c;
                }

                /* 2. Main Content Stage */
                .ipe-main-stage {
                    flex: 1;
                    min-width: 0;
                }

                /* Top Navigation Bar */
                .ipe-top-navbar {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 22px;
                }
                .ipe-nav-title .ipe-kicker {
                    font-size: 11px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    color: #5A6B70;
                    font-weight: 700;
                    display: block;
                    margin-bottom: 2px;
                }
                .ipe-nav-title h2 {
                    margin: 0;
                    font-size: 22px;
                    font-weight: 800;
                    color: #0E2931;
                }
                .ipe-nav-title h2 span {
                    font-size: 13px;
                    font-weight: 500;
                    color: #2B7574;
                    background: rgba(43, 117, 116, 0.12);
                    padding: 3px 10px;
                    border-radius: 12px;
                    margin-right: 8px;
                }
                .ipe-nav-controls {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                }
                .ipe-search-pill-form {
                    position: relative;
                    width: 290px;
                }
                .ipe-search-pill-form .ipe-search-icon {
                    position: absolute;
                    right: 14px;
                    top: 50%;
                    transform: translateY(-50%);
                    color: #5A6B70;
                    font-size: 16px;
                }
                .ipe-search-input {
                    width: 100%;
                    height: 40px;
                    background: #FFFFFF !important;
                    border: 1px solid rgba(14, 41, 49, 0.08) !important;
                    border-radius: 20px !important;
                    padding: 0 40px 0 16px !important;
                    font-size: 12px !important;
                    color: #0E2931 !important;
                    box-shadow: 0 2px 8px rgba(14, 41, 49, 0.04);
                    outline: none;
                    transition: all 0.2s ease;
                }
                .ipe-search-input:focus {
                    border-color: #2B7574 !important;
                    box-shadow: 0 0 0 2px rgba(43, 117, 116, 0.15) !important;
                }
                .ipe-team-avatar {
                    width: 40px;
                    height: 40px;
                    border-radius: 20px;
                    background: #FFFFFF;
                    border: 1px solid rgba(14, 41, 49, 0.08);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: #12484C;
                    box-shadow: 0 2px 6px rgba(14, 41, 49, 0.05);
                }

                /* Hero Section Grid */
                .ipe-hero-grid {
                    display: grid;
                    grid-template-columns: 1fr 270px;
                    gap: 20px;
                    margin-bottom: 22px;
                }
                /* Hero Overview Card (Deep Pine Teal with Crimson Wave) */
                .ipe-hero-card {
                    background: linear-gradient(135deg, #0E2931 0%, #12484C 100%);
                    border-radius: 24px;
                    padding: 24px 28px;
                    position: relative;
                    overflow: hidden;
                    color: #FFFFFF;
                    box-shadow: 0 15px 35px rgba(14, 41, 49, 0.18);
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    min-height: 220px;
                }
                .ipe-hero-topography-bg {
                    position: absolute;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    height: 140px;
                    pointer-events: none;
                    z-index: 1;
                }
                .ipe-topo-svg {
                    width: 100%;
                    height: 100%;
                    display: block;
                }
                .ipe-hero-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    position: relative;
                    z-index: 2;
                }
                .ipe-hero-title-group .ipe-hero-label {
                    font-size: 17px;
                    font-weight: 700;
                    color: #FFFFFF;
                }
                .ipe-hero-title-group .ipe-hero-tag {
                    font-size: 11px;
                    color: #A3B5B8;
                    margin-right: 8px;
                    background: rgba(255, 255, 255, 0.1);
                    padding: 2px 8px;
                    border-radius: 8px;
                }
                .ipe-hero-filter-pill {
                    background: rgba(255, 255, 255, 0.12);
                    padding: 5px 12px;
                    border-radius: 14px;
                    font-size: 11px;
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    color: #E2E2E0;
                }
                .ipe-crimson-wave-wrap {
                    position: relative;
                    height: 75px;
                    margin: 8px 0;
                    z-index: 2;
                }
                .ipe-wave-svg {
                    width: 100%;
                    height: 100%;
                    overflow: visible;
                }
                .ipe-wave-tooltip {
                    position: absolute;
                    top: 0px;
                    right: 38%;
                    background: rgba(14, 41, 49, 0.95);
                    border: 1px solid rgba(134, 18, 17, 0.5);
                    color: #FFFFFF;
                    padding: 4px 10px;
                    border-radius: 10px;
                    font-size: 11px;
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
                }
                .ipe-wave-tooltip .ipe-tooltip-dot {
                    width: 6px;
                    height: 6px;
                    background: #ff5b5a;
                    border-radius: 50%;
                }
                .ipe-hero-metrics {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 12px;
                    position: relative;
                    z-index: 2;
                    border-top: 1px solid rgba(255, 255, 255, 0.1);
                    padding-top: 12px;
                }
                .ipe-metric-box .ipe-metric-label {
                    display: block;
                    font-size: 11px;
                    color: #A3B5B8;
                    margin-bottom: 2px;
                }
                .ipe-metric-box .ipe-metric-val {
                    font-size: 20px;
                    font-weight: 800;
                    color: #FFFFFF;
                }
                .ipe-metric-box .ipe-metric-sub {
                    font-size: 10px;
                    color: #7A9093;
                    margin-right: 4px;
                }
                .ipe-metric-box.ipe-metric-center {
                    text-align: center;
                    border-left: 1px solid rgba(255, 255, 255, 0.08);
                    border-right: 1px solid rgba(255, 255, 255, 0.08);
                }

                /* Right Companion Cards */
                .ipe-companion-col {
                    display: flex;
                    flex-direction: column;
                    gap: 16px;
                }
                .ipe-action-card-teal {
                    background: linear-gradient(135deg, #12484C 0%, #2B7574 100%);
                    border-radius: 20px;
                    padding: 16px 18px;
                    color: #FFFFFF;
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    box-shadow: 0 10px 24px rgba(18, 72, 76, 0.2);
                    cursor: pointer;
                    transition: transform 0.2s ease, box-shadow 0.2s ease;
                }
                .ipe-action-card-teal:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 14px 28px rgba(18, 72, 76, 0.25);
                }
                .ipe-card-squircle {
                    width: 36px;
                    height: 36px;
                    border-radius: 12px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: #FFFFFF;
                    margin-bottom: 8px;
                }
                .ipe-squircle-teal {
                    background: rgba(255, 255, 255, 0.2);
                }
                .ipe-squircle-crimson {
                    background: #861211;
                    box-shadow: 0 4px 12px rgba(134, 18, 17, 0.35);
                }
                .ipe-action-card-teal h4 {
                    margin: 0 0 4px 0;
                    font-size: 15px;
                    font-weight: 700;
                    color: #FFFFFF;
                }
                .ipe-action-card-teal p {
                    margin: 0 0 12px 0;
                    font-size: 11px;
                    color: #C7DEE0;
                    line-height: 1.4;
                }
                .ipe-pill-btn-teal {
                    background: #0E2931;
                    color: #FFFFFF;
                    border: none;
                    border-radius: 16px;
                    padding: 7px 14px;
                    font-size: 11px;
                    font-weight: 700;
                    cursor: pointer;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 6px;
                    width: fit-content;
                }

                .ipe-action-card-white {
                    background: #FFFFFF;
                    border-radius: 20px;
                    padding: 16px 18px;
                    border: 1px solid rgba(14, 41, 49, 0.08);
                    box-shadow: 0 10px 30px rgba(14, 41, 49, 0.05);
                }
                .ipe-action-card-header {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    margin-bottom: 12px;
                }
                .ipe-action-card-header h4 {
                    margin: 0;
                    font-size: 14px;
                    font-weight: 700;
                    color: #0E2931;
                }
                .ipe-action-card-header p {
                    margin: 2px 0 0 0;
                    font-size: 11px;
                    color: #5A6B70;
                }
                .ipe-export-buttons-group {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 8px;
                }
                .ipe-mini-btn {
                    padding: 6px 10px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 600;
                    text-decoration: none;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 4px;
                    transition: all 0.15s ease;
                }
                .ipe-btn-excel {
                    background: rgba(43, 117, 116, 0.1);
                    color: #2B7574;
                    border: 1px solid rgba(43, 117, 116, 0.25);
                }
                .ipe-btn-excel:hover {
                    background: #2B7574;
                    color: #FFFFFF;
                }
                .ipe-btn-json {
                    background: rgba(14, 41, 49, 0.06);
                    color: #0E2931;
                    border: 1px solid rgba(14, 41, 49, 0.15);
                }
                .ipe-btn-json:hover {
                    background: #0E2931;
                    color: #FFFFFF;
                }

                /* 3. Middle 3 Stat Cards (White cards with floating squircles) */
                .ipe-stat-cards-grid {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 20px;
                    margin-bottom: 22px;
                }
                .ipe-stat-card {
                    background: #FFFFFF;
                    border-radius: 20px;
                    padding: 24px 20px 18px 20px;
                    border: 1px solid rgba(14, 41, 49, 0.08);
                    box-shadow: 0 10px 30px rgba(14, 41, 49, 0.05);
                    position: relative;
                    text-align: center;
                    transition: transform 0.2s ease;
                }
                .ipe-stat-card:hover {
                    transform: translateY(-2px);
                }
                .ipe-squircle-floating {
                    width: 48px;
                    height: 48px;
                    border-radius: 16px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: #FFFFFF;
                    margin: -10px auto 14px auto;
                }
                .ipe-squircle-floating .dashicons {
                    font-size: 22px;
                    width: 22px;
                    height: 22px;
                }
                .ipe-squircle-crimson {
                    background: #861211;
                    box-shadow: 0 6px 18px rgba(134, 18, 17, 0.35);
                }
                .ipe-squircle-teal {
                    background: #2B7574;
                    box-shadow: 0 6px 18px rgba(43, 117, 116, 0.35);
                }
                .ipe-squircle-pine {
                    background: #0E2931;
                    box-shadow: 0 6px 18px rgba(14, 41, 49, 0.35);
                }
                .ipe-stat-card-title {
                    margin: 0 0 2px 0;
                    font-size: 15px;
                    font-weight: 700;
                    color: #0E2931;
                }
                .ipe-stat-card-sub {
                    margin: 0 0 12px 0;
                    font-size: 11px;
                    color: #5A6B70;
                }
                .ipe-stat-number {
                    font-size: 30px;
                    font-weight: 800;
                    line-height: 1;
                    margin-bottom: 14px;
                }
                .ipe-mini-track {
                    width: 100%;
                    height: 6px;
                    background: #EAEAE8;
                    border-radius: 3px;
                    overflow: hidden;
                    margin-bottom: 12px;
                }
                .ipe-mini-fill {
                    height: 100%;
                    border-radius: 3px;
                }
                .ipe-fill-crimson { background: #861211; }
                .ipe-fill-teal { background: #2B7574; }
                .ipe-fill-pine { background: #0E2931; }

                .ipe-stat-card-footer {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    font-size: 11px;
                }
                .ipe-footer-percent {
                    font-weight: 600;
                    color: #5A6B70;
                }
                .ipe-pill-badge {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 4px;
                    padding: 4px 10px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 700;
                    white-space: nowrap;
                    line-height: 1.3;
                }
                .ipe-badge-crimson {
                    background: rgba(134, 18, 17, 0.1);
                    color: #861211;
                    border: 1px solid rgba(134, 18, 17, 0.3);
                }
                .ipe-badge-teal {
                    background: rgba(43, 117, 116, 0.1);
                    color: #2B7574;
                    border: 1px solid rgba(43, 117, 116, 0.3);
                }
                .ipe-badge-pine {
                    background: rgba(14, 41, 49, 0.1);
                    color: #0E2931;
                    border: 1px solid rgba(14, 41, 49, 0.2);
                }

                /* Dedicated Status Column & Badges */
                .ipe-status-th,
                .ipe-status-cell {
                    width: 125px !important;
                    min-width: 125px !important;
                    text-align: center !important;
                    vertical-align: middle !important;
                    white-space: nowrap !important;
                }
                .ipe-status-tag {
                    display: inline-flex !important;
                    align-items: center !important;
                    justify-content: center !important;
                    gap: 5px !important;
                    padding: 5px 12px !important;
                    border-radius: 14px !important;
                    font-size: 11px !important;
                    font-weight: 700 !important;
                    white-space: nowrap !important;
                    line-height: 1.3 !important;
                    box-shadow: 0 2px 5px rgba(14, 41, 49, 0.04);
                }
                .ipe-status-tag .dashicons {
                    font-size: 13px !important;
                    width: 13px !important;
                    height: 13px !important;
                    line-height: 13px !important;
                    margin: 0 !important;
                    flex-shrink: 0;
                }
                .ipe-status-opportunity {
                    background: rgba(134, 18, 17, 0.12) !important;
                    color: #861211 !important;
                    border: 1px solid rgba(134, 18, 17, 0.35) !important;
                }
                .ipe-status-linked {
                    background: rgba(43, 117, 116, 0.12) !important;
                    color: #2B7574 !important;
                    border: 1px solid rgba(43, 117, 116, 0.35) !important;
                }
                .ipe-status-neutral {
                    background: rgba(90, 107, 112, 0.1) !important;
                    color: #5A6B70 !important;
                    border: 1px solid rgba(90, 107, 112, 0.25) !important;
                }

                /* 4. Filter Bar (Capsule toggle matching image) */
                .ipe-filter-bar-card {
                    background: #FFFFFF;
                    border-radius: 20px;
                    padding: 12px 18px;
                    border: 1px solid rgba(14, 41, 49, 0.08);
                    box-shadow: 0 10px 30px rgba(14, 41, 49, 0.04);
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 20px;
                }
                .ipe-capsule-tabs {
                    display: inline-flex;
                    background: #EAEAE8;
                    padding: 4px;
                    border-radius: 20px;
                    gap: 4px;
                }
                .ipe-capsule-tab {
                    padding: 6px 14px;
                    border-radius: 16px;
                    font-size: 12px;
                    font-weight: 600;
                    color: #5A6B70;
                    text-decoration: none;
                    transition: all 0.15s ease;
                }
                .ipe-capsule-tab.active {
                    background: #0E2931;
                    color: #FFFFFF;
                    box-shadow: 0 2px 8px rgba(14, 41, 49, 0.2);
                }
                .ipe-capsule-tab:hover:not(.active) {
                    color: #0E2931;
                }
                .ipe-danger-pill-btn {
                    background: transparent;
                    color: #861211;
                    border: 1px solid rgba(134, 18, 17, 0.3);
                    border-radius: 16px;
                    padding: 6px 14px;
                    font-size: 11px;
                    font-weight: 600;
                    cursor: pointer;
                    display: inline-flex;
                    align-items: center;
                    gap: 4px;
                    transition: all 0.15s ease;
                }
                .ipe-danger-pill-btn:hover {
                    background: #861211;
                    color: #FFFFFF;
                }

                /* 5. Data Table in Light Card */
                .ipe-table-card {
                    background: #FFFFFF;
                    border-radius: 20px;
                    border: 1px solid rgba(14, 41, 49, 0.08);
                    box-shadow: 0 10px 30px rgba(14, 41, 49, 0.05);
                    overflow: visible;
                }
                .ipe-data-table {
                    width: 100%;
                    border-collapse: separate;
                    border-spacing: 0;
                }
                .ipe-data-table th {
                    background: rgba(234, 234, 232, 0.5);
                    color: #5A6B70;
                    font-size: 11px;
                    text-transform: uppercase;
                    font-weight: 700;
                    padding: 14px 16px;
                    text-align: right;
                    border-bottom: 1px solid rgba(14, 41, 49, 0.06);
                }
                .ipe-data-table td {
                    padding: 14px 16px;
                    font-size: 12px;
                    color: #0E2931;
                    border-bottom: 1px solid rgba(14, 41, 49, 0.04);
                    vertical-align: middle;
                }
                .ipe-data-table tr:last-child td {
                    border-bottom: none;
                }

                /* Thumbnail 36x36 with Hover Zoom */
                .ipe-thumb-wrap {
                    position: relative;
                    width: 36px;
                    height: 36px;
                    display: inline-block;
                }
                .ipe-thumb {
                    width: 36px;
                    height: 36px;
                    object-fit: cover;
                    border-radius: 10px;
                    border: 1px solid rgba(14, 41, 49, 0.1);
                    transition: transform 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275), box-shadow 0.25s ease;
                    display: block;
                    cursor: pointer;
                }
                .ipe-thumb-wrap:hover .ipe-thumb {
                    transform: scale(4.2);
                    transform-origin: center right;
                    box-shadow: 0 10px 28px rgba(14, 41, 49, 0.4);
                    z-index: 99999;
                    position: relative;
                    border: 2px solid #2B7574;
                    background: #FFFFFF;
                }

                .ipe-product-title-link {
                    font-size: 13px;
                    font-weight: 700;
                    color: #0E2931;
                    text-decoration: none;
                    display: block;
                    margin-bottom: 2px;
                }
                .ipe-product-title-link:hover {
                    color: #2B7574;
                }
                .ipe-product-actions {
                    font-size: 11px;
                    color: #5A6B70;
                }
                .ipe-product-actions a {
                    color: #2B7574;
                    text-decoration: none;
                }

                /* Unlinked Mentions & Gutenberg Button */
                .ipe-posts-list {
                    margin: 8px 0 0 0;
                    padding: 8px 12px;
                    background: #F8F9F9;
                    border-radius: 12px;
                    border: 1px solid rgba(14, 41, 49, 0.06);
                    list-style: none;
                    max-height: 180px;
                    overflow-y: auto;
                }
                .ipe-post-item {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 10px;
                    padding: 6px 0;
                    border-bottom: 1px dashed rgba(14, 41, 49, 0.08);
                }
                .ipe-post-item:last-child {
                    border-bottom: none;
                }
                .ipe-post-link {
                    color: #0E2931;
                    font-weight: 600;
                    text-decoration: none;
                }
                .ipe-post-link:hover {
                    color: #2B7574;
                }

                /* Context Snippet & Anchor Text Styles */
                .ipe-post-snippet {
                    margin-top: 6px;
                    padding: 6px 10px;
                    border-radius: 8px;
                    font-size: 11px;
                    line-height: 1.6;
                    display: flex;
                    align-items: flex-start;
                    gap: 6px;
                    white-space: normal;
                    text-align: right;
                    direction: rtl;
                }
                .ipe-snippet-linked {
                    background: #FFFFFF;
                    border: 1px solid rgba(43, 117, 116, 0.2);
                    color: #12484C;
                }
                .ipe-snippet-opportunity {
                    background: #FFF9F9;
                    border: 1px solid rgba(134, 18, 17, 0.15);
                    color: #4A1A1A;
                }
                .ipe-snippet-anchor {
                    font-size: 10px;
                    color: #2B7574;
                    background: rgba(43, 117, 116, 0.1);
                    padding: 2px 8px;
                    border-radius: 6px;
                    display: inline-flex;
                    align-items: center;
                    gap: 4px;
                    font-weight: 600;
                }
                .ipe-snippet-badge-term {
                    font-size: 10px;
                    color: #861211;
                    background: rgba(134, 18, 17, 0.08);
                    border: 1px solid rgba(134, 18, 17, 0.2);
                    padding: 2px 8px;
                    border-radius: 6px;
                    font-weight: 600;
                    display: inline-flex;
                    align-items: center;
                }
                .ipe-download-link {
                    color: #861211 !important;
                    font-weight: 600;
                    text-decoration: none;
                }
                .ipe-download-link:hover {
                    text-decoration: underline;
                }

                /* Crimson Red Capsule Button for Gutenberg Direct Edit */
                .ipe-btn-gutenberg {
                    white-space: nowrap;
                    display: inline-flex !important;
                    align-items: center;
                    gap: 4px;
                    font-size: 11px !important;
                    height: 24px !important;
                    line-height: 22px !important;
                    padding: 0 10px !important;
                    color: #FFFFFF !important;
                    background: #861211 !important;
                    border: none !important;
                    border-radius: 12px !important;
                    text-decoration: none !important;
                    font-weight: 700 !important;
                    transition: all 0.15s ease !important;
                    box-shadow: 0 3px 8px rgba(134, 18, 17, 0.3) !important;
                }
                .ipe-btn-gutenberg:hover {
                    background: #a31817 !important;
                    transform: translateY(-1px);
                    box-shadow: 0 5px 12px rgba(134, 18, 17, 0.4) !important;
                    color: #FFFFFF !important;
                }
                .ipe-btn-gutenberg .dashicons {
                    font-size: 13px;
                    width: 13px;
                    height: 13px;
                    line-height: 13px;
                }

                .ipe-btn-edit-plain {
                    white-space: nowrap;
                    display: inline-flex !important;
                    align-items: center;
                    gap: 3px;
                    font-size: 11px !important;
                    height: 24px !important;
                    line-height: 22px !important;
                    padding: 0 8px !important;
                    color: #0E2931 !important;
                    background: #EAEAE8 !important;
                    border: 1px solid rgba(14, 41, 49, 0.12) !important;
                    border-radius: 12px !important;
                    text-decoration: none !important;
                    font-weight: 600;
                }
                .ipe-btn-edit-plain:hover {
                    background: #d8d8d5 !important;
                }

                /* Pagination */
                .ipe-pagination-wrap {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 16px 20px;
                    border-top: 1px solid rgba(14, 41, 49, 0.06);
                    font-size: 12px;
                    color: #5A6B70;
                }
                .ipe-pagination-pages a, .ipe-pagination-pages span {
                    display: inline-block;
                    padding: 4px 10px;
                    margin: 0 2px;
                    border-radius: 10px;
                    text-decoration: none;
                    color: #0E2931;
                    font-weight: 600;
                }
                .ipe-pagination-pages span.current {
                    background: #0E2931;
                    color: #FFFFFF;
                }
                .ipe-pagination-pages a:hover {
                    background: #EAEAE8;
                }

                /* Progress Modal */
                .ipe-modal-backdrop {
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(14, 41, 49, 0.7);
                    backdrop-filter: blur(5px);
                    z-index: 100000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .ipe-modal-box {
                    background: #0E2931;
                    width: 90%;
                    max-width: 500px;
                    padding: 32px;
                    border-radius: 24px;
                    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4);
                    text-align: center;
                    color: #FFFFFF;
                    position: relative;
                    overflow: hidden;
                    border: 1px solid rgba(255, 255, 255, 0.1);
                }
                .ipe-modal-box h3 {
                    margin: 0 0 8px 0;
                    font-size: 18px;
                    font-weight: 800;
                    color: #FFFFFF;
                }
                .ipe-modal-box p {
                    margin: 0 0 24px 0;
                    font-size: 12px;
                    color: #A3B5B8;
                }
                .ipe-progress-track {
                    width: 100%;
                    height: 14px;
                    background: rgba(255, 255, 255, 0.12);
                    border-radius: 8px;
                    overflow: hidden;
                    margin-bottom: 12px;
                }
                .ipe-progress-fill {
                    height: 100%;
                    width: 0%;
                    background: linear-gradient(90deg, #2B7574, #861211);
                    transition: width 0.25s ease;
                    border-radius: 8px;
                }
                .ipe-progress-info {
                    display: flex;
                    justify-content: space-between;
                    font-size: 12px;
                    font-weight: 700;
                    color: #E2E2E0;
                }

                /* Empty state */
                .ipe-empty-state {
                    text-align: center;
                    padding: 60px 20px;
                    background: #FFFFFF;
                    border-radius: 20px;
                    border: 2px dashed rgba(14, 41, 49, 0.12);
                }
                .ipe-empty-state .dashicons {
                    font-size: 48px;
                    width: 48px;
                    height: 48px;
                    color: #2B7574;
                    margin-bottom: 12px;
                }
                .ipe-empty-state h3 {
                    font-size: 18px;
                    font-weight: 800;
                    margin: 0 0 6px 0;
                }
                .ipe-empty-state p {
                    color: #5A6B70;
                    margin: 0 0 20px 0;
                    font-size: 13px;
                }
            </style>

            <div class="ipe-app-shell">
                <!-- 1. Left Vertical Capsule Sidebar (matching reference image) -->
                <aside class="ipe-sidebar-pill">
                    <div class="ipe-sidebar-top-wave"></div>
                    <div class="ipe-sidebar-icons-top">
                        <a href="#" class="ipe-side-icon ipe-side-alert" title="<?php esc_attr_e('اعلانات و وضعیت سئو', 'image-plugin-extractor'); ?>">
                            <span class="dashicons dashicons-bell"></span>
                            <span class="ipe-side-badge"></span>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=image-plugin-extractor')); ?>" class="ipe-side-icon ipe-side-active" title="<?php esc_attr_e('داشبورد اصلی', 'image-plugin-extractor'); ?>">
                            <span class="dashicons dashicons-dashboard"></span>
                        </a>
                        <a href="<?php echo esc_url(add_query_arg(['filter' => 'opportunities', 'paged' => 1])); ?>" class="ipe-side-icon" title="<?php esc_attr_e('فرصت‌های لینک‌سازی', 'image-plugin-extractor'); ?>">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </a>
                        <a href="<?php echo esc_url(add_query_arg(['filter' => 'linked', 'paged' => 1])); ?>" class="ipe-side-icon" title="<?php esc_attr_e('پیوندهای فعال', 'image-plugin-extractor'); ?>">
                            <span class="dashicons dashicons-admin-links"></span>
                        </a>
                        <a href="#ipe-data-section" class="ipe-side-icon" title="<?php esc_attr_e('جدول محصولات', 'image-plugin-extractor'); ?>">
                            <span class="dashicons dashicons-editor-table"></span>
                        </a>
                        <a href="https://telescope.ir" target="_blank" class="ipe-side-icon" title="<?php esc_attr_e('مستندات و پشتیبانی', 'image-plugin-extractor'); ?>">
                            <span class="dashicons dashicons-book-alt"></span>
                        </a>
                    </div>
                    <div class="ipe-sidebar-icons-bottom">
                        <button id="ipe-side-reset-btn" class="ipe-side-icon ipe-side-reset" title="<?php esc_attr_e('بازنشانی داده‌ها', 'image-plugin-extractor'); ?>">
                            <span class="dashicons dashicons-update"></span>
                        </button>
                    </div>
                </aside>

                <!-- 2. Main Content Stage -->
                <main class="ipe-main-stage">
                    <!-- Top Navigation Bar -->
                    <header class="ipe-top-navbar">
                        <div class="ipe-nav-title">
                            <span class="ipe-kicker">Catalog & SEO Audit</span>
                            <h2>پیشخوان سئو کاتالوگ <span>Image Plugin Extractor v<?php echo esc_html(IPE_VERSION); ?></span></h2>
                        </div>
                        <div class="ipe-nav-controls">
                            <!-- Search Input Pill -->
                            <form method="get" class="ipe-search-pill-form">
                                <input type="hidden" name="page" value="image-plugin-extractor" />
                                <?php if ($current_filter !== 'all') : ?>
                                    <input type="hidden" name="filter" value="<?php echo esc_attr($current_filter); ?>" />
                                <?php endif; ?>
                                <span class="dashicons dashicons-search ipe-search-icon"></span>
                                <input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="<?php esc_attr_e('جستجوی محصولات یا دسته‌ها...', 'image-plugin-extractor'); ?>" class="ipe-search-input" />
                            </form>
                            <div class="ipe-team-avatar" title="Content Forge Team">
                                <span class="dashicons dashicons-admin-users"></span>
                            </div>
                        </div>
                    </header>

                    <?php if ($has_results) : ?>
                        <!-- Hero Section Grid -->
                        <div class="ipe-hero-grid">
                            <!-- Large Hero Overview Card (Deep Pine Teal with Crimson Wave) -->
                            <div class="ipe-hero-card">
                                <div class="ipe-hero-topography-bg">
                                    <svg viewBox="0 0 700 240" preserveAspectRatio="none" class="ipe-topo-svg">
                                        <path d="M0,150 C150,190 350,110 500,165 C620,195 700,150 700,150 L700,240 L0,240 Z" fill="#12484C" opacity="0.65"/>
                                        <path d="M0,185 C180,225 320,150 480,195 C600,230 700,195 700,195 L700,240 L0,240 Z" fill="#0A1E24" opacity="0.9"/>
                                    </svg>
                                </div>

                                <div class="ipe-hero-header">
                                    <div class="ipe-hero-title-group">
                                        <span class="ipe-hero-label">Overview</span>
                                        <span class="ipe-hero-tag">اسکن ناهمگام ۲۵ تایی</span>
                                    </div>
                                    <div class="ipe-hero-filter-pill">
                                        <span>کاتالوگ ۱۰۰۰+ محصول</span>
                                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                                    </div>
                                </div>

                                <!-- Crimson Wave Trend Line -->
                                <div class="ipe-crimson-wave-wrap">
                                    <svg viewBox="0 0 650 85" class="ipe-wave-svg">
                                        <path d="M 10,48 Q 90,75 160,52 T 290,32 T 410,16 T 530,46 T 640,28" fill="none" stroke="#861211" stroke-width="4.5" stroke-linecap="round" />
                                        <circle cx="290" cy="32" r="5" fill="#FFFFFF" stroke="#861211" stroke-width="3" />
                                        <circle cx="410" cy="16" r="8" fill="#861211" stroke="#FFFFFF" stroke-width="3" />
                                        <circle cx="530" cy="46" r="5" fill="#FFFFFF" stroke="#861211" stroke-width="3" />
                                    </svg>
                                    <div class="ipe-wave-tooltip">
                                        <span class="ipe-tooltip-dot"></span>
                                        <strong><?php echo esc_html(number_format_i18n($total_scanned)); ?></strong>
                                        <span>محصول اسکن‌شده</span>
                                    </div>
                                </div>

                                <!-- Hero Stats Footer -->
                                <div class="ipe-hero-metrics">
                                    <div class="ipe-metric-box">
                                        <span class="ipe-metric-label">کل محصولات کاتالوگ</span>
                                        <strong class="ipe-metric-val"><?php echo esc_html(number_format_i18n($total_scanned)); ?></strong>
                                        <span class="ipe-metric-sub">ووکامرس</span>
                                    </div>
                                    <div class="ipe-metric-box ipe-metric-center">
                                        <span class="ipe-metric-label">پوشش لینک داخلی</span>
                                        <strong class="ipe-metric-val"><?php echo $total_scanned > 0 ? round(($total_linked / $total_scanned) * 100) : 0; ?>%</strong>
                                        <span class="ipe-metric-sub">محصولات متصل</span>
                                    </div>
                                    <div class="ipe-metric-box">
                                        <span class="ipe-metric-label">فرصت‌های طلایی سئو</span>
                                        <strong class="ipe-metric-val" style="color: #ff9d9c;"><?php echo esc_html(number_format_i18n($total_opportunities)); ?></strong>
                                        <span class="ipe-metric-sub">آماده پیوند</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Right Companion Action Cards (from image) -->
                            <div class="ipe-companion-col">
                                <!-- Quick Scan Card -->
                                <div class="ipe-action-card-teal" id="ipe-hero-scan-trigger">
                                    <div class="ipe-card-squircle ipe-squircle-teal">
                                        <span class="dashicons dashicons-update"></span>
                                    </div>
                                    <div>
                                        <h4>اسکن زنده کاتالوگ</h4>
                                        <p>پردازش ناهمگام ۲۵ محصول در هر چرخه با کنترل رم</p>
                                    </div>
                                    <button id="ipe-start-scan-btn" class="ipe-pill-btn-teal">
                                        <?php echo esc_html__('اسکن مجدد', 'image-plugin-extractor'); ?>
                                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                                    </button>
                                </div>

                                <!-- Export Card -->
                                <div class="ipe-action-card-white">
                                    <div class="ipe-action-card-header">
                                        <div class="ipe-card-squircle ipe-squircle-crimson">
                                            <span class="dashicons dashicons-download"></span>
                                        </div>
                                        <div>
                                            <h4>خروجی و گزارش‌ها</h4>
                                            <p>اکسل فارسی (BOM) و هوش مصنوعی</p>
                                        </div>
                                    </div>
                                    <div class="ipe-export-buttons-group">
                                        <a href="<?php echo esc_url($csv_url); ?>" class="ipe-mini-btn ipe-btn-excel">
                                            <span class="dashicons dashicons-media-spreadsheet"></span>
                                            اکسل (CSV)
                                        </a>
                                        <a href="<?php echo esc_url($json_url); ?>" class="ipe-mini-btn ipe-btn-json">
                                            <span class="dashicons dashicons-media-code"></span>
                                            AI (JSON)
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Middle 3 Stat Cards (White cards with floating squircles) -->
                        <div class="ipe-stat-cards-grid">
                            <!-- Card 1: فرصت‌های طلایی (Crimson) -->
                            <div class="ipe-stat-card">
                                <div class="ipe-squircle-floating ipe-squircle-crimson">
                                    <span class="dashicons dashicons-lightbulb"></span>
                                </div>
                                <h3 class="ipe-stat-card-title">فرصت‌های طلایی لینک‌سازی</h3>
                                <p class="ipe-stat-card-sub">ذکر نام یا برچسب بدون لینک در مقالات</p>
                                <div class="ipe-stat-number" style="color: #861211;">
                                    <?php echo esc_html(number_format_i18n($total_opportunities)); ?>
                                </div>
                                <div class="ipe-mini-track">
                                    <div class="ipe-mini-fill ipe-fill-crimson" style="width: <?php echo $total_scanned > 0 ? min(100, round(($total_opportunities / $total_scanned) * 100)) : 0; ?>%;"></div>
                                </div>
                                <div class="ipe-stat-card-footer">
                                    <span class="ipe-footer-percent"><?php echo $total_scanned > 0 ? round(($total_opportunities / $total_scanned) * 100) : 0; ?>% محصولات</span>
                                    <span class="ipe-pill-badge ipe-badge-crimson">حداکثر ۱ فرصت در هر مقاله</span>
                                </div>
                            </div>

                            <!-- Card 2: لینک‌های برقرار شده (Muted Teal) -->
                            <div class="ipe-stat-card">
                                <div class="ipe-squircle-floating ipe-squircle-teal">
                                    <span class="dashicons dashicons-admin-links"></span>
                                </div>
                                <h3 class="ipe-stat-card-title">لینک‌های برقرار شده</h3>
                                <p class="ipe-stat-card-sub">محصولات متصل با پیوند داخلی فعال</p>
                                <div class="ipe-stat-number" style="color: #2B7574;">
                                    <?php echo esc_html(number_format_i18n($total_linked)); ?>
                                </div>
                                <div class="ipe-mini-track">
                                    <div class="ipe-mini-fill ipe-fill-teal" style="width: <?php echo $total_scanned > 0 ? min(100, round(($total_linked / $total_scanned) * 100)) : 0; ?>%;"></div>
                                </div>
                                <div class="ipe-stat-card-footer">
                                    <span class="ipe-footer-percent"><?php echo $total_scanned > 0 ? round(($total_linked / $total_scanned) * 100) : 0; ?>% پوشش</span>
                                    <span class="ipe-pill-badge ipe-badge-teal">سئو بهینه</span>
                                </div>
                            </div>

                            <!-- Card 3: محصولات کاتالوگ (Deep Pine) -->
                            <div class="ipe-stat-card">
                                <div class="ipe-squircle-floating ipe-squircle-pine">
                                    <span class="dashicons dashicons-cart"></span>
                                </div>
                                <h3 class="ipe-stat-card-title">کل محصولات کاتالوگ</h3>
                                <p class="ipe-stat-card-sub">استخراج کاتالوگ و تصاویر شاخص</p>
                                <div class="ipe-stat-number" style="color: #0E2931;">
                                    <?php echo esc_html(number_format_i18n($total_scanned)); ?>
                                </div>
                                <div class="ipe-mini-track">
                                    <div class="ipe-mini-fill ipe-fill-pine" style="width: 100%;"></div>
                                </div>
                                <div class="ipe-stat-card-footer">
                                    <span class="ipe-footer-percent"><?php echo esc_html($scanned_time); ?></span>
                                    <span class="ipe-pill-badge ipe-badge-pine">اعتبار کش: ۷ روز</span>
                                </div>
                            </div>
                        </div>

                        <!-- 4. Filter Bar (Capsule toggle matching image) -->
                        <div class="ipe-filter-bar-card" id="ipe-data-section">
                            <div class="ipe-capsule-tabs">
                                <a href="<?php echo esc_url(add_query_arg(['filter' => 'all', 'paged' => 1])); ?>" class="ipe-capsule-tab <?php echo $current_filter === 'all' ? 'active' : ''; ?>">
                                    همه محصولات (<?php echo esc_html($total_scanned); ?>)
                                </a>
                                <a href="<?php echo esc_url(add_query_arg(['filter' => 'opportunities', 'paged' => 1])); ?>" class="ipe-capsule-tab <?php echo $current_filter === 'opportunities' ? 'active' : ''; ?>">
                                    فقط فرصت‌های سئو (<?php echo esc_html($total_opportunities); ?>)
                                </a>
                                <a href="<?php echo esc_url(add_query_arg(['filter' => 'linked', 'paged' => 1])); ?>" class="ipe-capsule-tab <?php echo $current_filter === 'linked' ? 'active' : ''; ?>">
                                    لینک‌های فعال (<?php echo esc_html($total_linked); ?>)
                                </a>
                            </div>
                            <div class="ipe-filter-bar-right">
                                <button id="ipe-reset-data-btn" class="ipe-danger-pill-btn">
                                    <span class="dashicons dashicons-trash"></span>
                                    بازنشانی داده‌ها
                                </button>
                            </div>
                        </div>

                        <!-- 5. Data Table in Light Card -->
                        <div class="ipe-table-card">
                            <table class="ipe-data-table">
                                <thead>
                                    <tr>
                                        <th style="width: 58px; text-align: center;">تصویر</th>
                                        <th style="width: 24%;">نام محصول</th>
                                        <th style="width: 16%;">دسته‌بندی</th>
                                        <th style="width: 22%;">مقالات لینک‌دار</th>
                                        <th style="width: 26%;">فرصت‌های سئو (متن بدون لینک)</th>
                                        <th class="ipe-status-th">وضعیت</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($page_items)) : ?>
                                        <?php foreach ($page_items as $item) : ?>
                                            <tr>
                                                <!-- Thumbnail 36x36 with Hover Zoom -->
                                                <td style="text-align: center; vertical-align: middle;">
                                                    <div class="ipe-thumb-wrap">
                                                        <img src="<?php echo esc_url($item['thumb_url']); ?>" class="ipe-thumb" alt="<?php echo esc_attr($item['title']); ?>" />
                                                    </div>
                                                </td>

                                                <!-- Product Title -->
                                                <td>
                                                    <a href="<?php echo esc_url($item['permalink']); ?>" target="_blank" class="ipe-product-title-link">
                                                        <?php echo esc_html($item['title']); ?>
                                                    </a>
                                                    <div class="ipe-product-actions">
                                                        <a href="<?php echo esc_url($item['permalink']); ?>" target="_blank">مشاهده محصول</a> |
                                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $item['id'] . '&action=edit')); ?>" target="_blank">ویرایش</a> |
                                                        <a href="<?php echo esc_url(!empty($item['full_image_url']) ? $item['full_image_url'] : $item['thumb_url']); ?>" download class="ipe-download-link" title="<?php esc_attr_e('دانلود تصویر با کیفیت اصلی', 'image-plugin-extractor'); ?>" target="_blank">
                                                            <span class="dashicons dashicons-download" style="font-size: 11px; width: 11px; height: 11px; vertical-align: middle;"></span> دانلود عکس Full
                                                        </a>
                                                    </div>
                                                </td>

                                                <!-- Categories -->
                                                <td style="color: #5A6B70;">
                                                    <?php echo esc_html($item['categories']); ?>
                                                </td>

                                                <!-- Linked Posts -->
                                                <td>
                                                    <?php if (!empty($item['linked_count']) && $item['linked_count'] > 0) : ?>
                                                        <span class="ipe-pill-badge ipe-badge-teal">
                                                            <span class="dashicons dashicons-yes" style="font-size: 13px; width: 13px; height: 13px;"></span>
                                                            <?php printf(esc_html__('%d مقاله متصل', 'image-plugin-extractor'), $item['linked_count']); ?>
                                                        </span>
                                                        <details class="ipe-posts-details" style="margin-top: 4px;">
                                                            <summary style="font-size: 11px; color: #2B7574; cursor: pointer;"><?php echo esc_html__('مشاهده لیست مقالات و انکرتکست‌ها', 'image-plugin-extractor'); ?></summary>
                                                            <ul class="ipe-posts-list">
                                                                <?php foreach ($item['linked_posts'] as $lp) : ?>
                                                                    <li class="ipe-post-item" style="flex-direction: column; align-items: stretch;">
                                                                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                                                                            <div style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                                                <a href="<?php echo esc_url($lp['url']); ?>" target="_blank" class="ipe-post-link"><?php echo esc_html($lp['title']); ?></a>
                                                                            </div>
                                                                            <a href="<?php echo esc_url($lp['edit_url']); ?>" target="_blank" class="ipe-btn-edit-plain">
                                                                                <span class="dashicons dashicons-edit"></span>
                                                                                ویرایش
                                                                            </a>
                                                                        </div>

                                                                        <?php if (!empty($lp['anchor'])) : ?>
                                                                            <div style="margin-top: 4px;">
                                                                                <span class="ipe-snippet-anchor">
                                                                                    <span class="dashicons dashicons-admin-links" style="font-size: 11px; width: 11px; height: 11px;"></span>
                                                                                    <strong><?php echo esc_html__('انکرتکست:', 'image-plugin-extractor'); ?></strong> «<?php echo esc_html($lp['anchor']); ?>»
                                                                                </span>
                                                                            </div>
                                                                        <?php endif; ?>

                                                                        <?php if (!empty($lp['snippet'])) : ?>
                                                                            <div class="ipe-post-snippet ipe-snippet-linked">
                                                                                <span class="dashicons dashicons-location" style="font-size: 12px; width: 12px; height: 12px; flex-shrink: 0; color: #2B7574;"></span>
                                                                                <span class="ipe-snippet-text"><?php echo esc_html($lp['snippet']); ?></span>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </details>
                                                    <?php else : ?>
                                                        <span style="font-size: 11px; color: #A3B5B8;">بدون پیوند</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Unlinked Mentions (SEO Opportunities) with Crimson Gutenberg Button -->
                                                <td>
                                                    <?php if (!empty($item['unlinked_count']) && $item['unlinked_count'] > 0) : ?>
                                                        <span class="ipe-pill-badge ipe-badge-crimson">
                                                            <span class="dashicons dashicons-lightbulb" style="font-size: 13px; width: 13px; height: 13px;"></span>
                                                            <?php printf(esc_html__('%d فرصت سئو', 'image-plugin-extractor'), $item['unlinked_count']); ?>
                                                        </span>
                                                        <details class="ipe-posts-details" open style="margin-top: 4px;">
                                                            <summary style="font-size: 11px; color: #861211; font-weight: 700; cursor: pointer;"><?php echo esc_html__('مشاهده موقعیت متن و درج لینک', 'image-plugin-extractor'); ?></summary>
                                                            <ul class="ipe-posts-list">
                                                                <?php foreach ($item['unlinked_posts'] as $up) : ?>
                                                                    <li class="ipe-post-item" style="flex-direction: column; align-items: stretch;">
                                                                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                                                                            <div style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                                                <a href="<?php echo esc_url($up['url']); ?>" target="_blank" class="ipe-post-link"><?php echo esc_html($up['title']); ?></a>
                                                                            </div>
                                                                            <a href="<?php echo esc_url($up['edit_url']); ?>" target="_blank" class="ipe-btn-gutenberg" title="<?php esc_attr_e('ویرایش مستقیم این مقاله در گوتنبرگ برای افزودن لینک داخلی', 'image-plugin-extractor'); ?>">
                                                                                <span class="dashicons dashicons-edit"></span>
                                                                                ویرایش در گوتنبرگ
                                                                            </a>
                                                                        </div>

                                                                        <?php if (!empty($up['matched_term'])) : ?>
                                                                            <div style="margin-top: 4px;">
                                                                                <span class="ipe-snippet-badge-term">
                                                                                    <?php echo esc_html($up['matched_term']); ?>
                                                                                </span>
                                                                            </div>
                                                                        <?php endif; ?>

                                                                        <?php if (!empty($up['snippet'])) : ?>
                                                                            <div class="ipe-post-snippet ipe-snippet-opportunity">
                                                                                <span class="dashicons dashicons-location" style="font-size: 12px; width: 12px; height: 12px; flex-shrink: 0; color: #861211;"></span>
                                                                                <span class="ipe-snippet-text"><?php echo esc_html($up['snippet']); ?></span>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </details>
                                                    <?php else : ?>
                                                        <span style="font-size: 11px; color: #A3B5B8;">اشاره‌ای یافت نشد</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Status Badge -->
                                                <td class="ipe-status-cell">
                                                    <?php if (!empty($item['unlinked_count']) && $item['unlinked_count'] > 0) : ?>
                                                        <span class="ipe-status-tag ipe-status-opportunity">
                                                            <span class="dashicons dashicons-lightbulb"></span>
                                                            <?php echo esc_html__('فرصت سئو', 'image-plugin-extractor'); ?>
                                                        </span>
                                                    <?php elseif (!empty($item['linked_count']) && $item['linked_count'] > 0) : ?>
                                                        <span class="ipe-status-tag ipe-status-linked">
                                                            <span class="dashicons dashicons-yes-alt"></span>
                                                            <?php echo esc_html__('لینک فعال', 'image-plugin-extractor'); ?>
                                                        </span>
                                                    <?php else : ?>
                                                        <span class="ipe-status-tag ipe-status-neutral">
                                                            <span class="dashicons dashicons-minus"></span>
                                                            <?php echo esc_html__('بدون اشاره', 'image-plugin-extractor'); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <tr>
                                            <td colspan="6" style="text-align: center; padding: 36px; color: #5A6B70;">
                                                <?php echo esc_html__('هیچ موردی مطابق فیلتر یا جستجوی شما یافت نشد.', 'image-plugin-extractor'); ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1) : ?>
                                <div class="ipe-pagination-wrap">
                                    <div>
                                        <?php printf(esc_html__('نمایش %d تا %d از %d محصول', 'image-plugin-extractor'), $offset + 1, min($offset + $per_page, $total_filtered), $total_filtered); ?>
                                    </div>
                                    <div class="ipe-pagination-pages">
                                        <?php
                                        $page_links = paginate_links([
                                            'base'      => add_query_arg('paged', '%#%'),
                                            'format'    => '',
                                            'prev_text' => '&laquo; ' . esc_html__('قبلی', 'image-plugin-extractor'),
                                            'next_text' => esc_html__('بعدی', 'image-plugin-extractor') . ' &raquo;',
                                            'total'     => $total_pages,
                                            'current'   => $current_page,
                                        ]);
                                        echo wp_kses_post($page_links);
                                        ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                    <?php else : ?>
                        <!-- Empty State -->
                        <div class="ipe-empty-state">
                            <span class="dashicons dashicons-search"></span>
                            <h3><?php echo esc_html__('هنوز اسکن کاتالوگ انجام نشده است', 'image-plugin-extractor'); ?></h3>
                            <p><?php echo esc_html__('برای استخراج تصاویر و شناسایی فرصت‌های لینک‌سازی داخلی بین محصولات و مقالات، اسکن هوشمند را شروع کنید.', 'image-plugin-extractor'); ?></p>
                            <button id="ipe-empty-scan-btn" class="ipe-pill-btn-teal" style="font-size: 13px; padding: 10px 24px; border-radius: 20px;">
                                <span class="dashicons dashicons-update"></span>
                                <?php echo esc_html__('شروع اسکن محصولات ووکامرس', 'image-plugin-extractor'); ?>
                            </button>
                        </div>
                    <?php endif; ?>

                    <!-- Progress Modal -->
                    <div id="ipe-scan-modal" class="ipe-modal-backdrop" style="display: none;">
                        <div class="ipe-modal-box">
                            <h3><?php echo esc_html__('در حال پردازش دسته‌ای و تحلیل سئو', 'image-plugin-extractor'); ?></h3>
                            <p><?php echo esc_html__('سیستم در حال اسکن ناهمگام ۲۵ محصول در هر چرخه است. لطفاً پنجره مرورگر را نبندید.', 'image-plugin-extractor'); ?></p>
                            <div class="ipe-progress-track">
                                <div id="ipe-progress-fill" class="ipe-progress-fill"></div>
                            </div>
                            <div class="ipe-progress-info">
                                <span id="ipe-progress-status"><?php echo esc_html__('در حال استخراج شناسه‌ها...', 'image-plugin-extractor'); ?></span>
                                <span id="ipe-progress-percentage">0%</span>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
        </div>
        <?php
    }
}

/**
 * Class IPE_Exporter
 * Handles Streaming CSV Export (with UTF-8 BOM for Microsoft Excel) and Structured JSON Export.
 */
class IPE_Exporter {

    /**
     * Export Scanned Data to CSV for Excel.
     */
    public static function export_csv(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('شما اجازه دسترسی به این بخش را ندارید.', 'image-plugin-extractor'));
        }

        check_admin_referer('ipe_export_nonce', '_wpnonce');

        $data = get_transient(IPE_RESULTS_TRANSIENT);
        if (empty($data) || empty($data['items'])) {
            wp_die(esc_html__('هیچ داده‌ای برای خروجی وجود ندارد. لطفاً ابتدا اسکن را انجام دهید.', 'image-plugin-extractor'));
        }

        $filename = 'ipe-products-catalog-' . gmdate('Y-m-d-His') . '.csv';

        // Send headers
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Output stream
        $output = fopen('php://output', 'w');

        // Mandatory Byte Order Mark (BOM) for Persian Excel UTF-8 compatibility
        fwrite($output, "\xEF\xBB\xBF");

        // CSV Header Columns
        fputcsv($output, [
            'Product_ID',
            'Product_Name',
            'Product_URL',
            'Image_URL',
            'Linked_Posts_Count',
            'Linked_Posts_List',
            'Unlinked_Posts_Count',
            'Unlinked_Posts_List',
        ]);

        foreach ($data['items'] as $item) {
            $linked_titles = [];
            if (!empty($item['linked_posts'])) {
                foreach ($item['linked_posts'] as $lp) {
                    $matched_info = !empty($lp['matched_term']) ? ' [' . $lp['matched_term'] . ']' : '';
                    $anchor_info  = !empty($lp['anchor']) ? ' [انکرتکست: ' . $lp['anchor'] . ']' : '';
                    $snippet_info = !empty($lp['snippet']) ? ' (موقعیت متن: ' . $lp['snippet'] . ')' : '';
                    $linked_titles[] = $lp['title'] . $matched_info . $anchor_info . $snippet_info . ' (مشاهده: ' . $lp['url'] . ' | ویرایش: ' . $lp['edit_url'] . ')';
                }
            }

            $unlinked_titles = [];
            if (!empty($item['unlinked_posts'])) {
                foreach ($item['unlinked_posts'] as $up) {
                    $matched_info = !empty($up['matched_term']) ? ' [' . $up['matched_term'] . ']' : '';
                    $snippet_info = !empty($up['snippet']) ? ' (موقعیت متن: ' . $up['snippet'] . ')' : '';
                    $unlinked_titles[] = $up['title'] . $matched_info . $snippet_info . ' (مشاهده: ' . $up['url'] . ' | ویرایش در گوتنبرگ: ' . $up['edit_url'] . ')';
                }
            }

            fputcsv($output, [
                $item['id'],
                $item['title'],
                $item['permalink'],
                !empty($item['full_image_url']) ? $item['full_image_url'] : $item['thumb_url'],
                $item['linked_count'],
                implode(' | ', $linked_titles),
                $item['unlinked_count'],
                implode(' | ', $unlinked_titles),
            ]);
        }

        fclose($output);
        exit;
    }

    /**
     * Export Scanned Data to JSON for AI Pipelines.
     */
    public static function export_json(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('شما اجازه دسترسی به این بخش را ندارید.', 'image-plugin-extractor'));
        }

        check_admin_referer('ipe_export_nonce', '_wpnonce');

        $data = get_transient(IPE_RESULTS_TRANSIENT);
        if (empty($data) || empty($data['items'])) {
            wp_die(esc_html__('هیچ داده‌ای برای خروجی وجود ندارد. لطفاً ابتدا اسکن را انجام دهید.', 'image-plugin-extractor'));
        }

        $filename = 'ipe-products-catalog-' . gmdate('Y-m-d-His') . '.json';

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// Bootstrap the plugin
IPE_Plugin::get_instance();
