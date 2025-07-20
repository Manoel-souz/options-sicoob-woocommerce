<?php
/**
 * Arquivo de desinstalação do plugin Sicoob WooCommerce Gateway
 * 
 * Este arquivo é executado quando o plugin é desinstalado
 */

// Se não for chamado pelo WordPress, sair
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Verificar se o usuário tem permissão
if (!current_user_can('activate_plugins')) {
    return;
}

// Remover opções do banco de dados
delete_option('sicoob_woocommerce_version');
delete_option('woocommerce_sicoob_settings');

// Remover tabelas criadas pelo plugin
global $wpdb;

$table_name = $wpdb->prefix . 'sicoob_transactions';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Limpar logs
$log_dir = plugin_dir_path(__FILE__) . 'logs';
if (is_dir($log_dir)) {
    $files = glob($log_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($log_dir);
}

// Limpar cache de rewrite rules
flush_rewrite_rules(); 