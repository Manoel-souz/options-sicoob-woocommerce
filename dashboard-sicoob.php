<?php
/**
 * Dashboard Sicoob - Acompanhamento de Pagamentos
 * 
 * IMPORTANTE: Este arquivo é apenas para administradores. NÃO use em produção!
 * 
 * Para usar:
 * 1. Coloque este arquivo na raiz do seu WordPress
 * 2. Acesse: https://seusite.com/dashboard-sicoob.php
 * 3. Delete após os testes
 */

// Verificar se o WordPress está carregado
if (!defined('ABSPATH')) {
    require_once('wp-load.php');
}

// Verificar se o usuário é administrador
if (!current_user_can('manage_woocommerce')) {
    wp_die('Acesso negado. Apenas administradores podem acessar este dashboard.');
}

// Verificar se o plugin está ativo
if (!class_exists('Sicoob_API')) {
    wp_die('Plugin Sicoob não está ativo. Ative-o primeiro.');
}

echo "<!DOCTYPE html>";
echo "<html>";
echo "<head>";
echo "<title>Dashboard Sicoob - Pagamentos</title>";
echo "<meta charset='UTF-8'>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }";
echo ".container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }";
echo ".header { background: #0073aa; color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }";
echo ".stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }";
echo ".stat-card { background: #f8f9fa; padding: 20px; border-radius: 8px; text-align: center; border-left: 4px solid #0073aa; }";
echo ".stat-number { font-size: 2em; font-weight: bold; color: #0073aa; }";
echo ".stat-label { color: #666; margin-top: 5px; }";
echo ".section { margin-bottom: 30px; }";
echo ".section h3 { color: #333; border-bottom: 2px solid #0073aa; padding-bottom: 10px; }";
echo "table { width: 100%; border-collapse: collapse; margin-top: 15px; }";
echo "th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }";
echo "th { background: #f8f9fa; font-weight: bold; }";
echo ".status-pending { color: #f39c12; font-weight: bold; }";
echo ".status-approved { color: #27ae60; font-weight: bold; }";
echo ".status-cancelled { color: #e74c3c; font-weight: bold; }";
echo ".status-failed { color: #c0392b; font-weight: bold; }";
echo ".refresh-btn { background: #0073aa; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin-bottom: 20px; }";
echo ".refresh-btn:hover { background: #005a87; }";
echo "</style>";
echo "</head>";
echo "<body>";

echo "<div class='container'>";
echo "<div class='header'>";
echo "<h1>🏦 Dashboard Sicoob - Pagamentos</h1>";
echo "<p>Monitoramento completo de transações e pagamentos</p>";
echo "</div>";

// Botão de atualização
echo "<button class='refresh-btn' onclick='location.reload()'>🔄 Atualizar Dashboard</button>";

// Estatísticas Gerais
echo "<div class='section'>";
echo "<h3>📊 Estatísticas Gerais</h3>";
echo "<div class='stats'>";

// Contar pedidos por status
global $wpdb;
$orders_table = $wpdb->prefix . 'posts';
$meta_table = $wpdb->prefix . 'postmeta';

// Total de pedidos Sicoob
$total_orders = $wpdb->get_var("
    SELECT COUNT(DISTINCT p.ID) 
    FROM {$orders_table} p 
    INNER JOIN {$meta_table} pm ON p.ID = pm.post_id 
    WHERE p.post_type = 'shop_order' 
    AND pm.meta_key = '_payment_method' 
    AND pm.meta_value = 'sicoob'
");

// Pedidos pendentes
$pending_orders = $wpdb->get_var("
    SELECT COUNT(DISTINCT p.ID) 
    FROM {$orders_table} p 
    INNER JOIN {$meta_table} pm ON p.ID = pm.post_id 
    WHERE p.post_type = 'shop_order' 
    AND pm.meta_key = '_payment_method' 
    AND pm.meta_value = 'sicoob'
    AND p.post_status = 'wc-pending'
");

// Pedidos aprovados
$completed_orders = $wpdb->get_var("
    SELECT COUNT(DISTINCT p.ID) 
    FROM {$orders_table} p 
    INNER JOIN {$meta_table} pm ON p.ID = pm.post_id 
    WHERE p.post_type = 'shop_order' 
    AND pm.meta_key = '_payment_method' 
    AND pm.meta_value = 'sicoob'
    AND p.post_status = 'wc-completed'
");

// Total de receita
$total_revenue = $wpdb->get_var("
    SELECT SUM(pm2.meta_value) 
    FROM {$orders_table} p 
    INNER JOIN {$meta_table} pm ON p.ID = pm.post_id 
    INNER JOIN {$meta_table} pm2 ON p.ID = pm2.post_id 
    WHERE p.post_type = 'shop_order' 
    AND pm.meta_key = '_payment_method' 
    AND pm.meta_value = 'sicoob'
    AND pm2.meta_key = '_order_total'
    AND p.post_status = 'wc-completed'
");

echo "<div class='stat-card'>";
echo "<div class='stat-number'>" . ($total_orders ?: 0) . "</div>";
echo "<div class='stat-label'>Total de Pedidos</div>";
echo "</div>";

echo "<div class='stat-card'>";
echo "<div class='stat-number'>" . ($pending_orders ?: 0) . "</div>";
echo "<div class='stat-label'>Pedidos Pendentes</div>";
echo "</div>";

echo "<div class='stat-card'>";
echo "<div class='stat-number'>" . ($completed_orders ?: 0) . "</div>";
echo "<div class='stat-label'>Pedidos Aprovados</div>";
echo "</div>";

echo "<div class='stat-card'>";
echo "<div class='stat-number'>R$ " . number_format(($total_revenue ?: 0), 2, ',', '.') . "</div>";
echo "<div class='stat-label'>Receita Total</div>";
echo "</div>";

echo "</div>";
echo "</div>";

// Transações Sicoob
echo "<div class='section'>";
echo "<h3>💳 Transações Sicoob</h3>";

$transactions_table = $wpdb->prefix . 'sicoob_transactions';
$transactions = $wpdb->get_results("
    SELECT * FROM {$transactions_table} 
    ORDER BY created_at DESC 
    LIMIT 20
");

if ($transactions) {
    echo "<table>";
    echo "<thead>";
    echo "<tr>";
    echo "<th>ID do Pedido</th>";
    echo "<th>Transaction ID</th>";
    echo "<th>Status</th>";
    echo "<th>Valor</th>";
    echo "<th>Criado em</th>";
    echo "<th>Atualizado em</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    
    foreach ($transactions as $transaction) {
        $status_class = 'status-' . $transaction->status;
        echo "<tr>";
        echo "<td><a href='" . admin_url('post.php?post=' . $transaction->order_id . '&action=edit') . "' target='_blank'>#" . $transaction->order_id . "</a></td>";
        echo "<td>" . $transaction->transaction_id . "</td>";
        echo "<td class='{$status_class}'>" . ucfirst($transaction->status) . "</td>";
        echo "<td>R$ " . number_format($transaction->amount, 2, ',', '.') . "</td>";
        echo "<td>" . date('d/m/Y H:i', strtotime($transaction->created_at)) . "</td>";
        echo "<td>" . date('d/m/Y H:i', strtotime($transaction->updated_at)) . "</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
} else {
    echo "<p>Nenhuma transação encontrada.</p>";
}

echo "</div>";

// Pedidos Recentes
echo "<div class='section'>";
echo "<h3>🛒 Pedidos Recentes com Sicoob</h3>";

$recent_orders = $wpdb->get_results("
    SELECT p.ID, p.post_title, p.post_status, p.post_date, pm2.meta_value as total
    FROM {$orders_table} p 
    INNER JOIN {$meta_table} pm ON p.ID = pm.post_id 
    INNER JOIN {$meta_table} pm2 ON p.ID = pm2.post_id 
    WHERE p.post_type = 'shop_order' 
    AND pm.meta_key = '_payment_method' 
    AND pm.meta_value = 'sicoob'
    AND pm2.meta_key = '_order_total'
    ORDER BY p.post_date DESC 
    LIMIT 15
");

if ($recent_orders) {
    echo "<table>";
    echo "<thead>";
    echo "<tr>";
    echo "<th>Pedido</th>";
    echo "<th>Cliente</th>";
    echo "<th>Status</th>";
    echo "<th>Total</th>";
    echo "<th>Data</th>";
    echo "<th>Ações</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    
    foreach ($recent_orders as $order) {
        $order_obj = wc_get_order($order->ID);
        $customer_name = $order_obj ? $order_obj->get_billing_first_name() . ' ' . $order_obj->get_billing_last_name() : 'N/A';
        $status_class = 'status-' . str_replace('wc-', '', $order->post_status);
        
        echo "<tr>";
        echo "<td><a href='" . admin_url('post.php?post=' . $order->ID . '&action=edit') . "' target='_blank'>#" . $order->ID . "</a></td>";
        echo "<td>" . $customer_name . "</td>";
        echo "<td class='{$status_class}'>" . ucfirst(str_replace('wc-', '', $order->post_status)) . "</td>";
        echo "<td>R$ " . number_format($order->total, 2, ',', '.') . "</td>";
        echo "<td>" . date('d/m/Y H:i', strtotime($order->post_date)) . "</td>";
        echo "<td>";
        echo "<a href='" . admin_url('post.php?post=' . $order->ID . '&action=edit') . "' target='_blank'>👁️ Ver</a> | ";
        echo "<a href='" . admin_url('edit.php?post_type=shop_order&action=edit&post=' . $order->ID) . "' target='_blank'>✏️ Editar</a>";
        echo "</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
} else {
    echo "<p>Nenhum pedido encontrado.</p>";
}

echo "</div>";

// Logs do Sistema
echo "<div class='section'>";
echo "<h3>📝 Logs do Sistema</h3>";

$log_dir = SICOOB_WC_PLUGIN_PATH . 'logs/';
$logs = array();

if (is_dir($log_dir)) {
    $files = scandir($log_dir);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..' && pathinfo($file, PATHINFO_EXTENSION) == 'log') {
            $logs[] = $file;
        }
    }
}

if ($logs) {
    echo "<p><strong>Arquivos de log disponíveis:</strong></p>";
    echo "<ul>";
    foreach ($logs as $log) {
        $log_path = $log_dir . $log;
        $log_size = filesize($log_path);
        $log_time = filemtime($log_path);
        
        echo "<li>";
        echo "<strong>{$log}</strong> - ";
        echo "Tamanho: " . number_format($log_size / 1024, 2) . " KB - ";
        echo "Última modificação: " . date('d/m/Y H:i', $log_time);
        echo "</li>";
    }
    echo "</ul>";
    
    echo "<p><strong>Localização dos logs:</strong> <code>{$log_dir}</code></p>";
} else {
    echo "<p>Nenhum arquivo de log encontrado.</p>";
}

echo "</div>";

// Informações do Sistema
echo "<div class='section'>";
echo "<h3>⚙️ Informações do Sistema</h3>";

echo "<table>";
echo "<tr><td><strong>Plugin Sicoob:</strong></td><td>" . (defined('SICOOB_WC_VERSION') ? SICOOB_WC_VERSION : 'Não definida') . "</td></tr>";
echo "<tr><td><strong>WooCommerce:</strong></td><td>" . (defined('WC_VERSION') ? WC_VERSION : 'Não definida') . "</td></tr>";
echo "<tr><td><strong>WordPress:</strong></td><td>" . get_bloginfo('version') . "</td></tr>";
echo "<tr><td><strong>URL do Webhook:</strong></td><td><code>" . home_url('/wc-api/sicoob_webhook') . "</code></td></tr>";
echo "<tr><td><strong>Modo de Teste:</strong></td><td>" . (get_option('woocommerce_sicoob_settings')['testmode'] ?? 'Não configurado') . "</td></tr>";
echo "</table>";

echo "</div>";

echo "</div>";

echo "<script>";
echo "// Auto-refresh a cada 30 segundos";
echo "setTimeout(function() { location.reload(); }, 30000);";
echo "</script>";

echo "</body>";
echo "</html>";
?>

