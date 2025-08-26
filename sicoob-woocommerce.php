<?php

/**
 * Plugin Name: Options Sicoob WooCommerce Gateway
 * Plugin URI: https://github.com/Manoel-souz/options-sicoob-woocommerce
 * Description: Gateway de pagamento Sicoob para WooCommerce
 * Version: 1.0.0
 * Author: Manoel Souza
 * Author URI: https://optionstech.com.br
 * Text Domain: options-sicoob-woocommerce
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Verificar se o WooCommerce está ativo
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Verificar compatibilidade com HPOS
if (class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil')) {
    if (!Automattic\WooCommerce\Utilities\FeaturesUtil::is_feature_enabled('custom_order_tables')) {
        // HPOS não está ativo, continuar normalmente
    } else {
        // HPOS está ativo, verificar compatibilidade
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>Sicoob WooCommerce Gateway:</strong> Este plugin foi atualizado para suportar HPOS (High-Performance Order Storage). Se você encontrar problemas, desative temporariamente o HPOS em WooCommerce > Configurações > Avançado > Recursos.</p>';
            echo '</div>';
        });
    }
}

// Definir constantes
define('SICOOB_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SICOOB_WC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SICOOB_WC_VERSION', '1.0.0');

/**
 * Classe principal do plugin
 */
class Sicoob_WooCommerce_Gateway
{

    /**
     * Construtor
     */
    public function __construct()
    {
        add_action('plugins_loaded', array($this, 'init'));
        add_action('init', array($this, 'load_textdomain'));
    }

    /**
     * Inicializar o plugin
     */
    public function init()
    {
        // Carregar gateway de pagamento
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));

        // Carregar classes
        require_once SICOOB_WC_PLUGIN_PATH . 'includes/class-sicoob-gateway.php';
        require_once SICOOB_WC_PLUGIN_PATH . 'includes/class-sicoob-api.php';
        require_once SICOOB_WC_PLUGIN_PATH . 'includes/class-sicoob-webhook.php';

        // Hooks de ativação/desativação
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Declarar compatibilidade com HPOS
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
    }

    /**
     * Carregar traduções
     */
    public function load_textdomain()
    {
        load_plugin_textdomain('sicoob-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Adicionar gateway ao WooCommerce
     */
    public function add_gateway($gateways)
    {
        $gateways[] = 'WC_Gateway_Sicoob';
        return $gateways;
    }

    /**
     * Declarar compatibilidade com HPOS
     */
    public function declare_hpos_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    /**
     * Ativação do plugin
     */
    public function activate()
    {
        // Criar tabelas necessárias
        $this->create_tables();

        // Adicionar opções padrão
        add_option('sicoob_woocommerce_version', SICOOB_WC_VERSION);

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Desativação do plugin
     */
    public function deactivate()
    {
        // Limpar dados temporários se necessário
        flush_rewrite_rules();
    }

    /**
     * Criar tabelas necessárias
     */
    private function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $table_name = $wpdb->prefix . 'sicoob_transactions';

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            transaction_id varchar(255) NOT NULL,
            status varchar(50) NOT NULL,
            amount decimal(10,2) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY transaction_id (transaction_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Inicializar o plugin
new Sicoob_WooCommerce_Gateway();
