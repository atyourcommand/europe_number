<?php
/**
 * Europe Products Module
 *
 * Shortcode: [europe_products]
 * Params:
 *   category="Europe"      – default selected category
 *   default_data="20GB"    – default selected data value  (matched loosely, e.g. "20 GB" == "20GB")
 *
 * Behaviour
 * ─────────
 * • Two dropdowns (Category + Data) live above a single product card.
 * • Changing either dropdown instantly swaps the card contents via JS — no page reload.
 * • The card shows: product title · meta traffic_policy · price · add-to-cart button.
 * • Add-to-cart uses the WooCommerce Store REST API; on success the button becomes
 *   "View Cart →" and WC mini-cart fragments are refreshed.
 *
 * Requirements: WordPress + WooCommerce.
 * Styles: Tailwind CSS Play CDN (loaded in <head>).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Tailwind CDN — disabled; WindPress compiles Tailwind instead
// ─────────────────────────────────────────────────────────────────────────────
/* Tailwind CDN disabled — WindPress compiles Tailwind instead
add_action( 'wp_enqueue_scripts', function () {
	if ( ! wp_script_is( 'tailwind-cdn', 'registered' ) ) {
		wp_register_script( 'tailwind-cdn', 'https://cdn.tailwindcss.com', [], null, false );
	}
}, 5 );
*/

// ─────────────────────────────────────────────────────────────────────────────
// Shortcode
// ─────────────────────────────────────────────────────────────────────────────
add_shortcode( 'europe_products', function ( $atts ) {

	if ( ! function_exists( 'wc_get_products' ) ) {
		return '<p>WooCommerce is required for this module.</p>';
	}

	$atts = shortcode_atts(
		[
			'category'     => 'Europe',
			'default_data' => '30GB',
		],
		$atts,
		'europe_products'
	);

	// On a product category page, override the default and lock the dropdown.
	$default_category = $atts['category'];
	$disable_category = false;

	if ( function_exists( 'is_product_category' ) && is_product_category() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			$default_category = $term->name;
			$disable_category = true;
		}
	}

	/* Tailwind CDN disabled — WindPress compiles Tailwind instead
	if ( ! wp_script_is( 'tailwind-cdn', 'enqueued' ) ) {
		wp_enqueue_script( 'tailwind-cdn', 'https://cdn.tailwindcss.com', [], null, false );
	}
	*/

	// Build payload and inject JS once per page, even if shortcode appears twice.
	static $injected = false;
	if ( ! $injected ) {
		$injected = true;
		$payload  = ep_build_payload();

		add_action( 'wp_footer', function () use ( $payload ) {
			echo '<script id="ep-data">window.epData=' . wp_json_encode( $payload ) . ';</script>' . "\n";
			echo '<script id="ep-js">' . ep_js() . '</script>' . "\n";
		}, 20 );
	}

	return ep_html( $default_category, $atts['default_data'], $disable_category );
} );

// ─────────────────────────────────────────────────────────────────────────────
// Data payload
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Try a list of meta key variants in order; return the first non-empty value.
 * Handles the inconsistent naming conventions common across WooCommerce setups
 * (e.g. "Display size" vs "display_size" vs "_display_size").
 */
function ep_get_meta( $id, ...$keys ) {
	foreach ( $keys as $key ) {
		$val = get_post_meta( $id, $key, true );
		if ( $val !== '' && $val !== false && $val !== null ) {
			return (string) $val;
		}
	}
	return '';
}

function ep_build_payload() {

	$raw      = wc_get_products( [ 'limit' => -1, 'status' => 'publish' ] );
	$products = [];
	$cat_set  = [];

	foreach ( $raw as $product ) {
		$id    = $product->get_id();
		$terms = get_the_terms( $id, 'product_cat' );
		$cats  = [];

		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$cats[]              = $t->name;
				$cat_set[ $t->name ] = true;
			}
		}

		// Data size — CSV column "meta:Display size" (numeric) + "meta:Display units" (e.g. "GB")
		// WooCommerce CSV importers may store the key with or without the "meta:" prefix.
		$display_size  = ep_get_meta( $id, 'display size', 'meta:Display size', 'Display size', 'display_size' );
		$display_units = ep_get_meta( $id, 'display units', 'meta:Display units', 'Display units', 'display_units' );
		if ( $display_size && $display_units ) {
			$display_size = $display_size . $display_units;   // e.g. "20" + "GB" → "20GB"
		} elseif ( $display_size && is_numeric( $display_size ) ) {
			$display_size = $display_size . 'GB';             // assume GB if units missing
		}
		// Last resort: parse GB value from product title
		if ( ! $display_size && preg_match( '/(\d+)\s*GB/i', $product->get_name(), $m ) ) {
			$display_size = $m[1] . 'GB';
		}

		// Traffic policy — actual WP meta key is lowercase with space: "traffic policy"
		$traffic_policy = ep_get_meta( $id, 'traffic policy', 'meta:Traffic policy', 'Traffic policy', 'traffic_policy' );

		// Validity — meta:download_expiry_days (numeric days, e.g. 15, 30)
		$expiry_days = ep_get_meta( $id, 'download_expiry_days', 'meta:download_expiry_days', '_download_expiry' );

		$products[] = [
			'id'             => $id,
			'title'          => $product->get_name(),
			'sku'            => $product->get_sku(),
			'add_to_cart_url' => $product->add_to_cart_url(),
			'price'          => (float) $product->get_price(),
			'categories'     => $cats,
			'display_size'   => $display_size,
			'traffic_policy' => $traffic_policy,
			'expiry_days'    => $expiry_days ? (int) $expiry_days : 0,
		];
	}

	$categories = array_keys( $cat_set );
	sort( $categories );

	return [
		'products'     => $products,
		'categories'   => $categories,
		'cartUrl'      => wc_get_cart_url(),
		'currency'     => [
			'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'position' => get_option( 'woocommerce_currency_pos', 'left' ),
			'decimals' => wc_get_price_decimals(),
		],

		// ── Hero background images ───────────────────────────────────────────
		// Add one entry per category as images become available.
		// Key = WooCommerce category name. Value = filename inside heroBase.
		'heroBase'    => 'https://europenumber.com/wp-content/uploads/2026/05/',
		'heroDefault' => 'hero-default-1-600x467.webp',
		'heroImages'  => [
			'Europe' => 'hero-europe-1-600x467.webp',
			'France' => 'hero-france-1-600x467.webp',
			'Spain'  => 'hero-spain-1-600x467.webp',
			'UK'     => 'hero-uk-1-600x467.webp',
		],
		'brandIcon'   => ( function () {
			$id  = attachment_url_to_postid( 'https://europenumber.com/wp-content/uploads/2025/12/favicon_black.png' );
			$src = $id ? wp_get_attachment_image_src( $id, 'thumbnail' ) : false;
			return $src ? $src[0] : 'https://europenumber.com/wp-content/uploads/2025/12/favicon_black.png';
		} )(),
	];
}

// ─────────────────────────────────────────────────────────────────────────────
// HTML shell
// ─────────────────────────────────────────────────────────────────────────────
function ep_html( $default_category = 'Europe', $default_data = '30GB', $disable_category = false ) {
	ob_start();
	?>
	<div class="site-container">
	<div id="ep-module"
		class="w-full max-w-lg lg:max-w-4xl mx-auto font-sans"
		data-default-category="<?php echo esc_attr( $default_category ); ?>"
		data-default-data="<?php echo esc_attr( $default_data ); ?>"
		data-disable-category="<?php echo $disable_category ? 'true' : 'false'; ?>">

		<p aria-hidden="true" class="text-center text-base/7 whitespace-pre max-sm:px-4 !mb-[5px]"><small>- Choose your eSIM -</small></p>

		<!-- ── Dropdowns ── -->
		<div class="flex gap-3 mb-3">

			<!-- Category -->
			<div class="flex-1">
				<label for="ep-category"
					class="hidden block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">
					Category
				</label>
				<select id="ep-category"
					class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm
					       text-gray-900 shadow-sm cursor-pointer
					       focus:border-indigo-500 focus:outline-none focus:ring-2
					       focus:ring-indigo-500/30 transition-colors
					       disabled:opacity-60 disabled:cursor-not-allowed">
				</select>
			</div>

			<!-- Data -->
			<div class="flex-1 min-w-0">
				<label id="ep-data-label" for="ep-data"
					class="hidden block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">
					Data
				</label>
				<select id="ep-data"
					class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm
					       text-gray-900 shadow-sm cursor-pointer
					       focus:border-indigo-500 focus:outline-none focus:ring-2
					       focus:ring-indigo-500/30 transition-colors
					       disabled:opacity-40 disabled:cursor-not-allowed"
					disabled>
				</select>
			</div>

			<!-- Quantity -->
			<div class="flex-1 min-w-0">
				<label for="ep-qty"
					class="hidden block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">
					Qty
				</label>
				<select id="ep-qty"
					class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm
					       text-gray-900 shadow-sm cursor-pointer
					       focus:border-indigo-500 focus:outline-none focus:ring-2
					       focus:ring-indigo-500/30 transition-colors">
					<option value="1">1</option>
					<option value="2">2</option>
					<option value="3">3</option>
					<option value="4">4</option>
					<option value="5">5</option>
				</select>
			</div>

			<!-- Price total -->
			<div class="flex-1 min-w-0">
				<p class="hidden block text-xs font-semibold uppercase tracking-wide text-gray-500 !mb-1">
					Price
				</p>
				<p id="ep-price"
					class="py-2 text-sm font-semibold text-gray-900 text-left min-h-[2.375rem]"></p>
			</div>

		</div>

		<!-- ── Product title (above card) ── -->
		<p id="ep-product-title"
			class="hidden text-base text-center text-gray-700 font-medium mb-3 min-h-[1.5rem]"></p>

		<!-- ── Product card ── -->
		<div id="ep-card"
			class="relative rounded-2xl border border-gray-200 shadow-sm overflow-hidden min-h-[550px]">
			<!-- background image layer — fades independently of the content -->
			<div id="ep-card-bg"
				style="position:absolute;inset:0;background-size:cover;background-position:center;
				       opacity:0;transition:opacity 0.5s ease;pointer-events:none;"></div>
			<!-- gradient overlay keeps white text readable over any image -->
			<div class="absolute inset-0 bg-gradient-to-b from-black/20 to-black/60 pointer-events-none"></div>
			<!-- inner fills the full card via absolute positioning so flex children have a definite height -->
			<div id="ep-card-inner" class="absolute inset-0 flex flex-col overflow-hidden">
				<div class="p-6 text-center text-sm text-gray-400">Loading&hellip;</div>
			</div>
		</div>

	</div>
	</div>
	<?php
	return ob_get_clean();
}

// ─────────────────────────────────────────────────────────────────────────────
// JavaScript
// ─────────────────────────────────────────────────────────────────────────────
function ep_js() {
	// phpcs:disable
	return <<<'JSEOF'
(function () {
	'use strict';

	var d        = window.epData;
	var module   = document.getElementById('ep-module');
	var selCat   = document.getElementById('ep-category');
	var selData  = document.getElementById('ep-data');
	var selQty   = document.getElementById('ep-qty');
	var dLabel   = document.getElementById('ep-data-label');
	var card     = document.getElementById('ep-card');
	var cardBg   = document.getElementById('ep-card-bg');
	var inner    = document.getElementById('ep-card-inner');
	var priceEl  = document.getElementById('ep-price');
	var titleEl  = document.getElementById('ep-product-title');

	if (!d || !module || !selCat || !selData || !card || !inner) return;

	var disableCat = module.dataset.disableCategory === 'true';

	// ── state ────────────────────────────────────────────────────────────────

	var state = {
		category  : '',
		dataValue : '',
		quantity  : 1,
		inCart    : {},   // { productId: true }
	};

	// ── helpers ──────────────────────────────────────────────────────────────

	/** Safely escape a string for innerHTML */
	function esc(str) {
		var el = document.createElement('div');
		el.appendChild(document.createTextNode(String(str)));
		return el.innerHTML;
	}

	/** Escape a string for use inside a double-quoted HTML attribute */
	function escAttr(str) {
		return esc(str).replace(/"/g, '&quot;');
	}

	/** Format a numeric amount using the WooCommerce currency settings */
	function formatPrice(amount) {
		var c   = d.currency || {};
		var sym = c.symbol   || '';
		var dec = typeof c.decimals === 'number' ? c.decimals : 2;
		var pos = c.position || 'left';
		var num = amount.toFixed(dec);
		if (pos === 'right')       return num + sym;
		if (pos === 'right_space') return num + ' ' + sym;
		if (pos === 'left_space')  return sym + ' ' + num;
		return sym + num;
	}

	/** Normalise a data-size value so "20 GB" and "20GB" compare equal */
	function normData(v) {
		return String(v).toLowerCase().replace(/\s+/g, '');
	}

	/** Sort data-size strings numerically (1GB < 5GB < 10GB < 20GB) */
	function sortDataValues(arr) {
		return arr.slice().sort(function (a, b) {
			return (parseFloat(a) || 0) - (parseFloat(b) || 0);
		});
	}

	// ── hero background ──────────────────────────────────────────────────────

	var bgSeq = 0; // prevents stale async loads from overwriting a newer image

	function updateCardBackground(catName) {
		if (!cardBg) return;

		var seq      = ++bgSeq;
		var base     = d.heroBase    || '';
		var fallback = base + (d.heroDefault || 'hero-default-1-300x233.webp');
		var filename = d.heroImages && d.heroImages[catName];
		var target   = filename ? base + filename : fallback;

		cardBg.style.opacity = '0';

		function apply(url) {
			if (seq !== bgSeq) return; // a newer call already won
			cardBg.style.backgroundImage = 'url(' + url + ')';
			cardBg.style.opacity         = '1';
		}

		if (target === fallback) {
			// No custom image for this category — use default immediately
			setTimeout(function () { apply(fallback); }, 20);
			return;
		}

		var img    = new Image();
		img.onload  = function () { apply(target); };
		img.onerror = function () { apply(fallback); };
		img.src     = target;
	}

	/**
	 * Unique display_size values for products in the given category,
	 * sorted numerically (1GB < 5GB < 10GB < 20GB).
	 * Source: meta:Display size on each WooCommerce product.
	 */
	function dataOptionsFor(catName) {
		var seen = {};
		d.products.forEach(function (p) {
			if (catName && !p.categories.includes(catName)) return;
			if (p.display_size) seen[p.display_size] = true;
		});
		return sortDataValues(Object.keys(seen));
	}

	/**
	 * Find the single product matching category + display_size selection.
	 * Falls back to the first product in the category when no exact match.
	 */
	function findProduct() {
		var inCat = d.products.filter(function (p) {
			return !state.category || p.categories.includes(state.category);
		});

		if (!inCat.length) return null;
		if (!state.dataValue) return inCat[0];

		return inCat.find(function (p) {
			return normData(p.display_size) === normData(state.dataValue);
		}) || inCat[0];
	}

	// ── render ───────────────────────────────────────────────────────────────

	function renderCategoryDropdown() {
		selCat.innerHTML = '';
		d.categories.forEach(function (c) {
			var o         = document.createElement('option');
			o.value       = c;
			o.textContent = c;
			if (c === state.category) o.selected = true;
			selCat.appendChild(o);
		});
		selCat.disabled = disableCat;
	}

	function renderDataDropdown() {
		var options = dataOptionsFor(state.category);

		if (dLabel) dLabel.textContent = 'Data';

		selData.innerHTML = '';

		if (!options.length) {
			var placeholder = document.createElement('option');
			placeholder.value       = '';
			placeholder.textContent = 'N/A';
			selData.appendChild(placeholder);
			selData.disabled = true;
			state.dataValue  = '';
			return;
		}

		options.forEach(function (v) {
			var o         = document.createElement('option');
			o.value       = v;
			o.textContent = v;
			if (normData(v) === normData(state.dataValue)) o.selected = true;
			selData.appendChild(o);
		});

		// Sync state.dataValue — prefer exact match, then last (largest) option
		var matched = options.find(function (v) {
			return normData(v) === normData(state.dataValue);
		});
		state.dataValue  = matched || options[options.length - 1] || '';
		selData.value    = state.dataValue;
		selData.disabled = false;
	}

	function renderCard() {
		var p = findProduct();

		if (!p) {
			inner.innerHTML = '<div class="flex-1 p-6 flex items-center justify-center text-sm text-white/70">'
				+ 'No product available for this selection.</div>';
			if (titleEl) titleEl.textContent = '';
			if (priceEl) priceEl.innerHTML   = '';
			return;
		}

		var inCart  = state.inCart[p.id];

		var spinnerSvg = '<span class="kadence-svg-iconset svg-baseline">'
			+ '<svg class="kadence-svg-icon kadence-spinner-svg" fill="currentColor" version="1.1"'
			+ ' xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">'
			+ '<title>Loading</title>'
			+ '<path d="M16 6h-6l2.243-2.243c-1.133-1.133-2.64-1.757-4.243-1.757s-3.109 0.624-4.243 1.757'
			+ 'c-1.133 1.133-1.757 2.64-1.757 4.243s0.624 3.109 1.757 4.243c1.133 1.133 2.64 1.757 4.243 1.757'
			+ 's3.109-0.624 4.243-1.757c0.095-0.095 0.185-0.192 0.273-0.292l1.505 1.317'
			+ 'c-1.466 1.674-3.62 2.732-6.020 2.732-4.418 0-8-3.582-8-8s3.582-8 8-8'
			+ 'c2.209 0 4.209 0.896 5.656 2.344l2.343-2.344v6z"></path>'
			+ '</svg></span>';

		var checkSvg = '<span class="kadence-svg-iconset svg-baseline">'
			+ '<svg class="kadence-svg-icon kadence-check-svg" fill="currentColor" version="1.1"'
			+ ' xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">'
			+ '<title>Done</title>'
			+ '<path d="M14 2.5l-8.5 8.5-3.5-3.5-1.5 1.5 5 5 10-10z"></path>'
			+ '</svg></span>';

		var btnHtml;
		if (inCart) {
			btnHtml = '<a href="' + esc(d.cartUrl) + '"'
				+ ' class="button product_type_simple block w-full text-center">'
				+ 'View Cart &rarr;</a>';
		} else {
			btnHtml = '<div class="product-action-wrap">'
				+ '<a href="' + esc(p.add_to_cart_url) + '"'
				+ ' data-quantity="' + state.quantity + '"'
				+ ' class="button product_type_simple add_to_cart_button ajax_add_to_cart w-full block text-center"'
				+ ' data-product_id="' + p.id + '"'
				+ ' data-product_sku="' + escAttr(p.sku) + '"'
				+ ' aria-label="Add to cart: &quot;' + escAttr(p.title) + '&quot;"'
				+ ' rel="nofollow"'
				+ ' data-success_message="&quot;' + escAttr(p.title) + '&quot; has been added to your cart"'
				+ ' role="button">'
				+ 'Add to cart'
				+ (p.price > 0 ? ' &mdash; ' + esc(formatPrice(p.price * state.quantity)) : '')
				+ spinnerSvg
				+ checkSvg
				+ '</a>'
				+ '<span id="woocommerce_loop_add_to_cart_link_describedby_' + p.id + '"'
				+ ' class="screen-reader-text"></span>'
				+ '</div>';
		}

		// ── Populate elements outside the card ─────────────────────────────
		if (titleEl) titleEl.textContent = '';
		if (priceEl) priceEl.textContent = p.price > 0
			? formatPrice(p.price * state.quantity)
			: '';

		// ── Badge data ──────────────────────────────────────────────────────
		var policyBadges = {
			'calls'      : ['Calls SMS', 'No data'],
			'calls_data' : ['Calls SMS', 'Data'],
			'data'       : ['Data only'],
		};
		var badges = (p.traffic_policy && policyBadges[p.traffic_policy])
			? policyBadges[p.traffic_policy]
			: [];
		var badgeHtml = badges.map(function (label) {
			return '<span class="inline-block rounded-full bg-white/20 text-white'
				+ ' text-xs lg:text-sm font-bold px-2.5 py-1 backdrop-blur-sm">' + label + '</span>';
		}).join('');

		inner.innerHTML =
			'<div class="flex-1 p-6 flex flex-col gap-3">'

			// ── Top row: pills left, brand icon right ───────────────────────
			+ '<div class="flex items-start justify-between gap-2">'
			+   '<div class="flex flex-wrap items-center gap-2">'
			+     badgeHtml
			+     (p.expiry_days ? '<span class="text-xs lg:text-sm font-bold text-white/80">' + p.expiry_days + ' days</span>' : '')
			+   '</div>'
			+   (d.brandIcon
				? '<img src="' + esc(d.brandIcon) + '" alt="" aria-hidden="true"'
				+ ' class="w-8 h-8 object-contain flex-shrink-0 opacity-90">'
				: '')
			+ '</div>'

			// ── Spacer — pushes heading + CTA to bottom ────────────────────
			+ '<div class="flex-1"></div>'

			// ── Category name — bottom third ────────────────────────────────
			+ '<h2 class="!text-white !font-bold !text-5xl uppercase tracking-wide text-center drop-shadow">'
			+   esc(state.category || p.categories[0] || '')
			+ '</h2>'

			// ── Product name ─────────────────────────────────────────────────
			+ '<p class="text-white/90 text-sm text-center">' + esc(p.title) + '</p>'

			// ── CTA ─────────────────────────────────────────────────────────
			+ '<div>' + btnHtml + '</div>'

			+ '</div>';

	}

	// ── event listeners ──────────────────────────────────────────────────────

	selCat.addEventListener('change', function () {
		var prevCategory = state.category;
		state.category   = this.value;
		state.dataValue  = module.dataset.defaultData || '30GB';

		if (typeof gtag === 'function') {
			gtag('event', 'esim_category_select', {
				esim_category          : state.category,
				esim_previous_category : prevCategory,
			});
		}

		updateCardBackground(state.category);
		renderDataDropdown();
		renderCard();
	});

	selData.addEventListener('change', function () {
		var prevData    = state.dataValue;
		state.dataValue = this.value;

		if (typeof gtag === 'function') {
			gtag('event', 'esim_data_select', {
				esim_data          : state.dataValue,
				esim_previous_data : prevData,
				esim_category      : state.category,
			});
		}

		renderCard();
	});

	if (selQty) {
		selQty.addEventListener('change', function () {
			state.quantity = parseInt(this.value, 10) || 1;
			renderCard();
		});
	}

	// ── init ─────────────────────────────────────────────────────────────────

	function init() {
		state.category  = module.dataset.defaultCategory || '';
		state.dataValue = module.dataset.defaultData      || '';

		renderCategoryDropdown();
		renderDataDropdown();
		updateCardBackground(state.category);
		renderCard();

		// Listen for WooCommerce native AJAX add-to-cart success
		if (typeof jQuery !== 'undefined') {
			jQuery(document.body).on('added_to_cart', function (e, fragments, cartHash, $btn) {
				var id = $btn && parseInt($btn.data('product_id'), 10);
				if (id) {
					state.inCart[id] = true;
					renderCard();
				}
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

}());
JSEOF;
	// phpcs:enable
}
