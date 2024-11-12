$(document).ready(function() {
    console.log('Document ready executed');

    function handleExpandClick(e) {
        console.log('Click detected on expand icon:', {
            'event type': e.type,
            'target': e.target,
            'currentTarget': e.currentTarget,
            'classes': $(e.currentTarget).attr('class')
        });

        var $icon = $(e.currentTarget);
        var $td = $icon.closest('td');
        var $row = $td.closest('tr');
        var spoolId = $row.data('key');
        var tabId = '#tabs-' + spoolId + '-logs';

        console.log('Click processing:', {
            'icon': $icon[0],
            'td has-logs': $td.hasClass('has-logs'),
            'spoolId': spoolId,
            'tabId': tabId
        });

        if ($td.hasClass('has-logs')) {
            // Inseriamo il loader prima di iniziare il caricamento
            var $tabContent = $(tabId);
            var $loader = $('<div class="kv-loader-overlay"><div class="kv-loader"></div></div>');

            // Aggiungiamo stili inline necessari se non presenti nel CSS della GridView
            $loader.css({
                'position': 'absolute',
                'top': '0',
                'left': '0',
                'width': '100%',
                'height': '100%',
                'background': 'rgba(255,255,255,0.7)',
                'display': 'flex',
                'justify-content': 'center',
                'align-items': 'center',
                'z-index': '1000'
            });

            // Posizionamento relativo al contenitore se non già impostato
            $tabContent.css('position', 'relative');

            // Aggiungiamo il loader
            $tabContent.prepend($loader);

            loadLogContent(spoolId, tabId);
        }
    }

    function loadLogContent(spoolId, tabId) {
        console.log('loadLogContent triggered:', {
            spoolId: spoolId,
            tabId: tabId,
            'tab exists': $(tabId).length > 0,
            'loaded state': $(tabId).data('loaded')
        });

        if (!$(tabId).data('loaded')) {
            $.ajax({
                url: '?r=cron/commands-spool/lazy-load-logs',
                data: { id: spoolId },
                method: 'GET',
                beforeSend: function() {
                    console.log('AJAX starting for spoolId:', spoolId);
                },
                success: function(response) {
                    console.log('AJAX success:', {
                        spoolId: spoolId,
                        responseLength: response.length,
                        response: response.substring(0, 100) + '...'
                    });

                    // Rimuoviamo il loader
                    $(tabId).find('.kv-loader-overlay').remove();

                    // Inseriamo il contenuto
                    $(tabId).html(response);
                    $(tabId).data('loaded', true);
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText
                    });

                    // Rimuoviamo il loader anche in caso di errore
                    $(tabId).find('.kv-loader-overlay').remove();
                    $(tabId).html('Errore nel caricamento del log');
                }
            });
        } else {
            console.log('Content already loaded for:', tabId);
            $(tabId).find('.kv-loader-overlay').remove();
        }
    }

    // Attacca l'handler in più modi per debug
    $(document).on('click', 'div.kv-expand-icon', handleExpandClick);
    $(document).on('click', '.kv-expand-icon.kv-state-init-collapsed', handleExpandClick);

    // Attacca anche direttamente agli elementi esistenti
    $('.kv-expand-icon').each(function() {
        $(this).on('click', handleExpandClick);
        console.log('Direct handler attached to:', this);
    });

    // Monitora altri click sul documento per debug
    $(document).on('click', function(e) {
        console.log('Document click:', {
            'target': e.target,
            'target classes': $(e.target).attr('class'),
            'is expand icon': $(e.target).hasClass('kv-expand-icon')
        });
    });
});