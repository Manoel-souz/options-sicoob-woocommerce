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
class Sicoob_API
{

    private $client_id;
    private $client_secret;
    private $environment;
    private $base_url;
    private $access_token;

    /**
     * Construtor
     */
    public function __construct($client_id, $client_secret, $environment = 'production', $access_token = '')
    {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->environment = $environment;
        // Para sandbox, o token é fornecido pelo usuário via configurações
        $this->access_token = $access_token;

        // Definir URL base baseada no ambiente
        if ($environment === 'sandbox') {
            $this->base_url = 'https://sandbox.sicoob.com.br/sicoob/sandbox';
        } else {
            $this->base_url = 'https://api.sicoob.com.br';
        }

        // Para sandbox, não usamos OAuth; utilizamos Bearer Token direto
    }

    /**
     * Obter token de acesso
     */
    private function get_access_token()
    {
        if ($this->access_token) {
            return $this->access_token;
        }

        // Para sandbox, o token deve ser informado nas configurações
        if ($this->environment === 'sandbox') {
            throw new Exception('Access Token do sandbox não configurado. Informe o token nas configurações do gateway.');
        }

        $url = $this->base_url . '/oauth/token';

        $headers = array(
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
    private function make_request($endpoint, $method = 'GET', $data = null, $expect_json = true, $extra_headers = array())
    {
        $url = $this->base_url . $endpoint;

        $headers = array(
            'Authorization: Bearer ' . $this->get_access_token(),
            'Content-Type: application/json',
            'Accept: application/json',
            'Accept-Encoding: gzip, deflate, br',
            'Expect:',
            // Enviar identificadores em todas as chamadas conforme requisito
            'client_id: ' . $this->client_id
        );
        if (!empty($this->client_secret)) {
            $headers[] = 'client_secret: ' . $this->client_secret;
        }
        if (!empty($extra_headers)) {
            $headers = array_merge($headers, $extra_headers);
        }

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
            'httpversion' => '1.1',
            'user-agent' => 'options-sicoob/1.0; WordPress/' . get_bloginfo('version')
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
        $response_data = $expect_json ? json_decode($body, true) : $body;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Sicoob_API Debug:');
            error_log('URL: ' . print_r($url, true));
            error_log('Headers: ' . print_r($headers, true));
            error_log('Corpo enviado: ' . print_r(json_encode($args, JSON_PRETTY_PRINT), true));
            error_log('status_code: ' . print_r($status_code, true));
            error_log('body: ' . print_r($body, true));
            error_log('response_data: ' . print_r($response_data, true));
        }

        // Alguns gateways do Sicoob podem responder 200 com página HTML de WAF
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if ($status_code === 200 && is_string($body) && stripos($body, 'Request Rejected') !== false) {
            throw new Exception('Requisição bloqueada pelo WAF do Sicoob. Verifique headers (client_id, Authorization) e o caminho do endpoint.');
        }
        if ($status_code >= 400) {
            if ($expect_json) {
                $error_message = isset($response_data['message']) ? $response_data['message'] : 'Erro na API do Sicoob';
            } else {
                $error_message = 'Erro na API do Sicoob (conteúdo não-JSON)';
            }
            // Incluir corpo bruto para facilitar o debug
            throw new Exception($error_message . ' (HTTP ' . $status_code . '): ' . $body);
        }

        return $response_data;
    }

    /**
     * PIX - Criar cobrança imediata (PUT /pix/api/v2/cob/{txid})
     */
    public function pix_criar_cobranca_imediata($txid, $valor_original, $nome_devedor, $documento_devedor, $chave_pix, $expiracao = 3600)
    {
        $endpoint = '/pix/api/v2/cob/' . rawurlencode($txid);

        $devedor = array('nome' => $nome_devedor);
        $documento_devedor = preg_replace('/\D+/', '', (string) $documento_devedor);
        if (strlen($documento_devedor) === 14) {
            $devedor['cnpj'] = $documento_devedor;
        } else {
            throw new Exception('CNPJ inválido. Informe um CNPJ com 14 dígitos.');
        }

        $data = array(
            'calendario' => array('expiracao' => (int) $expiracao),
            'devedor' => $devedor,
            'valor' => array(
                'original' => strval(number_format((float) $valor_original, 2, '.', '')),
                'modalidadeAlteracao' => 1
            ),
            'chave' => $chave_pix,
            'solicitacaoPagador' => 'Pagamento do pedido via WooCommerce'
        );

        return $this->make_request($endpoint, 'PUT', $data, true);
    }

    /**
     * PIX - Consultar cobrança (GET /pix/api/v2/cob/{txid})
     */
    public function pix_obter_cobranca($txid)
    {
        $endpoint = '/pix/api/v2/cob/' . rawurlencode($txid);
        return $this->make_request($endpoint, 'GET', null, true);
    }

    /**
     * PIX - Obter imagem (PNG) do QR Code (GET /pix/api/v2/cob/{txid}/imagem)
     * Retorna data URI pronta para uso em <img src="..." />
     */
    public function pix_obter_qr_code_imagem($txid, $largura = 360)
    {
        $endpoint = '/pix/api/v2/cob/' . rawurlencode($txid) . '/imagem?largura=' . intval($largura);
        $png_binary = $this->make_request($endpoint, 'GET', null, false, array('Accept: image/png'));
        $data_uri = 'data:image/png;base64,' . base64_encode($png_binary);
        return $data_uri;
    }

    /**
     * Criar pagamento
     */
    public function create_payment($payment_data)
    {
        // Usar endpoint correto conforme documentação oficial
        $endpoint = '/v1/payments';

        // Estrutura de dados conforme documentação do Sicoob
        $data = array(
            'amount' => array(
                'value' => $payment_data['amount'],
                'currency' => $payment_data['currency']
            ),
            'description' => $payment_data['description'],
            'orderId' => $payment_data['order_id'],
            'customer' => array(
                'name' => $payment_data['customer']['name'],
                'email' => $payment_data['customer']['email'],
                'taxId' => $payment_data['customer']['tax_id']
            ),
            'returnUrl' => $payment_data['return_url'],
            'cancelUrl' => $payment_data['cancel_url'],
            'notificationUrl' => home_url('/wc-api/sicoob_webhook'),
            'paymentMethod' => 'CREDIT_CARD'
        );

        return $this->make_request($endpoint, 'POST', $data);
    }

    /**
     * Obter status do pagamento
     */
    public function get_payment_status($transaction_id)
    {
        $endpoint = '/v1/payments/' . $transaction_id;

        return $this->make_request($endpoint, 'GET');
    }

    /**
     * Cancelar pagamento
     */
    public function cancel_payment($transaction_id)
    {
        $endpoint = '/v1/payments/' . $transaction_id . '/cancel';

        return $this->make_request($endpoint, 'POST');
    }

    /**
     * Obter informações da conta
     */
    public function get_account_info()
    {
        $endpoint = '/v1/account';

        return $this->make_request($endpoint, 'GET');
    }

    /**
     * Validar credenciais
     */
    public function validate_credentials()
    {
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
    public function test_connection()
    {
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
