<?php
/**
 * Classe de Administração do Plugin Sicoob
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe Sicoob_Admin
 */
class Sicoob_Admin
{
    /**
     * Construtor
     */
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_post_sicoob_save_settings', array($this, 'save_settings'));
        // AJAX seguro para testar obtenção de token
        add_action('wp_ajax_sicoob_request_token', array($this, 'ajax_request_token'));
    }

    /**
     * Adicionar menu administrativo
     */
    public function add_admin_menu()
    {
        add_menu_page(
            __('Sicoob', 'sicoob-woocommerce'),
            __('Sicoob', 'sicoob-woocommerce'),
            'manage_woocommerce',
            'sicoob-settings',
            array($this, 'render_admin_page'),
            SICOOB_WC_PLUGIN_URL . 'assets/images/sicoob-logo.png',
            56
        );
    }

    /**
     * Registrar configurações
     */
    public function register_settings()
    {
        register_setting('sicoob_settings_group', 'sicoob_settings');
        // Opções protegidas (armazenadas criptografadas)
        register_setting('sicoob_settings_group', 'sicoob_pfx_blob');
        register_setting('sicoob_settings_group', 'sicoob_pfx_password_enc');
    }

    /**
     * Enfileirar assets do admin
     */
    public function enqueue_admin_assets($hook)
    {
        if ($hook !== 'toplevel_page_sicoob-settings') {
            return;
        }

        wp_enqueue_style(
            'sicoob-admin-css',
            SICOOB_WC_PLUGIN_URL . 'assets/css/admin-sicoob.css',
            array(),
            SICOOB_WC_VERSION
        );

        wp_enqueue_script(
            'sicoob-admin-js',
            SICOOB_WC_PLUGIN_URL . 'assets/js/admin-sicoob.js',
            array('jquery'),
            SICOOB_WC_VERSION,
            true
        );

        wp_localize_script('sicoob-admin-js', 'sicoobAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sicoob_admin_nonce'),
            'strings' => array(
                'copied' => __('Copiado!', 'sicoob-woocommerce'),
                'copy' => __('Copiar', 'sicoob-woocommerce'),
                'error' => __('Erro ao copiar', 'sicoob-woocommerce'),
            )
        ));
    }

    /**
     * Renderizar página administrativa
     */
    public function render_admin_page()
    {
        // Obter configurações salvas
        $settings = get_option('sicoob_settings', array());
        $gateway_settings = get_option('woocommerce_sicoob_settings', array());
        
        // Valores padrão
        $defaults = array(
            'nome_cooperado' => $settings['nome_cooperado'] ?? '',
            'cooperativa' => $settings['cooperativa'] ?? '4027',
            'conta_corrente' => $settings['conta_corrente'] ?? '',
            'conta_poupanca' => $settings['conta_poupanca'] ?? 'no',
            'empresa_parceira' => $settings['empresa_parceira'] ?? 'no',
            'client_id' => $gateway_settings['client_id'] ?? '',
            'secret_id' => $gateway_settings['client_secret'] ?? '',
            'token_url' => 'https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token',
            'enabled' => $gateway_settings['enabled'] ?? 'no',
            // Escopos API Cobrança Bancária
            'boletos_consulta' => $settings['boletos_consulta'] ?? 'yes',
            'boletos_inclusao' => $settings['boletos_inclusao'] ?? 'yes',
            'boletos_alteracao' => $settings['boletos_alteracao'] ?? 'yes',
            'webhooks_inclusao' => $settings['webhooks_inclusao'] ?? 'yes',
            'webhooks_consulta' => $settings['webhooks_consulta'] ?? 'yes',
            'webhooks_alteracao' => $settings['webhooks_alteracao'] ?? 'yes',
            // Escopos API Pagamentos
            'pagamentos_inclusao' => $settings['pagamentos_inclusao'] ?? 'yes',
            'pagamentos_alteracao' => $settings['pagamentos_alteracao'] ?? 'yes',
            'pagamentos_consulta' => $settings['pagamentos_consulta'] ?? 'yes',
        );

        ?>
        <div class="wrap sicoob-admin-wrap">
            <div class="sicoob-header">
                <h1>
                    <?php echo esc_html__('Sicoob', 'sicoob-woocommerce'); ?>
                    <span class="sicoob-subtitle"><?php echo esc_html__('(Pagamento site)', 'sicoob-woocommerce'); ?></span>
                </h1>
                <div class="sicoob-status">
                    <span class="sicoob-status-label"><?php echo esc_html__('Ativo', 'sicoob-woocommerce'); ?></span>
                    <span class="sicoob-status-indicator <?php echo $defaults['enabled'] === 'yes' ? 'active' : ''; ?>"></span>
                </div>
            </div>

            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Configurações salvas com sucesso!', 'sicoob-woocommerce'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sicoob-form">
                <?php wp_nonce_field('sicoob_save_settings', 'sicoob_nonce'); ?>
                <input type="hidden" name="action" value="sicoob_save_settings">

                <!-- Dados Gerais -->
                <div class="sicoob-section">
                    <h2 class="sicoob-section-title"><?php echo esc_html__('Dados Gerais', 'sicoob-woocommerce'); ?></h2>
                    
                    <table class="form-table sicoob-form-table">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="nome_cooperado"><?php echo esc_html__('Nome do cooperado', 'sicoob-woocommerce'); ?></label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="nome_cooperado" 
                                           name="sicoob_settings[nome_cooperado]" 
                                           value="<?php echo esc_attr($defaults['nome_cooperado']); ?>" 
                                           class="regular-text">
                                </td>
                                <th scope="row">
                                    <label for="cooperativa"><?php echo esc_html__('Cooperativa', 'sicoob-woocommerce'); ?></label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="cooperativa" 
                                           name="sicoob_settings[cooperativa]" 
                                           value="<?php echo esc_attr($defaults['cooperativa']); ?>" 
                                           class="regular-text">
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="conta_corrente"><?php echo esc_html__('Conta corrente', 'sicoob-woocommerce'); ?></label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="conta_corrente" 
                                           name="sicoob_settings[conta_corrente]" 
                                           value="<?php echo esc_attr($defaults['conta_corrente']); ?>" 
                                           class="regular-text">
                                </td>
                                <th scope="row">
                                    <label for="conta_poupanca"><?php echo esc_html__('Conta poupança', 'sicoob-woocommerce'); ?></label>
                                </th>
                                <td>
                                    <label class="sicoob-checkbox-label">
                                        <input type="checkbox" 
                                               id="conta_poupanca" 
                                               name="sicoob_settings[conta_poupanca]" 
                                               value="yes"
                                               <?php checked($defaults['conta_poupanca'], 'yes'); ?>>
                                        <?php echo esc_html__('Não', 'sicoob-woocommerce'); ?>
                                    </label>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="empresa_parceira"><?php echo esc_html__('Empresa parceira', 'sicoob-woocommerce'); ?></label>
                                </th>
                                <td>
                                    <label class="sicoob-checkbox-label">
                                        <input type="checkbox" 
                                               id="empresa_parceira" 
                                               name="sicoob_settings[empresa_parceira]" 
                                               value="yes"
                                               <?php checked($defaults['empresa_parceira'], 'yes'); ?>>
                                        <?php echo esc_html__('Não', 'sicoob-woocommerce'); ?>
                                    </label>
                                </td>
                                <th scope="row">
                                    <label for="descricao_aplicativo"><?php echo esc_html__('Descrição do aplicativo', 'sicoob-woocommerce'); ?></label>
                                </th>
                                <td>
                                    <span class="sicoob-readonly-text">
                                        <?php echo esc_html__('API vinculada ao site para pagamentos dos clientes', 'sicoob-woocommerce'); ?>
                                    </span>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="client_id"><?php echo esc_html__('Client ID', 'sicoob-woocommerce'); ?></label>
                                </th>
                                <td colspan="3">
                                    <div class="sicoob-input-group">
                                        <input type="text" 
                                               id="client_id" 
                                               name="sicoob_settings[client_id]" 
                                               value="<?php echo esc_attr($defaults['client_id']); ?>" 
                                               class="large-text" 
                                               readonly>
                                        <button type="button" class="button sicoob-copy-btn" data-copy-target="client_id">
                                            <span class="dashicons dashicons-clipboard"></span>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="secret_id"><?php echo esc_html__('Secret ID', 'sicoob-woocommerce'); ?></label>
                                </th>
                                <td colspan="3">
                                    <input type="text" 
                                           id="secret_id" 
                                           value="" 
                                           class="large-text" 
                                           readonly>
                                    <p class="description">
                                        <?php echo esc_html__('Não é necessário para utilizar as APIs do Sicoob.', 'sicoob-woocommerce'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="token_url"><?php echo esc_html__('Geração do access token', 'sicoob-woocommerce'); ?></label>
                                </th>
                                <td colspan="3">
                                    <div class="sicoob-input-group">
                                        <input type="text" 
                                               id="token_url" 
                                               value="<?php echo esc_attr($defaults['token_url']); ?>" 
                                               class="large-text" 
                                               readonly>
                                        <button type="button" class="button sicoob-copy-btn" data-copy-target="token_url">
                                            <span class="dashicons dashicons-clipboard"></span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Certificado PFX (produção) -->
                <div class="sicoob-section">
                    <h2 class="sicoob-section-title"><?php echo esc_html__('Certificado PFX (Produção)', 'sicoob-woocommerce'); ?></h2>
                    <p class="description"><?php echo esc_html__('Para gerar access token em produção é necessário enviar o PFX e senha.', 'sicoob-woocommerce'); ?></p>

                    <table class="form-table sicoob-form-table">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="sicoob_pfx_file"><?php echo esc_html__('Arquivo PFX', 'sicoob-woocommerce'); ?></label>
                                </th>
                                <td>
                                    <input type="file" id="sicoob_pfx_file" name="sicoob_pfx_file" accept=".pfx,.p12" />
                                    <?php $has_pfx = get_option('sicoob_pfx_blob') ? true : false; ?>
                                    <?php if ($has_pfx): ?>
                                        <p class="description" style="margin-top:6px;">
                                            <?php echo esc_html__('Um certificado PFX já está armazenado (criptografado). Envie um novo para substituir.', 'sicoob-woocommerce'); ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="sicoob_pfx_password"><?php echo esc_html__('Senha do Certificado PFX', 'sicoob-woocommerce'); ?></label>
                                </th>
                                <td>
                                    <?php $has_password = get_option('sicoob_pfx_password_enc') ? true : false; ?>
                                    <input type="password" 
                                           id="sicoob_pfx_password" 
                                           name="sicoob_pfx_password" 
                                           class="regular-text" 
                                           placeholder="<?php echo esc_attr__('Digite a senha do certificado PFX', 'sicoob-woocommerce'); ?>" 
                                           autocomplete="off" />
                                    <?php if ($has_password): ?>
                                        <p class="description" style="color: #46b450; margin-top: 6px;">
                                            <span class="dashicons dashicons-yes-alt" style="vertical-align: middle;"></span>
                                            <?php echo esc_html__('Senha já configurada. Deixe em branco para manter a atual ou digite uma nova para alterar.', 'sicoob-woocommerce'); ?>
                                        </p>
                                    <?php else: ?>
                                        <p class="description" style="color: #dc3232; margin-top: 6px;">
                                            <span class="dashicons dashicons-warning" style="vertical-align: middle;"></span>
                                            <?php echo esc_html__('Senha não configurada. Configure a senha do certificado PFX para autenticação em produção.', 'sicoob-woocommerce'); ?>
                                        </p>
                                    <?php endif; ?>
                                    <p class="description" style="margin-top: 6px;">
                                        <?php echo esc_html__('A senha será armazenada de forma criptografada no banco de dados.', 'sicoob-woocommerce'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- APIs -->
                <div class="sicoob-section">
                    <h2 class="sicoob-section-title"><?php echo esc_html__('APIs', 'sicoob-woocommerce'); ?></h2>
                    
                    <div class="sicoob-api-cards">
                        <!-- API 1: Cobrança Bancária -->
                        <div class="sicoob-api-card">
                            <div class="sicoob-api-header">
                                <h3 class="sicoob-api-title">
                                    <?php echo esc_html__('1 - Cobrança Bancária', 'sicoob-woocommerce'); ?>
                                </h3>
                            </div>
                            
                            <div class="sicoob-api-body">
                                <div class="sicoob-api-url">
                                    <strong><?php echo esc_html__('URL:', 'sicoob-woocommerce'); ?></strong>
                                    <code>https://api.sicoob.com.br/cobranca-bancaria/v3</code>
                                </div>

                                <div class="sicoob-api-scopes">
                                    <strong><?php echo esc_html__('Escopos da API:', 'sicoob-woocommerce'); ?></strong>
                                    
                                    <div class="sicoob-scope-item">
                                        <label>
                                            <input type="checkbox" 
                                                   name="sicoob_settings[boletos_consulta]" 
                                                   value="yes"
                                                   <?php checked($defaults['boletos_consulta'], 'yes'); ?>>
                                            <span class="sicoob-scope-name">boletos_consulta:</span>
                                            <span class="sicoob-scope-description"><?php echo esc_html__('Permite realizar consultas de um boleto', 'sicoob-woocommerce'); ?></span>
                                        </label>
                                    </div>

                                    <div class="sicoob-scope-item">
                                        <label>
                                            <input type="checkbox" 
                                                   name="sicoob_settings[boletos_inclusao]" 
                                                   value="yes"
                                                   <?php checked($defaults['boletos_inclusao'], 'yes'); ?>>
                                            <span class="sicoob-scope-name">boletos_inclusao:</span>
                                            <span class="sicoob-scope-description"><?php echo esc_html__('Permite realizar operações de inclusão de um boleto, negativação e protesto', 'sicoob-woocommerce'); ?></span>
                                        </label>
                                    </div>

                                    <div class="sicoob-scope-item">
                                        <label>
                                            <input type="checkbox" 
                                                   name="sicoob_settings[boletos_alteracao]" 
                                                   value="yes"
                                                   <?php checked($defaults['boletos_alteracao'], 'yes'); ?>>
                                            <span class="sicoob-scope-name">boletos_alteracao:</span>
                                            <span class="sicoob-scope-description"><?php echo esc_html__('Permite realizar alterações de um boleto', 'sicoob-woocommerce'); ?></span>
                                        </label>
                                    </div>

                                    <div class="sicoob-scope-item">
                                        <label>
                                            <input type="checkbox" 
                                                   name="sicoob_settings[webhooks_inclusao]" 
                                                   value="yes"
                                                   <?php checked($defaults['webhooks_inclusao'], 'yes'); ?>>
                                            <span class="sicoob-scope-name">webhooks_inclusao:</span>
                                            <span class="sicoob-scope-description"><?php echo esc_html__('Permite realizar operações de inclusão de um webhook', 'sicoob-woocommerce'); ?></span>
                                        </label>
                                    </div>

                                    <div class="sicoob-scope-item">
                                        <label>
                                            <input type="checkbox" 
                                                   name="sicoob_settings[webhooks_consulta]" 
                                                   value="yes"
                                                   <?php checked($defaults['webhooks_consulta'], 'yes'); ?>>
                                            <span class="sicoob-scope-name">webhooks_consulta:</span>
                                            <span class="sicoob-scope-description"><?php echo esc_html__('Permite realizar consultas de um webhook', 'sicoob-woocommerce'); ?></span>
                                        </label>
                                    </div>

                                    <div class="sicoob-scope-item">
                                        <label>
                                            <input type="checkbox" 
                                                   name="sicoob_settings[webhooks_alteracao]" 
                                                   value="yes"
                                                   <?php checked($defaults['webhooks_alteracao'], 'yes'); ?>>
                                            <span class="sicoob-scope-name">webhooks_alteracao:</span>
                                            <span class="sicoob-scope-description"><?php echo esc_html__('Permite realizar alterações de um webhook', 'sicoob-woocommerce'); ?></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- API 2: Cobrança Bancária Pagamentos -->
                        <div class="sicoob-api-card">
                            <div class="sicoob-api-header">
                                <h3 class="sicoob-api-title">
                                    <?php echo esc_html__('2 - Cobrança Bancária Pagamentos', 'sicoob-woocommerce'); ?>
                                </h3>
                            </div>
                            
                            <div class="sicoob-api-body">
                                <div class="sicoob-api-url">
                                    <strong><?php echo esc_html__('URL:', 'sicoob-woocommerce'); ?></strong>
                                    <code>https://api.sicoob.com.br/pagamentos/v3</code>
                                </div>

                                <div class="sicoob-api-scopes">
                                    <strong><?php echo esc_html__('Escopos da API:', 'sicoob-woocommerce'); ?></strong>
                                    
                                    <div class="sicoob-scope-item">
                                        <label>
                                            <input type="checkbox" 
                                                   name="sicoob_settings[pagamentos_inclusao]" 
                                                   value="yes"
                                                   <?php checked($defaults['pagamentos_inclusao'], 'yes'); ?>>
                                            <span class="sicoob-scope-name">pagamentos_inclusao:</span>
                                            <span class="sicoob-scope-description"><?php echo esc_html__('Escopo de inclusão para pagamentos de boletos', 'sicoob-woocommerce'); ?></span>
                                        </label>
                                    </div>

                                    <div class="sicoob-scope-item">
                                        <label>
                                            <input type="checkbox" 
                                                   name="sicoob_settings[pagamentos_alteracao]" 
                                                   value="yes"
                                                   <?php checked($defaults['pagamentos_alteracao'], 'yes'); ?>>
                                            <span class="sicoob-scope-name">pagamentos_alteracao:</span>
                                            <span class="sicoob-scope-description"><?php echo esc_html__('Escopo de alteração para pagamentos de boletos', 'sicoob-woocommerce'); ?></span>
                                        </label>
                                    </div>

                                    <div class="sicoob-scope-item">
                                        <label>
                                            <input type="checkbox" 
                                                   name="sicoob_settings[pagamentos_consulta]" 
                                                   value="yes"
                                                   <?php checked($defaults['pagamentos_consulta'], 'yes'); ?>>
                                            <span class="sicoob-scope-name">pagamentos_consulta:</span>
                                            <span class="sicoob-scope-description"><?php echo esc_html__('Escopo de consulta para pagamentos de boletos', 'sicoob-woocommerce'); ?></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php submit_button(__('Salvar Configurações', 'sicoob-woocommerce'), 'primary large', 'submit', true); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Salvar configurações
     */
    public function save_settings()
    {
        // Verificar nonce
        if (!isset($_POST['sicoob_nonce']) || !wp_verify_nonce($_POST['sicoob_nonce'], 'sicoob_save_settings')) {
            wp_die(__('Ação não autorizada.', 'sicoob-woocommerce'));
        }

        // Verificar permissões
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Você não tem permissão para acessar esta página.', 'sicoob-woocommerce'));
        }

        // Sanitizar e salvar dados
        $settings = isset($_POST['sicoob_settings']) ? $_POST['sicoob_settings'] : array();
        
        $sanitized_settings = array(
            'nome_cooperado' => sanitize_text_field($settings['nome_cooperado'] ?? ''),
            'cooperativa' => sanitize_text_field($settings['cooperativa'] ?? '4027'),
            'conta_corrente' => sanitize_text_field($settings['conta_corrente'] ?? ''),
            'conta_poupanca' => isset($settings['conta_poupanca']) ? 'yes' : 'no',
            'empresa_parceira' => isset($settings['empresa_parceira']) ? 'yes' : 'no',
            'client_id' => sanitize_text_field($settings['client_id'] ?? ''),
            // Escopos
            'boletos_consulta' => isset($settings['boletos_consulta']) ? 'yes' : 'no',
            'boletos_inclusao' => isset($settings['boletos_inclusao']) ? 'yes' : 'no',
            'boletos_alteracao' => isset($settings['boletos_alteracao']) ? 'yes' : 'no',
            'webhooks_inclusao' => isset($settings['webhooks_inclusao']) ? 'yes' : 'no',
            'webhooks_consulta' => isset($settings['webhooks_consulta']) ? 'yes' : 'no',
            'webhooks_alteracao' => isset($settings['webhooks_alteracao']) ? 'yes' : 'no',
            'pagamentos_inclusao' => isset($settings['pagamentos_inclusao']) ? 'yes' : 'no',
            'pagamentos_alteracao' => isset($settings['pagamentos_alteracao']) ? 'yes' : 'no',
            'pagamentos_consulta' => isset($settings['pagamentos_consulta']) ? 'yes' : 'no',
        );

        // Salvar configurações
        update_option('sicoob_settings', $sanitized_settings);

        // Sincronizar com configurações do gateway
        $gateway_settings = get_option('woocommerce_sicoob_settings', array());
        $gateway_settings['client_id'] = $sanitized_settings['client_id'];
        update_option('woocommerce_sicoob_settings', $gateway_settings);

        // Upload PFX (opcional)
        if (!empty($_FILES['sicoob_pfx_file']['name']) && is_uploaded_file($_FILES['sicoob_pfx_file']['tmp_name'])) {
            $pfx_contents = file_get_contents($_FILES['sicoob_pfx_file']['tmp_name']);
            if ($pfx_contents !== false) {
                // Criptografar blob com chave derivada do AUTH_KEY do WP
        $encrypted = self::encrypt_secret($pfx_contents);
                if ($encrypted) {
                    update_option('sicoob_pfx_blob', $encrypted, false);
                }
            }
        }

        // Senha PFX (opcional - sempre atualiza se fornecida)
        if (isset($_POST['sicoob_pfx_password'])) {
            $pfx_password = trim($_POST['sicoob_pfx_password']);
            if (!empty($pfx_password)) {
                // Criptografar e salvar a nova senha
                $pass_enc = self::encrypt_secret($pfx_password);
                if ($pass_enc) {
                    update_option('sicoob_pfx_password_enc', $pass_enc, false);
                }
            }
            // Se o campo estiver vazio mas foi enviado, não altera (mantém a senha atual)
            // Para remover a senha, seria necessário um campo específico de "limpar senha"
        }

        // Redirecionar de volta com mensagem de sucesso
        wp_redirect(add_query_arg(
            array(
                'page' => 'sicoob-settings',
                'settings-updated' => 'true'
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Criptografa dados sensíveis usando libsodium (se disponível) ou OpenSSL como fallback.
     */
    public static function encrypt_secret($plain)
    {
        $key = wp_salt('auth');
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, substr(hash('sha256', $key, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            return base64_encode($nonce . $cipher);
        }
        // OpenSSL fallback AES-256-GCM
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return '';
        }
        return base64_encode($iv . $tag . $cipher);
    }

    /**
     * Descriptografa dado armazenado
     */
    public static function decrypt_secret($blob)
    {
        if (!$blob) return '';
        $raw = base64_decode($blob, true);
        if ($raw === false) return '';
        $key = wp_salt('auth');
        if (function_exists('sodium_crypto_secretbox_open')) {
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = @sodium_crypto_secretbox_open($cipher, $nonce, substr(hash('sha256', $key, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            return $plain === false ? '' : $plain;
        }
        // OpenSSL fallback
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    /**
     * AJAX para tentar obter um access_token (teste)
     */
    public function ajax_request_token()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        $pfx_blob = get_option('sicoob_pfx_blob');
        $pfx_pass_enc = get_option('sicoob_pfx_password_enc');
        $pfx_pass = $this->decrypt_secret($pfx_pass_enc);
        // Usar função helper se disponível, senão usar wp_tempnam() nativo
        if (function_exists('sicoob_wp_tempnam')) {
            $tmp = sicoob_wp_tempnam('sicoob_pfx');
        } elseif (function_exists('wp_tempnam')) {
            $tmp = wp_tempnam('sicoob_pfx');
        } else {
            // Fallback manual
            $dir = get_temp_dir();
            $tmp = $dir . 'sicoob_pfx_' . uniqid() . '.tmp';
            @touch($tmp);
        }
        if ($pfx_blob && $tmp) {
            file_put_contents($tmp, $this->decrypt_secret($pfx_blob));
        }

        $settings = get_option('sicoob_settings', array());
        $client_id = $settings['client_id'] ?? '';

        // Monta chamada com cURL direto (form-urlencoded)
        $ch = curl_init('https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(array(
                'client_id' => $client_id,
                'grant_type' => 'client_credentials',
                'scope' => 'cob.write cob.read cobv.write cobv.read pix.write pix.read webhook.read webhook.write payloadlocation.read payloadlocation.write'
            )),
            CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSLCERT => $tmp,
            CURLOPT_SSLCERTPASSWD => $pfx_pass,
        ));
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($tmp);
        if ($err) {
            wp_send_json_error($err);
        }
        wp_send_json(array('code' => $code, 'body' => json_decode($resp, true)));
    }
}

