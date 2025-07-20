<?php
/**
 * Classe para gerenciar webhooks do Sicoob
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe Sicoob_Webhook
 */
class Sicoob_Webhook {
    
    /**
     * Construtor
     */
    public function __construct() {
        add_action('init', array($this, 'register_webhook_endpoint'));
        add_action('wp_ajax_sicoob_test_webhook', array($this, 'test_webhook'));
        add_action('wp_ajax_nopriv_sicoob_test_webhook', array($this, 'test_webhook'));
    }
    
    /**
     * Registrar endpoint do webhook
     */
    public function register_webhook_endpoint() {
        add_rewrite_rule(
            '^wc-api/sicoob_webhook/?$',
            'index.php?wc-api=sicoob_webhook',
            'top'
        );
        
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('parse_request', array($this, 'handle_webhook_request'));
    }
    
    /**
     * Adicionar variáveis de query
     */
    public function add_query_vars($vars) {
        $vars[] = 'wc-api';
        return $vars;
    }
    
    /**
     * Manipular requisição do webhook
     */
    public function handle_webhook_request($wp) {
        if (isset($wp->query_vars['wc-api']) && $wp->query_vars['wc-api'] === 'sicoob_webhook') {
            $this->process_webhook();
        }
    }
    
    /**
     * Processar webhook
     */
    public function process_webhook() {
        // Verificar método HTTP
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die('Method not allowed', 'Sicoob Webhook', array('response' => 405));
        }
        
        // Obter dados do webhook
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            wp_die('Invalid webhook data', 'Sicoob Webhook', array('response' => 400));
        }
        
        // Log do webhook
        $this->log_webhook($data);
        
        // Verificar assinatura
        $signature = $_SERVER['HTTP_X_SICOOB_SIGNATURE'] ?? '';
        if (!$this->verify_signature($input, $signature)) {
            wp_die('Invalid signature', 'Sicoob Webhook', array('response' => 401));
        }
        
        // Processar dados do webhook
        $this->handle_webhook_data($data);
        
        // Responder com sucesso
        wp_die('OK', 'Sicoob Webhook', array('response' => 200));
    }
    
    /**
     * Verificar assinatura do webhook
     */
    private function verify_signature($payload, $signature) {
        $webhook_secret = get_option('woocommerce_sicoob_settings')['webhook_secret'] ?? '';
        
        if (empty($webhook_secret)) {
            return false;
        }
        
        $expected_signature = hash_hmac('sha256', $payload, $webhook_secret);
        return hash_equals($expected_signature, $signature);
    }
    
    /**
     * Manipular dados do webhook
     */
    private function handle_webhook_data($data) {
        $event_type = $data['event_type'] ?? '';
        $transaction_id = $data['transaction_id'] ?? '';
        $status = $data['status'] ?? '';
        
        if (!$transaction_id || !$status) {
            return;
        }
        
        // Buscar pedido pela transação
        global $wpdb;
        $table_name = $wpdb->prefix . 'sicoob_transactions';
        $transaction = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE transaction_id = %s", $transaction_id)
        );
        
        if (!$transaction) {
            $this->log_webhook_error('Transaction not found: ' . $transaction_id);
            return;
        }
        
        $order = wc_get_order($transaction->order_id);
        if (!$order) {
            $this->log_webhook_error('Order not found: ' . $transaction->order_id);
            return;
        }
        
        // Atualizar status da transação
        $this->update_transaction_status($transaction_id, $status);
        
        // Atualizar status do pedido baseado no evento
        switch ($event_type) {
            case 'payment.approved':
                $this->handle_payment_approved($order, $transaction_id, $data);
                break;
                
            case 'payment.cancelled':
                $this->handle_payment_cancelled($order, $transaction_id, $data);
                break;
                
            case 'payment.failed':
                $this->handle_payment_failed($order, $transaction_id, $data);
                break;
                
            case 'payment.pending':
                $this->handle_payment_pending($order, $transaction_id, $data);
                break;
                
            default:
                $this->log_webhook_error('Unknown event type: ' . $event_type);
                break;
        }
    }
    
    /**
     * Manipular pagamento aprovado
     */
    private function handle_payment_approved($order, $transaction_id, $data) {
        $order->payment_complete($transaction_id);
        $order->add_order_note(
            sprintf(
                __('Pagamento aprovado via Sicoob. Transaction ID: %s', 'sicoob-woocommerce'),
                $transaction_id
            )
        );
        
        // Enviar e-mail de confirmação se necessário
        $this->send_payment_confirmation_email($order);
    }
    
    /**
     * Manipular pagamento cancelado
     */
    private function handle_payment_cancelled($order, $transaction_id, $data) {
        $order->update_status(
            'cancelled',
            sprintf(
                __('Pagamento cancelado via Sicoob. Transaction ID: %s', 'sicoob-woocommerce'),
                $transaction_id
            )
        );
    }
    
    /**
     * Manipular pagamento falhou
     */
    private function handle_payment_failed($order, $transaction_id, $data) {
        $order->update_status(
            'failed',
            sprintf(
                __('Pagamento falhou via Sicoob. Transaction ID: %s', 'sicoob-woocommerce'),
                $transaction_id
            )
        );
    }
    
    /**
     * Manipular pagamento pendente
     */
    private function handle_payment_pending($order, $transaction_id, $data) {
        $order->update_status(
            'pending',
            sprintf(
                __('Pagamento pendente via Sicoob. Transaction ID: %s', 'sicoob-woocommerce'),
                $transaction_id
            )
        );
    }
    
    /**
     * Atualizar status da transação
     */
    private function update_transaction_status($transaction_id, $status) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sicoob_transactions';
        
        $wpdb->update(
            $table_name,
            array('status' => $status),
            array('transaction_id' => $transaction_id),
            array('%s'),
            array('%s')
        );
    }
    
    /**
     * Log do webhook
     */
    private function log_webhook($data) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'data' => $data
        );
        
        $log_file = SICOOB_WC_PLUGIN_PATH . 'logs/webhook.log';
        $log_dir = dirname($log_file);
        
        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        file_put_contents($log_file, json_encode($log_entry) . "\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Log de erro do webhook
     */
    private function log_webhook_error($message) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'error' => $message
        );
        
        $log_file = SICOOB_WC_PLUGIN_PATH . 'logs/webhook-error.log';
        $log_dir = dirname($log_file);
        
        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        file_put_contents($log_file, json_encode($log_entry) . "\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Enviar e-mail de confirmação
     */
    private function send_payment_confirmation_email($order) {
        $mailer = WC()->mailer();
        $email = $mailer->get_emails()['WC_Email_Customer_Completed_Order'] ?? null;
        
        if ($email) {
            $email->trigger($order->get_id());
        }
    }
    
    /**
     * Testar webhook
     */
    public function test_webhook() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        
        $webhook_url = home_url('/wc-api/sicoob_webhook');
        
        $test_data = array(
            'event_type' => 'payment.test',
            'transaction_id' => 'test_' . time(),
            'status' => 'test',
            'timestamp' => current_time('mysql')
        );
        
        $response = wp_remote_post($webhook_url, array(
            'headers' => array(
                'Content-Type: application/json',
                'X-Sicoob-Signature: ' . hash_hmac('sha256', json_encode($test_data), 'test_secret')
            ),
            'body' => json_encode($test_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Erro ao testar webhook: ' . $response->get_error_message());
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code === 200) {
                wp_send_json_success('Webhook testado com sucesso');
            } else {
                wp_send_json_error('Webhook retornou status: ' . $status_code);
            }
        }
    }
    
    /**
     * Obter URL do webhook
     */
    public static function get_webhook_url() {
        return home_url('/wc-api/sicoob_webhook');
    }
} 