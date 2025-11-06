<?php

/**
 * Gateway de Pagamento Sicoob
 */

if (!defined('ABSPATH')) {
    exit;
}

// Garante registro da rota REST mesmo se o gateway não for instanciado nesta requisição
add_action('rest_api_init', function(){
    if (class_exists('WC_Gateway_Sicoob') && method_exists('WC_Gateway_Sicoob', 'register_rest_routes_static')) {
        WC_Gateway_Sicoob::register_rest_routes_static();
    }
});

/**
 * Classe WC_Gateway_Sicoob
 */
class WC_Gateway_Sicoob extends WC_Payment_Gateway
{
    /**
     * Propriedades para compatibilidade com PHP >= 8.2 (evita dynamic properties)
     */
    public $testmode;
    public $client_id;
    public $client_secret;
    public $access_token;
    public $environment;
    // mTLS e App Key (produção)
    public $mtls_cert_path;
    public $mtls_key_path;
    public $mtls_key_pass;
    public $app_key;

    /**
     * Construtor
     */
    public function __construct()
    {
        $this->id = 'sicoob';
        $this->icon = SICOOB_WC_PLUGIN_URL . 'assets/images/sicoob-logo.png';
        $this->has_fields = true;
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
        $this->access_token = $this->get_option('access_token');
        $this->environment = $this->testmode ? 'sandbox' : 'production';
        // Extras produção
        $this->mtls_cert_path = $this->get_option('mtls_cert_path');
        $this->mtls_key_path  = $this->get_option('mtls_key_path');
        $this->mtls_key_pass  = $this->get_option('mtls_key_pass');
        $this->app_key        = $this->get_option('app_key');

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_sicoob_webhook', array($this, 'webhook_handler'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('wp_ajax_sicoob_pix_status', array($this, 'ajax_pix_status'));
        add_action('wp_ajax_nopriv_sicoob_pix_status', array($this, 'ajax_pix_status'));
        add_action('wp_ajax_sicoob_pix_confirmar', array($this, 'ajax_pix_confirmar'));
        add_action('wp_ajax_nopriv_sicoob_pix_confirmar', array($this, 'ajax_pix_confirmar'));

        // REST API para depuração e confirmação via servidor (proxy seguro para a API Sicoob)
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    /**
     * Campos exibidos no checkout
     */
    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }
        $enable_pix = 'yes' === $this->get_option('enable_pix', 'yes');
        $enable_boleto = 'yes' === $this->get_option('enable_boleto', 'yes');
        $selected_type = isset($_POST['sicoob_payment_type']) ? sanitize_text_field(wp_unslash($_POST['sicoob_payment_type'])) : 'pix';
        echo '<fieldset class="wc-sicoob-fields">';
        echo '<p><strong>' . esc_html__('Escolha como deseja pagar:', 'sicoob-woocommerce') . '</strong></p>';
        if ($enable_pix) {
            echo '<p class="form-row">';
            echo '<label><input type="radio" name="sicoob_payment_type" value="pix" ' . checked('pix', $selected_type, false) . '> ' . esc_html__('PIX', 'sicoob-woocommerce') . '</label>';
            echo '</p>';
        }
        if ($enable_boleto) {
            echo '<p class="form-row">';
            echo '<label><input type="radio" name="sicoob_payment_type" value="boleto" ' . checked('boleto', $selected_type, false) . '> ' . esc_html__('Boleto', 'sicoob-woocommerce') . '</label>';
            echo '</p>';
        }
        // PIX
        echo '<div class="sicoob-section sicoob-section-pix" style="margin-top:10px; ' . ($selected_type === 'pix' ? '' : 'display:none;') . '">';
        // Nome completo (necessário para a cobrança)
        $nome_padrao = '';
        if (isset($_POST['sicoob_nome'])) {
            $nome_padrao = esc_attr(wp_unslash($_POST['sicoob_nome']));
        } elseif (function_exists('WC') && WC()->customer) {
            $nome_padrao = esc_attr(trim(WC()->customer->get_billing_first_name() . ' ' . WC()->customer->get_billing_last_name()));
        }
        echo '<p class="form-row form-row-wide">';
        echo '<label for="sicoob_nome">' . esc_html__('Nome completo (obrigatório)', 'sicoob-woocommerce') . '</label>';
        echo '<input id="sicoob_nome" name="sicoob_nome" type="text" value="' . $nome_padrao . '" required />';
        echo '</p>';
        // Documento (CPF ou CNPJ)
        $doc_padrao = isset($_POST['sicoob_documento']) ? esc_attr(wp_unslash($_POST['sicoob_documento'])) : '';
        echo '<p class="form-row form-row-first">';
        echo '<label for="sicoob_documento">' . esc_html__('CPF ou CNPJ', 'sicoob-woocommerce') . '</label>';
        echo '<input id="sicoob_documento" name="sicoob_documento" type="text" inputmode="numeric" pattern="[0-9\.\-/\s]*" value="' . $doc_padrao . '" placeholder="CPF ou CNPJ" required />';
        echo '</p>';
        // Campos auxiliares opcionais
        // Removidos campos opcionais de chave/agenda — chave vem das configurações do gateway
        echo '</div>';
        // Boleto
        echo '<div class="sicoob-section sicoob-section-boleto" style="margin-top:10px; ' . ($selected_type === 'boleto' ? '' : 'display:none;') . '">';
        echo '<p class="form-row form-row-wide">';
        echo '<label for="sicoob_boleto_codigo_barras">' . esc_html__('Código de barras', 'sicoob-woocommerce') . ' <span class="optional">' . esc_html__('opcional', 'sicoob-woocommerce') . '</span></label>';
        echo '<input id="sicoob_boleto_codigo_barras" name="sicoob_boleto_codigo_barras" type="text" inputmode="numeric" pattern="[0-9\\s]*" value="' . (isset($_POST['sicoob_boleto_codigo_barras']) ? esc_attr(wp_unslash($_POST['sicoob_boleto_codigo_barras'])) : '') . '" />';
        echo '</p>';
        echo '</div>';
        // Script (toggle + máscara de CPF/CNPJ)
        echo '<script>
        (function(){
            // Função para aplicar máscara de CPF ou CNPJ automaticamente
            function aplicarMascaraDocumento(valor) {
                // Remove tudo que não é dígito
                var digitos = (valor || "").replace(/\D/g, "");
                
                // Limitar a 14 dígitos (máximo para CNPJ)
                digitos = digitos.slice(0, 14);
                
                // Se tiver 11 dígitos ou menos, aplicar máscara de CPF
                if (digitos.length <= 11) {
                    // Máscara de CPF: 000.000.000-00
                    digitos = digitos.slice(0, 11);
                    var formatado = digitos;
                    formatado = formatado.replace(/^(\d{3})(\d)/, "$1.$2");
                    formatado = formatado.replace(/^(\d{3}\.)(\d{3})(\d)/, "$1$2.$3");
                    formatado = formatado.replace(/(\d{3})(\d{1,2})$/, "$1-$2");
                    return formatado;
                }
                
                // Máscara de CNPJ: 00.000.000/0000-00
                var formatado = digitos;
                formatado = formatado.replace(/^(\d{2})(\d)/, "$1.$2");
                formatado = formatado.replace(/^(\d{2}\.)(\d{3})(\d)/, "$1$2.$3");
                formatado = formatado.replace(/^(\d{2}\.\d{3}\.)(\d{3})(\d)/, "$1$2/$3");
                formatado = formatado.replace(/(\d{4})(\d{1,2})$/, "$1-$2");
                return formatado;
            }
            
            // Função para validar CPF ou CNPJ
            function validarDocumento(doc) {
                doc = (doc || "").replace(/\D/g, "");
                if (doc.length === 11) {
                    // Validação básica CPF: não pode ser todos os dígitos iguais
                    if (/^(\d)\1+$/.test(doc)) {
                        return false;
                    }
                    return true;
                } else if (doc.length === 14) {
                    // Validação básica CNPJ: não pode ser todos os dígitos iguais
                    if (/^(\d)\1+$/.test(doc)) {
                        return false;
                    }
                    return true;
                }
                return false;
            }
            
            // Toggle entre PIX e Boleto
            document.addEventListener("change", function(e) {
                if (e.target && e.target.name === "sicoob_payment_type") {
                    var tipo = e.target.value;
                    var pixSection = document.querySelector(".sicoob-section-pix");
                    var boletoSection = document.querySelector(".sicoob-section-boleto");
                    if (pixSection && boletoSection) {
                        if (tipo === "pix") {
                            pixSection.style.display = "";
                            boletoSection.style.display = "none";
                        } else {
                            pixSection.style.display = "none";
                            boletoSection.style.display = "";
                        }
                    }
                }
            });
            
            // Aplicar máscara no campo de documento
            var campoDocumento = document.getElementById("sicoob_documento");
            if (campoDocumento) {
                // Aplicar máscara enquanto digita
                campoDocumento.addEventListener("input", function() {
                    this.setCustomValidity("");
                    this.value = aplicarMascaraDocumento(this.value);
                });
                
                // Validar ao perder o foco
                campoDocumento.addEventListener("blur", function() {
                    var docLimpo = this.value.replace(/\D/g, "");
                    
                    // Se tiver algum valor mas não for documento válido
                    if (docLimpo.length > 0 && !validarDocumento(this.value)) {
                        if (docLimpo.length < 11) {
                            this.setCustomValidity("Por favor, informe um CPF completo (11 dígitos) ou CNPJ (14 dígitos).");
                        } else if (docLimpo.length === 11) {
                            this.setCustomValidity("CPF inválido. Por favor, verifique os dígitos informados.");
                        } else if (docLimpo.length < 14) {
                            this.setCustomValidity("Por favor, informe um CNPJ completo (14 dígitos).");
                        } else {
                            this.setCustomValidity("CNPJ inválido. Por favor, verifique os dígitos informados.");
                        }
                        this.reportValidity();
                    } else {
                        this.setCustomValidity("");
                    }
                });
                
                // Aplicar máscara ao carregar (se já tiver valor)
                if (campoDocumento.value) {
                    campoDocumento.value = aplicarMascaraDocumento(campoDocumento.value);
                }
                
                // Aplicar máscara ao colar
                campoDocumento.addEventListener("paste", function(e) {
                    setTimeout(function() {
                        campoDocumento.setCustomValidity("");
                        campoDocumento.value = aplicarMascaraDocumento(campoDocumento.value);
                    }, 10);
                });
            }
        })();
        </script>';
        echo '</fieldset>';
    }

    /**
     * Validação dos campos
     */
    public function validate_fields()
    {
        $payment_type = isset($_POST['sicoob_payment_type']) ? sanitize_text_field(wp_unslash($_POST['sicoob_payment_type'])) : '';
        if (!$payment_type) {
            wc_add_notice(__('Selecione PIX ou Boleto.', 'sicoob-woocommerce'), 'error');
            return false;
        }
        if ($payment_type === 'pix') {
            $nome = isset($_POST['sicoob_nome']) ? trim(sanitize_text_field(wp_unslash($_POST['sicoob_nome']))) : '';
            $doc = isset($_POST['sicoob_documento']) ? sanitize_text_field(wp_unslash($_POST['sicoob_documento'])) : '';
            if (!$nome) {
                wc_add_notice(__('Informe o nome completo para pagar via PIX.', 'sicoob-woocommerce'), 'error');
                return false;
            }
            $doc_num = preg_replace('/\D+/', '', $doc);
            
            // Validar se é CPF (11 dígitos) ou CNPJ (14 dígitos)
            if (strlen($doc_num) !== 11 && strlen($doc_num) !== 14) {
                wc_add_notice(__('Por favor, informe um CPF válido (11 dígitos) ou CNPJ (14 dígitos).', 'sicoob-woocommerce'), 'error');
                return false;
            }
            
            // Validação básica: não pode ser todos os dígitos iguais
            if (preg_match('/^(\d)\1+$/', $doc_num)) {
                if (strlen($doc_num) === 11) {
                    wc_add_notice(__('CPF inválido. Por favor, informe um CPF válido.', 'sicoob-woocommerce'), 'error');
                } else {
                    wc_add_notice(__('CNPJ inválido. Por favor, informe um CNPJ válido.', 'sicoob-woocommerce'), 'error');
                }
                return false;
            }
        }
        return true;
    }
    /**
     * Campos do formulário de configuração
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Habilitar/Desabilitar', 'sicoob-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Habilitar Sicoob', 'sicoob-woocommerce'),
                'default' => 'no'
            ),
            'enable_pix' => array(
                'title' => __('PIX', 'sicoob-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Habilitar pagamento via PIX', 'sicoob-woocommerce'),
                'default' => 'yes'
            ),
            'enable_boleto' => array(
                'title' => __('Boleto', 'sicoob-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Habilitar pagamento via Boleto', 'sicoob-woocommerce'),
                'default' => 'yes'
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
            'access_token' => array(
                'title' => __('Access Token (Bearer)', 'sicoob-woocommerce'),
                'type' => 'password',
                'description' => __('Token Bearer do Sandbox fornecido pelo Sicoob (obrigatório no sandbox).', 'sicoob-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'pix_chave' => array(
                'title' => __('Chave PIX (recebedor)', 'sicoob-woocommerce'),
                'type' => 'text',
                'description' => __('Informe a chave PIX que receberá os pagamentos.', 'sicoob-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'pix_expiracao' => array(
                'title' => __('Expiração PIX (segundos)', 'sicoob-woocommerce'),
                'type' => 'number',
                'description' => __('Tempo para expirar a cobrança PIX (padrão 3600).', 'sicoob-woocommerce'),
                'default' => '3600',
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
     * Salva opções
     */
    public function process_admin_options()
    {
        parent::process_admin_options();
    }

    /**
     * Renderiza as opções e injeta JS para exibir/ocultar blocos e botão de upload.
     */
    public function admin_options()
    {
        echo '<h2>' . esc_html($this->get_method_title()) . '</h2>';
        echo wp_kses_post(wpautop($this->get_method_description()));
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';

        // Inline JS para toggle e upload
        ?>
        <script>
        (function($){
            function toggleBlocks(){
                var test = $('#woocommerce_<?php echo esc_js($this->id); ?>_testmode').is(':checked');
                var accessRow = $('#woocommerce_<?php echo esc_js($this->id); ?>_access_token').closest('tr');
                if(test){
                    accessRow.show();
                } else {
                    accessRow.hide();
                }
            }
            $(document).on('change', '#woocommerce_<?php echo esc_js($this->id); ?>_testmode', toggleBlocks);
            $(toggleBlocks);
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Processar pagamento
     */
    public function process_payment($order_id)
    {
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
            $api = new Sicoob_API(
                $this->client_id,
                $this->client_secret,
                $this->environment,
                $this->access_token,
                array(
                    'mtls_cert_path' => (string) $this->mtls_cert_path,
                    'mtls_key_path'  => (string) $this->mtls_key_path,
                    'mtls_key_pass'  => (string) $this->mtls_key_pass,
                    'app_key'        => (string) $this->app_key,
                )
            );

            // Capturar campos informados
            $payment_type = isset($_POST['sicoob_payment_type']) ? sanitize_text_field(wp_unslash($_POST['sicoob_payment_type'])) : 'pix';
            $pix_chave = '';
            $pix_data = '';
            $pix_nome = isset($_POST['sicoob_nome']) ? sanitize_text_field(wp_unslash($_POST['sicoob_nome'])) : '';
            $pix_documento = isset($_POST['sicoob_documento']) ? sanitize_text_field(wp_unslash($_POST['sicoob_documento'])) : '';
            $boleto_cod = isset($_POST['sicoob_boleto_codigo_barras']) ? sanitize_text_field(wp_unslash($_POST['sicoob_boleto_codigo_barras'])) : '';

            // Persistir no pedido
            $order->update_meta_data('_sicoob_payment_type', $payment_type);
            // Não armazenamos chave/data informadas no checkout, pois são removidas
            if ($pix_nome) {
                $order->update_meta_data('_sicoob_nome', $pix_nome);
            }
            if ($pix_documento) {
                $order->update_meta_data('_sicoob_documento', $pix_documento);
            }
            if ($boleto_cod) {
                $order->update_meta_data('_sicoob_boleto_codigo_barras', $boleto_cod);
            }
            $order->save();

            // Se o método for PIX, criar cobrança e redirecionar para obrigado com QR
            if ($payment_type === 'pix') {
                $txid = $this->generate_txid(get_current_user_id(), $order_id);
                $nome = $pix_nome ?: trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                $doc = $pix_documento ?: ($order->get_meta('_billing_cnpj') ?: $order->get_meta('_billing_cpf') ?: '');
                $chave = $this->get_option('pix_chave');
                $expiracao = absint($this->get_option('pix_expiracao', '3600')) ?: 3600;

                if (!$chave) {
                    throw new Exception(__('Chave PIX do recebedor não configurada.', 'sicoob-woocommerce'));
                }

                // Criar cobrança PIX e capturar resposta completa
                $cobranca_response = $api->pix_criar_cobranca_imediata($txid, $order->get_total(), $nome, $doc, $chave, $expiracao);
                
                // Validar resposta da API
                if (empty($cobranca_response) || !isset($cobranca_response['status'])) {
                    throw new Exception(__('Erro ao criar cobrança PIX: resposta inválida da API.', 'sicoob-woocommerce'));
                }
                
                // IMPORTANTE: Usar o txid retornado pela API (pode ser diferente do enviado)
                $txid_final = $cobranca_response['txid'] ?? $txid;
                
                // Salvar dados completos da cobrança (resposta completa da API)
                $order->update_meta_data('_sicoob_pix_txid', $txid_final);
                $order->update_meta_data('_sicoob_pix_cobranca_response', json_encode($cobranca_response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $order->update_meta_data('_sicoob_pix_status', $cobranca_response['status'] ?? '');
                $order->update_meta_data('_sicoob_pix_location', $cobranca_response['location'] ?? '');
                $order->update_meta_data('_sicoob_pix_brcode', $cobranca_response['brcode'] ?? '');
                
                // Salvar campos adicionais se presentes
                if (isset($cobranca_response['calendario'])) {
                    $order->update_meta_data('_sicoob_pix_calendario', json_encode($cobranca_response['calendario']));
                    if (isset($cobranca_response['calendario']['criacao'])) {
                        $order->update_meta_data('_sicoob_pix_criacao', $cobranca_response['calendario']['criacao']);
                    }
                    if (isset($cobranca_response['calendario']['expiracao'])) {
                        $order->update_meta_data('_sicoob_pix_expiracao', $cobranca_response['calendario']['expiracao']);
                    }
                }
                
                if (isset($cobranca_response['revisao'])) {
                    $order->update_meta_data('_sicoob_pix_revisao', $cobranca_response['revisao']);
                }
                
                if (isset($cobranca_response['devedor'])) {
                    $order->update_meta_data('_sicoob_pix_devedor', json_encode($cobranca_response['devedor']));
                }
                
                if (isset($cobranca_response['valor'])) {
                    $order->update_meta_data('_sicoob_pix_valor', json_encode($cobranca_response['valor']));
                }
                
                if (isset($cobranca_response['chave'])) {
                    $order->update_meta_data('_sicoob_pix_chave', $cobranca_response['chave']);
                }
                
                if (isset($cobranca_response['solicitacaoPagador'])) {
                    $order->update_meta_data('_sicoob_pix_solicitacao_pagador', $cobranca_response['solicitacaoPagador']);
                }
                
                // Obter imagem do QR Code usando o txid retornado pela API
                $revisao = isset($cobranca_response['revisao']) ? $cobranca_response['revisao'] : null;
                try {
                    $qr_image = $api->pix_obter_qr_code_imagem($txid_final, 360, $revisao);
                    $order->update_meta_data('_sicoob_pix_qr_image', $qr_image);
                } catch (Exception $e) {
                    // Se falhar ao obter imagem, não interromper o processo
                    // Log do erro mas continua com o pedido
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[SICOOB][AVISO] Falha ao obter imagem do QR Code: ' . $e->getMessage());
                    }
                    // Não salvar imagem mas continua
                }
                
                $order->save();

                $order->update_status('pending', __('Aguardando pagamento PIX', 'sicoob-woocommerce'));
                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            }

            $payment_data = array(
                'amount' => $order->get_total() * 100, // Converter para centavos
                'currency' => 'BRL',
                'order_id' => $order_id,
                'description' => sprintf(__('Pedido #%s', 'sicoob-woocommerce'), $order_id),
                'customer' => array(
                    'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'tax_id' => $order->get_meta('_billing_cpf') ?: $order->get_meta('_billing_cnpj') ?: '00000000000',
                ),
                'return_url' => $this->get_return_url($order),
                'cancel_url' => $order->get_cancel_order_url(),
            );

            $response = $api->create_payment($payment_data);

            // Flexibilizar chaves de retorno
            $payment_url = $response['payment_url'] ?? $response['paymentUrl'] ?? null;
            $transaction_id = $response['transaction_id'] ?? $response['id'] ?? null;

            if ($response && $payment_url) {
                // Salvar dados da transação (se houver id)
                if ($transaction_id) {
                    $this->save_transaction($order_id, $transaction_id, 'pending');
                }

                // Atualizar status do pedido
                $order->update_status('pending', __('Aguardando pagamento via Sicoob', 'sicoob-woocommerce'));

                // Redirecionar para página de pagamento
                return array(
                    'result' => 'success',
                    'redirect' => $payment_url
                );
            }

            // Se chegou aqui, resposta inválida
            throw new Exception(__('Erro ao criar transação no Sicoob: resposta inesperada da API', 'sicoob-woocommerce') . ' ' . json_encode($response));
        } catch (Exception $e) {
            // Formatar mensagem de erro - se contém |, separar title e detail
            $error_message = $e->getMessage();
            if (strpos($error_message, '|') !== false) {
                list($title, $detail) = explode('|', $error_message, 2);
                $formatted_message = '<strong>' . esc_html(trim($title)) . '</strong><br>' . esc_html(trim($detail));
            } else {
                $formatted_message = esc_html($error_message);
            }
            wc_add_notice(__('Erro no pagamento: ', 'sicoob-woocommerce') . $formatted_message, 'error');
            return array(
                'result' => 'fail',
                'redirect' => ''
            );
        }
    }

    /**
     * Página de obrigado: exibe QR Code do PIX e inicia polling.
     */
    public function thankyou_page($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $txid = $order->get_meta('_sicoob_pix_txid');
        $qr = $order->get_meta('_sicoob_pix_qr_image');
        if (!$txid || !$qr) {
            return;
        }

        // Pré-checagem: se já estiver pago, não mostrar QR Code
        $current_status = $order->get_status();
        if (in_array($current_status, array('processing', 'completed'), true)) {
            $this->gateway_debug('THANKYOU precheck', array('order_id' => $order_id, 'already_paid' => true, 'order_status' => $current_status));
            echo '<section class="woocommerce-order">';
            echo '<h2>' . esc_html__('Pagamento confirmado!', 'sicoob-woocommerce') . '</h2>';
            echo '<p>' . esc_html__('Seu pagamento PIX foi confirmado. Obrigado!', 'sicoob-woocommerce') . '</p>';
            echo '</section>';
            return;
        }

        // Consultar status na API antes de renderizar QR Code
        try {
            $api = new Sicoob_API(
                $this->client_id,
                $this->client_secret,
                $this->environment,
                $this->access_token,
                array(
                    'mtls_cert_path' => (string) $this->mtls_cert_path,
                    'mtls_key_path'  => (string) $this->mtls_key_path,
                    'mtls_key_pass'  => (string) $this->mtls_key_pass,
                    'app_key'        => (string) $this->app_key,
                )
            );

            $cob = $api->pix_obter_cobranca($txid);
            $status_pix = isset($cob['status']) ? $cob['status'] : '';
            $this->gateway_debug('THANKYOU precheck API', array('order_id' => $order_id, 'txid' => $txid, 'status' => $status_pix));

            if (in_array($status_pix, array('CONCLUIDA', 'approved', 'RECEBIDO'), true)) {
                if (!in_array($current_status, array('processing', 'completed'), true)) {
                    $order->payment_complete($txid);
                    $order->add_order_note(__('Pagamento PIX confirmado na carga da página de obrigado (pré-checagem).', 'sicoob-woocommerce'));
                    $order->save();
                }
                echo '<section class="woocommerce-order">';
                echo '<h2>' . esc_html__('Pagamento confirmado!', 'sicoob-woocommerce') . '</h2>';
                echo '<p>' . esc_html__('Seu pagamento PIX foi confirmado. Obrigado!', 'sicoob-woocommerce') . '</p>';
                echo '</section>';
                return;
            }
        } catch (Exception $e) {
            $this->gateway_debug('THANKYOU precheck error', $e->getMessage());
            // Falhou a consulta — seguir exibindo QR Code como fallback
        }
        echo '<section class="woocommerce-order">';
        echo '<h2>' . esc_html__('Pague via PIX', 'sicoob-woocommerce') . '</h2>';
        echo '<p>' . esc_html__('Leia o QR Code abaixo no seu app do banco. A cobrança expira em breve.', 'sicoob-woocommerce') . '</p>';
        echo '<img alt="PIX QR Code" style="max-width:280px;height:auto;border:1px solid #eee;padding:8px;background:#fff" src="' . esc_attr($qr) . '" />';
        echo '<p id="sicoob-pix-status" style="margin-top:8px">' . esc_html__('Aguardando pagamento...', 'sicoob-woocommerce') . '</p>';
        echo '<p><button type="button" id="sicoob-pix-ja-paguei" class="button alt" style="margin-top:6px">' . esc_html__('Já paguei', 'sicoob-woocommerce') . '</button></p>';
        ?>
        <script>
        (function() {
            var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var orderId = <?php echo intval($order_id); ?>;
            var txid = '<?php echo esc_js($txid); ?>';
            var statusEl = document.getElementById('sicoob-pix-status');
            var restBase = '<?php echo esc_url_raw( get_rest_url( null, 'sicoob/v1/cob/' ) ); ?>';
            var thankyouUrl = '<?php echo esc_url_raw( $order->get_checkout_order_received_url() ); ?>';
            var btn = document.getElementById('sicoob-pix-ja-paguei');
            
            if (!btn || !statusEl) {
                console.error('[SICOOB] Elementos não encontrados no DOM');
                return;
            }
            
            // Função para verificar status do pagamento
            function verificarStatus() {
                if (!statusEl || !txid) {
                    return;
                }
                
                var xhr = new XMLHttpRequest();
                // Trocar para GET para contornar 400 no admin-ajax (alguns ambientes exigem GET sem body)
                // Os handlers PHP usam $_REQUEST, então funcionará igualmente
                var qs = 'action=sicoob_pix_confirmar&order_id=' + orderId + '&txid=' + encodeURIComponent(txid) + '&sicoob_force=1';
                var urlGet = ajaxUrl + (ajaxUrl.indexOf('?') === -1 ? '?' : '&') + qs;
                xhr.open('GET', urlGet);
                xhr.onload = function() {
                    // Logar sempre o retorno bruto da requisição "Já paguei"
                    try { console.log('[SICOOB][JA_PAGUEI] raw:', xhr.responseText); } catch (e) {}
                    try {
                        var response = JSON.parse(xhr.responseText) || {};
                        try { console.log('[SICOOB][JA_PAGUEI] parsed:', response); } catch (e) {}
                        if (response.ok === true && (response.status === 'CONCLUIDA' || response.status === 'approved')) {
                            statusEl.innerText = '<?php echo esc_js(__('Pagamento confirmado!', 'sicoob-woocommerce')); ?>';
                            window.location.href = thankyouUrl;
                        }
                    } catch (e) {
                        console.error('[SICOOB] Erro ao processar resposta de status:', e);
                    }
                };
                xhr.onerror = function() {
                    console.error('[SICOOB] Erro na requisição de status');
                };
                xhr.send('action=sicoob_pix_status&order_id=' + orderId + '&txid=' + encodeURIComponent(txid));
            }
            
            // Auto polling desativado por padrão (evitar requisições indevidas)
            var ENABLE_AUTO_POLL = false;
            var pollInterval = null;
            if (ENABLE_AUTO_POLL) {
                pollInterval = setInterval(function() {
                    verificarStatus();
                }, 4000);
            }
            
            // Handler do botão "Já paguei"
            btn.addEventListener('click', function() {
                console.log('========== [SICOOB][JA_PAGUEI] BOTÃO CLICADO ==========');
                console.log('[SICOOB][JA_PAGUEI] orderId:', orderId);
                console.log('[SICOOB][JA_PAGUEI] txid:', txid);
                console.log('[SICOOB][JA_PAGUEI] ajaxUrl:', ajaxUrl);
                
                var prevText = btn.innerText;
                btn.disabled = true;
                btn.innerText = '<?php echo esc_js(__('Processando...', 'sicoob-woocommerce')); ?>';
                
                var xhr = new XMLHttpRequest();
                // Chamar rota REST do WordPress que faz a consulta ao Sicoob no servidor
                var urlGet = restBase + encodeURIComponent(txid) + '?order_id=' + encodeURIComponent(orderId);
                xhr.open('GET', urlGet, true);
                
                // Log antes de enviar
                console.log('[SICOOB][JA_PAGUEI] Dados enviados (GET) pela REST:', 'order_id=' + orderId + ' txid=' + txid);
                console.log('[SICOOB][JA_PAGUEI] URL completa:', urlGet);
                
                xhr.onload = function() {
                    console.log('[SICOOB][JA_PAGUEI] Resposta recebida - Status HTTP:', xhr.status);
                    console.log('[SICOOB][JA_PAGUEI] Resposta bruta (raw):', xhr.responseText);
                    
                    try {
                        var response = JSON.parse(xhr.responseText) || {};
                        console.log('[SICOOB][JA_PAGUEI] Resposta parseada (JSON):', JSON.stringify(response, null, 2));
                        console.log('[SICOOB][JA_PAGUEI] response.ok:', response.ok);
                        console.log('[SICOOB][JA_PAGUEI] response.status:', response.status);
                        console.log('[SICOOB][JA_PAGUEI] response.error:', response.error);
                        
                        if (response.ok === true && (response.status === 'CONCLUIDA' || response.status === 'approved')) {
                            console.log('[SICOOB][JA_PAGUEI] ✓ Pagamento confirmado! Redirecionando para a página de obrigado...');
                            statusEl.innerText = '<?php echo esc_js(__('Pagamento confirmado!', 'sicoob-woocommerce')); ?>';
                            if (pollInterval) { clearInterval(pollInterval); }
                            setTimeout(function() {
                                window.location.href = thankyouUrl;
                            }, 600);
                        } else {
                            console.log('[SICOOB][JA_PAGUEI] ✗ Pagamento não confirmado');
                            // Mostrar mensagem de erro específica ou genérica
                            var errorMsg = '';
                            if (response.error) {
                                errorMsg = response.error;
                                console.log('[SICOOB][JA_PAGUEI] Erro específico:', response.error);
                            } else if (response.status === 'NAO_ENCONTRADO') {
                                errorMsg = '<?php echo esc_js(__('Pagamento não localizado ainda. Tente novamente em instantes.', 'sicoob-woocommerce')); ?>';
                                console.log('[SICOOB][JA_PAGUEI] Status: NAO_ENCONTRADO');
                            } else {
                                errorMsg = '<?php echo esc_js(__('Não foi possível verificar o pagamento no momento. Tente novamente em instantes.', 'sicoob-woocommerce')); ?>';
                                console.log('[SICOOB][JA_PAGUEI] Erro genérico');
                            }
                            
                            statusEl.innerText = errorMsg;
                            statusEl.style.color = '#d63638'; // Cor vermelha para erro
                            btn.innerText = prevText;
                            btn.disabled = false;
                        }
                    } catch (e) {
                        console.error('[SICOOB][JA_PAGUEI] ERRO ao processar resposta:', e);
                        console.error('[SICOOB][JA_PAGUEI] Resposta bruta que causou erro:', xhr.responseText);
                        console.error('[SICOOB][JA_PAGUEI] Stack trace:', e.stack);
                        statusEl.innerText = '<?php echo esc_js(__('Erro ao processar resposta do servidor. Tente novamente.', 'sicoob-woocommerce')); ?>';
                        statusEl.style.color = '#d63638';
                        btn.innerText = prevText;
                        btn.disabled = false;
                    }
                };
                
                xhr.onerror = function() {
                    console.error('[SICOOB][JA_PAGUEI] ERRO na requisição AJAX');
                    console.error('[SICOOB][JA_PAGUEI] Status HTTP:', xhr.status);
                    console.error('[SICOOB][JA_PAGUEI] Status Text:', xhr.statusText);
                    statusEl.innerText = '<?php echo esc_js(__('Erro na conexão. Tente novamente.', 'sicoob-woocommerce')); ?>';
                    btn.innerText = prevText;
                    btn.disabled = false;
                };
                
                xhr.onloadstart = function() {
                    console.log('[SICOOB][JA_PAGUEI] Requisição iniciada...');
                };
                
                xhr.onloadend = function() {
                    console.log('[SICOOB][JA_PAGUEI] Requisição finalizada');
                };
                
                xhr.send(null);
                console.log('[SICOOB][JA_PAGUEI] Requisição enviada');
            });
        })();
        </script>
        <?php
        echo '</section>';
    }

    /**
     * AJAX: consulta status da cobrança PIX e retorna JSON.
     */
    public function ajax_pix_status()
    {
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $txid = isset($_POST['txid']) ? sanitize_text_field(wp_unslash($_POST['txid'])) : '';
        // Log forçado em wp-content/debug.log
        $this->gateway_debug('AJAX pix_status START', array('order_id' => $order_id, 'txid' => $txid));
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SICOOB][AJAX][DEBUG] ajax_pix_status chamado');
            error_log('[SICOOB][AJAX][DEBUG] Order ID: ' . $order_id);
            error_log('[SICOOB][AJAX][DEBUG] TXID: ' . $txid);
        }
        
        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order || !$txid) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][AJAX][DEBUG] Erro: Order ou TXID não encontrado');
                error_log('[SICOOB][AJAX][DEBUG] Order existe: ' . ($order ? 'SIM' : 'NÃO'));
                error_log('[SICOOB][AJAX][DEBUG] TXID existe: ' . ($txid ? 'SIM' : 'NÃO'));
            }
            wp_send_json(array('ok' => false, 'error' => 'Order ou TXID não encontrado'));
        }
        
        // Verificar status atual do pedido
        $order_status = $order->get_status();
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SICOOB][AJAX][DEBUG] Status atual do pedido: ' . $order_status);
            error_log('[SICOOB][AJAX][DEBUG] TXID salvo no pedido: ' . $order->get_meta('_sicoob_pix_txid'));
        }
        
        try {
            $api = new Sicoob_API(
                $this->client_id,
                $this->client_secret,
                $this->environment,
                $this->access_token,
                array(
                    'mtls_cert_path' => (string) $this->mtls_cert_path,
                    'mtls_key_path'  => (string) $this->mtls_key_path,
                    'mtls_key_pass'  => (string) $this->mtls_key_pass,
                    'app_key'        => (string) $this->app_key,
                )
            );
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][AJAX][DEBUG] Consultando cobrança na API Sicoob...');
            }
            
            $cob = $api->pix_obter_cobranca($txid);
            $status = $cob['status'] ?? '';
            $this->gateway_debug('AJAX pix_status COB', array('status' => $status, 'response' => $cob));
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][AJAX][DEBUG] Status retornado da API: ' . $status);
                error_log('[SICOOB][AJAX][DEBUG] Resposta completa da consulta: ' . json_encode($cob, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            
            if ($status === 'CONCLUIDA' || $status === 'approved') {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][AJAX][DEBUG] Pagamento CONCLUÍDO! Atualizando pedido...');
                }
                
                // Verificar se já foi pago para evitar processar duas vezes
                if ($order_status !== 'processing' && $order_status !== 'completed') {
                    $order->payment_complete($txid);
                    $order->add_order_note(__('Pagamento PIX confirmado via consulta de status.', 'sicoob-woocommerce'));
                    
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[SICOOB][AJAX][DEBUG] Pedido atualizado para: ' . $order->get_status());
                    }
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[SICOOB][AJAX][DEBUG] Pedido já estava pago (status: ' . $order_status . ')');
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][AJAX][DEBUG] Status não é CONCLUIDA. Status atual: ' . $status);
                }
            }
            
            $this->gateway_debug('AJAX pix_status END', array('ok' => true, 'status' => $status));
            wp_send_json(array('ok' => true, 'status' => $status));
        } catch (Exception $e) {
            $this->gateway_debug('AJAX pix_status ERROR', $e->getMessage());
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][AJAX][DEBUG] ERRO ao consultar cobrança: ' . $e->getMessage());
                error_log('[SICOOB][AJAX][DEBUG] Stack trace: ' . $e->getTraceAsString());
            }
            wp_send_json(array('ok' => false, 'error' => $e->getMessage()));
        }
    }

    /**
     * AJAX: confirmação manual "Já paguei" – consulta PIX recebidos em um range de tempo
     */
    public function ajax_pix_confirmar()
    {
        // Log FORÇADO - sempre executa (vai para debug.log)
        $this->gateway_debug('AJAX ja_paguei START');
        
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $txid = isset($_POST['txid']) ? sanitize_text_field(wp_unslash($_POST['txid'])) : '';
        $this->gateway_debug('AJAX ja_paguei POST', array('order_id' => $order_id, 'txid' => $txid));

        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order || !$txid) {
            error_log('[SICOOB][JA_PAGUEI] ERRO: Order ou TXID não encontrado. Order=' . ($order ? 'SIM' : 'NÃO') . ' TXID=' . ($txid ? 'SIM' : 'NÃO'));
            wp_send_json(array('ok' => false, 'error' => 'Order ou TXID não encontrado'));
        }
        
        $this->gateway_debug('AJAX ja_paguei ORDER', array('id' => $order->get_id(), 'status' => $order->get_status()));

        try {
            $api = new Sicoob_API(
                $this->client_id,
                $this->client_secret,
                $this->environment,
                $this->access_token,
                array(
                    'mtls_cert_path' => (string) $this->mtls_cert_path,
                    'mtls_key_path'  => (string) $this->mtls_key_path,
                    'mtls_key_pass'  => (string) $this->mtls_key_pass,
                    'app_key'        => (string) $this->app_key,
                )
            );

            // Primeiro, tentar consultar a cobrança diretamente (com revisao=0)
            $this->gateway_debug('AJAX ja_paguei COB start', array('txid' => $txid, 'revisao' => 0));
            
            try {
                $cob = $api->pix_obter_cobranca($txid);
                $status = $cob['status'] ?? '';

                $this->gateway_debug('AJAX ja_paguei COB resp', array('status' => $status, 'response' => $cob));
                
                // Verificar se a cobrança foi concluída
                if ($status === 'CONCLUIDA' || $status === 'approved' || $status === 'RECEBIDO') {
                    $order_status = $order->get_status();
                    if ($order_status !== 'processing' && $order_status !== 'completed') {
                        $order->payment_complete($txid);
                        $order->add_order_note(__('Pagamento PIX confirmado via verificação manual (consulta de cobrança).', 'sicoob-woocommerce'));
                        $order->save();
                    }
                    $resp_final = array('ok' => true, 'status' => 'CONCLUIDA');
                    $this->gateway_debug('AJAX ja_paguei END (cobranca)', $resp_final);
                    wp_send_json($resp_final);
                } else if ($status === 'ATIVA') {
                    // Cobrança ainda está ativa (não foi paga)
                    $this->gateway_debug('AJAX ja_paguei COB ativa');
                }
            } catch (Exception $e_cob) {
                $this->gateway_debug('AJAX ja_paguei COB error', $e_cob->getMessage());
                // Continuar para tentar buscar PIX recebidos mesmo se a consulta da cobrança falhar
            }

            // Se não encontrou na cobrança, tentar buscar PIX recebidos
            try {
                $created = $order->get_date_created();
                $created_ts = $created ? $created->getTimestamp() : (time() - 7200);
                $inicio = gmdate('Y-m-d\TH:i:s\Z', $created_ts - 2 * 3600);
                $fim    = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

                $this->gateway_debug('AJAX ja_paguei PIX start', array('inicio' => $inicio, 'fim' => $fim));

                $resp = $api->pix_listar_recebidos_por_txid($txid, $inicio, $fim);
                
                $this->gateway_debug('AJAX ja_paguei PIX resp', $resp);
                
                $pix_list = isset($resp['pix']) && is_array($resp['pix']) ? $resp['pix'] : array();

                if (!empty($pix_list)) {
                    // Considerar pago – pegar primeiro item
                    $endToEndId = $pix_list[0]['endToEndId'] ?? '';
                    $order_status = $order->get_status();
                    if ($order_status !== 'processing' && $order_status !== 'completed') {
                        $order->payment_complete($txid);
                        $order->add_order_note(__('Pagamento PIX confirmado via verificação manual (range).', 'sicoob-woocommerce'));
                        if ($endToEndId) {
                            $order->update_meta_data('_sicoob_pix_endtoendid', $endToEndId);
                        }
                        $order->save();
                    }
                    $resp_final = array('ok' => true, 'status' => 'CONCLUIDA');
                    $this->gateway_debug('AJAX ja_paguei END (pix)', $resp_final);
                    wp_send_json($resp_final);
                }

                // Se chegou aqui, não encontrou o pagamento
                $resp_final = array('ok' => false, 'status' => 'NAO_ENCONTRADO', 'error' => __('Pagamento não localizado ainda. Tente novamente em instantes.', 'sicoob-woocommerce'));
                $this->gateway_debug('AJAX ja_paguei END (nao encontrado)', $resp_final);
                wp_send_json($resp_final);
            } catch (Exception $e_pix) {
                $this->gateway_debug('AJAX ja_paguei PIX error', $e_pix->getMessage());
                // Se deu erro na busca de PIX recebidos, retornar mensagem amigável
                $resp_final = array('ok' => false, 'status' => 'ERRO_BUSCA', 'error' => __('Não foi possível verificar o pagamento no momento. Tente novamente em instantes.', 'sicoob-woocommerce'));
                $this->gateway_debug('AJAX ja_paguei END (erro busca)', $resp_final);
                wp_send_json($resp_final);
            }
        } catch (Exception $e) {
            $this->gateway_debug('AJAX ja_paguei ERROR', $e->getMessage());
            $resp_final = array('ok' => false, 'error' => $e->getMessage());
            $this->gateway_debug('AJAX ja_paguei END (erro geral)', $resp_final);
            wp_send_json($resp_final);
        }
    }

    /**
     * Registrar rotas REST para depuração/consulta de cobrança por TXID
     */
    public function register_rest_routes()
    {
        register_rest_route(
            'sicoob/v1',
            '/cob/(?P<txid>[^/]+)',
            array(
                'methods'  => 'GET',
                'callback' => array($this, 'rest_get_cobranca'),
                'permission_callback' => '__return_true', // ambiente interno; se necessário, ajustar
            )
        );
    }

    /**
     * Handler da rota REST: GET /wp-json/sicoob/v1/cob/{txid}?order_id=123
     */
    public function rest_get_cobranca( \WP_REST_Request $request )
    {
        $txid = sanitize_text_field( $request->get_param('txid') );
        $order_id = absint( $request->get_param('order_id') );
        $this->gateway_debug('REST cob START', array('order_id' => $order_id, 'txid' => $txid));

        try {
            $api = new Sicoob_API(
                $this->client_id,
                $this->client_secret,
                $this->environment,
                $this->access_token,
                array(
                    'mtls_cert_path' => (string) $this->mtls_cert_path,
                    'mtls_key_path'  => (string) $this->mtls_key_path,
                    'mtls_key_pass'  => (string) $this->mtls_key_pass,
                    'app_key'        => (string) $this->app_key,
                )
            );

            $cob = $api->pix_obter_cobranca($txid);
            $status = isset($cob['status']) ? $cob['status'] : '';
            $this->gateway_debug('REST cob RESP', array('status' => $status, 'response' => $cob));

            // Se veio um order_id e a cobrança concluiu, tentar completar o pedido
            if ( $order_id && in_array( $status, array('CONCLUIDA','approved'), true ) ) {
                $order = wc_get_order( $order_id );
                if ( $order && ! in_array( $order->get_status(), array('processing','completed'), true ) ) {
                    $order->payment_complete( $txid );
                    $order->add_order_note( __( 'Pagamento PIX confirmado via REST (consulta de cobrança).', 'sicoob-woocommerce' ) );
                    $order->save();
                    $this->gateway_debug('REST cob ORDER UPDATED', array('order_id' => $order_id, 'status' => $order->get_status()));
                }
            }

            return rest_ensure_response( array( 'ok' => true, 'status' => $status, 'cob' => $cob ) );
        } catch ( \Exception $e ) {
            $this->gateway_debug('REST cob ERROR', $e->getMessage());
            return new \WP_REST_Response( array( 'ok' => false, 'error' => $e->getMessage() ), 500 );
        }
    }

    /**
     * Log em wp-content/debug.log (gateway)
     */
    private function gateway_debug($label, $data = null)
    {
        $path = trailingslashit(WP_CONTENT_DIR) . 'debug.log';
        $line = '[' . date('Y-m-d H:i:s') . '] [SICOOB][GATEWAY][' . $label . '] ';
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
     * Registro de rotas REST (estático) — garante registro mesmo se o gateway não for instanciado.
     */
    public static function register_rest_routes_static()
    {
        register_rest_route(
            'sicoob/v1',
            '/cob/(?P<txid>[^/]+)',
            array(
                'methods'  => 'GET',
                'callback' => array('WC_Gateway_Sicoob', 'rest_get_cobranca_static'),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Handler REST estático — lê configurações salvas e consulta o Sicoob do lado do servidor.
     */
    public static function rest_get_cobranca_static( \WP_REST_Request $request )
    {
        $txid = sanitize_text_field( $request->get_param('txid') );
        $order_id = absint( $request->get_param('order_id') );
        $path = trailingslashit(WP_CONTENT_DIR) . 'debug.log';
        @file_put_contents($path, '['.date('Y-m-d H:i:s')."] [SICOOB][REST STATIC][START] txid={$txid} order_id={$order_id}\n", FILE_APPEND);

        try {
            $opts = get_option('woocommerce_sicoob_settings', array());
            $client_id    = isset($opts['client_id']) ? $opts['client_id'] : '';
            $client_secret= isset($opts['client_secret']) ? $opts['client_secret'] : '';
            $access_token = isset($opts['access_token']) ? $opts['access_token'] : '';
            $testmode     = isset($opts['testmode']) && $opts['testmode'] === 'yes';
            $environment  = $testmode ? 'sandbox' : 'production';
            $extras = array(
                'mtls_cert_path' => isset($opts['mtls_cert_path']) ? (string)$opts['mtls_cert_path'] : '',
                'mtls_key_path'  => isset($opts['mtls_key_path'])  ? (string)$opts['mtls_key_path']  : '',
                'mtls_key_pass'  => isset($opts['mtls_key_pass'])  ? (string)$opts['mtls_key_pass']  : '',
                'app_key'        => isset($opts['app_key'])        ? (string)$opts['app_key']        : '',
            );

            $api = new Sicoob_API($client_id, $client_secret, $environment, $access_token, $extras);
            $cob = $api->pix_obter_cobranca($txid);
            $status = isset($cob['status']) ? $cob['status'] : '';
            @file_put_contents($path, '['.date('Y-m-d H:i:s').'] [SICOOB][REST STATIC][RESP] '. json_encode(array('status'=>$status,'cob'=>$cob), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ."\n", FILE_APPEND);

            if ( $order_id && in_array($status, array('CONCLUIDA','approved'), true) ) {
                $order = wc_get_order($order_id);
                if ( $order && ! in_array( $order->get_status(), array('processing','completed'), true ) ) {
                    $order->payment_complete($txid);
                    $order->add_order_note( __( 'Pagamento PIX confirmado via REST (static).', 'sicoob-woocommerce' ) );
                    $order->save();
                    @file_put_contents($path, '['.date('Y-m-d H:i:s').'] [SICOOB][REST STATIC][ORDER UPDATED] '. json_encode(array('order_id'=>$order_id,'status'=>$order->get_status()), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ."\n", FILE_APPEND);
                }
            }

            return rest_ensure_response( array('ok'=>true,'status'=>$status,'cob'=>$cob) );
        } catch ( \Exception $e ) {
            @file_put_contents($path, '['.date('Y-m-d H:i:s').'] [SICOOB][REST STATIC][ERROR] '. $e->getMessage() ."\n", FILE_APPEND);
            return new \WP_REST_Response( array('ok'=>false,'error'=>$e->getMessage()), 500 );
        }
    }

    /**
     * Gerar txid único baseando-se em usuário, pedido e aleatório.
     */
    private function generate_txid($user_id, $order_id)
    {
        // Gera um txid alfanumérico entre 26 e 35 caracteres (BACEN)
        $base = 'WC' . intval($user_id) . 'O' . intval($order_id) . strtoupper(wp_generate_password(20, false, false));
        $txid = preg_replace('/[^A-Z0-9]/', '', $base);
        $len = strlen($txid);
        if ($len < 26) {
            $txid .= strtoupper(wp_generate_password(26 - $len, false, false));
        } elseif ($len > 35) {
            $txid = substr($txid, 0, 35);
        }
        return $txid;
    }

    /**
     * Salvar transação no banco de dados
     */
    private function save_transaction($order_id, $transaction_id, $status)
    {
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
    public function update_transaction_status($transaction_id, $status)
    {
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
     * Processa notificações PIX do Sicoob
     */
    public function webhook_handler()
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SICOOB][WEBHOOK][DEBUG] Webhook recebido');
            error_log('[SICOOB][WEBHOOK][DEBUG] Dados recebidos: ' . $input);
        }

        if (!$data) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][WEBHOOK][DEBUG] Erro: Dados inválidos');
            }
            wp_die('Invalid webhook data', 'Sicoob Webhook', array('response' => 400));
        }

        // Verificar assinatura do webhook (se configurado)
        $webhook_secret = $this->get_option('webhook_secret');
        if ($webhook_secret) {
            $signature = $_SERVER['HTTP_X_SICOOB_SIGNATURE'] ?? '';
            if (!$this->verify_webhook_signature($input, $signature)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][WEBHOOK][DEBUG] Erro: Assinatura inválida');
                }
                wp_die('Invalid signature', 'Sicoob Webhook', array('response' => 401));
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][WEBHOOK][DEBUG] AVISO: Webhook secret não configurado - processando sem validação');
            }
        }

        // Processar webhook
        $this->process_webhook_pix($data);

        wp_die('OK', 'Sicoob Webhook', array('response' => 200));
    }

    /**
     * Verificar assinatura do webhook
     */
    private function verify_webhook_signature($payload, $signature)
    {
        $expected_signature = hash_hmac('sha256', $payload, $this->get_option('webhook_secret'));
        return hash_equals($expected_signature, $signature);
    }

    /**
     * Processar webhook PIX do Sicoob
     * Processa diferentes tipos de eventos de PIX (pix.payment.received, pix.payment.confirmed, etc.)
     */
    private function process_webhook_pix($data)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SICOOB][WEBHOOK][DEBUG] Processando webhook PIX');
            error_log('[SICOOB][WEBHOOK][DEBUG] Estrutura dos dados: ' . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // Tentar diferentes formatos de webhook PIX
        $txid = '';
        $status = '';
        $endToEndId = '';
        
        // Formato 1: Evento direto com txid
        if (isset($data['txid'])) {
            $txid = $data['txid'];
            $status = $data['status'] ?? '';
        }
        // Formato 2: Evento dentro de pix[]
        elseif (isset($data['pix']) && is_array($data['pix'])) {
            foreach ($data['pix'] as $pix) {
                if (isset($pix['txid'])) {
                    $txid = $pix['txid'];
                    break;
                }
            }
            $status = $data['status'] ?? '';
        }
        // Formato 3: Evento dentro de cobranca
        elseif (isset($data['cobranca'])) {
            $txid = $data['cobranca']['txid'] ?? '';
            $status = $data['cobranca']['status'] ?? '';
        }
        // Formato 4: Evento genérico
        elseif (isset($data['evento'])) {
            $txid = $data['evento']['txid'] ?? '';
            $status = $data['evento']['status'] ?? '';
        }
        
        // Tentar pegar endToEndId se disponível
        if (isset($data['endToEndId'])) {
            $endToEndId = $data['endToEndId'];
        } elseif (isset($data['pix'][0]['endToEndId'])) {
            $endToEndId = $data['pix'][0]['endToEndId'];
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SICOOB][WEBHOOK][DEBUG] TXID extraído: ' . $txid);
            error_log('[SICOOB][WEBHOOK][DEBUG] Status extraído: ' . $status);
            error_log('[SICOOB][WEBHOOK][DEBUG] endToEndId extraído: ' . $endToEndId);
        }

        if (!$txid) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][WEBHOOK][DEBUG] Erro: TXID não encontrado no webhook');
            }
            return;
        }

        // Buscar pedido pelo TXID salvo no meta
        $orders = wc_get_orders(array(
            'limit' => 1,
            'meta_key' => '_sicoob_pix_txid',
            'meta_value' => $txid,
            'status' => array('pending', 'on-hold')
        ));

        if (empty($orders)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][WEBHOOK][DEBUG] Pedido não encontrado para TXID: ' . $txid);
            }
            return;
        }

        $order = $orders[0];
        $order_status = $order->get_status();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SICOOB][WEBHOOK][DEBUG] Pedido encontrado: #' . $order->get_id());
            error_log('[SICOOB][WEBHOOK][DEBUG] Status atual do pedido: ' . $order_status);
        }

        // Verificar se o status indica pagamento recebido
        $status_pago = false;
        if ($status === 'CONCLUIDA' || $status === 'RECEBIDO' || $status === 'RECEBIDO_MANUAL') {
            $status_pago = true;
        }
        
        // Se tiver endToEndId, significa que o PIX foi creditado
        if ($endToEndId) {
            $status_pago = true;
        }

        if ($status_pago) {
            // Verificar se já foi pago para evitar processar duas vezes
            if ($order_status !== 'processing' && $order_status !== 'completed') {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][WEBHOOK][DEBUG] PIX creditado! Atualizando pedido...');
                }

                $order->payment_complete($txid);
                $order->add_order_note(
                    sprintf(
                        __('Pagamento PIX confirmado via webhook. TXID: %s%s', 'sicoob-woocommerce'),
                        $txid,
                        $endToEndId ? ' | endToEndId: ' . $endToEndId : ''
                    )
                );

                // Salvar endToEndId se disponível
                if ($endToEndId) {
                    $order->update_meta_data('_sicoob_pix_endtoendid', $endToEndId);
                }

                // Salvar dados do webhook
                $order->update_meta_data('_sicoob_pix_webhook_data', json_encode($data, JSON_UNESCAPED_UNICODE));
                $order->save();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][WEBHOOK][DEBUG] Pedido atualizado para: ' . $order->get_status());
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[SICOOB][WEBHOOK][DEBUG] Pedido já estava pago (status: ' . $order_status . ')');
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[SICOOB][WEBHOOK][DEBUG] Status não indica pagamento recebido. Status: ' . $status);
            }
        }
    }

    /**
     * Processar dados do webhook (método legado - mantido para compatibilidade)
     */
    private function process_webhook($data)
    {
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
