<?php
/**
 * Plugin Name: Agitazioni Letterarie Castelluccesi - Iscrizione
 * Description: Modulo iscrizione per-categoria con salvataggio file su cartella CPT, conteggi caratteri/versi, CSV export e pagamento PayPal.
 * Version: 2.7.0
 * Author: Agostino Padula
 * Text Domain: alc-plugin
 */
if ( ! defined('ABSPATH') ) { exit; }
define('ALC_VERSION', '2.7.0');
define('ALC_PLUGIN_FILE', __FILE__);
define('ALC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ALC_PLUGIN_URL', plugin_dir_url(__FILE__));
require_once ALC_PLUGIN_DIR . 'includes/class-alc-plugin.php';
function alc_plugin(){ static $i = null; if(!$i){ $i = new ALC_Plugin(); } return $i; }
add_action('plugins_loaded', 'alc_plugin');
register_activation_hook(__FILE__, ['ALC_Plugin','activate']);
register_deactivation_hook(__FILE__, ['ALC_Plugin','deactivate']);
