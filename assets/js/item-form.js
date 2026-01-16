/**
 * Tainacan AI - Item Form JavaScript
 * @version 1.0.0
 */
(function($) {
    'use strict';

    const TainacanAIApp = {
        // Estado
        state: {
            itemId: null,
            collectionId: null,
            attachmentId: null,
            documentInfo: null,
            isAnalyzing: false,
            lastResult: null,
            panelOpen: false,
        },

        // Elementos DOM
        elements: {},

        /**
         * Inicializa a aplicação
         */
        init() {
            this.cacheElements();
            this.bindEvents();
            this.observeUrlChanges();
            this.extractContext();
            this.createSidebarPanel();

            if (TainacanAI.debug) {
                console.log('[TainacanAI] Initialized', this.state);
            }
        },

        /**
         * Cache de elementos DOM
         */
        cacheElements() {
            this.elements = {
                widget: $('#tainacan-ai-widget'),
                analyzeBtn: $('#tainacan-ai-analyze'),
                refreshBtn: $('#tainacan-ai-refresh'),
                status: $('#tainacan-ai-status'),
                results: $('#tainacan-ai-results'),
                resultsContent: $('#tainacan-ai-results-content'),
                exifContent: $('#tainacan-ai-exif-content'),
                cacheBadge: $('#tainacan-ai-cache-badge'),
                documentInfo: $('#tainacan-ai-document-info'),
                docType: $('#tainacan-ai-doc-type'),
                docName: $('#tainacan-ai-doc-name'),
                tabExif: $('#tainacan-ai-tab-exif'),
                modelInfo: $('#tainacan-ai-model'),
                tokensInfo: $('#tainacan-ai-tokens'),
                copyAllBtn: $('#tainacan-ai-copy-all'),
            };
        },

        /**
         * Cria o painel lateral
         */
        createSidebarPanel() {
            // Remove se já existir
            $('.tainacan-ai-sidebar-panel').remove();
            $('.tainacan-ai-sidebar-overlay').remove();
            $('.tainacan-ai-panel-indicator').remove();

            // Overlay
            $('body').append('<div class="tainacan-ai-sidebar-overlay"></div>');

            // Indicador lateral (aparece quando o painel está fechado e há resultados)
            $('body').append(`
                <div class="tainacan-ai-panel-indicator" title="Abrir resultados da análise">
                    <span class="dashicons dashicons-arrow-left-alt2"></span>
                </div>
            `);

            // Painel lateral
            const panelHtml = `
                <div class="tainacan-ai-sidebar-panel">
                    <div class="tainacan-ai-sidebar-header">
                        <h3>
                            <span class="dashicons dashicons-format-aside"></span>
                            ${TainacanAI.texts?.analysisResults || 'Resultados da Análise'}
                        </h3>
                        <button type="button" class="tainacan-ai-sidebar-close" title="Fechar">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                    <div class="tainacan-ai-sidebar-actions">
                        <button type="button" class="button button-primary" id="tainacan-ai-fill-all" title="${TainacanAI.texts?.fillAllTooltip || 'Preenche automaticamente os campos do Tainacan com os valores extraídos'}">
                            <span class="dashicons dashicons-download"></span>
                            ${TainacanAI.texts?.fillAll || 'Preencher Campos'}
                        </button>
                        <button type="button" class="button button-secondary" id="tainacan-ai-panel-copy-all">
                            <span class="dashicons dashicons-admin-page"></span>
                            ${TainacanAI.texts?.copyAll || 'Copiar Tudo'}
                        </button>
                        <button type="button" class="button button-secondary" id="tainacan-ai-panel-refresh">
                            <span class="dashicons dashicons-update"></span>
                            ${TainacanAI.texts?.newAnalysis || 'Nova Análise'}
                        </button>
                    </div>
                    <div class="tainacan-ai-sidebar-body" id="tainacan-ai-sidebar-content">
                        <!-- Conteúdo será inserido aqui -->
                    </div>
                    <div class="tainacan-ai-sidebar-footer">
                        <span id="tainacan-ai-panel-model">
                            <span class="dashicons dashicons-cloud"></span>
                            <span class="model-name">-</span>
                        </span>
                        <span id="tainacan-ai-panel-tokens">
                            <span class="dashicons dashicons-performance"></span>
                            <span class="tokens-count">-</span>
                        </span>
                    </div>
                </div>
            `;

            $('body').append(panelHtml);

            // Cache dos novos elementos
            this.elements.sidebarPanel = $('.tainacan-ai-sidebar-panel');
            this.elements.sidebarOverlay = $('.tainacan-ai-sidebar-overlay');
            this.elements.sidebarContent = $('#tainacan-ai-sidebar-content');
            this.elements.panelIndicator = $('.tainacan-ai-panel-indicator');
        },

        /**
         * Binding de eventos
         */
        bindEvents() {
            // Botão de análise
            $(document).on('click', '#tainacan-ai-analyze', (e) => {
                e.preventDefault();
                this.analyze(false);
            });

            // Botão de refresh (força nova análise)
            $(document).on('click', '#tainacan-ai-refresh', (e) => {
                e.preventDefault();
                this.analyze(true);
            });

            // Tabs
            $(document).on('click', '.tainacan-ai-tab', (e) => {
                this.switchTab($(e.currentTarget).data('tab'));
            });

            // Copiar valor individual
            $(document).on('click', '.tainacan-ai-copy-btn, .tainacan-ai-copy-mini', (e) => {
                this.copyValue($(e.currentTarget));
            });

            // Copiar todos
            $(document).on('click', '#tainacan-ai-copy-all, #tainacan-ai-panel-copy-all', () => {
                this.copyAllValues();
            });

            // Fechar painel lateral (apenas pelo botão X, não pelo overlay)
            $(document).on('click', '.tainacan-ai-sidebar-close', () => {
                this.closeSidebarPanel();
            });

            // Preencher campo individual
            $(document).on('click', '.tainacan-ai-fill-field', (e) => {
                const $btn = $(e.currentTarget);
                const metadataKey = $btn.data('metadata-key');
                const value = $btn.data('value');
                this.fillTainacanField(metadataKey, value, $btn);
            });

            // Preencher todos os campos mapeados
            $(document).on('click', '#tainacan-ai-fill-all', () => {
                this.fillAllMappedFields();
            });

            // Abrir painel via indicador
            $(document).on('click', '.tainacan-ai-panel-indicator', () => {
                this.openSidebarPanel();
            });

            // Nova análise do painel
            $(document).on('click', '#tainacan-ai-panel-refresh', (e) => {
                e.preventDefault();
                this.analyze(true);
            });

            // Tecla ESC para fechar
            $(document).on('keydown', (e) => {
                if (e.key === 'Escape' && this.state.panelOpen) {
                    this.closeSidebarPanel();
                }
            });
        },

        /**
         * Abre o painel lateral
         */
        openSidebarPanel() {
            // Garante que o painel existe
            if (!this.elements.sidebarPanel || !this.elements.sidebarPanel.length) {
                this.createSidebarPanel();
            }

            if (this.elements.sidebarPanel) {
                this.elements.sidebarPanel.addClass('open');
            }
            if (this.elements.sidebarOverlay) {
                this.elements.sidebarOverlay.addClass('visible');
            }
            if (this.elements.panelIndicator) {
                this.elements.panelIndicator.removeClass('visible');
            }
            this.state.panelOpen = true;
        },

        /**
         * Fecha o painel lateral
         */
        closeSidebarPanel() {
            if (this.elements.sidebarPanel) {
                this.elements.sidebarPanel.removeClass('open');
            }
            if (this.elements.sidebarOverlay) {
                this.elements.sidebarOverlay.removeClass('visible');
            }
            this.state.panelOpen = false;

            // Mostra indicador se há resultados
            if (this.state.lastResult && this.elements.panelIndicator) {
                this.elements.panelIndicator.addClass('visible');
            }
        },

        /**
         * Reseta o estado da análise (ao mudar de item)
         */
        resetAnalysisState() {
            this.state.lastResult = null;
            this.state.attachmentId = null;
            this.state.documentInfo = null;

            // Esconde indicador
            if (this.elements.panelIndicator) {
                this.elements.panelIndicator.removeClass('visible');
            }

            // Limpa conteúdo do painel
            if (this.elements.sidebarPanel) {
                this.elements.sidebarPanel.find('.tainacan-ai-panel-body').html(`
                    <div class="tainacan-ai-panel-placeholder">
                        <span class="dashicons dashicons-search"></span>
                        <p>Clique em "Analisar Documento" para extrair metadados</p>
                    </div>
                `);
            }

            if (TainacanAI.debug) {
                console.log('[TainacanAI] Estado de análise resetado');
            }
        },

        /**
         * Observa mudanças na URL (navegação SPA)
         */
        observeUrlChanges() {
            // Guarda o item atual para detectar mudanças
            let currentHash = window.location.hash;

            // Hash change - detecta mudança de item ou navegação
            $(window).on('hashchange', () => {
                const newHash = window.location.hash;
                const oldItemMatch = currentHash.match(/items\/(\d+)/);
                const newItemMatch = newHash.match(/items\/(\d+)/);

                const oldItemId = oldItemMatch ? oldItemMatch[1] : null;
                const newItemId = newItemMatch ? newItemMatch[1] : null;

                // Se mudou de item ou saiu da edição, fecha o painel e limpa resultados
                if (oldItemId !== newItemId || !newItemMatch) {
                    this.closeSidebarPanel();
                    this.resetAnalysisState();
                }

                currentHash = newHash;
                this.extractContext();
            });

            // Detecta clique no botão salvar do Tainacan
            $(document).on('click', '.tainacan-form button[type="submit"], .item-page button.is-success, [class*="submit-button"]', () => {
                // Fecha o painel após um pequeno delay para permitir o salvamento
                setTimeout(() => {
                    this.closeSidebarPanel();
                }, 500);
            });

            // MutationObserver para detectar mudanças no DOM
            const observer = new MutationObserver((mutations) => {
                let shouldUpdate = false;

                mutations.forEach((mutation) => {
                    if (mutation.addedNodes.length) {
                        mutation.addedNodes.forEach((node) => {
                            if (node.nodeType === 1 && (
                                node.classList?.contains('tainacan-form') ||
                                node.id === 'tainacan-ai-widget' ||
                                node.querySelector?.('#tainacan-ai-widget')
                            )) {
                                shouldUpdate = true;
                            }
                        });
                    }
                });

                if (shouldUpdate) {
                    this.cacheElements();
                    this.extractContext();
                }
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true,
            });
        },

        /**
         * Extrai contexto da página (item_id, collection_id)
         */
        extractContext() {
            // Reset
            this.state.itemId = null;
            this.state.collectionId = null;
            this.state.documentInfo = null;

            // Método 1: URL hash (#/collections/X/items/Y/edit)
            const hash = window.location.hash;
            const hashMatch = hash.match(/collections\/(\d+)\/items\/(\d+)/);
            if (hashMatch) {
                this.state.collectionId = parseInt(hashMatch[1]);
                this.state.itemId = parseInt(hashMatch[2]);
            }

            // Método 1b: URL hash alternativo (#/collections/X/items/new)
            if (!this.state.collectionId) {
                const collectionMatch = hash.match(/collections\/(\d+)/);
                if (collectionMatch) {
                    this.state.collectionId = parseInt(collectionMatch[1]);
                }
            }

            // Método 2: Query params
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('item')) {
                this.state.itemId = parseInt(urlParams.get('item'));
            }
            if (urlParams.get('post')) {
                this.state.itemId = parseInt(urlParams.get('post'));
            }

            // Método 3: Data attributes
            const $container = $('[data-item-id]');
            if ($container.length) {
                this.state.itemId = parseInt($container.data('item-id'));
            }
            const $collection = $('[data-collection-id]');
            if ($collection.length) {
                this.state.collectionId = parseInt($collection.data('collection-id'));
            }

            // Método 4: tainacan_plugin global
            if (typeof window.tainacan_plugin !== 'undefined') {
                if (window.tainacan_plugin.item_id) {
                    this.state.itemId = parseInt(window.tainacan_plugin.item_id);
                }
                if (window.tainacan_plugin.collection_id) {
                    this.state.collectionId = parseInt(window.tainacan_plugin.collection_id);
                }
            }

            // Método 5: Buscar na URL completa
            if (!this.state.collectionId) {
                const fullUrl = window.location.href;
                const urlCollectionMatch = fullUrl.match(/collections[\/=](\d+)/i);
                if (urlCollectionMatch) {
                    this.state.collectionId = parseInt(urlCollectionMatch[1]);
                }
            }

            if (TainacanAI.debug) {
                console.log('[TainacanAI] Context extracted:', this.state);
            }

            // Busca mapeamento se temos collection_id
            if (this.state.collectionId) {
                this.fetchMetadataMapping();
            }

            // Detecta documento se temos item_id
            if (this.state.itemId) {
                this.detectDocument();
            }
        },

        /**
         * Busca mapeamento de metadados via AJAX
         */
        async fetchMetadataMapping() {
            if (!this.state.collectionId) return;

            try {
                const response = await $.ajax({
                    url: TainacanAI.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'tainacan_ai_get_item_mapping',
                        nonce: TainacanAI.nonce,
                        collection_id: this.state.collectionId,
                    },
                });

                if (response.success && response.data) {
                    TainacanAI.metadataMapping = response.data;
                    if (TainacanAI.debug) {
                        console.log('[TainacanAI] Mapeamento atualizado via AJAX:', response.data);
                    }
                }
            } catch (error) {
                console.error('[TainacanAI] Erro ao buscar mapeamento:', error);
            }
        },

        /**
         * Detecta documento do item
         */
        async detectDocument() {
            if (!this.state.itemId) return;

            try {
                const response = await $.ajax({
                    url: TainacanAI.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'tainacan_ai_get_item_document',
                        nonce: TainacanAI.nonce,
                        item_id: this.state.itemId,
                    },
                });

                if (response.success && response.data) {
                    this.state.documentInfo = response.data;
                    this.state.attachmentId = response.data.id;
                    this.showDocumentInfo(response.data);
                }
            } catch (error) {
                if (TainacanAI.debug) {
                    console.log('[TainacanAI] No document found');
                }
            }
        },

        /**
         * Exibe informações do documento detectado
         */
        showDocumentInfo(doc) {
            if (!doc) return;

            const typeLabels = {
                image: TainacanAI.texts.image,
                pdf: TainacanAI.texts.pdf,
                text: TainacanAI.texts.text,
            };

            const typeIcons = {
                image: 'dashicons-format-image',
                pdf: 'dashicons-pdf',
                text: 'dashicons-media-text',
            };

            this.elements.docType.html(
                `<span class="dashicons ${typeIcons[doc.type] || 'dashicons-media-default'}"></span> ` +
                (typeLabels[doc.type] || doc.type)
            );
            this.elements.docName.text(doc.title || '');
            this.elements.documentInfo.show();
        },

        /**
         * Executa análise
         */
        async analyze(forceRefresh = false) {
            if (this.state.isAnalyzing) return;

            // Verifica se temos item ou attachment
            if (!this.state.itemId && !this.state.attachmentId) {
                this.showError(TainacanAI.texts.noDocument);
                return;
            }

            this.state.isAnalyzing = true;
            this.showLoading();

            let hasError = false;

            try {
                const response = await $.ajax({
                    url: TainacanAI.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'tainacan_ai_analyze',
                        nonce: TainacanAI.nonce,
                        item_id: this.state.itemId,
                        attachment_id: this.state.attachmentId,
                        collection_id: this.state.collectionId,
                        force_refresh: forceRefresh,
                    },
                });

                if (response.success) {
                    this.state.lastResult = response.data.result;
                    this.displayResults(response.data.result, response.data.from_cache);
                } else {
                    hasError = true;
                    this.showError(response.data || TainacanAI.texts.error);
                }
            } catch (error) {
                hasError = true;
                console.error('[TainacanAI] Analysis error:', error);
                this.showError(error.responseJSON?.data || TainacanAI.texts.error);
            } finally {
                this.state.isAnalyzing = false;
                if (!hasError) {
                    this.hideLoading();
                }
            }
        },

        /**
         * Exibe resultados
         */
        displayResults(result, fromCache) {
            // Cache badge
            if (fromCache) {
                this.elements.cacheBadge.show();
            } else {
                this.elements.cacheBadge.hide();
            }

            // Metadados AI - renderiza no painel lateral
            if (result.ai_metadata) {
                this.renderMetadataInPanel(result.ai_metadata);
            }

            // EXIF (mostra na área abaixo do botão se disponível)
            if (result.exif && Object.keys(result.exif).length > 0) {
                this.elements.tabExif.show();
                this.elements.results.show();
                this.renderExif(result.exif);
            } else {
                this.elements.tabExif.hide();
                this.elements.results.hide();
            }

            // Info da análise no painel lateral
            if (result.model) {
                $('#tainacan-ai-panel-model .model-name').text(result.model);
            }
            if (result.tokens_used) {
                $('#tainacan-ai-panel-tokens .tokens-count').text(`${result.tokens_used} tokens`);
            }

            // Abre o painel lateral automaticamente
            this.openSidebarPanel();
        },

        /**
         * Renderiza metadados no painel lateral com evidências
         */
        renderMetadataInPanel(metadata) {
            // Garante que o painel existe
            if (!this.elements.sidebarContent || !this.elements.sidebarContent.length) {
                this.createSidebarPanel();
            }

            const $container = this.elements.sidebarContent;
            if (!$container || !$container.length) {
                console.error('[TainacanAI] Sidebar content container not found');
                return;
            }

            $container.empty();

            Object.entries(metadata).forEach(([key, data], index) => {
                const formattedLabel = this.formatLabel(key);

                // Verifica se o dado tem o novo formato com evidência
                let value, evidence;
                if (data && typeof data === 'object' && 'valor' in data) {
                    value = data.valor;
                    evidence = data.evidencia;
                } else {
                    value = data;
                    evidence = null;
                }

                const formattedValue = this.formatValue(value);
                // Converte arrays para string separada por vírgulas para preenchimento de campos
                let rawValue;
                if (Array.isArray(value)) {
                    rawValue = value.join(', ');
                } else if (typeof value === 'string') {
                    rawValue = value;
                } else if (value === null || value === undefined) {
                    rawValue = '';
                } else {
                    rawValue = JSON.stringify(value);
                }
                const isEmpty = value === null || value === undefined ||
                               (Array.isArray(value) && value.length === 0) ||
                               value === '';

                // Verifica se há mapeamento para este campo
                const mapping = TainacanAI.metadataMapping || {};
                const mappedField = mapping[key];
                const hasMappedField = mappedField && !isEmpty;

                const $item = $(`
                    <div class="tainacan-ai-metadata-item-with-evidence ${isEmpty ? 'empty' : ''}"
                         style="animation-delay: ${index * 0.05}s"
                         data-metadata-key="${this.escapeHtml(key)}">
                        <div class="tainacan-ai-metadata-main">
                            <div class="tainacan-ai-metadata-top">
                                <div class="tainacan-ai-metadata-label-with-copy">
                                    <span class="tainacan-ai-metadata-label">${formattedLabel}</span>
                                    ${hasMappedField ? `<span class="tainacan-ai-mapped-badge" title="Mapeado para: ${this.escapeHtml(mappedField.name || mappedField)}">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                    </span>` : ''}
                                </div>
                                <div class="tainacan-ai-metadata-actions-mini">
                                    ${hasMappedField ? `
                                    <button type="button" class="tainacan-ai-fill-field"
                                            data-metadata-key="${this.escapeHtml(key)}"
                                            data-value="${this.escapeHtml(rawValue)}"
                                            title="${TainacanAI.texts?.fillField || 'Preencher campo'}">
                                        <span class="dashicons dashicons-download"></span>
                                    </button>
                                    ` : ''}
                                    <button type="button" class="tainacan-ai-copy-mini"
                                            data-value="${this.escapeHtml(rawValue)}"
                                            title="${TainacanAI.texts?.copy || 'Copiar'}">
                                        <span class="dashicons dashicons-clipboard"></span>
                                    </button>
                                </div>
                            </div>
                            <div class="tainacan-ai-metadata-value-box">
                                <div class="tainacan-ai-metadata-value-text">${formattedValue}</div>
                            </div>
                        </div>
                        ${evidence ? `
                        <div class="tainacan-ai-metadata-evidence">
                            <div class="tainacan-ai-evidence-label">
                                <span class="dashicons dashicons-search"></span>
                                ${TainacanAI.texts?.evidence || 'Evidência'}
                            </div>
                            <div class="tainacan-ai-evidence-text">${this.escapeHtml(evidence)}</div>
                        </div>
                        ` : ''}
                    </div>
                `);

                $container.append($item);
            });
        },

        /**
         * Renderiza dados EXIF
         */
        renderExif(exif) {
            const $container = this.elements.exifContent;
            $container.empty();

            const renderSection = (title, data) => {
                if (!data || Object.keys(data).length === 0) return;

                const $section = $(`
                    <div class="tainacan-ai-exif-section">
                        <h6>${title}</h6>
                        <div class="tainacan-ai-exif-grid"></div>
                    </div>
                `);

                const $grid = $section.find('.tainacan-ai-exif-grid');

                Object.entries(data).forEach(([key, value]) => {
                    if (value !== null && value !== undefined) {
                        $grid.append(`
                            <div class="tainacan-ai-exif-item">
                                <span class="tainacan-ai-exif-label">${this.formatLabel(key)}</span>
                                <span class="tainacan-ai-exif-value">${this.escapeHtml(String(value))}</span>
                            </div>
                        `);
                    }
                });

                $container.append($section);
            };

            const sectionTitles = {
                camera: 'Camera',
                captura: 'Captura',
                imagem: 'Imagem',
                gps: 'Localização',
                autoria: 'Autoria',
            };

            Object.entries(exif).forEach(([section, data]) => {
                renderSection(sectionTitles[section] || section, data);
            });

            if (exif.gps?.google_maps_link) {
                $container.append(`
                    <div class="tainacan-ai-exif-map">
                        <a href="${exif.gps.google_maps_link}" target="_blank" class="button">
                            <span class="dashicons dashicons-location"></span>
                            Ver no Google Maps
                        </a>
                    </div>
                `);
            }
        },

        /**
         * Alterna entre tabs
         */
        switchTab(tabId) {
            $('.tainacan-ai-tab').removeClass('active');
            $(`.tainacan-ai-tab[data-tab="${tabId}"]`).addClass('active');

            $('.tainacan-ai-tab-content').removeClass('active');
            $(`#tainacan-ai-content-${tabId}`).addClass('active');
        },

        /**
         * Copia valor para clipboard
         */
        async copyValue($btn) {
            const value = $btn.data('value');

            try {
                await navigator.clipboard.writeText(value);
                this.showCopySuccess($btn);
            } catch (error) {
                const $temp = $('<textarea>').val(value).appendTo('body').select();
                document.execCommand('copy');
                $temp.remove();
                this.showCopySuccess($btn);
            }
        },

        /**
         * Copia todos os valores
         */
        async copyAllValues() {
            if (!this.state.lastResult?.ai_metadata) return;

            const text = Object.entries(this.state.lastResult.ai_metadata)
                .map(([key, data]) => {
                    let value;
                    if (data && typeof data === 'object' && 'valor' in data) {
                        value = data.valor;
                    } else {
                        value = data;
                    }
                    const formattedValue = typeof value === 'string' ? value : JSON.stringify(value);
                    return `${this.formatLabel(key)}: ${formattedValue}`;
                })
                .join('\n');

            try {
                await navigator.clipboard.writeText(text);
                this.showToast(TainacanAI.texts?.allCopied || 'Copiado!');
            } catch (error) {
                const $temp = $('<textarea>').val(text).appendTo('body').select();
                document.execCommand('copy');
                $temp.remove();
                this.showToast(TainacanAI.texts?.allCopied || 'Copiado!');
            }
        },

        /**
         * Feedback visual de cópia
         */
        showCopySuccess($btn) {
            const $icon = $btn.find('.dashicons');
            $icon.removeClass('dashicons-clipboard').addClass('dashicons-yes');
            $btn.addClass('copied');

            setTimeout(() => {
                $icon.removeClass('dashicons-yes').addClass('dashicons-clipboard');
                $btn.removeClass('copied');
            }, 1500);
        },

        /**
         * Mostra estado de loading
         */
        showLoading() {
            this.elements.analyzeBtn.prop('disabled', true);
            this.elements.refreshBtn.prop('disabled', true);
            this.elements.status.show();
            this.elements.results.hide();
        },

        /**
         * Esconde loading
         */
        hideLoading() {
            this.elements.analyzeBtn.prop('disabled', false);
            this.elements.refreshBtn.prop('disabled', false);
            this.elements.status.hide();
        },

        /**
         * Mostra erro
         */
        showError(message) {
            this.elements.analyzeBtn.prop('disabled', false);
            this.elements.refreshBtn.prop('disabled', false);

            this.elements.status.html(`
                <div class="tainacan-ai-error">
                    <span class="dashicons dashicons-warning"></span>
                    <div class="tainacan-ai-error-text">
                        <strong>Erro</strong>
                        <span>${message}</span>
                    </div>
                </div>
            `).show();

            setTimeout(() => {
                this.elements.status.fadeOut();
            }, 10000);
        },

        /**
         * Mostra toast notification
         */
        showToast(message) {
            const $toast = $(`
                <div class="tainacan-ai-toast">
                    <span class="dashicons dashicons-yes-alt"></span>
                    ${message}
                </div>
            `).appendTo('body');

            setTimeout(() => {
                $toast.addClass('visible');
            }, 10);

            setTimeout(() => {
                $toast.removeClass('visible');
                setTimeout(() => $toast.remove(), 300);
            }, 2000);
        },

        /**
         * Formata label do metadado
         */
        formatLabel(key) {
            return key
                .replace(/_/g, ' ')
                .replace(/([A-Z])/g, ' $1')
                .replace(/^./, str => str.toUpperCase())
                .trim();
        },

        /**
         * Formata valor para exibição
         */
        formatValue(value) {
            if (value === null || value === undefined || value === '') {
                return '<span class="tainacan-ai-empty-value">-</span>';
            }

            if (Array.isArray(value)) {
                if (value.length === 0) {
                    return '<span class="tainacan-ai-empty-value">-</span>';
                }
                return value.map(v => `<span class="tainacan-ai-tag">${this.escapeHtml(String(v))}</span>`).join(' ');
            }

            if (typeof value === 'object') {
                return `<pre class="tainacan-ai-json">${this.escapeHtml(JSON.stringify(value, null, 2))}</pre>`;
            }

            return this.escapeHtml(String(value));
        },

        /**
         * Preenche um campo do Tainacan com o valor extraído
         */
        fillTainacanField(metadataKey, value, $btn = null) {
            const mapping = TainacanAI.metadataMapping || {};
            const fieldInfo = mapping[metadataKey];

            if (!fieldInfo) {
                console.log(`[TainacanAI] Campo "${metadataKey}" não tem mapeamento`);
                return false;
            }

            // Tenta encontrar o campo do Tainacan
            const metadataId = fieldInfo.id || fieldInfo;
            console.log(`[TainacanAI] Tentando preencher "${metadataKey}" -> id=${metadataId}, slug=${fieldInfo.slug}`);

            const $field = this.findTainacanField(metadataId, fieldInfo.slug);

            if (!$field || !$field.length) {
                console.log(`[TainacanAI] Campo "${metadataKey}" NÃO encontrado no DOM`);
                return false;
            }

            console.log(`[TainacanAI] Campo "${metadataKey}" ENCONTRADO:`, $field[0]);

            // Preenche o campo baseado no tipo
            const success = this.setFieldValue($field, value, fieldInfo.type);

            if (success) {
                // Feedback visual
                if ($btn) {
                    const $icon = $btn.find('.dashicons');
                    $icon.removeClass('dashicons-download').addClass('dashicons-yes');
                    $btn.addClass('filled');
                    setTimeout(() => {
                        $icon.removeClass('dashicons-yes').addClass('dashicons-download');
                        $btn.removeClass('filled');
                    }, 2000);
                }

                // Destaca o campo preenchido
                $field.addClass('tainacan-ai-field-filled');
                setTimeout(() => {
                    $field.removeClass('tainacan-ai-field-filled');
                }, 3000);

                return true;
            }

            return false;
        },

        /**
         * Encontra o campo do Tainacan no DOM
         */
        findTainacanField(metadataId, slug) {
            // Tenta várias estratégias para encontrar o campo
            let $field;

            // 1. PRINCIPAL: Por ID do input no formato Tainacan (tainacan-item-metadatum_id-{ID})
            $field = $(`#tainacan-item-metadatum_id-${metadataId}`);
            if ($field.length) {
                // Se for um container (div), busca o input/textarea dentro dele
                if ($field.is('div, span, section')) {
                    const $innerField = $field.find('input:not([type="hidden"]), textarea').first();
                    if ($innerField.length) {
                        console.log('[findField] Encontrado via tainacan-item-metadatum_id (inner)');
                        return $innerField;
                    }
                }
                // Se for input/textarea diretamente
                if ($field.is('input, textarea')) {
                    console.log('[findField] Encontrado via tainacan-item-metadatum_id');
                    return $field;
                }
            }

            // 2. Por ID do metadado no Vue/Tainacan (vários formatos)
            $field = $(`[data-metadatum-id="${metadataId}"] input:not([type="hidden"]), [data-metadatum-id="${metadataId}"] textarea`).first();
            if ($field.length) { console.log('[findField] Encontrado via data-metadatum-id'); return $field; }

            // 3. Por atributo tainacan-metadatum-id (Vue)
            $field = $(`[tainacan-metadatum-id="${metadataId}"] input, [tainacan-metadatum-id="${metadataId}"] textarea`).first();
            if ($field.length) { console.log('[findField] Encontrado via tainacan-metadatum-id attr'); return $field; }

            // 4. Por outros formatos de ID
            $field = $(`#tainacan-metadata-${metadataId}, #metadatum-${metadataId}, #metadata-${metadataId}`);
            if ($field.length) { console.log('[findField] Encontrado via ID alternativo'); return $field; }

            // 4. Por name do campo
            $field = $(`[name="metadata[${metadataId}]"], [name="tainacan_metadatum[${metadataId}]"], [name="metadatum_id_${metadataId}"]`);
            if ($field.length) { console.log('[findField] Encontrado via estratégia 4'); return $field; }

            // 5. Por slug/classe - com várias variações
            if (slug) {
                // Tenta com slug exato e parcial
                const slugVariations = [slug, slug.replace(/-\d+$/, ''), slug.replace(/-/g, '_')];
                for (const s of slugVariations) {
                    $field = $(`.tainacan-metadatum-${s} input, .tainacan-metadatum-${s} textarea`).first();
                    if ($field.length) { console.log('[findField] Encontrado via slug classe:', s); return $field; }

                    $field = $(`[data-metadatum-slug="${s}"] input, [data-metadatum-slug="${s}"] textarea`).first();
                    if ($field.length) { console.log('[findField] Encontrado via data-slug:', s); return $field; }
                }
            }

            // 6. Busca no formulário Vue do Tainacan
            $field = $(`.tainacan-item-metadatum[data-metadatum-id="${metadataId}"]`).find('input:not([type="hidden"]), textarea').first();
            if ($field.length) { console.log('[findField] Encontrado via estratégia 6'); return $field; }

            // 7. Busca por aria-label ou placeholder contendo o slug
            if (slug) {
                const slugBase = slug.replace(/-\d+$/, '');
                $field = $(`input[aria-label*="${slugBase}"], textarea[aria-label*="${slugBase}"]`).first();
                if ($field.length) { console.log('[findField] Encontrado via aria-label'); return $field; }
            }

            // 8. Busca por label (pelo nome do metadado)
            if (slug) {
                const $labels = $('label');
                $labels.each(function() {
                    const labelText = $(this).text().toLowerCase().trim();
                    const slugLower = slug.toLowerCase().replace(/-\d+$/, '').replace(/dc/g, '');
                    if (labelText.includes(slugLower) || slugLower.includes(labelText.replace(/[^a-z]/g, ''))) {
                        const forId = $(this).attr('for');
                        if (forId) {
                            $field = $(`#${forId}`);
                            if ($field.length) {
                                console.log('[findField] Encontrado via label for:', labelText);
                                return false; // break
                            }
                        }
                        // Tenta pegar input/textarea no mesmo container
                        $field = $(this).closest('.field, .tainacan-metadatum, .form-group, [class*="metadatum"]').find('input:not([type="hidden"]), textarea').first();
                        if ($field.length) {
                            console.log('[findField] Encontrado via label container:', labelText);
                            return false; // break
                        }
                    }
                });
                if ($field && $field.length) return $field;
            }

            // 9. Debug: mostra todos os inputs/textareas disponíveis
            if (TainacanAI.debug) {
                console.log('[findField] Inputs disponíveis no DOM:');
                $('input:not([type="hidden"]), textarea').each(function(i) {
                    if (i < 20) { // limita a 20 para não poluir
                        console.log(`  - ${this.tagName}`, {
                            id: this.id,
                            name: this.name,
                            class: this.className,
                            'data-*': this.dataset
                        });
                    }
                });
            }

            return null;
        },

        /**
         * Define o valor de um campo
         */
        setFieldValue($field, value, fieldType) {
            try {
                // Converte arrays para string se necessário
                let finalValue = value;
                if (Array.isArray(value)) {
                    finalValue = value.join(', ');
                } else if (typeof value === 'object') {
                    finalValue = JSON.stringify(value);
                }

                const tagName = $field.prop('tagName').toLowerCase();
                const inputType = $field.attr('type');

                // Campos de texto/textarea
                if (tagName === 'textarea' || inputType === 'text' || !inputType) {
                    // Para Vue/React, dispara eventos para atualizar o estado
                    $field.val(finalValue);
                    $field.trigger('input');
                    $field.trigger('change');

                    // Tenta disparar evento nativo para frameworks reativos
                    const nativeEvent = new Event('input', { bubbles: true });
                    $field[0].dispatchEvent(nativeEvent);

                    return true;
                }

                // Campos de seleção
                if (tagName === 'select') {
                    $field.val(finalValue).trigger('change');
                    return true;
                }

                // Checkbox
                if (inputType === 'checkbox') {
                    const shouldCheck = finalValue === true || finalValue === '1' || finalValue === 'true';
                    $field.prop('checked', shouldCheck).trigger('change');
                    return true;
                }

                // Outros tipos de input
                $field.val(finalValue).trigger('input').trigger('change');
                return true;

            } catch (error) {
                console.error('[TainacanAI] Error setting field value:', error);
                return false;
            }
        },

        /**
         * Preenche todos os campos mapeados
         */
        fillAllMappedFields() {
            if (!this.state.lastResult?.ai_metadata) {
                this.showToast(TainacanAI.texts?.noResults || 'Nenhum resultado disponível');
                return;
            }

            const mapping = TainacanAI.metadataMapping || {};
            let filledCount = 0;
            let totalMapped = 0;

            // Debug: mostra mapeamento e resultado da IA
            console.log('[TainacanAI] Mapeamento disponível:', mapping);
            console.log('[TainacanAI] Resultado da IA:', this.state.lastResult.ai_metadata);

            Object.entries(this.state.lastResult.ai_metadata).forEach(([key, data]) => {
                console.log(`[TainacanAI] Verificando chave: "${key}" -> existe no mapeamento:`, !!mapping[key]);
                if (!mapping[key]) return;

                totalMapped++;

                // Extrai o valor (formato novo ou antigo)
                let value;
                if (data && typeof data === 'object' && 'valor' in data) {
                    value = data.valor;
                } else {
                    value = data;
                }

                // Pula valores vazios
                if (value === null || value === undefined || value === '' ||
                    (Array.isArray(value) && value.length === 0)) {
                    return;
                }

                if (this.fillTainacanField(key, value)) {
                    filledCount++;
                }
            });

            if (filledCount > 0) {
                this.showToast(
                    (TainacanAI.texts?.fieldsFilled || '{count} campos preenchidos')
                        .replace('{count}', filledCount)
                );
            } else if (totalMapped === 0) {
                this.showToast(TainacanAI.texts?.noMappedFields || 'Nenhum campo mapeado encontrado');
            } else {
                this.showToast(TainacanAI.texts?.noFieldsToFill || 'Nenhum campo para preencher');
            }
        },

        /**
         * Escape HTML
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
    };

    // Inicializa quando DOM estiver pronto
    $(document).ready(() => {
        TainacanAIApp.init();
    });

})(jQuery);
