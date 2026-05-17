<?php
/**
 * Europe Products Module
 *
 * Shortcode: [europe_products]
 * Optional attr: category="Europe" (overrides the default)
 *
 * Drop into a Code Snippets plugin entry, a mu-plugin file, or functions.php.
 * Requires WooCommerce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Enqueue Tailwind CDN (Play CDN — processes classes dynamically in-browser)
// ---------------------------------------------------------------------------
add_action( 'wp_enqueue_scripts', function () {
	if ( ! wp_script_is( 'tailwind-cdn', 'registered' ) ) {
		wp_register_script( 'tailwind-cdn', 'https://cdn.tailwindcss.com', [], null, false );
	}
}, 5 );

// ---------------------------------------------------------------------------
// Shortcode [europe_products]
// ---------------------------------------------------------------------------
add_shortcode( 'europe_products', function ( $atts ) {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return '<p class="text-red-600">WooCommerce is required for this module.</p>';
	}

	$atts = shortcode_atts( [ 'category' => 'Europe' ], $atts, 'europe_products' );

	if ( ! wp_script_is( 'tailwind-cdn', 'enqueued' ) ) {
		wp_enqueue_script( 'tailwind-cdn', 'https://cdn.tailwindcss.com', [], null, false );
	}

	// Inject data + JS once per page load
	static $registered = false;
	if ( ! $registered ) {
		$registered = true;
		$payload    = ep_build_product_payload();
		add_action( 'wp_footer', function () use ( $payload ) {
			echo '<script id="ep-data-json">window.epData=' . wp_json_encode( $payload ) . ';</script>' . "\n";
			echo '<script id="ep-module-js">' . ep_module_js() . '</script>' . "\n";
		}, 20 );
	}

	return ep_module_html( $atts['category'] );
} );

// ---------------------------------------------------------------------------
// Build product data payload for JS
// ---------------------------------------------------------------------------
function ep_build_product_payload() {
	$raw      = wc_get_products( [ 'limit' => -1, 'status' => 'publish' ] );
	$products = [];
	$cat_set  = [];

	foreach ( $raw as $product ) {
		$terms     = get_the_terms( $product->get_id(), 'product_cat' );
		$cat_names = [];

		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$cat_names[]          = $term->name;
				$cat_set[ $term->name ] = true;
			}
		}

		// Collect product attributes (global taxonomy + local)
		$attributes = [];
		foreach ( $product->get_attributes() as $key => $attr ) {
			$label = wc_attribute_label( $key );
			if ( $attr->is_taxonomy() ) {
				$vals = wc_get_product_terms( $product->get_id(), $key, [ 'fields' => 'names' ] );
			} else {
				$vals = array_map( 'trim', (array) $attr->get_options() );
			}
			$attributes[ $label ] = array_values( array_filter( $vals ) );
		}

		$products[] = [
			'id'         => $product->get_id(),
			'title'      => $product->get_name(),
			'price_html' => $product->get_price_html(),
			'categories' => $cat_names,
			'attributes' => $attributes,
		];
	}

	$categories = array_keys( $cat_set );
	sort( $categories );

	return [
		'products'        => $products,
		'categories'      => $categories,
		'cartUrl'         => wc_get_cart_url(),
		'storeApiBase'    => esc_url_raw( get_rest_url( null, 'wc/store/v1' ) ),
		'storeNonce'      => wp_create_nonce( 'wc_store_api' ),
		'defaultCategory' => 'Europe',
	];
}

// ---------------------------------------------------------------------------
// Module HTML shell (dropdowns + grid placeholder)
// ---------------------------------------------------------------------------
function ep_module_html( $default_category = 'Europe' ) {
	ob_start();
	?>
	<div id="ep-module"
		class="w-full max-w-6xl mx-auto px-4 py-8 font-sans"
		data-default-category="<?php echo esc_attr( $default_category ); ?>">

		<!-- Filters -->
		<div class="flex flex-wrap gap-4 mb-6">

			<!-- Category -->
			<div class="flex-1 min-w-48">
				<label for="ep-category"
					class="block text-sm font-medium text-gray-700 mb-1">
					Category
				</label>
				<select id="ep-category"
					class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm
					       text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none
					       focus:ring-1 focus:ring-indigo-500 transition-colors">
				</select>
			</div>

			<!-- Attribute (data) -->
			<div class="flex-1 min-w-48">
				<label id="ep-attr-label" for="ep-attribute"
					class="block text-sm font-medium text-gray-700 mb-1">
					Attribute
				</label>
				<select id="ep-attribute"
					class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm
					       text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none
					       focus:ring-1 focus:ring-indigo-500 transition-colors
					       disabled:opacity-40 disabled:cursor-not-allowed"
					disabled>
					<option value="">All</option>
				</select>
			</div>

		</div>

		<!-- Results count -->
		<p id="ep-count" class="text-xs text-gray-400 mb-4"></p>

		<!-- Product grid -->
		<div id="ep-grid"
			class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
			<p class="col-span-full text-center text-gray-400 py-10">Loading&hellip;</p>
		</div>

	</div>
	<?php
	return ob_get_clean();
}

// ---------------------------------------------------------------------------
// Module JavaScript
// ---------------------------------------------------------------------------
function ep_module_js() {
	// phpcs:disable
	return <<<'JSEOF'
(function () {
	'use strict';

	var d       = window.epData;
	var module  = document.getElementById('ep-module');
	var selCat  = document.getElementById('ep-category');
	var selAttr = document.getElementById('ep-attribute');
	var label   = document.getElementById('ep-attr-label');
	var grid    = document.getElementById('ep-grid');
	var counter = document.getElementById('ep-count');

	if (!d || !module || !selCat || !selAttr || !grid) return;

	var state = {
		category : '',
		attrName : '',
		attrValue: '',
		inCart   : {},
		loading  : {},
	};

	// --- helpers -------------------------------------------------------

	function esc(str) {
		var el = document.createElement('div');
		el.appendChild(document.createTextNode(str));
		return el.innerHTML;
	}

	function filteredProducts() {
		return d.products.filter(function (p) {
			if (state.category && !p.categories.includes(state.category)) return false;
			if (state.attrName && state.attrValue) {
				var vals = p.attributes[state.attrName];
				if (!vals || !vals.includes(state.attrValue)) return false;
			}
			return true;
		});
	}

	function attributeMapForCategory(catName) {
		var map = {};
		d.products.forEach(function (p) {
			if (catName && !p.categories.includes(catName)) return;
			Object.keys(p.attributes).forEach(function (name) {
				if (!map[name]) map[name] = new Set();
				p.attributes[name].forEach(function (v) { map[name].add(v); });
			});
		});
		return map;
	}

	// --- render --------------------------------------------------------

	function renderCategoryDropdown() {
		selCat.innerHTML = '<option value="">All Categories</option>';
		d.categories.forEach(function (c) {
			var o       = document.createElement('option');
			o.value     = c;
			o.textContent = c;
			if (c === state.category) o.selected = true;
			selCat.appendChild(o);
		});
	}

	function renderAttributeDropdown() {
		var attrMap = attributeMapForCategory(state.category);
		var names   = Object.keys(attrMap).sort();

		selAttr.innerHTML = '<option value="">All</option>';

		if (!names.length) {
			selAttr.disabled = true;
			state.attrName   = '';
			state.attrValue  = '';
			if (label) label.textContent = 'Attribute';
			return;
		}

		// Keep previous selection if still available, else use first name
		if (!state.attrName || !attrMap[state.attrName]) {
			state.attrName = names[0];
		}
		if (label) label.textContent = state.attrName;

		var values = Array.from(attrMap[state.attrName]).sort();
		values.forEach(function (v) {
			var o       = document.createElement('option');
			o.value     = v;
			o.textContent = v;
			if (v === state.attrValue) o.selected = true;
			selAttr.appendChild(o);
		});

		if (!values.includes(state.attrValue)) state.attrValue = '';
		selAttr.disabled = false;
	}

	function renderGrid() {
		var products = filteredProducts();
		counter.textContent = products.length + ' product' + (products.length !== 1 ? 's' : '') + ' found';

		if (!products.length) {
			grid.innerHTML = '<p class="col-span-full text-center text-gray-400 py-10">No products match this selection.</p>';
			return;
		}

		grid.innerHTML = products.map(function (p) {
			var inCart  = state.inCart[p.id];
			var loading = state.loading[p.id];

			var btnHtml;
			if (inCart) {
				btnHtml = '<a href="' + esc(d.cartUrl) + '"'
					+ ' class="block w-full text-center bg-emerald-600 hover:bg-emerald-700'
					+ ' text-white text-sm font-semibold py-2 px-4 rounded-lg'
					+ ' transition-colors duration-150">'
					+ 'View Cart &rarr;</a>';
			} else {
				btnHtml = '<button data-id="' + p.id + '"'
					+ ' class="ep-atc w-full bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800'
					+ ' text-white text-sm font-semibold py-2 px-4 rounded-lg'
					+ ' transition-colors duration-150 cursor-pointer'
					+ (loading ? ' opacity-60 cursor-wait' : '') + '"'
					+ (loading ? ' disabled' : '') + '>'
					+ (loading ? 'Adding&hellip;' : 'Add to Cart')
					+ '</button>';
			}

			return '<div class="bg-white rounded-xl border border-gray-200 shadow-sm'
				+ ' hover:shadow-md transition-shadow duration-200 p-5 flex flex-col gap-4">'
				+ '<h3 class="text-gray-900 font-semibold text-sm leading-snug line-clamp-2">'
				+ esc(p.title) + '</h3>'
				+ '<div class="text-indigo-700 font-bold text-lg leading-none ep-price">'
				+ p.price_html + '</div>'
				+ '<div class="mt-auto">' + btnHtml + '</div>'
				+ '</div>';
		}).join('');

		grid.querySelectorAll('.ep-atc').forEach(function (btn) {
			btn.addEventListener('click', handleAddToCart);
		});
	}

	// --- add to cart ---------------------------------------------------

	function handleAddToCart(e) {
		var btn = e.currentTarget;
		var id  = parseInt(btn.dataset.id, 10);

		if (state.loading[id]) return;
		state.loading[id] = true;
		renderGrid();

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
			// Signal WooCommerce to refresh mini-cart fragments
			if (typeof jQuery !== 'undefined') {
				jQuery(document.body).trigger('wc_fragment_refresh');
			}
			renderGrid();
		})
		.catch(function () {
			// Fallback: direct add-to-cart URL
			state.loading[id] = false;
			window.location.href = '/?add-to-cart=' + id;
		});
	}

	// --- event listeners -----------------------------------------------

	selCat.addEventListener('change', function () {
		state.category  = this.value;
		state.attrValue = '';
		renderAttributeDropdown();
		renderGrid();
	});

	selAttr.addEventListener('change', function () {
		state.attrValue = this.value;
		renderGrid();
	});

	// --- init ----------------------------------------------------------

	function init() {
		state.category = module.dataset.defaultCategory || d.defaultCategory || '';
		renderCategoryDropdown();
		renderAttributeDropdown();
		renderGrid();
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
