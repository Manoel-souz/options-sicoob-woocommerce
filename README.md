# Sicoob WooCommerce Gateway

Plugin WordPress para integração do Sicoob com WooCommerce, permitindo aceitar pagamentos via Sicoob em sua loja online.

## Características

- ✅ Integração completa com WooCommerce
- ✅ Suporte a pagamentos via Sicoob
- ✅ Modo de teste (sandbox)
- ✅ Webhooks para atualização automática de status
- ✅ Logs detalhados de transações
- ✅ Interface administrativa intuitiva
- ✅ Suporte a múltiplas moedas
- ✅ Notificações por e-mail

## Requisitos

- WordPress 5.0 ou superior
- WooCommerce 5.0 ou superior
- PHP 7.4 ou superior
- SSL habilitado (obrigatório para produção)

## Instalação

### Método 1: Upload Manual

1. Faça o download do plugin
2. Extraia o arquivo ZIP
3. Faça upload da pasta `sicoob-woocommerce` para o diretório `/wp-content/plugins/`
4. Ative o plugin através do menu 'Plugins' no WordPress
5. Configure o plugin em WooCommerce > Configurações > Pagamentos

### Método 2: Via FTP

1. Faça upload dos arquivos via FTP para `/wp-content/plugins/sicoob-woocommerce/`
2. Ative o plugin no painel administrativo
3. Configure as opções do gateway

## Configuração

### 1. Obter Credenciais do Sicoob

Para usar o plugin, você precisa:

1. Criar uma conta no Sicoob
2. Solicitar acesso à API de pagamentos
3. Obter suas credenciais (Client ID e Client Secret)
4. Configurar webhooks no painel do Sicoob

### 2. Configurar o Plugin

1. Vá para **WooCommerce > Configurações > Pagamentos**
2. Localize "Sicoob" na lista de gateways
3. Clique em "Gerenciar"
4. Configure as seguintes opções:

#### Configurações Básicas:
- **Habilitar Sicoob**: Marque para ativar o gateway
- **Título**: Nome que aparecerá no checkout
- **Descrição**: Descrição para o cliente
- **Modo de Teste**: Habilite para testar sem cobranças reais

#### Configurações da API:
- **Client ID**: Seu Client ID do Sicoob
- **Client Secret**: Seu Client Secret do Sicoob
- **Webhook Secret**: Secret para validar webhooks

### 3. Configurar Webhooks

No painel do Sicoob, configure o webhook com:

- **URL**: `https://seusite.com/wc-api/sicoob_webhook`
- **Eventos**: `payment.approved`, `payment.cancelled`, `payment.failed`, `payment.pending`
- **Secret**: O mesmo valor configurado no plugin

## Estrutura do Plugin

```
sicoob-woocommerce/
├── sicoob-woocommerce.php          # Arquivo principal
├── includes/
│   ├── class-sicoob-gateway.php    # Gateway de pagamento
│   ├── class-sicoob-api.php        # Classe da API
│   └── class-sicoob-webhook.php    # Gerenciamento de webhooks
├── assets/
│   └── images/
│       └── sicoob-logo.png         # Logo do Sicoob
├── languages/                       # Arquivos de tradução
└── logs/                           # Logs do sistema
```

## Funcionalidades

### Processamento de Pagamentos

1. Cliente seleciona Sicoob no checkout
2. Sistema cria transação no Sicoob
3. Cliente é redirecionado para página de pagamento
4. Após pagamento, cliente retorna à loja
5. Webhook atualiza status automaticamente

### Status de Pagamentos

- **Pendente**: Aguardando confirmação
- **Aprovado**: Pagamento confirmado
- **Cancelado**: Pagamento cancelado
- **Falhou**: Erro no processamento

### Logs e Monitoramento

O plugin gera logs detalhados em:
- `/logs/webhook.log` - Logs de webhooks
- `/logs/webhook-error.log` - Erros de webhooks

## Testes

### Modo de Teste

1. Habilite "Modo de Teste" nas configurações
2. Use credenciais de teste do Sicoob
3. Faça pedidos de teste
4. Verifique logs para debug

### Teste de Webhook

1. Configure o webhook no painel do Sicoob
2. Use a URL: `https://seusite.com/wc-api/sicoob_webhook`
3. Teste com dados de exemplo
4. Verifique logs para confirmar recebimento

## Solução de Problemas

### Problemas Comuns

1. **Webhook não recebido**
   - Verifique se a URL está correta
   - Confirme se o SSL está habilitado
   - Verifique logs de erro

2. **Erro de autenticação**
   - Confirme Client ID e Secret
   - Verifique se as credenciais estão corretas
   - Teste no modo sandbox primeiro

3. **Pagamento não processado**
   - Verifique logs do plugin
   - Confirme configuração do webhook
   - Teste com valores pequenos

### Debug

Para ativar debug:

1. Adicione ao `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

2. Verifique logs em `/wp-content/debug.log`

## Suporte

### Documentação

- [Documentação da API Sicoob](https://api.sicoob.com.br/docs)
- [Documentação WooCommerce](https://docs.woocommerce.com/)

### Contato

Para suporte técnico:
- Email: suporte@seusite.com
- GitHub: [Issues](https://github.com/seu-usuario/sicoob-woocommerce/issues)

## Changelog

### Versão 1.0.0
- Lançamento inicial
- Integração básica com Sicoob
- Suporte a webhooks
- Interface administrativa

## Licença

Este plugin é licenciado sob GPL v2 ou posterior.

## Contribuição

Contribuições são bem-vindas! Por favor:

1. Fork o projeto
2. Crie uma branch para sua feature
3. Commit suas mudanças
4. Push para a branch
5. Abra um Pull Request

## Segurança

- Todas as comunicações são feitas via HTTPS
- Webhooks são validados por assinatura
- Dados sensíveis são criptografados
- Logs não contêm informações sensíveis

## Atualizações

Para atualizar o plugin:

1. Faça backup do site
2. Desative o plugin
3. Substitua os arquivos
4. Reative o plugin
5. Verifique configurações

---

**Nota**: Este plugin é fornecido "como está" sem garantias. Teste sempre em ambiente de desenvolvimento antes de usar em produção. 