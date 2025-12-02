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
        // Boleto (sem campo de código de barras – o boleto será gerado pelo sistema)
        echo '<div class="sicoob-section sicoob-section-boleto" style="margin-top:10px; ' . ($selected_type === 'boleto' ? '' : 'display:none;') . '">';
        echo '<p class="form-row form-row-wide" style="margin:6px 0;">' . esc_html__('O boleto será gerado automaticamente após finalizar o pedido.', 'sicoob-woocommerce') . '</p>';
        
        // Nome do pagador (obrigatório para boleto)
        $pagador_nome_padrao = '';
        if (isset($_POST['sicoob_boleto_pagador_nome'])) {
            $pagador_nome_padrao = esc_attr(wp_unslash($_POST['sicoob_boleto_pagador_nome']));
        } elseif (function_exists('WC') && WC()->customer) {
            $pagador_nome_padrao = esc_attr(trim(WC()->customer->get_billing_first_name() . ' ' . WC()->customer->get_billing_last_name()));
        }
        echo '<p class="form-row form-row-wide">';
        echo '<label for="sicoob_boleto_pagador_nome">' . esc_html__('Nome completo do pagador (obrigatório)', 'sicoob-woocommerce') . '</label>';
        echo '<input id="sicoob_boleto_pagador_nome" name="sicoob_boleto_pagador_nome" type="text" value="' . $pagador_nome_padrao . '" required />';
        echo '</p>';
        
        // Documento do pagador (CPF ou CNPJ) - obrigatório para boleto
        $pagador_doc_padrao = isset($_POST['sicoob_boleto_pagador_documento']) ? esc_attr(wp_unslash($_POST['sicoob_boleto_pagador_documento'])) : '';
        if (!$pagador_doc_padrao && function_exists('WC') && WC()->customer) {
            $pagador_doc_padrao = WC()->customer->get_meta('billing_cpf') ?: WC()->customer->get_meta('billing_cnpj') ?: '';
        }
        echo '<p class="form-row form-row-first">';
        echo '<label for="sicoob_boleto_pagador_documento">' . esc_html__('CPF ou CNPJ do pagador (obrigatório)', 'sicoob-woocommerce') . '</label>';
        echo '<input id="sicoob_boleto_pagador_documento" name="sicoob_boleto_pagador_documento" type="text" inputmode="numeric" pattern="[0-9\.\-/\s]*" value="' . $pagador_doc_padrao . '" placeholder="CPF ou CNPJ" required />';
        echo '</p>';
        
        // Quantidade de parcelas
        $max_parcelas = absint($this->get_option('boleto_max_parcelas', 1));
        if ($max_parcelas < 1) {
            $max_parcelas = 12; // Fallback seguro
        }
        echo '<p class="form-row form-row-last">';
        echo '<label for="sicoob_boleto_parcelas">' . esc_html__('Quantidade de parcelas', 'sicoob-woocommerce') . '</label>';
        echo '<select id="sicoob_boleto_parcelas" name="sicoob_boleto_parcelas">';
        for ($i = 1; $i <= $max_parcelas; $i++) {
            $sel = (isset($_POST['sicoob_boleto_parcelas']) ? intval($_POST['sicoob_boleto_parcelas']) : 1) == $i ? ' selected' : '';
            echo '<option value="' . $i . '"' . $sel . '>' . $i . '</option>';
        }
        echo '</select>';
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
            
            // Aplicar máscara no campo de documento do pagador do boleto
            var campoDocumentoPagador = document.getElementById("sicoob_boleto_pagador_documento");
            if (campoDocumentoPagador) {
                // Aplicar máscara enquanto digita
                campoDocumentoPagador.addEventListener("input", function() {
                    this.setCustomValidity("");
                    this.value = aplicarMascaraDocumento(this.value);
                });
                
                // Validar ao perder o foco
                campoDocumentoPagador.addEventListener("blur", function() {
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
                if (campoDocumentoPagador.value) {
                    campoDocumentoPagador.value = aplicarMascaraDocumento(campoDocumentoPagador.value);
                }
                
                // Aplicar máscara ao colar
                campoDocumentoPagador.addEventListener("paste", function(e) {
                    setTimeout(function() {
                        campoDocumentoPagador.setCustomValidity("");
                        campoDocumentoPagador.value = aplicarMascaraDocumento(campoDocumentoPagador.value);
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
        
        if ($payment_type === 'boleto') {
            $pagador_nome = isset($_POST['sicoob_boleto_pagador_nome']) ? trim(sanitize_text_field(wp_unslash($_POST['sicoob_boleto_pagador_nome']))) : '';
            $pagador_doc = isset($_POST['sicoob_boleto_pagador_documento']) ? sanitize_text_field(wp_unslash($_POST['sicoob_boleto_pagador_documento'])) : '';
            
            if (!$pagador_nome) {
                wc_add_notice(__('Informe o nome completo do pagador para pagar via Boleto.', 'sicoob-woocommerce'), 'error');
                return false;
            }
            
            if (!$pagador_doc) {
                wc_add_notice(__('Informe o CPF ou CNPJ do pagador para pagar via Boleto.', 'sicoob-woocommerce'), 'error');
                return false;
            }
            
            $pagador_doc_num = preg_replace('/\D+/', '', $pagador_doc);
            
            // Validar se é CPF (11 dígitos) ou CNPJ (14 dígitos)
            if (strlen($pagador_doc_num) !== 11 && strlen($pagador_doc_num) !== 14) {
                wc_add_notice(__('Por favor, informe um CPF válido (11 dígitos) ou CNPJ (14 dígitos) do pagador.', 'sicoob-woocommerce'), 'error');
                return false;
            }
            
            // Validação básica: não pode ser todos os dígitos iguais
            if (preg_match('/^(\d)\1+$/', $pagador_doc_num)) {
                if (strlen($pagador_doc_num) === 11) {
                    wc_add_notice(__('CPF do pagador inválido. Por favor, informe um CPF válido.', 'sicoob-woocommerce'), 'error');
                } else {
                    wc_add_notice(__('CNPJ do pagador inválido. Por favor, informe um CNPJ válido.', 'sicoob-woocommerce'), 'error');
                }
                return false;
            }
            
            // Validar dados de endereço do pagador (obrigatórios para boleto)
            if (function_exists('WC') && WC()->customer) {
                $billing_address_1 = WC()->customer->get_billing_address_1();
                $billing_neighborhood = WC()->customer->get_meta('billing_neighborhood');
                $billing_city = WC()->customer->get_billing_city();
                $billing_postcode = WC()->customer->get_billing_postcode();
                $billing_state = WC()->customer->get_billing_state();
                
                if (empty($billing_address_1)) {
                    wc_add_notice(__('O endereço de cobrança do pagador é obrigatório para pagamento via Boleto. Por favor, preencha todos os dados de endereço.', 'sicoob-woocommerce'), 'error');
                    return false;
                }
                
                if (empty($billing_neighborhood)) {
                    wc_add_notice(__('O bairro do endereço de cobrança é obrigatório para pagamento via Boleto. Por favor, preencha todos os dados de endereço.', 'sicoob-woocommerce'), 'error');
                    return false;
                }
                
                if (empty($billing_city)) {
                    wc_add_notice(__('A cidade do endereço de cobrança é obrigatória para pagamento via Boleto. Por favor, preencha todos os dados de endereço.', 'sicoob-woocommerce'), 'error');
                    return false;
                }
                
                if (empty($billing_postcode)) {
                    wc_add_notice(__('O CEP do endereço de cobrança é obrigatório para pagamento via Boleto. Por favor, preencha todos os dados de endereço.', 'sicoob-woocommerce'), 'error');
                    return false;
                }
                
                if (empty($billing_state)) {
                    wc_add_notice(__('O estado (UF) do endereço de cobrança é obrigatório para pagamento via Boleto. Por favor, preencha todos os dados de endereço.', 'sicoob-woocommerce'), 'error');
                    return false;
                }
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
            // ====== Configurações específicas de BOLETO ======
            'boleto_section_title' => array(
                'title' => __('Configurações de Boleto', 'sicoob-woocommerce'),
                'type'  => 'title',
                'description' => __('Parâmetros usados na emissão do boleto.', 'sicoob-woocommerce'),
            ),
            'boleto_max_parcelas' => array(
                'title' => __('Máximo de Parcelas', 'sicoob-woocommerce'),
                'type' => 'number',
                'description' => __('Número máximo de parcelas que aparecerá no checkout (padrão: 1).', 'sicoob-woocommerce'),
                'default' => '1',
                'custom_attributes' => array(
                    'min' => '1',
                    'max' => '48',
                ),
                'desc_tip' => true,
            ),
            'boleto_numero_cliente' => array(
                'title' => __('Número do Cliente', 'sicoob-woocommerce'),
                'type'  => 'text',
                'default' => '',
                'description' => __('Número do cliente fornecido pelo Sicoob. Informe o código numérico que identifica sua conta/cliente no banco. Exemplo: 25546454', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_codigo_modalidade' => array(
                'title' => __('Código da Modalidade', 'sicoob-woocommerce'),
                'type'  => 'number',
                'default' => 1,
                'description' => __('Código da modalidade de cobrança conforme cadastrado no Sicoob. Valores comuns: 1 (Simples), 2 (Vinculada), etc. Consulte sua documentação contratual.', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_numero_conta_corrente' => array(
                'title' => __('Número da Conta Corrente', 'sicoob-woocommerce'),
                'type'  => 'text',
                'default' => '0',
                'description' => __('Número da conta corrente onde os valores serão creditados. Deixe 0 (zero) se não aplicável ou se a conta padrão será utilizada.', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_especie_documento' => array(
                'title' => __('Espécie do Documento', 'sicoob-woocommerce'),
                'type'  => 'select',
                'default' => 'DM',
                'options' => array(
                    'DMI' => __('DMI - Duplicata Mercantil Indicação', 'sicoob-woocommerce'),
                    'DS'  => __('DS - Duplicata de Serviço', 'sicoob-woocommerce'),
                    'DSI' => __('DSI - Duplicata Serviço Indicação', 'sicoob-woocommerce'),
                    'DR'  => __('DR - Duplicata Rural', 'sicoob-woocommerce'),
                    'LC'  => __('LC - Letra de Câmbio', 'sicoob-woocommerce'),
                    'NCC' => __('NCC - Nota de Crédito Comercial', 'sicoob-woocommerce'),
                    'NCE' => __('NCE - Nota de Crédito Exportação', 'sicoob-woocommerce'),
                    'NCI' => __('NCI - Nota de Crédito Industrial', 'sicoob-woocommerce'),
                    'NCR' => __('NCR - Nota de Crédito Rural', 'sicoob-woocommerce'),
                    'NP'  => __('NP - Nota Promissória', 'sicoob-woocommerce'),
                    'NPR' => __('NPR - Nota Promissória Rural', 'sicoob-woocommerce'),
                    'TM'  => __('TM - Triplicata Mercantil', 'sicoob-woocommerce'),
                    'TS'  => __('TS - Triplicata de Serviço', 'sicoob-woocommerce'),
                    'NS'  => __('NS - Nota de Seguro', 'sicoob-woocommerce'),
                    'RC'  => __('RC - Recibo', 'sicoob-woocommerce'),
                    'FAT' => __('FAT - Fatura', 'sicoob-woocommerce'),
                    'ND'  => __('ND - Nota de Débito', 'sicoob-woocommerce'),
                    'AP'  => __('AP - Apólice de Seguro', 'sicoob-woocommerce'),
                    'ME'  => __('ME - Mensalidade Escolar', 'sicoob-woocommerce'),
                    'PC'  => __('PC - Pagamento de Consórcio', 'sicoob-woocommerce'),
                    'NF'  => __('NF - Nota Fiscal', 'sicoob-woocommerce'),
                    'DD'  => __('DD - Documento de Dívida', 'sicoob-woocommerce'),
                    'CC'  => __('CC - Cartão de Crédito', 'sicoob-woocommerce'),
                    'BDP' => __('BDP - Boleto Proposta', 'sicoob-woocommerce'),
                    'OU'  => __('OU - Outros', 'sicoob-woocommerce'),
                ),
                'description' => __('Espécie do documento do boleto. Selecione o tipo conforme o documento utilizado.', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_dias_vencimento' => array(
                'title' => __('Dias para Vencimento', 'sicoob-woocommerce'),
                'type'  => 'number',
                'default' => 3,
                'description' => __('Quantidade de dias a partir da data de emissão para o vencimento do boleto. Exemplo: 3 = vence em 3 dias, 30 = vence em 30 dias. Mínimo: 1 dia', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_multa_tipo' => array(
                'title' => __('Tipo de Multa', 'sicoob-woocommerce'),
                'type'  => 'select',
                'default' => '0',
                'options' => array(
                    '0' => __('0 - Sem multa', 'sicoob-woocommerce'),
                    '1' => __('1 - Valor fixo', 'sicoob-woocommerce'),
                    '2' => __('2 - Percentual', 'sicoob-woocommerce'),
                ),
                'description' => __('Tipo de cálculo da multa por atraso. Selecione "Sem multa" se não deseja aplicar multa, ou escolha entre valor fixo ou percentual.', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_multa_valor' => array(
                'title' => __('Valor da Multa', 'sicoob-woocommerce'),
                'type'  => 'text',
                'default' => '0',
                'description' => __('Valor ou percentual da multa. Se tipo = 1 (Valor fixo), informe valor em reais (ex: 10,50 ou 10.50). Se tipo = 2 (Percentual), informe percentual (ex: 2 para 2%). Se tipo = 0 (Sem multa), deixe 0. O campo formata automaticamente como moeda brasileira.', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_juros_tipo' => array(
                'title' => __('Tipo de Juros de Mora', 'sicoob-woocommerce'),
                'type'  => 'select',
                'default' => '0',
                'options' => array(
                    '0' => __('0 - Sem juros', 'sicoob-woocommerce'),
                    '1' => __('1 - Taxa mensal', 'sicoob-woocommerce'),
                    '2' => __('2 - Valor por dia', 'sicoob-woocommerce'),
                ),
                'description' => __('Tipo de cálculo dos juros de mora. Selecione "Sem juros" se não deseja aplicar juros, ou escolha entre taxa mensal ou valor por dia.', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_juros_valor' => array(
                'title' => __('Valor dos Juros de Mora', 'sicoob-woocommerce'),
                'type'  => 'text',
                'default' => '0',
                'description' => __('Valor dos juros conforme o tipo: tipo 1 (Taxa mensal) = percentual mensal (ex: 1 para 1% ao mês), tipo 2 (Valor por dia) = valor por dia em reais (ex: 0,50 ou 0.50). Se tipo = 0 (Sem juros), deixe 0. O campo formata automaticamente como moeda brasileira.', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_negativacao_codigo' => array(
                'title' => __('Código de Negativação', 'sicoob-woocommerce'),
                'type'  => 'select',
                'default' => '0',
                'options' => array(
                    '0' => __('0 - Não negativar', 'sicoob-woocommerce'),
                    '1' => __('1 - Negativar', 'sicoob-woocommerce'),
                    '2' => __('2 - Negativar com dias informados', 'sicoob-woocommerce'),
                ),
                'description' => __('Código para negativação automática após atraso. Selecione a opção desejada. Se escolher "Negativar com dias informados", configure o campo "Dias para Negativação".', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_negativacao_dias' => array(
                'title' => __('Dias para Negativação', 'sicoob-woocommerce'),
                'type'  => 'number',
                'default' => 0,
                'description' => __('Quantidade de dias após o vencimento para efetuar a negativação. Exemplo: 60 = negativa após 60 dias de atraso. Só usado se código de negativação for 1 ou 2.', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_protesto_codigo' => array(
                'title' => __('Código de Protesto', 'sicoob-woocommerce'),
                'type'  => 'select',
                'default' => '0',
                'options' => array(
                    '0' => __('0 - Não protestar', 'sicoob-woocommerce'),
                    '1' => __('1 - Protestar', 'sicoob-woocommerce'),
                    '2' => __('2 - Protestar com dias informados', 'sicoob-woocommerce'),
                ),
                'description' => __('Código para protesto do título. Selecione a opção desejada. Se escolher "Protestar com dias informados", configure o campo "Dias para Protesto".', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_protesto_dias' => array(
                'title' => __('Dias para Protesto', 'sicoob-woocommerce'),
                'type'  => 'number',
                'default' => 0,
                'description' => __('Quantidade de dias após o vencimento para efetuar o protesto. Exemplo: 30 = protesta após 30 dias de atraso. Só usado se código de protesto for 1 ou 2.', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'benef_final_cpf_cnpj' => array(
                'title' => __('Beneficiário Final - CPF/CNPJ', 'sicoob-woocommerce'),
                'type'  => 'text',
                'default' => '',
                'description' => __('CPF ou CNPJ do beneficiário final do boleto (sem pontuação). Exemplo: 11122233300 (CPF) ou 12345678000190 (CNPJ). Obrigatório se houver beneficiário final.', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'benef_final_nome' => array(
                'title' => __('Beneficiário Final - Nome', 'sicoob-woocommerce'),
                'type'  => 'text',
                'default' => '',
                'description' => __('Nome completo ou razão social do beneficiário final. Deve corresponder ao CPF/CNPJ informado. Exemplo: "João da Silva" ou "Empresa XYZ Ltda".', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_instrucoes' => array(
                'title' => __('Mensagens/Instruções (uma por linha, máx. 5)', 'sicoob-woocommerce'),
                'type'  => 'textarea',
                'default' => '',
                'description' => __('Instruções que aparecerão no boleto. Uma mensagem por linha, máximo de 5 linhas. Exemplos: "Não receber após o vencimento", "Em caso de dúvidas, entre em contato", etc.', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_gerar_pdf' => array(
                'title' => __('Gerar PDF do boleto', 'sicoob-woocommerce'),
                'type'  => 'checkbox',
                'default' => 'no',
                'label' => __('Habilitar geração automática de PDF', 'sicoob-woocommerce'),
                'description' => __('Marque esta opção se deseja que a API gere automaticamente um arquivo PDF do boleto. O PDF estará disponível na resposta da emissão.', 'sicoob-woocommerce'),
            ),
            'boleto_rateio_json' => array(
                'title' => __('Rateio de Créditos (JSON opcional)', 'sicoob-woocommerce'),
                'type'  => 'textarea',
                'default' => '',
                'description' => __('JSON opcional para rateio de créditos entre contas. Formato: array de objetos com númeroBanco, numeroAgencia, numeroContaCorrente, valorRateio, etc. Deixe vazio se não houver rateio. Consulte a documentação do Sicoob para o formato completo.', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_cadastrar_pix' => array(
                'title' => __('Código Cadastrar PIX', 'sicoob-woocommerce'),
                'type'  => 'select',
                'default' => '0',
                'options' => array(
                    '0' => __('0 - Não cadastrar', 'sicoob-woocommerce'),
                    '1' => __('1 - Cadastrar', 'sicoob-woocommerce'),
                ),
                'description' => __('Código para cadastrar PIX no boleto. Selecione "Cadastrar" se deseja que a chave PIX seja incluída automaticamente no boleto para pagamento via PIX.', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
            'boleto_numero_contrato' => array(
                'title' => __('Número do Contrato de Cobrança', 'sicoob-woocommerce'),
                'type'  => 'number',
                'default' => 0,
                'description' => __('Número do contrato de cobrança registrado no Sicoob. Informe o número do contrato utilizado para emissão de boletos. Exemplo: 1, 2, etc. Obrigatório para emissão.', 'sicoob-woocommerce'),
                'desc_tip' => true,
            ),
        );
    }

    /**
     * Função helper para remover formatação monetária
     */
    private function limpar_valor_monetario($valor) {
        if (empty($valor) || $valor === '0') {
            return '0';
        }
        // Remove formatação (espaços, pontos de milhar, mantém apenas números e vírgula/ponto decimal)
        $limpo = preg_replace('/[^\d,.-]/', '', (string) $valor);
        // Substitui vírgula por ponto
        $limpo = str_replace(',', '.', $limpo);
        // Remove múltiplos pontos, mantendo apenas o último
        $partes = explode('.', $limpo);
        if (count($partes) > 2) {
            $limpo = $partes[0] . '.' . implode('', array_slice($partes, 1));
        }
        // Se não tiver ponto, adiciona .00
        if (strpos($limpo, '.') === false && $limpo !== '0') {
            $limpo = $limpo . '.00';
        }
        return $limpo ?: '0';
    }

    /**
     * Salva opções
     */
    public function process_admin_options()
    {
        // Limpar formatação monetária antes de salvar
        $campos_monetarios = array(
            'boleto_multa_valor',
            'boleto_juros_valor'
        );
        
        foreach ($campos_monetarios as $campo) {
            $key = 'woocommerce_' . $this->id . '_' . $campo;
            if (isset($_POST[$key])) {
                $_POST[$key] = $this->limpar_valor_monetario($_POST[$key]);
            }
        }
        
        parent::process_admin_options();
    }

    /**
     * Renderiza as opções e injeta JS para exibir/ocultar blocos e botão de upload.
     */
    public function admin_options()
    {
        echo '<h2>' . esc_html($this->get_method_title()) . '</h2>';
        echo wp_kses_post(wpautop($this->get_method_description()));
        
        // CSS customizado para melhorar UX das configurações de boleto
        ?>
        <style>
        /* Estilização da seção de Configurações de Boleto */
        .woocommerce .form-table tr.boleto-section-title th,
        .woocommerce .form-table tr.boleto-section-title td {
            padding: 20px 0 10px 0;
            border-bottom: 2px solid #e5e5e5;
        }
        
        .woocommerce .form-table tr.boleto-section-title th {
            font-size: 16px;
            font-weight: 600;
            color: #23282d;
        }
        
        .woocommerce .form-table tr.boleto-section-title td p.description {
            margin: 5px 0 0 0;
            color: #646970;
            font-size: 13px;
        }
        
        /* Agrupamento visual para campos relacionados */
        .woocommerce .form-table tr[id*="boleto_multa"],
        .woocommerce .form-table tr[id*="boleto_juros"] {
            background-color: #f9f9f9;
        }
        
        .woocommerce .form-table tr[id*="boleto_multa"] td,
        .woocommerce .form-table tr[id*="boleto_juros"] td {
            padding-top: 12px;
            padding-bottom: 12px;
        }
        
        /* Wrapper para campos monetários com prefixo R$ */
        .sicoob-money-field-wrapper {
            position: relative;
            display: inline-block;
            width: 100%;
            max-width: 300px;
        }
        
        .sicoob-money-field-wrapper::before {
            content: 'R$';
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #646970;
            font-weight: 600;
            font-size: 14px;
            z-index: 1;
            pointer-events: none;
            line-height: 1;
        }
        
        .sicoob-money-field-wrapper input[type="text"] {
            padding-left: 40px !important;
            width: 100% !important;
        }
        
        /* Garantir que o wrapper funcione dentro da célula da tabela */
        .woocommerce .form-table td .sicoob-money-field-wrapper {
            display: inline-block;
            width: 100%;
            max-width: 300px;
        }
        
        /* Melhor espaçamento entre grupos de campos */
        .woocommerce .form-table tr[id*="boleto_numero_cliente"],
        .woocommerce .form-table tr[id*="boleto_codigo_modalidade"],
        .woocommerce .form-table tr[id*="boleto_numero_conta_corrente"],
        .woocommerce .form-table tr[id*="boleto_especie_documento"],
        .woocommerce .form-table tr[id*="boleto_dias_vencimento"] {
            border-top: 1px solid #f0f0f1;
        }
        
        /* Agrupamento visual para Multa */
        .woocommerce .form-table tr[id*="boleto_multa_tipo"] {
            border-top: 2px solid #dcdcde;
            margin-top: 10px;
        }
        
        .woocommerce .form-table tr[id*="boleto_multa_tipo"] th {
            padding-top: 20px;
        }
        
        /* Agrupamento visual para Juros */
        .woocommerce .form-table tr[id*="boleto_juros_tipo"] {
            border-top: 2px solid #dcdcde;
            margin-top: 10px;
        }
        
        .woocommerce .form-table tr[id*="boleto_juros_tipo"] th {
            padding-top: 20px;
        }
        
        /* Melhor estilo para selects */
        .woocommerce .form-table select[id*="boleto"] {
            padding: 6px 8px;
            border-radius: 4px;
            border: 1px solid #8c8f94;
            min-width: 250px;
        }
        
        .woocommerce .form-table select[id*="boleto"]:focus {
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
            outline: 2px solid transparent;
        }

        .sicoob-tabs-active-geral #woocommerce_sicoob_boleto_section_title { display: none; }
        .sicoob-tabs-active-boleto #woocommerce_sicoob_boleto_section_title { display: table-row; }
        
        /* Melhor estilo para inputs */
        .woocommerce .form-table input[type="text"][id*="boleto"],
        .woocommerce .form-table input[type="number"][id*="boleto"] {
            padding: 6px 10px;
            border-radius: 4px;
            border: 1px solid #8c8f94;
            min-width: 250px;
        }
        
        .woocommerce .form-table input[type="text"][id*="boleto"]:focus,
        .woocommerce .form-table input[type="number"][id*="boleto"]:focus {
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
            outline: 2px solid transparent;
        }
        
        /* Ícones de ajuda mais visíveis */
        .woocommerce .form-table th label .woocommerce-help-tip {
            margin-left: 5px;
            color: #2271b1;
        }
        
        .woocommerce .form-table th label .woocommerce-help-tip:hover {
            color: #135e96;
        }
        
        /* Agrupamento para Beneficiário Final */
        .woocommerce .form-table tr[id*="benef_final"] {
            background-color: #f0f6fc;
            border-left: 3px solid #2271b1;
        }
        
        .woocommerce .form-table tr[id*="benef_final"] th,
        .woocommerce .form-table tr[id*="benef_final"] td {
            padding-left: 15px;
        }
        
        /* Abas customizadas */
        .sicoob-settings-tabs {
            margin: 20px 0 15px 0;
            display: flex !important;
            gap: 8px;
            border-bottom: 2px solid #e5e5e5;
            flex-wrap: wrap;
            padding-bottom: 0;
        }
        
        .sicoob-settings-tabs .sicoob-tab {
            border: none !important;
            background: #f0f0f1 !important;
            color: #3c434a !important;
            padding: 10px 20px !important;
            border-radius: 4px 4px 0 0 !important;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s ease;
            margin: 0;
            margin-bottom: -2px;
            outline: none;
        }
        
        .sicoob-settings-tabs .sicoob-tab:hover {
            background: #dcdcde !important;
        }
        
        .sicoob-settings-tabs .sicoob-tab.active {
            background: #2271b1 !important;
            color: #fff !important;
            border-bottom: 2px solid #2271b1 !important;
            position: relative;
            z-index: 1;
        }
        
        /* Quando as abas são inicializadas, esconder todas as linhas por padrão */
        .sicoob-settings-table.sicoob-tabs-initialized tr[data-tab] {
            display: none !important;
        }
        
        /* Mostrar apenas a aba ativa */
        .sicoob-settings-table.sicoob-tabs-active-geral tr[data-tab="geral"] {
            display: table-row !important;
        }
        
        .sicoob-settings-table.sicoob-tabs-active-boleto tr[data-tab="boleto"] {
            display: table-row !important;
        }
        
        .sicoob-settings-table.sicoob-tabs-active-conta tr[data-tab="conta"] {
            display: table-row !important;
        }
        
        /* Subtítulos visuais para grupos */
        .woocommerce .form-table tr.boleto-subsection th {
            font-size: 14px;
            font-weight: 600;
            color: #50575e;
            padding-top: 20px;
            padding-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 12px;
        }
        
        /* Responsividade */
        @media (max-width: 782px) {
            .woocommerce .form-table tr[id*="boleto"] th,
            .woocommerce .form-table tr[id*="boleto"] td {
                display: block;
                width: 100%;
            }
            
            .woocommerce .form-table tr[id*="boleto"] th {
                padding-bottom: 5px;
            }
        }
        </style>
        <?php
        
        echo '<div class="sicoob-settings-tabs" style="display: flex !important; margin: 20px 0 15px 0; gap: 8px; border-bottom: 2px solid #e5e5e5; padding-bottom: 0; visibility: visible !important;">';
        echo '<button type="button" class="sicoob-tab active" data-tab="geral" style="border: none !important; background: #2271b1 !important; color: #fff !important; padding: 10px 20px !important; border-radius: 4px 4px 0 0 !important; cursor: pointer; font-weight: 600; font-size: 14px; margin: 0; margin-bottom: -2px; visibility: visible !important;">' . esc_html__('Geral', 'sicoob-woocommerce') . '</button>';
        echo '<button type="button" class="sicoob-tab" data-tab="boleto" style="border: none !important; background: #f0f0f1 !important; color: #3c434a !important; padding: 10px 20px !important; border-radius: 4px 4px 0 0 !important; cursor: pointer; font-weight: 600; font-size: 14px; margin: 0; margin-bottom: -2px; visibility: visible !important;">' . esc_html__('Boleto', 'sicoob-woocommerce') . '</button>';
        echo '<button type="button" class="sicoob-tab" data-tab="conta" style="border: none !important; background: #f0f0f1 !important; color: #3c434a !important; padding: 10px 20px !important; border-radius: 4px 4px 0 0 !important; cursor: pointer; font-weight: 600; font-size: 14px; margin: 0; margin-bottom: -2px; visibility: visible !important;">' . esc_html__('Conta', 'sicoob-woocommerce') . '</button>';
        echo '</div>';
        
        echo '<table class="form-table sicoob-settings-table" style="width: 100%;">';
        $this->generate_settings_html();
        echo '</table>';

        // Inline JS para toggle, upload e formatação monetária
        ?>
        <script>
        (function($){
            // Verificar se jQuery está disponível
            if (typeof jQuery === 'undefined') {
                console.error('[SICOOB] jQuery não está disponível');
                return;
            }
            
            function toggleBlocks(){
                // Função mantida para compatibilidade, mas não precisa mais mostrar/esconder access_token
            }
            
            // Formatação monetária
            function limparValorMonetario(valor) {
                if (!valor || valor === '') return '';
                // Remove tudo que não é número, vírgula ou ponto
                var limpo = valor.toString().replace(/[^\d,.-]/g, '');
                // Substitui vírgula por ponto
                limpo = limpo.replace(',', '.');
                // Remove múltiplos pontos, mantendo apenas o último
                var partes = limpo.split('.');
                if (partes.length > 2) {
                    limpo = partes[0] + '.' + partes.slice(1).join('');
                }
                return limpo;
            }
            
            function formatarValorMonetario(valor) {
                if (!valor || valor === '' || valor === '0') return '';
                var limpo = limparValorMonetario(valor);
                var numero = parseFloat(limpo);
                if (isNaN(numero) || numero <= 0) return '';
                // Formata como moeda brasileira
                return numero.toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }
            
            // Aplicar formatação monetária aos campos
            function aplicarFormatacaoMonetaria() {
                var camposMoeda = [
                    '#woocommerce_<?php echo esc_js($this->id); ?>_boleto_multa_valor',
                    '#woocommerce_<?php echo esc_js($this->id); ?>_boleto_juros_valor'
                ];
                
                camposMoeda.forEach(function(seletor) {
                    var $campo = $(seletor);
                    if ($campo.length) {
                        // Formatar valor inicial ao carregar
                        var valorInicial = $campo.val();
                        if (valorInicial && valorInicial !== '0' && valorInicial !== '') {
                            var formatado = formatarValorMonetario(valorInicial);
                            if (formatado) {
                                $campo.val(formatado);
                            }
                        }
                        
                        // Formatar ao perder o foco (blur)
                        $campo.on('blur', function() {
                            var $this = $(this);
                            var valor = $this.val();
                            if (valor && valor !== '' && valor !== '0') {
                                var formatado = formatarValorMonetario(valor);
                                if (formatado) {
                                    $this.val(formatado);
                                } else {
                                    $this.val('0,00');
                                }
                            } else if (valor === '' || valor === '0') {
                                $this.val('0,00');
                            }
                        });
                        
                        // Remover formatação ao focar para facilitar edição
                        $campo.on('focus', function() {
                            var $this = $(this);
                            var valor = $this.val();
                            if (valor) {
                                var limpo = limparValorMonetario(valor);
                                $this.val(limpo || '');
                            }
                        });
                        
                        // Permitir apenas números, vírgula e ponto durante digitação
                        $campo.on('keypress', function(e) {
                            var char = String.fromCharCode(e.which);
                            if (!/[0-9,.]/.test(char)) {
                                e.preventDefault();
                                return false;
                            }
                        });
                        
                        // Antes de enviar o formulário, remover formatação
                        $campo.closest('form').on('submit', function() {
                            camposMoeda.forEach(function(sel) {
                                var $c = $(sel);
                                if ($c.length) {
                                    var val = $c.val();
                                    if (val) {
                                        var limpo = limparValorMonetario(val);
                                        $c.val(limpo || '0');
                                    }
                                }
                            });
                        });
                    }
                });
            }
            
            // Melhorar organização visual dos campos de boleto
            function melhorarOrganizacaoBoleto() {
                // Envolver campos monetários com wrapper para prefixo R$
                var camposMoeda = [
                    '#woocommerce_<?php echo esc_js($this->id); ?>_boleto_multa_valor',
                    '#woocommerce_<?php echo esc_js($this->id); ?>_boleto_juros_valor'
                ];
                
                camposMoeda.forEach(function(seletor) {
                    var $campo = $(seletor);
                    if ($campo.length && !$campo.closest('.sicoob-money-field-wrapper').length) {
                        $campo.wrap('<div class="sicoob-money-field-wrapper"></div>');
                    }
                });
                
                // Adicionar classes para melhor agrupamento visual
                var $boletoSection = $('tr[id*="boleto_section_title"]');
                if ($boletoSection.length) {
                    $boletoSection.addClass('boleto-section-title');
                }
                
                // Adicionar classe para seção de Multa
                var $multaTipo = $('tr[id*="boleto_multa_tipo"]');
                if ($multaTipo.length) {
                    $multaTipo.addClass('boleto-subsection');
                }
                
                // Adicionar classe para seção de Juros
                var $jurosTipo = $('tr[id*="boleto_juros_tipo"]');
                if ($jurosTipo.length) {
                    $jurosTipo.addClass('boleto-subsection');
                }
                
                // Melhorar visibilidade dos campos monetários - adicionar prefixo R$ visual
                camposMoeda.forEach(function(seletor) {
                    var $campo = $(seletor);
                    if ($campo.length) {
                        // Garantir que o valor não comece com R$ para evitar duplicação
                        var valor = $campo.val();
                        if (valor && valor.indexOf('R$') === -1 && valor !== '0' && valor !== '0,00' && valor !== '') {
                            // O CSS já adiciona o prefixo visual, mas podemos melhorar o valor exibido
                        }
                    }
                });
                
                // Adicionar espaçamento visual entre grupos de campos relacionados
                var grupos = [
                    { selector: 'tr[id*="boleto_numero_cliente"]', addTop: true },
                    { selector: 'tr[id*="boleto_multa_tipo"]', addTop: true },
                    { selector: 'tr[id*="boleto_juros_tipo"]', addTop: true },
                    { selector: 'tr[id*="benef_final"]', addTop: true }
                ];
                
                grupos.forEach(function(grupo) {
                    var $tr = $(grupo.selector);
                    if ($tr.length && grupo.addTop) {
                        $tr.css('border-top', '2px solid #dcdcde');
                        $tr.find('th').css('padding-top', '20px');
                    }
                });
            }
            
            function atribuirAbas() {
                var $table = $('.sicoob-settings-table');
                if (!$table.length) {
                    return;
                }
                
                var $rows = $table.find('tr');
                if (!$rows.length) {
                    return;
                }

                // Define padrão como "geral" para todas as linhas
                $rows.attr('data-tab', 'geral');
                
                // Marcar tabela como inicializada
                $table.addClass('sicoob-tabs-initialized');

                // FORÇAR marcação por ID antes de qualquer detecção mais complexa
                // - Todos os campos que começam com boleto_ vão para a aba Boleto
                // - Ajustes finos virão depois (ETAPA 2) para mover campos específicos para Conta
                $table.find('tr[id*="boleto_"]').attr('data-tab', 'boleto');
                // Título da seção de boleto
                $table.find('tr[id*="boleto_section_title"]').attr('data-tab', 'boleto');
                // Campos de Conta (PIX e Beneficiário Final) por ID
                $table.find('tr[id*="pix_chave"], tr[id*="pix_expiracao"], tr[id*="benef_final_"]').attr('data-tab', 'conta');

                // ETAPA 1: Encontrar título "Configurações de Boleto" e marcar TODAS as linhas seguintes
                var inBoletoSection = false;
                var boletoSectionStartIndex = -1;
                
                // Primeiro, encontrar onde começa a seção de boleto
                $rows.each(function(index) {
                    var $tr = $(this);
                    var rowText = ($tr.text() || '').toLowerCase();
                    var $th = $tr.find('th');
                    var thText = ($th.text() || '').toLowerCase();
                    var $td = $tr.find('td');
                    var tdText = ($td.text() || '').toLowerCase();
                    var allText = (thText + ' ' + tdText + ' ' + rowText).toLowerCase();
                    
                    // Verificar se é título de seção de Boleto
                    if (allText.indexOf('configurações de boleto') !== -1 || 
                        allText.indexOf('parâmetros usados na emissão do boleto') !== -1) {
                        inBoletoSection = true;
                        boletoSectionStartIndex = index;
                        $tr.attr('data-tab', 'boleto');
                        return false; // break - encontramos o início
                    }
                });
                
                // Se encontrou a seção de boleto, marcar linhas seguintes até encontrar outro título ou campo de conta
                if (inBoletoSection && boletoSectionStartIndex >= 0) {
                    var contaKeywords = ['pix_chave', 'chave pix', 'expiração pix'];
                    
                    $rows.each(function(index) {
                        if (index > boletoSectionStartIndex) {
                            var $tr = $(this);
                            var rowText = ($tr.text() || '').toLowerCase();
                            var $th = $tr.find('th');
                            var thText = ($th.text() || '').toLowerCase();
                            
                            // Verificar se encontrou outro título de seção (th com texto mas sem inputs)
                            var isAnotherTitle = $th.length > 0 && 
                                                $tr.find('input, select, textarea').length === 0 &&
                                                thText.length > 0 &&
                                                thText.indexOf('configurações de boleto') === -1 &&
                                                thText.indexOf('parâmetros usados na emissão do boleto') === -1;
                            
                            // Verificar se é um campo de conta (pix_chave indica início de seção de conta)
                            var isContaField = false;
                            var $inputs = $tr.find('input, select, textarea');
                            $inputs.each(function() {
                                var id = ($(this).attr('id') || '').toLowerCase();
                                var name = ($(this).attr('name') || '').toLowerCase();
                                if (id.indexOf('pix_chave') !== -1 || name.indexOf('pix_chave') !== -1) {
                                    isContaField = true;
                                    return false;
                                }
                            });
                            
                            // Se encontrou outro título ou campo de conta, parar de marcar como boleto
                            if (isAnotherTitle || isContaField) {
                                return false; // break
                            }
                            
                            // Marcar como boleto
                            $tr.attr('data-tab', 'boleto');
                        }
                    });
                }
                
                // ETAPA 2: Procurar pelos IDs/names dos campos diretamente (sobrescreve seção se necessário)
                var contaFieldNames = ['pix_chave', 'pix_expiracao', 'benef_final_cpf_cnpj', 'benef_final_nome', 
                                       'boleto_numero_cliente', 'boleto_codigo_modalidade', 'boleto_numero_conta_corrente', 
                                       'boleto_numero_contrato'];
                
                var boletoFieldNames = ['boleto_max_parcelas', 'boleto_especie_documento', 'boleto_dias_vencimento',
                                        'boleto_multa_tipo', 'boleto_multa_valor', 'boleto_juros_tipo', 'boleto_juros_valor',
                                        'boleto_negativacao_codigo', 'boleto_negativacao_dias', 'boleto_protesto_codigo',
                                        'boleto_protesto_dias', 'boleto_instrucoes', 'boleto_gerar_pdf', 'boleto_rateio_json',
                                        'boleto_cadastrar_pix'];
                
                // Atribuir pela ID/name (isso pode sobrescrever a atribuição por seção se necessário)
                $rows.each(function() {
                    var $tr = $(this);
                    var $inputs = $tr.find('input, select, textarea');
                    var foundTab = null;
                    
                    // Verificar inputs/selects/textarea
                    $inputs.each(function() {
                        var $input = $(this);
                        var id = ($input.attr('id') || '').toLowerCase();
                        var name = ($input.attr('name') || '').toLowerCase();
                        var combined = id + ' ' + name;
                        
                        // Verificar se é campo de conta (verificar primeiro)
                        for (var i = 0; i < contaFieldNames.length; i++) {
                            var fieldName = contaFieldNames[i].toLowerCase();
                            if (combined.indexOf(fieldName) !== -1 || 
                                combined.indexOf('_' + fieldName) !== -1 ||
                                combined.indexOf(fieldName + '_') !== -1 ||
                                id.indexOf(fieldName) !== -1 ||
                                name.indexOf(fieldName) !== -1) {
                                foundTab = 'conta';
                                return false; // break
                            }
                        }
                        
                        // Verificar se é campo de boleto (exceto os que já foram para conta)
                        if (!foundTab) {
                            for (var j = 0; j < boletoFieldNames.length; j++) {
                                var boletoFieldName = boletoFieldNames[j].toLowerCase();
                                // Pular campos que já são de conta
                                if (boletoFieldName.indexOf('numero_cliente') !== -1 ||
                                    boletoFieldName.indexOf('codigo_modalidade') !== -1 ||
                                    boletoFieldName.indexOf('numero_conta_corrente') !== -1 ||
                                    boletoFieldName.indexOf('numero_contrato') !== -1) {
                                    continue;
                                }
                                if (combined.indexOf(boletoFieldName) !== -1 || 
                                    combined.indexOf('_' + boletoFieldName) !== -1 ||
                                    combined.indexOf(boletoFieldName + '_') !== -1 ||
                                    id.indexOf(boletoFieldName) !== -1 ||
                                    name.indexOf(boletoFieldName) !== -1) {
                                    foundTab = 'boleto';
                                    return false; // break
                                }
                            }
                        }
                    });
                    
                    if (foundTab) {
                        $tr.attr('data-tab', foundTab);
                    }
                });
                
                // Depois, atribuir baseado no texto visível das labels/th (para títulos e campos sem input)
                $rows.each(function() {
                    var $tr = $(this);
                    
                    // Se já foi atribuído, pular
                    if ($tr.attr('data-tab') !== 'geral') {
                        return;
                    }
                    
                    // Pular linhas vazias
                    if ($tr.find('th, td').length === 0) {
                        return;
                    }
                    
                    var labelText = ($tr.find('label').text() || '').toLowerCase();
                    var thText = ($tr.find('th').text() || '').toLowerCase();
                    var tdText = ($tr.find('td').text() || '').toLowerCase();
                    var allText = (labelText + ' ' + thText + ' ' + tdText).toLowerCase().trim();
                    
                    if (!allText) {
                        return;
                    }
                    
                    // ABA CONTA: Campos relacionados a conta/beneficiário/PIX (verificar por texto)
                    var contaKeywords = [
                        'chave pix',
                        'expiração pix',
                        'expiração pix (segundos)',
                        'beneficiário final',
                        'beneficiário final - cpf/cnpj',
                        'beneficiário final - nome',
                        'número do cliente',
                        'código da modalidade',
                        'código modalidade',
                        'número da conta corrente',
                        'número conta corrente',
                        'número do contrato',
                        'número contrato cobrança'
                    ];
                    
                    var isConta = false;
                    for (var k = 0; k < contaKeywords.length; k++) {
                        if (allText.indexOf(contaKeywords[k]) !== -1) {
                            isConta = true;
                            break;
                        }
                    }
                    
                    if (isConta) {
                        $tr.attr('data-tab', 'conta');
                        return;
                    }
                    
                    // ABA BOLETO: Configurações específicas de boleto (verificar por texto)
                    // var boletoKeywords = [
                    //     'configurações de boleto',
                    //     'parâmetros usados na emissão do boleto',
                    //     'máximo de parcelas',
                    //     'máximo parcelas',
                    //     'espécie do documento',
                    //     'espécie documento',
                    //     'dias para vencimento',
                    //     'dias vencimento',
                    //     'tipo de multa',
                    //     'tipo multa',
                    //     'valor da multa',
                    //     'valor multa',
                    //     'tipo de juros',
                    //     'tipo juros',
                    //     'tipo juros de mora',
                    //     'juros de mora',
                    //     'valor dos juros',
                    //     'valor juros',
                    //     'valor juros de mora',
                    //     'código de negativação',
                    //     'código negativação',
                    //     'dias para negativação',
                    //     'dias negativação',
                    //     'código de protesto',
                    //     'código protesto',
                    //     'dias para protesto',
                    //     'dias protesto',
                    //     'mensagens/instruções',
                    //     'mensagens instruções',
                    //     'gerar pdf',
                    //     'rateio de créditos',
                    //     'rateio créditos',
                    //     'código cadastrar pix',
                    //     'cadastrar pix'
                    // ];
                    
                    // var isBoleto = false;
                    // for (var l = 0; l < boletoKeywords.length; l++) {
                    //     if (allText.indexOf(boletoKeywords[l]) !== -1) {
                    //         isBoleto = true;
                    //         break;
                    //     }
                    // }
                });
            }

            function ativarTab(tab) {
                if (!tab) {
                    tab = 'geral';
                }

                var $tabs = $('.sicoob-settings-tabs .sicoob-tab');
                var $table = $('.sicoob-settings-table');
                
                if (!$table.length || !$tabs.length) {
                    return;
                }
                
                var $rows = $table.find('tr');
                
                // Atualizar estado visual das abas
                $tabs.each(function() {
                    var $tab = $(this);
                    $tab.removeClass('active');
                    if ($tab.data('tab') === tab) {
                        $tab.addClass('active');
                        $tab.css({
                            'background': '#2271b1',
                            'color': '#fff'
                        });
                    } else {
                        $tab.css({
                            'background': '#f0f0f1',
                            'color': '#3c434a'
                        });
                    }
                });

                // Remover todas as classes de aba ativa da tabela
                $table.removeClass('sicoob-tabs-active-geral sicoob-tabs-active-boleto sicoob-tabs-active-conta');
                
                // Adicionar classe da aba ativa
                $table.addClass('sicoob-tabs-active-' + tab);
                
                // Esconder todas as linhas primeiro
                $rows.each(function() {
                    $(this).hide();
                });
                
                // Mostrar apenas as linhas da aba selecionada
                $rows.filter('[data-tab="' + tab + '"]').each(function() {
                    $(this).show();
                });
            }
            
            $(document).on('change', '#woocommerce_<?php echo esc_js($this->id); ?>_testmode', toggleBlocks);
            $(toggleBlocks);
            
            // Função de inicialização
            function inicializarAbas() {
                var $table = $('.sicoob-settings-table');
                if (!$table.length) {
                    setTimeout(inicializarAbas, 100);
                    return;
                }
                // Evitar múltiplas inicializações
                if ($table.hasClass('sicoob-tabs-finalized')) {
                    return;
                }
                
                // Atribuir abas aos campos
                atribuirAbas();
                
                // Melhorar organização e formatação
                melhorarOrganizacaoBoleto();
                aplicarFormatacaoMonetaria();
                
                // Ativar aba inicial
                var $tabs = $('.sicoob-settings-tabs .sicoob-tab');
                if ($tabs.length) {
                    var tabInicial = $tabs.filter('.active').data('tab') || 'geral';
                    ativarTab(tabInicial);
                    
                    // Adicionar eventos de clique nas abas
                    $('.sicoob-settings-tabs').off('click', '.sicoob-tab').on('click', '.sicoob-tab', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var tab = $(this).data('tab');
                        if (tab) {
                            ativarTab(tab);
                        }
                        return false;
                    });
                } else {
                    setTimeout(inicializarAbas, 200);
                }
                // Marcar como finalizado para não reexecutar
                $table.addClass('sicoob-tabs-finalized');
            }
            
            // Executar quando o documento estiver pronto
            $(document).ready(function() {
                inicializarAbas();
                
                // Re-executar após delay apenas se ainda não finalizado
                setTimeout(function() {
                    var $table = $('.sicoob-settings-table');
                    if (!$table.hasClass('sicoob-tabs-finalized')) {
                        atribuirAbas();
                        melhorarOrganizacaoBoleto();
                        var tabAtual = $('.sicoob-settings-tabs .sicoob-tab.active').data('tab') || 'geral';
                        ativarTab(tabAtual);
                        $table.addClass('sicoob-tabs-finalized');
                    }
                }, 500);
            });
            
            // Executar quando a página for completamente carregada
            $(window).on('load', function() {
                setTimeout(function() {
                    var $table = $('.sicoob-settings-table');
                    if (!$table.hasClass('sicoob-tabs-finalized')) {
                        atribuirAbas();
                        var tabAtual = $('.sicoob-settings-tabs .sicoob-tab.active').data('tab') || 'geral';
                        ativarTab(tabAtual);
                        $table.addClass('sicoob-tabs-finalized');
                    }
                }, 200);
            });
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

            // Persistir no pedido
            $order->update_meta_data('_sicoob_payment_type', $payment_type);
            // Não armazenamos chave/data informadas no checkout, pois são removidas
            if ($pix_nome) {
                $order->update_meta_data('_sicoob_nome', $pix_nome);
            }
            if ($pix_documento) {
                $order->update_meta_data('_sicoob_documento', $pix_documento);
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

            // ===== BOLETO: emitir diretamente conforme documentação =====
            if ($payment_type === 'boleto') {
                $valor_total = (float) $order->get_total();
                $dias_venc   = absint($this->get_option('boleto_dias_vencimento', $this->get_option('boleto_dias_vencimento', 3)));
                $venc_date   = date('Y-m-d', time() + $dias_venc * DAY_IN_SECONDS);
                $emissao     = date('Y-m-d');
                $parcelas    = isset($_POST['sicoob_boleto_parcelas']) ? max(1, intval($_POST['sicoob_boleto_parcelas'])) : 1;

                $benef_cnpj  = preg_replace('/\D+/', '', (string) $this->get_option('benef_final_cpf_cnpj'));
                $benef_nome  = trim((string) $this->get_option('benef_final_nome'));

                // Usar número de cliente 3536700 conforme solicitado
                $numeroCliente        = 3536700;
                $codigoModalidade     = intval($this->get_option('boleto_codigo_modalidade', 1));
                $numeroContaCorrente  = trim($this->get_option('boleto_numero_conta_corrente', '0'));
                
                // Limpar formatação do número da conta corrente (remover hífens, espaços, etc)
                $numeroContaCorrente_limpo = preg_replace('/[^0-9]/', '', $numeroContaCorrente);
                
                // Validar que numeroContaCorrente está preenchido corretamente
                if (empty($numeroContaCorrente_limpo) || $numeroContaCorrente_limpo === '0' || intval($numeroContaCorrente_limpo) === 0) {
                    wc_add_notice(__('O número da conta corrente deve estar configurado corretamente nas configurações do gateway para gerar boletos.', 'sicoob-woocommerce'), 'error');
                    return array(
                        'result' => 'fail',
                        'redirect' => ''
                    );
                }
                
                // Usar o valor limpo (apenas números)
                $numeroContaCorrente = $numeroContaCorrente_limpo;
                $especieDocumento     = $this->get_option('boleto_especie_documento', 'DM');
                $identEmissao         = intval($this->get_option('boleto_ident_emissao', 1));
                $identDistribuicao    = intval($this->get_option('boleto_ident_distribuicao', 1));
                $gerarPdf             = $this->get_option('boleto_gerar_pdf', 'no') === 'yes';

                $multa_tipo  = intval($this->get_option('boleto_multa_tipo', 0));
                $multa_valor_raw = $this->get_option('boleto_multa_valor', '0');
                $multa_valor = (float) $this->limpar_valor_monetario($multa_valor_raw);
                $juros_tipo  = intval($this->get_option('boleto_juros_tipo', 0));
                $juros_valor_raw = $this->get_option('boleto_juros_valor', '0');
                $juros_valor = (float) $this->limpar_valor_monetario($juros_valor_raw);

                $neg_codigo  = intval($this->get_option('boleto_negativacao_codigo', 0));
                $neg_dias    = intval($this->get_option('boleto_negativacao_dias', 0));
                $prot_codigo = intval($this->get_option('boleto_protesto_codigo', 0));
                $prot_dias   = intval($this->get_option('boleto_protesto_dias', 0));

                $cad_pix     = intval($this->get_option('boleto_cadastrar_pix', 0));
                $num_contrato = intval($this->get_option('boleto_numero_contrato', 0));
                // O número do contrato DEVE ser sempre igual ao número do cliente (exigência da API)
                // Se não informado explicitamente, usar sempre o número do cliente
                if ($num_contrato == 0 || $num_contrato != $numeroCliente) {
                    $num_contrato = $numeroCliente;
                }

                // Instruções (máximo 5)
                $instr_lines = array_filter(array_map('trim', explode("\n", (string) $this->get_option('boleto_instrucoes', ''))));
                $instr_lines = array_slice($instr_lines, 0, 5);

                // Capturar dados do pagador do formulário
                $pagador_nome_form = isset($_POST['sicoob_boleto_pagador_nome']) ? trim(sanitize_text_field(wp_unslash($_POST['sicoob_boleto_pagador_nome']))) : '';
                $pagador_doc_form = isset($_POST['sicoob_boleto_pagador_documento']) ? sanitize_text_field(wp_unslash($_POST['sicoob_boleto_pagador_documento'])) : '';
                
                // Identificadores (conforme formato fornecido: strings de 20 caracteres)
                // nossoNumero, seuNumero e identificacaoBoletoEmpresa devem ser strings de 20 caracteres
                $nossoNumero   = str_pad((string) $order_id, 20, '0', STR_PAD_LEFT);
                $seuNumero     = str_pad((string) $order_id, 20, '0', STR_PAD_LEFT);
                $idBoletoEmp   = str_pad((string) $order_id, 20, '0', STR_PAD_LEFT);

                // Datas auxiliares (usar mesmo vencimento por padrão)
                $dataLimite    = $venc_date;
                $dataMulta     = $venc_date;
                $dataJuros     = $venc_date;

                // Rateio (JSON livre opcional)
                $rateio = array();
                $rateio_json = trim((string) $this->get_option('boleto_rateio_json', ''));
                if ($rateio_json) {
                    $parsed = json_decode($rateio_json, true);
                    if (is_array($parsed)) {
                        $rateio = $parsed;
                    }
                }

                // Dados do pagador (usar dados do formulário ou fallback para dados do pedido)
                $pagador_cpf_cnpj = !empty($pagador_doc_form) ? preg_replace('/\D+/', '', $pagador_doc_form) : preg_replace('/\D+/', '', (string) ($order->get_meta('_billing_cpf') ?: $order->get_meta('_billing_cnpj') ?: ''));
                $pagador_nome  = !empty($pagador_nome_form) ? trim($pagador_nome_form) : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                $pagador_email = $order->get_billing_email();
                $pagador_end   = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
                $pagador_bairro= $order->get_meta('_billing_neighborhood') ?: '';
                $pagador_cidade= $order->get_billing_city();
                $pagador_cep   = preg_replace('/\D+/', '', (string) $order->get_billing_postcode());
                $pagador_uf    = $order->get_billing_state();
                
                // Validar que todos os dados de endereço do pagador estão preenchidos (até o bairro)
                if (empty($pagador_end)) {
                    wc_add_notice(__('O endereço do pagador é obrigatório para gerar o boleto. Por favor, preencha todos os dados de endereço no checkout.', 'sicoob-woocommerce'), 'error');
                    return array(
                        'result' => 'fail',
                        'redirect' => ''
                    );
                }
                
                if (empty($pagador_bairro)) {
                    wc_add_notice(__('O bairro do endereço do pagador é obrigatório para gerar o boleto. Por favor, preencha todos os dados de endereço no checkout.', 'sicoob-woocommerce'), 'error');
                    return array(
                        'result' => 'fail',
                        'redirect' => ''
                    );
                }
                
                if (empty($pagador_cidade)) {
                    wc_add_notice(__('A cidade do endereço do pagador é obrigatória para gerar o boleto. Por favor, preencha todos os dados de endereço no checkout.', 'sicoob-woocommerce'), 'error');
                    return array(
                        'result' => 'fail',
                        'redirect' => ''
                    );
                }
                
                if (empty($pagador_cep)) {
                    wc_add_notice(__('O CEP do endereço do pagador é obrigatório para gerar o boleto. Por favor, preencha todos os dados de endereço no checkout.', 'sicoob-woocommerce'), 'error');
                    return array(
                        'result' => 'fail',
                        'redirect' => ''
                    );
                }
                
                if (empty($pagador_uf)) {
                    wc_add_notice(__('O estado (UF) do endereço do pagador é obrigatório para gerar o boleto. Por favor, preencha todos os dados de endereço no checkout.', 'sicoob-woocommerce'), 'error');
                    return array(
                        'result' => 'fail',
                        'redirect' => ''
                    );
                }

                // Montar payload conforme formato fornecido pelo usuário
                // IMPORTANTE: beneficiarioFinal vem ANTES de pagador
                $payload_boleto = array(
                    'numeroCliente' => (int)$numeroCliente,
                    'codigoModalidade' => (int)$codigoModalidade,
                    'numeroContaCorrente' => (int)$numeroContaCorrente,
                    'codigoEspecieDocumento' => (string)$especieDocumento,
                    'dataEmissao' => (string)$emissao,
                    'nossoNumero' => (string)$nossoNumero, // String de 20 caracteres conforme formato
                    'seuNumero' => (string)$seuNumero, // String de 20 caracteres conforme formato
                    'identificacaoBoletoEmpresa' => (string)$idBoletoEmp, // String de 20 caracteres conforme formato
                    'identificacaoEmissaoBoleto' => (int)$identEmissao,
                    'identificacaoDistribuicaoBoleto' => (int)$identDistribuicao,
                    'valor' => (float)$valor_total,
                    'dataVencimento' => (string)$venc_date,
                    'dataLimitePagamento' => (string)$dataLimite,
                    'valorAbatimento' => (float)0,
                    'tipoDesconto' => (int)0,
                    'dataPrimeiroDesconto' => (string)$venc_date,
                    'valorPrimeiroDesconto' => (float)0,
                    'dataSegundoDesconto' => (string)$venc_date,
                    'valorSegundoDesconto' => (float)0,
                    'dataTerceiroDesconto' => (string)$venc_date,
                    'valorTerceiroDesconto' => (float)0,
                    'tipoMulta' => (int)$multa_tipo,
                    'dataMulta' => (string)$dataMulta,
                    'valorMulta' => (float)$multa_valor,
                    'tipoJurosMora' => (int)$juros_tipo,
                    'dataJurosMora' => (string)$dataJuros,
                    'valorJurosMora' => (float)$juros_valor,
                    'numeroParcela' => (int)$parcelas,
                    'aceite' => (bool)true,
                    'codigoProtesto' => (int)$prot_codigo,
                    'numeroDiasProtesto' => (int)$prot_dias,
                );
                
                // Adicionar beneficiarioFinal ANTES de pagador (conforme formato fornecido)
                if (!empty($benef_cnpj) && !empty($benef_nome)) {
                    $payload_boleto['beneficiarioFinal'] = array(
                        'numeroCpfCnpj' => (string)$benef_cnpj,
                        'nome' => (string)$benef_nome,
                    );
                }
                
                // Adicionar pagador (usando dados do formulário)
                $payload_boleto['pagador'] = array(
                    'numeroCpfCnpj' => (string)$pagador_cpf_cnpj,
                    'nome' => (string)$pagador_nome,
                    'endereco' => (string)$pagador_end,
                    'bairro' => (string)$pagador_bairro,
                    'cidade' => (string)$pagador_cidade,
                    'cep' => (string)$pagador_cep,
                    'uf' => (string)$pagador_uf,
                    'email' => (string)$pagador_email,
                );
                
                // Adicionar mensagensInstrucao
                $payload_boleto['mensagensInstrucao'] = is_array($instr_lines) ? $instr_lines : array();
                
                // Adicionar gerarPdf
                $payload_boleto['gerarPdf'] = (bool)$gerarPdf;
                
                // Adicionar rateioCreditos apenas se houver dados
                if (!empty($rateio)) {
                    $payload_boleto['rateioCreditos'] = $rateio;
                }

                // Salvar dados do pagador no pedido para referência
                if ($pagador_nome_form) {
                    $order->update_meta_data('_sicoob_boleto_pagador_nome', $pagador_nome_form);
                }
                if ($pagador_doc_form) {
                    $order->update_meta_data('_sicoob_boleto_pagador_documento', preg_replace('/\D+/', '', $pagador_doc_form));
                }
                $order->save();

                $resp_boleto = $api->boleto_emitir($payload_boleto);
                // Guardar dados retornados
                $order->update_meta_data('_sicoob_boleto_emissao', $resp_boleto);
                $order->update_status('pending', __('Boleto emitido. Aguardando pagamento.', 'sicoob-woocommerce'));
                $order->save();

                // Se houver URL de PDF ou linha digitável, salvar e exibir em obrigado (futuro)
                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            }

            // ===== CARTÃO (fluxo anterior) =====
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

            // Se o usuário escolheu BOLETO, ajustar o método e dados específicos
            if ($payment_type === 'boleto') {
                $payment_data['payment_method'] = 'BOLETO';
                // Dados opcionais do boleto (vencimento e instruções). Ajuste conforme sua política.
                $payment_data['boleto'] = array(
                    'dueDate' => date('Y-m-d', time() + 3 * DAY_IN_SECONDS),
                    // 'instructions' => __('Pague até o vencimento.', 'sicoob-woocommerce'),
                );
            }

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
