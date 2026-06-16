<style>
   .pill-slide-bg {
      position: absolute;
      inset: 0;
      background-size: cover;
      background-position: center center;
      background-repeat: no-repeat;
      transition: opacity 1.2s ease-in-out;
      border-radius: inherit;
   }
</style>
<?php
   // API data — cached via transients (12 hrs). Defined in helpers snippet.
   $obj            = en_get_region_data();
   $country_intros = en_get_country_intros();

   // Category identity — from helpers snippet.
   // Fall back to Europe defaults when no term is queried or WC injects the 'product' catch-all category.
   $_raw_slug     = en_get_cat_slug();
   $_raw_name     = en_get_cat_name();
   $cat_slug      = ($_raw_slug && $_raw_slug !== 'product') ? $_raw_slug : 'esim-europe';
   $category_name = ($_raw_name && strtolower($_raw_name) !== 'product') ? $_raw_name : 'Europe';
   $category_slug = str_replace('esim-', '', $cat_slug); // e.g. 'europe' — used for JSON lookup & flag image

   function get_country_values($obj, $category_slug, $meta) {
      $val = '';
      if (is_array($obj) || is_object($obj)) {
         foreach ($obj as $items) {
            foreach ($items as $key => $value) {
               if (gettype($value) === 'array') {
                  $items[$key] = implode(",", $value);
               }
               if ($key == 'Region slug' && $value == $category_slug) {
                  if ($meta == 'countries') {
                     $val = $items['Countries'];
                  } elseif ($meta == 'cost_mb') {
                     $val = $items['$USD/MB'];
                  }
               }
            }
         }
      }
      return $val;
   }

   $region_countries        = get_country_values($obj, $category_slug, 'countries');
   $region_cost_mb          = get_country_values($obj, $category_slug, 'cost_mb');
   $region_countries_format = substr_replace($region_countries, ' and', strrpos($region_countries, ','), 1);

   function stringCount($string) {
      return ($string) ? count(explode(",", $string)) : 0;
   }
   $stringCount = stringCount($region_countries);

   // Takes the full slug (e.g. 'esim-monaco') — no category object needed.
   function show_more_region_info($slug, $info) {
      $special = '<div class="text-lg font-semibold text-[#ed704a]">Data | Calls & SMS | Number</div>';

      if ($info == 'slide') {
         if ($slug == 'esim-monaco') {
            return array(
               '/wp-content/uploads/2026/05/best-europe-esim-f1-monaco-grand-prix-regen_opt-1.jpg',
               '/wp-content/uploads/2026/05/2026-05-16-best-europe-esim-including-monaco_opt.jpg',
               '/wp-content/uploads/2026/05/2026-05-27-best-esim-f1-monaco-grand-prix_opt.jpg',
            );
         }
         return array(
            '/wp-content/uploads/2026/04/2026-04-21-cheapest-europe-data-esim-2026_opt-1.jpg',
            '/wp-content/uploads/2026/05/esim-vs-international-roaming-europe-cost-regen_opt-3.jpg',
            '/wp-content/uploads/2026/03/2026-03-23-france-esim-phone-number-vs-data-only_opt-1.jpg',
            '/wp-content/uploads/2026/04/2026-04-08-firstcamptrek_opt.jpg',
            '/wp-content/uploads/2026/05/2026-05-12-know-your-europe-esim-3-types-explained_opt.jpg',
            '/wp-content/uploads/2026/04/2026-04-21-best-multi-country-europe-esim-schengen_opt.jpg',
            '/wp-content/uploads/2026/05/2026-05-21-apply-jobs-france-temporary-french-number_opt.jpg',
         );
      }

      if ($info == 'special') {
         return $special;
      }

      return '';
   }

   $category_slides  = show_more_region_info($cat_slug, 'slide');
   $category_special = show_more_region_info($cat_slug, 'special');

   // Network partners: logo path + carrier name per region slug.
   // Holding on logos for now — row falls back to text label only.
   function get_network_partners($slug) {
      $n = '/wp-content/uploads/networks/';
      $partners = [
         'esim-europe' => [
            ['name' => 'Vodafone',         'logo' => $n . 'vodafone.png'],
            ['name' => 'Orange',           'logo' => $n . 'orange.png'],
            ['name' => 'Deutsche Telekom', 'logo' => $n . 'deutsche-telekom.png'],
            ['name' => 'T-Mobile',         'logo' => $n . 'tmobile.png'],
         ],
         'esim-usa' => [
            ['name' => 'AT&T',    'logo' => '/wp-content/uploads/2025/06/att.png'],
            ['name' => 'T-Mobile','logo' => $n . 'tmobile.png'],
            ['name' => 'Verizon', 'logo' => '/wp-content/uploads/2025/06/verizon.png'],
         ],
         'esim-uk' => [
            ['name' => 'EE',      'logo' => $n . 'ee.png'],
            ['name' => 'Vodafone','logo' => $n . 'vodafone.png'],
            ['name' => 'O2',      'logo' => $n . 'o2.png'],
            ['name' => 'Three',   'logo' => $n . 'three.png'],
         ],
         'esim-canada' => [
            ['name' => 'Bell',  'logo' => $n . 'bell.png'],
            ['name' => 'Rogers','logo' => $n . 'rogers.png'],
            ['name' => 'Telus', 'logo' => $n . 'telus.png'],
         ],
         'esim-monaco' => [
            ['name' => 'Monaco Telecom','logo' => $n . 'monaco-telecom.png'],
            ['name' => 'Orange',        'logo' => $n . 'orange.png'],
         ],
         'esim-france' => [
            ['name' => 'Orange',     'logo' => $n . 'orange.png'],
            ['name' => 'SFR',        'logo' => $n . 'sfr.png'],
            ['name' => 'Bouygues',   'logo' => $n . 'bouygues.png'],
            ['name' => 'Free Mobile','logo' => $n . 'free-mobile.png'],
         ],
         'esim-germany' => [
            ['name' => 'Deutsche Telekom','logo' => $n . 'deutsche-telekom.png'],
            ['name' => 'Vodafone',        'logo' => $n . 'vodafone.png'],
            ['name' => 'O2',              'logo' => $n . 'o2.png'],
         ],
         'esim-spain' => [
            ['name' => 'Movistar','logo' => $n . 'movistar.png'],
            ['name' => 'Vodafone','logo' => $n . 'vodafone.png'],
            ['name' => 'Orange',  'logo' => $n . 'orange.png'],
         ],
         'esim-italy' => [
            ['name' => 'TIM',     'logo' => $n . 'tim.png'],
            ['name' => 'Vodafone','logo' => $n . 'vodafone.png'],
            ['name' => 'WindTre', 'logo' => $n . 'windtre.png'],
         ],
         'esim-portugal' => [
            ['name' => 'NOS',    'logo' => $n . 'nos.png'],
            ['name' => 'Vodafone','logo' => $n . 'vodafone.png'],
            ['name' => 'MEO',    'logo' => $n . 'meo.png'],
         ],
         'esim-netherlands' => [
            ['name' => 'KPN',     'logo' => $n . 'kpn.png'],
            ['name' => 'Vodafone','logo' => $n . 'vodafone.png'],
            ['name' => 'T-Mobile','logo' => $n . 'tmobile.png'],
         ],
         'esim-switzerland' => [
            ['name' => 'Swisscom','logo' => $n . 'swisscom.png'],
            ['name' => 'Sunrise', 'logo' => $n . 'sunrise.png'],
            ['name' => 'Salt',    'logo' => $n . 'salt.png'],
         ],
         'esim-austria' => [
            ['name' => 'A1',      'logo' => $n . 'a1.png'],
            ['name' => 'T-Mobile','logo' => $n . 'tmobile.png'],
            ['name' => 'Drei',    'logo' => $n . 'drei.png'],
         ],
         'esim-asia' => [
            ['name' => 'Singtel',     'logo' => $n . 'singtel.png'],
            ['name' => 'KDDI',        'logo' => $n . 'kddi.png'],
            ['name' => 'SK Telecom',  'logo' => $n . 'sk-telecom.png'],
            ['name' => 'China Mobile','logo' => $n . 'china-mobile.png'],
         ],
         'esim-greater-asia' => [
            ['name' => 'Singtel',   'logo' => $n . 'singtel.png'],
            ['name' => 'KDDI',      'logo' => $n . 'kddi.png'],
            ['name' => 'SK Telecom','logo' => $n . 'sk-telecom.png'],
            ['name' => 'Airtel',    'logo' => $n . 'airtel.png'],
         ],
         'esim-japan' => [
            ['name' => 'NTT Docomo','logo' => $n . 'ntt-docomo.png'],
            ['name' => 'SoftBank',  'logo' => $n . 'softbank.png'],
            ['name' => 'KDDI',      'logo' => $n . 'kddi.png'],
         ],
         'esim-south-korea' => [
            ['name' => 'SK Telecom','logo' => $n . 'sk-telecom.png'],
            ['name' => 'KT',        'logo' => $n . 'kt.png'],
            ['name' => 'LG Uplus',  'logo' => $n . 'lg-uplus.png'],
         ],
         'esim-australia' => [
            ['name' => 'Telstra', 'logo' => $n . 'telstra.png'],
            ['name' => 'Optus',   'logo' => $n . 'optus.png'],
            ['name' => 'Vodafone','logo' => $n . 'vodafone.png'],
         ],
      ];
      return $partners[$slug] ?? [];
   }

   $network_partners = get_network_partners($cat_slug);
?>
<script id="pill_slides" type="text/javascript">
   var imageArray = <?php echo json_encode($category_slides); ?>;
   var switchMilliseconds = 10000;

   window.onload = function () {
      var bgs    = [document.getElementById('pillSlideBg0'), document.getElementById('pillSlideBg1')];
      var active = 0;
      var idx    = 0;

      function nextSlide() {
         var next    = 1 - active;
         idx         = (idx + 1) % imageArray.length;
         bgs[next].style.backgroundImage = 'url("' + imageArray[idx] + '")';
         // Double rAF ensures the new bg is painted before we start the fade
         requestAnimationFrame(function () {
            requestAnimationFrame(function () {
               bgs[next].style.opacity = '1';
               bgs[active].style.opacity = '0';
               active = next;
               setTimeout(nextSlide, switchMilliseconds);
            });
         });
      }

      setTimeout(nextSlide, switchMilliseconds);
   };
</script>

<div class="site-container">
   <div class="mx-auto max-w-4xl lg:max-w-7xl lg:px-8">
      <div class="flex max-lg:flex-col lg:flex-row max-lg:gap-[40px] lg:gap-10 lg:items-center pt-10">

         <!-- Left: heading block -->
         <div class="flex justify-end flex-1 max-lg:self-center heading max-lg:pb-5">
            <center class="lg:text-right">
               <div class="flex text-center lg:justify-end text-base/7 font-semibold text-[#ed704a]"><?php echo $category_special ?></div>
               <div class="flex flex-wrap max-lg:justify-center lg:justify-end align-center text-2xl lg:!text-4xl font-semibold tracking-tight text-balance !capitalize">
                  <span class="hidden">eSIM&nbsp;</span>
                  <span class="whitespace-nowrap"><?php echo $category_name ?></span>&nbsp;
                  <span class="flex whitespace-nowrap">
                     <img src="/wp-content/uploads/2025/08/esim-logo.png" width="50" height="" alt="eSIM logo" title="eSIM logo" class="self-center">
                     &nbsp;<img src="/wp-content/uploads/2026/05/icon-5g.png" width="19" height="" alt="5g" class="self-center">
                  </span>
               </div>
               <div class="flex max-lg:justify-center lg:justify-end gap-x-2 mt-[5px]">
                  <span class="mt-0.5 size-5 flex justify-center items-center text-blue-600 dark:text-blue-500">
                     <svg width="26px" height="26px" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd" d="M8 16L4.35009 13.3929C2.24773 11.8912 1 9.46667 1 6.88306V3L8 0L15 3V6.88306C15 9.46667 13.7523 11.8912 11.6499 13.3929L8 16ZM12.2071 5.70711L10.7929 4.29289L7 8.08579L5.20711 6.29289L3.79289 7.70711L7 10.9142L12.2071 5.70711Z" fill="#00BCFF"></path>
                     </svg>
                  </span>
                  <div>
                     <span class="dark:text-white">
                        <span class="font-bold"><a href="javascript:void(0)" class="flex open-modal-dialog !text-[#4a9bed] items-center" data-modal="modal_window_countries" role="button" aria-haspopup="dialog" aria-controls="modal_window_countries" aria-label="View list of countries for calls and SMS" style="text-decoration: underline dotted;">Network <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M19.5 4.5h-7V6h4.44l-5.97 5.97 1.06 1.06L18 7.06v4.44h1.5v-7Zm-13 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3H17v3a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h3V5.5h-3Z"></path></svg></a></span>
                     </span>
                  </div>
               </div>
            </center>
         </div>

         <!-- Right: pill slide + network partners -->
         <div class="pillSlideWrap flex flex-col flex-2 relative min-h-[280px] md:min-h-[380px]">

            <div id="pillSlides" class="min-h-[280px] md:min-h-[380px] flex bg-white shadow-sm ring-1 ring-black/5 flex-3 overflow-hidden" style="border-radius:24px 8px 24px 8px; position:relative;">

               <!-- Crossfade background layers — first image server-rendered, no JS flash -->
               <div id="pillSlideBg0" class="pill-slide-bg" style="background-image:url('<?php echo $category_slides[0]; ?>'); opacity:1;"></div>
               <div id="pillSlideBg1" class="pill-slide-bg" style="opacity:0;"></div>

               <div class="flex max-md:flex-col w-full p-6 md:p-10 gap-[15px] backdrop-brightness-70" style="position:relative; z-index:1;">
                  <div class="w-full h-full relative">
                     <div class="flex h-full w-full justify-end">
                        <div class="flex flex-col justify-end text-right title text-white font-bold uppercase text-2xl/6 md:text-4xl/9 mb-4">
                           Connection is everything <br>
                           <span class="md:text-lg/6">
                              <?php if ($cat_slug == 'esim-usa' || $cat_slug == 'esim-uk') { echo 'the&nbsp;'; } ?>
                              <?php echo $category_name ?>
                           </span>
                        </div>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Network partners row -->
            <?php if (!empty($network_partners)) : ?>
            <div class="flex items-center py-4 justify-center text-center">
               <span class="text-xs">Network carriers include: <b><?php echo implode(', ', array_column($network_partners, 'name')) ?></b></span>
            </div>
            <?php endif; ?>

            <!-- Country flag badge -->
            <img style="z-index:50;" class="w-[80px] absolute max-md:top-[-40px] max-md:left-[50%] max-md:ml-[-40px] md:top-[50%] md:left-[20px] md:mt-[-40px] border-[3px] border-white rounded-full"
               src="/wp-content/uploads/2025/08/round-flag-<?php echo $category_slug ?>-150x150.png"
               title="eSIM for Travel in <?php echo $category_name ?>"
               alt="eSIM for Travel in <?php echo $category_name ?>"
               width="128px" height="128px">
         </div>

      </div>
   </div>
</div>

<!-- MODAL: Countries included in region -->
<!--<dialog class="modal" id="modal_window_countries">
   <div class="button-container"><button data-modal-close="modal_window_countries" class="button new-close-modal">Close</button></div>
   <img src="/wp-content/uploads/2024/11/help-modal-globe.png" class="image-hero" alt="Lady checking her phone for eSIM service">
   <p><?php //echo $region_countries_format ?></p>
   <div class="author">
      <img src="/wp-content/uploads/2025/05/help-assistant.png" width="96" height="96" alt="Avatar" class="avatar">
      <span class="author-name">This eSIM should offer excellent value</span>
   </div>
</dialog>-->