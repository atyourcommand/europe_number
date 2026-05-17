<?php
/**
 * Europe Products Module
 *
 * Shortcode: [europe_products]
 * Params:
 *   category="Europe"      – default selected category
 *   default_data="20GB"    – default selected data value  (matched loosely, e.g. "20 GB" == "20GB")
 *   data_attr=""           – product attribute name used as the data dropdown
 *                            (auto-detected when blank: prefers "Data", then any attr whose
 *                             values all contain GB / MB / TB)
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
// Tailwind CDN — registered early so any add_action can enqueue it later
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', function () {
	if ( ! wp_script_is( 'tailwind-cdn', 'registered' ) ) {
		wp_register_script( 'tailwind-cdn', 'https://cdn.tailwindcss.com', [], null, false );
	}
}, 5 );

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
			'default_data' => '20GB',
			'data_attr'    => '',
		],
		$atts,
		'europe_products'
	);

	if ( ! wp_script_is( 'tailwind-cdn', 'enqueued' ) ) {
		wp_enqueue_script( 'tailwind-cdn', 'https://cdn.tailwindcss.com', [], null, false );
	}

	// Build payload and inject JS once per page, even if shortcode appears twice.
	static $injected = false;
	if ( ! $injected ) {
		$injected = true;
		$payload  = ep_build_payload( $atts['data_attr'] );

		add_action( 'wp_footer', function () use ( $payload ) {
			echo '<script id="ep-data">window.epData=' . wp_json_encode( $payload ) . ';</script>' . "\n";
			echo '<script id="ep-js">' . ep_js() . '</script>' . "\n";
		}, 20 );
	}

	return ep_html( $atts['category'], $atts['default_data'] );
} );

// ─────────────────────────────────────────────────────────────────────────────
// Data payload
// ─────────────────────────────────────────────────────────────────────────────
function ep_build_payload( $forced_data_attr = '' ) {

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

		// Product attributes
		$attributes = [];
		foreach ( $product->get_attributes() as $key => $attr ) {
			$label = wc_attribute_label( $key );
			if ( $attr->is_taxonomy() ) {
				$vals = wc_get_product_terms( $id, $key, [ 'fields' => 'names' ] );
			} else {
				$vals = array_map( 'trim', (array) $attr->get_options() );
			}
			$attributes[ $label ] = array_values( array_filter( $vals ) );
		}

		// traffic_policy custom field (try both common key patterns)
		$traffic_policy = get_post_meta( $id, 'traffic_policy', true );
		if ( ! $traffic_policy ) {
			$traffic_policy = get_post_meta( $id, '_traffic_policy', true );
		}

		$products[] = [
			'id'             => $id,
			'title'          => $product->get_name(),
			'price_html'     => $product->get_price_html(),
			'categories'     => $cats,
			'attributes'     => $attributes,
			'traffic_policy' => (string) $traffic_policy,
		];
	}

	$categories = array_keys( $cat_set );
	sort( $categories );

	return [
		'products'     => $products,
		'categories'   => $categories,
		'dataAttrName' => ep_detect_data_attr( $products, $forced_data_attr ),
		'cartUrl'      => wc_get_cart_url(),
		'storeApiBase' => esc_url_raw( get_rest_url( null, 'wc/store/v1' ) ),
		'storeNonce'   => wp_create_nonce( 'wc_store_api' ),
	];
}

/**
 * Determine which product attribute name represents the data-size dimension.
 *
 * Priority:
 *   1. Shortcode param if provided.
 *   2. Attribute named exactly "Data" (case-insensitive).
 *   3. Attribute whose values universally match a storage-size pattern (GB/MB/TB).
 *   4. First attribute found across all products.
 */
function ep_detect_data_attr( array $products, $forced = '' ) {

	if ( $forced !== '' ) {
		return $forced;
	}

	$attr_names = [];

	foreach ( $products as $p ) {
		foreach ( array_keys( $p['attributes'] ) as $name ) {
			$attr_names[ $name ] = ( $attr_names[ $name ] ?? 0 ) + 1;
		}
	}

	if ( ! $attr_names ) {
		return '';
	}

	// Pass 1 – exact "Data" match
	foreach ( array_keys( $attr_names ) as $name ) {
		if ( strtolower( trim( $name ) ) === 'data' ) {
			return $name;
		}
	}

	// Pass 2 – values look like storage sizes
	$size_pattern = '/^\d+(\.\d+)?\s*(GB|MB|TB)/i';
	foreach ( array_keys( $attr_names ) as $name ) {
		foreach ( $products as $p ) {
			$vals = $p['attributes'][ $name ] ?? [];
			if ( $vals && count( array_filter( $vals, fn( $v ) => preg_match( $size_pattern, $v ) ) ) === count( $vals ) ) {
				return $name;
			}
		}
	}

	// Pass 3 – first attribute found
	return array_key_first( $attr_names );
}

// ─────────────────────────────────────────────────────────────────────────────
// HTML shell
// ─────────────────────────────────────────────────────────────────────────────
function ep_html( $default_category = 'Europe', $default_data = '20GB' ) {
	ob_start();
	?>
	<div id="ep-module"
		class="w-full max-w-lg mx-auto font-sans"
		data-default-category="<?php echo esc_attr( $default_category ); ?>"
		data-default-data="<?php echo esc_attr( $default_data ); ?>">

		<!-- ── Dropdowns ── -->
		<div class="flex flex-wrap gap-3 mb-5">

			<div class="flex-1 min-w-36">
				<label for="ep-category"
					class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">
					Category
				</label>
				<select id="ep-category"
					class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm
					       text-gray-900 shadow-sm cursor-pointer
					       focus:border-indigo-500 focus:outline-none focus:ring-2
					       focus:ring-indigo-500/30 transition-colors">
				</select>
			</div>

			<div class="flex-1 min-w-36">
				<label id="ep-data-label" for="ep-data"
					class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">
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

		</div>

		<!-- ── Product card ── -->
		<div id="ep-card"
			class="rounded-2xl border border-gray-200 bg-white shadow-sm
			       transition-opacity duration-200">
			<div class="p-6 text-center text-sm text-gray-400">Loading&hellip;</div>
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

	var d      = window.epData;
	var module = document.getElementById('ep-module');
	var selCat = document.getElementById('ep-category');
	var selData= document.getElementById('ep-data');
	var dLabel = document.getElementById('ep-data-label');
	var card   = document.getElementById('ep-card');

	if (!d || !module || !selCat || !selData || !card) return;

	// ── state ────────────────────────────────────────────────────────────────

	var state = {
		category  : '',
		dataValue : '',
		inCart    : {},   // { productId: true }
		loading   : {},   // { productId: true }
	};

	// ── helpers ──────────────────────────────────────────────────────────────

	/** Safely escape a string for innerHTML */
	function esc(str) {
		var el = document.createElement('div');
		el.appendChild(document.createTextNode(String(str)));
		return el.innerHTML;
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

	/** All distinct data-attribute values available for a given category */
	function dataOptionsFor(catName) {
		var seen = {};
		d.products.forEach(function (p) {
			if (catName && !p.categories.includes(catName)) return;
			var vals = p.attributes[d.dataAttrName] || [];
			vals.forEach(function (v) { seen[v] = true; });
		});
		return sortDataValues(Object.keys(seen));
	}

	/**
	 * Find the single product that best matches category + data selection.
	 * Falls back to the first product in the category if no data match.
	 */
	function findProduct() {
		var inCat = d.products.filter(function (p) {
			return !state.category || p.categories.includes(state.category);
		});

		if (!inCat.length) return null;
		if (!state.dataValue) return inCat[0];

		var exact = inCat.find(function (p) {
			var vals = p.attributes[d.dataAttrName] || [];
			return vals.some(function (v) {
				return normData(v) === normData(state.dataValue);
			});
		});

		return exact || inCat[0];
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
	}

	function renderDataDropdown() {
		var options = dataOptionsFor(state.category);

		selData.innerHTML = '';

		if (!options.length) {
			selData.disabled = true;
			return;
		}

		// Update label to the attribute name (e.g. "Data" or "GB")
		if (dLabel && d.dataAttrName) dLabel.textContent = d.dataAttrName;

		options.forEach(function (v) {
			var o         = document.createElement('option');
			o.value       = v;
			o.textContent = v;
			if (normData(v) === normData(state.dataValue)) o.selected = true;
			selData.appendChild(o);
		});

		// Sync state.dataValue to what is actually selected after building list
		var matched = options.find(function (v) {
			return normData(v) === normData(state.dataValue);
		});
		state.dataValue = matched || options[options.length - 1] || '';
		selData.value   = state.dataValue;
		selData.disabled = false;
	}

	function renderCard() {
		var p = findProduct();

		if (!p) {
			card.innerHTML = '<div class="p-6 text-center text-sm text-gray-400">'
				+ 'No product available for this selection.</div>';
			return;
		}

		var inCart  = state.inCart[p.id];
		var loading = state.loading[p.id];

		var btnHtml;
		if (inCart) {
			btnHtml = '<a href="' + esc(d.cartUrl) + '"'
				+ ' class="block w-full text-center rounded-xl bg-emerald-600 hover:bg-emerald-700'
				+ ' text-white font-semibold py-3 px-6 transition-colors duration-150">'
				+ 'View Cart &rarr;</a>';
		} else {
			btnHtml = '<button data-id="' + p.id + '"'
				+ ' class="ep-atc block w-full rounded-xl bg-indigo-600 hover:bg-indigo-700'
				+ ' active:bg-indigo-800 text-white font-semibold py-3 px-6'
				+ ' transition-colors duration-150 cursor-pointer'
				+ (loading ? ' opacity-60 pointer-events-none' : '') + '"'
				+ (loading ? ' disabled' : '') + '>'
				+ (loading ? '<span class="inline-block animate-pulse">Adding&hellip;</span>'
				           : 'Add to Cart')
				+ '</button>';
		}

		var policyHtml = '';
		if (p.traffic_policy) {
			policyHtml = '<p class="text-xs text-gray-500 mt-1">' + esc(p.traffic_policy) + '</p>';
		}

		card.innerHTML =
			'<div class="p-6 flex flex-col gap-5">'

			// ── Data dropdown sits above the title ──────────────────────────
			// (The #ep-data select is in the controls bar above the card.
			//  This inner label echoes the current selection for clarity.)
			+ '<div class="flex items-center justify-between gap-2">'
			+   '<span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-50'
			+         ' text-indigo-700 text-xs font-semibold px-3 py-1">'
			+     esc(state.dataValue || '&mdash;')
			+   '</span>'
			+   '<span class="text-xs text-gray-400">' + esc(p.categories.join(', ')) + '</span>'
			+ '</div>'

			// ── Title + traffic policy ──────────────────────────────────────
			+ '<div>'
			+   '<h2 class="text-gray-900 font-bold text-xl leading-snug">'
			+     esc(p.title)
			+   '</h2>'
			+   policyHtml
			+ '</div>'

			// ── Price ───────────────────────────────────────────────────────
			+ '<div class="text-2xl font-extrabold text-indigo-700 leading-none">'
			+   p.price_html
			+ '</div>'

			// ── CTA ─────────────────────────────────────────────────────────
			+ '<div>' + btnHtml + '</div>'

			+ '</div>';

		var atcBtn = card.querySelector('.ep-atc');
		if (atcBtn) atcBtn.addEventListener('click', handleAddToCart);
	}

	// ── add-to-cart ──────────────────────────────────────────────────────────

	function handleAddToCart(e) {
		var btn = e.currentTarget;
		var id  = parseInt(btn.dataset.id, 10);

		if (state.loading[id]) return;
		state.loading[id] = true;
		renderCard();

		fetch(d.storeApiBase + '/cart/add-item', {
			method     : 'POST',
			credentials: 'same-origin',
			headers    : {
				'Content-Type'        : 'application/json',
				'X-WC-Store-API-Nonce': d.storeNonce,
			},
			body: JSON.stringify({ id: id, quantity: 1 }),
		})
		.then(function (r) {
			if (!r.ok) throw new Error(r.status);
			return r.json();
		})
		.then(function () {
			state.inCart[id]  = true;
			state.loading[id] = false;
			if (typeof jQuery !== 'undefined') {
				jQuery(document.body).trigger('wc_fragment_refresh');
			}
			renderCard();
		})
		.catch(function () {
			state.loading[id] = false;
			window.location.href = '/?add-to-cart=' + id;
		});
	}

	// ── event listeners ──────────────────────────────────────────────────────

	selCat.addEventListener('change', function () {
		state.category  = this.value;
		state.dataValue = '';          // reset; renderDataDropdown will re-default
		renderDataDropdown();
		renderCard();
	});

	selData.addEventListener('change', function () {
		state.dataValue = this.value;
		renderCard();
	});

	// ── init ─────────────────────────────────────────────────────────────────

	function init() {
		state.category  = module.dataset.defaultCategory || '';
		state.dataValue = module.dataset.defaultData      || '';

		renderCategoryDropdown();
		renderDataDropdown();
		renderCard();
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
