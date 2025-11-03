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

// Verificação de HPOS será feita após o WooCommerce estar carregado

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
        // Permitir upload de PFX/P12 via biblioteca de mídia
        add_filter('upload_mimes', array($this, 'allow_pfx_mimes'));
        add_filter('wp_check_filetype_and_ext', array($this, 'allow_pfx_filetype'), 10, 4);
    }

    /**
     * Inicializar o plugin
     */
    public function init()
    {
        // Carregar gateway de pagamento
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));

        // Carregar classes
        // API e Webhook não dependem do WooCommerce para serem carregados
        require_once SICOOB_WC_PLUGIN_PATH . 'includes/class-sicoob-api.php';
        require_once SICOOB_WC_PLUGIN_PATH . 'includes/class-sicoob-webhook.php';

        // Carregar a classe do gateway APÓS o WooCommerce estar carregado
        add_action('woocommerce_loaded', array($this, 'load_gateway_class'));

        // Carregar classe admin apenas no admin
        add_action('admin_init', array($this, 'load_admin_class'));

        // Verificar compatibilidade com HPOS após WooCommerce estar carregado
        add_action('woocommerce_loaded', array($this, 'check_hpos_compatibility'));

        // Declarar compatibilidade com HPOS
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
    }

    /**
     * Carregar classe admin
     */
    public function load_admin_class()
    {
        if (!class_exists('Sicoob_Admin')) {
            require_once SICOOB_WC_PLUGIN_PATH . 'includes/class-sicoob-admin.php';
            new Sicoob_Admin();
        }
    }

    /**
     * Carrega a classe do gateway quando o WooCommerce já está carregado
     */
    public function load_gateway_class()
    {
        if (!class_exists('WC_Payment_Gateway')) {
            // WooCommerce não carregado corretamente; evitar fatal
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>' . esc_html__('Sicoob: WooCommerce não foi carregado corretamente. Atualize/ative o WooCommerce.', 'sicoob-woocommerce') . '</p></div>';
            });
            return;
        }

        // Agora é seguro carregar o gateway
        require_once SICOOB_WC_PLUGIN_PATH . 'includes/class-sicoob-gateway.php';
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
        // Garantir que a classe do gateway esteja carregada antes de registrar
        if (!class_exists('WC_Gateway_Sicoob')) {
            // Tenta carregar usando o método dedicado (já verifica WC_Payment_Gateway)
            $this->load_gateway_class();
            // Fallback adicional caso o hook ainda não tenha disparado
            if (!class_exists('WC_Gateway_Sicoob') && file_exists(SICOOB_WC_PLUGIN_PATH . 'includes/class-sicoob-gateway.php')) {
                require_once SICOOB_WC_PLUGIN_PATH . 'includes/class-sicoob-gateway.php';
            }
        }

        $gateways[] = 'WC_Gateway_Sicoob';
        return $gateways;
    }

    /**
     * Verificar compatibilidade com HPOS
     */
    public function check_hpos_compatibility()
    {
        if (class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            // Verificar se HPOS está ativo usando método compatível
            $hpos_enabled = false;
            
            // Tentar diferentes métodos de verificação baseado na versão do WooCommerce
            if (method_exists('Automattic\WooCommerce\Utilities\FeaturesUtil', 'is_feature_enabled')) {
                $hpos_enabled = Automattic\WooCommerce\Utilities\FeaturesUtil::is_feature_enabled('custom_order_tables');
            } elseif (method_exists('Automattic\WooCommerce\Utilities\FeaturesUtil', 'feature_is_enabled')) {
                $hpos_enabled = Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled('custom_order_tables');
            } else {
                // Fallback: verificar se a classe OrderUtil existe (indica HPOS ativo)
                $hpos_enabled = class_exists('Automattic\WooCommerce\Utilities\OrderUtil');
            }
            
            if ($hpos_enabled) {
                // HPOS está ativo, mostrar aviso
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-warning is-dismissible">';
                    echo '<p><strong>Sicoob WooCommerce Gateway:</strong> Este plugin foi atualizado para suportar HPOS (High-Performance Order Storage). Se você encontrar problemas, desative temporariamente o HPOS em WooCommerce > Configurações > Avançado > Recursos.</p>';
                    echo '</div>';
                });
            }
        }
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
     * Autoriza upload de arquivos .pfx e .p12 na mídia
     */
    public function allow_pfx_mimes($mimes)
    {
        $mimes['pfx'] = 'application/x-pkcs12';
        $mimes['p12'] = 'application/x-pkcs12';
        return $mimes;
    }

    /**
     * Força reconhecimento do tipo/extensão para PFX/P12 quando o fileinfo retornar genérico
     */
    public function allow_pfx_filetype($data, $file, $filename, $mimes)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, array('pfx', 'p12'), true)) {
            $data['ext'] = $ext;
            // Aceita tanto application/x-pkcs12 quanto octet-stream
            $data['type'] = 'application/x-pkcs12';
            if (empty($data['proper_filename'])) {
                $data['proper_filename'] = $filename;
            }
        }
        return $data;
    }
}

/**
 * Função de ativação do plugin
 */
function sicoob_wc_activate() {
    // Verificar se o WooCommerce está ativo
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('Este plugin requer o WooCommerce para funcionar. Por favor, instale e ative o WooCommerce primeiro.', 'sicoob-woocommerce'),
            __('Erro de Ativação', 'sicoob-woocommerce'),
            array('back_link' => true)
        );
    }

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'sicoob_transactions';

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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

    // Adicionar opções padrão
    add_option('sicoob_woocommerce_version', SICOOB_WC_VERSION);

    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Função de desativação do plugin
 */
function sicoob_wc_deactivate() {
    // Limpar dados temporários se necessário
    flush_rewrite_rules();
}

// Hooks de ativação e desativação
register_activation_hook(__FILE__, 'sicoob_wc_activate');
register_deactivation_hook(__FILE__, 'sicoob_wc_deactivate');

// Inicializar o plugin
new Sicoob_WooCommerce_Gateway();
