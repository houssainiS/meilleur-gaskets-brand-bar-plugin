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

///////////////////////////////
// Hide prices and show minimal checkout summary

// Always set product price to 0 if empty, so checkout works
add_filter('woocommerce_product_get_price', function($price, $product) {
    return $price ?: 0;
}, 10, 2);

add_filter('woocommerce_product_get_regular_price', function($price, $product) {
    return $price ?: 0;
}, 10, 2);

// Hide price text on frontend
add_filter('woocommerce_get_price_html', '__return_empty_string');
add_filter('woocommerce_cart_item_price', '__return_empty_string');
add_filter('woocommerce_cart_item_subtotal', '__return_empty_string');

// Disable payment (acts as request order)
add_filter('woocommerce_cart_needs_payment', '__return_false');

// Hide coupon form
add_filter('woocommerce_coupons_enabled', '__return_false');

// Allow checkout even with 0 prices
add_filter('woocommerce_cart_total', function($value) {
    return '';
});

// CSS for cart + checkout pages
add_action('wp_head', function() {
    if (is_cart() || is_checkout()) {
        echo '<style>
            /* Hide all price/total blocks in cart and checkout */
            .wc-block-components-totals-wrapper,
            .wc-block-components-totals-item,
            .wp-block-woocommerce-cart-order-summary-block,
            .wp-block-woocommerce-checkout-order-summary-block,
            .wc-block-components-checkout-order-summary__title-price,
            .wc-block-components-order-summary-item__individual-price,
            .wc-block-components-order-summary-item__total-price,
            .price,
            .woocommerce-Price-amount,
            .wc-block-components-product-price {
                display: none !important;
                visibility: hidden !important;
            }

            /* Cart page: keep checkout button visible */
            .is-cart .wc-block-cart__submit-button,
            .is-cart .wc-block-components-button {
                display: inline-flex !important;
                visibility: visible !important;
            }
        </style>';
    }
});

// Custom minimal order summary: names + quantities only on checkout
add_action('woocommerce_before_checkout_form', function() {
    if (!is_checkout()) return;

    $cart = WC()->cart->get_cart();
    if (!$cart) return;

    echo '<div class="custom-order-summary" style="margin-bottom:20px; border:1px solid #ddd; padding:10px; border-radius:5px;">';
    echo '<h2 style="margin-top:0;">Résumé de la commande</h2>';
    echo '<ul style="list-style:none; padding-left:0;">';
    foreach ($cart as $cart_item) {
        $product = $cart_item['data'];
        $name = $product->get_name();
        $qty = $cart_item['quantity'];
        echo '<li style="margin-bottom:5px;">' . esc_html($qty) . ' × ' . esc_html($name) . '</li>';
    }
    echo '</ul>';
    echo '</div>';
});


?>
