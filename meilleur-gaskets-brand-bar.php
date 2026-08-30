<?php

/*
Plugin Name: Meilleur Gaskets Brand Bar
Description: Displays a dynamic car brand logo bar above the WooCommerce shop page and categories with drag-to-scroll functionality. Supports bidirectional brand and category filtering with checkbox category widget. Includes secure PDF Catalogue Viewer.
Version: 2.3
Author: Houssaini Slimen
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// =========================================================
// SECTION 1: BRAND BAR - FRONTEND DISPLAY & FUNCTIONALITY
// =========================================================
// Displays a horizontal scrollable bar of brand logos on shop/category pages
// with infinite auto-scroll, drag-to-scroll, and filtering

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
        // Left arrow button
        echo '<button class="mg-brand-arrow mg-brand-arrow-left" id="mgBrandArrowLeft" aria-label="Scroll brands left">&#10094;</button>';
        
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
            // FIX: Added draggable="false" to the anchor tag
            echo '<a class="mg-brand-item" draggable="false" href="'. esc_url( $brand_link ) .'">';
            if ( $brand_img ) {
                // The image itself already has draggable="false" added below
                echo str_replace( '<img', '<img draggable="false"', $brand_img );
            } else {
                echo esc_html( $brand->name );
            }
            echo '</a>';
        }
    }

    echo '</div>'; // .mg-brand-bar-scroll
    
    echo '<button class="mg-brand-arrow mg-brand-arrow-right" id="mgBrandArrowRight" aria-label="Scroll brands right">&#10095;</button>';
    
    echo '</div>'; // .mg-brand-bar-wrapper
}
add_action( 'woocommerce_before_main_content', 'mg_display_brand_bar', 5 );

/**
 * Enqueue styles and INFINITE SCROLL JavaScript for brand bar
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

    /* Brand bar wrapper - handles overflow and spacing with arrow buttons */
    .mg-brand-bar-wrapper {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 30px;
        padding-bottom: 10px;
        user-select: none;
        position: relative;
    }

    /* Scrollable container for brand logos */
    .mg-brand-bar-scroll {
        display: flex;
        flex-wrap: nowrap;
        gap: 20px;
        overflow-x: hidden; /* Hidden scrollbar for auto-scroll aesthetic */
        overflow-y: hidden;
        flex: 1;
        white-space: nowrap;
        cursor: grab;
        /* Ensure hardware acceleration for smoother animation */
        -webkit-overflow-scrolling: touch;
        /* FIX: Prevent text selection/link dragging on different browsers */
        user-select: none;
        -webkit-user-select: none;
        -moz-user-select: none;
    }
    
    .mg-brand-bar-scroll.active {
        cursor: grabbing;
    }

    /* Brand logo image sizing */
    .mg-brand-item img {
        max-height: 60px;
        object-fit: contain;
        display: block;
        -webkit-user-drag: none;
        user-drag: none;
        pointer-events: none; /* Already prevents image click/drag */
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

    /* Arrow button styling */
    .mg-brand-arrow {
        flex-shrink: 0;
        background-color: #f0f0f0;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 8px 12px;
        font-size: 18px;
        cursor: pointer;
        transition: all 0.2s ease;
        user-select: none;
        display: flex;
        align-items: center;
        justify-content: center;
        min-width: 40px;
        height: 40px;
        z-index: 2;
    }

    .mg-brand-arrow:hover {
        background-color: #e0e0e0;
        border-color: #999;
    }
    </style>';

    // --- JAVASCRIPT: INFINITE LOOP, AUTO-SCROLL, DRAG ---
    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        const slider = document.getElementById("mgBrandScroll");
        const leftArrow = document.getElementById("mgBrandArrowLeft");
        const rightArrow = document.getElementById("mgBrandArrowRight");
        
        // --- CONFIGURATION ---
        const speed = 0.5; // Auto-scroll speed
        const arrowStep = 300; // Pixels to scroll on arrow click
        
        // --- 1. SETUP INFINITE LOOP (CLONING) ---
        const items = Array.from(slider.children);
        
        // <CHANGE> Calculate original width BEFORE cloning
        let cycleWidth = slider.scrollWidth;
        
        // Clone once
        items.forEach(item => {
            const clone = item.cloneNode(true);
            clone.setAttribute("aria-hidden", "true");
            slider.appendChild(clone);
        });

        // State variables
        let isPaused = false;
        let isDragging = false;
        let animationId;

        // --- 2. AUTO SCROLL ENGINE ---
        function animateScroll() {
            if (!isPaused && !isDragging) {
                slider.scrollLeft += speed;
                // Seamless Reset: If we pass the halfway point, jump back to start
                if (slider.scrollLeft >= cycleWidth) {
                   slider.scrollLeft -= cycleWidth;
                }
            }
            animationId = requestAnimationFrame(animateScroll);
        }
        
        // Start the loop
        animationId = requestAnimationFrame(animateScroll);

        // --- 3. PAUSE ON HOVER ---
        slider.addEventListener("mouseenter", () => isPaused = true);
        slider.addEventListener("mouseleave", () => isPaused = false);
        
        leftArrow.addEventListener("mouseenter", () => isPaused = true);
        leftArrow.addEventListener("mouseleave", () => isPaused = false);
        rightArrow.addEventListener("mouseenter", () => isPaused = true);
        rightArrow.addEventListener("mouseleave", () => isPaused = false);

        // --- 4. ARROW NAVIGATION (FIXED) ---
        
        // Right Arrow: Scrolls forward with seamless wrapping
        rightArrow.addEventListener("click", function() {
            let newPos = slider.scrollLeft + arrowStep;
            
            // <CHANGE> Wrap seamlessly when reaching cycle boundary
            if (newPos >= cycleWidth) {
                newPos = newPos % cycleWidth;
            }
            
            slider.scrollLeft = newPos;
        });
        
        // Left Arrow: Scrolls backward with seamless wrapping
        leftArrow.addEventListener("click", function() {
            let newPos = slider.scrollLeft - arrowStep;
            
            // <CHANGE> Wrap seamlessly when going below zero
            if (newPos < 0) {
                newPos = cycleWidth + (newPos % cycleWidth);
            }
            
            slider.scrollLeft = newPos;
        });

        // --- 5. DRAG TO SCROLL (TOUCH & MOUSE) ---
        let isDown = false;
        let startX;
        let startScrollLeft;

        slider.addEventListener("mousedown", (e) => {
            isDown = true;
            isDragging = true;
            slider.classList.add("active");
            startX = e.pageX - slider.offsetLeft;
            startScrollLeft = slider.scrollLeft;
        });

        slider.addEventListener("mouseleave", () => {
            isDown = false;
            isDragging = false;
            slider.classList.remove("active");
        });

        slider.addEventListener("mouseup", () => {
            isDown = false;
            isDragging = false;
            slider.classList.remove("active");
        });

        slider.addEventListener("mousemove", (e) => {
            if(!isDown) return;
            e.preventDefault();
            const x = e.pageX - slider.offsetLeft;
            const walk = (x - startX) * 1.5;
            slider.scrollLeft = startScrollLeft - walk;
        });

        // Touch support
        let startTouchX = 0;
        slider.addEventListener("touchstart", (e) => {
            startTouchX = e.touches[0].pageX;
            startScrollLeft = slider.scrollLeft;
            isDragging = true;
        });

        slider.addEventListener("touchend", () => {
            isDragging = false;
        });

        slider.addEventListener("touchmove", (e) => {
            const x = e.touches[0].pageX;
            const walk = (x - startTouchX) * 1.5;
            slider.scrollLeft = startScrollLeft - walk;
        });
        
        // Prevent clicking links while dragging
        slider.querySelectorAll("a").forEach(a => {
            a.addEventListener("click", (e) => {
                if (isDragging) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
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
        accent-color: #D11D27;
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
// UPDATED: Prices are VISIBLE on Single Product main listing, but HIDDEN in Related Products.

/**
 * Control the numeric price value for sorting and logic.
 * Returns the actual price if on a Single Product Page or Admin.
 * Returns 0 everywhere else (Shop, Archives) to prevent sorting/logic exposure.
 * Hooked to: woocommerce_product_get_price and woocommerce_product_get_regular_price
 */
function mg_conditional_price_value( $price, $product ) {
    // Always return real price in Admin dashboard
    if ( is_admin() ) {
        return $price;
    }

    // Return real price on the Single Product Page (for main and related items)
    // We handle the visual hiding of related items in the HTML filter below.
    if ( is_product() ) {
        return $price;
    }

    // Default: Return 0 for Shop, Categories, Search, etc.
    return 0;
}
add_filter( 'woocommerce_product_get_price', 'mg_conditional_price_value', 10, 2 );
add_filter( 'woocommerce_product_get_regular_price', 'mg_conditional_price_value', 10, 2 );

/**
 * Control the visual HTML output of the price (e.g., "$100.00").
 * Returns the price HTML ONLY for the Main Product on the Single Product Page.
 * Hides price for Related Products, Upsells, and other pages (Shop/Category).
 * Hooked to: woocommerce_get_price_html
 */
function mg_conditional_price_html( $price_html, $product ) {
    // Always show price HTML in Admin dashboard
    if ( is_admin() ) {
        return $price_html;
    }

    // Logic for Single Product Page
    if ( is_product() ) {
        // Get the ID of the main product being viewed
        $main_product_id = get_queried_object_id();
        $current_product_id = $product->get_id();

        // 1. If this is the Main Product, show the price
        if ( $current_product_id === $main_product_id ) {
            return $price_html;
        }

        // 2. If this is a Variation of the Main Product, show the price
        if ( $product->is_type('variation') && $product->get_parent_id() === $main_product_id ) {
            return $price_html;
        }

        // 3. Otherwise (Related Products, Cross-sells, Upsells), hide the price
        return '';
    }

    // Default: Return empty string to hide price text on Shop, Categories, Search, etc.
    return '';
}
add_filter( 'woocommerce_get_price_html', 'mg_conditional_price_html', 10, 2 );

/**
 * Hide prices specifically in the Cart items.
 * Even if a product has a price, we hide it in the cart summary line items.
 * Hooked to: woocommerce_cart_item_price, woocommerce_cart_item_subtotal
 */
add_filter( 'woocommerce_cart_item_price', '__return_empty_string' );
add_filter( 'woocommerce_cart_item_subtotal', '__return_empty_string' );

/**
 * Disable payment processing and coupon functionality.
 * Ensures the site functions as a catalog/quote system rather than a direct store.
 * Hooked to: woocommerce_cart_needs_payment and woocommerce_coupons_enabled
 */
add_filter( 'woocommerce_cart_needs_payment', '__return_false' );
add_filter( 'woocommerce_coupons_enabled', '__return_false' );

/**
 * Hide the total cost at the bottom of the cart.
 * Hooked to: woocommerce_cart_total
 */
add_filter( 'woocommerce_cart_total', function($value) {
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
// SECTION 9: BRAND CATALOGUE SYSTEM (SECURE VIEWER)
// =========================================================
// Custom post type for managing brand catalogs with PDF uploads
// Includes frontend shortcode display, Secure PDF.js viewer, and grid layout

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
 * Serve PDF file strictly for PDF.js to fetch
 * Handles PDF display via query parameter: ?mg_pdf_viewer=POST_ID
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

    // --- SECURITY: Block direct URL access ---
    if ( ! isset( $_SERVER['HTTP_REFERER'] ) || strpos( $_SERVER['HTTP_REFERER'], home_url() ) === false ) {
        header("HTTP/1.0 403 Forbidden");
        die('Accès refusé. Veuillez consulter le catalogue via le site web.');
    }

    $catalogue_post_id = intval( $_GET['mg_pdf_viewer'] );
    $post = get_post( $catalogue_post_id );
    
    if ( ! $post || $post->post_type !== 'mg_brand_catalogue' ) {
        header("HTTP/1.0 404 Not Found");
        die('File not found.');
    }

    $att_id = intval( get_post_meta( $catalogue_post_id, '_mg_brand_pdf', true ) );
    $file_path = get_attached_file( $att_id );

    if ( ! $file_path || ! file_exists( $file_path ) ) {
        header("HTTP/1.0 404 Not Found");
        die('PDF file missing.');
    }

    // --- FIX FOR LARGE FILES: INCREASE LIMITS ---
    @set_time_limit(600); // 10 minutes max for slow connections
    if(function_exists('ini_set')) {
        ini_set('memory_limit', '512M'); // Ensure server has room to stream
    }

    // --- PREPARE FOR OUTPUT ---
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }

    if ( function_exists('header_remove') ) {
        header_remove('Content-Disposition');
        header_remove('Pragma');
        header_remove('Cache-Control');
        header_remove('X-Content-Type-Options');
    }

    $size = filesize($file_path);

    // --- HEADERS FOR STREAMING ---
    header("Access-Control-Allow-Origin: " . home_url());
    header("Access-Control-Allow-Methods: GET");
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
    header('Accept-Ranges: bytes'); // CRITICAL: Allows browser to request chunks
    header('Content-Length: ' . $size);
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');

    // --- SMART STREAMING LOGIC ---
    // This allows the browser to request specific parts of the 80MB file
    if (isset($_SERVER['HTTP_RANGE'])) {
        list($a, $range) = explode("=", $_SERVER['HTTP_RANGE'], 2);
        list($range) = explode(",", $range, 1);
        list($from, $to) = explode("-", $range, 2);
        if ($to === "") $to = $size - 1;
        if ($from === "") $from = 0;
        
        $new_length = $to - $from + 1;
        header("HTTP/1.1 206 Partial Content");
        header("Content-Range: bytes $from-$to/$size");
        header("Content-Length: $new_length");
        
        $fp = fopen($file_path, "rb");
        fseek($fp, $from);
        
        $buffer = 1024 * 8;
        while(!feof($fp) && ($p = ftell($fp)) <= $to) {
            if ($p + $buffer > $to) $buffer = $to - $p + 1;
            echo fread($fp, $buffer);
            flush();
        }
        fclose($fp);
    } else {
        // Normal full file output
        readfile($file_path);
    }
    
    die();
}
add_action( 'init', 'mg_serve_catalogue_pdf', 0 );

/**
 * Shortcode: [mg_catalogue]
 * Frontend display of brand catalogue with PDF.js secure rendering
 */
function mg_catalogue_shortcode( $atts ) {
    ob_start();

    $brand_slug = isset( $_GET['brand'] ) ? sanitize_text_field( wp_unslash( $_GET['brand'] ) ) : '';

    // --- DETAIL VIEW: Single Brand with Secure PDF.js Viewer ---
    if ( $brand_slug ) {
        $term = get_term_by( 'slug', $brand_slug, 'pwb-brand' );
        if ( ! $term || is_wp_error( $term ) ) {
            echo '<p>Brand not found.</p>';
            return ob_get_clean();
        }

        $catalogue_post = mg_get_catalogue_by_brand_term_id( $term->term_id );

        // SECURITY: oncontextmenu blocks right clicks on the entire viewer wrapper
        echo '<div class="mg-catalogue-detail" oncontextmenu="return false;">';
        echo '<p><a href="' . esc_url( get_permalink() ) . '" style="text-decoration:none; font-weight:bold;">&larr; Retour au catalogue</a></p>';
        echo '<h2 style="margin-bottom:20px;">' . esc_html( $term->name ) . '</h2>';

        if ( $catalogue_post ) {
            $viewer_url = home_url( '?mg_pdf_viewer=' . $catalogue_post->ID );
            
            // PDF Container
            echo '<div class="mg-embed-pdf" id="mg-pdf-container" oncontextmenu="return false;">';
                
                // SECURITY: Transparent Shield blocks interaction
                echo '<div class="mg-pdf-shield"></div>';

                // --- IMPROVED LOADER WITH PROGRESS BAR ---
                echo '<div id="mg-pdf-loader" class="mg-pdf-loader">';
                    echo '<div class="mg-pdf-spinner">';
                        echo '<div class="mg-spinner-ring"></div>';
                        echo '<div class="mg-spinner-ring-inner"></div>';
                    echo '</div>';
                    echo '<p class="mg-loader-text">Chargement du catalogue...</p>';
                    
                    // Progress Bar UI
                    echo '<div class="mg-progress-wrapper">';
                        echo '<div id="mg-pdf-progress-bar" style="width: 0%;"></div>';
                    echo '</div>';
                    echo '<small id="mg-pdf-percent" style="margin-top:10px; color:#666; font-family:sans-serif;">0%</small>';
                echo '</div>';

                // Container where PDF canvases will be drawn
                echo '<div id="mg-pdf-render-area"></div>';
            
            echo '</div>';

            // --- LOAD PDF.JS AND RENDER SCRIPT ---
            wp_enqueue_script( 'pdfjs-lib', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js', array(), '3.4.120', true );
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // 1. Wait for PDF.js library to load
                const checkPdfJs = setInterval(function() {
                    const lib = window['pdfjs-dist/build/pdf'] || window.pdfjsLib;
                    if (lib) {
                        clearInterval(checkPdfJs);
                        initPDF(lib);
                    }
                }, 100);

                // 2. MAIN INITIALIZATION FUNCTION (This was missing!)
                function initPDF(pdfjs) {
                    pdfjs.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';
                    
                    const pdfUrl = '<?php echo esc_js($viewer_url); ?>';
                    const container = document.getElementById('mg-pdf-render-area');
                    const loader = document.getElementById('mg-pdf-loader');
                    const progressBar = document.getElementById('mg-pdf-progress-bar');
                    const percentText = document.getElementById('mg-pdf-percent');

                    // Configure loading task for large files (80MB+)
                    const loadingTask = pdfjs.getDocument({
                        url: pdfUrl,
                        rangeChunkSize: 65536 * 16, 
                        disableAutoFetch: false,
                        disableStream: false, 
                    });

                    // Update Progress Bar
                    loadingTask.onProgress = function(progress) {
                        if (progress.total > 0) {
                            const percent = Math.round((progress.loaded / progress.total) * 100);
                            progressBar.style.width = percent + '%';
                            percentText.innerHTML = percent + '% (' + (progress.loaded / 1024 / 1024).toFixed(1) + ' MB / ' + (progress.total / 1024 / 1024).toFixed(1) + ' MB)';
                        }
                    };

                    loadingTask.promise.then(function(pdfDoc) {
                        renderPages(pdfDoc, container, loader);
                    }).catch(function(error) {
                        loader.innerHTML = `
                            <div style="text-align:center; padding:20px;">
                                <p style="color:#D11D27; font-weight:bold;">Erreur de chargement (Fichier volumineux).</p>
                                <button onclick="window.location.reload()" style="padding:10px 20px; background:#D11D27; color:white; border:none; border-radius:5px; cursor:pointer;">Réessayer</button>
                            </div>
                        `;
                        console.error('PDF Load Error:', error);
                    });
                }

                // 3. PAGE RENDERING FUNCTION (With Centering Fix)
                async function renderPages(pdfDoc, container, loader) {
                    for (let num = 1; num <= pdfDoc.numPages; num++) {
                        try {
                            const page = await pdfDoc.getPage(num);
                            const scale = window.innerWidth < 768 ? 1.0 : 1.5; 
                            const viewport = page.getViewport({scale: scale});
                            
                            const canvas = document.createElement('canvas');
                            const ctx = canvas.getContext('2d');
                            canvas.height = viewport.height;
                            canvas.width = viewport.width;

                            // --- STYLING: CENTER THE PAGES ---
                            canvas.style.display = 'block';
                            canvas.style.margin = '0 auto 20px auto';
                            canvas.style.maxWidth = '95%';
                            canvas.style.height = 'auto';
                            canvas.style.boxShadow = '0 4px 10px rgba(0,0,0,0.2)';

                            container.appendChild(canvas);

                            await page.render({ canvasContext: ctx, viewport: viewport }).promise;

                            // Hide loader once the first page appears
                            if (num === 1) {
                                loader.classList.add('hidden');
                            }
                        } catch (err) {
                            console.warn("Render error on page " + num, err);
                        }
                    }
                }

                // 4. SECURITY: Block Shortcuts
                window.addEventListener('keydown', function(e) {
                    if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 's' || e.key === 'u')) {
                        e.preventDefault();
                        alert('Action désactivée.');
                    }
                });
            });
            </script>
            <style>
                /* Progress Bar Styles */
                .mg-progress-wrapper {
                    width: 250px;
                    height: 12px;
                    background: #f0f0f0;
                    border-radius: 10px;
                    margin-top: 15px;
                    overflow: hidden;
                    border: 1px solid #ddd;
                }
                #mg-pdf-progress-bar {
                    height: 100%;
                    background: #D11D27; /* Your Brand Red */
                    width: 0%;
                    transition: width 0.3s ease;
                }
                /* Ensure loader stays on top during progress */
                .mg-pdf-loader {
                    z-index: 100 !important;
                }
            </style>
            <?php

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

    /* --- UPDATED PDF VIEWER STYLES --- */
    .mg-embed-pdf {
        position: relative;
        width: 100%;
        height: 800px;
        background: #9e9e9e; /* Real PDF viewer background color */
        border: 1px solid #e5e5e5;
        border-radius: 4px;
        overflow-y: auto; /* Allows user to scroll the canvases */
        overflow-x: hidden;
        padding: 20px 0;
    }

    /* SECURITY: Makes rendered canvases unclickable */
    #mg-pdf-render-area {
        position: relative;
        pointer-events: none; /* Prevents right-click and dragging on canvases */
        user-select: none;
        -webkit-user-select: none;
        -moz-user-select: none;
    }

    /* SECURITY: The transparent shield overlay */
    .mg-pdf-shield {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 5;
        background: transparent;
        pointer-events: none;
    }

    /* Loader overlay */
    .mg-pdf-loader {
        position: absolute;
        top: 0; left: 0; width: 100%; height: 100%;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        background: #ffffff; z-index: 10;
        transition: opacity 0.5s ease-out, visibility 0.5s;
    }

    .mg-pdf-loader.hidden {
        opacity: 0; visibility: hidden; pointer-events: none;
    }

    .mg-pdf-spinner {
        position: relative; width: 60px; height: 60px; margin-bottom: 15px;
    }

    .mg-spinner-ring {
        position: absolute; top: 0; left: 0; width: 100%; height: 100%;
        border: 4px solid rgba(209, 29, 39, 0.2); border-radius: 50%;
    }

    .mg-spinner-ring-inner {
        position: absolute; top: 0; left: 0; width: 100%; height: 100%;
        border: 4px solid transparent; border-top-color: #D11D27; border-radius: 50%;
        animation: mg-spin 1s linear infinite;
    }

    @keyframes mg-spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .mg-loader-text {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        font-size: 14px; font-weight: 500; color: #555;
    }

    /* SECURITY: Anti-Print Styles */
    @media print {
        .mg-catalogue-detail { display: none !important; }
        body:after {
            content: "L'impression de ce catalogue n'est pas autorisée.";
            display: block; text-align: center; font-size: 24px; color: #D11D27; padding: 50px;
        }
    }
    </style>
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
// SECTION 11: ADMIN MENU CUSTOMIZATION FOR ALL STAFF ROLES
// =========================================================
// Restricts admin menu items for users who are NOT Administrators.
// Gives "Commandes" and "Formulaires" access to Product Managers AND Account Creators.

/**
 * Customize admin menu for non-administrators
 * Removes standard menus (Dashboard, Posts, etc.)
 * Adds custom Orders and Metform menus for all staff roles.
 * Hooked to: admin_menu at priority 9999
 */
add_action('admin_menu', function() {
    

    $user = wp_get_current_user();
    
    // VERIFICATION : Appliquer à tout le monde SAUF l'Administrateur principal
    if ( ! in_array( 'administrator', (array) $user->roles ) ) {

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
        remove_menu_page('metform-menu');        // Masquer le menu Metform par défaut (trop complexe)

        // Remove WooCommerce submenus
        remove_submenu_page('woocommerce', 'wc-settings');
        remove_submenu_page('woocommerce', 'wc-status');

        // --- PART B: REMOVE STUBBORN MENUS BY SLUG ---
        global $menu;
        
        if (!empty($menu)) {
            foreach ($menu as $key => $item) {
                $slug = $item[2];

                // Remove payment menus & Checkout
                if (strpos($slug, 'PAYMENTS_MENU_ITEM') !== false || strpos($slug, 'tab=checkout') !== false) {
                    unset($menu[$key]);
                }

                // Remove Yoast SEO
                if (strpos($slug, 'wpseo') !== false) {
                    unset($menu[$key]);
                }
            }
        }

        // --- PART C: ADD CUSTOM MENUS FOR STAFF ---
        // Cela ajoute les menus pour TOUS les rôles staff (Product Manager, Account Creator, etc.)
        
        // 1. Ajouter "Commandes" (Orders)
        add_menu_page(
            'Commandes',
            'Commandes',
            'read', // 'read' permet à tout le monde de voir le menu (la restriction se fait au clic si besoin)
            'edit.php?post_type=shop_order',
            '',
            'dashicons-cart',
            6
        );

        // 2. Ajouter "Formulaires" (Metform Entries)
        // Maintenant visible pour tous les admins staff, pas seulement le product manager
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
 * Allow staff roles to edit Metform forms and entries
 * Forces Metform to use standard post capabilities so staff can access it
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
 * Shows: Reference, OEM, Designation (translated via ACF), Compatible, Type Véhicule
 * Hooked to: woocommerce_single_product_summary at priority 25
 */
add_action( 'woocommerce_single_product_summary', 'show_product_details_acf', 25 );

function show_product_details_acf() {
    
    // Ensure both ACF and the language detection function exist
    if( function_exists('get_field') && function_exists('hous_is_current_language_english') ) {
        
        $is_english = hous_is_current_language_english();
        
        // --- 1. Designation Logic ---
        if ( $is_english ) {
            $designation_label = 'Designation:';
            $designation_value = get_field('designation_in_english');
            
            // Fallback: Use the original designation if the English ACF field is empty
            if ( empty( $designation_value ) ) {
                $designation_value = get_field('designation');
            }
        } else {
            $designation_label = 'Designation:';
            $designation_value = get_field('designation');
        }

        // --- 2. Start Display ---
        echo '<div class="product-details-table">';
        
        // Reference and OEM (labels will be handled by TranslatePress string translation)
        echo '<p><strong>Reference:</strong> ' . get_field('reference') . '</p>';
        echo '<p><strong>OEM:</strong> ' . get_field('oem') . '</p>';
        
        // Designation (value is custom handled by ACF)
        echo '<p><strong>' . $designation_label . '</strong> ' . $designation_value . '</p>';
        
        // Compatible and Type Véhicule (value will be from the original ACF field)
        echo '<p><strong>Equivalent:</strong> ' . get_field('compatible') . '</p>';
        echo '<p><strong>Type Vehicule:</strong> ' . get_field('type_veicule') . '</p>';
        
        echo '</div>';
    }
}

// =========================================================
// SECTION 13: CUSTOM ENGLISH TITLE FIELD FOR PRODUCTS (ACF) - IMPROVED
// =========================================================
// Uses ACF field "english_title" to show a manual English product title
// on the English site. If the field is empty, TranslatePress automatic
// translation is used as a fallback.
//
// Improvements vs. previous version:
// - Handles front-end only (skips admin & REST requests)
// - More flexible language detection (en, en_US, en_GB...)
// - Applies to 'the_title', 'get_the_title' and WooCommerce product getters
// - Works when templates call $product->get_name()
// =========================================================


// ---------------------------------------------------------
// Helper: return English ACF title for a product ID (or false)
// ---------------------------------------------------------
function hous_acf_get_english_title_for_product( $post_id ) {
    // Ensure ACF function exists
    if ( ! function_exists( 'get_field' ) ) {
        return false;
    }

    // Get ACF field (field name: english_title)
    $english = get_field( 'english_title', $post_id );

    if ( ! empty( $english ) ) {
        return (string) $english;
    }

    return false;
}


// ---------------------------------------------------------
// Helper: detect if current front-end language is English
// (tries TranslatePress first, then falls back to locale)
// ---------------------------------------------------------
function hous_is_current_language_english() {
    // If TranslatePress installed, use it
    if ( function_exists( 'trp_get_current_language' ) ) {
        $lang = trp_get_current_language();
        if ( ! empty( $lang ) && strpos( $lang, 'en' ) === 0 ) {
            return true;
        }
    }

    // Fallback: use get_locale()
    $locale = get_locale();
    if ( ! empty( $locale ) && strpos( $locale, 'en' ) === 0 ) {
        return true;
    }

    return false;
}


// ---------------------------------------------------------
// CORE: Replace title for front-end product contexts if ACF english_title exists
// Attached to the_title and get_the_title (covers most WP templates)
// ---------------------------------------------------------
add_filter( 'the_title', 'hous_acf_override_product_title_english', 20, 2 );
add_filter( 'get_the_title', 'hous_acf_override_product_title_english', 20, 2 );
function hous_acf_override_product_title_english( $title, $post_id ) {

    // Only run on front-end (not admin, not REST)
    if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return $title;
    }

    // Require a valid post id
    $post_id = intval( $post_id );
    if ( $post_id <= 0 ) {
        return $title;
    }

    // Only for products
    if ( get_post_type( $post_id ) !== 'product' ) {
        return $title;
    }

    // Only override when current language is English
    if ( ! hous_is_current_language_english() ) {
        return $title;
    }

    // Get ACF english_title
    $english_title = hous_acf_get_english_title_for_product( $post_id );

    if ( $english_title !== false ) {
        return $english_title;
    }

    // Fallback: return original title (TranslatePress will handle translation)
    return $title;
}


// ---------------------------------------------------------
// WooCommerce: Ensure product API/get_name also respects the ACF english title
// This covers templates and plugins that call $product->get_name()
// ---------------------------------------------------------
add_filter( 'woocommerce_product_get_name', 'hous_acf_override_wc_product_name', 10, 2 );
add_filter( 'woocommerce_product_get_title', 'hous_acf_override_wc_product_name', 10, 2 ); // extra safety
function hous_acf_override_wc_product_name( $name, $product ) {

    // Only run on front-end (not admin, not REST)
    if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return $name;
    }

    // Only run if we have a product object
    if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
        return $name;
    }

    // Only override when current language is English
    if ( ! hous_is_current_language_english() ) {
        return $name;
    }

    $post_id = intval( $product->get_id() );
    if ( $post_id <= 0 ) {
        return $name;
    }

    $english_title = hous_acf_get_english_title_for_product( $post_id );
    if ( $english_title !== false ) {
        return $english_title;
    }

    return $name;
}


// =========================================
// Section 14 : Replace Woostify Account Icon with "Login" for Logged-Out Users
// =========================================

add_action( 'wp_enqueue_scripts', 'mg_replace_account_icon_with_text' );
function mg_replace_account_icon_with_text() {

    if ( is_admin() ) return;

    // Register an empty script to attach inline JS
    wp_register_script( 'mg-account-replace', false );
    wp_enqueue_script( 'mg-account-replace' );

    $login_url = esc_url( wc_get_page_permalink( 'myaccount' ) );

    // Inline JS
    $js = "
    document.addEventListener('DOMContentLoaded', function() {
        try {
            if (document.body.classList.contains('logged-in')) {
                return; // Do nothing if logged-in → keep default icon
            }

            // Woostify selectors
            var acct = document.querySelector('.tools-icon.my-account > a.my-account-icon')
                    || document.querySelector('.tools-icon.my-account a')
                    || document.querySelector('.my-account-icon')
                    || document.querySelector('.tools-icon.my-account');

            if (!acct) return;

            var href = acct.getAttribute('href') ? acct.getAttribute('href') : '" . $login_url . "';

            // New anchor
            var newA = document.createElement('a');
            newA.setAttribute('href', href);
            newA.className = 'tools-icon my-account-icon mg-my-account-text';
            newA.setAttribute('aria-label', 'Login');

            var span = document.createElement('span');
            span.className = 'mg-my-account-text-span';
            span.textContent = 'Login';

            newA.appendChild(span);

            if (acct.tagName.toLowerCase() === 'a') {
                acct.parentNode.replaceChild(newA, acct);
            } else {
                var existingAnchor = acct.querySelector('a');
                if (existingAnchor) {
                    existingAnchor.parentNode.replaceChild(newA, existingAnchor);
                } else {
                    acct.insertBefore(newA, acct.firstChild);
                }
            }
        } catch (e) { console.log('mg account replace error', e); }
    });
    ";

    wp_add_inline_script( 'mg-account-replace', $js );

    // CSS
    wp_register_style( 'mg-account-replace-style', false );
    wp_enqueue_style( 'mg-account-replace-style' );

    $css = "
    /* Only hide the icon when logged-out (safe fix) */
    body:not(.logged-in) .tools-icon.my-account .woostify-svg-icon,
    body:not(.logged-in) .tools-icon.my-account svg {
        display: none !important;
    }

    /* Style login text */
    .mg-my-account-text {
        text-decoration: none;
        display: inline-flex;
        align-items: center;
    }

    .mg-my-account-text-span {
        font-size: 15px;
        color: #2b2b2b;
        line-height: 1;
    }

    /* Hover color */
    .mg-my-account-text:hover .mg-my-account-text-span {
        color: #D11D27 !important;
    }
    ";

    wp_add_inline_style( 'mg-account-replace-style', $css );
}

// =========================================================
// SECTION 15: ADVANCED SEARCH (OEM & REFERENCE)
// =========================================================
// Extends default WordPress search to include custom fields:
// 'oem' and 'reference'. Supports partial matches (e.g., "AB" finds "12345-AB").

/**
 * Join the Post Meta table to the search query.
 * This allows us to look at the custom fields (ACF data) during search.
 */
add_filter('posts_join', 'mg_search_join_meta');
function mg_search_join_meta( $join ) {
    if ( is_search() && ! is_admin() ) {
        global $wpdb;
        // Connect the meta table to the products table
        $join .= " LEFT JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id ";
    }
    return $join;
}

/**
 * Modify the "WHERE" clause to check OEM and Reference.
 * It uses the Logic: (Title Match) OR (OEM Match) OR (Reference Match)
 */
add_filter('posts_where', 'mg_search_where_meta');
function mg_search_where_meta( $where ) {
    if ( is_search() && ! is_admin() ) {
        global $wpdb;
        
        // Get the search keyword (e.g., "AB")
        $term = get_query_var( 's' );
        if ( empty( $term ) ) return $where;

        // Escape the term for security and wrap in % for partial matching
        // %term% means: anything before + term + anything after
        $like = '%' . $wpdb->esc_like( $term ) . '%';

        // We use Regex to insert our condition right after the Title check.
        // This effectively says: If Title matches OR if Meta (OEM/Ref) matches.
        $where = preg_replace(
            "/\(\s*{$wpdb->posts}.post_title\s+LIKE\s*(\'[^\']+\')\s*\)/",
            "({$wpdb->posts}.post_title LIKE $1) OR ( ({$wpdb->postmeta}.meta_key = 'oem' OR {$wpdb->postmeta}.meta_key = 'reference') AND {$wpdb->postmeta}.meta_value LIKE '$like' )",
            $where
        );
    }
    return $where;
}

/**
 * Prevent duplicate results.
 * Because a product might match both Title AND OEM, we ensure it only shows up once.
 */
add_filter('posts_distinct', 'mg_search_distinct');
function mg_search_distinct( $where ) {
    if ( is_search() && ! is_admin() ) {
        return "DISTINCT";
    }
    return $where;
}

// =========================================================
// SECTION 16: DISPLAY REFERENCE IN SHOP LOOP
// =========================================================
// Adds the 'reference' ACF field right after the product title on shop/category pages.

/**
 * Display the product reference (ACF field) on the shop page after the title.
 * Hooked to: woocommerce_after_shop_loop_item_title
 */
function mg_display_product_reference_in_loop() {
    global $product;
    
    // Check if ACF is active and we have a product
    if ( ! function_exists('get_field') || ! $product ) {
        return;
    }
    
    $product_id = $product->get_id();
    $reference = get_field('reference', $product_id);
    
    if ( $reference ) {
        // Output the reference number with a label
        echo '<span class="mg-product-reference">- ' . esc_html($reference) . '</span>';
    }
}
// Hook to display the reference right after the title in the loop (priority 15 is below the default title priority)
add_action( 'woocommerce_after_shop_loop_item_title', 'mg_display_product_reference_in_loop', 15 );

/**
 * Add CSS for the product reference display in the shop loop.
 * Hooked to: wp_head
 */
function mg_style_product_reference() {
    // Only apply CSS on relevant archive pages
    if ( ! is_shop() && ! is_product_category() && ! is_product_taxonomy() && ! is_search() ) {
        return;
    }

    echo '<style>
        .mg-product-reference {
            display: block; /* Forces it onto a new line */
            font-size: 14px;
            font-weight: 500;
            color: #8f8f8f; /* Matches the brand color for clarity */
            margin-top: 5px;
            line-height: 1.2;
            word-break: break-all;
        }
        
        /* Ensure title spacing is tight above the reference */
        .woocommerce ul.products li.product .woocommerce-loop-product__title {
            margin-bottom: 0 !important; 
        }
    </style>';
}
add_action( 'wp_head', 'mg_style_product_reference' );

// =========================================================
// SECTION 17: CATEGORY ENGLISH TITLE OVERRIDE (TRANSLATION FIX)
// =========================================================
// Overrides the WooCommerce Product Category name with a manual ACF field
// when the current language is detected as English.

/**
 * Helper function to get the ACF English category title from the term meta.
 * @param int $term_id The ID of the term.
 * @return string|false The English title or false if not set/ACF not available.
 */
function mg_acf_get_english_category_title( $term_id ) {
    if ( ! function_exists( 'get_field' ) ) {
        return false;
    }
    // We target the taxonomy term using the format 'taxonomy_slug_TERM_ID'
    // This assumes the ACF field is named 'english_category_title'
    $english = get_field( 'english_category_title', 'product_cat_' . $term_id );
    return ( ! empty( $english ) ) ? (string) $english : false;
}

/**
 * Filter to override the category name field (used by widgets and loops).
 * This ensures the name displayed is the ACF title when viewing the English version.
 * Hooked to: get_term_field
 */
add_filter( 'get_term_field', 'mg_override_category_title_english', 20, 3 );
function mg_override_category_title_english( $value, $field, $term ) {

    // Only apply if we are asking for the 'name' field
    if ( $field !== 'name' ) {
        return $value;
    }

    // Only run on front-end and when language is English (uses helper from Section 13)
    // hous_is_current_language_english is assumed to exist from Section 13
    if ( is_admin() || ! function_exists('hous_is_current_language_english') || ! hous_is_current_language_english() ) {
        return $value;
    }

    // Ensure we have a term object or ID
    $term_id = ( is_object( $term ) && isset( $term->term_id ) ) ? $term->term_id : intval( $term );
    
    // Attempt to get the English ACF title
    $english_title = mg_acf_get_english_category_title( $term_id );

    if ( $english_title !== false ) {
        // If the ACF field is set, override the name
        return $english_title;
    }

    // Fallback: return original value (TranslatePress will translate if ACF is empty)
    return $value;
}

/**
 * Filter to override the category name when the full term object is fetched.
 * This is crucial for fixing the title on actual category archive pages (e.g., when is_product_category() is true).
 * Hooked to: get_term
 */
add_filter( 'get_term', 'mg_override_full_category_term_english', 20, 2 );
function mg_override_full_category_term_english( $term, $taxonomy ) {
    
    // Only for product categories
    if ( ! is_object( $term ) || $term->taxonomy !== 'product_cat' ) {
        return $term;
    }

    // Only run on front-end and when language is English (uses helper from Section 13)
    if ( is_admin() || ! function_exists('hous_is_current_language_english') || ! hous_is_current_language_english() ) {
        return $term;
    }

    $english_title = mg_acf_get_english_category_title( $term->term_id );

    if ( $english_title !== false ) {
        // Override the name property of the term object
        $term->name = $english_title;
    }

    return $term;
}

// =========================================================
// SECTION 18: WOOCOMMERCE ADMIN PRODUCTS SEARCH ENHANCEMENT (FINAL FIX)
// =========================================================
// Fixes 'Serialization of Closure' error by replacing $query->set() with standard filters.
// Uses the confirmed keys ('reference', 'oem') and a high-priority filter.

/**
 * Global variable to temporarily hold the search term for the WHERE clause function.
 */
global $mg_admin_search_term;

/**
 * STEP 1: Intercepts the query early to set up the necessary data and remove the default WC filter.
 * Hooked to: pre_get_posts (priority 99 to run last)
 */
function mg_admin_product_search_acf_meta_setup( $query ) {
    global $mg_admin_search_term;

    // 1. Check for necessary conditions: Admin, Main Query, Product Post Type, Search active.
    if ( ! is_admin() || 
         ! $query->is_main_query() || 
         $query->get( 'post_type' ) !== 'product' || 
         ! isset( $_GET['s'] ) || 
         empty( $_GET['s'] ) 
    ) {
        return;
    }
    
    // Get search term and sanitize it
    $mg_admin_search_term = sanitize_text_field( $_GET['s'] );
    
    // 2. Temporarily remove the default WooCommerce search filter to prevent conflict.
    remove_filter( 'posts_search', 'wc_products_admin_search' ); 
    
    // 3. Add our named filter function to modify the WHERE clause.
    // Use a high priority (99) to ensure it runs late.
    add_filter( 'posts_where', 'mg_admin_product_search_acf_meta_where', 99, 1 );

    // 4. Re-add the WooCommerce search filter (if it was defined) immediately, 
    // but only if it exists, and before our custom WHERE runs.
    if ( function_exists( 'wc_products_admin_search' ) ) {
        // Use a priority lower than 99 so it runs before our where clause.
        add_filter( 'posts_search', 'wc_products_admin_search', 50, 2 ); 
    }
}
add_action( 'pre_get_posts', 'mg_admin_product_search_acf_meta_setup', 99 );


/**
 * STEP 2: Modifies the WHERE clause using a NAMED function to avoid the Closure serialization error.
 * Hooked to: posts_where (priority 99)
 */
function mg_admin_product_search_acf_meta_where( $where ) {
    global $wpdb, $mg_admin_search_term;

    // If the term wasn't set in the setup function, bail out.
    if ( empty( $mg_admin_search_term ) ) {
        return $where;
    }

    $search_pattern = sprintf( '%%%s%%', esc_sql( $mg_admin_search_term ) );
    
    // Confirmed keys
    $meta_keys = array( 'reference', 'oem' );
    $meta_key_string = "'" . implode( "','", array_map( 'esc_sql', $meta_keys ) ) . "'";
    
    // Use a subquery to find product IDs that match the meta data.
    $meta_search_condition = $wpdb->prepare(
        " OR (
            {$wpdb->posts}.ID IN (
                SELECT post_id
                FROM {$wpdb->postmeta}
                WHERE meta_key IN ({$meta_key_string})
                AND meta_value LIKE %s
            )
        ) ",
        $search_pattern
    );

    // Append the new search condition to the existing WHERE clause.
    $where .= $meta_search_condition;

    // IMPORTANT: Remove the filter after execution to prevent it from running on subsequent queries.
    remove_filter( 'posts_where', 'mg_admin_product_search_acf_meta_where', 99 );

    return $where;
}


// =========================================================
// SECTION 19: YOU CAN STILL ORDER PRODUCT WITHOUT A PRICE
// =========================================================

// --- PART 1: Make the product purchasable if the price is empty ---
function custom_make_product_purchasable_no_price( $is_purchasable, $product ) {
    // Check if the product price is explicitly set to an empty string.
    // get_price() returns an empty string when no price is set.
    if ( '' === $product->get_price() ) {
        // Return TRUE to force it to be purchasable (shows 'Add to Cart')
        return true; 
    }
    
    // For all other cases (price > 0 or price = 0), use the default check.
    return $is_purchasable;
}
add_filter( 'woocommerce_is_purchasable', 'custom_make_product_purchasable_no_price', 10, 2 );


// --- PART 2: Set the price to 0 in the cart for empty-priced products ---
function custom_set_zero_price_for_empty_products( $cart_object ) {
    // Check to ensure this runs only on the frontend, not in the admin.
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    // Check if the cart is empty or not yet fully loaded
    if ( empty( $cart_object->cart_contents ) ) {
        return;
    }

    // Loop through all items in the cart
    foreach ( $cart_object->get_cart() as $cart_item_key => $cart_item ) {
        /** @var WC_Product $product */
        $product = $cart_item['data'];

        // Use the same check: if the product price is empty
        if ( '' === $product->get_price() ) {
            // Set the price of the product object in the cart to 0
            $cart_item['data']->set_price( 0 );
        }
    }
}
// Hook runs every time WooCommerce calculates the totals (cart/checkout pages)
add_action( 'woocommerce_before_calculate_totals', 'custom_set_zero_price_for_empty_products', 10, 1 );


// =========================================================
// SECTION 20: DYNAMIC RIGHT CLICK DISABLE FOR WOOCOMMERCE
// =========================================================

/**
 * Disables right-click based on the WooCommerce page type:
 * - Shop/Category Pages: Only blocks images.
 * - Single Product Page: Blocks the entire page (most secure against zoom/overlays).
 */
function disable_product_image_right_click_dynamic() {

    // Ensure WooCommerce functions exist
    if ( ! function_exists( 'is_woocommerce' ) ) {
        return;
    }

    // 1. Logic for Single Product Page (Most secure: disables full right-click)
    if ( is_product() ) {
        ?>
        <script type="text/javascript">
            (function($) {
                // Disable Right-Click on the entire body for maximum security against zoom/overlays
                $(document).on('contextmenu', 'body', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                });
                // Also block dragging images on the single product page
                const allSelectors = '.woocommerce-product-gallery, .woocommerce-product-gallery__wrapper, .zoomContainer, .zoomImg, img';
                $(document).on('dragstart', allSelectors, function(e) {
                    e.preventDefault();
                    return false;
                });
            })(jQuery);
        </script>
        <?php
    } 
    
    // 2. Logic for Shop/Category/Tag Pages (Less intrusive: only blocks images)
    else if ( is_shop() || is_product_category() || is_product_tag() ) {
        ?>
        <script type="text/javascript">
            (function($) {
                // Define selectors for images in the shop/loop view
                const shop_image_selectors = '.woocommerce-loop-product__link img, .attachment-woocommerce_thumbnail img, .product-category img';
                
                // Use event delegation on the document body to block only image right-clicks
                $('body').on('contextmenu', shop_image_selectors, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                });
                
                // Block dragging on these images
                $('body').on('dragstart', shop_image_selectors, function(e) {
                    e.preventDefault();
                    return false;
                });
            })(jQuery);
        </script>
        <?php
    }
}

// Ensure the function is hooked to run after jQuery and all scripts are loaded
add_action('wp_footer', 'disable_product_image_right_click_dynamic');

// =========================================================
// SECTION 21: JAVASCRIPT FIX FOR WOOSTIFY/WOOCOMMERCE IMAGE CROPPING (CORRECTED TARGET)
// =========================================================

/**
 * Executes a JavaScript fix to override Woostify/Elementor's fixed image height script
 * by targeting the correct wrapper element.
 */
function fix_woostify_image_cropping_js() {

    // Only apply this fix on product archive pages (Shop, Category, Tag)
    if ( is_shop() || is_product_category() || is_product_tag() ) {
        ?>
        <script type="text/javascript">
            (function() {
                // Wait for the entire page content (including theme scripts) to be fully loaded
                document.addEventListener('DOMContentLoaded', function() {
                    
                    // --- CRITICAL CHANGE: Target the .product-loop-image-wrapper ---
                    const imageContainers = document.querySelectorAll('.product-loop-image-wrapper');
                    
                    // Iterate through each product image container
                    imageContainers.forEach(containerWrapper => {
                        
                        // --- 1. Reset Height of the Container Wrapper ---
                        // Force the container to use auto height and show overflowing content
                        containerWrapper.style.height = 'auto';
                        containerWrapper.style.paddingBottom = '0';
                        containerWrapper.style.overflow = 'visible';
                        
                        // --- 2. Remove the Problematic Class ---
                        // This forcefully deletes the class responsible for the fixed height
                        containerWrapper.classList.remove('has-equal-image-height');
                        
                        // Find the actual image inside the wrapper
                        const img = containerWrapper.querySelector('img');
                        
                        if (img) {
                            // --- 3. Force Image to Contain (No Crop) ---
                            // Guarantee that the WHOLE image fits inside its parent box
                            img.style.objectFit = 'contain';
                            img.style.width = '100%';
                            img.style.height = 'auto'; // Use natural height
                            img.style.maxHeight = '450px'; // Safety max-height
                            img.style.backgroundColor = '#ffffff'; // Fill background with white for clean edges
                        }
                    });
                });
            })();
        </script>
        <?php
    }
}

// Ensure the function runs in the footer, AFTER theme scripts have applied their fixed height
add_action('wp_footer', 'fix_woostify_image_cropping_js');

// =========================================================
// SECTION 22: ADVANCED ADMIN CLEANUP (ELEMENTOR, JEG KIT, TOP BAR)
// =========================================================

/**
 * 1. Redirect Non-Admins from the dashboard to the Products page.
 */
function mg_custom_admin_redirects() {
    if ( is_admin() && ! current_user_can( 'manage_options' ) && ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) ) {
        if ( basename( $_SERVER['PHP_SELF'] ) == 'index.php' ) {
            wp_redirect( admin_url( 'edit.php?post_type=product' ) );
            exit;
        }
    }
}
add_action( 'admin_init', 'mg_custom_admin_redirects' );

/**
 * 2. Remove Sidebar Menus (Elementor, Jeg Kit, Yoast) for non-admins.
 */
function mg_remove_sidebar_menus_for_staff() {
    if ( ! current_user_can( 'manage_options' ) ) {
        // Elementor
        remove_menu_page( 'elementor' );
        remove_menu_page( 'elementor-home' );
        remove_menu_page( 'edit.php?post_type=elementor_library' );
        
        // Jeg Kit & Yoast
        remove_menu_page( 'jkit' );
        remove_menu_page( 'jekit' );
        remove_menu_page( 'jeg-dashboard' );
        remove_menu_page( 'wpseo_dashboard' );
    }
}
add_action( 'admin_menu', 'mg_remove_sidebar_menus_for_staff', 9999 );

/**
 * 3. Remove Items from the TOP ADMIN BAR (Yoast, Jeg Kit, Comments, New, etc.)
 */
function mg_cleanup_top_admin_bar( $wp_admin_bar ) {
    // Only remove for users who are NOT full administrators
    if ( ! current_user_can( 'manage_options' ) ) {
        
        // Remove WordPress Logo
        $wp_admin_bar->remove_node( 'wp-logo' );
        
        // Remove "En ligne" (WooCommerce visibility)
        $wp_admin_bar->remove_node( 'woocommerce-site-visibility-badge' );
        
        // Remove Comments icon
        $wp_admin_bar->remove_node( 'comments' );
        
        // Remove "Créer" (New Content) plus icon
        $wp_admin_bar->remove_node( 'new-content' );
        
        // Remove Yoast & Jeg Kit
        $wp_admin_bar->remove_node( 'wpseo-menu' );
        $wp_admin_bar->remove_node( 'jeg-kit' );
        $wp_admin_bar->remove_node( 'jeg-menu' );
        
        // Remove Elementor
        $wp_admin_bar->remove_node( 'elementor_inspector' );
        $wp_admin_bar->remove_node( 'elementor_edit_page' );
    }
}
add_action( 'admin_bar_menu', 'mg_cleanup_top_admin_bar', 9999 );

/**
 * 4. CSS Safety Net: Hide the IDs to ensure they never flicker or show
 */
function mg_force_hide_css_cleanup() {
    if ( ! current_user_can( 'manage_options' ) ) {
        echo '<style>
            /* Hide Sidebar items */
            #toplevel_page_elementor-home, #toplevel_page_elementor, 
            #toplevel_page_jkit, #toplevel_page_jekit, 
            #toplevel_page_wpseo_dashboard, #menu-comments { 
                display: none !important; 
            }

            /* Hide Top Admin Bar items */
            #wp-admin-bar-wp-logo,
            #wp-admin-bar-woocommerce-site-visibility-badge,
            #wp-admin-bar-comments,
            #wp-admin-bar-new-content,
            #wp-admin-bar-jeg-kit,
            #wp-admin-bar-wpseo-menu { 
                display: none !important; 
            }
        </style>';
    }
}
add_action( 'admin_head', 'mg_force_hide_css_cleanup' );
add_action( 'wp_head', 'mg_force_hide_css_cleanup' ); // Also hides it when they view the site front-end
// =========================================================
// SECTION 22: ADMIN UI CLEANUP (TOP BAR & SIDEBAR)
// =========================================================

/**
 * 1. Clean up Sidebar and Top Bar for Staff Roles
 */
add_action( 'admin_menu', function() {
    $staff_roles = array( 'product_manager', 'accounts_creator' );
    $user = wp_get_current_user();
    if ( ! $user || ! isset($user->roles) ) return;
    
    $is_staff = array_intersect( $staff_roles, (array) $user->roles );

    if ( $is_staff ) {
        // Hide standard clutter from Sidebar
        remove_menu_page( 'elementor' );
        remove_menu_page( 'edit.php?post_type=elementor_library' );
        remove_menu_page( 'jkit' );
        remove_menu_page( 'wpseo_dashboard' );
        remove_menu_page( 'edit-comments.php' );
        remove_menu_page( 'accounts-creator-form' ); 
    }
}, 999 );

/**
 * 2. Remove Items from Top Bar specifically (Home page & Shop page)
 */
add_action( 'admin_bar_menu', function( $wp_admin_bar ) {
    $user = wp_get_current_user();
    if ( ! $user || ! isset($user->roles) ) return;

    $staff_roles = array( 'product_manager', 'accounts_creator' );
    if ( array_intersect( $staff_roles, (array) $user->roles ) ) {
        
        // --- Standard WordPress Items ---
        $wp_admin_bar->remove_node( 'wp-logo' );
        $wp_admin_bar->remove_node( 'comments' );
        $wp_admin_bar->remove_node( 'new-content' );

        // --- Elementor "Modifier avec Elementor" (Home Page fix) ---
        $wp_admin_bar->remove_node( 'elementor_edit_page' );
        
        // --- Woo Variation Swatches "Clear Transient" (Shop Page fix) ---
        $wp_admin_bar->remove_node( 'woo-variation-swatches-clear-transient' );

        // --- WooCommerce specific nodes ---
        $wp_admin_bar->remove_node( 'woocommerce-site-visibility-badge' );
    }
}, 9999 ); // High priority to run after plugins add their buttons

/**
 * 3. CSS Safety Net for frontend/backend
 */
add_action( 'wp_head', 'mg_staff_css_cleanup' );
add_action( 'admin_head', 'mg_staff_css_cleanup' );
function mg_staff_css_cleanup() {
    $staff_roles = array( 'product_manager', 'accounts_creator' );
    $user = wp_get_current_user();
    if ( ! $user || ! isset($user->roles) ) return;

    if ( array_intersect( $staff_roles, (array) $user->roles ) ) {
        echo '<style>
            /* Hide Top Bar items that might linger */
            #wp-admin-bar-elementor_edit_page, 
            #wp-admin-bar-woo-variation-swatches-clear-transient,
            #wp-admin-bar-wp-logo, 
            #wp-admin-bar-new-content,
            #wp-admin-bar-comments { 
                display: none !important; 
            }
        </style>';
    }
}

// =========================================================
// SECTION 23: USER MANAGEMENT (CUSTOMERS ONLY)
// =========================================================

/**
 * 1. USER LIST FILTER: Only show "Customer" accounts to the Accounts Creator
 */
add_action('pre_user_query', function($user_search) {
    if ( ! is_admin() ) return;

    $user = wp_get_current_user();
    if ( $user && in_array( 'accounts_creator', (array) $user->roles ) ) {
        global $wpdb;
        $user_search->query_where = str_replace(
            'WHERE 1=1', 
            "WHERE 1=1 AND {$wpdb->users}.ID IN (
                SELECT user_id FROM {$wpdb->usermeta} 
                WHERE meta_key = '{$wpdb->prefix}capabilities' 
                AND meta_value LIKE '%\"customer\"%'
            )", 
            $user_search->query_where
        );
    }
});

/**
 * 2. SCREEN CLEANUP: Hide unnecessary fields and the Role dropdown
 */
add_action('admin_head', function() {
    $user = wp_get_current_user();
    if ( $user && in_array( 'accounts_creator', (array) $user->roles ) ) {
        echo '<style>
            tr.user-role-wrap, .user-rich-editing-wrap, .user-admin-color-wrap, 
            .user-comment-shortcuts-wrap, .user-admin-bar-front-wrap, 
            .user-description-wrap, .user-url-wrap, .user-profile-picture-wrap,
            .subsubsub { 
                display: none !important; 
            }
        </style>';
    }
});

/**
 * 3. RESTRICT ROLES & KILL FRONTEND WARNING
 */
add_filter('editable_roles', function($roles) {
    if ( ! is_array( $roles ) ) {
        $roles = array();
    }
    if ( ! is_admin() ) {
        return $roles;
    }
    $user = wp_get_current_user();
    if ( $user && in_array( 'accounts_creator', (array) $user->roles ) ) {
        $allowed = array( 'customer' );
        $filtered = array();
        foreach ( $roles as $key => $data ) {
            if ( in_array( $key, $allowed ) ) {
                $filtered[$key] = $data;
            }
        }
        return ! empty( $filtered ) ? $filtered : array();
    }
    return $roles;
}, 9999, 1);

// =========================================================
// SECTION 24: ACCOUNT REDIRECTS & RESTRICTIONS
// =========================================================

/**
 * 1. Redirect ALL users to the Home Page after login
 * Instead of going to "My Account", they go straight to the shop/home.
 */
add_filter( 'woocommerce_login_redirect', 'mg_redirect_to_home_after_login', 9999, 2 );
function mg_redirect_to_home_after_login( $redirect, $user ) {
    // If you want Admins to still go to dashboard, you can uncomment this line:
    // if ( in_array( 'administrator', (array) $user->roles ) ) return admin_url();
    
    return home_url();
}

/**
 * 2. Remove "Account Details" (Edit Account) tab from My Account Menu
 * Prevents users from seeing the tab to change their name/password/email.
 */
add_filter( 'woocommerce_account_menu_items', 'mg_remove_edit_account_tab', 999 );
function mg_remove_edit_account_tab( $items ) {
    // Remove "Account details"
    unset( $items['edit-account'] );
    return $items;
}

/**
 * 3. SECURITY: Block direct access to the "Edit Account" endpoint
 * If someone tries to guess the URL (e.g. /my-account/edit-account/), kick them out.
 */
add_action( 'template_redirect', 'mg_block_edit_account_access' );
function mg_block_edit_account_access() {
    // Check if we are on the 'edit-account' endpoint of WooCommerce
    if ( is_wc_endpoint_url( 'edit-account' ) ) {
        // Redirect them back to the main My Account page (or Home)
        wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
        exit;
    }
}

/**
 * 4. CSS: Hide the specific "Edit Account" Icon/Link on the Dashboard
 * This targets the exact HTML structure you provided to make it invisible.
 */
add_action( 'wp_head', 'mg_hide_edit_account_ui_elements' );
function mg_hide_edit_account_ui_elements() {
    if ( ! is_account_page() ) return;
    
    echo '<style>
        /* Hide the specific Dashboard icon link you found */
        .woocommerce-MyAccount-content a[href*="edit-account"],
        /* Hide the sidebar link just in case the PHP filter missed it */
        .woocommerce-MyAccount-navigation-link--edit-account,
        /* Hide any other links pointing to edit-account on this page */
        a[href*="/my-account/edit-account/"] {
            display: none !important;
        }
    </style>';
}


// =========================================================
// SECTION 25: USER PRODUCT TRACKING SYSTEM (v2 - custom table)
// =========================================================
// Tracks which WooCommerce products logged-in customers have viewed.
//
// REFACTOR NOTES (moving off wp_usermeta / serialized arrays):
// - Data now lives in a dedicated table ({$wpdb->prefix}mg_product_tracking)
//   instead of a serialized array under the `_mg_visited_products` usermeta
//   key. A serialized-array column has to be fully unserialized, scanned,
//   re-serialized and rewritten on every single visit, and it can't be
//   queried, sorted or aggregated by MySQL at all — every "top products" /
//   "top brands" report had to be computed in PHP after loading each user's
//   entire history. A relational table with proper indexes lets MySQL do
//   that work instead, and scales to large histories/large user counts.
// - Recording a visit no longer happens synchronously on page load (hooked
//   to `woocommerce_after_single_product`). It happens client-side via
//   `fetch()` against a REST endpoint, so the write never blocks or delays
//   rendering the product page for the shopper.
// - Historical data already stored in `_mg_visited_products` is preserved
//   via the one-time migration routine below; the usermeta itself is left
//   in place (not deleted) so nothing is lost if a rollback is ever needed.

/**
 * Helper: fully-prefixed name of the custom tracking table.
 * Centralised here so every query below stays in sync if the table name
 * ever changes.
 */
function mg_get_tracking_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'mg_product_tracking';
}

/**
 * Helper: fully-prefixed name of the per-product rollup/summary table.
 * One row per product (not per visit), so admin dashboard "top products" /
 * "top brands" queries stay fast no matter how large the raw visit table
 * (mg_get_tracking_table_name()) grows over time.
 */
function mg_get_summary_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'mg_product_visit_summary';
}

/**
 * 1. DATABASE SCHEMA
 * Creates (or upgrades) the custom tracking table via dbDelta().
 *
 * dbDelta() needs the SQL formatted in a very specific way to reliably
 * detect schema changes: each column/key on its own line, two spaces after
 * PRIMARY KEY, and no backticks around the table name. See
 * https://developer.wordpress.org/reference/functions/dbdelta/
 *
 * Registered on the activation hook, and also re-run on `plugins_loaded`
 * whenever the stored schema version doesn't match — dbDelta() is safe to
 * call repeatedly (it only applies the diff), and this keeps sites that
 * update the plugin via SVN without deactivating/reactivating it in sync.
 */
register_activation_hook( __FILE__, 'mg_create_tracking_table' );
add_action( 'plugins_loaded', 'mg_maybe_upgrade_tracking_table' );

// v1.1: added mg_product_visit_summary, the per-product rollup table that
// backs the "Produits Populaires" dashboard (see mg_backfill_summary_table()).
define( 'MG_TRACKING_DB_VERSION', '1.1' );

function mg_create_tracking_table() {
    global $wpdb;

    $table_name      = mg_get_tracking_table_name();
    $summary_table   = mg_get_summary_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    // dbDelta() accepts multiple CREATE TABLE statements in one string.
    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        brand_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        visit_count INT UNSIGNED NOT NULL DEFAULT 1,
        last_visited DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_product (user_id, product_id),
        KEY user_id (user_id),
        KEY brand_id (brand_id),
        KEY product_id (product_id)
    ) {$charset_collate};
    CREATE TABLE {$summary_table} (
        product_id BIGINT UNSIGNED NOT NULL,
        brand_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        total_visits BIGINT UNSIGNED NOT NULL DEFAULT 0,
        unique_visitors BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_visited DATETIME NOT NULL,
        PRIMARY KEY  (product_id),
        KEY brand_id (brand_id),
        KEY total_visits (total_visits)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Recompute the summary table from whatever is already in the raw
    // table. Cheap on a fresh install (raw table is empty) and, on sites
    // upgrading from v1.0, this is what backfills summary rows for visits
    // that were recorded before the summary table existed. Safe to re-run:
    // it's an authoritative recompute (ON DUPLICATE KEY UPDATE), not an
    // increment, so running it twice can't double-count anything.
    mg_backfill_summary_table();

    update_option( 'mg_tracking_db_version', MG_TRACKING_DB_VERSION );
}

function mg_maybe_upgrade_tracking_table() {
    if ( get_option( 'mg_tracking_db_version' ) !== MG_TRACKING_DB_VERSION ) {
        mg_create_tracking_table();
    }
}

/**
 * Recompute mg_product_visit_summary from mg_product_tracking in one pass.
 * Runs automatically on activation/schema upgrade; safe to call any time
 * (e.g. from WP-CLI) if the two tables ever need to be resynced.
 */
function mg_backfill_summary_table() {
    global $wpdb;

    $raw_table     = mg_get_tracking_table_name();
    $summary_table = mg_get_summary_table_name();

    $wpdb->query(
        "INSERT INTO {$summary_table} (product_id, brand_id, total_visits, unique_visitors, last_visited)
         SELECT product_id, brand_id, SUM(visit_count), COUNT(DISTINCT user_id), MAX(last_visited)
         FROM {$raw_table}
         GROUP BY product_id
         ON DUPLICATE KEY UPDATE
            brand_id        = VALUES(brand_id),
            total_visits    = VALUES(total_visits),
            unique_visitors = VALUES(unique_visitors),
            last_visited    = VALUES(last_visited)"
    );
}

/**
 * Shared write path for recording a product visit — used by both the live
 * REST endpoint (one visit at a time) and the historical migration (which
 * can import a visit_count greater than 1 from old usermeta data).
 *
 * No cap on how many distinct products a user can accumulate here: unlike
 * the old serialized-array approach, each visit is a single indexed UPSERT,
 * so it costs the same whether this is a user's 5th product or their 5,000th.
 *
 * @param int    $user_id
 * @param int    $product_id
 * @param int    $brand_id
 * @param int    $visit_delta      How many visits to add (1 for a live visit).
 * @param string $mysql_timestamp  MySQL datetime string; defaults to now.
 * @return bool Whether this (user_id, product_id) pair was newly created
 *              (i.e. counts as a "unique visitor" of this product).
 */
function mg_record_product_visit( $user_id, $product_id, $brand_id, $visit_delta = 1, $mysql_timestamp = null ) {
    global $wpdb;

    $user_id         = absint( $user_id );
    $product_id      = absint( $product_id );
    $brand_id        = absint( $brand_id );
    $visit_delta     = max( 1, absint( $visit_delta ) );
    $mysql_timestamp = $mysql_timestamp ? $mysql_timestamp : current_time( 'mysql' );

    $raw_table = mg_get_tracking_table_name();

    // MySQL's affected-rows count for INSERT ... ON DUPLICATE KEY UPDATE
    // tells us, for free, whether this (user, product) pair is brand new
    // (1 row affected = INSERT) or already existed (2 rows affected =
    // UPDATE) — no extra SELECT needed just to know whether to count this
    // as a new "unique visitor" of the product.
    $affected = $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO {$raw_table} (user_id, product_id, brand_id, visit_count, last_visited)
             VALUES (%d, %d, %d, %d, %s)
             ON DUPLICATE KEY UPDATE
                visit_count   = visit_count + VALUES(visit_count),
                brand_id      = VALUES(brand_id),
                last_visited  = GREATEST(last_visited, VALUES(last_visited))",
            $user_id,
            $product_id,
            $brand_id,
            $visit_delta,
            $mysql_timestamp
        )
    );

    $is_new_visitor = ( 1 === (int) $affected );

    $summary_table = mg_get_summary_table_name();

    // Second, tiny upsert against the one-row-per-product summary table.
    $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO {$summary_table} (product_id, brand_id, total_visits, unique_visitors, last_visited)
             VALUES (%d, %d, %d, %d, %s)
             ON DUPLICATE KEY UPDATE
                total_visits    = total_visits + VALUES(total_visits),
                unique_visitors = unique_visitors + %d,
                brand_id        = VALUES(brand_id),
                last_visited    = GREATEST(last_visited, VALUES(last_visited))",
            $product_id,
            $brand_id,
            $visit_delta,
            1, // first-ever row for this product = definitionally 1 unique visitor so far
            $mysql_timestamp,
            $is_new_visitor ? 1 : 0
        )
    );

    return $is_new_visitor;
}

/**
 * 2. ASYNCHRONOUS TRACKING — REST endpoint
 * A small, purpose-built endpoint that only does one thing: record that the
 * current user viewed one product. Namespaced under `meilleur-gaskets/v1`
 * so it can't collide with other plugins.
 */
add_action( 'rest_api_init', 'mg_register_tracking_rest_route' );
function mg_register_tracking_rest_route() {
    register_rest_route(
        'meilleur-gaskets/v1',
        '/track',
        array(
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => 'mg_rest_track_visit',
            'permission_callback' => 'mg_rest_track_permission_check',
            'args'                => array(
                'product_id' => array(
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function ( $value ) {
                        return is_numeric( $value ) && absint( $value ) > 0;
                    },
                ),
            ),
        )
    );
}

/**
 * Permission check for the tracking endpoint.
 *
 * Security note on nonces: for cookie-authenticated REST requests, WordPress
 * core already verifies the `X-WP-Nonce` header against the 'wp_rest' action
 * before this callback ever runs (see `rest_cookie_check_errors()` in
 * wp-includes/rest-api.php) — a missing/invalid nonce gets rejected with a
 * 403 automatically, as long as the request goes through a logged-in
 * session cookie, which is the case here. We only need to additionally
 * confirm there IS a logged-in user, since anonymous visits aren't tracked.
 */
function mg_rest_track_permission_check( WP_REST_Request $request ) {
    return is_user_logged_in();
}

/**
 * REST callback: upsert one visit row for the current user/product.
 */
function mg_rest_track_visit( WP_REST_Request $request ) {
    global $wpdb;

    $user_id    = get_current_user_id();
    $product_id = absint( $request->get_param( 'product_id' ) );

    if ( ! $user_id || ! $product_id ) {
        return new WP_Error( 'mg_invalid_request', __( 'Invalid tracking payload.', 'meilleur-gaskets' ), array( 'status' => 400 ) );
    }

    // Never trust the ID blindly: confirm it's a real, published WooCommerce
    // product before writing anything.
    $product = wc_get_product( $product_id );
    if ( ! $product || ! is_a( $product, 'WC_Product' ) || 'publish' !== get_post_status( $product_id ) ) {
        return new WP_Error( 'mg_invalid_product', __( 'Unknown product.', 'meilleur-gaskets' ), array( 'status' => 404 ) );
    }

    // The brand is derived server-side from the product's own taxonomy
    // terms rather than accepted from the client. Trusting a client-supplied
    // brand_id would let anyone attribute visits to an arbitrary brand.
    $brand_id = 0;
    $terms    = wp_get_post_terms( $product_id, 'pwb-brand', array( 'fields' => 'ids' ) );
    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
        $brand_id = absint( $terms[0] );
    }

    // Shared write path: upserts both the per-user row and the per-product
    // summary row that the "Produits Populaires" dashboard reads from.
    mg_record_product_visit( $user_id, $product_id, $brand_id, 1 );

    if ( $wpdb->last_error ) {
        return new WP_Error( 'mg_db_error', __( 'Could not record visit.', 'meilleur-gaskets' ), array( 'status' => 500 ) );
    }

    return new WP_REST_Response( array( 'tracked' => true ), 200 );
}

/**
 * 2b. ASYNCHRONOUS TRACKING — front-end JavaScript
 * Fires the tracking request from the browser after the single product page
 * has already rendered, so it can never delay or block page load the way
 * the old server-side `woocommerce_after_single_product` hook could.
 */
add_action( 'wp_enqueue_scripts', 'mg_enqueue_tracking_script' );
function mg_enqueue_tracking_script() {
    if ( ! is_product() || ! is_user_logged_in() ) {
        return;
    }

    global $product;
    if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
        return;
    }

    // This snippet is tiny and page-specific, so it's added as inline JS
    // rather than shipping a separate .js asset. wp_register_script() with
    // an empty src just gives wp_add_inline_script() a handle to attach to.
    wp_register_script( 'mg-product-tracking', '', array(), '1.0', true );
    wp_enqueue_script( 'mg-product-tracking' );

    wp_localize_script(
        'mg-product-tracking',
        'mgTracking',
        array(
            'restUrl'   => esc_url_raw( rest_url( 'meilleur-gaskets/v1/track' ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'productId' => $product->get_id(),
        )
    );

    $inline_js = <<<'JS'
( function () {
    if ( ! window.mgTracking || ! window.fetch ) {
        return;
    }

    // Fire-and-forget: `keepalive: true` lets the browser finish sending
    // this request even if the shopper navigates away immediately, and
    // fetch() never blocks page rendering the way a synchronous request
    // (or the old PHP hook) did. Failures are swallowed on purpose —
    // tracking must never surface an error or affect the shopping experience.
    window.fetch( mgTracking.restUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': mgTracking.nonce
        },
        credentials: 'same-origin',
        keepalive: true,
        body: JSON.stringify( { product_id: mgTracking.productId } )
    } ).catch( function () {} );
} )();
JS;

    wp_add_inline_script( 'mg-product-tracking', $inline_js );
}

/**
 * 3. DATA MIGRATION — one-time usermeta -> custom table
 * Reads every user's `_mg_visited_products` history and upserts it into the
 * new table. Idempotent by design (safe to re-run): rows are merged via the
 * same UNIQUE KEY (user_id, product_id) used by the REST endpoint, and
 * counts/timestamps are only ever moved forward (GREATEST()), so re-running
 * it can't clobber real visits recorded after a first migration.
 *
 * The old usermeta is intentionally left untouched — this is an additive
 * copy, not a destructive move, so there's a clean rollback path if needed.
 *
 * @param bool $force Bypass the "already migrated" guard (used by WP-CLI --force).
 * @return array|WP_Error
 */
function mg_migrate_visited_products_to_table( $force = false ) {
    // Callable either from wp-admin (capability check) or WP-CLI (trusted
    // execution context, no user session to check capabilities against).
    $is_cli = defined( 'WP_CLI' ) && WP_CLI;
    if ( ! $is_cli && ! current_user_can( 'manage_options' ) ) {
        return new WP_Error( 'mg_forbidden', __( 'Insufficient permissions.', 'meilleur-gaskets' ) );
    }

    if ( ! $force && get_option( 'mg_tracking_migration_done' ) ) {
        return array(
            'skipped' => true,
            'reason'  => 'Migration already completed. Pass $force / --force to re-run.',
        );
    }

    // Only fetch the IDs of users that actually have tracking history —
    // avoids loading every single user on large sites.
    $user_ids = get_users(
        array(
            'meta_key' => '_mg_visited_products', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'fields'   => 'ID',
        )
    );

    $migrated_rows = 0;

    foreach ( $user_ids as $user_id ) {
        $history = get_user_meta( $user_id, '_mg_visited_products', true );

        if ( empty( $history ) || ! is_array( $history ) ) {
            continue;
        }

        foreach ( $history as $entry ) {
            if ( empty( $entry['product_id'] ) ) {
                continue;
            }

            $product_id   = absint( $entry['product_id'] );
            $count        = isset( $entry['count'] ) ? absint( $entry['count'] ) : 1;
            $timestamp    = isset( $entry['last_visited'] ) ? absint( $entry['last_visited'] ) : time();
            $last_visited = gmdate( 'Y-m-d H:i:s', $timestamp );

            $brand_id = 0;
            $terms    = wp_get_post_terms( $product_id, 'pwb-brand', array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $brand_id = absint( $terms[0] );
            }

            // Same write path the REST endpoint uses, so migrated history
            // populates the summary table too, not just the raw table.
            mg_record_product_visit( $user_id, $product_id, $brand_id, $count, $last_visited );
            $migrated_rows++;
        }
    }

    update_option( 'mg_tracking_migration_done', current_time( 'mysql' ) );

    return array(
        'skipped' => false,
        'rows'    => $migrated_rows,
    );
}

/**
 * 3a. WP-CLI entry point: `wp mg migrate-tracking [--force]`
 * The one-time wp-admin "Run Migration" button has been removed now that
 * the initial migration is complete; this WP-CLI command is left in place
 * as a manual fallback (e.g. to resync after a data import) rather than
 * removing mg_migrate_visited_products_to_table() outright.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command(
        'mg migrate-tracking',
        function ( $args, $assoc_args ) {
            $force  = isset( $assoc_args['force'] );
            $result = mg_migrate_visited_products_to_table( $force );

            if ( is_wp_error( $result ) ) {
                WP_CLI::error( $result->get_error_message() );
            }

            if ( ! empty( $result['skipped'] ) ) {
                WP_CLI::warning( $result['reason'] );
                return;
            }

            WP_CLI::success( sprintf( 'Migrated/updated %d visit record(s).', $result['rows'] ) );
        }
    );
}

/**
 * 4. DATA RETRIEVAL FOUNDATION
 * Core $wpdb queries for "top visited products" and "top visited brands"
 * for a given user — the building blocks for the future Elementor widgets
 * and admin dashboard datatable. Both are single indexed queries; MySQL
 * does the sorting/aggregation instead of PHP looping over a full history.
 */

/**
 * Top products a user has visited, most-visited first.
 *
 * @param int $user_id
 * @param int $limit
 * @return array Rows with ->product_id, ->brand_id, ->visit_count, ->last_visited
 */
function mg_get_top_visited_products( $user_id, $limit = 10 ) {
    global $wpdb;
    $table = mg_get_tracking_table_name();

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT product_id, brand_id, visit_count, last_visited
             FROM {$table}
             WHERE user_id = %d
             ORDER BY visit_count DESC, last_visited DESC
             LIMIT %d",
            absint( $user_id ),
            absint( $limit )
        )
    );
}

/**
 * Top brands a user has visited, ranked by total visits across all of that
 * brand's products (visit_count summed, grouped by brand_id).
 *
 * @param int $user_id
 * @param int $limit
 * @return array Rows with ->brand_id, ->total_visits, ->last_visited
 */
function mg_get_top_visited_brands( $user_id, $limit = 10 ) {
    global $wpdb;
    $table = mg_get_tracking_table_name();

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT brand_id, SUM(visit_count) AS total_visits, MAX(last_visited) AS last_visited
             FROM {$table}
             WHERE user_id = %d AND brand_id > 0
             GROUP BY brand_id
             ORDER BY total_visits DESC, last_visited DESC
             LIMIT %d",
            absint( $user_id ),
            absint( $limit )
        )
    );
}

/**
 * 5. ADMIN MENU: "Suivi Utilisateurs" submenu under WooCommerce Products
 * Visible to anyone who can already view/manage the Products screen itself
 * (i.e. everyone with wp-admin access except the "customer" role, which has
 * no product capabilities). Gated on 'edit_products' rather than hardcoded
 * role names — this is the same capability WordPress already uses to decide
 * whether a role can see the Products parent menu.
 * Hooked to: admin_menu
 */
add_action( 'admin_menu', 'mg_register_user_tracking_menu' );
function mg_register_user_tracking_menu() {

    if ( ! mg_user_tracking_can_access() ) {
        return;
    }

    add_submenu_page(
        'edit.php?post_type=product',
        __( 'Suivi Utilisateurs', 'meilleur-gaskets' ),
        __( 'Suivi Utilisateurs', 'meilleur-gaskets' ),
        'edit_products',
        'mg-users-track',
        'mg_render_user_tracking_page'
    );
}

/**
 * Helper: Check if the current user is allowed to view the tracking screen.
 * Anyone who can manage products (i.e. everyone except "customer") qualifies.
 */
function mg_user_tracking_can_access() {
    return current_user_can( 'edit_products' );
}

/**
 * 6. ADMIN PAGE ROUTER: Decide between the users list and a single user's history
 */
function mg_render_user_tracking_page() {

    // Defense in depth: re-check access even though the menu is already gated
    if ( ! current_user_can( 'edit_products' ) ) {
        wp_die( esc_html__( 'Vous n\'avez pas la permission d\'accéder à cette page.', 'meilleur-gaskets' ) );
    }

    $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

    echo '<div class="wrap">';

    if ( $user_id > 0 ) {
        mg_render_user_tracking_detail( $user_id );
    } else {
        mg_render_user_tracking_list();
    }

    echo '</div>';
}

/**
 * 6a. MAIN VIEW: List all users with the "customer" role, with a search box
 * Search matches against display name, username and email
 */
function mg_render_user_tracking_list() {

    echo '<h1>' . esc_html__( 'Suivi Utilisateurs', 'meilleur-gaskets' ) . '</h1>';

    $base_url = admin_url( 'edit.php?post_type=product&page=mg-users-track' );
    $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

    // --- Search box ---
    echo '<form method="get" action="' . esc_url( admin_url( 'edit.php' ) ) . '" style="margin:15px 0;">';
    echo '<input type="hidden" name="post_type" value="product" />';
    echo '<input type="hidden" name="page" value="mg-users-track" />';
    echo '<p class="search-box" style="margin:0;">';
    echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Rechercher un utilisateur (nom ou email)…', 'meilleur-gaskets' ) . '" style="min-width:280px;" />';
    echo '&nbsp;<input type="submit" class="button" value="' . esc_attr__( 'Rechercher', 'meilleur-gaskets' ) . '" />';
    if ( '' !== $search ) {
        echo '&nbsp;<a href="' . esc_url( $base_url ) . '" class="button">' . esc_html__( 'Réinitialiser', 'meilleur-gaskets' ) . '</a>';
    }
    echo '</p>';
    echo '</form>';

    $query_args = array(
        'role'    => 'customer',
        'orderby' => 'display_name',
        'order'   => 'ASC',
    );

    if ( '' !== $search ) {
        $query_args['search']         = '*' . $search . '*';
        $query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
    }

    $customers = get_users( $query_args );

    if ( empty( $customers ) ) {
        if ( '' !== $search ) {
            echo '<p>' . sprintf(
                /* translators: %s: search term */
                esc_html__( 'Aucun client trouvé pour "%s".', 'meilleur-gaskets' ),
                esc_html( $search )
            ) . '</p>';
        } else {
            echo '<p>' . esc_html__( 'Aucun client trouvé.', 'meilleur-gaskets' ) . '</p>';
        }
        return;
    }

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__( 'Nom', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Email', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Action', 'meilleur-gaskets' ) . '</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ( $customers as $customer ) {

        $detail_url = add_query_arg( 'user_id', $customer->ID, $base_url );

        echo '<tr>';
        echo '<td>' . esc_html( $customer->display_name ) . '</td>';
        echo '<td>' . esc_html( $customer->user_email ) . '</td>';
        echo '<td><a href="' . esc_url( $detail_url ) . '" class="button button-secondary">' . esc_html__( 'View History', 'meilleur-gaskets' ) . '</a></td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
}

/**
 * 6b. DETAIL VIEW: Show a single user's product visit history, with a search box
 * Search matches against the visited product's title.
 * Now reads from the custom table instead of the `_mg_visited_products`
 * usermeta — the SQL does the "most recent first" ordering, so there's no
 * PHP-side uasort() over the whole history any more.
 */
function mg_render_user_tracking_detail( $user_id ) {

    global $wpdb;

    $user = get_userdata( $user_id );

    $back_url = admin_url( 'edit.php?post_type=product&page=mg-users-track' );

    if ( ! $user ) {
        echo '<h1>' . esc_html__( 'Suivi Utilisateurs', 'meilleur-gaskets' ) . '</h1>';
        echo '<p>' . esc_html__( 'Utilisateur introuvable.', 'meilleur-gaskets' ) . '</p>';
        echo '<a href="' . esc_url( $back_url ) . '" class="button">' . esc_html__( '&larr; Back to Users', 'meilleur-gaskets' ) . '</a>';
        return;
    }

    echo '<h1>' . sprintf(
        /* translators: %s: user display name */
        esc_html__( 'Historique de %s', 'meilleur-gaskets' ),
        esc_html( $user->display_name )
    ) . '</h1>';

    echo '<p><a href="' . esc_url( $back_url ) . '" class="button">' . esc_html__( '&larr; Back to Users', 'meilleur-gaskets' ) . '</a></p>';

    $table = mg_get_tracking_table_name();

    $history = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT product_id, brand_id, visit_count, last_visited
             FROM {$table}
             WHERE user_id = %d
             ORDER BY last_visited DESC",
            $user_id
        )
    );

    if ( empty( $history ) ) {
        echo '<p>' . esc_html__( 'Aucun produit consulté pour le moment.', 'meilleur-gaskets' ) . '</p>';
        return;
    }

    $product_search = isset( $_GET['product_s'] ) ? sanitize_text_field( wp_unslash( $_GET['product_s'] ) ) : '';
    $detail_url     = add_query_arg( 'user_id', $user_id, $back_url );

    // --- Search box (product name) ---
    echo '<form method="get" action="' . esc_url( admin_url( 'edit.php' ) ) . '" style="margin:15px 0;">';
    echo '<input type="hidden" name="post_type" value="product" />';
    echo '<input type="hidden" name="page" value="mg-users-track" />';
    echo '<input type="hidden" name="user_id" value="' . esc_attr( $user_id ) . '" />';
    echo '<p class="search-box" style="margin:0;">';
    echo '<input type="search" name="product_s" value="' . esc_attr( $product_search ) . '" placeholder="' . esc_attr__( 'Rechercher par nom ou référence…', 'meilleur-gaskets' ) . '" style="min-width:280px;" />';
    echo '&nbsp;<input type="submit" class="button" value="' . esc_attr__( 'Rechercher', 'meilleur-gaskets' ) . '" />';
    if ( '' !== $product_search ) {
        echo '&nbsp;<a href="' . esc_url( $detail_url ) . '" class="button">' . esc_html__( 'Réinitialiser', 'meilleur-gaskets' ) . '</a>';
    }
    echo '</p>';
    echo '</form>';

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th style="width:80px;">' . esc_html__( 'Image', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Produit', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Référence', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Nombre de visites', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Dernière visite', 'meilleur-gaskets' ) . '</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    $rows_found = 0;

    foreach ( $history as $entry ) {

        if ( empty( $entry->product_id ) ) {
            continue;
        }

        $product = wc_get_product( $entry->product_id );

        // Skip entries whose product has been deleted
        if ( ! $product ) {
            continue;
        }

        $reference = function_exists( 'get_field' ) ? get_field( 'reference', $product->get_id() ) : '';

        // Filter by product name OR reference if a search term was entered
        if ( '' !== $product_search ) {
            $name_match = false !== stripos( $product->get_name(), $product_search );
            $ref_match  = $reference && false !== stripos( (string) $reference, $product_search );
            if ( ! $name_match && ! $ref_match ) {
                continue;
            }
        }

        $rows_found++;

        $thumbnail             = $product->get_image( array( 40, 40 ) ); // Already escaped by WooCommerce core
        $store_url             = get_permalink( $product->get_id() );
        $count                 = absint( $entry->visit_count );
        $last_visited_display  = $entry->last_visited ? date_i18n( 'd/m/Y H:i', strtotime( $entry->last_visited ) ) : '—';

        echo '<tr>';
        echo '<td>' . $thumbnail . '</td>';
        echo '<td>';
        if ( $store_url ) {
            echo '<a href="' . esc_url( $store_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $product->get_name() ) . '</a>';
        } else {
            echo esc_html( $product->get_name() );
        }
        echo '</td>';
        echo '<td>' . ( $reference ? esc_html( $reference ) : '—' ) . '</td>';
        echo '<td>' . esc_html( $count ) . '</td>';
        echo '<td>' . esc_html( $last_visited_display ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    if ( 0 === $rows_found && '' !== $product_search ) {
        echo '<p>' . sprintf(
            /* translators: %s: search term */
            esc_html__( 'Aucun produit consulté ne correspond à "%s".', 'meilleur-gaskets' ),
            esc_html( $product_search )
        ) . '</p>';
    }
}

// =========================================================
// SECTION 26: GLOBAL POPULARITY DASHBOARD (top products / top brands)
// =========================================================
// Site-wide "most visited products" and "most visited brands" reports,
// with filtering and pagination, for the future Elementor widgets and for
// the admin dashboard table added below. Reads from the summary table
// (mg_get_summary_table_name()) for the fast, no-date-filter case, and
// falls back to aggregating the raw per-visit table only when a date range
// is requested — see the docblock on mg_get_top_products_global() for why.

/**
 * Top products site-wide, with optional filters, sorted and paginated.
 *
 * IMPORTANT LIMITATION when filtering by date: mg_product_tracking stores
 * one row per (user, product) with a running total and only the *last*
 * visit's timestamp — not one row per individual visit. So a date filter
 * here means "products whose most recent visit from at least one user
 * falls in this range", and the visit_count summed for that product
 * includes ALL of that user's historical visits, not just the ones inside
 * the range. It's a reasonable "recently popular" proxy, not an exact
 * visits-per-period count. An exact version would need a per-visit event
 * log or a daily rollup table — worth adding later if trend charts
 * ("visits this week vs last week") become a requirement.
 *
 * @param array $args {
 *     @type string $search      Match against product title.
 *     @type int    $brand_id    Restrict to one brand (pwb-brand term_id).
 *     @type int    $category_id Restrict to one category (product_cat term_id).
 *     @type int    $min_visits  Only products with at least this many total visits.
 *     @type string $date_from   Y-m-d. Triggers the raw-table fallback (see above).
 *     @type string $date_to     Y-m-d. Triggers the raw-table fallback (see above).
 *     @type string $orderby     total_visits (default) | unique_visitors | last_visited.
 *     @type string $order       DESC (default) | ASC.
 *     @type int    $per_page    Default 20.
 *     @type int    $paged       Default 1.
 * }
 * @return array { rows: object[], total: int, pages: int }
 */
function mg_get_top_products_global( $args = array() ) {
    global $wpdb;

    $args = wp_parse_args(
        $args,
        array(
            'search'      => '',
            'brand_id'    => 0,
            'category_id' => 0,
            'min_visits'  => 0,
            'date_from'   => '',
            'date_to'     => '',
            'orderby'     => 'total_visits',
            'order'       => 'DESC',
            'per_page'    => 20,
            'paged'       => 1,
        )
    );

    // Whitelist ORDER BY column — never interpolate user input into it directly.
    $orderby_map = array(
        'total_visits'    => 'total_visits',
        'unique_visitors' => 'unique_visitors',
        'last_visited'    => 'last_visited',
    );
    $orderby = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'total_visits';
    $order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

    $per_page = max( 1, absint( $args['per_page'] ) );
    $paged    = max( 1, absint( $args['paged'] ) );
    $offset   = ( $paged - 1 ) * $per_page;

    $raw_table     = mg_get_tracking_table_name();
    $summary_table = mg_get_summary_table_name();

    $has_date_filter = ( '' !== $args['date_from'] || '' !== $args['date_to'] );

    if ( $has_date_filter ) {
        $date_where  = array();
        $date_params = array();

        if ( '' !== $args['date_from'] ) {
            $date_where[]  = 'last_visited >= %s';
            $date_params[] = $args['date_from'] . ' 00:00:00';
        }
        if ( '' !== $args['date_to'] ) {
            $date_where[]  = 'last_visited <= %s';
            $date_params[] = $args['date_to'] . ' 23:59:59';
        }

        $date_where_sql = $date_where ? ( 'WHERE ' . implode( ' AND ', $date_where ) ) : '';

        $source_sql    = "( SELECT product_id, brand_id, SUM(visit_count) AS total_visits,
                                    COUNT(DISTINCT user_id) AS unique_visitors, MAX(last_visited) AS last_visited
                             FROM {$raw_table}
                             {$date_where_sql}
                             GROUP BY product_id ) s";
        $source_params = $date_params;
    } else {
        $source_sql    = "{$summary_table} s";
        $source_params = array();
    }

    $where  = array( '1=1' );
    $params = array();
    $joins  = '';

    if ( $args['category_id'] ) {
        $joins   .= " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = s.product_id
                       INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                                  AND tt.taxonomy = 'product_cat' AND tt.term_id = %d";
        $params[] = absint( $args['category_id'] );
    }

    if ( '' !== $args['search'] ) {
        // Match either the product title or the 'reference' ACF field
        // (same meta_key used elsewhere in this plugin, e.g.
        // mg_display_product_reference_in_loop()). LEFT JOIN so products
        // with no reference set still match on title alone.
        $joins .= " INNER JOIN {$wpdb->posts} p ON p.ID = s.product_id
                     LEFT JOIN {$wpdb->postmeta} pm_ref ON pm_ref.post_id = s.product_id AND pm_ref.meta_key = 'reference'";

        $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        $where[]  = '( p.post_title LIKE %s OR pm_ref.meta_value LIKE %s )';
        $params[] = $like;
        $params[] = $like;
    }

    if ( $args['brand_id'] ) {
        $where[]  = 's.brand_id = %d';
        $params[] = absint( $args['brand_id'] );
    }

    if ( $args['min_visits'] ) {
        $where[]  = 's.total_visits >= %d';
        $params[] = absint( $args['min_visits'] );
    }

    $where_sql = implode( ' AND ', $where );

    $count_sql = "SELECT COUNT(*) FROM {$source_sql} {$joins} WHERE {$where_sql}";
    $data_sql  = "SELECT s.product_id, s.brand_id, s.total_visits, s.unique_visitors, s.last_visited
                  FROM {$source_sql} {$joins}
                  WHERE {$where_sql}
                  ORDER BY {$orderby} {$order}
                  LIMIT %d OFFSET %d";

    // Placeholder order must match their physical order in the SQL above:
    // source_sql (date range) -> joins (category) -> where (search/brand/min).
    $all_params  = array_merge( $source_params, $params );
    $data_params = array_merge( $all_params, array( $per_page, $offset ) );

    $total = (int) $wpdb->get_var(
        $all_params ? $wpdb->prepare( $count_sql, $all_params ) : $count_sql
    );

    $rows = $wpdb->get_results(
        $wpdb->prepare( $data_sql, $data_params )
    );

    return array(
        'rows'  => $rows,
        'total' => $total,
        'pages' => $per_page ? (int) ceil( $total / $per_page ) : 1,
    );
}

/**
 * Top brands site-wide, with optional filters, sorted and paginated.
 *
 * Without a date filter, brand totals are derived from the summary table
 * (SUM of each brand's products) — fast, but unique_visitors is an
 * approximation: a customer who viewed two different products of the same
 * brand counts as 2 there, not 1, since the summary table only tracks
 * uniqueness per product. With a date filter, totals come from the raw
 * table grouped directly by brand_id, which gives an exact distinct-visitor
 * count for that range at the cost of a full scan — acceptable for an
 * admin-only, occasionally-used report.
 *
 * @param array $args {
 *     @type string $search     Match against brand name.
 *     @type int    $min_visits Only brands with at least this many total visits.
 *     @type string $date_from  Y-m-d.
 *     @type string $date_to    Y-m-d.
 *     @type string $orderby    total_visits (default) | unique_visitors | last_visited.
 *     @type string $order      DESC (default) | ASC.
 *     @type int    $per_page   Default 20.
 *     @type int    $paged      Default 1.
 * }
 * @return array { rows: object[], total: int, pages: int }
 */
function mg_get_top_brands_global( $args = array() ) {
    global $wpdb;

    $args = wp_parse_args(
        $args,
        array(
            'search'     => '',
            'min_visits' => 0,
            'date_from'  => '',
            'date_to'    => '',
            'orderby'    => 'total_visits',
            'order'      => 'DESC',
            'per_page'   => 20,
            'paged'      => 1,
        )
    );

    $orderby_map = array(
        'total_visits'    => 'total_visits',
        'unique_visitors' => 'unique_visitors',
        'last_visited'    => 'last_visited',
    );
    $orderby = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'total_visits';
    $order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

    $per_page = max( 1, absint( $args['per_page'] ) );
    $paged    = max( 1, absint( $args['paged'] ) );
    $offset   = ( $paged - 1 ) * $per_page;

    $raw_table     = mg_get_tracking_table_name();
    $summary_table = mg_get_summary_table_name();

    $has_date_filter = ( '' !== $args['date_from'] || '' !== $args['date_to'] );

    if ( $has_date_filter ) {
        $date_where  = array( 'brand_id > 0' );
        $date_params = array();

        if ( '' !== $args['date_from'] ) {
            $date_where[]  = 'last_visited >= %s';
            $date_params[] = $args['date_from'] . ' 00:00:00';
        }
        if ( '' !== $args['date_to'] ) {
            $date_where[]  = 'last_visited <= %s';
            $date_params[] = $args['date_to'] . ' 23:59:59';
        }

        $date_where_sql = 'WHERE ' . implode( ' AND ', $date_where );

        $source_sql    = "( SELECT brand_id, SUM(visit_count) AS total_visits,
                                    COUNT(DISTINCT user_id) AS unique_visitors, MAX(last_visited) AS last_visited
                             FROM {$raw_table}
                             {$date_where_sql}
                             GROUP BY brand_id ) s";
        $source_params = $date_params;
    } else {
        $source_sql    = "( SELECT brand_id, SUM(total_visits) AS total_visits,
                                    SUM(unique_visitors) AS unique_visitors, MAX(last_visited) AS last_visited
                             FROM {$summary_table}
                             WHERE brand_id > 0
                             GROUP BY brand_id ) s";
        $source_params = array();
    }

    $where  = array( '1=1' );
    $params = array();
    $joins  = '';

    if ( '' !== $args['search'] ) {
        $joins   .= " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = s.brand_id AND tt.taxonomy = 'pwb-brand'
                       INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id";
        $where[]  = 't.name LIKE %s';
        $params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
    }

    if ( $args['min_visits'] ) {
        $where[]  = 's.total_visits >= %d';
        $params[] = absint( $args['min_visits'] );
    }

    $where_sql = implode( ' AND ', $where );

    $count_sql = "SELECT COUNT(*) FROM {$source_sql} {$joins} WHERE {$where_sql}";
    $data_sql  = "SELECT s.brand_id, s.total_visits, s.unique_visitors, s.last_visited
                  FROM {$source_sql} {$joins}
                  WHERE {$where_sql}
                  ORDER BY {$orderby} {$order}
                  LIMIT %d OFFSET %d";

    $all_params  = array_merge( $source_params, $params );
    $data_params = array_merge( $all_params, array( $per_page, $offset ) );

    $total = (int) $wpdb->get_var(
        $all_params ? $wpdb->prepare( $count_sql, $all_params ) : $count_sql
    );

    $rows = $wpdb->get_results(
        $wpdb->prepare( $data_sql, $data_params )
    );

    return array(
        'rows'  => $rows,
        'total' => $total,
        'pages' => $per_page ? (int) ceil( $total / $per_page ) : 1,
    );
}

/**
 * Admin menu entry: "Produits Populaires" — the global dashboard, distinct
 * from "Suivi Utilisateurs" (which is per-user history). Same capability
 * gate as the rest of the tracking screens.
 */
add_action( 'admin_menu', 'mg_register_popular_tracking_menu' );
function mg_register_popular_tracking_menu() {

    if ( ! mg_user_tracking_can_access() ) {
        return;
    }

    add_submenu_page(
        'edit.php?post_type=product',
        __( 'Produits & Marques Populaires', 'meilleur-gaskets' ),
        __( 'Produits Populaires', 'meilleur-gaskets' ),
        'edit_products',
        'mg-popular-tracking',
        'mg_render_popular_tracking_page'
    );
}

/**
 * Page router: tabs between the products view and the brands view.
 */
function mg_render_popular_tracking_page() {

    if ( ! current_user_can( 'edit_products' ) ) {
        wp_die( esc_html__( 'Vous n\'avez pas la permission d\'accéder à cette page.', 'meilleur-gaskets' ) );
    }

    $view     = isset( $_GET['view'] ) && 'brands' === $_GET['view'] ? 'brands' : 'products';
    $base_url = admin_url( 'edit.php?post_type=product&page=mg-popular-tracking' );

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Produits & Marques Populaires', 'meilleur-gaskets' ) . '</h1>';

    echo '<h2 class="nav-tab-wrapper">';
    printf(
        '<a href="%s" class="nav-tab %s">%s</a>',
        esc_url( add_query_arg( 'view', 'products', $base_url ) ),
        'products' === $view ? 'nav-tab-active' : '',
        esc_html__( 'Produits', 'meilleur-gaskets' )
    );
    printf(
        '<a href="%s" class="nav-tab %s">%s</a>',
        esc_url( add_query_arg( 'view', 'brands', $base_url ) ),
        'brands' === $view ? 'nav-tab-active' : '',
        esc_html__( 'Marques', 'meilleur-gaskets' )
    );
    echo '</h2>';

    if ( 'brands' === $view ) {
        mg_render_popular_brands_view( $base_url );
    } else {
        mg_render_popular_products_view( $base_url );
    }

    echo '</div>';
}

/**
 * Reads and sanitizes the filter fields shared by both views from $_GET.
 */
function mg_get_popular_filters_from_request() {
    $orderby_allowed = array( 'total_visits', 'unique_visitors', 'last_visited' );
    $orderby          = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'total_visits';

    return array(
        'search'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
        'brand_id'    => isset( $_GET['brand_id'] ) ? absint( $_GET['brand_id'] ) : 0,
        'category_id' => isset( $_GET['category_id'] ) ? absint( $_GET['category_id'] ) : 0,
        'min_visits'  => isset( $_GET['min_visits'] ) ? absint( $_GET['min_visits'] ) : 0,
        // Basic Y-m-d shape check; anything malformed is dropped rather than
        // passed to SQL — an empty string just means "no date filter".
        'date_from'   => ( isset( $_GET['date_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ) ) ? $_GET['date_from'] : '',
        'date_to'     => ( isset( $_GET['date_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'] ) ) ? $_GET['date_to'] : '',
        'orderby'     => in_array( $orderby, $orderby_allowed, true ) ? $orderby : 'total_visits',
        'order'       => ( isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'ASC' : 'DESC',
        'paged'       => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
    );
}

/**
 * Renders the shared bits of the filter bar (search, min visits, date
 * range, sort) as hidden/visible inputs on a GET form. $extra_fields_cb, if
 * given, is called to inject view-specific fields (brand/category dropdowns)
 * before the submit button.
 */
function mg_render_popular_filter_form( $base_url, $view, $filters, $extra_fields_cb = null ) {
    echo '<form method="get" action="' . esc_url( admin_url( 'edit.php' ) ) . '" style="margin:15px 0;display:flex;flex-wrap:wrap;gap:10px;align-items:end;">';
    echo '<input type="hidden" name="post_type" value="product" />';
    echo '<input type="hidden" name="page" value="mg-popular-tracking" />';
    echo '<input type="hidden" name="view" value="' . esc_attr( $view ) . '" />';

    echo '<p style="margin:0;"><label>' . esc_html__( 'Recherche', 'meilleur-gaskets' ) . '<br/>';
    $search_placeholder = 'products' === $view
        ? __( 'Nom ou référence…', 'meilleur-gaskets' )
        : __( 'Nom…', 'meilleur-gaskets' );
    echo '<input type="search" name="s" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr( $search_placeholder ) . '" /></label></p>';

    if ( is_callable( $extra_fields_cb ) ) {
        call_user_func( $extra_fields_cb, $filters );
    }

    echo '<p style="margin:0;"><label>' . esc_html__( 'Depuis', 'meilleur-gaskets' ) . '<br/>';
    echo '<input type="date" name="date_from" value="' . esc_attr( $filters['date_from'] ) . '" /></label></p>';

    echo '<p style="margin:0;"><label>' . esc_html__( "Jusqu'à", 'meilleur-gaskets' ) . '<br/>';
    echo '<input type="date" name="date_to" value="' . esc_attr( $filters['date_to'] ) . '" /></label></p>';

    echo '<p style="margin:0;"><label>' . esc_html__( 'Visites min.', 'meilleur-gaskets' ) . '<br/>';
    echo '<input type="number" min="0" name="min_visits" value="' . esc_attr( $filters['min_visits'] ? $filters['min_visits'] : '' ) . '" style="width:90px;" /></label></p>';

    echo '<p style="margin:0;"><label>' . esc_html__( 'Trier par', 'meilleur-gaskets' ) . '<br/>';
    echo '<select name="orderby">';
    $orderby_labels = array(
        'total_visits'    => __( 'Visites totales', 'meilleur-gaskets' ),
        'unique_visitors' => __( 'Visiteurs uniques', 'meilleur-gaskets' ),
        'last_visited'    => __( 'Dernière visite', 'meilleur-gaskets' ),
    );
    foreach ( $orderby_labels as $value => $label ) {
        printf(
            '<option value="%s" %s>%s</option>',
            esc_attr( $value ),
            selected( $filters['orderby'], $value, false ),
            esc_html( $label )
        );
    }
    echo '</select></label></p>';

    echo '<p style="margin:0;"><label>' . esc_html__( 'Ordre', 'meilleur-gaskets' ) . '<br/>';
    echo '<select name="order">';
    printf( '<option value="DESC" %s>%s</option>', selected( $filters['order'], 'DESC', false ), esc_html__( 'Décroissant', 'meilleur-gaskets' ) );
    printf( '<option value="ASC" %s>%s</option>', selected( $filters['order'], 'ASC', false ), esc_html__( 'Croissant', 'meilleur-gaskets' ) );
    echo '</select></label></p>';

    echo '<p style="margin:0;"><input type="submit" class="button button-primary" value="' . esc_attr__( 'Filtrer', 'meilleur-gaskets' ) . '" /></p>';

    $has_any_filter = $filters['search'] || $filters['date_from'] || $filters['date_to'] || $filters['min_visits']
        || ! empty( $filters['brand_id'] ) || ! empty( $filters['category_id'] );
    if ( $has_any_filter ) {
        echo '<p style="margin:0;"><a href="' . esc_url( add_query_arg( 'view', $view, $base_url ) ) . '" class="button">' . esc_html__( 'Réinitialiser', 'meilleur-gaskets' ) . '</a></p>';
    }

    echo '</form>';
}

/**
 * Renders pagination links, preserving all current filters.
 */
function mg_render_popular_pagination( $base_url, $filters, $total_pages ) {
    if ( $total_pages < 2 ) {
        return;
    }

    $current_url = add_query_arg( $filters, $base_url );

    echo '<div class="tablenav"><div class="tablenav-pages">';
    echo paginate_links(
        array(
            'base'      => add_query_arg( 'paged', '%#%', $current_url ),
            'format'    => '',
            'current'   => $filters['paged'],
            'total'     => $total_pages,
            'prev_text' => __( '&laquo; Précédent', 'meilleur-gaskets' ),
            'next_text' => __( 'Suivant &raquo;', 'meilleur-gaskets' ),
        )
    );
    echo '</div></div>';
}

/**
 * VIEW: most visited products, site-wide.
 */
function mg_render_popular_products_view( $base_url ) {

    $filters = mg_get_popular_filters_from_request();

    // Everything below — filter form, table, pagination — lives inside this
    // container so a drill-down can swap it out entirely (and a "back"
    // click can restore it) without touching the tab nav above it.
    echo '<div id="mg-popular-content">';

    mg_render_popular_filter_form(
        $base_url,
        'products',
        $filters,
        function ( $filters ) {
            // Brand dropdown
            $brands = get_terms( array( 'taxonomy' => 'pwb-brand', 'hide_empty' => false ) );
            echo '<p style="margin:0;"><label>' . esc_html__( 'Marque', 'meilleur-gaskets' ) . '<br/>';
            echo '<select name="brand_id"><option value="0">' . esc_html__( 'Toutes', 'meilleur-gaskets' ) . '</option>';
            if ( ! is_wp_error( $brands ) ) {
                foreach ( $brands as $brand ) {
                    printf(
                        '<option value="%d" %s>%s</option>',
                        (int) $brand->term_id,
                        selected( $filters['brand_id'], $brand->term_id, false ),
                        esc_html( $brand->name )
                    );
                }
            }
            echo '</select></label></p>';

            // Category dropdown
            $categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
            echo '<p style="margin:0;"><label>' . esc_html__( 'Catégorie', 'meilleur-gaskets' ) . '<br/>';
            echo '<select name="category_id"><option value="0">' . esc_html__( 'Toutes', 'meilleur-gaskets' ) . '</option>';
            if ( ! is_wp_error( $categories ) ) {
                foreach ( $categories as $cat ) {
                    printf(
                        '<option value="%d" %s>%s</option>',
                        (int) $cat->term_id,
                        selected( $filters['category_id'], $cat->term_id, false ),
                        esc_html( $cat->name )
                    );
                }
            }
            echo '</select></label></p>';
        }
    );

    $filters['per_page'] = 20;
    $result               = mg_get_top_products_global( $filters );

    if ( empty( $result['rows'] ) ) {
        echo '<p>' . esc_html__( 'Aucun produit ne correspond à ces filtres.', 'meilleur-gaskets' ) . '</p>';
        echo '</div>'; // #mg-popular-content
        return;
    }

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th style="width:80px;">' . esc_html__( 'Image', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Produit', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Référence', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Marque', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Visites totales', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Visiteurs uniques', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Dernière visite', 'meilleur-gaskets' ) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ( $result['rows'] as $row ) {
        $product = wc_get_product( $row->product_id );
        if ( ! $product ) {
            continue; // Product deleted since last visit; skip silently.
        }

        $brand_name = '—';
        if ( $row->brand_id ) {
            $brand_term = get_term( $row->brand_id, 'pwb-brand' );
            if ( $brand_term && ! is_wp_error( $brand_term ) ) {
                $brand_name = $brand_term->name;
            }
        }

        $reference = function_exists( 'get_field' ) ? get_field( 'reference', $product->get_id() ) : '';

        $thumbnail = $product->get_image( array( 40, 40 ) );
        $store_url = get_permalink( $product->get_id() );

        echo '<tr>';
        echo '<td>' . $thumbnail . '</td>';
        echo '<td>';
        // Clicking the title navigates (AJAX, no page reload) to the list
        // of users who viewed this product. The small ↗ next to it is the
        // only thing that opens the actual storefront product page.
        echo '<span class="mg-drilldown-link" data-mg-type="product" data-mg-id="' . esc_attr( $row->product_id ) . '" tabindex="0" role="button">' . esc_html( $product->get_name() ) . '</span>';
        if ( $store_url ) {
            echo ' <a href="' . esc_url( $store_url ) . '" target="_blank" rel="noopener noreferrer" class="mg-external-link" title="' . esc_attr__( 'Voir la fiche produit', 'meilleur-gaskets' ) . '">&#8599;</a>';
        }
        echo '</td>';
        echo '<td>' . ( $reference ? esc_html( $reference ) : '—' ) . '</td>';
        echo '<td>' . esc_html( $brand_name ) . '</td>';
        echo '<td>' . esc_html( number_format_i18n( (int) $row->total_visits ) ) . '</td>';
        echo '<td>' . esc_html( number_format_i18n( (int) $row->unique_visitors ) ) . '</td>';
        echo '<td>' . esc_html( $row->last_visited ? date_i18n( 'd/m/Y H:i', strtotime( $row->last_visited ) ) : '—' ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    mg_render_popular_pagination( $base_url, $filters, $result['pages'] );

    echo '</div>'; // #mg-popular-content
}

/**
 * VIEW: most visited brands, site-wide.
 */
function mg_render_popular_brands_view( $base_url ) {

    $filters = mg_get_popular_filters_from_request();

    echo '<div id="mg-popular-content">';

    mg_render_popular_filter_form( $base_url, 'brands', $filters, null );

    $filters['per_page'] = 20;
    $result               = mg_get_top_brands_global( $filters );

    if ( empty( $result['rows'] ) ) {
        echo '<p>' . esc_html__( 'Aucune marque ne correspond à ces filtres.', 'meilleur-gaskets' ) . '</p>';
        echo '</div>'; // #mg-popular-content
        return;
    }

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th style="width:80px;">' . esc_html__( 'Logo', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Marque', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Visites totales', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Visiteurs uniques', 'meilleur-gaskets' ) . '</th>';
    echo '<th>' . esc_html__( 'Dernière visite', 'meilleur-gaskets' ) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ( $result['rows'] as $row ) {
        $brand_term = get_term( $row->brand_id, 'pwb-brand' );
        if ( ! $brand_term || is_wp_error( $brand_term ) ) {
            continue; // Brand term deleted since last visit; skip silently.
        }

        $brand_img_id = get_term_meta( $brand_term->term_id, 'pwb_brand_image', true );
        $brand_img    = $brand_img_id ? wp_get_attachment_image( $brand_img_id, array( 40, 40 ) ) : '';
        $brand_shop_url = add_query_arg( 'pwb-brand', $brand_term->slug, get_permalink( wc_get_page_id( 'shop' ) ) );

        echo '<tr>';
        echo '<td>' . ( $brand_img ? $brand_img : '—' ) . '</td>';
        echo '<td>';
        // Clicking the name navigates (AJAX, no page reload) to that
        // brand's product list. The small ↗ opens the actual storefront
        // shop page filtered to this brand.
        echo '<span class="mg-drilldown-link" data-mg-type="brand" data-mg-id="' . esc_attr( $row->brand_id ) . '" tabindex="0" role="button">' . esc_html( $brand_term->name ) . '</span>';
        echo ' <a href="' . esc_url( $brand_shop_url ) . '" target="_blank" rel="noopener noreferrer" class="mg-external-link" title="' . esc_attr__( 'Voir la page de la marque', 'meilleur-gaskets' ) . '">&#8599;</a>';
        echo '</td>';
        echo '<td>' . esc_html( number_format_i18n( (int) $row->total_visits ) ) . '</td>';
        echo '<td>' . esc_html( number_format_i18n( (int) $row->unique_visitors ) ) . '</td>';
        echo '<td>' . esc_html( $row->last_visited ? date_i18n( 'd/m/Y H:i', strtotime( $row->last_visited ) ) : '—' ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    mg_render_popular_pagination( $base_url, $filters, $result['pages'] );

    echo '</div>'; // #mg-popular-content
}

// =========================================================
// SECTION 27: AJAX DRILL-DOWNS FOR THE POPULARITY DASHBOARD
// =========================================================
// Powers three interactions on the "Produits Populaires" admin screen, all
// as an in-place view swap inside #mg-popular-content (no page reload, no
// modal, no accordion):
//
//   1. Click a product's title -> that container's content is replaced by
//      a table of every user who viewed it. The small ↗ next to the title
//      is the only thing that opens the real storefront product page.
//   2. Click a brand's title   -> replaced by a table of that brand's
//      products, same ↗ convention (opens the shop page filtered to the
//      brand).
//   3. Click a product title *inside* that brand table -> the same user
//      table as (1). This falls out for free: every product row, whether
//      rendered on page load or injected later via AJAX, carries the same
//      class="mg-drilldown-link" data-mg-type="product" contract, and the
//      JS below delegates its click handler on the container rather than
//      binding to specific elements — so it also catches rows that don't
//      exist yet at page-load time.
//
// A "← Retour" button is part of every AJAX-returned view; clicking it
// pops a client-side history stack of previous view snapshots, so going
// back is instant (no re-fetch) and works through arbitrary depth.
//
// Both AJAX endpoints return a small JSON envelope { success, data: { html } }
// where `html` is a fully server-rendered (and already-escaped) fragment —
// the JS's only job is to drop it into the container, it does no
// templating of its own.

/**
 * AJAX: table of every user who viewed a given product, plus a back button
 * and a heading with a ↗ link to the real storefront product page.
 * Backs both the top-level product drill-down and the nested one reached
 * via a brand's product list — same handler either way.
 */
add_action( 'wp_ajax_mg_get_product_viewers', 'mg_ajax_get_product_viewers' );
function mg_ajax_get_product_viewers() {
    check_ajax_referer( 'mg_popular_dashboard', 'nonce' );

    if ( ! current_user_can( 'edit_products' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission refusée.', 'meilleur-gaskets' ) ), 403 );
    }

    $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
    if ( ! $product_id ) {
        wp_send_json_error( array( 'message' => __( 'Produit invalide.', 'meilleur-gaskets' ) ), 400 );
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        wp_send_json_error( array( 'message' => __( 'Produit introuvable.', 'meilleur-gaskets' ) ), 404 );
    }

    global $wpdb;
    $raw_table = mg_get_tracking_table_name();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT t.user_id, t.visit_count, t.last_visited, u.display_name, u.user_email
             FROM {$raw_table} t
             INNER JOIN {$wpdb->users} u ON u.ID = t.user_id
             WHERE t.product_id = %d
             ORDER BY t.visit_count DESC, t.last_visited DESC",
            $product_id
        )
    );

    $store_url = get_permalink( $product->get_id() );

    ob_start();

    echo '<div class="mg-drilldown-header">';
    echo '<button type="button" class="button mg-drilldown-back">&#8592; ' . esc_html__( 'Retour', 'meilleur-gaskets' ) . '</button>';
    echo '<h2 class="mg-drilldown-title">';
    printf(
        /* translators: %s: product name */
        esc_html__( 'Utilisateurs ayant consulté « %s »', 'meilleur-gaskets' ),
        esc_html( $product->get_name() )
    );
    if ( $store_url ) {
        echo ' <a href="' . esc_url( $store_url ) . '" target="_blank" rel="noopener noreferrer" class="mg-external-link" title="' . esc_attr__( 'Voir la fiche produit', 'meilleur-gaskets' ) . '">&#8599;</a>';
    }
    echo '</h2>';
    echo '</div>';

    if ( empty( $rows ) ) {
        echo '<p class="mg-drilldown-empty">' . esc_html__( 'Aucun visiteur enregistré pour ce produit.', 'meilleur-gaskets' ) . '</p>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped mg-drilldown-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Utilisateur', 'meilleur-gaskets' ) . '</th>';
        echo '<th>' . esc_html__( 'Email', 'meilleur-gaskets' ) . '</th>';
        echo '<th>' . esc_html__( 'Visites', 'meilleur-gaskets' ) . '</th>';
        echo '<th>' . esc_html__( 'Dernière visite', 'meilleur-gaskets' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            echo '<tr>';
            echo '<td>' . esc_html( $row->display_name ) . '</td>';
            echo '<td>' . esc_html( $row->user_email ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( (int) $row->visit_count ) ) . '</td>';
            echo '<td>' . esc_html( $row->last_visited ? date_i18n( 'd/m/Y H:i', strtotime( $row->last_visited ) ) : '—' ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    wp_send_json_success( array( 'html' => ob_get_clean() ) );
}

/**
 * AJAX: table of the products viewed under a given brand, plus a back
 * button and a heading with a ↗ link to the storefront shop page filtered
 * to this brand.
 * Each product row uses the same data-mg-type="product" contract as the
 * top-level product list, so clicking one re-enters
 * mg_ajax_get_product_viewers() above through the same JS — that's the
 * "deep drill-down" (brand -> product -> users), no extra wiring needed.
 */
add_action( 'wp_ajax_mg_get_brand_products', 'mg_ajax_get_brand_products' );
function mg_ajax_get_brand_products() {
    check_ajax_referer( 'mg_popular_dashboard', 'nonce' );

    if ( ! current_user_can( 'edit_products' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission refusée.', 'meilleur-gaskets' ) ), 403 );
    }

    $brand_id = isset( $_POST['brand_id'] ) ? absint( $_POST['brand_id'] ) : 0;
    if ( ! $brand_id ) {
        wp_send_json_error( array( 'message' => __( 'Marque invalide.', 'meilleur-gaskets' ) ), 400 );
    }

    $brand_term = get_term( $brand_id, 'pwb-brand' );
    if ( ! $brand_term || is_wp_error( $brand_term ) ) {
        wp_send_json_error( array( 'message' => __( 'Marque introuvable.', 'meilleur-gaskets' ) ), 404 );
    }

    global $wpdb;
    $summary_table = mg_get_summary_table_name();

    // Reads from the summary table (one row per product) rather than the
    // raw visit log — same fast-path reasoning as mg_get_top_products_global().
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT product_id, total_visits, unique_visitors, last_visited
             FROM {$summary_table}
             WHERE brand_id = %d
             ORDER BY total_visits DESC",
            $brand_id
        )
    );

    $brand_shop_url = add_query_arg( 'pwb-brand', $brand_term->slug, get_permalink( wc_get_page_id( 'shop' ) ) );

    ob_start();

    echo '<div class="mg-drilldown-header">';
    echo '<button type="button" class="button mg-drilldown-back">&#8592; ' . esc_html__( 'Retour', 'meilleur-gaskets' ) . '</button>';
    echo '<h2 class="mg-drilldown-title">';
    printf(
        /* translators: %s: brand name */
        esc_html__( 'Produits de la marque « %s »', 'meilleur-gaskets' ),
        esc_html( $brand_term->name )
    );
    echo ' <a href="' . esc_url( $brand_shop_url ) . '" target="_blank" rel="noopener noreferrer" class="mg-external-link" title="' . esc_attr__( 'Voir la page de la marque', 'meilleur-gaskets' ) . '">&#8599;</a>';
    echo '</h2>';
    echo '</div>';

    if ( empty( $rows ) ) {
        echo '<p class="mg-drilldown-empty">' . esc_html__( 'Aucun produit enregistré pour cette marque.', 'meilleur-gaskets' ) . '</p>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped mg-drilldown-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Produit', 'meilleur-gaskets' ) . '</th>';
        echo '<th>' . esc_html__( 'Référence', 'meilleur-gaskets' ) . '</th>';
        echo '<th>' . esc_html__( 'Visites totales', 'meilleur-gaskets' ) . '</th>';
        echo '<th>' . esc_html__( 'Visiteurs uniques', 'meilleur-gaskets' ) . '</th>';
        echo '<th>' . esc_html__( 'Dernière visite', 'meilleur-gaskets' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $product = wc_get_product( $row->product_id );
            if ( ! $product ) {
                continue; // Product deleted since last visit; skip silently.
            }

            $reference = function_exists( 'get_field' ) ? get_field( 'reference', $product->get_id() ) : '';
            $store_url = get_permalink( $product->get_id() );

            echo '<tr>';
            echo '<td>';
            echo '<span class="mg-drilldown-link" data-mg-type="product" data-mg-id="' . esc_attr( $row->product_id ) . '" tabindex="0" role="button">' . esc_html( $product->get_name() ) . '</span>';
            if ( $store_url ) {
                echo ' <a href="' . esc_url( $store_url ) . '" target="_blank" rel="noopener noreferrer" class="mg-external-link" title="' . esc_attr__( 'Voir la fiche produit', 'meilleur-gaskets' ) . '">&#8599;</a>';
            }
            echo '</td>';
            echo '<td>' . ( $reference ? esc_html( $reference ) : '—' ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( (int) $row->total_visits ) ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( (int) $row->unique_visitors ) ) . '</td>';
            echo '<td>' . esc_html( $row->last_visited ? date_i18n( 'd/m/Y H:i', strtotime( $row->last_visited ) ) : '—' ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    wp_send_json_success( array( 'html' => ob_get_clean() ) );
}

/**
 * Enqueue the drill-down JS/CSS, only on the "Produits Populaires" screen.
 */
add_action( 'admin_enqueue_scripts', 'mg_enqueue_popular_dashboard_script' );
function mg_enqueue_popular_dashboard_script( $hook_suffix ) {

    if ( false === strpos( (string) $hook_suffix, 'mg-popular-tracking' ) ) {
        return;
    }

    // No separate .js/.css asset for this small amount of code — a stub
    // handle is enough to hang wp_add_inline_script()/wp_add_inline_style() on.
    wp_register_script( 'mg-popular-dashboard', '', array(), '1.0', true );
    wp_enqueue_script( 'mg-popular-dashboard' );

    wp_localize_script(
        'mg-popular-dashboard',
        'mgPopularDashboard',
        array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'mg_popular_dashboard' ),
            'i18n'    => array(
                'loading' => __( 'Chargement…', 'meilleur-gaskets' ),
                'error'   => __( "Une erreur s'est produite.", 'meilleur-gaskets' ),
                'back'    => __( '← Retour', 'meilleur-gaskets' ),
            ),
        )
    );

    // Navigation model: #mg-popular-content is swapped wholesale on each
    // drill-down (product/brand title click), and a client-side stack of
    // its previous innerHTML powers the "back" button — no re-fetching on
    // the way back, and it naturally supports going N levels deep
    // (products list -> brand's products -> that product's viewers) since
    // each level just pushes one more snapshot onto the stack.
    $inline_js = <<<'JS'
( function () {
    if ( ! window.mgPopularDashboard ) {
        return;
    }

    var container = document.getElementById( 'mg-popular-content' );
    if ( ! container ) {
        return;
    }

    var historyStack = [];

    var AJAX_ACTIONS = {
        product: 'mg_get_product_viewers',
        brand:   'mg_get_brand_products'
    };

    var ID_FIELDS = {
        product: 'product_id',
        brand:   'brand_id'
    };

    // Every navigation swaps #mg-popular-content in place — its scroll
    // position in the page doesn't move, so if you were scrolled down the
    // browsing table, the freshly-loaded content renders off-screen above
    // you. This brings the container's top into view (offset for the fixed
    // WP admin toolbar) every time content changes.
    function scrollToContainer() {
        var adminBar = document.getElementById( 'wpadminbar' );
        var offset   = adminBar ? adminBar.offsetHeight : 0;
        var top      = container.getBoundingClientRect().top + window.pageYOffset - offset - 10;
        window.scrollTo( { top: Math.max( 0, top ), behavior: 'smooth' } );
    }

    function showLoading() {
        container.innerHTML = '';
        var p = document.createElement( 'p' );
        p.className = 'mg-drilldown-loading';
        p.textContent = mgPopularDashboard.i18n.loading;
        container.appendChild( p );
        scrollToContainer();
    }

    function showError( text ) {
        container.innerHTML = '';

        var p = document.createElement( 'p' );
        p.className = 'mg-drilldown-error';
        p.textContent = text;
        container.appendChild( p );

        // Keep a way out of the error state even though nothing loaded —
        // the previous view is still sitting on historyStack.
        var backBtn = document.createElement( 'button' );
        backBtn.type = 'button';
        backBtn.className = 'button mg-drilldown-back';
        backBtn.textContent = mgPopularDashboard.i18n.back;
        container.appendChild( backBtn );
    }

    function navigateTo( type, id ) {
        var action  = AJAX_ACTIONS[ type ];
        var idField = ID_FIELDS[ type ];
        if ( ! action || ! idField ) {
            return;
        }

        // Snapshot the current view so "back" can restore it instantly,
        // without a round trip.
        historyStack.push( container.innerHTML );

        showLoading();

        var formData = new FormData();
        formData.append( 'action', action );
        formData.append( 'nonce', mgPopularDashboard.nonce );
        formData.append( idField, id );

        fetch( mgPopularDashboard.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        } )
            .then( function ( response ) { return response.json(); } )
            .then( function ( data ) {
                if ( data && data.success && data.data && typeof data.data.html === 'string' ) {
                    // Server-rendered, already-escaped fragment from our own
                    // AJAX handlers — same trust level as any other
                    // admin-rendered markup in this plugin.
                    container.innerHTML = data.data.html;
                    scrollToContainer();
                } else {
                    var message = ( data && data.data && data.data.message ) ? data.data.message : mgPopularDashboard.i18n.error;
                    showError( message );
                }
            } )
            .catch( function () {
                showError( mgPopularDashboard.i18n.error );
            } );
    }

    function goBack() {
        if ( ! historyStack.length ) {
            return;
        }
        container.innerHTML = historyStack.pop();
        scrollToContainer();
    }

    function handleActivate( target ) {
        var link = target.closest ? target.closest( '.mg-drilldown-link' ) : null;
        if ( link ) {
            navigateTo( link.getAttribute( 'data-mg-type' ), link.getAttribute( 'data-mg-id' ) );
            return;
        }

        var backBtn = target.closest ? target.closest( '.mg-drilldown-back' ) : null;
        if ( backBtn ) {
            goBack();
        }
    }

    // Delegated on the container (not individual rows) so it keeps working
    // for markup injected later by a drill-down — including nested product
    // rows inside a brand's product list.
    container.addEventListener( 'click', function ( e ) {
        handleActivate( e.target );
    } );

    container.addEventListener( 'keydown', function ( e ) {
        if ( 'Enter' !== e.key && ' ' !== e.key && 'Spacebar' !== e.key ) {
            return;
        }
        var link = e.target.closest ? e.target.closest( '.mg-drilldown-link' ) : null;
        if ( ! link ) {
            return;
        }
        e.preventDefault();
        navigateTo( link.getAttribute( 'data-mg-type' ), link.getAttribute( 'data-mg-id' ) );
    } );
} )();
JS;

    wp_add_inline_script( 'mg-popular-dashboard', $inline_js );

    // 'wp-admin' is already enqueued on every admin screen — attaching a
    // few inline rules to it avoids registering a whole stylesheet for this.
    $inline_css = '
        .mg-drilldown-link { cursor: pointer; text-decoration: underline dotted; text-underline-offset: 2px; }
        .mg-drilldown-link:hover, .mg-drilldown-link:focus { color: #2271b1; }
        .mg-drilldown-link:focus { outline: 2px solid #2271b1; outline-offset: 2px; }
        .mg-external-link { text-decoration: none; margin-left: 2px; }
        .mg-drilldown-header { margin-bottom: 12px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .mg-drilldown-title { margin: 0; }
        .mg-drilldown-loading, .mg-drilldown-empty { color: #757575; font-style: italic; }
        .mg-drilldown-error { color: #d63638; }
        .mg-drilldown-table th, .mg-drilldown-table td { padding: 8px 10px; }
    ';
    wp_add_inline_style( 'wp-admin', $inline_css );
}




?>