<?php
/*
Plugin Name: Meilleur Gaskets Brand Bar
Description: Displays a dynamic car brand logo bar above the WooCommerce shop page and categories with drag-to-scroll functionality.
Version: 1.7
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
        
        if ( is_product_category() ) {
            $queried_object = get_queried_object();
            if ( $queried_object && isset( $queried_object->term_id ) ) {
                $current_category = $queried_object->slug;
            }
        }

        foreach ( $brands as $brand ) {
            $shop_url = get_permalink( wc_get_page_id( 'shop' ) );
            $brand_link = add_query_arg( 'pwb-brand', $brand->slug, $shop_url );
            
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
    </style>

    <script>
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
