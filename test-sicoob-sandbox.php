<?php

/**
 * Teste da API do Sicoob Sandbox
 * Coloque este arquivo na raiz do WordPress para testar
 */

// Carregar WordPress
require_once('wp-load.php');

// Verificar se o plugin está ativo
if (!class_exists('Sicoob_API')) {
    die('Plugin Sicoob WooCommerce não está ativo!');
}

// Configurações do sandbox
$client_id = 'seu_client_id_aqui'; // Substitua pelo seu Client ID
$environment = 'sandbox';

echo "<h1>🧪 Teste da API Sicoob Sandbox</h1>";

try {
    // Criar instância da API
    $api = new Sicoob_API($client_id, '', $environment);

    echo "<h2>✅ Conexão estabelecida</h2>";
    echo "<p><strong>Ambiente:</strong> {$environment}</p>";
    echo "<p><strong>Base URL:</strong> " . ($environment === 'sandbox' ? 'https://sandbox.sicoob.com.br/sicoob/sandbox' : 'https://api.sicoob.com.br') . "</p>";

    // Testar conexão
    echo "<h2>🔍 Testando conexão...</h2>";
    $connection_test = $api->test_connection();

    if ($connection_test['success']) {
        echo "<p style='color: green;'>✅ " . $connection_test['message'] . "</p>";
    } else {
        echo "<p style='color: red;'>❌ " . $connection_test['message'] . "</p>";
    }

    // Criar pagamento de teste
    echo "<h2>💳 Criando pagamento de teste...</h2>";

    $payment_data = array(
        'amount' => 1000, // R$ 10,00 em centavos
        'currency' => 'BRL',
        'order_id' => 'TEST-' . time(),
        'description' => 'Pagamento de teste - Sandbox Sicoob',
        'customer' => array(
            'name' => 'Cliente Teste',
            'email' => 'teste@exemplo.com',
            'tax_id' => '12345678901', // CPF de teste
        ),
        'return_url' => home_url('/teste-retorno'),
        'cancel_url' => home_url('/teste-cancelamento'),
    );

    echo "<p><strong>Dados do pagamento:</strong></p>";
    echo "<pre>" . print_r($payment_data, true) . "</pre>";

    $response = $api->create_payment($payment_data);

    if ($response) {
        echo "<h3>✅ Pagamento criado com sucesso!</h3>";
        echo "<p><strong>Resposta da API:</strong></p>";
        echo "<pre>" . print_r($response, true) . "</pre>";

        // Verificar se temos URL de pagamento
        if (isset($response['paymentUrl'])) {
            echo "<h3>🔗 Link para Pagamento:</h3>";
            echo "<p><a href='{$response['paymentUrl']}' target='_blank' style='background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Ir para Pagamento</a></p>";
            echo "<p><small>Clique no botão acima para ir para a página de pagamento do Sicoob</small></p>";
        } elseif (isset($response['payment_url'])) {
            echo "<h3>🔗 Link para Pagamento:</h3>";
            echo "<p><a href='{$response['payment_url']}' target='_blank' style='background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Ir para Pagamento</a></p>";
            echo "<p><small>Clique no botão acima para ir para a página de pagamento do Sicoob</small></p>";
        }

        // Verificar se temos ID da transação
        if (isset($response['id'])) {
            echo "<p><strong>ID da Transação:</strong> {$response['id']}</p>";
        } elseif (isset($response['transaction_id'])) {
            echo "<p><strong>ID da Transação:</strong> {$response['transaction_id']}</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Erro ao criar pagamento</p>";
    }
} catch (Exception $e) {
    echo "<h2>❌ Erro:</h2>";
    echo "<p style='color: red;'>" . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>📋 Próximos Passos:</h2>";
echo "<ol>";
echo "<li>Configure o plugin no WooCommerce com suas credenciais</li>";
echo "<li>Faça um pedido de teste no site</li>";
echo "<li>Use os dados de cartão de teste fornecidos pelo Sicoob</li>";
echo "<li>Monitore os webhooks e logs</li>";
echo "</ol>";

echo "<h2>🔧 Dados de Cartão de Teste (Sicoob Sandbox):</h2>";
echo "<p>Use os cartões de teste fornecidos na documentação oficial do Sicoob Sandbox.</p>";
echo "<p>Normalmente incluem números como:</p>";
echo "<ul>";
echo "<li><strong>Visa:</strong> 4111111111111111</li>";
echo "<li><strong>Mastercard:</strong> 5555555555554444</li>";
echo "<li><strong>CVV:</strong> 123</li>";
echo "<li><strong>Validade:</strong> 12/30</li>";
echo "<li><strong>CPF:</strong> 12345678901</li>";
echo "</ul>";
echo "<p><small><em>Nota: Os números exatos devem ser verificados na documentação oficial do Sicoob</em></small></p>";
