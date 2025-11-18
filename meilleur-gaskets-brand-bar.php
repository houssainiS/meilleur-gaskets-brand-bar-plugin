<?php
/*
Plugin Name: Meilleur Gaskets Brand Bar
Description: Displays a dynamic car brand logo bar above the WooCommerce shop page and categories with drag-to-scroll functionality. Supports bidirectional brand and category filtering with checkbox category widget.
Version: 2.0
Author: Houssaini Slimen
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Debug message
add_action( 'wp_head', function() {
    echo '<!-- Brand Plugin Loaded ✅ -->';
});

// Show brand logos above shop and category pages
function mg_display_brand_bar() {
    if ( ! ( is_shop() || is_product_category() ) ) {
        return;
    }

    echo '<div class="mg-brand-bar-wrapper">';
    echo '<div class="mg-brand-bar-scroll" id="mgBrandScroll">';

    // Get all brands from Perfect Brands plugin
    $brands = get_terms( array(
        'taxonomy' => 'pwb-brand',
        'hide_empty' => false,
    ));

    if ( empty( $brands ) || is_wp_error( $brands ) ) {
        echo '<p>No brands found.</p>';
    } else {
        $current_category = null;
        $current_brand = isset( $_GET['pwb-brand'] ) ? sanitize_text_field( $_GET['pwb-brand'] ) : null;
        
        if ( is_product_category() ) {
            $queried_object = get_queried_object();
            if ( $queried_object && isset( $queried_object->slug ) ) {
                $current_category = $queried_object->slug;
            }
        } elseif ( isset( $_GET['product_cat'] ) ) {
            $current_category = sanitize_text_field( $_GET['product_cat'] );
        }

        foreach ( $brands as $brand ) {
            $shop_url = get_permalink( wc_get_page_id( 'shop' ) );
            $brand_link = add_query_arg( 'pwb-brand', $brand->slug, $shop_url );
            
            // Add category to brand link if currently selected
            if ( $current_category ) {
                $brand_link = add_query_arg( 'product_cat', $current_category, $brand_link );
            }

            $brand_img_id = get_term_meta( $brand->term_id, 'pwb_brand_image', true );
            $brand_img = $brand_img_id ? wp_get_attachment_image( $brand_img_id, 'medium' ) : '';

            echo '<a class="mg-brand-item" href="'. esc_url( $brand_link ) .'">';
            if ( $brand_img ) {
                echo str_replace( '<img', '<img draggable="false"', $brand_img );
            } else {
                echo esc_html( $brand->name );
            }
            echo '</a>';
        }
    }

    echo '</div>'; // .mg-brand-bar-scroll
    echo '</div>'; // .mg-brand-bar-wrapper
}
add_action( 'woocommerce_before_main_content', 'mg_display_brand_bar', 5 );

// Add styles and drag-to-scroll script
function mg_brand_bar_styles_scripts() {
    if ( ! ( is_shop() || is_product_category() ) ) {
        return;
    }

    echo '<style>
    .woocommerce-result-count {
    display: none !important;
}


    .mg-brand-bar-wrapper {
        overflow: hidden;
        margin-bottom: 30px;
        padding-bottom: 10px;
        cursor: grab;
        user-select: none;
    }
    .mg-brand-bar-scroll {
        display: flex;
        flex-wrap: nowrap;
        gap: 15px;
        overflow-x: scroll;
        scroll-behavior: smooth;
    }
    .mg-brand-item img {
        max-height: 60px;
        object-fit: contain;
        display: block;
        -webkit-user-drag: none; 
        user-drag: none;
        pointer-events: none;
    }
    .mg-brand-item {
        display: inline-block;
        padding: 5px;
        flex: 0 0 auto;
        transition: transform 0.2s ease;
    }
    .mg-brand-item:hover {
        transform: scale(1.05);
    }
    .mg-brand-bar-scroll::-webkit-scrollbar {
        display: none;
    }
    </style>';

    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        const slider = document.getElementById("mgBrandScroll");
        let isDown = false;
        let startX;
        let scrollLeft;
        let isDragging = false;

        slider.addEventListener("mousedown", (e) => {
            isDown = true;
            isDragging = false;
            slider.classList.add("active");
            startX = e.pageX - slider.offsetLeft;
            scrollLeft = slider.scrollLeft;
        });

        slider.addEventListener("mouseleave", () => {
            isDown = false;
            slider.classList.remove("active");
        });

        slider.addEventListener("mouseup", () => {
            isDown = false;
            slider.classList.remove("active");
        });

        slider.addEventListener("mousemove", (e) => {
            if(!isDown) return;
            const x = e.pageX - slider.offsetLeft;
            const walk = x - startX;
            if (Math.abs(walk) > 2) isDragging = true;
            if (isDragging) {
                e.preventDefault();
                slider.scrollLeft = scrollLeft - walk;
            }
        });

        slider.addEventListener("dragstart", (e) => {
            e.preventDefault();
            return false;
        });

        // Prevent link clicks when dragging
        slider.querySelectorAll("a").forEach(a => {
            a.addEventListener("click", (e) => {
                if (isDragging) e.preventDefault();
            });
        });

        // Touch support
        let startTouchX = 0;
        slider.addEventListener("touchstart", (e) => {
            startTouchX = e.touches[0].pageX;
            scrollLeft = slider.scrollLeft;
        });
        slider.addEventListener("touchmove", (e) => {
            const x = e.touches[0].pageX;
            const walk = x - startTouchX;
            slider.scrollLeft = scrollLeft - walk;
        });
    });
    </script>';
}
add_action( 'wp_footer', 'mg_brand_bar_styles_scripts' );

class MG_Category_Checkbox_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'woocommerce_product_categories_checkbox',
            __( 'Product Categories (Checkboxes)', 'woocommerce' ),
            array( 'description' => __( 'A list of product categories with checkboxes for multi-selection filtering', 'woocommerce' ) )
        );
    }

    public function widget( $args, $instance ) {
        if ( ! is_shop() && ! is_product_category() && ! is_product_taxonomy() ) {
            return;
        }

        $title         = isset( $instance['title'] ) ? $instance['title'] : __( 'Product Categories', 'woocommerce' );
        $orderby       = 'name';
        $hierarchical  = true;
        $hide_empty    = false;

        $current_cats  = isset( $_GET['product_cat'] ) ? array_map( 'sanitize_text_field', explode( ',', $_GET['product_cat'] ) ) : array();
        $current_brand = isset( $_GET['pwb-brand'] ) ? sanitize_text_field( $_GET['pwb-brand'] ) : null;

        $product_categories = get_terms( 'product_cat', array(
            'orderby'    => $orderby,
            'order'      => 'ASC',
            'hide_empty' => $hide_empty ? 1 : 0,
            'pad_counts' => true,
        ) );

        if ( empty( $product_categories ) || is_wp_error( $product_categories ) ) {
            return;
        }

        echo $args['before_widget'];

        if ( $title ) {
            echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
        }

        echo '<ul class="mg-category-checklist">';

        foreach ( $product_categories as $cat ) {
            if ( $hierarchical && $cat->parent != 0 ) continue; // Skip subcategories for now

            $checked = in_array( $cat->slug, $current_cats ) ? 'checked' : '';
            $shop_url = get_permalink( wc_get_page_id( 'shop' ) );
            $cat_link = add_query_arg( 'product_cat', $cat->slug, $shop_url );

            if ( $current_brand ) {
                $cat_link = add_query_arg( 'pwb-brand', $current_brand, $cat_link );
            }

            // Display only category name, remove product count
            echo '<li class="mg-category-item">';
            echo '<label>';
            echo '<input type="checkbox" name="product_cat" value="' . esc_attr( $cat->slug ) . '" ' . $checked . ' data-category="' . esc_attr( $cat->slug ) . '" data-cat-link="' . esc_url( $cat_link ) . '" class="mg-cat-checkbox">';
            echo ' ' . esc_html( $cat->name );
            echo '</label>';
            echo '</li>';
        }

        echo '</ul>';
        echo $args['after_widget'];
    }
}

// Register the custom widget
function mg_register_category_widget() {
    register_widget( 'MG_Category_Checkbox_Widget' );
}
add_action( 'widgets_init', 'mg_register_category_widget' );

function mg_filter_by_categories() {
    if ( ! isset( $_POST['categories'] ) ) {
        wp_die();
    }

    $categories = array_map( 'sanitize_text_field', explode( ',', $_POST['categories'] ) );
    $brand = isset( $_POST['brand'] ) ? sanitize_text_field( $_POST['brand'] ) : null;

    $shop_url = get_permalink( wc_get_page_id( 'shop' ) );

    if ( ! empty( $categories ) ) {
        $shop_url = add_query_arg( 'product_cat', implode( ',', $categories ), $shop_url );
    }

    if ( $brand ) {
        $shop_url = add_query_arg( 'pwb-brand', $brand, $shop_url );
    }

    wp_send_json_success( array( 'redirect_url' => $shop_url ) );
}
add_action( 'wp_ajax_mg_filter_categories', 'mg_filter_by_categories' );
add_action( 'wp_ajax_nopriv_mg_filter_categories', 'mg_filter_by_categories' );

function mg_enqueue_checkbox_script() {
    if ( ! ( is_shop() || is_product_category() ) ) {
        return;
    }

    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        const checkboxes = document.querySelectorAll(".mg-cat-checkbox");
        
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener("change", function() {
                const selectedCategories = Array.from(checkboxes)
                    .filter(cb => cb.checked)
                    .map(cb => cb.dataset.category)
                    .join(",");

                const currentBrand = new URLSearchParams(window.location.search).get("pwb-brand");
                
                let url = "' . get_permalink( wc_get_page_id( 'shop' ) ) . '";
                
                if (selectedCategories) {
                    url = new URL(url);
                    url.searchParams.set("product_cat", selectedCategories);
                } else {
                    url = new URL(url);
                    url.searchParams.delete("product_cat");
                }
                
                if (currentBrand) {
                    url.searchParams.set("pwb-brand", currentBrand);
                }
                
                window.location.href = url.toString();
            });
        });
    });
    </script>';
}
add_action( 'wp_footer', 'mg_enqueue_checkbox_script' );

function mg_checkbox_styles() {
    if ( ! ( is_shop() || is_product_category() ) ) {
        return;
    }

    echo '<style>
    .mg-category-checklist {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .mg-category-item {
        padding: 8px 0;
        border-bottom: 1px solid #eee;
    }
    .mg-category-item label {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        cursor: pointer;
        font-size: 14px;
        line-height: 1.3;
    }
    .mg-cat-checkbox {
        flex-shrink: 0;
        margin-top: 2px;
        cursor: pointer;
        width: 18px;
        height: 18px;
    }
    .mg-category-item label:hover {
        color: #0073aa;
    }
    </style>';
}
add_action( 'wp_footer', 'mg_checkbox_styles' );

// =========================================================
// 1. General Filters: Ordering Functionality (Applies Everywhere)
// =========================================================

/**
 * Ensures products can be ordered by setting price to 0 if empty.
 */
add_filter('woocommerce_product_get_price', function($price, $product) {
    return $price ?: 0;
}, 10, 2);

add_filter('woocommerce_product_get_regular_price', function($price, $product) {
    return $price ?: 0;
}, 10, 2);

/**
 * Hides price text wherever it appears (shop, cart, checkout items).
 */
add_filter('woocommerce_get_price_html', '__return_empty_string');
add_filter('woocommerce_cart_item_price', '__return_empty_string');
add_filter('woocommerce_cart_item_subtotal', '__return_empty_string');

/**
 * Disables payment and hides coupon forms.
 */
add_filter('woocommerce_cart_needs_payment', '__return_false');
add_filter('woocommerce_coupons_enabled', '__return_false');

/**
 * Allows checkout with 0 prices.
 */
add_filter('woocommerce_cart_total', function($value) {
    return '';
});

// =========================================================
// 2. Cart Page Specific Styles (Hide Totals/Prices)
// =========================================================

add_action('wp_head', 'custom_hide_cart_prices');
function custom_hide_cart_prices() {
    if (!is_cart()) return;

    echo '<style>
        /* Hide all prices, totals, and the default summary block on the cart page */
        .wc-block-cart-item__prices,
        .wc-block-cart-item__total-price-and-sale-badge-wrapper,
        .wc-block-cart-item__total,
        .wc-block-components-product-price,
        .wc-block-components-totals-wrapper,
        .wc-block-components-totals-item,
        .wp-block-woocommerce-cart-order-summary-block {
            display: none !important;
            visibility: hidden !important;
        }
        
        /* Ensure the cart checkout button remains visible */
        .wc-block-cart__submit-button,
        .wc-block-components-button {
            display: inline-flex !important;
            visibility: visible !important;
        }
    </style>';
}

// =========================================================
// 3. Checkout Page Specific Styles (Show Resume, Hide Prices, Show Image/Quantity)
// =========================================================

add_action('wp_head', 'final_custom_checkout_styles');
function final_custom_checkout_styles() {
    if (!is_checkout()) return;

    echo '<style>
        /* === A. Hide All Price and Total Elements === */
        
        /* Hides the totals block (Subtotal, Tax, Shipping) */
        [data-block-name="woocommerce/checkout-order-summary-totals-block"],
        /* Hides the main title price (Total at the top) */
        .wc-block-components-checkout-order-summary__title-price,
        /* Hides the totals at the very bottom (Subtotal, Total) */
        .wc-block-components-totals-footer-item,
        .wc-block-components-totals-item__value,
        /* Hides individual prices and total price per item */
        .wc-block-components-order-summary-item__individual-price,
        .wc-block-components-order-summary-item__total-price,
        /* Hides screen reader text for prices */
        span.screen-reader-text[aria-hidden="false"] {
            display: none !important;
        }

        /* === B. Clean up the default Product Resume (Removed Image/Quantity Hiding) === */
        
        /* The image and quantity are now visible, so we only hide the product metadata description */
        .wc-block-components-product-metadata {
            display: none !important;
        }
        
        /* Adjust spacing back to normal since the image is visible */
        .wc-block-components-order-summary-item__description {
            /* Remove the previous margin-left: 0 !important; to align with the visible image */
        }

    </style>';
}


// =========================================================
// 4. Mini-Cart/Cart Drawer Total Hiding
// =========================================================

add_action('wp_head', 'custom_hide_mini_cart_total');
/**
 * Hides the "Sous-total/Subtotal" price line in the sliding Mini-Cart (Cart Drawer).
 * Uses high-specificity CSS to override conflicting theme styles.
 */
function custom_hide_mini_cart_total() {
    // This targets the specific class you provided, adding the parent element to increase priority.
    echo '<style>
        /* High-specificity rule to hide the subtotal/total line in the standard Mini-Cart widget */
        .widget_shopping_cart_content .woocommerce-mini-cart__total.total {
            display: none !important;
        }

        /* Rule for the new WooCommerce Block Mini-Cart if your theme uses it */
        .wc-block-mini-cart__footer .wc-block-components-totals-wrapper {
            display: none !important;
        }

        /* Catch-all for other potential Mini-Cart wrapper classes */
        .woocommerce-mini-cart__total,
        .mini-cart-total,
        .cart-widget-total {
            display: none !important;
        }
    </style>';
}

// =========================================================
// 5. Restrict Shop, Cart, and Mini-Cart for Logged-Out Users
// =========================================================

add_action('template_redirect', 'redirect_guest_users_from_shop_cart');
/**
 * Redirects non-logged-in users trying to access the Shop, Cart, or Mini-Cart.
 * The redirect sends them to the default WordPress login page, and then returns 
 * them to the page they were trying to access after a successful login.
 */
function redirect_guest_users_from_shop_cart() {
    // 1. Check if the user is NOT logged in.
    if ( ! is_user_logged_in() ) {
        
        // 2. Define the pages we want to restrict.
        // is_shop() checks the main "Catalogue" page.
        // is_cart() checks the main Cart page.
        // is_woocommerce() checks WooCommerce pages in general (including product pages).
        if ( is_shop() || is_cart() || is_woocommerce() ) {
            
            // 3. Perform the secure redirect.
            // auth_redirect() is the best function for this:
            // - It sends them to the WP login page.
            // - After they log in, it automatically redirects them back to the 
            //   original page (e.g., the Cart or Shop page).
            auth_redirect();
            exit(); // Ensure no further code is executed
        }
    }
}

// =========================================================
// 6. Hide prices on the Order Received / Thank You page
// =========================================================
add_action('wp_head', 'custom_hide_order_received_prices');
function custom_hide_order_received_prices() {
    if (!is_order_received_page()) return;

    echo '<style>
        /* Hide all product totals in the order table */
        .woocommerce-table--order-details .product-total,
        .woocommerce-table--order-details tfoot th,
        .woocommerce-table--order-details tfoot td,
        /* Hide the total in the order overview block */
        .woocommerce-order-overview__total {
            display: none !important;
            visibility: hidden !important;
        }
    </style>';
}

//////////catalogue ////////////

/* ---------- Brand Catalogue CPT + PDF uploader + Frontend Catalogue ---------- */
/* Add to your Meilleur Gaskets Brand Bar plugin file */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 1) Register CPT: mg_brand_catalogue
 */
function mg_register_brand_catalogue_cpt() {
    $labels = array(
        'name'               => __( 'Brand Catalogues', 'mg' ),
        'singular_name'      => __( 'Brand Catalogue', 'mg' ),
        'menu_name'          => __( 'Brand Catalogues', 'mg' ),
        'name_admin_bar'     => __( 'Brand Catalogue', 'mg' ),
        'add_new'            => __( 'Add New', 'mg' ),
        'add_new_item'       => __( 'Add New Catalogue', 'mg' ),
        'new_item'           => __( 'New Catalogue', 'mg' ),
        'edit_item'          => __( 'Edit Catalogue', 'mg' ),
        'view_item'          => __( 'View Catalogue', 'mg' ),
        'all_items'          => __( 'All Catalogues', 'mg' ),
        'search_items'       => __( 'Search Catalogues', 'mg' ),
        'not_found'          => __( 'No catalogues found.', 'mg' ),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => false, // not public by itself; accessible via frontend list
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'menu_position'      => 58,
        'menu_icon'          => 'dashicons-media-document',
        'supports'           => array( 'title' ),
        'capability_type'    => 'post',
    );

    register_post_type( 'mg_brand_catalogue', $args );
}
add_action( 'init', 'mg_register_brand_catalogue_cpt' );

/**
 * 2) Meta box: Brand selector + PDF upload (attachment ID)
 */
function mg_add_catalogue_meta_box() {
    add_meta_box(
        'mg_catalogue_meta',
        __( 'Brand Catalogue Data', 'mg' ),
        'mg_catalogue_meta_box_callback',
        'mg_brand_catalogue',
        'normal',
        'high'
    );
}
add_action( 'add_meta_boxes', 'mg_add_catalogue_meta_box' );

function mg_catalogue_meta_box_callback( $post ) {
    wp_nonce_field( 'mg_catalogue_meta_save', 'mg_catalogue_meta_nonce' );

    $selected_term_id = (int) get_post_meta( $post->ID, '_mg_brand_term_id', true );
    $pdf_attachment_id = (int) get_post_meta( $post->ID, '_mg_brand_pdf', true );
    $pdf_url = $pdf_attachment_id ? wp_get_attachment_url( $pdf_attachment_id ) : '';

    $brands = get_terms( array(
        'taxonomy' => 'pwb-brand',
        'hide_empty' => false,
    ) );

    echo '<p><label><strong>' . esc_html__( 'Select brand', 'mg' ) . '</strong></label></p>';
    echo '<p><select name="mg_brand_term_id" style="width:100%;">';
    echo '<option value="">' . esc_html__( '-- Select a brand --', 'mg' ) . '</option>';
    if ( ! empty( $brands ) && ! is_wp_error( $brands ) ) {
        foreach ( $brands as $b ) {
            printf(
                '<option value="%d" %s>%s</option>',
                intval( $b->term_id ),
                selected( $selected_term_id, $b->term_id, false ),
                esc_html( $b->name )
            );
        }
    } else {
        echo '<option value="">' . esc_html__( 'No brands found', 'mg' ) . '</option>';
    }
    echo '</select></p>';

    // --- DELETED DUPLICATE FUNCTION FROM HERE ---

    // PDF uploader markup
    echo '<p><label><strong>' . esc_html__( 'Catalogue PDF', 'mg' ) . '</strong></label></p>';
    echo '<p>
        <input type="hidden" id="mg_brand_pdf" name="mg_brand_pdf" value="' . esc_attr( $pdf_attachment_id ) . '">
        <button type="button" class="button" id="mg_upload_pdf_button">' . ( $pdf_attachment_id ? esc_html__( 'Replace PDF', 'mg' ) : esc_html__( 'Upload PDF', 'mg' ) ) . '</button>
        <span id="mg_pdf_preview" style="margin-left:10px;">' . ( $pdf_url ? '<a href="' . esc_url( $pdf_url ) . '" target="_blank">' . esc_html__( 'View current PDF', 'mg' ) . '</a>' : esc_html__( 'No file selected', 'mg' ) ) . '</span>
        <button type="button" style="margin-left:10px;" class="button" id="mg_remove_pdf_button">' . esc_html__( 'Remove', 'mg' ) . '</button>
    </p>';

    ?>
    <script>
    (function($){
        var frame;
        $('#mg_upload_pdf_button').on('click', function(e){
            e.preventDefault();
            if ( frame ) frame.open();
            frame = wp.media({
                title: '<?php echo esc_js( "Select or Upload PDF" ); ?>',
                button: { text: '<?php echo esc_js( "Use this PDF" ); ?>' },
                library: { type: '' },
                multiple: false
            });
            frame.on( 'select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                if (attachment.mime && attachment.mime !== 'application/pdf') {
                    if (!attachment.url || attachment.url.indexOf('.pdf') === -1) {
                        alert('<?php echo esc_js( "Please select a PDF file." ); ?>');
                        return;
                    }
                }
                $('#mg_brand_pdf').val(attachment.id);
                $('#mg_pdf_preview').html('<a href="'+attachment.url+'" target="_blank">View current PDF</a>');
                $('#mg_upload_pdf_button').text('Replace PDF');
            });
            frame.open();
        });

        $('#mg_remove_pdf_button').on('click', function(e){
            e.preventDefault();
            $('#mg_brand_pdf').val('');
            $('#mg_pdf_preview').text('No file selected');
            $('#mg_upload_pdf_button').text('Upload PDF');
        });

    })(jQuery);
    </script>
    <?php
}

/**
 * Save meta
 */
function mg_save_catalogue_meta( $post_id, $post ) {
    if ( ! isset( $_POST['mg_catalogue_meta_nonce'] ) || ! wp_verify_nonce( $_POST['mg_catalogue_meta_nonce'], 'mg_catalogue_meta_save' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    if ( isset( $_POST['mg_brand_term_id'] ) ) {
        update_post_meta( $post_id, '_mg_brand_term_id', intval( $_POST['mg_brand_term_id'] ) );
    } else {
        delete_post_meta( $post_id, '_mg_brand_term_id' );
    }

    if ( isset( $_POST['mg_brand_pdf'] ) && $_POST['mg_brand_pdf'] !== '' ) {
        update_post_meta( $post_id, '_mg_brand_pdf', intval( $_POST['mg_brand_pdf'] ) );
    } else {
        delete_post_meta( $post_id, '_mg_brand_pdf' );
    }
}
add_action( 'save_post_mg_brand_catalogue', 'mg_save_catalogue_meta', 10, 2 );

/**
 * 3) Helper: get catalogue post by brand term_id
 */
function mg_get_catalogue_by_brand_term_id( $term_id ) {
    $args = array(
        'post_type'      => 'mg_brand_catalogue',
        'meta_key'       => '_mg_brand_term_id',
        'meta_value'     => intval( $term_id ),
        'post_status'    => 'publish',
        'posts_per_page' => 1,
    );
    $q = new WP_Query( $args );
    return $q->have_posts() ? $q->posts[0] : null;
}

/**
 * 4) Serve PDF inline for iframe and new tab
 */
function mg_serve_catalogue_pdf() {
    // Don't run this logic in the admin area at all
    if ( is_admin() ) {
        return;
    }

    // Check if our specific query var is set
    if ( ! isset( $_GET['mg_pdf_viewer'] ) ) {
        return;
    }

    $catalogue_post_id = intval( $_GET['mg_pdf_viewer'] );
    
    // We must check for the post *before* clearing buffers or sending headers
    $post = get_post( $catalogue_post_id );
    if ( ! $post || $post->post_type !== 'mg_brand_catalogue' ) {
        // We can't use wp_die() yet, as it will output HTML.
        // Send a simple, clean 404.
        header("HTTP/1.0 404 Not Found");
        die('File not found.');
    }

    $att_id = intval( get_post_meta( $catalogue_post_id, '_mg_brand_pdf', true ) );
    if ( ! $att_id ) {
        header("HTTP/1.0 404 Not Found");
        die('No PDF attached.');
    }

    $file_path = get_attached_file( $att_id );
    if ( ! $file_path || ! file_exists( $file_path ) ) {
        header("HTTP/1.0 404 Not Found");
        die('PDF file missing from server.');
    }

    // --- CRITICAL SECTION ---
    
    // 1. Aggressively clear any and all output buffers
    // This removes any invisible whitespace, BOMs, etc.
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }

    // 2. Remove any conflicting headers that might have been set
    // This is an extra precaution.
    if ( function_exists('header_remove') ) {
        header_remove('Content-Disposition');
        header_remove('Pragma');
        header_remove('Cache-Control');
        header_remove('Expires');
        header_remove('X-Content-Type-Options');
    }

    // 3. Set our own headers to force INLINE display
    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: inline; filename="' . basename( $file_path ) . '"' );
    header( 'Content-Length: ' . filesize( $file_path ) );
    header( 'Accept-Ranges: bytes' );
    
    // Caching headers
    header( 'Cache-Control: public, max-age=86400' );
    header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + 86400 ) . ' GMT' );
    
    // Security header
    header( 'X-Content-Type-Options: nosniff' );

    // 4. Clear all system buffers one last time
    flush();
    
    // 5. Read the file and send it to the browser
    $readfile_success = readfile( $file_path );
    
    // 6. Stop all further WordPress execution
    // We use die() to be absolutely certain.
    if ($readfile_success === false) {
        die('Failed to read file.');
    }
    
    die();
}

// THIS IS THE HOOK - It should already be correct
// We run this on 'init' at priority 0.
add_action( 'init', 'mg_serve_catalogue_pdf', 0 );


/**
 * 5) Frontend shortcode
 */
function mg_catalogue_shortcode( $atts ) {
    ob_start();

    $brand_slug = isset( $_GET['brand'] ) ? sanitize_text_field( wp_unslash( $_GET['brand'] ) ) : '';

    if ( $brand_slug ) {
        $term = get_term_by( 'slug', $brand_slug, 'pwb-brand' );
        if ( ! $term || is_wp_error( $term ) ) {
            echo '<p>Brand not found.</p>';
            return ob_get_clean();
        }

        $catalogue_post = mg_get_catalogue_by_brand_term_id( $term->term_id );

        echo '<div class="mg-catalogue-detail">';
        echo '<p><a href="' . esc_url( get_permalink() ) . '">← Back to Catalogue</a></p>';
        echo '<h2>' . esc_html( $term->name ) . '</h2>';

        if ( $catalogue_post ) {
            $viewer_url = home_url( '?mg_pdf_viewer=' . $catalogue_post->ID );
            echo '<p><a class="mg-download-btn" href="' . esc_url( $viewer_url ) . '" target="_blank" rel="noopener">Open PDF (new tab)</a></p>';
            echo '<div class="mg-embed-pdf" style="max-width:100%;height:800px;overflow:auto;">';
            echo '<iframe src="' . esc_url( $viewer_url ) . '" width="100%" height="100%" style="border:1px solid #ddd;" frameborder="0" allowfullscreen></iframe>';
            echo '</div>';
        } else {
            echo '<p>No catalogue assigned for this brand.</p>';
        }

        echo '</div>';
        return ob_get_clean();
    }

    $brands = get_terms( array(
        'taxonomy' => 'pwb-brand',
        'hide_empty' => false,
    ) );

    echo '<div class="mg-catalogue-grid">';
    if ( empty( $brands ) || is_wp_error( $brands ) ) {
        echo '<p>No brands found.</p>';
    } else {
        foreach ( $brands as $brand ) {
            $term_id = $brand->term_id;
            $term_slug = $brand->slug;
            $brand_name = $brand->name;

            $brand_img_id = get_term_meta( $term_id, 'pwb_brand_image', true );
            $brand_img_html = $brand_img_id ? wp_get_attachment_image( $brand_img_id, 'medium' ) : '';

            $catalogue_link = add_query_arg( 'brand', $term_slug, get_permalink() );

            echo '<div class="mg-catalogue-card">';
            echo '<a class="mg-catalogue-link" href="' . esc_url( $catalogue_link ) . '">';
            if ( $brand_img_html ) {
                echo '<div class="mg-brand-logo">' . $brand_img_html . '</div>';
            } else {
                echo '<div class="mg-brand-logo-placeholder">' . esc_html( $brand_name ) . '</div>';
            }
            echo '<div class="mg-brand-name">' . esc_html( $brand_name ) . '</div>';
            echo '</a>';
            echo '</div>';
        }
    }
    echo '</div>';

    ?>
    <style>
    .mg-catalogue-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px,1fr)); gap:18px; margin:20px 0; }
    .mg-catalogue-card { border:1px solid #eee; padding:12px; text-align:center; background:#fff; border-radius:8px; box-shadow:0 1px 2px rgba(0,0,0,0.03); }
    .mg-brand-logo img { max-height:70px; object-fit:contain; display:block; margin:0 auto 8px; }
    .mg-brand-logo-placeholder { padding:24px 6px; font-weight:600; color:#333; }
    .mg-brand-name { font-size:14px; margin-top:6px; color:#333; }
    .mg-download-btn { display:inline-block; padding:8px 12px; border:1px solid #ddd; background:#f7f7f7; border-radius:6px; text-decoration:none; color:#333; margin-bottom:12px; }
    .mg-embed-pdf iframe { min-height:600px; }
    </style>
    <?php

    return ob_get_clean();
}
add_shortcode( 'mg_catalogue', 'mg_catalogue_shortcode' );

/**
 * 6) Auto-insert shortcode on page with slug 'catalogue'
 */
function mg_auto_insert_catalogue_shortcode( $content ) {
    if ( is_admin() ) return $content;
    if ( is_page() ) {
        global $post;
        if ( ! $post ) return $content;
        if ( 'catalogue' === $post->post_name && ! has_shortcode( $post->post_content, 'mg_catalogue' ) ) {
            return do_shortcode( '[mg_catalogue]' ) . $content;
        }
    }
    return $content;
}
add_filter( 'the_content', 'mg_auto_insert_catalogue_shortcode', 20 );

/**
 * 7) Enqueue WP media uploader for CPT
 */
function mg_enqueue_admin_uploader($hook) {
    global $post;
    if (isset($post) && $post->post_type === 'mg_brand_catalogue') {
        wp_enqueue_media();
    }
}
add_action('admin_enqueue_scripts', 'mg_enqueue_admin_uploader');

////////// End of Brand Catalogue module ////////////



//remove duplicated show password
add_action('wp_footer', function() {
  ?>
  <script>
  (function(){
    function removeStaticPasswordButtons(){
      document.querySelectorAll('.password-input input[type="password"]').forEach(function(input){
        var btn = input.parentElement.querySelector('button.show-password-input');
        if(btn) {
          // remove only the static sibling button (keeps dynamically inserted ones)
          btn.remove();
        }
      });
    }
    document.addEventListener('DOMContentLoaded', removeStaticPasswordButtons);
    // also observe in case markup changes later
    var obs = new MutationObserver(removeStaticPasswordButtons);
    obs.observe(document.body, { childList: true, subtree: true });
  })();
  </script>
  <?php
}, 999);


/// remove language selector in the admin login 

add_filter( 'login_display_language_dropdown', '__return_false' );
