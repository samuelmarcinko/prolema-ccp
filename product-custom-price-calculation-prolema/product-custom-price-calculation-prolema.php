<?php
/**
 * Plugin Name: Product Custom Price Calculation PROLEMA
 * Description: Adds custom length (mm) field for variable products and calculates price automatically. Active only for selected category. Includes variation admin fields + bulk tool.
 * Version: 1.4.1
 * Author: Samuel Marcinko
 * License: GPLv2 or later
 * Text Domain: pcpp-prolema
 */

if (!defined('ABSPATH')) exit;

class PCPP_Prolema_Plugin {

    const META_LENGTH_MM = '_pcpp_length_mm';
    const META_IS_BASE   = '_pcpp_is_base';

    const TARGET_CATEGORY_SLUG = 'konstrukcne-profily';
    const BULK_BASE_MM = 1000;

    public function __construct() {

        add_action('woocommerce_before_add_to_cart_button', [$this, 'render_custom_length_ui'], 20);
        add_action('wp_footer', [$this, 'render_inline_js'], 99);

        add_filter('woocommerce_add_to_cart_validation', [$this, 'add_to_cart_validation_and_autoselect'], 1, 5);

        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 3);

        // VERY late so pricing plugins (B2BKing) apply their rules first
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_custom_price_in_cart'], 999999, 1);

        add_filter('woocommerce_get_item_data', [$this, 'display_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_order_item_meta'], 10, 3);

        add_action('woocommerce_variation_options_pricing', [$this, 'admin_add_variation_fields'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'admin_save_variation_fields'], 10, 2);

        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_post_pcpp_prolema_bulk_set_base', [$this, 'handle_bulk_set_base']);
    }

    private function is_target_product($product_id) {
        return has_term(self::TARGET_CATEGORY_SLUG, 'product_cat', $product_id);
    }

    private function admin_notice_redirect($message, $type = 'updated') {
        $url = add_query_arg([
            'page' => 'pcpp-prolema',
            'pcpp_notice' => rawurlencode($message),
            'pcpp_notice_type' => $type,
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    /* =========================
     * FRONTEND UI
     * ========================= */

    public function render_custom_length_ui() {
        if (!function_exists('wc_get_product')) return;

        global $product;
        if (!$product || !$product->is_type('variable')) return;

        $product_id = $product->get_id();
        if (!$this->is_target_product($product_id)) return;

        echo '<div class="pcpp-prolema-wrap">';
        echo '  <div class="pcpp-head">';
        echo '    <label class="pcpp-toggle">';
        echo '      <input type="checkbox" id="pcpp_use_custom_length" name="pcpp_use_custom_length" value="1">';
        echo '      <span class="pcpp-title">Vlastná dĺžka</span>';
        echo '      <span class="pcpp-badge">na mieru</span>';
        echo '    </label>';
        echo '    <div class="pcpp-subtitle">Zadajte dĺžku v milimetroch a počet kusov. Cena sa prepočíta automaticky podľa základnej varianty.</div>';
        echo '  </div>';

        echo '  <div id="pcpp_custom_length_fields" class="pcpp-body" style="display:none;">';
        echo '    <div class="pcpp-row">';
        echo '      <div class="pcpp-grid">';
        echo '        <div class="pcpp-col pcpp-col-length">';
        echo '          <label for="pcpp_custom_length_mm" class="pcpp-label">Dĺžka (mm)</label>';
        echo '          <input type="number" min="1" step="1" id="pcpp_custom_length_mm" name="pcpp_custom_length_mm" class="pcpp-input" placeholder="napr. 6000" />';
        echo '          <div class="pcpp-hint">Tip: 1000 mm = 1 m</div>';
        echo '        </div>';

        echo '        <div class="pcpp-col pcpp-col-qty">';
        echo '          <label class="pcpp-label pcpp-qty-label">Počet (ks)</label>';
        echo '          <div class="pcpp-qty-slot" aria-label="Počet kusov"></div>';
        echo '        </div>';
        echo '      </div>';

        echo '      <div class="pcpp-error" style="display:none;"></div>';
        echo '    </div>';

        echo '    <div class="pcpp-calc" aria-live="polite">';
        echo '      <div class="pcpp-calc-line"><span>Cena za 1 m:</span> <strong class="pcpp-price-per-m">—</strong></div>';
        echo '      <div class="pcpp-calc-line"><span>Cena za kus:</span> <strong class="pcpp-price-per-piece">—</strong></div>';
        echo '      <div class="pcpp-calc-line"><span>Spolu:</span> <strong class="pcpp-price-total">—</strong></div>';
        echo '      <div class="pcpp-calc-note">Výsledná cena sa môže mierne líšiť podľa nastavení DPH a zaokrúhľovania v košíku.</div>';
        echo '    </div>';

        echo '  </div>';
        echo '</div>';
    }

    public function render_inline_js() {
        if (!function_exists('is_product') || !is_product()) return;
        if (!function_exists('wc_get_product')) return;

        global $product;
        if (!$product || !$product->is_type('variable')) return;

        $product_id = $product->get_id();
        if (!$this->is_target_product($product_id)) return;

        $base_vid   = $this->find_base_variation_id($product);
        $base_attrs = [];

        if ($base_vid) {
            $base_var = wc_get_product($base_vid);
            if ($base_var) {
                $base_attrs = (array) $base_var->get_attributes();
            }
        }

        $base_len = $base_vid ? (int) get_post_meta($base_vid, self::META_LENGTH_MM, true) : 1000;
        if ($base_len <= 0) $base_len = 1000;
        ?>
        <style>
          form.variations_form .variations_button.woocommerce-variation-add-to-cart {
            display: flex !important;
            flex-wrap: wrap !important;
            align-items: flex-start !important;
            gap: 12px !important;
          }

          form.variations_form .variations_button .pcpp-prolema-wrap{
            flex: 0 0 100% !important;
            width: 100% !important;
            margin: 12px 0 !important;
          }

          form.variations_form .variations_button .quantity{ margin: 0 !important; }
          form.variations_form .variations_button .single_add_to_cart_button{ margin: 0 !important; }

          /* v custom režime skryjeme pôvodný qty slot (qty presúvame do boxu) */
          form.variations_form .variations_button.pcpp-custom-active > .quantity{
            display:none !important;
          }

          .pcpp-prolema-wrap{
            border:1px solid #e7e7e7;
            border-radius:10px;
            padding:14px;
            background:#fff;
            box-shadow: 0 1px 0 rgba(0,0,0,.03);
          }
          .pcpp-head{ display:flex; flex-direction:column; gap:6px; }
          .pcpp-toggle{ display:flex; align-items:center; gap:10px; margin:0; cursor:pointer; }
          .pcpp-title{ font-weight:700; }
          .pcpp-badge{
            margin-left:8px;
            font-size:12px;
            padding:3px 8px;
            border-radius:999px;
            background:rgba(0,0,0,.06);
          }
          .pcpp-subtitle{ color:#666; font-size:13px; line-height:1.35; }
          .pcpp-body{ margin-top:12px; }

          .pcpp-grid{
            display:flex;
            flex-wrap:wrap;
            gap:14px;
            align-items:flex-start;
          }
          .pcpp-col-length{ flex: 1 1 260px; min-width:240px; }
          .pcpp-col-qty{ flex: 0 0 180px; min-width:160px; }

          .pcpp-label{ display:block; font-weight:600; margin-bottom:6px; }
          .pcpp-qty-label{ margin-bottom:6px; } /* pre istotu */

          .pcpp-input{
            width:100%;
            max-width:100%;
            padding:10px 12px;
            border-radius:8px;
            border:1px solid #dcdcdc;
            outline:none;
          }
          .pcpp-input:focus{ border-color:#b8c7e8; box-shadow:0 0 0 3px rgba(30,100,230,.10); }
          .pcpp-hint{ margin-top:6px; color:#666; font-size:12px; }

          /* qty v slote */
          .pcpp-qty-slot{
            display:block;
          }
          .pcpp-qty-slot .quantity{
            display:inline-flex !important;
            flex-direction: row !important;
            align-items: center !important;
          }

          .pcpp-error{
            margin-top:10px;
            padding:10px;
            border-radius:8px;
            background:#fff1f1;
            color:#9b1c1c;
            font-size:13px;
            border:1px solid #ffd0d0;
          }

          .pcpp-calc{
            margin-top:12px;
            padding:12px;
            border-radius:10px;
            background:rgba(0,0,0,.03);
            border:1px solid rgba(0,0,0,.06);
          }
          .pcpp-calc-line{
            display:flex;
            justify-content:space-between;
            gap:10px;
            padding:6px 0;
          }
          .pcpp-calc-note{ margin-top:8px; font-size:12px; color:#666; line-height:1.35; }

          /* FIX default režim: quantity horizontálne */
          form.variations_form .variations_button.woocommerce-variation-add-to-cart:not(.pcpp-custom-active) .quantity{
            display: inline-flex !important;
            flex-direction: row !important;
            align-items: center !important;
            gap: 0 !important;
          }
        </style>

        <script>
        (function(){
          const cb   = document.getElementById('pcpp_use_custom_length');
          const box  = document.getElementById('pcpp_custom_length_fields');
          const mm   = document.getElementById('pcpp_custom_length_mm');
          const form = document.querySelector('form.variations_form');

          if(!cb || !box || !mm || !form) return;

          const baseAttrs = <?php echo wp_json_encode($base_attrs); ?>;

          const pricePerMEl     = document.querySelector('.pcpp-price-per-m');
          const pricePerPieceEl = document.querySelector('.pcpp-price-per-piece');
          const priceTotalEl    = document.querySelector('.pcpp-price-total');
          const errorEl         = document.querySelector('.pcpp-error');

          const qtySlot = document.querySelector('.pcpp-qty-slot');

          const MIN_MM = 1;
          const MAX_MM = 999999;

          const baseLenMm = <?php echo (int) $base_len; ?>;
          let basePrice = null;

          // pre návrat qty späť
          let qtyEl = null;
          let qtyOriginalParent = null;
          let qtyOriginalNextSibling = null;

          // aby sme nepridávali listenery viackrát
          let qtyListenersBound = false;

          function show(el){ if(el) el.style.display = ''; }
          function hide(el){ if(el) el.style.display = 'none'; }

          function setError(msg){
            if (!errorEl) return;
            if (!msg) {
              errorEl.style.display = 'none';
              errorEl.textContent = '';
              return;
            }
            errorEl.style.display = 'block';
            errorEl.textContent = msg;
          }

          function formatMoney(amount){
            const currency = 'EUR';
            try {
              return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount);
            } catch(e) {
              return (Math.round(amount * 100) / 100).toFixed(2) + ' €';
            }
          }

          function clearCalc(){
            if (pricePerMEl) pricePerMEl.textContent = '—';
            if (pricePerPieceEl) pricePerPieceEl.textContent = '—';
            if (priceTotalEl) priceTotalEl.textContent = '—';
          }

          function getQtyInput(){
            return form.querySelector('input[name="quantity"]');
          }

          function getQtyValue(){
            const qtyInput = getQtyInput();
            const q = qtyInput ? parseInt(qtyInput.value || '1', 10) : 1;
            return (!q || q < 1) ? 1 : q;
          }

          function updateCalc(){
            if (!cb.checked) return;

            const val = parseInt(mm.value || '0', 10);
            const qty = getQtyValue();

            if (!val || val < MIN_MM || val > MAX_MM) {
              setError(`Zadajte dĺžku v rozsahu ${MIN_MM}–${MAX_MM} mm.`);
              clearCalc();
              return;
            }

            setError('');

            if (basePrice === null || !basePrice || baseLenMm <= 0) {
              clearCalc();
              return;
            }

            const pricePerMm = basePrice / baseLenMm;
            const perM = pricePerMm * 1000;
            const perPiece = pricePerMm * val;
            const total = perPiece * qty;

            if (pricePerMEl) pricePerMEl.textContent = formatMoney(perM);
            if (pricePerPieceEl) pricePerPieceEl.textContent = formatMoney(perPiece);
            if (priceTotalEl) priceTotalEl.textContent = formatMoney(total);
          }

          function bindQtyListeners(){
            if (qtyListenersBound) return;
            qtyListenersBound = true;

            const qtyInput = getQtyInput();
            if (qtyInput) {
              ['input','change','keyup'].forEach(ev => {
                qtyInput.addEventListener(ev, () => updateCalc());
              });
            }

            // 🔧 Témy často menia qty cez +/– bez eventov -> počúvame kliky
            form.addEventListener('click', function(e){
              const t = e.target;
              if (!t) return;

              const btn = t.closest ? t.closest('.plus, .minus') : null;
              if (!btn) return;

              // po ticku bude hodnota už upravená
              setTimeout(() => updateCalc(), 0);
            }, true);
          }

          function setSelectValue(select, value) {
            if (!select) return false;
            const target = (value ?? '').toString();
            let found = false;

            for (const opt of select.options) {
              if (!opt.value) continue;
              if (opt.value === target) { found = true; break; }
            }
            if (!found) return false;

            select.value = target;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
          }

          function autoSelectBaseVariation() {
            if (!form) return;

            if (baseAttrs && typeof baseAttrs === 'object') {
              Object.keys(baseAttrs).forEach((tax) => {
                const val = baseAttrs[tax];
                const select = form.querySelector('select[name="attribute_' + tax + '"]');
                setSelectValue(select, val);
              });
            }

            const selects = form.querySelectorAll('select[name^="attribute_"]');
            selects.forEach((select) => {
              if (select.value) return;
              for (const opt of select.options) {
                if (opt.value) {
                  select.value = opt.value;
                  select.dispatchEvent(new Event('change', { bubbles: true }));
                  break;
                }
              }
            });

            if (window.jQuery) {
              window.jQuery(form).trigger('check_variations');
            }
          }

          function parsePriceFromText(t){
            if(!t) return null;
            const matches = t
              .replace(/\s+/g,' ')
              .match(/(\d{1,3}(?:[\s\u00A0]\d{3})*|\d+)([.,]\d{1,2})?/g);
            if(!matches || !matches.length) return null;

            const last = matches[matches.length - 1]
              .replace(/[\s\u00A0]/g,'')
              .replace(',', '.');

            const num = parseFloat(last);
            return isNaN(num) ? null : num;
          }

          function captureQtyOriginalPosition(){
            if (qtyEl && qtyOriginalParent) return;
            qtyEl = form.querySelector('.variations_button.woocommerce-variation-add-to-cart .quantity');
            if (!qtyEl) return;

            qtyOriginalParent = qtyEl.parentElement;
            qtyOriginalNextSibling = qtyEl.nextSibling;
          }

          function moveQtyIntoBox(){
            if (!qtySlot) return;
            captureQtyOriginalPosition();
            if (!qtyEl || !qtyEl.parentElement) return;

            if (qtyEl.parentElement !== qtySlot) {
              qtySlot.appendChild(qtyEl);
            }

            bindQtyListeners();
            updateCalc();
          }

          function restoreQtyBack(){
            if (!qtyEl || !qtyOriginalParent) return;

            if (qtyEl.parentElement !== qtyOriginalParent) {
              if (qtyOriginalNextSibling) {
                qtyOriginalParent.insertBefore(qtyEl, qtyOriginalNextSibling);
              } else {
                qtyOriginalParent.appendChild(qtyEl);
              }
            }

            bindQtyListeners(); // nech funguje prepočet aj mimo custom (nevadí)
          }

          // hook into Woo variation events
          if (window.jQuery && form) {
            const $form = window.jQuery(form);

            $form.on('found_variation', function(evt, variation){
              let p = null;

              if (variation && variation.price_html) {
                const tmp = document.createElement('div');
                tmp.innerHTML = variation.price_html;
                p = parsePriceFromText(tmp.textContent || tmp.innerText || '');
              }

              if (p === null) {
                const el = document.querySelector('.woocommerce-variation-price');
                if (el) p = parsePriceFromText(el.textContent || '');
              }

              if (p === null && variation && typeof variation.display_price !== 'undefined') {
                const dp = parseFloat(variation.display_price);
                p = isNaN(dp) ? null : dp;
              }

              if (p !== null && !isNaN(p)) {
                basePrice = p;
                updateCalc();
              }
            });

            $form.on('reset_data', function(){
              basePrice = null;
              updateCalc();
            });
          }

          mm.addEventListener('input', updateCalc);
          mm.addEventListener('change', updateCalc);

          function toggleUiForCustom(isCustom) {
            box.style.display = isCustom ? 'block' : 'none';

            const variationsButton = form.querySelector('.variations_button.woocommerce-variation-add-to-cart');
            if (variationsButton) {
              variationsButton.classList.toggle('pcpp-custom-active', isCustom);
            }

            const variationsTable = form.querySelector('.variations');
            const resetLink = form.querySelector('.reset_variations');

            if (isCustom) {
              hide(variationsTable);
              hide(resetLink);
              moveQtyIntoBox();
              updateCalc();
            } else {
              show(variationsTable);
              show(resetLink);
              restoreQtyBack();
              mm.value = '';
              setError('');
              clearCalc();
            }
          }

          cb.addEventListener('change', () => {
            const isCustom = cb.checked;
            toggleUiForCustom(isCustom);

            if (isCustom) {
              autoSelectBaseVariation();
              bindQtyListeners();
              updateCalc();
            }
          });

          // initial
          bindQtyListeners();

          if (cb.checked) {
            toggleUiForCustom(true);
            autoSelectBaseVariation();
            updateCalc();
          } else {
            toggleUiForCustom(false);
          }

        })();
        </script>
        <?php
    }

    /* =========================
     * BASE VARIATION PICK
     * ========================= */

    private function find_base_variation_id($variable_product) {
        if (!$variable_product || !$variable_product->is_type('variable')) return 0;

        $children = $variable_product->get_children();
        if (empty($children)) return 0;

        $fallback = 0;
        $first_with_mm = 0;
        $one_meter = 0;
        $explicit_base = 0;

        foreach ($children as $vid) {
            $v = wc_get_product($vid);
            if (!$v || !$v->exists() || !$v->is_purchasable()) continue;

            if (!$fallback) $fallback = $vid;

            $is_base = get_post_meta($vid, self::META_IS_BASE, true);
            if (!$explicit_base && ($is_base === 'yes' || $is_base === '1' || $is_base === 1)) {
                $explicit_base = $vid;
            }

            $mm = (int) get_post_meta($vid, self::META_LENGTH_MM, true);
            if ($mm > 0 && !$first_with_mm) $first_with_mm = $vid;
            if ($mm === 1000) $one_meter = $vid;
        }

        if ($explicit_base) return $explicit_base;
        if ($one_meter) return $one_meter;
        if ($first_with_mm) return $first_with_mm;
        return $fallback;
    }

    /* =========================
     * ADD TO CART + CART PRICE
     * ========================= */

    public function add_to_cart_validation_and_autoselect($passed, $product_id, $qty, $variation_id = 0, $variations = []) {
        if (empty($_POST['pcpp_use_custom_length'])) return $passed;
        if (!$this->is_target_product($product_id)) return $passed;

        $mm = isset($_POST['pcpp_custom_length_mm']) ? (int) $_POST['pcpp_custom_length_mm'] : 0;
        if ($mm <= 0) {
            wc_add_notice('Zadajte platnú dĺžku v mm.', 'error');
            return false;
        }

        if ((int)$qty < 1) {
            wc_add_notice('Zadajte platný počet kusov.', 'error');
            return false;
        }

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) return $passed;

        if (!empty($_REQUEST['variation_id'])) return $passed;

        $base_vid = $this->find_base_variation_id($product);
        if (!$base_vid) {
            wc_add_notice('Nepodarilo sa nájsť vhodnú variáciu pre výpočet ceny.', 'error');
            return false;
        }

        $base_var = wc_get_product($base_vid);
        if (!$base_var) {
            wc_add_notice('Základná variácia nie je dostupná.', 'error');
            return false;
        }

        $_REQUEST['variation_id'] = $base_vid;

        $attrs = $base_var->get_attributes();
        foreach ($attrs as $tax_or_name => $value) {
            $_REQUEST['attribute_' . $tax_or_name] = $value;
        }

        return $passed;
    }

    public function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
        if (empty($_POST['pcpp_use_custom_length'])) return $cart_item_data;
        if (!$this->is_target_product($product_id)) return $cart_item_data;

        $mm = isset($_POST['pcpp_custom_length_mm']) ? (int) $_POST['pcpp_custom_length_mm'] : 0;
        if ($mm <= 0) return $cart_item_data;

        if (empty($variation_id)) return $cart_item_data;

        $cart_item_data['pcpp_custom_length_mm'] = $mm;

        // different length should not merge
        $cart_item_data['pcpp_unique_key'] = md5($variation_id . '|' . $mm);

        // captured after discount in cart totals
        $cart_item_data['pcpp_base_price'] = null;

        return $cart_item_data;
    }

    public function apply_custom_price_in_cart($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (!$cart || !method_exists($cart, 'get_cart')) return;

        foreach ($cart->get_cart() as $key => $item) {
            if (empty($item['pcpp_custom_length_mm'])) continue;
            if (!isset($item['data']) || !is_object($item['data'])) continue;

            $mm = (int) $item['pcpp_custom_length_mm'];
            if ($mm <= 0) continue;

            $variation_id = !empty($item['variation_id']) ? (int) $item['variation_id'] : 0;
            if (!$variation_id) continue;

            $base_length_mm = (int) get_post_meta($variation_id, self::META_LENGTH_MM, true);
            if ($base_length_mm <= 0) $base_length_mm = 1000;

            if (!isset($cart->cart_contents[$key]['pcpp_base_price']) || $cart->cart_contents[$key]['pcpp_base_price'] === null) {
                $cart->cart_contents[$key]['pcpp_base_price'] = (float) $item['data']->get_price();
            }

            $base_price = (float) $cart->cart_contents[$key]['pcpp_base_price'];
            if ($base_price <= 0) continue;

            $custom_price = ($base_price / $base_length_mm) * $mm;

            // price per piece; Woo multiplies by quantity
            $cart->cart_contents[$key]['data']->set_price((float) $custom_price);
        }
    }

    public function display_item_data($item_data, $cart_item) {
        if (!empty($cart_item['pcpp_custom_length_mm'])) {
            $item_data[] = [
                'name'  => 'Dĺžka',
                'value' => (int) $cart_item['pcpp_custom_length_mm'] . ' mm',
            ];
        }
        return $item_data;
    }

    public function save_order_item_meta($item, $cart_item_key, $values) {
        if (!empty($values['pcpp_custom_length_mm'])) {
            $item->add_meta_data('Dĺžka', (int) $values['pcpp_custom_length_mm'] . ' mm', true);
        }
    }

    /* =========================
     * ADMIN – Variation fields
     * ========================= */

    public function admin_add_variation_fields($loop, $variation_data, $variation) {
        $parent_id = wp_get_post_parent_id($variation->ID);
        if ($parent_id && !$this->is_target_product($parent_id)) return;

        woocommerce_wp_text_input([
            'id'          => 'pcpp_length_mm_' . $variation->ID,
            'label'       => __('Dĺžka (mm) – PROLEMA', 'pcpp-prolema'),
            'desc_tip'    => true,
            'description' => __('Zadaj dĺžku tejto varianty v mm (napr. 1000 pre 1m, 6000 pre 6m). Používa sa pre výpočet custom ceny.', 'pcpp-prolema'),
            'type'        => 'number',
            'custom_attributes' => [
                'step' => '1',
                'min'  => '1',
            ],
            'value'       => get_post_meta($variation->ID, self::META_LENGTH_MM, true),
        ]);

        woocommerce_wp_checkbox([
            'id'          => 'pcpp_is_base_' . $variation->ID,
            'label'       => __('Base variácia pre custom výpočet – PROLEMA', 'pcpp-prolema'),
            'description' => __('Ak zaškrtneš, táto variácia sa použije ako základ pre výpočet ceny pri “Vlastná dĺžka”. (Len jedna base na produkt.)', 'pcpp-prolema'),
            'value'       => (get_post_meta($variation->ID, self::META_IS_BASE, true) === 'yes') ? 'yes' : 'no',
        ]);
    }

    public function admin_save_variation_fields($variation_id, $i) {
        $parent_id = wp_get_post_parent_id($variation_id);
        if ($parent_id && !$this->is_target_product($parent_id)) return;

        $len_key = 'pcpp_length_mm_' . $variation_id;
        $len_val = isset($_POST[$len_key]) ? (int) $_POST[$len_key] : 0;

        if ($len_val > 0) update_post_meta($variation_id, self::META_LENGTH_MM, $len_val);
        else delete_post_meta($variation_id, self::META_LENGTH_MM);

        $base_key = 'pcpp_is_base_' . $variation_id;
        $is_base  = !empty($_POST[$base_key]) && $_POST[$base_key] === 'yes';

        if ($is_base) {
            update_post_meta($variation_id, self::META_IS_BASE, 'yes');

            if ($parent_id) {
                $parent = wc_get_product($parent_id);
                if ($parent && $parent->is_type('variable')) {
                    foreach ($parent->get_children() as $sib_id) {
                        if ((int)$sib_id === (int)$variation_id) continue;
                        delete_post_meta($sib_id, self::META_IS_BASE);
                    }
                }
            }
        } else {
            delete_post_meta($variation_id, self::META_IS_BASE);
        }
    }

    /* =========================
     * ADMIN PAGE + BULK TOOL
     * ========================= */

    public function register_admin_page() {
        add_submenu_page(
            'woocommerce',
            'PCPP PROLEMA',
            'PCPP PROLEMA',
            'manage_woocommerce',
            'pcpp-prolema',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_woocommerce')) wp_die('Nemáš oprávnenie.');

        $notice = isset($_GET['pcpp_notice']) ? sanitize_text_field(wp_unslash($_GET['pcpp_notice'])) : '';
        $notice_type = isset($_GET['pcpp_notice_type']) ? sanitize_text_field(wp_unslash($_GET['pcpp_notice_type'])) : 'updated';

        echo '<div class="wrap">';
        echo '<h1>Product Custom Price Calculation PROLEMA</h1>';

        if ($notice) {
            $class = ($notice_type === 'error') ? 'notice notice-error' : 'notice notice-success';
            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($notice) . '</p></div>';
        }

        echo '<p><strong>Aktívne len pre kategóriu:</strong> <code>' . esc_html(self::TARGET_CATEGORY_SLUG) . '</code></p>';
        echo '<hr/>';

        echo '<h2>Bulk akcia</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="pcpp_prolema_bulk_set_base" />';
        wp_nonce_field('pcpp_prolema_bulk_set_base', 'pcpp_nonce');
        submit_button('Nastaviť 1m variácie ako base (1000mm) pre všetky produkty v kategórii', 'primary');
        echo '</form>';

        echo '</div>';
    }

    public function handle_bulk_set_base() {
        if (!current_user_can('manage_woocommerce')) wp_die('Nemáš oprávnenie.');
        if (!isset($_POST['pcpp_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pcpp_nonce'])), 'pcpp_prolema_bulk_set_base')) {
            wp_die('Neplatný nonce.');
        }

        $args = [
            'status'   => ['publish', 'private', 'draft'],
            'limit'    => -1,
            'type'     => ['variable'],
            'category' => [self::TARGET_CATEGORY_SLUG],
            'return'   => 'ids',
        ];

        $product_ids = wc_get_products($args);

        $updated_products = 0;
        $skipped_products = 0;

        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product || !$product->is_type('variable')) { $skipped_products++; continue; }

            $base_variation_id = $this->find_1m_variation_id($product);
            if (!$base_variation_id) { $skipped_products++; continue; }

            update_post_meta($base_variation_id, self::META_LENGTH_MM, self::BULK_BASE_MM);
            update_post_meta($base_variation_id, self::META_IS_BASE, 'yes');

            foreach ($product->get_children() as $vid) {
                if ((int)$vid === (int)$base_variation_id) continue;
                delete_post_meta($vid, self::META_IS_BASE);
            }

            $updated_products++;
        }

        $this->admin_notice_redirect(
            "Hotovo. Upravené produkty: {$updated_products}. Preskočené: {$skipped_products}.",
            'updated'
        );
    }

    private function find_1m_variation_id($variable_product) {
        $children = $variable_product->get_children();
        if (empty($children)) return 0;

        foreach ($children as $vid) {
            $v = wc_get_product($vid);
            if (!$v || !$v->exists()) continue;

            $attrs = $v->get_attributes();
            foreach ($attrs as $val) {
                $s = is_string($val) ? $val : '';
                $s_norm = strtolower(trim($s));
                $s_norm = str_replace(' ', '', $s_norm);
                if ($s_norm === '1m' || $s_norm === '01m') return $vid;
            }
        }

        foreach ($children as $vid) {
            $v = wc_get_product($vid);
            if (!$v || !$v->exists()) continue;

            $name = strtolower($v->get_name());
            $sku  = strtolower((string)$v->get_sku());

            if (strpos(str_replace(' ', '', $name), '1m') !== false) return $vid;
            if ($sku && strpos(str_replace(' ', '', $sku), '1m') !== false) return $vid;
        }

        return 0;
    }
}

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        new PCPP_Prolema_Plugin();
    }
});
