<?php
/**
 * Classe para comunicação com a API do Sicoob
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe Sicoob_API
 */
class Sicoob_API {
    
    private $client_id;
    private $client_secret;
    private $environment;
    private $base_url;
    private $access_token;
    
    /**
     * Construtor
     */
    public function __construct($client_id, $client_secret, $environment = 'production') {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->environment = $environment;
        
        // Definir URL base baseada no ambiente
        if ($environment === 'sandbox') {
            $this->base_url = 'https://sandbox.api.sicoob.com.br';
        } else {
            $this->base_url = 'https://api.sicoob.com.br';
        }
    }
    
    /**
     * Obter token de acesso
     */
    private function get_access_token() {
        if ($this->access_token) {
            return $this->access_token;
        }
        
        $url = $this->base_url . '/oauth/token';
        
        $headers = array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode($this->client_id . ':' . $this->client_secret)
        );
        
        $data = array(
            'grant_type' => 'client_credentials',
            'scope' => 'payment'
        );
        
        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => http_build_query($data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('Erro ao obter token de acesso: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['access_token'])) {
            throw new Exception('Resposta inválida da API do Sicoob');
        }
        
        $this->access_token = $data['access_token'];
        return $this->access_token;
    }
    
    /**
     * Fazer requisição para a API
     */
    private function make_request($endpoint, $method = 'GET', $data = null) {
        $url = $this->base_url . $endpoint;
        
        $headers = array(
            'Authorization: Bearer ' . $this->get_access_token(),
            'Content-Type: application/json'
        );
        
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30
        );
        
        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            throw new Exception('Erro na requisição: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $response_data = json_decode($body, true);
        
        if ($status_code >= 400) {
            $error_message = isset($response_data['message']) ? $response_data['message'] : 'Erro na API do Sicoob';
            throw new Exception($error_message);
        }
        
        return $response_data;
    }
    
    /**
     * Criar pagamento
     */
    public function create_payment($payment_data) {
        $endpoint = '/v1/payments';
        
        $data = array(
            'amount' => $payment_data['amount'],
            'currency' => $payment_data['currency'],
            'description' => $payment_data['description'],
            'order_id' => $payment_data['order_id'],
            'customer' => $payment_data['customer'],
            'return_url' => $payment_data['return_url'],
            'cancel_url' => $payment_data['cancel_url'],
            'notification_url' => home_url('/wc-api/sicoob_webhook'),
        );
        
        return $this->make_request($endpoint, 'POST', $data);
    }
    
    /**
     * Obter status do pagamento
     */
    public function get_payment_status($transaction_id) {
        $endpoint = '/v1/payments/' . $transaction_id;
        
        return $this->make_request($endpoint, 'GET');
    }
    
    /**
     * Cancelar pagamento
     */
    public function cancel_payment($transaction_id) {
        $endpoint = '/v1/payments/' . $transaction_id . '/cancel';
        
        return $this->make_request($endpoint, 'POST');
    }
    
    /**
     * Obter informações da conta
     */
    public function get_account_info() {
        $endpoint = '/v1/account';
        
        return $this->make_request($endpoint, 'GET');
    }
    
    /**
     * Validar credenciais
     */
    public function validate_credentials() {
        try {
            $this->get_access_token();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Testar conexão
     */
    public function test_connection() {
        try {
            $account_info = $this->get_account_info();
            return array(
                'success' => true,
                'message' => 'Conexão com Sicoob estabelecida com sucesso',
                'account' => $account_info
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Erro na conexão: ' . $e->getMessage()
            );
        }
    }
} 