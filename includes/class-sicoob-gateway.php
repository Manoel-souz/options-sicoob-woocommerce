<?php
/**
 * Gateway de Pagamento Sicoob
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe WC_Gateway_Sicoob
 */
class WC_Gateway_Sicoob extends WC_Payment_Gateway {
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->id = 'sicoob';
        $this->icon = SICOOB_WC_PLUGIN_URL . 'assets/images/sicoob-logo.png';
        $this->has_fields = false;
        $this->method_title = __('Sicoob', 'sicoob-woocommerce');
        $this->method_description = __('Aceite pagamentos via Sicoob', 'sicoob-woocommerce');
        
        // Carregar configurações
        $this->init_form_fields();
        $this->init_settings();
        
        // Definir propriedades
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->client_id = $this->get_option('client_id');
        $this->client_secret = $this->get_option('client_secret');
        $this->environment = $this->testmode ? 'sandbox' : 'production';
        
        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_sicoob_webhook', array($this, 'webhook_handler'));
    }
    
    /**
     * Campos do formulário de configuração
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Habilitar/Desabilitar', 'sicoob-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Habilitar Sicoob', 'sicoob-woocommerce'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Título', 'sicoob-woocommerce'),
                'type' => 'text',
                'description' => __('Título que o cliente verá durante o checkout', 'sicoob-woocommerce'),
                'default' => __('Cartão de Crédito', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Descrição', 'sicoob-woocommerce'),
                'type' => 'textarea',
                'description' => __('Descrição que o cliente verá durante o checkout', 'sicoob-woocommerce'),
                'default' => __('Pague com segurança via Sicoob', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'testmode' => array(
                'title' => __('Modo de Teste', 'sicoob-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Habilitar modo de teste', 'sicoob-woocommerce'),
                'default' => 'yes',
                'description' => __('Sicoob modo de teste pode ser usado para testar pagamentos', 'sicoob-woocommerce'),
            ),
            'client_id' => array(
                'title' => __('Client ID', 'sicoob-woocommerce'),
                'type' => 'text',
                'description' => __('Client ID fornecido pelo Sicoob', 'sicoob-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'client_secret' => array(
                'title' => __('Client Secret (Opcional)', 'sicoob-woocommerce'),
                'type' => 'password',
                'description' => __('Client Secret fornecido pelo Sicoob. No sandbox, pode ser deixado em branco.', 'sicoob-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'webhook_secret' => array(
                'title' => __('Webhook Secret', 'sicoob-woocommerce'),
                'type' => 'password',
                'description' => __('Secret para validar webhooks do Sicoob', 'sicoob-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
        );
    }
    
    /**
     * Processar pagamento
     */
    public function process_payment($order_id) {
        // Usar método compatível com HPOS
        $order = wc_get_order($order_id);
        
        // Verificar se a ordem existe
        if (!$order) {
            return array(
                'result' => 'fail',
                'redirect' => ''
            );
        }
        
        try {
            // Criar transação no Sicoob
            $api = new Sicoob_API($this->client_id, $this->client_secret, $this->environment);
            
            $payment_data = array(
                'amount' => $order->get_total() * 100, // Converter para centavos
                'currency' => 'BRL',
                'order_id' => $order_id,
                'description' => sprintf(__('Pedido #%s', 'sicoob-woocommerce'), $order_id),
                'customer' => array(
                    'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'phone' => $order->get_billing_phone(),
                ),
                'return_url' => $this->get_return_url($order),
                'cancel_url' => $order->get_cancel_order_url(),
            );
            
            $response = $api->create_payment($payment_data);
            
            if ($response && isset($response['payment_url'])) {
                // Salvar dados da transação
                $this->save_transaction($order_id, $response['transaction_id'], 'pending');
                
                // Atualizar status do pedido
                $order->update_status('pending', __('Aguardando pagamento via Sicoob', 'sicoob-woocommerce'));
                
                // Redirecionar para página de pagamento
                return array(
                    'result' => 'success',
                    'redirect' => $response['payment_url']
                );
            } else {
                throw new Exception(__('Erro ao criar transação no Sicoob', 'sicoob-woocommerce'));
            }
            
        } catch (Exception $e) {
            wc_add_notice(__('Erro no pagamento: ', 'sicoob-woocommerce') . $e->getMessage(), 'error');
            return array(
                'result' => 'fail',
                'redirect' => ''
            );
        }
    }
    
    /**
     * Salvar transação no banco de dados
     */
    private function save_transaction($order_id, $transaction_id, $status) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sicoob_transactions';
        
        $wpdb->insert(
            $table_name,
            array(
                'order_id' => $order_id,
                'transaction_id' => $transaction_id,
                'status' => $status,
                'amount' => wc_get_order($order_id)->get_total(),
            ),
            array('%d', '%s', '%s', '%f')
        );
    }
    
    /**
     * Atualizar status da transação
     */
    public function update_transaction_status($transaction_id, $status) {
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
     * Handler para webhooks
     */
    public function webhook_handler() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            wp_die('Invalid webhook data', 'Sicoob Webhook', array('response' => 400));
        }
        
        // Verificar assinatura do webhook
        $signature = $_SERVER['HTTP_X_SICOOB_SIGNATURE'] ?? '';
        if (!$this->verify_webhook_signature($input, $signature)) {
            wp_die('Invalid signature', 'Sicoob Webhook', array('response' => 401));
        }
        
        // Processar webhook
        $this->process_webhook($data);
        
        wp_die('OK', 'Sicoob Webhook', array('response' => 200));
    }
    
    /**
     * Verificar assinatura do webhook
     */
    private function verify_webhook_signature($payload, $signature) {
        $expected_signature = hash_hmac('sha256', $payload, $this->get_option('webhook_secret'));
        return hash_equals($expected_signature, $signature);
    }
    
    /**
     * Processar dados do webhook
     */
    private function process_webhook($data) {
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
            return;
        }
        
        $order = wc_get_order($transaction->order_id);
        if (!$order) {
            return;
        }
        
        // Atualizar status da transação
        $this->update_transaction_status($transaction_id, $status);
        
        // Atualizar status do pedido
        switch ($status) {
            case 'approved':
                $order->payment_complete($transaction_id);
                $order->add_order_note(__('Pagamento aprovado via Sicoob', 'sicoob-woocommerce'));
                break;
                
            case 'cancelled':
                $order->update_status('cancelled', __('Pagamento cancelado via Sicoob', 'sicoob-woocommerce'));
                break;
                
            case 'failed':
                $order->update_status('failed', __('Pagamento falhou via Sicoob', 'sicoob-woocommerce'));
                break;
                
            case 'pending':
                $order->update_status('pending', __('Pagamento pendente via Sicoob', 'sicoob-woocommerce'));
                break;
        }
    }
} 