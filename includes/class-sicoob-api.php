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
    // mTLS e app key (produção)
    private $mtls_cert_path;
    private $mtls_key_path;
    private $mtls_key_pass;
    private $app_key;
    private $tmp_pfx_path; // temp file for PFX during requests
    private $pfx_file_url;
    private $pfx_password;
    private $base_url_pagamentos; // URL base para endpoints de pagamentos (boletos)

    /**
     * Construtor
     */
    public function __construct($client_id, $client_secret, $environment = 'production', $access_token = '', $extras = array())
    {
        $this->client_id = $client_id;
        // Em produção, normalmente não se usa client_secret (somente mTLS)
        $this->client_secret = $environment === 'production' ? '' : $client_secret;
        $this->environment = $environment;
        // Para sandbox, o token é fornecido pelo usuário via configurações
        $this->access_token = $access_token;
        // Extras
        $this->mtls_cert_path = isset($extras['mtls_cert_path']) ? (string) $extras['mtls_cert_path'] : '';
        $this->mtls_key_path  = isset($extras['mtls_key_path']) ? (string) $extras['mtls_key_path'] : '';
        $this->mtls_key_pass  = isset($extras['mtls_key_pass']) ? (string) $extras['mtls_key_pass'] : '';
        $this->app_key        = isset($extras['app_key']) ? (string) $extras['app_key'] : '';
        // PFX proveniente da tela (caso não use armazenamento criptografado)
        $this->pfx_file_url   = isset($extras['pfx_file_url']) ? (string) $extras['pfx_file_url'] : '';
        $this->pfx_password   = isset($extras['pfx_password']) ? (string) $extras['pfx_password'] : '';

        // Definir URL base baseada no ambiente
        if ($environment === 'sandbox') {
            $this->base_url = 'https://sandbox.sicoob.com.br/sicoob/sandbox';
            $this->base_url_pagamentos = 'https://sandbox.sicoob.com.br/sicoob/sandbox/pagamentos/v3';
        } else {
            $this->base_url = 'https://api.sicoob.com.br';
            $this->base_url_pagamentos = 'https://api.sicoob.com.br/pagamentos/v3';
        }

        // Para sandbox, não usamos OAuth; utilizamos Bearer Token direto

        // Em produção, PIX exige mTLS. Anexa certificados via cURL quando configurados.
        if ($this->environment === 'production') {
            add_action('http_api_curl', array($this, 'inject_mtls'), 10, 3);
        }
    }

    /**
     * Descriptografia compatível com Sicoob_Admin::decrypt_secret
     */
    private function decrypt_secret_compat($blob)
    {
        if (!$blob) return '';
        $raw = base64_decode($blob, true);
        if ($raw === false) return '';
        $key = wp_salt('auth');
        if (function_exists('sodium_crypto_secretbox_open')) {
            if (strlen($raw) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $plain = @sodium_crypto_secretbox_open($cipher, $nonce, substr(hash('sha256', $key, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
                return $plain === false ? '' : $plain;
            }
        }
        // OpenSSL fallback AES-256-GCM
        if (strlen($raw) > 28) {
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
            return $plain === false ? '' : $plain;
        }
        return '';
    }

    /**
     * Localiza PFX (blob/URL/arquivo no diretório do plugin) e senha.
     */
    private function resolve_pfx_raw_and_pass()
    {
        $raw = '';
        $pass = $this->pfx_password ?: $this->decrypt_secret_compat(get_option('sicoob_pfx_password_enc'));
        if (!$pass && defined('SICOOB_MTLS_KEYPASS')) {
            $pass = SICOOB_MTLS_KEYPASS;
        }

        // 1) Blob criptografado salvo
        $pfx_blob = get_option('sicoob_pfx_blob');
        if ($pfx_blob) {
            $raw = $this->decrypt_secret_compat($pfx_blob);
        }

        // 2) Arquivo fixo no diretório raiz do plugin
        if (!$raw && defined('SICOOB_WC_PLUGIN_PATH')) {
            $fixed = SICOOB_WC_PLUGIN_PATH . 'MUNDIAL HOSPITALAR PRODUTOS PARA SAUDE LTDA - 08002459000489.pfx';
            if (file_exists($fixed)) {
                $raw = file_get_contents($fixed);
            }
        }

        // 3) URL informada
        if (!$raw && $this->pfx_file_url) {
            $uploads = wp_get_upload_dir();
            if (strpos($this->pfx_file_url, $uploads['baseurl']) === 0) {
                $local = str_replace($uploads['baseurl'], $uploads['basedir'], $this->pfx_file_url);
                if (file_exists($local)) {
                    $raw = file_get_contents($local);
                }
            }
            if (!$raw) {
                $resp = wp_remote_get($this->pfx_file_url, array('timeout' => 20));
                if (!is_wp_error($resp)) {
                    $raw = wp_remote_retrieve_body($resp);
                }
            }
        }


        return array($raw, $pass);
    }

    /**
     * Injeta opções de mTLS para chamadas cURL quando em produção.
     * Configure no wp-config.php (ou via servidor) as constantes:
     *  - SICOOB_MTLS_CERT (caminho absoluto do .pem/.crt)
     *  - SICOOB_MTLS_KEY  (caminho absoluto da chave .key/.pem)
     *  - SICOOB_MTLS_KEYPASS (opcional, senha da chave)
     */
    public function inject_mtls($handle, $r, $url)
    {
        if ($this->environment !== 'production') {
            return;
        }
        // Aplica apenas para chamadas ao domínio da API Sicoob
        if (strpos($url, $this->base_url) !== 0) {
            return;
        }
        // 1) Preferir PFX (blob/URL/arquivo raiz do plugin)
        list($raw, $pass) = $this->resolve_pfx_raw_and_pass();
        if ($raw && !$this->tmp_pfx_path) {
            $this->tmp_pfx_path = wp_tempnam('sicoob_pfx_runtime');
            if ($this->tmp_pfx_path) {
                file_put_contents($this->tmp_pfx_path, $raw);
                add_action('shutdown', function(){
                    if (file_exists($this->tmp_pfx_path)) { @unlink($this->tmp_pfx_path); }
                });
                $this->mtls_key_pass = $this->mtls_key_pass ?: $pass;
            }
        }
        if ($this->tmp_pfx_path && file_exists($this->tmp_pfx_path)) {
            @curl_setopt($handle, CURLOPT_SSLCERT, $this->tmp_pfx_path);
            @curl_setopt($handle, CURLOPT_SSLCERTTYPE, 'P12');
            if ($this->mtls_key_pass) {
                @curl_setopt($handle, CURLOPT_SSLCERTPASSWD, $this->mtls_key_pass);
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][mTLS] Certificado PFX aplicado ao request: ' . $this->tmp_pfx_path);
            }
            return; // Já configurado via PFX
        }

        // 2) Fallback para PEM + KEY se informados nas opções/constantes
        $cert = $this->mtls_cert_path ?: (defined('SICOOB_MTLS_CERT') ? SICOOB_MTLS_CERT : '');
        $key  = $this->mtls_key_path ?: (defined('SICOOB_MTLS_KEY') ? SICOOB_MTLS_KEY : '');
        $pwd  = $this->mtls_key_pass ?: (defined('SICOOB_MTLS_KEYPASS') ? SICOOB_MTLS_KEYPASS : '');
        if ($cert && file_exists($cert)) {
            @curl_setopt($handle, CURLOPT_SSLCERT, $cert);
        }
        if ($key && file_exists($key)) {
            @curl_setopt($handle, CURLOPT_SSLKEY, $key);
        }
        if ($pwd) {
            @curl_setopt($handle, CURLOPT_KEYPASSWD, $pwd);
        }
    }

    /**
     * Obter token de acesso
     */
    /**
     * Obter token de acesso com debug aprimorado
     */
    private function get_access_token()
    {
        // Para facilitar debug, juntar logs
        $debug_log = array();

        // Sandbox: usa token informado manualmente no campo da configuração
        if ($this->environment === 'sandbox') {
            $debug_log[] = '[SICOOB] Ambiente sandbox detectado';
            if ($this->access_token) {
                $debug_log[] = '[SICOOB] Token do sandbox já está configurado';
                if (defined('WP_DEBUG') && WP_DEBUG) error_log(implode("\n", $debug_log));
                return $this->access_token;
            }
            $debug_log[] = '[SICOOB][ERRO] Access Token do sandbox não configurado.';
            if (defined('WP_DEBUG') && WP_DEBUG) error_log(implode("\n", $debug_log));
            throw new Exception('Access Token do sandbox não configurado. Informe o token nas configurações do gateway.');
        }

        // Produção: obter via endpoint OAuth da autorização (com PFX)
        $token_url = 'https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token';
        $debug_log[] = "[SICOOB] Ambiente de produção. URL do token: {$token_url}";

        // Cache de token (evita solicitar a cada requisição)
        $cache_key = 'sicoob_token_' . md5($this->client_id . '|' . $this->environment);
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached['access_token'])) {
            $debug_log[] = '[SICOOB] Token obtido do cache com sucesso';
            if (defined('WP_DEBUG') && WP_DEBUG) error_log(implode("\n", $debug_log));
            return $cached['access_token'];
        }
        $debug_log[] = "[SICOOB] Cache de token vazio ou expirado (key: {$cache_key})";

        // Prepara arquivo PFX temporário SEMPRE a partir do arquivo fixo na raiz do plugin
        list($raw_from_root, $pass_from_root) = $this->resolve_pfx_raw_and_pass();
        $pfx_pass = $pass_from_root;
        $tmp_pfx = '';
        if ($raw_from_root) {
            $tmp_pfx = wp_tempnam('sicoob_pfx_root');
            if ($tmp_pfx) {
                file_put_contents($tmp_pfx, $raw_from_root);
                $debug_log[] = "[SICOOB] PFX da raiz do plugin carregado em: {$tmp_pfx}";
            } else {
                $debug_log[] = '[SICOOB][ERRO] Falha ao criar arquivo temporário para PFX da raiz!';
            }
        } else {
            if (defined('SICOOB_WC_PLUGIN_PATH')) {
                $debug_log[] = '[SICOOB][ERRO] PFX não encontrado na raiz do plugin: ' . SICOOB_WC_PLUGIN_PATH;
            } else {
                $debug_log[] = '[SICOOB][ERRO] Constante SICOOB_WC_PLUGIN_PATH não definida.';
            }
        }
        if (!$tmp_pfx) {
            $debug_log[] = '[SICOOB][ERRO] Não foi possível preparar um arquivo PFX para uso.';
        }

        // Monta cabeçalhos
        $token_headers = array('Content-Type: application/x-www-form-urlencoded');
        if (!empty($this->client_secret)) {
            $token_headers[] = 'Authorization: Basic ' . base64_encode($this->client_id . ':' . $this->client_secret);
            $debug_log[] = '[SICOOB] client_secret presente, Authorization: Basic será enviado';
        } else {
            $debug_log[] = '[SICOOB] client_secret NÃO informado!';
        }

        // Dados de POST usados para OAuth
        $token_post_fields = array(
            'client_id' => $this->client_id,
            'grant_type' => 'client_credentials',
            'scope' => 'cob.write cob.read cobv.write cobv.read pix.write pix.read webhook.read webhook.write payloadlocation.read payloadlocation.write'
        );
        $debug_log[] = '[SICOOB] Campos enviados no POST ao token endpoint: ' . json_encode($token_post_fields);

        // Prepara e executa cURL para token
        $ch = curl_init($token_url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($token_post_fields),
            CURLOPT_HTTPHEADER => $token_headers,
            CURLOPT_RETURNTRANSFER => true,
        ));
        if ($tmp_pfx) {
            curl_setopt($ch, CURLOPT_SSLCERT, $tmp_pfx);
            curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
            $debug_log[] = "[SICOOB] Enviando certificado PFX: {$tmp_pfx}";
            if ($pfx_pass) {
                curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $pfx_pass);
                $debug_log[] = '[SICOOB] Password do PFX informado.';
            } else {
                $debug_log[] = '[SICOOB][ERRO] Password do PFX não foi informado ou não conseguido descriptografar.';
            }
        } else {
            $debug_log[] = '[SICOOB][ERRO] Não foi enviado certificado SSL (PFX não disponível)';
        }

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($err) {
            $debug_log[] = "[SICOOB][cURL ERRO] {$err}";
        } else {
            $debug_log[] = "[SICOOB] cURL executado. Código HTTP: {$code}";
        }
        $debug_log[] = "[SICOOB] Resposta bruta do token endpoint: {$resp}";
        curl_close($ch);
        if ($tmp_pfx) { 
            @unlink($tmp_pfx);
            $debug_log[] = "[SICOOB] Arquivo temporário PFX removido: {$tmp_pfx}";
        }

        if ($err) {
            if (defined('WP_DEBUG') && WP_DEBUG) error_log(implode("\n", $debug_log));
            throw new Exception('Erro ao obter token (cURL): ' . $err);
        }

        $data = json_decode($resp, true);
        $debug_log[] = "[SICOOB] Response decodificado: " . var_export($data, true);

        if ($code >= 400 || !$data || empty($data['access_token'])) {
            $debug_log[] = "[SICOOB][ERRO] Falha ao obter token. HTTP {$code} Resposta: {$resp}";
            if (defined('WP_DEBUG') && WP_DEBUG) error_log(implode("\n", $debug_log));
            throw new Exception('Falha ao obter token. HTTP ' . $code . ' Resposta: ' . $resp);
        }

        $this->access_token = $data['access_token'];
        $ttl = max(60, intval($data['expires_in'] ?? 3000) - 60);
        set_transient($cache_key, array('access_token' => $this->access_token), $ttl);
        $debug_log[] = "[SICOOB] Novo token armazenado em cache (key: {$cache_key}), TTL: {$ttl}s";

        if (defined('WP_DEBUG') && WP_DEBUG) error_log(implode("\n", $debug_log));

        return $this->access_token;
    }

    /**
     * Fazer requisição para a API
     * @param bool $use_pagamentos_base Se true, usa base_url_pagamentos (para boletos)
     */
    private function make_request($endpoint, $method = 'GET', $data = null, $expect_json = true, $extra_headers = array(), $use_auth = true, $use_pagamentos_base = false, $header_mode = 'default')
    {
        $base = $use_pagamentos_base ? $this->base_url_pagamentos : $this->base_url;
        $url = $base . $endpoint;

        // Montagem de headers (modo padrão ou mínimo)
        if ($header_mode === 'minimal') {
            // Somente o essencial pedido: Accept, Content-Type, client_id e Authorization
            $headers = array(
                'Content-Type: application/json',
                'Accept: application/json',
                'client_id: ' . $this->client_id,
            );
            if ($use_auth) {
                $headers[] = 'Authorization: Bearer ' . $this->get_access_token();
            }
        } else {
            $headers = array(
                'Content-Type: application/json',
                'Accept: application/json',
                // Enviar identificadores em todas as chamadas conforme requisito
                'client_id: ' . $this->client_id
            );
            
            // Authorization header (opcional, conforme documentação)
            if ($use_auth) {
                $headers[] = 'Authorization: Bearer ' . $this->get_access_token();
            }
            if (!empty($this->client_secret)) {
                $headers[] = 'client_secret: ' . $this->client_secret;
            }
            // Chave de app (se o WAF exigir), configure SICOOB_APP_KEY no wp-config.php
            $appKey = $this->app_key ?: (defined('SICOOB_APP_KEY') ? SICOOB_APP_KEY : '');
            if ($appKey) {
                $headers[] = 'X-Developer-Application-Key: ' . $appKey;
            }
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
     * PIX - Criar cobrança imediata (POST /pix/api/v2/cob?txid={txid})
     */
    public function pix_criar_cobranca_imediata($txid, $valor_original, $nome_devedor, $documento_devedor, $chave_pix, $expiracao = 3600)
    {
        // Usar o formato com query string conforme solicitado: /cob?txid=...
        $endpoint = '/pix/api/v2/cob?txid=' . rawurlencode($txid);

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

        // Headers mínimos (sem Accept-Language, client_secret, app key)
        $extra_headers = array();
        return $this->make_request($endpoint, 'POST', $data, true, $extra_headers, true, false, 'minimal');
    }

    /**
     * PIX - Consultar cobrança (POST /pix/api/v2/cob?txid={txid})
     */
    public function pix_obter_cobranca($txid)
    {
        // Consultar no formato com query string
        $endpoint = '/pix/api/v2/cob?txid=' . rawurlencode($txid);
        return $this->make_request($endpoint, 'POST', null, true, array(), true, false, 'minimal');
    }

    /**
     * PIX - Obter imagem (PNG) do QR Code (GET /pix/api/v2/cob/{txid}/imagem)
     * Retorna data URI pronta para uso em <img src="..." />
     */
    public function pix_obter_qr_code_imagem($txid, $largura = 360)
    {
        $endpoint = '/pix/api/v2/cob/' . rawurlencode($txid) . '/imagem?largura=' . intval($largura);
        $png_binary = $this->make_request($endpoint, 'GET', null, false, array('Accept: image/png', 'Accept-Language: pt-BR'), true);
        $data_uri = 'data:image/png;base64,' . base64_encode($png_binary);
        return $data_uri;
    }

    /**
     * Criar pagamento
     */
    public function create_payment($payment_data)
    {
        // Usar endpoint correto conforme documentação oficial
        $endpoint = '/v3/payments';

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
        $endpoint = '/v3/payments/' . $transaction_id;

        return $this->make_request($endpoint, 'GET');
    }

    /**
     * Cancelar pagamento
     */
    public function cancel_payment($transaction_id)
    {
        $endpoint = '/v3/payments/' . $transaction_id . '/cancel';

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

    /**
     * BOLETO - Consultar boleto (GET /boletos/{codigoBarras})
     * 
     * @param string $codigo_barras Código de barras do boleto (44 posições)
     * @param array $query_params Parâmetros opcionais (numeroConta, dataPagamento)
     * @return array Dados do boleto
     */
    public function boleto_consultar($codigo_barras, $query_params = array())
    {
        // Limpar código de barras (remover caracteres não numéricos)
        $codigo_barras = preg_replace('/\D+/', '', $codigo_barras);
        
        if (strlen($codigo_barras) !== 44) {
            throw new Exception('Código de barras deve ter exatamente 44 dígitos.');
        }

        $endpoint = '/boletos/' . rawurlencode($codigo_barras);
        
        // Adicionar query params se fornecidos
        if (!empty($query_params)) {
            $query_string = http_build_query($query_params);
            $endpoint .= '?' . $query_string;
        }

        return $this->make_request($endpoint, 'GET', null, true, array('Accept-Language: pt-BR'), true, true);
    }

    /**
     * BOLETO - Pagar boleto (POST /boletos/pagamentos/{codigoBarras})
     * 
     * @param string $codigo_barras Código de barras do boleto (44 posições)
     * @param array $boleto_data Dados do pagamento conforme documentação
     * @param string $idempotency_key Chave de idempotência (formato: cooperativa-conta-UUID)
     * @return array Resultado do pagamento
     */
    public function boleto_pagar($codigo_barras, $boleto_data, $idempotency_key = null)
    {
        // Limpar código de barras
        $codigo_barras = preg_replace('/\D+/', '', $codigo_barras);
        
        if (strlen($codigo_barras) !== 44) {
            throw new Exception('Código de barras deve ter exatamente 44 dígitos.');
        }

        $endpoint = '/boletos/pagamentos/' . rawurlencode($codigo_barras);

        // Gerar idempotency key se não fornecida
        if (!$idempotency_key) {
            // Formato: cooperativa-conta-UUID (ex: 4342-8901234-550e8400-e29b-41d4-a716-446655440000)
            // Por enquanto, usar UUID simples. Em produção, usar dados da conta.
            $uuid = wp_generate_uuid4();
            $idempotency_key = $uuid;
        }

        $extra_headers = array(
            'X-Idempotency-Key: ' . $idempotency_key,
            'Accept-Language: pt-BR'
        );

        return $this->make_request($endpoint, 'POST', $boleto_data, true, $extra_headers, true, true);
    }

    /**
     * BOLETO - Consultar comprovante de pagamento (GET /boletos/pagamentos/{idPagamento}/comprovantes)
     * 
     * @param string $id_pagamento ID do pagamento
     * @return array Dados do comprovante
     */
    public function boleto_consultar_comprovante($id_pagamento)
    {
        $endpoint = '/boletos/pagamentos/' . rawurlencode($id_pagamento) . '/comprovantes';
        return $this->make_request($endpoint, 'GET', null, true, array('Accept-Language: pt-BR'), true, true);
    }

    /**
     * BOLETO - Cancelar agendamento de pagamento (DELETE /boletos/pagamentos/agendamentos/{idPagamento})
     * 
     * @param string $id_pagamento ID do pagamento agendado
     * @return array Resultado do cancelamento
     */
    public function boleto_cancelar_agendamento($id_pagamento)
    {
        $endpoint = '/boletos/pagamentos/agendamentos/' . rawurlencode($id_pagamento);
        return $this->make_request($endpoint, 'DELETE', null, true, array('Accept-Language: pt-BR'), true, true);
    }
}
