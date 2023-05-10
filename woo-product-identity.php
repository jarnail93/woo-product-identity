<?php

/**
 * Plugin Name: Woo Product Identity
 * Description: Create unique, verifiable codes with QR-code for each product sold.
 * Author: Jarnail Singh
 * Version: 0.1
 */


if (!defined('ABSPATH')) exit;


define('WPI_PLUGIN_DIR', untrailingslashit(plugin_dir_path(__FILE__)));
define('WPI_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
define('WPI_PLUGIN_NAME', plugin_basename(__FILE__));

register_activation_hook(__FILE__, 'wpi_activate');
register_deactivation_hook(__FILE__, 'wpi_deactivate');


// Make any changes necessary between versions
register_activation_hook(__FILE__, 'wpi_load_migrations');

// Autoupdates are called via cron and cron doesn't deactivate/activate plugin
// thus, we can't rely on activation hook alone to execute migrations
add_action('upgrader_process_complete', 'wpi_load_migrations', 10, 2);


/**
 * Load all files here. Make sure they does not execute anything
 */
require_once(WPI_PLUGIN_DIR . '/includes/Database.php');


/**
 * Activate
 */
function wpi_activate()
{
  $db = new WPI_Database();

  if (!$db->is_db_available()) {

    if (!$db->create_table()) {

      // Admin should be notiied by WPI_Activate class
      wpi_deactivate_self();
    }
  }
}


/**
 * Deactivate
 */
function wpi_deactivate()
{
  // let user clear storage only (still optional) when they uninstall plugin
}


/*
 * Make any database or other continues changes needed between versions
 * @since 0.1
 */
function wpi_load_migrations($upgrader = null, $args = [])
{

  /** Plugin_Upgrader class */
  require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
  require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

  // When something has been upgraded
  // Check if its a plugin update and see if it is ours.
  if (null != $upgrader && !($upgrader instanceof Plugin_Upgrader)) {
    return;
  }

  // It is self sufficient, no external invocation required
  require_once(WPI_PLUGIN_DIR . '/includes/Migration.php');
}


/**
 * If WooCommerce is not activate, disable our plugin
 */
function wpi_is_woocommerce_activated()
{
  if (!class_exists('woocommerce')) {

    wpi_deactivate_self();
  }
}


/** 
 * Deactivate ourself for now.
 */
function wpi_deactivate_self()
{
  $plugins = [
    // 'woo-product-identity/woo-product-identity.php'
    WPI_PLUGIN_NAME
  ];

  require_once(ABSPATH . 'wp-admin/includes/plugin.php');

  // Notify admin, don't confuse
  deactivate_plugins($plugins);
}


function wpi_item_meta_display_key($display_key, $meta, $order_item)
{
  if ('wpi_product_identity_' === substr($meta->key, 0, 21)) {
    $display_key = __('Product Identity', 'wpi-text-domain');
  }

  return $display_key;
}


function wpi_item_meta_display_value($display_value, $meta, $order_item)
{

  if ('wpi_product_identity_' === substr($meta->key, 0, 21)) {

    $display_value = '<div class="wpi-product-identity">';
    // Add link where we will verify the code
    $user_option_base_url = get_option('wpi-option-base-url');
    $user_option_base_url = '' == $user_option_base_url ? get_home_url() : $user_option_base_url;
    $link = urlencode($user_option_base_url . '?' . http_build_query(['wpi-verify-product-identity' => $meta->value]));

    /**
     * 
     * QR Code Service used from https://goqr.me/
     * Currently we are generating QRs on the fly.
     * Will limit them using JS button to load on demand for admin at least.
     * 
     * */
    $text = __("Product Identity QR Code", 'wpi-text-domain');
    $display_value .= <<<HTML
      <figure>
        <img src="https://api.qrserver.com/v1/create-qr-code/?color=000000&bgcolor=FFFFFF&qzone=0&margin=0&size=200x200&ecc=L&data={$link}" alt="{$text}">
        <figcaption class="label">{$meta->value}</figcaption>
      </figure>
    HTML;

    $display_value .= '</div>';
    $display_value = wp_kses_post($display_value);
  }

  return $display_value;
}

function wpi_meta_key_label($label)
{
  if ('wpi_product_identity_' === substr($label, 0, 21)) {
    $label = __('Product Identity', 'wpi-text-domain');
  }

  return $label;
}


/**
 * Add CSS to WooCommerce Emails
 */
function wpi_add_css_to_emails($css, $email)
{
  $css .= file_get_contents(WPI_PLUGIN_DIR . '/assets/email-style.css');

  return $css;
}


/**
 * Add CSS for our custom meta display in admin
 */
function wpi_admin_style()
{
  wp_enqueue_style(
    'wpi_style',
    WPI_PLUGIN_URL . '/assets/admin-style.css',
    false,
    '1.0.1'
  );
}

/**
 * Add CSS for our custom meta display on front-end
 */
function wpi_enqueue_script()
{
  wp_enqueue_style(
    'wpi_style',
    WPI_PLUGIN_URL . '/assets/style.css',
    false,
    '1.0.1'
  );

  wp_enqueue_script('jquery-ui-dialog');
  wp_enqueue_style('wp-jquery-ui-dialog');

  wp_enqueue_script(
    'wpi_style',
    WPI_PLUGIN_URL . '/assets/product-info.js',
    ['jquery'],
    '0.0.1'
  );
}


/** 
 * Main job of the plugin
 */
function wpi_create_and_associate($item_id, $item, $order_id)
{
  global $wpdb;

  $db = new WPI_Database();;
  $qty = $item->get_quantity();
  $idx = 1;

  do {
    $unique_code = $db->create_identity_code($item_id);

    if (!$unique_code) {

      error_log('Database error.');
    } else {

      // Mark this meta key as unique, such that each item can have it only once
      $item->add_meta_data('wpi_product_identity_' . $idx, $unique_code, true);

      $qty--;
      $idx++;
    }
  } while ($qty > 0);

  $item->save_meta_data();
}


/**
 * Modify 
 */
function wpi_item_modified($item_id, $item, $order_id)
{
  $db = new WPI_Database();

  $meta_list = $item->get_meta_data();
  $new_identity_code_list = [];

  foreach ($meta_list as $meta) {

    if ('wpi_product_identity_' === substr($meta->key, 0, 21)) {

      $new_identity_code_list[] = $meta->value;
    }
  }

  $existing_identity_codes = $db->get_identity_codes_by_item_id($item_id);
  if (null == $existing_identity_codes) {
    // no existing codes. Could an old product.
    $existing_identity_codes = [];
  }
  // array flatten
  $existing_identity_codes = array_merge([], ...$existing_identity_codes);

  // delete these identity codes
  $deleting = array_diff($existing_identity_codes, $new_identity_code_list);
  $db->delete_identity_codes($deleting);

  // add these identity codes
  $adding = array_diff($new_identity_code_list, $existing_identity_codes);
  $db->create_identity_codes($adding, $item_id);
}


/**
 * Create the section under the products tab in WooCommerce for our settings
 **/
function wpi_add_settings_section($sections)
{
  $sections['wpi_settings'] = __('Woo Product Identity', 'wpi-text-domain');

  return $sections;
}

/**
 * And settings to the above newly created section
 */
function wpi_add_settings($settings, $current_section)
{
  // Same as the section key we have added earlier
  if ('wpi_settings' === $current_section) {

    $settings = [

      // Our section title
      [
        'name' => __('Woo Product Idetity Settings', 'wpi-text-domain'),
        'type' => 'title',
        'desc' => __('The following options are used to configure Woo Product Identity', 'wpi-text-domain'),
        'id' => 'wpi-option-title'
      ],

      // Our base url where the QR code will resolve to verify
      [
        'name' => __('Base URL (if different than home page)', 'wpi-text-domain'),
        'type' => 'text',
        'desc' => __('Change the URL where the QR code will resolve to verify the Product', 'wpi-text-domain'),
        'id' => 'wpi-option-base-url'
      ],

      [
        'name' => __('Display verification count', 'wpi-text-domain'),
        'type' => 'checkbox',
        'desc' => __('Should we tell the customer how many times before the requested code has been verified?', 'wpi-text-domain'),
        'id' => 'wpi-option-display-verification-count'
      ],

      // This ensures that the "Save changes" buttons prints at the bottom of the form
      [
        'type' => 'sectionend',
        'id'   => 'wpi-settings',
      ]
    ];
  }

  return $settings;
}


/**
 * Add Settings link to plugins page because we do not have our own admin page
 */
function wpi_add_settings_link($actions)
{

  $settings = [];

  $settings['settings'] = sprintf(
    '<a href="%s" aria-label="%s">%s</a>',
    wp_nonce_url('admin.php?page=wc-settings&tab=products&section=wpi_settings'),
    esc_attr(__('View Woo Product Identity settings', 'wpi-text-domain')),
    __('Settings')
  );

  return array_merge($settings, $actions);
}


/**
 * Check if the key for our product identity code exists and if so,
 * record and verify it
 */
function wpi_verify_product_identity()
{
  global $wip_product_info;

  // This is accessible globally and will added to page footer for JavaScript
  $wip_product_info = [
    'dialog' => true,
    'found' => false
  ];

  $db = new WPI_Database();

  if (array_key_exists('wpi-verify-product-identity', $_GET)) {

    $identity_code = $_GET['wpi-verify-product-identity'];

    $item_id = $db->get_item_id_by_identity_code($identity_code);

    /**
     * Check existence
     */
    if (null === $item_id) {
      // does not exist
      // some non-existence message
      return;
    }

    $wip_product_info['found'] = true;
    $order_id = wc_get_order_id_by_order_item_id($item_id);
    $order = wc_get_order($order_id);

    foreach ($order->get_items() as $item_idx => $item) {

      if ((int)$item_id === (int)$item_idx) {

        $wip_product_info['data']['Name'] = $item->get_name();

        $meta_data         = $item->get_meta_data();
        $hideprefix        = '_';
        $hideprefix_length = !empty($hideprefix) ? strlen($hideprefix) : 0;
        $product           = is_callable(array($item, 'get_product')) ? $item->get_product() : false;

        foreach ($meta_data as $meta) {

          if (empty($meta->id) || '' === $meta->value || !is_scalar($meta->value) || ($hideprefix_length && substr($meta->key, 0, $hideprefix_length) === $hideprefix)) {
            continue;
          }

          $meta->key     = rawurldecode((string) $meta->key);
          $meta->value   = rawurldecode((string) $meta->value);
          $attribute_key = str_replace('attribute_', '', $meta->key);
          $display_key   = wc_attribute_label($attribute_key, $product);
          $display_value = wp_kses_post($meta->value);

          if (taxonomy_exists($attribute_key)) {
            $term = get_term_by('slug', $meta->value, $attribute_key);
            if (!is_wp_error($term) && is_object($term) && $term->name) {
              $display_value = $term->name;
            }
          }

          $wip_product_info['data'][$display_key] = $display_value;
        }

        if ('yes' === get_option('wpi-option-display-verification-count', 'no')) {
          $wip_product_info['data'][__('Verified', 'wpi-text-domain')] = $db->get_verify_count($identity_code);
        }
      }
    }


    // Note the verify_count
    $db->verify_count($identity_code);
  }
}


/**
 * Passing product info to JavaScript while verification to display
 */
function wip_product_info_to_JS()
{
  global $wip_product_info;

  $json = json_encode($wip_product_info);

  echo <<<HTML
    <script>
      const product_info = {$json};
    </script>
  HTML;
}


/** 
 * 
 * We begin here
 * 
 */
function boot()
{
  // Keep checking for WooCommerce
  wpi_is_woocommerce_activated();


  /** 
   * 
   * Associate a unique code for each item in order using following hook.
   * Later, we will send this in email to customer as well as admin.
   * Also, will show these to admin.
   * 
   * */
  add_action('woocommerce_new_order_item', 'wpi_create_and_associate', 11, 3);

  // In case admin dare to change the product identity code, we do not hesitate
  add_action('woocommerce_update_order_item', 'wpi_item_modified', 11, 3);

  // This will display the product identity code as a QR code
  add_filter('woocommerce_order_item_display_meta_key', 'wpi_item_meta_display_key', 11, 3);
  add_filter('woocommerce_order_item_display_meta_value', 'wpi_item_meta_display_value', 11, 3);
  add_filter('woocommerce_attribute_label', 'wpi_meta_key_label', 11, 1);

  // Adding style for QR code block at various places
  add_filter('woocommerce_email_styles', 'wpi_add_css_to_emails', 11, 2);
  add_action('admin_enqueue_scripts', 'wpi_admin_style', 11);
  add_action('wp_enqueue_scripts', 'wpi_enqueue_script', 11);

  // Our plugin's settings in WooCommerce -> Settings -> Product
  add_filter('woocommerce_get_sections_products', 'wpi_add_settings_section');
  add_filter('woocommerce_get_settings_products', 'wpi_add_settings', 11, 2);

  // Link to our settings on Plugins page
  add_filter('plugin_action_links_' . WPI_PLUGIN_NAME, 'wpi_add_settings_link', 11);

  // When we verify a product, this will pass the data to JavaScript to display in a modal
  add_action('wp_footer', 'wip_product_info_to_JS');


  /**
   * Let's vefrify the product identity
   */
  wpi_verify_product_identity();
}

add_action('init', 'boot');
