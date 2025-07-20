<?php
/**
 * Arquivo de exemplo de configuração para desenvolvimento
 * 
 * Copie este arquivo para config.php e ajuste as configurações
 */

// Configurações de desenvolvimento
define('SICOOB_DEV_MODE', true);
define('SICOOB_DEBUG', true);

// URLs da API (exemplo - ajuste conforme necessário)
define('SICOOB_SANDBOX_URL', 'https://sandbox.api.sicoob.com.br');
define('SICOOB_PRODUCTION_URL', 'https://api.sicoob.com.br');

// Configurações de log
define('SICOOB_LOG_LEVEL', 'debug'); // debug, info, warning, error
define('SICOOB_LOG_FILE', SICOOB_WC_PLUGIN_PATH . 'logs/sicoob.log');

// Configurações de webhook
define('SICOOB_WEBHOOK_TIMEOUT', 30);
define('SICOOB_WEBHOOK_RETRIES', 3);

// Configurações de cache
define('SICOOB_CACHE_ENABLED', true);
define('SICOOB_CACHE_DURATION', 3600); // 1 hora 