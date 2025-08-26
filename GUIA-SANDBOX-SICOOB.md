# 🧪 Guia Completo - Sicoob Sandbox

## 📋 Pré-requisitos

### 1. Credenciais do Sandbox
- ✅ **Client ID** (fornecido pelo Sicoob)
- ✅ **Access Token (Bearer)** (fornecido pelo Sicoob)
- ❌ **Client Secret** (NÃO é necessário para sandbox)

### 2. URLs de Callback (no portal Sicoob)
- **URL de Retorno**: `https://seusite.com/checkout/order-received/`
- **URL de Webhook**: `https://seusite.com/wc-api/sicoob_webhook`

## ⚙️ Configuração do Plugin

### 1. Ativar o Plugin
- Vá em **Plugins > Plugins Instalados**
- Ative o **Sicoob WooCommerce Gateway**

### 2. Configurar Gateway
- **WooCommerce > Configurações > Pagamentos**
- Clique em **Gerenciar** no método Sicoob
- Configure:
  - ✅ **Ativar/Desativar**: Marque como ativo
  - **Título**: "Sicoob"
  - **Descrição**: "Pague com cartão via Sicoob"
  - ✅ **Modo de Teste**: Marque (IMPORTANTE!)
  - **Client ID**: Seu Client ID do sandbox
  - **Client Secret**: Deixe em branco
  - **Webhook Secret**: Digite algo como `test123`
- Clique em **Salvar alterações**

## 🧪 Teste de Conexão

### 1. Usar Arquivo de Teste
- Coloque `test-sicoob-sandbox.php` na raiz do WordPress
- Acesse: `https://seusite.com/test-sicoob-sandbox.php`
- Substitua `seu_client_id_aqui` pelo seu Client ID real
- Execute o teste

### 2. Verificar Resultados
- ✅ **Conexão OK**: API respondeu
- ✅ **Pagamento Criado**: Transação foi criada
- 🔗 **Link de Pagamento**: URL para página do Sicoob

## 💳 Teste de Pagamento

### 1. Dados de Cartão de Teste
**IMPORTANTE**: Use APENAS os cartões fornecidos na documentação oficial do Sicoob Sandbox!

Exemplos típicos (verifique na sua documentação):
- **Visa**: 4111111111111111
- **Mastercard**: 5555555555554444
- **CVV**: 123
- **Validade**: 12/30
- **CPF**: 12345678901

### 2. Fluxo de Teste
1. **No seu site**: Adicione produto ao carrinho
2. **Checkout**: Selecione "Sicoob" como método de pagamento
3. **Finalizar**: Clique em "Finalizar pedido"
4. **Redirecionamento**: Será enviado para página do Sicoob
5. **Dados do cartão**: Use os cartões de teste
6. **Confirmação**: Complete o pagamento
7. **Retorno**: Volta para seu site com status

## 📊 Monitoramento

### 1. Pedidos WooCommerce
- **WooCommerce > Pedidos**
- Status será atualizado automaticamente

### 2. Transações Sicoob
- **Tabela**: `wp_sicoob_transactions`
- **Logs**: `wp-content/plugins/options-sicoob/logs/`

### 3. Dashboard Personalizado
- Use `dashboard-sicoob.php` na raiz do WordPress
- Acesse: `https://seusite.com/dashboard-sicoob.php`

## 🔧 Troubleshooting

### Erro 401/403
- ✅ Verifique se o Access Token está correto
- ✅ Confirme se está em modo sandbox
- ✅ Verifique se o Client ID está correto

### Erro 400
- ✅ Verifique estrutura dos dados enviados
- ✅ Confirme se as URLs de callback estão corretas
- ✅ Verifique se o valor está em centavos

### Webhook não funciona
- ✅ Confirme se a URL está acessível publicamente
- ✅ Verifique se o Webhook Secret está correto
- ✅ Teste com ngrok se estiver em ambiente local

## 📚 Documentação Oficial

- **Sandbox**: https://developers.sicoob.com.br/portal/documentacao?slugItem=sandbox
- **Endpoints**: `/v1/payments`
- **Autenticação**: Bearer Token
- **Formato**: JSON

## ⚠️ Importante

1. **NUNCA** use cartões reais no sandbox
2. **SEMPRE** use os cartões de teste oficiais
3. **DELETE** os arquivos de teste após uso
4. **MONITORE** os logs para debug
5. **TESTE** todos os cenários (aprovado/recusado)

## 🚀 Próximos Passos

Após testes bem-sucedidos:
1. Configure para produção
2. Obtenha credenciais reais
3. Configure URLs de callback reais
4. Teste com valores pequenos
5. Monitore transações reais

