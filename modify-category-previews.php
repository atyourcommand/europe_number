// Modify thumbnail size
add_filter( 'woocommerce_get_image_size_thumbnail', function( $size ) {
    $size['width']  = 205;
    $size['height'] = 300;
    $size['crop']   = 0;
    return $size;
} );

//Remove default product title
add_action( 'woocommerce_shop_loop_item_title', function() { ob_start(); }, 1 );
add_action( 'woocommerce_shop_loop_item_title', function() { ob_end_clean(); }, 99 );

// Remove default WC thumbnail (replaced by insert_extra_fields)
remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10 );

// Show only esim-europe on the main shop page
add_action( 'pre_get_posts', function( $query ) {
    if ( is_post_type_archive( 'product' ) && $query->is_main_query() && ! is_admin() ) {
        $query->set( 'tax_query', [ [
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => 'esim-europe',
        ] ] );
    }
} );

// Add unlimited/standard CSS class to product cards
add_filter( 'post_class', function( $classes, $class, $product_id ) {
    if ( is_product_category() || is_shop() ) {
        $display_size = get_post_meta( $product_id, 'display size', true );
        $classes[]    = ( $display_size === 'Unlimited' ) ? 'unlimited' : 'standard';
    }
    return $classes;
}, 10, 3 );

// Helper: eSIM deliverables label
function en_esim_deliverables_label( $traffic_policy ) {
    if ( $traffic_policy === 'calls' )      return 'NUMBER';
    if ( $traffic_policy === 'data' )       return 'DATA';
    return 'NUMBER, CALLS, SMS & DATA';
}

// Helper: daily data available display
function en_calculated_data( $size, $units ) {
    if ( $size === 'Unlimited' ) {
        if ( in_array( $units, [ 'Light', 'Regular', '' ], true ) ) return '1GB';
        if ( in_array( $units, [ 'Medium', 'High', 'Pro' ], true ) ) return '3GB';
        return '5GB';
    }
    return "{$size}{$units}";
}

// Product card inner template
add_action( 'woocommerce_before_shop_loop_item_title', function() {
    if ( ! is_product_category() && ! is_shop() ) return;

    global $product;
    if ( ! is_object( $product ) ) {
        $product = wc_get_product( get_the_ID() );
    }

    $product_id   = $product->get_id();
    $product_name = $product->get_name();
    $price        = $product->get_price_html();
    $permalink    = $product->get_permalink();

    // Category slug/name — used for flag image
    $term_slug = '';
    $term_name = '';
    $cats = wp_get_post_terms( get_the_ID(), 'product_cat' );
    if ( $cats && ! is_wp_error( $cats ) ) {
        $cat       = array_shift( $cats );
        $term_name = $cat->name;
        $term_slug = str_replace( [ 'esim-', 'data-' ], '', $cat->slug );
    }

    // Meta — single get_post_meta() call each, no redundant meta_exists() checks
    $expiry_days    = get_post_meta( $product_id, 'download_expiry_days', true ) ?: '!';
    $display_size   = get_post_meta( $product_id, 'display size', true ) ?: '!';
    $display_units  = rtrim( get_post_meta( $product_id, 'display units', true ) ?: '!' );
    $traffic_policy = rtrim( get_post_meta( $product_id, 'traffic policy', true ) );

    // Derived values
    $size_with_spacer   = ( $display_size === 'Unlimited' ) ? "Unlimited&nbsp;" : $display_size;
    $available_data_str = ( $display_size === 'Unlimited' ) ? "Available <b>High Speed</b> Data:" : "Available Data:";
    $deliverables_label = en_esim_deliverables_label( $traffic_policy );
    $data_available     = en_calculated_data( $display_size, $display_units );
    $modal_img          = 'esim-' . ( $traffic_policy === 'data' ? 'data-' : '' ) . ( rtrim( $display_size ) === '' ? 'number' : $display_size ) . '-205x300.png';
    $use_in             = esc_html( $term_name ) . ( strtolower( $term_name ) !== 'europe' ? ', Europe' : '' ) . ' & UK';
    $banner_bg          = ( $traffic_policy === 'data' ) ? '#e07820' : ( ( $traffic_policy === 'calls' ) ? '#29a8df' : '#2d5fa8' );

    ob_start(); ?>
    <h2 class="mt-2 text-sm lg:text-base font-bold tracking-tight text-[#1a202c] text-center uppercase" style="font-size:80%;margin-bottom:10px;text-align:center;">
        <a href="<?= esc_attr( $permalink ) ?>" style="text-decoration: underline dotted;" class="font-bold" title="Go to full product page"><?= esc_html( $product_name ) ?></a>
    </h2>

    <div class="product-inner"><div class="product-wrap" style="padding-bottom:20px;">

        <div class="product-header">
            <div class="product-data">
                <img src="/wp-content/uploads/2025/08/round-flag-<?= esc_attr( $term_slug ) ?>-100x100.png" class="image-region" alt="<?= esc_attr( $term_name ) ?>" title="<?= esc_attr( $term_name ) ?>" width="20" height="20">
                &nbsp;<span class="data-size"><?= esc_html( $size_with_spacer ) ?></span>
                <span class="data-units units MB"><?= esc_html( $display_units ) ?> </span>
                &nbsp;<img src="/wp-content/uploads/2025/08/esim-logo.png" width="44" alt="esim logo" class="self-center">
            </div>
            <div class="product-price"><?= $price ?></div>
        </div>

        <div class="data-option-message" style="background-color:<?= esc_attr( $banner_bg ) ?>"><?= esc_html( $deliverables_label ) ?></div>

        <div class="details-row-brief">
            <div class="details-expiry"><?= esc_html( $expiry_days ) ?> days</div>
            <div class="details-more">
                <a style="align-items:center" href="javascript:void(0)"
                   class="open-modal-dialog flex font-bold !text-[#4a9bed] !underline"
                   aria-label="View more: <?= esc_attr( $product_name ) ?>"
                   aria-haspopup="dialog"
                   aria-controls="modal_window_<?= esc_attr( $product_id ) ?>"
                   data-modal="modal_window_<?= esc_attr( $product_id ) ?>"
                   title="Click for more details">
                    View more&nbsp;<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M19.5 4.5h-7V6h4.44l-5.97 5.97 1.06 1.06L18 7.06v4.44h1.5v-7Zm-13 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3H17v3a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h3V5.5h-3Z"></path></svg>
                </a>
            </div>
        </div>

        <dialog class="modal" id="modal_window_<?= esc_attr( $product_id ) ?>" aria-labelledby="modal-title-<?= esc_attr( $product_id ) ?>">
            <div class="button-container">
                <button data-modal-close="modal_window_<?= esc_attr( $product_id ) ?>" class="button new-close-modal">Close</button>
            </div>
            <a href="<?= esc_attr( $permalink ) ?>">
                <img src="/wp-content/uploads/2025/11/<?= esc_attr( $modal_img ) ?>" class="image-hero-product" alt="eSIM <?= esc_attr( $term_name ) ?>" title="eSIM <?= esc_attr( $term_name ) ?>">
            </a>
            <p class="font-bold" id="modal-title-<?= esc_attr( $product_id ) ?>"><?= esc_html( $product_name ) ?></p>
            <p>&nbsp;</p>
            <div class="modal-row"><div>For use in:</div><div><?= $use_in ?></div></div>
            <div class="modal-row"><div><?= $available_data_str ?></div><div><?= esc_html( $data_available ) ?> <?= ( rtrim( $display_size ) === '' ) ? 'No high speed data' : 'in total' ?></div></div>
            <?php if ( $display_size === 'Unlimited' ) : ?>
                <div><div><b>Unlimited LTE (500Kbps) Data. High Speed Data refreshed daily</b></div></div>
            <?php endif; ?>
            <div class="modal-row"><div>Period of use:</div><div><?= esc_html( $expiry_days ) ?> day</div></div>
            <div class="modal-row"><div>eSIM Type:</div><div><?= esc_html( $deliverables_label ) ?></div></div>
            <div class="modal-row"><div class="flex-1 text-sm text-left">Activation codes:</div><div class="text-sm font-bold">Via email</div></div>
            <?php if ( $traffic_policy !== 'data' ) : ?>
                <p class="leading-1 pt-6"><span class="text-sm">This eSIM includes unlimited calls and sms to the Europe regions listed. A temporary +33 France phone number is supplied.</span></p>
            <?php endif; ?>
            <div class="author">
                <img src="https://europenumber.com/wp-content/uploads/2025/08/help-assistant.png" width="96" height="96" alt="Customer Success Team" class="avatar">
                <span class="author-name">This eSIM has a <a href="/refund-policy/" title="Customer Success Team" rel="author">6 month refund guarantee</a></span>
            </div>
        </dialog>

    <?php echo ob_get_clean();
}, 1 );

// Product card closing wrapper
add_action( 'woocommerce_after_shop_loop_item', function() {
    echo '<!--<div class="details-footer">
        <img src="/wp-content/uploads/2025/08/stripe.svg" width="20" height="20" alt="Checkout powered by Stripe" title="Checkout powered by Stripe">
        <span>&nbsp;Checkout by Stripe&nbsp;&nbsp;&nbsp;&nbsp;</span>
    </div>-->
    </div><!--End .product-inner-->';
}, 60 );
