<?php
/*
Plugin Name: Meilleur Gaskets Brand Bar
Description: Displays a dynamic car brand logo bar above the WooCommerce shop page and categories with drag-to-scroll functionality. Supports bidirectional brand and category filtering with checkbox category widget. Includes secure PDF Catalogue Viewer.
Version: 2.1
Author: Houssaini Slimen
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// =========================================================
// SECTION 1: BRAND BAR - FRONTEND DISPLAY & FUNCTIONALITY
// =========================================================
// Displays a horizontal scrollable bar of brand logos on shop/category pages
// with drag-to-scroll, touch support, and brand/category filtering

/**
 * Display the brand bar on shop and category pages
 * Shows brand logos with links that maintain current category filters
 * Hooked to: woocommerce_before_main_content at priority 5
 */
function mg_display_brand_bar() {
    // Only show on shop or product category pages
    if ( ! ( is_shop() || is_product_category() ) ) {
        return;
    }

    echo '<div class="mg-brand-bar-wrapper">';
    echo '<div class="mg-brand-bar-scroll" id="mgBrandScroll">';

    // Get all brands from Perfect Brands plugin (pwb-brand taxonomy)
    $brands = get_terms( array(
        'taxonomy' => 'pwb-brand',
        'hide_empty' => false,
    ));

    if ( empty( $brands ) || is_wp_error( $brands ) ) {
        echo '<p>No brands found.</p>';
    } else {
        // --- Get current filters from URL ---
        $current_category = null;
        $current_brand = isset( $_GET['pwb-brand'] ) ? sanitize_text_field( $_GET['pwb-brand'] ) : null;
        
        // Check if viewing a product category page
        if ( is_product_category() ) {
            $queried_object = get_queried_object();
            if ( $queried_object && isset( $queried_object->slug ) ) {
                $current_category = $queried_object->slug;
            }
        } elseif ( isset( $_GET['product_cat'] ) ) {
            $current_category = sanitize_text_field( $_GET['product_cat'] );
        }

        // --- Loop through each brand and output logo ---
        foreach ( $brands as $brand ) {
            $shop_url = get_permalink( wc_get_page_id( 'shop' ) );
            $brand_link = add_query_arg( 'pwb-brand', $brand->slug, $shop_url );
            
            // Maintain category filter when clicking a brand
            if ( $current_category ) {
                $brand_link = add_query_arg( 'product_cat', $current_category, $brand_link );
            }

            // Get brand image from Perfect Brands plugin metadata
            $brand_img_id = get_term_meta( $brand->term_id, 'pwb_brand_image', true );
            $brand_img = $brand_img_id ? wp_get_attachment_image( $brand_img_id, 'medium' ) : '';

            // Output brand item with logo or fallback to brand name
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

/**
 * Enqueue styles and drag-to-scroll JavaScript for brand bar
 * Includes:
 * - CSS for brand bar layout and scrolling
 * - JS for mouse drag-to-scroll functionality
 * - JS for touch support (mobile)
 * Hooked to: wp_footer
 */
function mg_brand_bar_styles_scripts() {
    if ( ! ( is_shop() || is_product_category() ) ) {
        return;
    }

    // --- STYLES ---
    echo '<style>
    /* Hide WooCommerce result count that conflicts with brand bar */
    .woocommerce-result-count {
        display: none !important;
    }

    /* Brand bar wrapper - handles overflow and spacing */
    .mg-brand-bar-wrapper {
        overflow: hidden;
        margin-bottom: 30px;
        padding-bottom: 10px;
        cursor: grab;
        user-select: none;
    }

    /* Scrollable container for brand logos */
    .mg-brand-bar-scroll {
        display: flex;
        flex-wrap: nowrap;
        gap: 15px;
        overflow-x: scroll;
        scroll-behavior: smooth;
    }

    /* Brand logo image sizing */
    .mg-brand-item img {
        max-height: 60px;
        object-fit: contain;
        display: block;
        -webkit-user-drag: none; 
        user-drag: none;
        pointer-events: none;
    }

    /* Brand item link styling */
    .mg-brand-item {
        display: inline-block;
        padding: 5px;
        flex: 0 0 auto;
        transition: transform 0.2s ease;
    }

    .mg-brand-item:hover {
        transform: scale(1.05);
    }

    /* Hide default scrollbar for cleaner look */
    .mg-brand-bar-scroll::-webkit-scrollbar {
        display: none;
    }
    </style>';

    // --- JAVASCRIPT: DRAG-TO-SCROLL & TOUCH SUPPORT ---
    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        const slider = document.getElementById("mgBrandScroll");
        
        // --- MOUSE DRAG FUNCTIONALITY ---
        let isDown = false;
        let startX;
        let scrollLeft;
        let isDragging = false;

        // Mouse down: start tracking position
        slider.addEventListener("mousedown", (e) => {
            isDown = true;
            isDragging = false;
            slider.classList.add("active");
            startX = e.pageX - slider.offsetLeft;
            scrollLeft = slider.scrollLeft;
        });

        // Mouse leave: stop dragging
        slider.addEventListener("mouseleave", () => {
            isDown = false;
            slider.classList.remove("active");
        });

        // Mouse up: stop dragging
        slider.addEventListener("mouseup", () => {
            isDown = false;
            slider.classList.remove("active");
        });

        // Mouse move: perform drag scroll
        slider.addEventListener("mousemove", (e) => {
            if(!isDown) return;
            const x = e.pageX - slider.offsetLeft;
            const walk = x - startX;
            // Only consider it dragging if movement > 2px (prevents accidental drag on clicks)
            if (Math.abs(walk) > 2) isDragging = true;
            if (isDragging) {
                e.preventDefault();
                slider.scrollLeft = scrollLeft - walk;
            }
        });

        // Prevent native drag behavior on images
        slider.addEventListener("dragstart", (e) => {
            e.preventDefault();
            return false;
        });

        // Prevent link navigation when dragging (only navigate on pure clicks)
        slider.querySelectorAll("a").forEach(a => {
            a.addEventListener("click", (e) => {
                if (isDragging) e.preventDefault();
            });
        });

        // --- TOUCH SUPPORT FOR MOBILE ---
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


// =========================================================
// SECTION 2: CATEGORY CHECKBOX WIDGET & FILTERING
// =========================================================
// Custom widget for product category filtering with checkboxes
// Allows multi-select category filtering while preserving brand filters

/**
 * Custom Widget Class: Category Checkbox Filter
 * Displays product categories as clickable checkboxes for multi-selection
 * Works with brand filters to allow combined filtering
 */
class MG_Category_Checkbox_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'woocommerce_product_categories_checkbox',
            __( 'Product Categories (Checkboxes)', 'woocommerce' ),
            array( 'description' => __( 'A list of product categories with checkboxes for multi-selection filtering', 'woocommerce' ) )
        );
    }

    /**
     * Widget output on frontend
     * Shows list of categories as checkboxes
     * Maintains current brand filter when category is selected
     */
    public function widget( $args, $instance ) {
        // Only display on shop and product pages
        if ( ! is_shop() && ! is_product_category() && ! is_product_taxonomy() ) {
            return;
        }

        // --- WIDGET SETTINGS ---
        $title         = isset( $instance['title'] ) ? $instance['title'] : __( 'Product Categories', 'woocommerce' );
        $orderby       = 'name';
        $hierarchical  = true;
        $hide_empty    = false;

        // --- GET CURRENT FILTERS FROM URL ---
        $current_cats  = isset( $_GET['product_cat'] ) ? array_map( 'sanitize_text_field', explode( ',', $_GET['product_cat'] ) ) : array();
        $current_brand = isset( $_GET['pwb-brand'] ) ? sanitize_text_field( $_GET['pwb-brand'] ) : null;

        // --- FETCH ALL PRODUCT CATEGORIES ---
        $product_categories = get_terms( 'product_cat', array(
            'orderby'    => $orderby,
            'order'      => 'ASC',
            'hide_empty' => $hide_empty ? 1 : 0,
            'pad_counts' => true,
        ) );

        if ( empty( $product_categories ) || is_wp_error( $product_categories ) ) {
            return;
        }

        // --- OUTPUT WIDGET ---
        echo $args['before_widget'];

        if ( $title ) {
            echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
        }

        echo '<ul class="mg-category-checklist">';

        // --- OUTPUT EACH CATEGORY AS CHECKBOX ---
        foreach ( $product_categories as $cat ) {
            // Skip subcategories for now (show only top-level)
            if ( $hierarchical && $cat->parent != 0 ) continue;

            $checked = in_array( $cat->slug, $current_cats ) ? 'checked' : '';
            $shop_url = get_permalink( wc_get_page_id( 'shop' ) );
            $cat_link = add_query_arg( 'product_cat', $cat->slug, $shop_url );

            // Preserve brand filter when clicking category
            if ( $current_brand ) {
                $cat_link = add_query_arg( 'pwb-brand', $current_brand, $cat_link );
            }

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

/**
 * Register the custom category checkbox widget
 * Makes it available in Widgets area
 * Hooked to: widgets_init
 */
function mg_register_category_widget() {
    register_widget( 'MG_Category_Checkbox_Widget' );
}
add_action( 'widgets_init', 'mg_register_category_widget' );

/**
 * AJAX handler for category filtering
 * Called when checkboxes are changed to redirect to filtered page
 * Hooked to: wp_ajax_mg_filter_categories and wp_ajax_nopriv_mg_filter_categories
 */
function mg_filter_by_categories() {
    if ( ! isset( $_POST['categories'] ) ) {
        wp_die();
    }

    $categories = array_map( 'sanitize_text_field', explode( ',', $_POST['categories'] ) );
    $brand = isset( $_POST['brand'] ) ? sanitize_text_field( $_POST['brand'] ) : null;

    $shop_url = get_permalink( wc_get_page_id( 'shop' ) );

    // Add categories to URL
    if ( ! empty( $categories ) ) {
        $shop_url = add_query_arg( 'product_cat', implode( ',', $categories ), $shop_url );
    }

    // Preserve brand filter
    if ( $brand ) {
        $shop_url = add_query_arg( 'pwb-brand', $brand, $shop_url );
    }

    wp_send_json_success( array( 'redirect_url' => $shop_url ) );
}
add_action( 'wp_ajax_mg_filter_categories', 'mg_filter_by_categories' );
add_action( 'wp_ajax_nopriv_mg_filter_categories', 'mg_filter_by_categories' );

/**
 * Enqueue JavaScript for category checkbox functionality
 * Handles real-time URL updates when checkboxes change
 * Hooked to: wp_footer
 */
function mg_enqueue_checkbox_script() {
    if ( ! ( is_shop() || is_product_category() ) ) {
        return;
    }

    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        const checkboxes = document.querySelectorAll(".mg-cat-checkbox");
        
        // --- LISTEN FOR CHECKBOX CHANGES ---
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener("change", function() {
                // Get all checked categories
                const selectedCategories = Array.from(checkboxes)
                    .filter(cb => cb.checked)
                    .map(cb => cb.dataset.category)
                    .join(",");

                // Get current brand filter from URL
                const currentBrand = new URLSearchParams(window.location.search).get("pwb-brand");
                
                // Build new URL with selected categories
                let url = "' . get_permalink( wc_get_page_id( 'shop' ) ) . '";
                
                if (selectedCategories) {
                    url = new URL(url);
                    url.searchParams.set("product_cat", selectedCategories);
                } else {
                    url = new URL(url);
                    url.searchParams.delete("product_cat");
                }
                
                // Preserve brand filter
                if (currentBrand) {
                    url.searchParams.set("pwb-brand", currentBrand);
                }
                
                // Navigate to filtered URL
                window.location.href = url.toString();
            });
        });
    });
    </script>';
}
add_action( 'wp_footer', 'mg_enqueue_checkbox_script' );

/**
 * Styles for the category checkbox widget
 * Hooked to: wp_footer
 */
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
        color: #D11D27;
    }
    </style>';
}
add_action( 'wp_footer', 'mg_checkbox_styles' );


// =========================================================
// SECTION 3: WOOCOMMERCE PRICE HIDING & DISPLAY MODIFICATIONS
// =========================================================
// Global settings to hide prices throughout the store and disable payments
// Allows the store to function as a catalog-only system

/**
 * Ensure products always have a price value for sorting
 * Returns 0 if price is not set (prevents product ordering errors)
 * Hooked to: woocommerce_product_get_price and woocommerce_product_get_regular_price
 */
add_filter('woocommerce_product_get_price', function($price, $product) {
    return $price ?: 0;
}, 10, 2);

add_filter('woocommerce_product_get_regular_price', function($price, $product) {
    return $price ?: 0;
}, 10, 2);

/**
 * Hide all price text output everywhere in the store
 * Hooked to: woocommerce_get_price_html, woocommerce_cart_item_price, woocommerce_cart_item_subtotal
 */
add_filter('woocommerce_get_price_html', '__return_empty_string');
add_filter('woocommerce_cart_item_price', '__return_empty_string');
add_filter('woocommerce_cart_item_subtotal', '__return_empty_string');

/**
 * Disable payment processing and coupon functionality
 * Hooked to: woocommerce_cart_needs_payment and woocommerce_coupons_enabled
 */
add_filter('woocommerce_cart_needs_payment', '__return_false');
add_filter('woocommerce_coupons_enabled', '__return_false');

/**
 * Hide cart totals
 * Hooked to: woocommerce_cart_total
 */
add_filter('woocommerce_cart_total', function($value) {
    return '';
});


// =========================================================
// SECTION 4: CART PAGE STYLING
// =========================================================
// Hide prices and totals on the cart page while keeping checkout button visible

/**
 * Add CSS to hide prices and totals on cart page
 * Preserves checkout button functionality
 * Hooked to: wp_head
 */
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
        
        /* Ensure the cart checkout button remains visible and functional */
        .wc-block-cart__submit-button,
        .wc-block-components-button {
            display: inline-flex !important;
            visibility: visible !important;
        }
    </style>';
}


// =========================================================
// SECTION 5: CHECKOUT PAGE STYLING
// =========================================================
// Hide all pricing info on checkout while displaying product resume with images

/**
 * Add CSS to hide prices on checkout page
 * Shows product images and quantities but hides all pricing
 * Hooked to: wp_head
 */
add_action('wp_head', 'final_custom_checkout_styles');
function final_custom_checkout_styles() {
    if (!is_checkout()) return;

    echo '<style>
        /* === HIDE ALL PRICE AND TOTAL ELEMENTS === */
        
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

        /* === PRODUCT RESUME DISPLAY === */
        
        /* Hide the product metadata description while keeping image visible */
        .wc-block-components-product-metadata {
            display: none !important;
        }
    </style>';
}


// =========================================================
// SECTION 6: MINI-CART / CART DRAWER STYLING
// =========================================================
// Hide totals in the sliding cart drawer widget

/**
 * Hide subtotal/total line in mini-cart drawer
 * Uses high-specificity CSS to override theme conflicts
 * Hooked to: wp_head
 */
add_action('wp_head', 'custom_hide_mini_cart_total');
function custom_hide_mini_cart_total() {
    echo '<style>
        /* Hide subtotal/total line in standard Mini-Cart widget */
        .widget_shopping_cart_content .woocommerce-mini-cart__total.total {
            display: none !important;
        }

        /* Hide totals in WooCommerce Block Mini-Cart */
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
// SECTION 7: ACCESS CONTROL - RESTRICT SHOP FOR GUEST USERS
// =========================================================
// Redirect non-logged-in users from shop, cart, and WooCommerce pages

/**
 * Redirect guest users to login page
 * Users trying to access shop/cart without login are sent to WP login
 * After login, they're automatically redirected back to their original page
 * Hooked to: template_redirect
 */
add_action('template_redirect', 'redirect_guest_users_from_shop_cart');
function redirect_guest_users_from_shop_cart() {
    // Check if user is NOT logged in
    if ( ! is_user_logged_in() ) {
        
        // Check if they're trying to access restricted pages
        // is_shop() = main catalog page
        // is_cart() = cart page
        // is_woocommerce() = any WooCommerce page (including product pages)
        if ( is_shop() || is_cart() || is_woocommerce() ) {
            
            // auth_redirect() sends to WP login and returns here after successful login
            auth_redirect();
            exit();
        }
    }
}


// =========================================================
// SECTION 8: ORDER RECEIVED PAGE STYLING
// =========================================================
// Hide pricing information on thank you / order confirmation page

/**
 * Hide prices on order received (thank you) page
 * Hooked to: wp_head
 */
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


// =========================================================
// SECTION 9: BRAND CATALOGUE SYSTEM
// =========================================================
// Custom post type for managing brand catalogs with PDF uploads
// Includes frontend shortcode display and PDF viewer

/**
 * Register Custom Post Type: mg_brand_catalogue
 * Stores brand catalog posts with associated PDFs
 * Hooked to: init
 */
function mg_register_brand_catalogue_cpt() {
    $labels = array(
        'name'               => __( 'Catalogues de Brand', 'mg' ),
        'singular_name'      => __( 'Catalogue de Brand', 'mg' ),
        'menu_name'          => __( 'Catalogues de Brand', 'mg' ),
        'name_admin_bar'     => __( 'Catalogue de Brand', 'mg' ),
        'add_new'            => __( 'Ajouter Nouveau', 'mg' ),
        'add_new_item'       => __( 'Ajouter Nouveau Catalogue', 'mg' ),
        'new_item'           => __( 'Nouveau Catalogue', 'mg' ),
        'edit_item'          => __( 'Modifier Catalogue', 'mg' ),
        'view_item'          => __( 'Voir Catalogue', 'mg' ),
        'all_items'          => __( 'Tous les Catalogues', 'mg' ),
        'search_items'       => __( 'Rechercher Catalogues', 'mg' ),
        'not_found'          => __( 'Aucun catalogue trouvé.', 'mg' ),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => false,
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
 * Add meta box for catalogue editing
 * Allows admins to select brand and upload PDF
 * Hooked to: add_meta_boxes
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

/**
 * Meta box callback - Display brand selector and PDF uploader
 * Shows dropdown to select brand and button to upload PDF
 */
function mg_catalogue_meta_box_callback( $post ) {
    wp_nonce_field( 'mg_catalogue_meta_save', 'mg_catalogue_meta_nonce' );

    // --- GET STORED META VALUES ---
    $selected_term_id = (int) get_post_meta( $post->ID, '_mg_brand_term_id', true );
    $pdf_attachment_id = (int) get_post_meta( $post->ID, '_mg_brand_pdf', true );
    $pdf_url = $pdf_attachment_id ? wp_get_attachment_url( $pdf_attachment_id ) : '';

    // --- GET ALL BRANDS ---
    $brands = get_terms( array(
        'taxonomy' => 'pwb-brand',
        'hide_empty' => false,
    ) );

    // --- BRAND SELECTOR ---
    echo '<p><label><strong>' . esc_html__( 'Select brand', 'mg' ) . '</strong></label></p>';
    echo '<p><select name="mg_brand_term_id" style="width:100%;">';
    echo '<option value="">' . esc_html__( '-- Sélectionnez une Brand --', 'mg' ) . '</option>';
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

    // --- PDF UPLOADER ---
    echo '<p><label><strong>' . esc_html__( 'Catalogue PDF', 'mg' ) . '</strong></label></p>';
    echo '<p>
        <input type="hidden" id="mg_brand_pdf" name="mg_brand_pdf" value="' . esc_attr( $pdf_attachment_id ) . '">
        <button type="button" class="button" id="mg_upload_pdf_button">' . ( $pdf_attachment_id ? esc_html__( 'Remplacer PDF', 'mg' ) : esc_html__( 'Télécharger PDF', 'mg' ) ) . '</button>
        <span id="mg_pdf_preview" style="margin-left:10px;">' . ( $pdf_url ? '<a href="' . esc_url( $pdf_url ) . '" target="_blank">' . esc_html__( 'Voir PDF actuel', 'mg' ) . '</a>' : esc_html__( 'Aucun fichier sélectionné', 'mg' ) ) . '</span>
        <button type="button" style="margin-left:10px;" class="button" id="mg_remove_pdf_button">' . esc_html__( 'Supprimer', 'mg' ) . '</button>
    </p>';

    // --- JAVASCRIPT FOR MEDIA UPLOADER ---
    ?>
    <script>
    (function($){
        var frame;
        
        // Open media uploader on button click
        $('#mg_upload_pdf_button').on('click', function(e){
            e.preventDefault();
            if ( frame ) frame.open();
            frame = wp.media({
                title: '<?php echo esc_js( "Select or Upload PDF" ); ?>',
                button: { text: '<?php echo esc_js( "Use this PDF" ); ?>' },
                library: { type: '' },
                multiple: false
            });
            
            // Handle selection
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

        // Remove PDF button
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
 * Save catalogue meta data
 * Saves brand selection and PDF attachment ID to post meta
 * Hooked to: save_post_mg_brand_catalogue
 */
function mg_save_catalogue_meta( $post_id, $post ) {
    // --- SECURITY CHECKS ---
    if ( ! isset( $_POST['mg_catalogue_meta_nonce'] ) || ! wp_verify_nonce( $_POST['mg_catalogue_meta_nonce'], 'mg_catalogue_meta_save' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // --- SAVE BRAND SELECTION ---
    if ( isset( $_POST['mg_brand_term_id'] ) ) {
        update_post_meta( $post_id, '_mg_brand_term_id', intval( $_POST['mg_brand_term_id'] ) );
    } else {
        delete_post_meta( $post_id, '_mg_brand_term_id' );
    }

    // --- SAVE PDF ATTACHMENT ID ---
    if ( isset( $_POST['mg_brand_pdf'] ) && $_POST['mg_brand_pdf'] !== '' ) {
        update_post_meta( $post_id, '_mg_brand_pdf', intval( $_POST['mg_brand_pdf'] ) );
    } else {
        delete_post_meta( $post_id, '_mg_brand_pdf' );
    }
}
add_action( 'save_post_mg_brand_catalogue', 'mg_save_catalogue_meta', 10, 2 );

/**
 * Helper function: Get catalogue post by brand term ID
 * Queries catalogue posts by associated brand
 * Returns: WP_Post object or null
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
 * Serve PDF file inline in iframe or browser
 * Handles PDF display via query parameter: ?mg_pdf_viewer=POST_ID
 * Clears output buffers and sends proper headers
 * Hooked to: init at priority 0 (runs before other hooks)
 */
function mg_serve_catalogue_pdf() {
    // Don't run this in admin area
    if ( is_admin() ) {
        return;
    }

    // Check if our PDF viewer query var is set
    if ( ! isset( $_GET['mg_pdf_viewer'] ) ) {
        return;
    }

    $catalogue_post_id = intval( $_GET['mg_pdf_viewer'] );
    
    // --- SECURITY: VERIFY POST EXISTS AND IS CORRECT TYPE ---
    $post = get_post( $catalogue_post_id );
    if ( ! $post || $post->post_type !== 'mg_brand_catalogue' ) {
        header("HTTP/1.0 404 Not Found");
        die('File not found.');
    }

    // --- GET PDF ATTACHMENT ID AND FILE PATH ---
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

    // --- CRITICAL SECTION: PREPARE FOR PDF OUTPUT ---
    
    // 1. Clear all output buffers to prevent conflicts
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }

    // 2. Remove any conflicting headers
    if ( function_exists('header_remove') ) {
        header_remove('Content-Disposition');
        header_remove('Pragma');
        header_remove('Cache-Control');
        header_remove('Expires');
        header_remove('X-Content-Type-Options');
    }

    // --- SET HEADERS FOR INLINE PDF DISPLAY ---
    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: inline; filename="' . basename( $file_path ) . '"' );
    header( 'Content-Length: ' . filesize( $file_path ) );
    header( 'Accept-Ranges: bytes' );
    
    // Caching headers
    header( 'Cache-Control: public, max-age=86400' );
    header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + 86400 ) . ' GMT' );
    
    // Security header
    header( 'X-Content-Type-Options: nosniff' );

    // Clear system buffers one last time
    flush();
    
    // --- SEND PDF FILE ---
    $readfile_success = readfile( $file_path );
    
    if ($readfile_success === false) {
        die('Failed to read file.');
    }
    
    die();
}
add_action( 'init', 'mg_serve_catalogue_pdf', 0 );

/**
 * Shortcode: [mg_catalogue]
 * Frontend display of brand catalogue
 * Shows:
 * - Grid view: List of all brands (catalog page)
 * - Detail view: Single brand with PDF viewer (when ?brand=SLUG)
 * Returns: HTML content
 */
function mg_catalogue_shortcode( $atts ) {
    ob_start();

    $brand_slug = isset( $_GET['brand'] ) ? sanitize_text_field( wp_unslash( $_GET['brand'] ) ) : '';

    // --- DETAIL VIEW: Single Brand with PDF ---
    if ( $brand_slug ) {
        $term = get_term_by( 'slug', $brand_slug, 'pwb-brand' );
        if ( ! $term || is_wp_error( $term ) ) {
            echo '<p>Brand not found.</p>';
            return ob_get_clean();
        }

        $catalogue_post = mg_get_catalogue_by_brand_term_id( $term->term_id );

        echo '<div class="mg-catalogue-detail">';
        echo '<p><a href="' . esc_url( get_permalink() ) . '" style="text-decoration:none; font-weight:bold;">&larr; Retour au catalogue</a></p>';
        echo '<h2 style="margin-bottom:20px;">' . esc_html( $term->name ) . '</h2>';

        if ( $catalogue_post ) {
            $viewer_url = home_url( '?mg_pdf_viewer=' . $catalogue_post->ID );
            
            // Download / Open Button
            echo '<p><a class="mg-download-btn" href="' . esc_url( $viewer_url ) . '" target="_blank" rel="noopener">Ouvrir le PDF (Nouvel onglet)</a></p>';
            
            // PDF Container with Loader and Iframe
            echo '<div class="mg-embed-pdf">';
                
                // Loader (visible initially, hidden when PDF loads)
                echo '<div id="mg-pdf-loader" class="mg-pdf-loader">';
                    echo '<div class="mg-pdf-spinner">';
                        echo '<div class="mg-spinner-ring"></div>';
                        echo '<div class="mg-spinner-ring-inner"></div>';
                    echo '</div>';
                    echo '<p class="mg-loader-text">Chargement du catalogue...</p>';
                echo '</div>';

                // Iframe for PDF display (hidden initially with opacity 0)
                echo '<iframe id="mg-pdf-iframe" src="' . esc_url( $viewer_url ) . '" width="100%" height="100%" frameborder="0" allowfullscreen></iframe>';
            
            echo '</div>';

        } else {
            echo '<div style="padding:20px; background:#f9f9f9; border:1px solid #eee;">Désolé, aucun catalogue disponible pour cette marque pour le moment.</div>';
        }

        echo '</div>';
    } 
    // --- GRID VIEW: List of All Brands ---
    else {
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

                // Get brand logo
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
    }

    // --- STYLES FOR CATALOGUE ---
    ?>
    <style>
    /* --- GRID VIEW STYLES --- */
    
    .mg-catalogue-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(150px,1fr));
        gap: 18px;
        margin: 20px 0;
    }

    .mg-catalogue-card {
        border: 1px solid #eee;
        padding: 12px;
        text-align: center;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .mg-catalogue-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }

    .mg-brand-logo img {
        max-height: 70px;
        object-fit: contain;
        display: block;
        margin: 0 auto 8px;
    }

    .mg-brand-logo-placeholder {
        padding: 24px 6px;
        font-weight: 600;
        color: #333;
    }

    .mg-brand-name {
        font-size: 14px;
        margin-top: 6px;
        color: #333;
        text-decoration: none;
    }

    a.mg-catalogue-link {
        text-decoration: none;
    }

    .mg-download-btn {
        display: inline-block;
        padding: 8px 12px;
        border: 1px solid #ddd;
        background: #f7f7f7;
        border-radius: 6px;
        text-decoration: none;
        color: #333;
        margin-bottom: 12px;
        font-size: 14px;
    }

    .mg-download-btn:hover {
        background: #eee;
    }

    /* --- PDF VIEWER STYLES --- */
    
    .mg-embed-pdf {
        position: relative;
        width: 100%;
        height: 800px;
        background: #f0f0f0;
        border: 1px solid #e5e5e5;
        border-radius: 4px;
        overflow: hidden;
    }

    /* Iframe starts hidden to prevent white flash while loading */
    #mg-pdf-iframe {
        width: 100%;
        height: 100%;
        opacity: 0;
        transition: opacity 0.5s ease-in;
        display: block;
    }

    /* Loader overlay - centered and initially visible */
    .mg-pdf-loader {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: #ffffff;
        z-index: 10;
        transition: opacity 0.5s ease-out, visibility 0.5s;
    }

    /* Hide loader when PDF loads */
    .mg-pdf-loader.hidden {
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
    }

    /* Spinner animation */
    .mg-pdf-spinner {
        position: relative;
        width: 60px;
        height: 60px;
        margin-bottom: 15px;
    }

    .mg-spinner-ring {
        position: absolute;
        top: 0; left: 0; width: 100%; height: 100%;
        border: 4px solid rgba(209, 29, 39, 0.2);
        border-radius: 50%;
    }

    .mg-spinner-ring-inner {
        position: absolute;
        top: 0; left: 0; width: 100%; height: 100%;
        border: 4px solid transparent;
        border-top-color: #D11D27;
        border-radius: 50%;
        animation: mg-spin 1s linear infinite;
    }

    @keyframes mg-spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .mg-loader-text {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        font-size: 14px;
        font-weight: 500;
        color: #555;
    }
    </style>

    <!-- PDF Loading JavaScript -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var iframe = document.getElementById('mg-pdf-iframe');
        var loader = document.getElementById('mg-pdf-loader');

        if (!iframe || !loader) return;

        function onPdfLoaded() {
            // Hide loader and show PDF
            loader.classList.add('hidden');
            iframe.style.opacity = '1';
        }

        // Listen for iframe load event
        iframe.addEventListener('load', onPdfLoaded);

        // Fallback: Force show after 3.5 seconds (for cached PDFs or slow connections)
        setTimeout(onPdfLoaded, 3500);
    });
    </script>
    <?php

    return ob_get_clean();
}
add_shortcode( 'mg_catalogue', 'mg_catalogue_shortcode' );

/**
 * Auto-insert catalogue shortcode on "catalogue" page
 * Automatically adds [mg_catalogue] shortcode to pages with slug "catalogue"
 * Hooked to: the_content at priority 20
 */
function mg_auto_insert_catalogue_shortcode( $content ) {
    if ( is_admin() ) return $content;
    
    if ( is_page() ) {
        global $post;
        if ( ! $post ) return $content;
        
        // Auto-add shortcode if page slug is "catalogue" and shortcode not already present
        if ( 'catalogue' === $post->post_name && ! has_shortcode( $post->post_content, 'mg_catalogue' ) ) {
            return do_shortcode( '[mg_catalogue]' ) . $content;
        }
    }
    return $content;
}
add_filter( 'the_content', 'mg_auto_insert_catalogue_shortcode', 20 );

/**
 * Enqueue WordPress media uploader for catalogue CPT
 * Required for PDF upload functionality in meta box
 * Hooked to: admin_enqueue_scripts
 */
function mg_enqueue_admin_uploader($hook) {
    global $post;
    if (isset($post) && $post->post_type === 'mg_brand_catalogue') {
        wp_enqueue_media();
    }
}
add_action('admin_enqueue_scripts', 'mg_enqueue_admin_uploader');


// =========================================================
// SECTION 10: UTILITY FIXES
// =========================================================
// Various small fixes and cleanups

/**
 * Remove duplicate "Show Password" buttons
 * The password field may have duplicate toggle buttons - this removes static ones
 * Hooked to: wp_footer at priority 999
 */
add_action('wp_footer', function() {
  ?>
  <script>
  (function(){
    function removeStaticPasswordButtons(){
      document.querySelectorAll('.password-input input[type="password"]').forEach(function(input){
        var btn = input.parentElement.querySelector('button.show-password-input');
        if(btn) {
          btn.remove();
        }
      });
    }
    document.addEventListener('DOMContentLoaded', removeStaticPasswordButtons);
    
    // Also observe for dynamic markup changes
    var obs = new MutationObserver(removeStaticPasswordButtons);
    obs.observe(document.body, { childList: true, subtree: true });
  })();
  </script>
  <?php
}, 999);

/**
 * Remove language selector from admin login page
 * Hooked to: login_display_language_dropdown
 */
add_filter( 'login_display_language_dropdown', '__return_false' );


// =========================================================
// SECTION 11: ADMIN MENU CUSTOMIZATION FOR PRODUCTS MANAGER ROLE
// =========================================================
// Restricts admin menu items for users with products_manager role
// Shows only: Orders, Forms, and Products pages

/**
 * Customize admin menu for products_manager role
 * Removes Dashboard, Media, Posts, Comments, Tools, Elementor, WooCommerce menus
 * Adds custom Orders and Forms menus
 * Hooked to: admin_menu at priority 9999
 */
add_action('admin_menu', function() {
    
    // Check if user has products_manager role
    $user = wp_get_current_user();
    if (in_array('products_manager', (array) $user->roles)) {

        // --- PART A: REMOVE STANDARD MENUS ---
        
        remove_menu_page('index.php');           // Dashboard
        remove_menu_page('upload.php');          // Media
        remove_menu_page('edit.php');            // Posts
        remove_menu_page('edit-comments.php');   // Comments
        remove_menu_page('tools.php');           // Tools
        remove_menu_page('edit.php?post_type=elementor_library'); // Elementor
        remove_menu_page('woocommerce-marketing'); // WooCommerce Marketing
        remove_menu_page('woocommerce');         // WooCommerce Main Menu
        remove_menu_page('wc-admin');            // WooCommerce Admin
        remove_menu_page('wc-admin&path=/analytics'); // WooCommerce Analytics
        remove_menu_page('metform-menu');        // Metform

        // Remove WooCommerce submenus
        remove_submenu_page('woocommerce', 'wc-settings');
        remove_submenu_page('woocommerce', 'wc-status');

        // --- PART B: REMOVE STUBBORN MENUS BY SLUG MATCHING ---
        global $menu;
        
        if (!empty($menu)) {
            foreach ($menu as $key => $item) {
                $slug = $item[2];

                // Remove payment menus
                if (strpos($slug, 'PAYMENTS_MENU_ITEM') !== false || strpos($slug, 'tab=checkout') !== false) {
                    unset($menu[$key]);
                }

                // Remove Yoast SEO
                if (strpos($slug, 'wpseo') !== false) {
                    unset($menu[$key]);
                }
            }
        }

        // --- PART C: ADD CUSTOM MENUS ---
        
        // Add "Commandes" (Orders) menu
        add_menu_page(
            'Commandes',
            'Commandes',
            'edit_shop_orders',
            'edit.php?post_type=shop_order',
            '',
            'dashicons-cart',
            6
        );

        // Add "Formulaires" (Form Entries) menu
        add_menu_page(
            'Form Entries',
            'Formulaires',
            'read',
            'edit.php?post_type=metform-entry',
            '',
            'dashicons-email',
            7
        );
    }
}, 9999);

/**
 * Allow products_manager role to edit Metform forms and entries
 * Forces Metform to use standard post capabilities
 * Hooked to: register_post_type_args at priority 999
 */
add_filter( 'register_post_type_args', function( $args, $post_type ) {
    if ( 'metform-form' === $post_type || 'metform-entry' === $post_type ) {
        $args['capability_type'] = 'post';
        $args['map_meta_cap']    = true;
    }
    return $args;
}, 999, 2 );


// =========================================================
// SECTION 12: PRODUCT DETAILS DISPLAY
// =========================================================
// Display ACF product fields on single product page

/**
 * Display product details from ACF fields
 * Shows: Reference, OEM, Designation, Compatible
 * Hooked to: woocommerce_single_product_summary at priority 25
 */
add_action( 'woocommerce_single_product_summary', 'show_product_details_acf', 25 );

function show_product_details_acf() {
    if( function_exists('get_field') ) {
        echo '<div class="product-details-table">';
        echo '<p><strong>Référence:</strong> ' . get_field('reference') . '</p>';
        echo '<p><strong>OEM:</strong> ' . get_field('oem') . '</p>';
        echo '<p><strong>Désignation:</strong> ' . get_field('designation') . '</p>';
        echo '<p><strong>Compatible:</strong> ' . get_field('compatible') . '</p>';
        echo '</div>';
    }
}

?>
