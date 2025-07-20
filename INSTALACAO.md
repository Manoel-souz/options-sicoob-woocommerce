# Guia de Instalação Rápida - Sicoob WooCommerce

## Passo a Passo

### 1. Instalação do Plugin

1. **Faça upload dos arquivos** para `/wp-content/plugins/sicoob-woocommerce/`
2. **Ative o plugin** no painel WordPress (Plugins > Sicoob WooCommerce Gateway)
3. **Verifique se o WooCommerce está ativo**

### 2. Configuração Básica

1. Vá para **WooCommerce > Configurações > Pagamentos**
2. Localize **"Sicoob"** na lista
3. Clique em **"Gerenciar"**
4. Configure:

#### Configurações Obrigatórias:
- ✅ **Habilitar Sicoob**: Marque esta opção
- ✅ **Client ID**: Seu Client ID do Sicoob
- ✅ **Client Secret**: Seu Client Secret do Sicoob
- ✅ **Webhook Secret**: Secret para validar webhooks

#### Configurações Opcionais:
- **Título**: "Sicoob" (ou personalizado)
- **Descrição**: Descrição para o cliente
- **Modo de Teste**: Habilite para testes

### 3. Configurar Webhook no Sicoob

No painel do Sicoob, configure:

**URL do Webhook:**
```
https://seusite.com/wc-api/sicoob_webhook
```

**Eventos:**
- `payment.approved`
- `payment.cancelled`
- `payment.failed`
- `payment.pending`

**Secret:** (mesmo valor do plugin)

### 4. Teste

1. **Habilite modo de teste**
2. **Faça um pedido de teste**
3. **Verifique logs** em `/wp-content/plugins/sicoob-woocommerce/logs/`

## Problemas Comuns

### Plugin não aparece na lista
- Verifique se o WooCommerce está ativo
- Recarregue a página de configurações

### Erro de autenticação
- Confirme Client ID e Secret
- Teste no modo sandbox primeiro

### Webhook não funciona
- Verifique se a URL está correta
- Confirme se o SSL está habilitado
- Verifique logs de erro

## Suporte

Para ajuda adicional:
- Consulte o README.md completo
- Verifique logs em `/logs/`
- Entre em contato com o suporte

---

**Importante:** Sempre teste em ambiente de desenvolvimento antes de usar em produção! 