<?php
/**
 * Quick Links Module — eSIM category shortcuts
 *
 * Usage (in a page template or snippet):
 *   echo en_quick_links_module();           // auto-detects current category; falls back to 'europe'
 *   echo en_quick_links_module( 'europe' ); // explicit cleaned slug (no 'esim-' prefix)
 *
 * Shortcode:
 *   [en_quick_links]
 *   [en_quick_links category="france"]
 */
// ---------------------------------------------------------------------------
// Helper functions — mirrors the existing intro code, guarded against redeclaration
// ---------------------------------------------------------------------------
if ( ! function_exists( 'en_get_cat_name' ) ) {
	function en_get_cat_name() {
		return get_queried_object()->name ?? '';
	}
}
if ( ! function_exists( 'en_get_cat_slug' ) ) {
	function en_get_cat_slug() {
		return get_queried_object()->slug ?? '';
	}
}
if ( ! function_exists( 'get_category_data' ) ) {
	function get_category_data( $category, $meta ) {
		$val = '';
		if ( $meta === 'name' ) {
			$cat_name = $category->name ?? '';
			$val = ( empty( trim( $cat_name ) ) || $cat_name === 'product' ) ? 'Europe' : $cat_name;
		} elseif ( $meta === 'slug' ) {
			$slug = $category->slug ?? 'esim-europe';
			if ( empty( $slug ) ) {
				$slug = 'esim-europe';
			}
			$val = str_replace( [ 'esim-', '-unlimited-data' ], '', $slug );
		} else {
			$val = 'nada';
		}
		return $val;
	}
}
if ( ! function_exists( 'get_country_values' ) ) {
	function get_country_values( $obj, $category_slug, $meta ) {
		$val = '';
		if ( is_array( $obj ) || is_object( $obj ) ) {
			foreach ( $obj as $items ) {
				foreach ( $items as $key => $value ) {
					if ( gettype( $value ) === 'array' ) {
						$items[ $key ] = implode( ',', $value );
					}
					if ( $key === 'Region slug' && $value === $category_slug ) {
						if ( $meta === 'countries' ) {
							$val = $items['Countries'];
						} elseif ( $meta === 'cost_mb' ) {
							$val = $items['$USD/MB'];
						}
					}
				}
			}
		}
		return $val;
	}
}
if ( ! function_exists( 'stringCount' ) ) {
	function stringCount( $string ) {
		return $string ? count( explode( ',', $string ) ) : 0;
	}
}
if ( ! function_exists( 'en_esim_deliverables_label' ) ) {
	function en_esim_deliverables_label( $traffic_policy ) {
		if ( $traffic_policy === 'calls' ) return 'NUMBER';
		if ( $traffic_policy === 'data' )  return 'DATA';
		return 'NUMBER, CALLS, SMS & DATA';
	}
}

/**
 * Return a short display label for a product's traffic policy.
 * Used in the quick-link button sub-label.
 *
 * calls_data → "Number & Data"
 * calls      → "Number Only"
 * data       → "Data Only"
 */
if ( ! function_exists( 'en_ql_traffic_label' ) ) {
	function en_ql_traffic_label( $traffic_policy ) {
		if ( $traffic_policy === 'data' )  return 'Data Only';
		if ( $traffic_policy === 'calls' ) return 'Number Only';
		return 'Number & Data';  // calls_data or unknown
	}
}

// ---------------------------------------------------------------------------
// Core function
// ---------------------------------------------------------------------------
function en_quick_links_module( $category_slug = null ) {
	global $wp_query;
	$cat         = $wp_query->get_queried_object();
	$cat_is_term = ( $cat instanceof WP_Term );

	if ( empty( $category_slug ) ) {
		$category_slug = $cat_is_term ? ( get_category_data( $cat, 'slug' ) ?: 'europe' ) : 'europe';
	}
	$category_name = $cat_is_term ? ( get_category_data( $cat, 'name' ) ?: ucfirst( $category_slug ) ) : ucfirst( $category_slug );

	$json_path = ABSPATH . 'api_data/region-data.json';
	$obj       = file_exists( $json_path ) ? json_decode( file_get_contents( $json_path ), true ) : [];

	$region_countries        = get_country_values( $obj, $category_slug, 'countries' );
	$region_cost_mb          = get_country_values( $obj, $category_slug, 'cost_mb' );
	$region_countries_format = $region_countries && strrpos( $region_countries, ',' ) !== false
							   ? substr_replace( $region_countries, ' and', strrpos( $region_countries, ',' ), 1 )
							   : $region_countries;
	$string_count            = stringCount( $region_countries );

	$wc_slug          = sanitize_title( $category_slug );
	$wc_slug_prefixed = 'esim-' . $wc_slug;

	$args = [
		'post_type'      => 'product',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'orderby'        => 'menu_order',
		'order'          => 'ASC',
		'tax_query'      => [
			[
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => [ $wc_slug, $wc_slug_prefixed ],
				'operator' => 'IN',
			],
		],
		'meta_query'     => [
			[
				'key'   => '_stock_status',
				'value' => 'instock',
			],
		],
	];

	$query = new WP_Query( $args );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'en_quick_links: category_slug=' . $category_slug . ' | wc_slug=' . $wc_slug . ' | posts_found=' . $query->found_posts );
	}

	if ( ! $query->have_posts() ) {
		return '<p style="color:red;font-size:12px;padding:8px;border:1px solid red;">'
			 . 'en_quick_links: no products found for slugs ['
			 . esc_html( $wc_slug ) . ', ' . esc_html( $wc_slug_prefixed )
			 . ']. Check WooCommerce product category slugs.</p>';
	}

	ob_start();
	?>
	<style>
	:root {
		--en-ql-bg:     #4a9bed;
		--en-ql-hover:  #2f7fd4;
		--en-ql-radius: 8px;
		--en-ql-gap:    12px;
	}
	.en-ql-wrap {
		display: flex;
		flex-wrap: nowrap;
		gap: var(--en-ql-gap);
		width: 100%;
		box-sizing: border-box;
	}
	.en-ql-btn {
		flex: 1 1 0;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 4px;
		background-color: var(--en-ql-bg);
		color: #ffffff;
		text-decoration: none;
		padding: 18px 12px;
		border-radius: var(--en-ql-radius);
		text-align: center;
		transition: background-color 0.2s ease, transform 0.15s ease;
		min-width: 0;
	}
	.en-ql-btn:hover,
	.en-ql-btn:focus {
		background-color: var(--en-ql-hover);
		color: #ffffff;
		transform: translateY(-2px);
		text-decoration: none;
	}
	/* Static image button */
	.en-ql-btn--image {
		padding: 0;
		overflow: hidden;
	}
	.en-ql-btn--image:hover,
	.en-ql-btn--image:focus {
		background-color: transparent;
	}
	.en-ql-img {
		width: 100%;
		height: 100%;
		object-fit: cover;
		display: block;
		border-radius: var(--en-ql-radius);
	}
	.en-ql-title {
		display: block;
		font-size: 1.2rem;
		font-weight: 700;
		line-height: 1.2;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
		max-width: 100%;
	}
	.en-ql-price {
		display: block;
		font-size: 0.875rem;
		font-weight: 700;
		opacity: 0.9;
		line-height: 1.2;
	}
	.en-ql-label {
		display: block;
		font-size: 9px;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.1em;
		color: rgba(255, 255, 255, 0.75);
		line-height: 1.2;
		margin-top: 2px;
	}
	.en-ql-btn .woocommerce-Price-amount,
	.en-ql-btn .woocommerce-Price-currencySymbol {
		color: #ffffff !important;
	}
	/* Mobile: 3-column grid */
	@media ( max-width: 640px ) {
		.en-ql-wrap {
			display: grid;
			grid-template-columns: 1fr 1fr 1fr;
		}
		.en-ql-btn {
			padding: 12px 6px;
		}
		.en-ql-btn--image {
			padding: 0;
		}
	}
	</style>

	<div class="site-container">
	<div class="lg:px-8 py-6 md:py-12">
	<div class="banner mx-auto lg:max-w-4xl overflow-hidden">

		<!-- TOP: Category quick links -->
		<div class="bg-white flex items-center justify-center gap-6 px-6 py-3 border-b border-[#e2e8f0]">
			<a href="https://europenumber.com/product-category/esim-france/"
			   class="flex font-bold uppercase !text-[#4a9bed]"
			   style="text-decoration: underline dotted;">France &rarr;</a>
			<a href="https://europenumber.com/product-category/esim-europe/"
			   class="flex font-bold uppercase !text-[#4a9bed]"
			   style="text-decoration: underline dotted;">Europe &rarr;</a>
			<a href="https://europenumber.com/product-category/esim-united-kingdom/"
			   class="flex font-bold uppercase !text-[#4a9bed]"
			   style="text-decoration: underline dotted;">The UK &rarr;</a>
		</div>

		<!-- Heading — styled like a USP row with dynamic flag icon -->
		<div class="flex items-center justify-center gap-1.5 px-5 pt-[30px] pb-2">
			<img src="/wp-content/uploads/2025/08/round-flag-<?php echo esc_attr( $category_slug ); ?>-100x100.png"
				 alt="<?php echo esc_attr( $category_name ); ?> flag"
				 class="w-4 h-4 rounded-full flex-shrink-0"
				 style="object-fit: cover;">
			<span class="font-bold"><?php echo esc_html( $category_name ); ?> quick links</span>
		</div>

		<!-- Quick-link buttons -->
		<div class="en-ql-wrap px-5 pb-5">
			<?php
			while ( $query->have_posts() ) :
				$query->the_post();
				/** @var WC_Product $product */
				$product = wc_get_product( get_the_ID() );
				if ( ! $product ) {
					continue;
				}
				$product_id     = get_the_ID();
				$link           = get_permalink();
				$display_size   = get_post_meta( $product_id, 'display size', true ) ?: '';
				$display_units  = rtrim( get_post_meta( $product_id, 'display units', true ) ?: '' );
				$traffic_policy = get_post_meta( $product_id, 'traffic policy', true );
				$expiry_days    = get_post_meta( $product_id, 'download_expiry_days', true ) ?: '';
				$validity       = $expiry_days ? $expiry_days . ' Days' : '';
				$price          = wc_price( $product->get_price() );

				// Build display title — append "Data Only" when no calls, matching dropdown logic
				if ( $display_size && $display_units ) {
					$display_title = $display_size . $display_units;
				} else {
					$display_title = get_the_title();
				}
				if ( $traffic_policy === 'data' ) {
					$display_title .= ' Data Only';
				}

				$btn_label = en_ql_traffic_label( $traffic_policy );
				?>
				<a href="<?php echo esc_url( $link ); ?>" class="en-ql-btn">
					<span class="en-ql-title"><?php echo esc_html( $display_title ); ?></span>
					<span class="en-ql-price"><?php echo $price; ?></span>
					<span class="en-ql-label"><?php echo esc_html( $btn_label ); ?></span>
				</a>
			<?php
			endwhile;
			wp_reset_postdata();
			?>
			<!-- Static image quick link -->
			<a href="https://europenumber.com/product-category/esim-europe/" class="en-ql-btn en-ql-btn--image">
				<img src="https://europenumber.com/wp-content/uploads/2026/05/quick-links-europe-7.webp"
					 alt="Europe eSIM plans"
					 class="en-ql-img">
			</a>
		</div>

		<!-- Selling points / trust bar — BOTTOM -->
		<div class="flex flex-wrap gap-2 px-6 py-3.5 bg-[#EDF2F7] border-t border-[#e2e8f0]">
		<!-- List -->
		<ul class="items-center !mx-auto uppercase grid max-md:grid-cols-2 grid-cols-4 gap-[12px] w-fit max-w-4xl" style="padding:0; justify-content:center;font-size:90%; font-family: var(--global-heading-font-family)">
		  <li class="flex gap-x-2">
			<span class="mt-0.5 size-5 flex justify-center items-center rounded-full bg-blue-50 text-blue-600 dark:bg-blue-800/30 dark:text-blue-500">
			  <svg width="26px" height="26px" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
				  <path fill-rule="evenodd" clip-rule="evenodd" d="M8 16L4.35009 13.3929C2.24773 11.8912 1 9.46667 1 6.88306V3L8 0L15 3V6.88306C15 9.46667 13.7523 11.8912 11.6499 13.3929L8 16ZM12.2071 5.70711L10.7929 4.29289L7 8.08579L5.20711 6.29289L3.79289 7.70711L7 10.9142L12.2071 5.70711Z" fill="#00BCFF"></path>
			  </svg>
			</span>
			<div class="grow">
			  <span class="dark:text-white">
				  <span class="font-bold"><a href="javascript:void(0)"
   class="flex open-modal-dialog !text-[#4a9bed] items-center"
   data-modal="modal_window_countries"
   role="button"
   aria-haspopup="dialog"
   aria-controls="modal_window_countries"
   aria-label="View list of countries for calls and SMS" style="text-decoration: underline dotted;">Network <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M19.5 4.5h-7V6h4.44l-5.97 5.97 1.06 1.06L18 7.06v4.44h1.5v-7Zm-13 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3H17v3a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h3V5.5h-3Z"></path></svg></a></span>
			  </span>
			</div>
		  </li>
		  <li class="flex gap-x-2">
			<span class="mt-0.5 size-5 flex justify-center items-center rounded-full bg-blue-50 text-blue-600 dark:bg-blue-800/30 dark:text-blue-500">
			  <svg width="26px" height="26px" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
				  <path fill-rule="evenodd" clip-rule="evenodd" d="M8 16L4.35009 13.3929C2.24773 11.8912 1 9.46667 1 6.88306V3L8 0L15 3V6.88306C15 9.46667 13.7523 11.8912 11.6499 13.3929L8 16ZM12.2071 5.70711L10.7929 4.29289L7 8.08579L5.20711 6.29289L3.79289 7.70711L7 10.9142L12.2071 5.70711Z" fill="#00BCFF"></path>
			  </svg>
			</span>
			<div class="grow">
			  <span class="dark:text-white">
				  <span class="font-bold"><a href="javascript:void(0)"
   class="flex open-modal-dialog !text-[#4a9bed] items-center"
   data-modal="modal_window_poi_a"
   role="button"
   aria-haspopup="dialog"
   aria-controls="modal_window_poi_a"
   aria-label="View more about your France Phone Number" style="text-decoration: underline dotted;">+33 number <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M19.5 4.5h-7V6h4.44l-5.97 5.97 1.06 1.06L18 7.06v4.44h1.5v-7Zm-13 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3H17v3a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h3V5.5h-3Z"></path></svg></a></span>
			  </span>
			</div>
		  </li>
		  <li class="flex gap-x-2">
			<span class="mt-0.5 size-5 flex justify-center items-center rounded-full bg-blue-50 text-blue-600 dark:bg-blue-800/30 dark:text-blue-500">
			  <svg width="26px" height="26px" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
				  <path fill-rule="evenodd" clip-rule="evenodd" d="M8 16L4.35009 13.3929C2.24773 11.8912 1 9.46667 1 6.88306V3L8 0L15 3V6.88306C15 9.46667 13.7523 11.8912 11.6499 13.3929L8 16ZM12.2071 5.70711L10.7929 4.29289L7 8.08579L5.20711 6.29289L3.79289 7.70711L7 10.9142L12.2071 5.70711Z" fill="#00BCFF"></path>
			  </svg>
			</span>
			<div class="grow">
			  <span class="dark:text-white">
				  <span class="font-bold"><a href="javascript:void(0)"
   class="flex open-modal-dialog !text-[#4a9bed] items-center"
   data-modal="modal_window_poi_b"
   role="button"
   aria-haspopup="dialog"
   aria-controls="modal_window_poi_b"
   aria-label="View more Travel ready" style="text-decoration: underline dotted;">Travel ready<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M19.5 4.5h-7V6h4.44l-5.97 5.97 1.06 1.06L18 7.06v4.44h1.5v-7Zm-13 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3H17v3a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h3V5.5h-3Z"></path></svg></a></span>
			  </span>
			</div>
		  </li>
		  <li class="flex gap-x-2">
			<span class="mt-0.5 size-5 flex justify-center items-center rounded-full bg-blue-50 text-blue-600 dark:bg-blue-800/30 dark:text-blue-500">
			  <svg width="26px" height="26px" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
				  <path fill-rule="evenodd" clip-rule="evenodd" d="M8 16L4.35009 13.3929C2.24773 11.8912 1 9.46667 1 6.88306V3L8 0L15 3V6.88306C15 9.46667 13.7523 11.8912 11.6499 13.3929L8 16ZM12.2071 5.70711L10.7929 4.29289L7 8.08579L5.20711 6.29289L3.79289 7.70711L7 10.9142L12.2071 5.70711Z" fill="#00BCFF"></path>
			  </svg>
			</span>
			<div class="grow">
			  <span class="dark:text-white">
				<span class="font-bold"><a href="javascript:void(0)"
   class="flex open-modal-dialog !text-[#4a9bed] items-center"
   data-modal="modal_window_poi_c"
   role="button"
   aria-haspopup="dialog"
   aria-controls="modal_window_poi_c"
   aria-label="View more about Data Users eSIM Comparison" style="text-decoration: underline dotted;">Comparison<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M19.5 4.5h-7V6h4.44l-5.97 5.97 1.06 1.06L18 7.06v4.44h1.5v-7Zm-13 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3H17v3a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h3V5.5h-3Z"></path></svg></a></span>
			  </span>
			</div>
		  </li>
		</ul>
		<!-- End List -->
		</div>

	</div>

	</div><!-- /.lg:px-8 -->
	</div><!-- /.site-container -->

	<!--LEAVE THIS HERE Countries Modal Dialog-->
	<dialog class="modal" id="modal_window_countries">
		<div class="button-container"><button data-modal-close="modal_window_countries" class="button new-close-modal">Close</button></div>
		<img src="/wp-content/uploads/2025/12/favicon_black.png" class="image-hero" alt="Europe Number success team" title="Europe Number success team">
		<h2 class="pb-2"><b>Call or SMS</b></h2>
		<p><?php echo esc_html( $region_countries ); ?></p>
		<p class="mt-2"><b>Receive calls worldwide when in these regions.</b></p>
		<div class="author">
			<img src="/wp-content/uploads/2025/08/help-assistant.png" width="96" height="96" alt="Europe Number success team" title="Europe Number success team" class="avatar">
			<span class="author-name">Your eSIM activates in all these regions</span>
		</div>
	</dialog>
	<!--//Countries Modal Dialog-->

	<?php
	return ob_get_clean();
}
// ---------------------------------------------------------------------------
// Shortcode  [en_quick_links]  or  [en_quick_links category="france"]
// ---------------------------------------------------------------------------
add_shortcode( 'en_quick_links', function ( $atts ) {
	$atts = shortcode_atts(
		[ 'category' => '' ],
		$atts,
		'en_quick_links'
	);
	return en_quick_links_module( $atts['category'] ?: null );
} );
// ---------------------------------------------------------------------------
// Direct output — runs when snippet executes on the page
// ---------------------------------------------------------------------------
echo en_quick_links_module();
