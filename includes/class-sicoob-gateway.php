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
        // Documento (CNPJ)
        $doc_padrao = isset($_POST['sicoob_documento']) ? esc_attr(wp_unslash($_POST['sicoob_documento'])) : '';
        echo '<p class="form-row form-row-first">';
        echo '<label for="sicoob_documento">' . esc_html__('CNPJ (obrigatório)', 'sicoob-woocommerce') . '</label>';
        echo '<input id="sicoob_documento" name="sicoob_documento" type="text" inputmode="numeric" pattern="[0-9\.\-/\s]*" value="' . $doc_padrao . '" placeholder="00.000.000/0000-00" required />';
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
        // Script (toggle + máscara de CNPJ)
        echo '<script>(function(){function m(v){v=(v||"").replace(/\D/g,"").slice(0,14);v=v.replace(/^(\d{2})(\d)/,"$1.$2");v=v.replace(/^(\d{2}\.\d{3})(\d)/,"$1.$2");v=v.replace(/^(\d{2}\.\d{3}\.\d{3})(\d)/,"$1/$2");v=v.replace(/^(\d{2}\.\d{3}\.\d{3}\/\d{4})(\d)/,"$1-$2");return v;}document.addEventListener("change",function(e){if(e.target&&e.target.name==="sicoob_payment_type"){var t=e.target.value;var p=document.querySelector(".sicoob-section-pix");var b=document.querySelector(".sicoob-section-boleto");if(p&&b){if(t==="pix"){p.style.display="";b.style.display="none";}else{p.style.display="none";b.style.display="";}}}});var d=document.getElementById("sicoob_documento");if(d){d.addEventListener("input",function(){this.value=m(this.value);});}})();</script>';
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
            $cnpj_num = preg_replace('/\D+/', '', $doc);
            if (strlen($cnpj_num) !== 14) {
                wc_add_notice(__('Informe um CNPJ válido com 14 dígitos.', 'sicoob-woocommerce'), 'error');
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
            // // Token Produção via PFX
            // // Grupo Produção (mTLS)
            // 'mtls_section_title' => array(
            //     'title' => __('Produção (mTLS) - obrigatório para PIX em produção', 'sicoob-woocommerce'),
            //     'type' => 'title',
            //     'description' => __('Informe os caminhos absolutos dos arquivos de certificado e chave do cliente fornecidos pelo Sicoob. Em sandbox, deixe em branco.', 'sicoob-woocommerce'),
            // ),
            // 'app_key' => array(
            //     'title' => __('X-Developer-Application-Key (opcional)', 'sicoob-woocommerce'),
            //     'type' => 'text',
            //     'description' => __('Alguns ambientes exigem a chave de aplicativo do desenvolvedor.', 'sicoob-woocommerce'),
            //     'default' => '',
            //     'desc_tip' => true,
            // ),
            // 'mtls_cert_path' => array(
            //     'title' => __('Caminho do Certificado (SSL Cert)', 'sicoob-woocommerce'),
            //     'type' => 'text',
            //     'description' => __('Ex.: C:/laragon/www/certs/sicoob/client_cert.pem', 'sicoob-woocommerce'),
            //     'default' => '',
            //     'desc_tip' => true,
            // ),
            // 'mtls_key_path' => array(
            //     'title' => __('Caminho da Chave (SSL Key)', 'sicoob-woocommerce'),
            //     'type' => 'text',
            //     'description' => __('Ex.: C:/laragon/www/certs/sicoob/client_key.pem', 'sicoob-woocommerce'),
            //     'default' => '',
            //     'desc_tip' => true,
            // ),
            // 'mtls_key_pass' => array(
            //     'title' => __('Senha da Chave (se houver)', 'sicoob-woocommerce'),
            //     'type' => 'password',
            //     'description' => __('Senha da chave privada, se protegida por senha.', 'sicoob-woocommerce'),
            //     'default' => '',
            //     'desc_tip' => true,
            // ),
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
                $doc = $pix_documento ?: ($order->get_meta('_billing_cnpj') ?: '');
                $chave = $this->get_option('pix_chave');
                $expiracao = absint($this->get_option('pix_expiracao', '3600')) ?: 3600;

                if (!$chave) {
                    throw new Exception(__('Chave PIX do recebedor não configurada.', 'sicoob-woocommerce'));
                }

                $api->pix_criar_cobranca_imediata($txid, $order->get_total(), $nome, $doc, $chave, $expiracao);
                $qr_image = $api->pix_obter_qr_code_imagem($txid, 360);

                $order->update_meta_data('_sicoob_pix_txid', $txid);
                $order->update_meta_data('_sicoob_pix_qr_image', $qr_image);
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
            wc_add_notice(__('Erro no pagamento: ', 'sicoob-woocommerce') . $e->getMessage(), 'error');
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
        echo '<section class="woocommerce-order">';
        echo '<h2>' . esc_html__('Pague via PIX', 'sicoob-woocommerce') . '</h2>';
        echo '<p>' . esc_html__('Leia o QR Code abaixo no seu app do banco. A cobrança expira em breve.', 'sicoob-woocommerce') . '</p>';
        echo '<img alt="PIX QR Code" style="max-width:280px;height:auto;border:1px solid #eee;padding:8px;background:#fff" src="' . esc_attr($qr) . '" />';
        echo '<p id="sicoob-pix-status" style="margin-top:8px">' . esc_html__('Aguardando pagamento...', 'sicoob-woocommerce') . '</p>';
        echo '<script>!function(){function p(){var x=new XMLHttpRequest();x.open("POST","' . esc_url(admin_url('admin-ajax.php')) . '");x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");x.onload=function(){try{var r=JSON.parse(x.responseText)||{};if(r.status==="CONCLUIDA"||r.status==="approved"){document.getElementById("sicoob-pix-status").innerText="' . esc_js(__('Pagamento confirmado!', 'sicoob-woocommerce')) . '";window.location.reload();return;}if(r.status){document.getElementById("sicoob-pix-status").innerText="' . esc_js(__('Status: ', 'sicoob-woocommerce')) . '"+r.status;}setTimeout(p,4000);}catch(e){setTimeout(p,5000);}};x.send("action=sicoob_pix_status&order_id=' . intval($order_id) . '&txid=' . rawurlencode($txid) . '");}setTimeout(p,4000);}();</script>';
        echo '</section>';
    }

    /**
     * AJAX: consulta status da cobrança PIX e retorna JSON.
     */
    public function ajax_pix_status()
    {
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $txid = isset($_POST['txid']) ? sanitize_text_field(wp_unslash($_POST['txid'])) : '';
        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order || !$txid) {
            wp_send_json(array('ok' => false));
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
            $cob = $api->pix_obter_cobranca($txid);
            $status = $cob['status'] ?? '';
            if ($status === 'CONCLUIDA' || $status === 'approved') {
                $order->payment_complete($txid);
                $order->add_order_note(__('Pagamento PIX confirmado.', 'sicoob-woocommerce'));
            }
            wp_send_json(array('ok' => true, 'status' => $status));
        } catch (Exception $e) {
            wp_send_json(array('ok' => false, 'error' => $e->getMessage()));
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
     */
    public function webhook_handler()
    {
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
    private function verify_webhook_signature($payload, $signature)
    {
        $expected_signature = hash_hmac('sha256', $payload, $this->get_option('webhook_secret'));
        return hash_equals($expected_signature, $signature);
    }

    /**
     * Processar dados do webhook
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
