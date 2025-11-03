/**
 * Scripts da Interface Administrativa do Sicoob
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        
        /**
         * Funcionalidade de Copiar
         */
        $('.sicoob-copy-btn').on('click', function(e) {
            e.preventDefault();
            
            const $btn = $(this);
            const targetId = $btn.data('copy-target');
            const $input = $('#' + targetId);
            
            if (!$input.length) {
                console.error('Input alvo não encontrado:', targetId);
                return;
            }
            
            // Selecionar e copiar o texto
            $input.select();
            
            try {
                // Método moderno
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText($input.val())
                        .then(function() {
                            showCopySuccess($btn);
                        })
                        .catch(function(err) {
                            console.error('Erro ao copiar:', err);
                            // Fallback para método antigo
                            fallbackCopy($input, $btn);
                        });
                } else {
                    // Fallback para navegadores antigos
                    fallbackCopy($input, $btn);
                }
            } catch (err) {
                console.error('Erro ao copiar:', err);
                showCopyError($btn);
            }
        });
        
        /**
         * Fallback para copiar em navegadores antigos
         */
        function fallbackCopy($input, $btn) {
            try {
                $input[0].select();
                document.execCommand('copy');
                showCopySuccess($btn);
            } catch (err) {
                console.error('Erro no fallback de cópia:', err);
                showCopyError($btn);
            }
        }
        
        /**
         * Mostrar sucesso ao copiar
         */
        function showCopySuccess($btn) {
            const originalHtml = $btn.html();
            
            // Adicionar classe de sucesso
            $btn.addClass('copied');
            $btn.html('<span class="dashicons dashicons-yes"></span> ' + sicoobAdmin.strings.copied);
            
            // Remover após 2 segundos
            setTimeout(function() {
                $btn.removeClass('copied');
                $btn.html(originalHtml);
            }, 2000);
        }
        
        /**
         * Mostrar erro ao copiar
         */
        function showCopyError($btn) {
            const originalHtml = $btn.html();
            
            // Adicionar classe de erro
            $btn.addClass('error');
            $btn.html('<span class="dashicons dashicons-warning"></span> ' + sicoobAdmin.strings.error);
            
            // Remover após 2 segundos
            setTimeout(function() {
                $btn.removeClass('error');
                $btn.html(originalHtml);
            }, 2000);
        }
        
        /**
         * Validação do formulário
         */
        $('.sicoob-form').on('submit', function(e) {
            let isValid = true;
            const $form = $(this);
            
            // Remover mensagens de erro anteriores
            $form.find('.error-message').remove();
            $form.find('.error-field').removeClass('error-field');
            
            // Validar Client ID (se necessário)
            const $clientId = $('#client_id');
            if ($clientId.val().trim() === '' && $clientId.data('required')) {
                isValid = false;
                $clientId.addClass('error-field');
                $clientId.after('<p class="error-message" style="color: #d63638; margin-top: 5px;">Este campo é obrigatório.</p>');
            }
            
            // Se não for válido, prevenir envio
            if (!isValid) {
                e.preventDefault();
                
                // Scroll até o primeiro erro
                $('html, body').animate({
                    scrollTop: $form.find('.error-field').first().offset().top - 100
                }, 300);
                
                return false;
            }
            
            return true;
        });
        
        /**
         * Auto-save (opcional - pode ser ativado se necessário)
         */
        let autoSaveTimer;
        $('.sicoob-form input, .sicoob-form select, .sicoob-form textarea').on('change', function() {
            clearTimeout(autoSaveTimer);
            
            // Descomente para ativar auto-save após 3 segundos
            // autoSaveTimer = setTimeout(function() {
            //     saveSettings('auto');
            // }, 3000);
        });
        
        /**
         * Confirmar antes de sair com alterações não salvas
         */
        let formChanged = false;
        $('.sicoob-form input, .sicoob-form select, .sicoob-form textarea').on('change', function() {
            formChanged = true;
        });
        
        $('.sicoob-form').on('submit', function() {
            formChanged = false;
        });
        
        $(window).on('beforeunload', function(e) {
            if (formChanged) {
                const message = 'Você tem alterações não salvas. Deseja realmente sair?';
                e.returnValue = message;
                return message;
            }
        });
        
        /**
         * Toggle de checkboxes "Selecionar Todos"
         * (Pode ser adicionado se necessário)
         */
        $('.sicoob-select-all-scopes').on('change', function() {
            const $container = $(this).closest('.sicoob-api-card');
            const isChecked = $(this).is(':checked');
            $container.find('.sicoob-scope-item input[type="checkbox"]').prop('checked', isChecked);
        });
        
        /**
         * Animação suave ao focar em inputs
         */
        $('.sicoob-form input[type="text"], .sicoob-form input[type="password"]').on('focus', function() {
            $(this).closest('tr').addClass('focused');
        }).on('blur', function() {
            $(this).closest('tr').removeClass('focused');
        });
        
        /**
         * Feedback visual para checkboxes
         */
        $('.sicoob-scope-item input[type="checkbox"]').on('change', function() {
            const $item = $(this).closest('.sicoob-scope-item');
            if ($(this).is(':checked')) {
                $item.addClass('selected');
            } else {
                $item.removeClass('selected');
            }
        });
        
        // Aplicar estado inicial dos checkboxes
        $('.sicoob-scope-item input[type="checkbox"]:checked').each(function() {
            $(this).closest('.sicoob-scope-item').addClass('selected');
        });
        
        /**
         * Tooltip simples (opcional)
         */
        $('[data-tooltip]').on('mouseenter', function() {
            const tooltipText = $(this).data('tooltip');
            const $tooltip = $('<div class="sicoob-tooltip">' + tooltipText + '</div>');
            
            $('body').append($tooltip);
            
            const offset = $(this).offset();
            $tooltip.css({
                top: offset.top - $tooltip.outerHeight() - 10,
                left: offset.left + ($(this).outerWidth() / 2) - ($tooltip.outerWidth() / 2)
            });
            
            $tooltip.fadeIn(200);
        }).on('mouseleave', function() {
            $('.sicoob-tooltip').fadeOut(200, function() {
                $(this).remove();
            });
        });
    });
    
})(jQuery);

