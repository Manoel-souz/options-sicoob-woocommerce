<?php
/**
 * Arquivo de teste para o Sandbox do Sicoob
 * 
 * IMPORTANTE: Este arquivo é apenas para testes. NÃO use em produção!
 * 
 * Para usar:
 * 1. Coloque este arquivo na raiz do seu WordPress
 * 2. Acesse: https://seusite.com/test-sicoob-sandbox.php
 * 3. Verifique os resultados
 * 4. DELETE este arquivo após os testes
 */

// Verificar se o WordPress está carregado
if (!defined('ABSPATH')) {
    // Carregar WordPress
    require_once('wp-load.php');
}

// Verificar se o plugin está ativo
if (!class_exists('Sicoob_API')) {
    die('Plugin Sicoob não está ativo. Ative-o primeiro.');
}

// Configurações do sandbox
$client_id = '9b5e603e428cc477a2841e2683c92d21';
$client_secret = 'SEU_CLIENT_SECRET_AQUI'; // Substitua pelo seu Client Secret
$environment = 'sandbox';

echo "<h1>🧪 Teste do Sandbox Sicoob</h1>";
echo "<p><strong>Ambiente:</strong> {$environment}</p>";
echo "<p><strong>Client ID:</strong> {$client_id}</p>";
echo "<hr>";

try {
    // Criar instância da API
    $api = new Sicoob_API($client_id, $client_secret, $environment);
    
    echo "<h2>1. Teste de Conexão</h2>";
    
    // Testar conexão
    $connection_test = $api->test_connection();
    
    if ($connection_test['success']) {
        echo "<p style='color: green;'>✅ " . $connection_test['message'] . "</p>";
        if (isset($connection_test['account'])) {
            echo "<pre>" . print_r($connection_test['account'], true) . "</pre>";
        }
    } else {
        echo "<p style='color: red;'>❌ " . $connection_test['message'] . "</p>";
    }
    
    echo "<hr>";
    
    echo "<h2>2. Teste de Criação de Pagamento</h2>";
    
    // Dados de teste para pagamento
    $test_payment = array(
        'amount' => 1000, // R$ 10,00 em centavos
        'currency' => 'BRL',
        'order_id' => 'TEST_' . time(),
        'description' => 'Teste de pagamento via Sicoob Sandbox',
        'customer' => array(
            'name' => 'Cliente Teste',
            'email' => 'teste@exemplo.com',
            'phone' => '11999999999',
        ),
        'return_url' => home_url('/teste-retorno'),
        'cancel_url' => home_url('/teste-cancelamento'),
    );
    
    echo "<p><strong>Dados do pagamento de teste:</strong></p>";
    echo "<pre>" . print_r($test_payment, true) . "</pre>";
    
    // Tentar criar pagamento
    try {
        $payment_response = $api->create_payment($test_payment);
        
        echo "<p style='color: green;'>✅ Pagamento criado com sucesso!</p>";
        echo "<p><strong>Resposta da API:</strong></p>";
        echo "<pre>" . print_r($payment_response, true) . "</pre>";
        
        // Se houver URL de pagamento, mostrar link
        if (isset($payment_response['payment_url'])) {
            echo "<p><strong>Link para pagamento:</strong></p>";
            echo "<a href='{$payment_response['payment_url']}' target='_blank' style='background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔗 Ir para Pagamento</a>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao criar pagamento: " . $e->getMessage() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro geral: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>3. Informações de Debug</h2>";
echo "<p><strong>URL Base:</strong> " . (defined('SICOOB_WC_PLUGIN_URL') ? SICOOB_WC_PLUGIN_URL : 'Não definida') . "</p>";
echo "<p><strong>Plugin Path:</strong> " . (defined('SICOOB_WC_PLUGIN_PATH') ? SICOOB_WC_PLUGIN_PATH : 'Não definido') . "</p>";
echo "<p><strong>Versão do Plugin:</strong> " . (defined('SICOOB_WC_VERSION') ? SICOOB_WC_VERSION : 'Não definida') . "</p>";

echo "<hr>";
echo "<p><strong>⚠️ IMPORTANTE:</strong> Delete este arquivo após os testes!</p>";
echo "<p><a href='javascript:history.back()'>← Voltar</a></p>";
?>

