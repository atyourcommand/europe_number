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

	$raw      = wc_get_products( [ 'limit' => -1, 'status' => 'publish', 'stock_status' => 'instock' ] );
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
		$display_size  = trim( ep_get_meta( $id, 'display size', 'meta:Display size', 'Display size', 'display_size' ) );
		$display_units = trim( ep_get_meta( $id, 'display units', 'meta:Display units', 'Display units', 'display_units' ) );
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
	<style>
	/* ── Pill selects — override any theme styles ─────────────────────────── */
	#ep-module select {
		-webkit-appearance: none !important;
		appearance: none !important;
		background-color: rgba(0,0,0,0.5) !important;
		background-image: none !important;
		-webkit-backdrop-filter: blur(8px) !important;
		backdrop-filter: blur(8px) !important;
		border: 1px solid rgba(255,255,255,0.3) !important;
		border-radius: 9999px !important;
		color: #ffffff !important;
		cursor: pointer !important;
		font-size: 0.875rem !important;
		padding: 0.5rem 2rem 0.5rem 1rem !important;
		width: 100% !important;
		box-shadow: none !important;
		outline: none !important;
	}
	#ep-module select:focus {
		background-color: rgba(0,0,0,0.5) !important;
		border-color: #ffffff !important;
		box-shadow: none !important;
		outline: none !important;
	}
	#ep-module select:disabled {
		opacity: 0.4 !important;
		cursor: not-allowed !important;
	}
	#ep-module select option {
		background-color: #111111 !important;
		color: #ffffff !important;
	}

	/* ── Glass pill button — override theme .button styles ───────────────── */
	#ep-card-btn a.button,
	#ep-card-btn .button {
		display: block !important;
		width: 100% !important;
		text-align: center !important;
		background-color: rgba(0,0,0,0.6) !important;
		-webkit-backdrop-filter: blur(8px) !important;
		backdrop-filter: blur(8px) !important;
		border: 1px solid rgba(255,255,255,0.3) !important;
		border-radius: 9999px !important;
		color: #ffffff !important;
		cursor: pointer !important;
		font-size: 0.875rem !important;
		font-weight: 600 !important;
		letter-spacing: 0.03em !important;
		padding: 0.65rem 1.5rem !important;
		text-decoration: none !important;
		transition: background-color 0.2s ease !important;
	}
	#ep-card-btn a.button:hover,
	#ep-card-btn .button:hover {
		background-color: rgba(0,0,0,0.8) !important;
		color: #ffffff !important;
		text-decoration: none !important;
	}
	#ep-card-btn a.button:focus,
	#ep-card-btn .button:focus {
		outline: none !important;
		box-shadow: 0 0 0 2px rgba(255,255,255,0.5) !important;
	}

	/* ── Brand icon speech bubble ─────────────────────────────────────────── */
	.ep-bubble {
		position: absolute;
		top: calc(100% + 7px);
		right: 0;
		background: rgba(255,255,255,0.2);
		-webkit-backdrop-filter: blur(8px);
		backdrop-filter: blur(8px);
		color: #ffffff;
		font-size: 0.68rem;
		font-weight: 700;
		text-align: center;
		padding: 5px 9px;
		border-radius: 10px;
		line-height: 1.4;
		pointer-events: none;
	}
	.ep-bubble::after {
		content: '';
		position: absolute;
		bottom: 100%;
		right: 9px;
		border: 5px solid transparent;
		border-bottom-color: rgba(255,255,255,0.2);
	}
	</style>

	<div class="site-container">
	<div id="ep-module"
		class="w-full max-w-lg lg:max-w-4xl mx-auto font-sans"
		data-default-category="<?php echo esc_attr( $default_category ); ?>"
		data-default-data="<?php echo esc_attr( $default_data ); ?>"
		data-disable-category="<?php echo $disable_category ? 'true' : 'false'; ?>">

		<!-- ── WhatsApp link ── -->
		<p class="flex justify-center" style="margin-top:calc(var(--spacing)*4)!important;margin-bottom:0!important;"><a href="https://wa.me/610404562005?text=Hey!%20I%20need%20some%20extra%20information%20about%20your%20products." target="_blank" rel="noopener noreferrer" class="flex items-center !text-[#4a9bed]" style="text-decoration:underline dotted;">Ask a question on WhatsApp <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M19.5 4.5h-7V6h4.44l-5.97 5.97 1.06 1.06L18 7.06v4.44h1.5v-7Zm-13 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3H17v3a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h3V5.5h-3Z"></path></svg></a></p>

		<!-- ── Product card ── -->
		<div id="ep-card" style="margin-top:10px;"
			class="relative rounded-2xl border border-gray-200 shadow-sm overflow-hidden min-h-[550px]">
			<!-- background image layer — fades independently of the content -->
			<div id="ep-card-bg"
				style="position:absolute;inset:0;background-size:cover;background-position:center;
				       opacity:0;transition:opacity 0.5s ease;pointer-events:none;"></div>
			<!-- gradient overlay keeps white text readable over any image -->
			<div class="absolute inset-0 bg-gradient-to-b from-black/20 to-black/60 pointer-events-none"></div>
			<!-- inner fills the full card via absolute positioning so flex children have a definite height -->
			<div id="ep-card-inner" class="absolute inset-0 flex flex-col overflow-hidden">

				<!-- JS-rendered dynamic content -->
				<div id="ep-card-content" class="flex-1 p-6 flex flex-col gap-3">
					<div class="text-center text-sm text-white/50">Loading&hellip;</div>
				</div>

				<!-- Static controls: pill selects + button — never re-rendered by JS -->
				<div id="ep-card-controls" class="px-4 pb-5 flex flex-col gap-3 w-full max-w-[510px] mx-auto">

					<!-- Dropdown row -->
					<div class="flex gap-2">

						<!-- Category -->
						<div class="relative flex-1">
							<select id="ep-category" aria-label="Category"></select>
							<span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-white/70 text-xs" aria-hidden="true">&#9660;</span>
						</div>

						<!-- Data / Duration -->
						<div class="relative flex-1 min-w-0">
							<select id="ep-data" aria-label="Data" disabled></select>
							<span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-white/70 text-xs" aria-hidden="true">&#9660;</span>
						</div>

						<!-- Qty -->
						<div class="relative min-w-[4.5rem]">
							<select id="ep-qty" aria-label="Quantity">
								<option value="1">1</option>
								<option value="2">2</option>
								<option value="3">3</option>
								<option value="4">4</option>
								<option value="5">5</option>
							</select>
							<span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-white/70 text-xs" aria-hidden="true">&#9660;</span>
						</div>

					</div>

					<!-- Button placeholder — filled by renderCard() -->
					<div id="ep-card-btn"></div>

				</div>
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
	var card     = document.getElementById('ep-card');
	var cardBg   = document.getElementById('ep-card-bg');
	var inner    = document.getElementById('ep-card-content');
	var btnEl    = document.getElementById('ep-card-btn');

	if (!d || !module || !selCat || !selData || !card || !inner || !btnEl) return;

	var disableCat = module.dataset.disableCategory === 'true';

	// ── state ────────────────────────────────────────────────────────────────

	var state = {
		category  : '',
		dataValue : '',
		quantity  : 1,
		mode      : 'data',  // 'data' | 'duration'
		inCart    : {},
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

	function updateCardBackground(catName) {
		if (!cardBg) return;
		var base     = d.heroBase || '';
		var filename = d.heroImages && d.heroImages[catName];
		var url      = base + (filename || d.heroDefault || '');
		cardBg.style.opacity = '0';
		setTimeout(function () {
			cardBg.style.backgroundImage = 'url(' + url + ')';
			cardBg.style.opacity = '1';
		}, 500);
	}

	/** Data options for a category. Returns [{value, label}] sorted numerically.
	 *  Value is "display_size|product_id" so each product gets a unique slot.
	 *  Label shows display_size alone when unique; adds a suffix when two products share the same size. */
	function dataOptionsFor(catName) {
		var items = [];
		d.products.forEach(function (p) {
			if (catName && !p.categories.includes(catName)) return;
			var ds = p.display_size && p.display_size.trim();
			if (!ds) return;
			items.push({ value: ds + '|' + p.id, ds: ds, p: p });
		});

		// Count how many products share each display_size
		var dsCounts = {};
		items.forEach(function (item) { dsCounts[item.ds] = (dsCounts[item.ds] || 0) + 1; });

		// Sort numerically by data size
		items.sort(function (a, b) { return (parseFloat(a.ds) || 0) - (parseFloat(b.ds) || 0); });

		return items.map(function (item) {
			if (dsCounts[item.ds] === 1) return { value: item.value, label: item.ds };
			var tp     = item.p.traffic_policy || '';
			var suffix = (tp === 'calls_data' || tp === 'calls') ? '' : ' Data Only';
			return { value: item.value, label: item.ds + suffix };
		});
	}

	/** Unique expiry_days values for a category, sorted ascending. Used when display_size is absent. */
	function durationOptionsFor(catName) {
		var seen = {};
		d.products.forEach(function (p) {
			if (catName && !p.categories.includes(catName)) return;
			if (p.expiry_days) seen[p.expiry_days] = true;
		});
		return Object.keys(seen).map(Number).sort(function (a, b) { return a - b; });
	}

	/** Find the product matching the current state, respecting data vs duration mode. */
	function findProduct() {
		var inCat = d.products.filter(function (p) {
			return !state.category || p.categories.includes(state.category);
		});

		if (!inCat.length) return null;
		if (!state.dataValue) return inCat[0];

		if (state.mode === 'duration') {
			return inCat.find(function (p) {
				return p.expiry_days === parseInt(state.dataValue, 10);
			}) || inCat[0];
		}

		// state.dataValue is "display_size|product_id" — match by ID first, fall back to display_size
		var parts    = state.dataValue.split('|');
		var targetDs = parts[0];
		var targetId = parts[1] ? parseInt(parts[1], 10) : null;

		if (targetId) {
			var byId = inCat.find(function (p) { return p.id === targetId; });
			if (byId) return byId;
		}

		return inCat.find(function (p) {
			return normData(p.display_size) === normData(targetDs);
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
		var dataOpts     = dataOptionsFor(state.category);
		var durationOpts = dataOpts.length ? [] : durationOptionsFor(state.category);

		selData.innerHTML = '';

		if (dataOpts.length) {
			// ── Data mode ────────────────────────────────────────────────────
			state.mode = 'data';
			selData.setAttribute('aria-label', 'Data');

			// Match: exact compound key, or by display_size prefix (for initial defaultData)
			var stateDs  = state.dataValue.split('|')[0];
			var matched  = dataOpts.find(function (opt) {
				return opt.value === state.dataValue || normData(opt.ds) === normData(stateDs);
			});
			state.dataValue = matched ? matched.value : dataOpts[dataOpts.length - 1].value;

			dataOpts.forEach(function (opt) {
				var o         = document.createElement('option');
				o.value       = opt.value;
				o.textContent = opt.label;
				if (opt.value === state.dataValue) o.selected = true;
				selData.appendChild(o);
			});

			selData.value    = state.dataValue;
			selData.disabled = false;

		} else if (durationOpts.length) {
			// ── Duration mode — no display_size, use expiry_days ─────────────
			state.mode = 'duration';
			selData.setAttribute('aria-label', 'Duration');

			durationOpts.forEach(function (v) {
				var o         = document.createElement('option');
				o.value       = v;
				o.textContent = v + ' days';
				if (parseInt(state.dataValue, 10) === v) o.selected = true;
				selData.appendChild(o);
			});

			var matchedDur = durationOpts.find(function (v) {
				return parseInt(state.dataValue, 10) === v;
			});
			state.dataValue  = String(matchedDur || durationOpts[0] || '');
			selData.value    = state.dataValue;
			selData.disabled = false;

		} else {
			// ── Nothing available ─────────────────────────────────────────────
			state.mode = 'data';
			selData.setAttribute('aria-label', 'Data');
			var placeholder       = document.createElement('option');
			placeholder.value     = '';
			placeholder.textContent = 'N/A';
			selData.appendChild(placeholder);
			selData.disabled = true;
			state.dataValue  = '';
		}
	}

	function renderCard() {
		var p = findProduct();

		if (!p) {
			inner.innerHTML = '<div class="flex items-center justify-center h-full text-sm text-white/70">'
				+ 'No product available for this selection.</div>';
			btnEl.innerHTML = '';
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
			btnHtml = '<a href="' + esc(p.add_to_cart_url) + '"'
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
				+ '</a>';
		}

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

		// ── Content area (#ep-card-content) — pills, spacer, heading, subtitle
		inner.innerHTML =
			// ── Top row: pills left, brand icon right ───────────────────────
			'<div class="flex items-start justify-between gap-2">'
			+   '<div class="flex flex-wrap items-center gap-2">'
			+     badgeHtml
			+     (p.expiry_days ? '<span class="text-xs lg:text-sm font-bold text-white/80">' + p.expiry_days + ' days</span>' : '')
			+   '</div>'
			+   (d.brandIcon
				? '<div class="relative flex-shrink-0">'
				+ '<div class="ep-bubble">Scan QR<br>to activate</div>'
				+ '<a href="https://www.instagram.com/europe_number/" target="_blank" rel="noopener noreferrer" aria-label="Europe Number on Instagram">'
				+ '<img src="' + esc(d.brandIcon) + '" alt="" aria-hidden="true"'
				+ ' class="w-8 h-8 object-contain opacity-90">'
				+ '</a>'
				+ '</div>'
				: '')
			+ '</div>'

			// ── Spacer — pushes heading + subtitle to bottom ───────────────
			+ '<div class="flex-1"></div>'

			// ── Category name ──────────────────────────────────────────────
			+ '<h2 class="!text-white !font-bold !text-5xl uppercase tracking-wide text-center drop-shadow">'
			+   esc(state.category || p.categories[0] || '')
			+ '</h2>'

			// ── Product name ───────────────────────────────────────────────
			+ '<p class="text-white/90 text-sm text-center">' + esc(p.title) + '</p>';

		// ── Button placeholder (#ep-card-btn) ──────────────────────────────
		btnEl.innerHTML = btnHtml;

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
				esim_data          : state.dataValue.split('|')[0],
				esim_previous_data : prevData.split('|')[0],
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
