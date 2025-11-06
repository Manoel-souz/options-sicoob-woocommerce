<?php

/**
 * Classe para comunicação com a API do Sicoob
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper: Criar arquivo temporário (wrapper seguro para wp_tempnam)
 * Usa wp_tempnam() se disponível, senão usa função alternativa
 */
function sicoob_wp_tempnam($filename = '', $dir = '') {
    // Se wp_tempnam já existe, usar ela
    if (function_exists('wp_tempnam')) {
        return wp_tempnam($filename, $dir);
    }
    
    // Fallback: criar função alternativa
    if (empty($dir)) {
        // Tentar usar get_temp_dir() do WordPress se disponível
        if (function_exists('get_temp_dir')) {
            $dir = get_temp_dir();
        } else {
            // Fallback: usar sys_get_temp_dir() do PHP
            $dir = sys_get_temp_dir();
            if (!empty($dir) && substr($dir, -1) !== DIRECTORY_SEPARATOR) {
                $dir .= DIRECTORY_SEPARATOR;
            }
        }
    }
    
    // Gerar nome único
    if ($filename) {
        // Limpar nome do arquivo manualmente se sanitize_file_name não estiver disponível
        if (function_exists('sanitize_file_name')) {
            $prefix = sanitize_file_name($filename) . '_';
        } else {
            // Limpeza manual básica
            $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $filename) . '_';
        }
    } else {
        $prefix = 'tmp_';
    }
    $unique = uniqid($prefix, true);
    $file = $dir . $unique . '.tmp';
    
    // Criar arquivo vazio
    if (@touch($file)) {
        return $file;
    }
    
    // Fallback: usar tempnam() nativo do PHP
    $temp = @tempnam($dir, $prefix);
    if ($temp !== false) {
        return $temp;
    }
    
    return false;
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
    private $pix_base; // Base path para endpoints PIX

    /**
     * Logar em wp-content/debug.log com marcação SICOOB
     */
    private function sicoob_debug_log($label, $data = null)
    {
        $path = trailingslashit(WP_CONTENT_DIR) . 'debug.log';
        $line = '[' . date('Y-m-d H:i:s') . "] [SICOOB][" . $label . "] ";
        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                $line .= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            } else {
                $line .= (string) $data;
            }
        }
        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND);
    }

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
            $this->pix_base = '/pix/api/v2';
        } else {
            $this->base_url = 'https://api.sicoob.com.br';
            $this->base_url_pagamentos = 'https://api.sicoob.com.br/pagamentos/v3';
            $this->pix_base = '/pix/api/v2';
        }

        // Para sandbox, não usamos OAuth; utilizamos Bearer Token direto

        // Em produção, PIX exige mTLS. Anexa certificados via cURL quando configurados.
        if ($this->environment === 'production') {
            // Hook removido - sempre usando Guzzle agora
            // add_action('http_api_curl', array($this, 'inject_mtls'), 10, 3);
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
        // Prioridade: 1) Senha do código (SICOOB_PFX_PASSWORD), 2) Senha passada via extras, 3) Senha do banco, 4) Constante SICOOB_MTLS_KEYPASS
        $pass = '';
        if (defined('SICOOB_PFX_PASSWORD')) {
            $pfx_pass_const = constant('SICOOB_PFX_PASSWORD');
            if (!empty($pfx_pass_const)) {
                $pass = $pfx_pass_const;
            }
        }
        if (!$pass && $this->pfx_password) {
            $pass = $this->pfx_password;
        }
        if (!$pass && get_option('sicoob_pfx_password_enc')) {
            $pass = $this->decrypt_secret_compat(get_option('sicoob_pfx_password_enc'));
        }
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
            // Prioridade 1: Envios.pfx (arquivo principal para requisições)
            $fixed = SICOOB_WC_PLUGIN_PATH . 'Envios.pfx';
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][DEBUG] Verificando arquivo PFX: ' . $fixed);
                error_log('[SICOOB][DEBUG] Arquivo existe: ' . (file_exists($fixed) ? 'SIM' : 'NÃO'));
                if (file_exists($fixed)) {
                    error_log('[SICOOB][DEBUG] Tamanho do arquivo: ' . filesize($fixed) . ' bytes');
                    error_log('[SICOOB][DEBUG] Permissões do arquivo: ' . substr(sprintf('%o', fileperms($fixed)), -4));
                    error_log('[SICOOB][DEBUG] Arquivo é legível: ' . (is_readable($fixed) ? 'SIM' : 'NÃO'));
                }
            }
            
            if (file_exists($fixed) && is_readable($fixed)) {
                $raw = file_get_contents($fixed);
                if ($raw === false) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[SICOOB][ERRO] Falha ao ler conteúdo do arquivo PFX: ' . $fixed);
                    }
                    $raw = '';
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[SICOOB][DEBUG] Arquivo PFX lido com sucesso. Tamanho: ' . strlen($raw) . ' bytes');
                        error_log('[SICOOB][DEBUG] Senha do PFX: ' . (!empty($pass) ? 'CONFIGURADA (' . strlen($pass) . ' caracteres)' : 'NÃO CONFIGURADA'));
                        
                        // Tentar validar o certificado se OpenSSL estiver disponível
                        if (function_exists('openssl_pkcs12_read') && !empty($pass)) {
                            $certs = array();
                            $valid = @openssl_pkcs12_read($raw, $certs, $pass);
                            if ($valid) {
                                error_log('[SICOOB][DEBUG] Certificado PFX validado com sucesso via OpenSSL');
                                if (isset($certs['cert'])) {
                                    $cert_info = openssl_x509_parse($certs['cert']);
                                    if ($cert_info) {
                                        error_log('[SICOOB][DEBUG] Certificado CN: ' . (isset($cert_info['subject']['CN']) ? $cert_info['subject']['CN'] : 'N/A'));
                                        error_log('[SICOOB][DEBUG] Certificado válido até: ' . (isset($cert_info['validTo_time_t']) ? date('Y-m-d H:i:s', $cert_info['validTo_time_t']) : 'N/A'));
                                        if (isset($cert_info['validTo_time_t']) && $cert_info['validTo_time_t'] < time()) {
                                            error_log('[SICOOB][AVISO] Certificado PFX EXPIRADO!');
                                        }
                                    }
                                }
                            } else {
                                $openssl_error = openssl_error_string();
                                error_log('[SICOOB][ERRO] Falha ao validar certificado PFX via OpenSSL: ' . ($openssl_error ?: 'Senha incorreta ou arquivo corrompido'));
                            }
                        } else {
                            if (empty($pass)) {
                                error_log('[SICOOB][AVISO] Senha do PFX não configurada - validação via OpenSSL não será possível');
                            }
                            if (!function_exists('openssl_pkcs12_read')) {
                                error_log('[SICOOB][AVISO] OpenSSL não disponível - validação do certificado não será possível');
                            }
                        }
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if (!file_exists($fixed)) {
                        error_log('[SICOOB][ERRO] Arquivo PFX não encontrado: ' . $fixed);
                    } elseif (!is_readable($fixed)) {
                        error_log('[SICOOB][ERRO] Arquivo PFX existe mas não é legível: ' . $fixed);
                    }
                }
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
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SICOOB][mTLS] inject_mtls chamado para URL: ' . $url);
        }
        
        // 1) PRIORIDADE: Verificar se existem arquivos PEM no diretório do plugin
        $pem_cert = '';
        $pem_key = '';
        if (defined('SICOOB_WC_PLUGIN_PATH')) {
            // Verificar certificado.pem (completo) ou certificado_publico.pem
            $cert_completo = SICOOB_WC_PLUGIN_PATH . 'certificado.pem';
            $cert_publico = SICOOB_WC_PLUGIN_PATH . 'certificado_publico.pem';
            $key_privada = SICOOB_WC_PLUGIN_PATH . 'chave_privada.pem';
            
            if (file_exists($cert_completo)) {
                // Certificado completo (cert + key juntos)
                $pem_cert = $cert_completo;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][mTLS] Usando certificado.pem (completo): ' . $cert_completo);
                }
            } elseif (file_exists($cert_publico) && file_exists($key_privada)) {
                // Certificado e chave separados
                $pem_cert = $cert_publico;
                $pem_key = $key_privada;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][mTLS] Usando certificado_publico.pem: ' . $cert_publico);
                    error_log('[SICOOB][mTLS] Usando chave_privada.pem: ' . $key_privada);
                }
            }
        }
        
        // Se encontrou PEM, usar diretamente
        if ($pem_cert && file_exists($pem_cert)) {
            if ($pem_key && file_exists($pem_key)) {
                // Certificado e chave separados
                @curl_setopt($handle, CURLOPT_SSLCERT, $pem_cert);
                @curl_setopt($handle, CURLOPT_SSLKEY, $pem_key);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][mTLS] Certificado PEM aplicado (cert + key separados)');
                    error_log('[SICOOB][mTLS] CERT: ' . $pem_cert);
                    error_log('[SICOOB][mTLS] KEY: ' . $pem_key);
                }
            } else {
                // Certificado completo (cert + key juntos)
                @curl_setopt($handle, CURLOPT_SSLCERT, $pem_cert);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][mTLS] Certificado PEM completo aplicado: ' . $pem_cert);
                }
            }
            
            // Senha se necessário
            $pwd = $this->mtls_key_pass ?: (defined('SICOOB_MTLS_KEYPASS') ? SICOOB_MTLS_KEYPASS : '');
            if ($pwd) {
                @curl_setopt($handle, CURLOPT_KEYPASSWD, $pwd);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][mTLS] Senha do certificado PEM configurada');
                }
            }
            
            return; // Já configurado via PEM
        }
        
        // 2) Fallback: Preferir PFX (blob/URL/arquivo raiz do plugin)
        list($raw, $pass) = $this->resolve_pfx_raw_and_pass();
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SICOOB][mTLS] PFX raw obtido: ' . (!empty($raw) ? 'SIM (' . strlen($raw) . ' bytes)' : 'NÃO'));
            error_log('[SICOOB][mTLS] Senha obtida: ' . (!empty($pass) ? 'SIM (' . strlen($pass) . ' caracteres)' : 'NÃO'));
        }
        
        if ($raw && !$this->tmp_pfx_path) {
            $this->tmp_pfx_path = wp_tempnam('sicoob_pfx_runtime');
            if ($this->tmp_pfx_path) {
                $written = file_put_contents($this->tmp_pfx_path, $raw);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][mTLS] Arquivo temporário PFX criado: ' . $this->tmp_pfx_path);
                    error_log('[SICOOB][mTLS] Bytes escritos no arquivo temporário: ' . $written);
                }
                add_action('shutdown', function(){
                    if (file_exists($this->tmp_pfx_path)) { @unlink($this->tmp_pfx_path); }
                });
                $this->mtls_key_pass = $this->mtls_key_pass ?: $pass;
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][mTLS][ERRO] Falha ao criar arquivo temporário para PFX');
                }
            }
        }
        
        if ($this->tmp_pfx_path && file_exists($this->tmp_pfx_path)) {
            $result1 = @curl_setopt($handle, CURLOPT_SSLCERT, $this->tmp_pfx_path);
            $result2 = @curl_setopt($handle, CURLOPT_SSLCERTTYPE, 'P12');
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][mTLS] CURLOPT_SSLCERT definido: ' . ($result1 ? 'SIM' : 'NÃO') . ' - Arquivo: ' . $this->tmp_pfx_path);
                error_log('[SICOOB][mTLS] CURLOPT_SSLCERTTYPE definido: ' . ($result2 ? 'SIM' : 'NÃO') . ' - Tipo: P12');
            }
            
            if ($this->mtls_key_pass) {
                $result3 = @curl_setopt($handle, CURLOPT_SSLCERTPASSWD, $this->mtls_key_pass);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][mTLS] CURLOPT_SSLCERTPASSWD definido: ' . ($result3 ? 'SIM' : 'NÃO') . ' - Senha: ' . strlen($this->mtls_key_pass) . ' caracteres');
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][mTLS][AVISO] Senha do certificado não configurada - CURLOPT_SSLCERTPASSWD não será definido');
                }
            }
            
            // Verificar se o certificado foi configurado corretamente
            $cert_value = @curl_getinfo($handle, CURLINFO_CERTINFO);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][mTLS] Certificado PFX aplicado ao request: ' . $this->tmp_pfx_path);
                error_log('[SICOOB][mTLS] Tamanho do arquivo temporário: ' . filesize($this->tmp_pfx_path) . ' bytes');
            }
            
            return; // Já configurado via PFX
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][mTLS][ERRO] Arquivo temporário PFX não disponível ou não existe');
                if ($this->tmp_pfx_path) {
                    error_log('[SICOOB][mTLS][ERRO] Caminho do arquivo temporário: ' . $this->tmp_pfx_path);
                    error_log('[SICOOB][mTLS][ERRO] Arquivo existe: ' . (file_exists($this->tmp_pfx_path) ? 'SIM' : 'NÃO'));
                }
            }
        }

        // 3) Fallback final: PEM + KEY se informados nas opções/constantes
        $cert = $this->mtls_cert_path ?: (defined('SICOOB_MTLS_CERT') ? SICOOB_MTLS_CERT : '');
        $key  = $this->mtls_key_path ?: (defined('SICOOB_MTLS_KEY') ? SICOOB_MTLS_KEY : '');
        $pwd  = $this->mtls_key_pass ?: (defined('SICOOB_MTLS_KEYPASS') ? SICOOB_MTLS_KEYPASS : '');
        if ($cert && file_exists($cert)) {
            @curl_setopt($handle, CURLOPT_SSLCERT, $cert);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][mTLS] Certificado via constante SICOOB_MTLS_CERT: ' . $cert);
            }
        }
        if ($key && file_exists($key)) {
            @curl_setopt($handle, CURLOPT_SSLKEY, $key);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][mTLS] Chave via constante SICOOB_MTLS_KEY: ' . $key);
            }
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

        // Prepara arquivo PFX temporário a partir do arquivo fixo na raiz do plugin (prioridade: Envios.pfx)
        list($raw_from_root, $pass_from_root) = $this->resolve_pfx_raw_and_pass();
        $pfx_pass = $pass_from_root;
        $tmp_pfx = '';
        if ($raw_from_root) {
            // Usar wrapper compatível que cai para tempnam se wp_tempnam não existir
            $tmp_pfx = sicoob_wp_tempnam('sicoob_pfx_root');
            if ($tmp_pfx) {
                file_put_contents($tmp_pfx, $raw_from_root);
                $debug_log[] = "[SICOOB] PFX da raiz do plugin carregado em: {$tmp_pfx}";
            } else {
                $debug_log[] = '[SICOOB][ERRO] Falha ao criar arquivo temporário para PFX da raiz!';
            }
        } else {
            if (defined('SICOOB_WC_PLUGIN_PATH')) {
                $envios_pfx = SICOOB_WC_PLUGIN_PATH . 'Envios.pfx';
                $debug_log[] = '[SICOOB][ERRO] PFX não encontrado na raiz do plugin. Verificando: ' . $envios_pfx;
                if (file_exists($envios_pfx)) {
                    $debug_log[] = '[SICOOB][INFO] Arquivo Envios.pfx existe, mas não foi possível ler.';
                } else {
                    $debug_log[] = '[SICOOB][ERRO] Arquivo Envios.pfx não encontrado em: ' . SICOOB_WC_PLUGIN_PATH;
                }
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
                $debug_log[] = '[SICOOB] Password do PFX informado (tamanho: ' . strlen($pfx_pass) . ' caracteres).';
                
                // Tentar validar o certificado antes de usar (se OpenSSL estiver disponível)
                if (function_exists('openssl_pkcs12_read')) {
                    $pfx_content = file_get_contents($tmp_pfx);
                    $certs = array();
                    $valid = @openssl_pkcs12_read($pfx_content, $certs, $pfx_pass);
                    if (!$valid) {
                        $debug_log[] = '[SICOOB][AVISO] Falha ao validar PFX com OpenSSL antes do envio. Continuando mesmo assim...';
                    } else {
                        $debug_log[] = '[SICOOB] PFX validado com sucesso usando OpenSSL.';
                    }
                }
            } else {
                $debug_log[] = '[SICOOB][ERRO] Password do PFX não foi informado ou não conseguido descriptografar.';
                if (defined('WP_DEBUG') && WP_DEBUG) error_log(implode("\n", $debug_log));
                throw new Exception('Senha do certificado PFX não configurada. Configure a senha do certificado nas opções do plugin.');
            }
        } else {
            $debug_log[] = '[SICOOB][ERRO] Não foi enviado certificado SSL (PFX não disponível)';
            if (defined('WP_DEBUG') && WP_DEBUG) error_log(implode("\n", $debug_log));
            throw new Exception('Certificado PFX não encontrado. Verifique se o arquivo Envios.pfx está no diretório do plugin ou configurado corretamente.');
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
            // Detectar erro específico de PKCS12
            if (stripos($err, 'PKCS12') !== false || stripos($err, 'mac verify failure') !== false) {
                $debug_log[] = '[SICOOB][ERRO PKCS12] Erro ao validar senha do certificado PFX. Verifique a senha configurada.';
                if (defined('WP_DEBUG') && WP_DEBUG) error_log(implode("\n", $debug_log));
                throw new Exception('Erro ao validar certificado PFX: A senha do certificado está incorreta ou o arquivo está corrompido. Verifique a senha configurada nas opções do plugin.');
            }
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
     * Fazer requisição usando Guzzle com mTLS (se disponível)
     */
    private function make_request_guzzle($url, $method, $data, $expect_json, $headers)
    {
        if (!class_exists('GuzzleHttp\\Client')) {
            throw new Exception('Guzzle não está disponível');
        }

        // PRIORIDADE 1: Verificar se existem arquivos PEM
        $pem_cert = '';
        $pem_key = '';
        $cert_config = null;
        
        if (defined('SICOOB_WC_PLUGIN_PATH')) {
            $cert_completo = SICOOB_WC_PLUGIN_PATH . 'certificado.pem';
            $cert_publico = SICOOB_WC_PLUGIN_PATH . 'certificado_publico.pem';
            $key_privada = SICOOB_WC_PLUGIN_PATH . 'chave_privada.pem';
            
            if (file_exists($cert_completo)) {
                // Certificado completo (cert + key juntos)
                $cert_config = $cert_completo;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][Guzzle] Usando certificado.pem (completo): ' . $cert_completo);
                }
            } elseif (file_exists($cert_publico) && file_exists($key_privada)) {
                // Certificado e chave separados - Guzzle precisa do array
                $cert_config = [$cert_publico, $key_privada];
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][Guzzle] Usando certificado_publico.pem + chave_privada.pem');
                }
            }
        }
        
        // PRIORIDADE 2: Fallback para PFX
        if (!$cert_config) {
            list($pfx_raw, $pfx_pass) = $this->resolve_pfx_raw_and_pass();
            if (!$pfx_raw) {
                throw new Exception('Certificado PFX não encontrado. Coloque o Envios.pfx na raiz do plugin.');
            }

            $tmp_pfx = wp_tempnam('sicoob_pfx_guzzle');
            if (!$tmp_pfx) {
                throw new Exception('Falha ao criar arquivo temporário do certificado.');
            }
            file_put_contents($tmp_pfx, $pfx_raw);
            // Remover no shutdown
            add_action('shutdown', function() use ($tmp_pfx) {
                if (file_exists($tmp_pfx)) { @unlink($tmp_pfx); }
            });

            $cert_config = [$tmp_pfx, (string) $pfx_pass]; // P12 com senha
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][Guzzle] Preparando mTLS (PFX) e enviando via Guzzle: ' . $url);
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][Guzzle] Preparando mTLS (PEM) e enviando via Guzzle: ' . $url);
            }
        }

        $client = new \GuzzleHttp\Client([
            'timeout' => 30,
            'verify' => true,
            'http_errors' => false,
            'cert' => $cert_config,
        ]);

        $options = [ 'headers' => [] ];
        // Converter headers estilo array("Key: value") para assoc array
        foreach ($headers as $h) {
            $parts = explode(':', $h, 2);
            if (count($parts) === 2) {
                $options['headers'][trim($parts[0])] = trim($parts[1]);
            }
        }

        $method = strtoupper($method);
        if ($data && in_array($method, array('POST','PUT','PATCH'), true)) {
            // Se Content-Type application/json, usar json; caso contrário, body cru
            $ct = isset($options['headers']['Content-Type']) ? strtolower($options['headers']['Content-Type']) : '';
            if (strpos($ct, 'application/json') !== false) {
                $options['json'] = $data;
            } else {
                $options['body'] = is_string($data) ? $data : json_encode($data);
            }
        }

        $response = $client->request($method, $url, $options);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        // Logar resposta sempre em debug.log (status, headers e body)
        $this->sicoob_debug_log('HTTP RESPONSE', array(
            'url' => $url,
            'status' => $status,
            'headers' => $response->getHeaders(),
            'body' => $expect_json ? (json_decode($body, true) ?? substr($body, 0, 1000)) : substr($body, 0, 1000),
        ));

        if ($status >= 400) {
            // Tentar parsear o JSON para extrair title e detail
            $error_data = json_decode($body, true);
            if ($error_data && isset($error_data['title']) && isset($error_data['detail'])) {
                throw new Exception($error_data['title'] . '|' . $error_data['detail']);
            }
            // Fallback: se não conseguir parsear, usar o corpo completo
            throw new Exception('Erro na API do Sicoob (HTTP ' . $status . '): ' . $body);
        }

        // WAF pode retornar HTML com 200
        if ($status === 200 && is_string($body) && stripos($body, 'Request Rejected') !== false) {
            throw new Exception('Requisição bloqueada pelo WAF do Sicoob. Verifique headers e endpoint.');
        }

        return $expect_json ? json_decode($body, true) : $body;
    }

    /**
     * Fazer requisição para a API
     * @param bool $use_pagamentos_base Se true, usa base_url_pagamentos (para boletos)
     */
	private function make_request($endpoint, $method = 'GET', $data = null, $expect_json = true, $extra_headers = array(), $use_auth = true, $use_pagamentos_base = false, $header_mode = 'default')
    {
        $base = $use_pagamentos_base ? $this->base_url_pagamentos : $this->base_url;
        $url = $base . $endpoint;

        // Preparar corpo (quando aplicável)
        $body_string = '';
        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $body_string = json_encode($data);
        }

        // Montagem de headers (modo padrão ou mínimo)
        if ($header_mode === 'minimal') {
            // Essenciais, conforme a chamada que funcionou externamente (Accept, Content-Type, client_id, Authorization)
            $headers = array(
                'Accept: application/json',
                'client_id: ' . $this->client_id,
                'Authorization: Bearer ' . ($use_auth ? $this->get_access_token() : ''),
                'Content-Type: application/json',
            );
            // Remover Authorization vazio caso $use_auth seja false
            if (!$use_auth) {
                $headers = array_values(array_filter($headers, function ($h) { return strpos($h, 'Authorization:') !== 0; }));
            }
            // App Key opcional (se o WAF exigir)
            $appKey = $this->app_key ?: (defined('SICOOB_APP_KEY') ? SICOOB_APP_KEY : '');
            if ($appKey) {
                $headers[] = 'X-Developer-Application-Key: ' . $appKey;
            }
        } else {
            // Padrão: mesmo conjunto de headers essenciais
            $headers = array(
                'Accept: application/json',
                'client_id: ' . $this->client_id,
                'Authorization: Bearer ' . ($use_auth ? $this->get_access_token() : ''),
                'Content-Type: application/json',
            );
            if (!$use_auth) {
                $headers = array_values(array_filter($headers, function ($h) { return strpos($h, 'Authorization:') !== 0; }));
            }
            // Em produção não se usa client_secret; mantemos apenas se explicitamente definido
            if (!empty($this->client_secret)) {
                $headers[] = 'client_secret: ' . $this->client_secret;
            }
            $appKey = $this->app_key ?: (defined('SICOOB_APP_KEY') ? SICOOB_APP_KEY : '');
            if ($appKey) {
                $headers[] = 'X-Developer-Application-Key: ' . $appKey;
            }
        }
        if (!empty($extra_headers)) {
            $headers = array_merge($headers, $extra_headers);
        }

        // Debug de pré-envio (sempre em debug.log)
        $calculated_host = parse_url($url, PHP_URL_HOST);
        $calculated_len  = ($data && in_array($method, array('POST','PUT','PATCH'))) ? strlen($body_string) : 0; // bytes
        $this->sicoob_debug_log('HTTP REQUEST', array(
            'url' => $url,
            'method' => $method,
            'headers' => $headers,
            'calculated' => array('Host' => $calculated_host, 'Content-Length' => $calculated_len . ' bytes'),
            'body' => !empty($body_string) ? json_decode($body_string, true) ?? $body_string : null,
        ));

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
            'httpversion' => '1.1',
            'user-agent' => 'options-sicoob/1.0; WordPress/' . get_bloginfo('version')
        );

        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = $body_string;
        }

		// SEMPRE usar Guzzle - não mais fallback para wp_remote_request
		if (!class_exists('GuzzleHttp\\Client')) {
			throw new Exception('Guzzle não está disponível. Instale o Guzzle via Composer: composer require guzzlehttp/guzzle');
		}

        $this->sicoob_debug_log('HTTP SEND', array('url' => $url));

        $result = $this->make_request_guzzle($url, $method, ($args['body'] ?? $data), $expect_json, $headers);
        // Resultado bruto já foi tratado por make_request_guzzle; só registrar que concluiu
        $this->sicoob_debug_log('HTTP DONE', array('url' => $url));
        return $result;
    }

    /**
     * PIX - Criar cobrança imediata (POST {{base_url}}/{{pix_base}}/cob?txid={txid})
     */
    public function pix_criar_cobranca_imediata($txid, $valor_original, $nome_devedor, $documento_devedor, $chave_pix, $expiracao = 3600)
    {
        // Usar o formato com query string conforme solicitado: {{pix_base}}/cob?txid=...
        $endpoint = $this->pix_base . '/cob?txid=' . rawurlencode($txid);

        $devedor = array('nome' => $nome_devedor);
        $documento_devedor = preg_replace('/\D+/', '', (string) $documento_devedor);
        
        // Detectar se é CPF (11 dígitos) ou CNPJ (14 dígitos)
        if (strlen($documento_devedor) === 11) {
            // É CPF
            $devedor['cpf'] = $documento_devedor;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][PIX] Documento detectado como CPF: ' . $documento_devedor);
            }
        } elseif (strlen($documento_devedor) === 14) {
            // É CNPJ
            $devedor['cnpj'] = $documento_devedor;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][PIX] Documento detectado como CNPJ: ' . $documento_devedor);
            }
        } else {
            // Se não tiver 11 ou 14 dígitos, tentar como CNPJ (comportamento padrão)
            $devedor['cnpj'] = $documento_devedor;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][PIX] Documento com tamanho inválido (' . strlen($documento_devedor) . ' dígitos), enviando como CNPJ: ' . $documento_devedor);
            }
        }

        $data = array(
            'calendario' => array(
                'expiracao' => (int) $expiracao
            ),
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
        $response = $this->make_request($endpoint, 'POST', $data, true, $extra_headers, true, false, 'minimal');
        
        // Log da resposta completa para debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SICOOB][PIX] Resposta da criação de cobrança: ' . json_encode($response, JSON_PRETTY_PRINT));
        }
        
        // Validar campos obrigatórios na resposta
        if (empty($response)) {
            throw new Exception('Resposta vazia da API ao criar cobrança PIX.');
        }
        
        // Garantir que os campos essenciais estão presentes
        if (!isset($response['status'])) {
            throw new Exception('Resposta inválida da API: campo "status" não encontrado.');
        }
        
        if (!isset($response['txid'])) {
            throw new Exception('Resposta inválida da API: campo "txid" não encontrado.');
        }
        
        return $response;
    }

    /**
     * PIX - Consultar cobrança por TXID
     * Documentação: GET {{base_url}}/{{pix_base}}/cob/{txid}
     */
    public function pix_obter_cobranca($txid, $revisao = null)
    {
        // Endpoint correto para consulta por TXID (sem corpo)
        // Não utilizar parâmetro de revisão
        $endpoint = $this->pix_base . '/cob/' . rawurlencode($txid);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SICOOB][PIX][DEBUG] Consultando cobrança por TXID: ' . $txid);
            error_log('[SICOOB][PIX][DEBUG] Endpoint: ' . $endpoint);
            error_log('[SICOOB][PIX][DEBUG] URL completa: ' . $this->base_url . $endpoint);
        }
        
        $response = $this->make_request($endpoint, 'GET', null, true, array(), true, false, 'minimal');
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SICOOB][PIX][DEBUG] Resposta da consulta de cobrança:');
            error_log('[SICOOB][PIX][DEBUG] Status: ' . (isset($response['status']) ? $response['status'] : 'NÃO ENCONTRADO'));
            error_log('[SICOOB][PIX][DEBUG] Resposta completa: ' . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if (isset($response['txid'])) {
                error_log('[SICOOB][PIX][DEBUG] TXID retornado: ' . $response['txid']);
            }
            if (isset($response['calendario'])) {
                error_log('[SICOOB][PIX][DEBUG] Calendário: ' . json_encode($response['calendario'], JSON_UNESCAPED_UNICODE));
            }
            if (isset($response['valor'])) {
                error_log('[SICOOB][PIX][DEBUG] Valor: ' . json_encode($response['valor'], JSON_UNESCAPED_UNICODE));
            }
        }
        
        return $response;
    }

    /**
     * PIX - Obter imagem (PNG) do QR Code (GET {{base_url}}/{{pix_base}}/cob/{{txid}}/imagem)
     * Retorna data URI pronta para uso em <img src="..." />
     */
    public function pix_obter_qr_code_imagem($txid, $largura = 360, $revisao = null)
    {
        // Construir endpoint conforme documentação: {{base_url}}/{{pix_base}}/cob/{{txid}}/imagem
        $endpoint = $this->pix_base . '/cob/' . rawurlencode($txid) . '/imagem';
        
        // Adicionar parâmetros de query
        $query_params = array('largura' => intval($largura));
        if ($revisao !== null) {
            $query_params['revisao'] = intval($revisao);
        }
        if (!empty($query_params)) {
            $endpoint .= '?' . http_build_query($query_params);
        }
        
        $png_binary = $this->make_request($endpoint, 'GET', null, false, array('Accept: image/png', 'Accept-Language: pt-BR'), true);
        $data_uri = 'data:image/png;base64,' . base64_encode($png_binary);
        return $data_uri;
    }

    /**
     * PIX - Listar créditos (pix recebidos) por intervalo de tempo e filtrar por TXID
     * Documentação: GET {{base_url}}/{{pix_base}}/pix?inicio=...&fim=...&txid=...
     */
    public function pix_listar_recebidos_por_txid($txid, $inicio_iso8601 = null, $fim_iso8601 = null)
    {
        // Janela padrão: últimas 6 horas até agora
        if (!$inicio_iso8601) {
            $inicio_iso8601 = gmdate('Y-m-d\TH:i:s\Z', time() - 6 * 3600);
        }
        if (!$fim_iso8601) {
            $fim_iso8601 = gmdate('Y-m-d\TH:i:s\Z');
        }

        $query = http_build_query(array(
            'inicio' => $inicio_iso8601,
            'fim'    => $fim_iso8601,
            'txid'   => $txid,
        ));

        $endpoint = $this->pix_base . '/pix?' . $query;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SICOOB][PIX][DEBUG] Listando PIX recebidos por TXID. TXID=' . $txid . ' inicio=' . $inicio_iso8601 . ' fim=' . $fim_iso8601);
            error_log('[SICOOB][PIX][DEBUG] Endpoint: ' . $endpoint);
        }

        $response = $this->make_request($endpoint, 'GET', null, true, array(), true, false, 'minimal');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SICOOB][PIX][DEBUG] Resposta PIX recebidos: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
        }

        return $response;
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
