/**
 * Tainacan AI - Admin JavaScript
 * @version 1.0.0
 */
( function ( $ ) {
	'use strict';

	const Admin = {
		init() {
			this.bindEvents();
			this.populateCollections();
			this.populateMappingCollections();
			this.initProviderSelector();
			this.collectionMetadata = [];
			this.currentMapping = {};
		},

		bindEvents() {
			// Provider selection
			$( 'input[name="tainacan_ai_options[ai_provider]"]' ).on(
				'change',
				this.handleProviderChange.bind( this )
			);

			// Provider option click (para selecionar via label)
			$( '.tainacan-ai-provider-option' ).on( 'click', function () {
				$( this )
					.find( 'input[type="radio"]' )
					.prop( 'checked', true )
					.trigger( 'change' );
				$( '.tainacan-ai-provider-option' ).removeClass( 'selected' );
				$( this ).addClass( 'selected' );
			} );

			// API Key toggles (múltiplos)
			$( document ).on(
				'click',
				'.toggle-password',
				this.toggleApiKeyVisibility
			);

			// Test API (múltiplos provedores)
			$( document ).on(
				'click',
				'.test-api-btn',
				this.testProviderConnection.bind( this )
			);

			// Clear cache
			$( '#clear-all-cache' ).on(
				'click',
				this.clearAllCache.bind( this )
			);

			// Toggle cards
			$( '.tainacan-ai-toggle-card' ).on( 'click', this.toggleCard );

			// Collection prompts
			$( '#collection-select' ).on(
				'change',
				this.loadCollectionPrompt.bind( this )
			);
			$( 'input[name="collection_prompt_type"]' ).on(
				'change',
				this.loadCollectionPrompt.bind( this )
			);
			$( '#save-collection-prompt' ).on(
				'click',
				this.saveCollectionPrompt.bind( this )
			);
			$( '#reset-collection-prompt' ).on(
				'click',
				this.resetCollectionPrompt.bind( this )
			);
			$( '#generate-prompt-suggestion' ).on(
				'click',
				this.generatePromptSuggestion.bind( this )
			);

			// Dependencies help buttons
			$( document ).on(
				'click',
				'.tainacan-ai-dep-help',
				this.showInstallHelp.bind( this )
			);

			// Field Mapping
			$( '#mapping-collection-select' ).on(
				'change',
				this.loadCollectionMetadata.bind( this )
			);
			$( '#save-metadata-mapping' ).on(
				'click',
				this.saveMetadataMapping.bind( this )
			);
			$( '#auto-detect-mapping' ).on(
				'click',
				this.autoDetectMapping.bind( this )
			);
			$( '#clear-mapping' ).on( 'click', this.clearMapping.bind( this ) );
			$( '#add-mapping-row' ).on(
				'click',
				this.addMappingRow.bind( this )
			);
			$( document ).on(
				'click',
				'.remove-mapping-row',
				this.removeMappingRow.bind( this )
			);

			// Modal close
			$( document ).on(
				'click',
				'.tainacan-ai-modal-close, .tainacan-ai-modal-overlay',
				this.closeModal.bind( this )
			);
			$( document ).on( 'click', '.tainacan-ai-modal', function ( e ) {
				e.stopPropagation();
			} );

			// Install tabs
			$( document ).on(
				'click',
				'.tainacan-ai-install-tab',
				this.switchInstallTab.bind( this )
			);

			// Copy code
			$( document ).on(
				'click',
				'.tainacan-ai-copy-code',
				this.copyCode.bind( this )
			);
		},

		initProviderSelector() {
			// Mostra a configuração do provedor selecionado
			const selectedProvider = $(
				'input[name="tainacan_ai_options[ai_provider]"]:checked'
			).val();
			if ( selectedProvider ) {
				this.showProviderConfig( selectedProvider );
			}
		},

		handleProviderChange( e ) {
			const provider = $( e.target ).val();
			this.showProviderConfig( provider );
		},

		showProviderConfig( provider ) {
			// Esconde todas as configurações de provedor
			$( '.tainacan-ai-provider-config' ).hide();

			// Mostra apenas a do provedor selecionado
			$( `#provider-config-${ provider }` ).show();
		},

		toggleApiKeyVisibility( e ) {
			e.preventDefault();
			const $btn = $( e.currentTarget );
			const targetId = $btn.data( 'target' );
			const $input = targetId
				? $( `#${ targetId }` )
				: $btn.siblings( 'input[type="password"], input[type="text"]' );
			const $icon = $btn.find( '.dashicons' );

			if ( $input.attr( 'type' ) === 'password' ) {
				$input.attr( 'type', 'text' );
				$icon
					.removeClass( 'dashicons-visibility' )
					.addClass( 'dashicons-hidden' );
			} else {
				$input.attr( 'type', 'password' );
				$icon
					.removeClass( 'dashicons-hidden' )
					.addClass( 'dashicons-visibility' );
			}
		},

		testProviderConnection( e ) {
			e.preventDefault();
			const $btn = $( e.currentTarget );
			const provider = $btn.data( 'provider' );
			const $result = $(
				`.api-test-result[data-provider="${ provider }"]`
			);
			const originalHtml = $btn.html();

			$btn.prop( 'disabled', true ).html(
				'<span class="dashicons dashicons-update spin"></span> ' +
					TainacanAIAdmin.texts.testing
			);
			$result
				.removeClass( 'success error' )
				.addClass( 'loading' )
				.html(
					'<span class="tainacan-ai-spinner"></span> ' +
						TainacanAIAdmin.texts.testing
				)
				.show();

			$.ajax( {
				url: TainacanAIAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tainacan_ai_test_api',
					nonce: TainacanAIAdmin.nonce,
					provider: provider,
				},
				success( response ) {
					$result.removeClass( 'loading' );
					if ( response.success ) {
						const message =
							typeof response.data === 'object'
								? response.data.message
								: response.data;
						$result.addClass( 'success' ).html( '✓ ' + message );
					} else {
						const message =
							typeof response.data === 'object'
								? response.data.message || response.data
								: response.data;
						$result.addClass( 'error' ).html( '✗ ' + message );
					}
				},
				error() {
					$result
						.removeClass( 'loading' )
						.addClass( 'error' )
						.html( '✗ ' + TainacanAIAdmin.texts.error );
				},
				complete() {
					$btn.prop( 'disabled', false ).html( originalHtml );
				},
			} );
		},

		clearAllCache() {
			const $btn = $( '#clear-all-cache' );
			const originalHtml = $btn.html();

			if ( ! confirm( 'Tem certeza que deseja limpar todo o cache?' ) ) {
				return;
			}

			$btn.prop( 'disabled', true ).html(
				'<span class="dashicons dashicons-update spin"></span> ' +
					TainacanAIAdmin.texts.clearing
			);

			$.ajax( {
				url: TainacanAIAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tainacan_ai_clear_cache',
					nonce: TainacanAIAdmin.nonce,
				},
				success( response ) {
					if ( response.success ) {
						const message =
							typeof response.data === 'string'
								? response.data
								: TainacanAIAdmin.texts.cacheCleared;
						Admin.showNotice( message, 'success' );
					} else {
						const message =
							typeof response.data === 'string'
								? response.data
								: TainacanAIAdmin.texts.error;
						Admin.showNotice( message, 'error' );
					}
				},
				error() {
					Admin.showNotice( TainacanAIAdmin.texts.error, 'error' );
				},
				complete() {
					$btn.prop( 'disabled', false ).html( originalHtml );
				},
			} );
		},

		toggleCard() {
			const $card = $( this ).closest( '.tainacan-ai-card' );
			const $body = $card.find( '.tainacan-ai-collapsible' );
			const $icon = $( this ).find( '.dashicons' );

			$body.toggleClass( 'collapsed' );
			$icon.toggleClass(
				'dashicons-arrow-down-alt2 dashicons-arrow-up-alt2'
			);
		},

		populateCollections() {
			const $select = $( '#collection-select' );
			if ( ! $select.length || ! TainacanAIAdmin.collections ) return;

			TainacanAIAdmin.collections.forEach( ( collection ) => {
				$select.append(
					`<option value="${ collection.id }">${ collection.name }</option>`
				);
			} );
		},

		populateMappingCollections() {
			// Coleções já são populadas diretamente no PHP/HTML
			// Esta função agora é apenas um placeholder para compatibilidade
			const $select = $( '#mapping-collection-select' );
			if ( $select.length ) {
				console.log(
					'[TainacanAI] Mapping collections select found with',
					$select.find( 'option' ).length - 1,
					'collections'
				);
			}
		},

		loadCollectionMetadata() {
			const collectionId = $( '#mapping-collection-select' ).val();

			if ( ! collectionId ) {
				$( '#metadata-mapping-editor' ).hide();
				return;
			}

			$( '#metadata-mapping-editor' ).show();
			$( '#metadata-mapping-list' ).html(
				'<div class="tainacan-ai-loading"><span class="tainacan-ai-spinner"></span> Carregando metadados...</div>'
			);

			// Carrega metadados da coleção
			$.ajax( {
				url: TainacanAIAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tainacan_ai_get_collection_metadata',
					nonce: TainacanAIAdmin.nonce,
					collection_id: collectionId,
				},
				success: ( response ) => {
					if ( response.success ) {
						this.collectionMetadata = response.data;
						this.loadExistingMapping( collectionId );
					} else {
						$( '#metadata-mapping-list' ).html(
							'<p class="error">Erro ao carregar metadados.</p>'
						);
					}
				},
				error: () => {
					$( '#metadata-mapping-list' ).html(
						'<p class="error">Erro ao carregar metadados.</p>'
					);
				},
			} );
		},

		loadExistingMapping( collectionId ) {
			$.ajax( {
				url: TainacanAIAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tainacan_ai_get_mapping',
					nonce: TainacanAIAdmin.nonce,
					collection_id: collectionId,
				},
				success: ( response ) => {
					if ( response.success ) {
						this.currentMapping = response.data || {};
					} else {
						this.currentMapping = {};
					}
					this.renderMappingEditor();
				},
				error: () => {
					this.currentMapping = {};
					this.renderMappingEditor();
				},
			} );
		},

		renderMappingEditor() {
			const $container = $( '#metadata-mapping-list' );
			$container.empty();

			// Campos padrão da IA que podem ser mapeados
			const defaultAiFields = [
				{ key: 'titulo', label: 'Título' },
				{ key: 'descricao', label: 'Descrição' },
				{ key: 'autor', label: 'Autor' },
				{ key: 'data', label: 'Data' },
				{ key: 'assunto', label: 'Assunto' },
				{ key: 'tipo', label: 'Tipo' },
				{ key: 'formato', label: 'Formato' },
				{ key: 'idioma', label: 'Idioma' },
				{ key: 'fonte', label: 'Fonte' },
				{ key: 'direitos', label: 'Direitos' },
				{ key: 'cobertura', label: 'Cobertura' },
				{ key: 'editor', label: 'Editor' },
				{ key: 'contribuidor', label: 'Contribuidor' },
				{ key: 'relacao', label: 'Relação' },
				{ key: 'identificador', label: 'Identificador' },
			];

			// Adiciona campos personalizados que estão no mapeamento atual
			const existingKeys = Object.keys( this.currentMapping );
			const defaultKeys = defaultAiFields.map( ( f ) => f.key );

			existingKeys.forEach( ( key ) => {
				if ( ! defaultKeys.includes( key ) ) {
					defaultAiFields.push( {
						key: key,
						label: key,
						custom: true,
					} );
				}
			} );

			// Cria o seletor de metadados
			const metadataOptions = this.collectionMetadata
				.map(
					( meta ) =>
						`<option value="${ meta.id }">${ meta.name }</option>`
				)
				.join( '' );

			// Renderiza cada linha de mapeamento
			defaultAiFields.forEach( ( field ) => {
				const mappedValue = this.currentMapping[ field.key ];
				const selectedId = mappedValue ? mappedValue.metadata_id : '';

				const row = `
                    <div class="tainacan-ai-mapping-row" data-ai-field="${
						field.key
					}">
                        <div class="tainacan-ai-mapping-ai-field">
                            ${
								field.custom
									? `<input type="text" class="ai-field-name" value="${ field.key }" />`
									: `<span class="ai-field-label">${ field.label }</span>
                                 <code class="ai-field-key">${ field.key }</code>`
							}
                        </div>
                        <div class="tainacan-ai-mapping-arrow">
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </div>
                        <div class="tainacan-ai-mapping-metadata">
                            <select class="metadata-select">
                                <option value="">-- Não mapear --</option>
                                ${ metadataOptions }
                            </select>
                        </div>
                        ${
							field.custom
								? `<button type="button" class="button button-small remove-mapping-row" title="Remover">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>`
								: ''
						}
                    </div>
                `;

				$container.append( row );

				// Define o valor selecionado
				if ( selectedId ) {
					$container
						.find(
							`.tainacan-ai-mapping-row[data-ai-field="${ field.key }"] .metadata-select`
						)
						.val( selectedId );
				}
			} );

			// Botão para adicionar campo personalizado
			$container.append( `
                <div class="tainacan-ai-mapping-add">
                    <button type="button" class="button button-small" id="add-mapping-row">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        Adicionar campo personalizado
                    </button>
                </div>
            ` );
		},

		addMappingRow() {
			const metadataOptions = this.collectionMetadata
				.map(
					( meta ) =>
						`<option value="${ meta.id }">${ meta.name }</option>`
				)
				.join( '' );

			const uniqueId = 'custom_' + Date.now();
			const row = `
                <div class="tainacan-ai-mapping-row custom-row" data-ai-field="${ uniqueId }">
                    <div class="tainacan-ai-mapping-ai-field">
                        <input type="text" class="ai-field-name" placeholder="Nome do campo IA" />
                    </div>
                    <div class="tainacan-ai-mapping-arrow">
                        <span class="dashicons dashicons-arrow-right-alt"></span>
                    </div>
                    <div class="tainacan-ai-mapping-metadata">
                        <select class="metadata-select">
                            <option value="">-- Não mapear --</option>
                            ${ metadataOptions }
                        </select>
                    </div>
                    <button type="button" class="button button-small remove-mapping-row" title="Remover">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
            `;

			// Insere antes do botão de adicionar
			$( '.tainacan-ai-mapping-add' ).before( row );
		},

		removeMappingRow( e ) {
			$( e.currentTarget ).closest( '.tainacan-ai-mapping-row' ).remove();
		},

		saveMetadataMapping() {
			const collectionId = $( '#mapping-collection-select' ).val();
			if ( ! collectionId ) {
				Admin.showNotice( 'Selecione uma coleção primeiro.', 'error' );
				return;
			}

			const $btn = $( '#save-metadata-mapping' );
			const originalHtml = $btn.html();

			// Coleta o mapeamento
			const mapping = {};
			$( '.tainacan-ai-mapping-row' ).each( function () {
				let aiField = $( this ).data( 'ai-field' );
				const $nameInput = $( this ).find( '.ai-field-name' );

				// Se for campo personalizado, usa o valor do input
				if ( $nameInput.length && $nameInput.val() ) {
					aiField = $nameInput
						.val()
						.toLowerCase()
						.replace( /\s+/g, '_' );
				}

				const metadataId = $( this ).find( '.metadata-select' ).val();

				if ( aiField && metadataId ) {
					const metadataName = $( this )
						.find( '.metadata-select option:selected' )
						.text();
					mapping[ aiField ] = {
						metadata_id: parseInt( metadataId ),
						metadata_name: metadataName,
					};
				}
			} );

			$btn.prop( 'disabled', true ).html(
				'<span class="dashicons dashicons-update spin"></span> ' +
					TainacanAIAdmin.texts.saving
			);

			$.ajax( {
				url: TainacanAIAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tainacan_ai_save_mapping',
					nonce: TainacanAIAdmin.nonce,
					collection_id: collectionId,
					mapping: JSON.stringify( mapping ),
				},
				success: ( response ) => {
					if ( response.success ) {
						const message =
							typeof response.data === 'string'
								? response.data
								: TainacanAIAdmin.texts.saved;
						Admin.showNotice( message, 'success' );
						this.currentMapping = mapping;
					} else {
						const message =
							typeof response.data === 'string'
								? response.data
								: TainacanAIAdmin.texts.error;
						Admin.showNotice( message, 'error' );
					}
				},
				error: () => {
					Admin.showNotice( TainacanAIAdmin.texts.error, 'error' );
				},
				complete: () => {
					$btn.prop( 'disabled', false ).html( originalHtml );
				},
			} );
		},

		autoDetectMapping() {
			const collectionId = $( '#mapping-collection-select' ).val();
			if ( ! collectionId ) {
				Admin.showNotice( 'Selecione uma coleção primeiro.', 'error' );
				return;
			}

			const $btn = $( '#auto-detect-mapping' );
			const originalHtml = $btn.html();

			$btn.prop( 'disabled', true ).html(
				'<span class="dashicons dashicons-update spin"></span> Detectando...'
			);

			$.ajax( {
				url: TainacanAIAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tainacan_ai_auto_detect_mapping',
					nonce: TainacanAIAdmin.nonce,
					collection_id: collectionId,
				},
				success: ( response ) => {
					if ( response.success ) {
						this.currentMapping = response.data;
						this.renderMappingEditor();
						const count = Object.keys( response.data ).length;
						Admin.showNotice(
							`${ count } campo(s) detectado(s) automaticamente!`,
							'success'
						);
					} else {
						Admin.showNotice(
							'Erro ao detectar mapeamento.',
							'error'
						);
					}
				},
				error: () => {
					Admin.showNotice( TainacanAIAdmin.texts.error, 'error' );
				},
				complete: () => {
					$btn.prop( 'disabled', false ).html( originalHtml );
				},
			} );
		},

		clearMapping() {
			if (
				! confirm( 'Tem certeza que deseja limpar todo o mapeamento?' )
			)
				return;

			this.currentMapping = {};
			this.renderMappingEditor();
			Admin.showNotice(
				'Mapeamento limpo. Clique em "Salvar" para confirmar.',
				'success'
			);
		},

		loadCollectionPrompt() {
			const collectionId = $( '#collection-select' ).val();
			const type = $(
				'input[name="collection_prompt_type"]:checked'
			).val();

			if ( ! collectionId ) {
				$( '#collection-prompt-editor' ).hide();
				return;
			}

			$( '#collection-prompt-editor' ).show();
			$( '#collection-prompt-text' )
				.val( '' )
				.attr(
					'placeholder',
					TainacanAIAdmin.texts.loading || 'Carregando...'
				);

			$.ajax( {
				url: TainacanAIAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tainacan_ai_get_collection_prompt',
					nonce: TainacanAIAdmin.nonce,
					collection_id: collectionId,
					type: type,
				},
				success( response ) {
					if ( response.success ) {
						const prompt =
							response.data.custom_prompt?.prompt_text || '';
						$( '#collection-prompt-text' )
							.val( prompt )
							.attr(
								'placeholder',
								'Deixe em branco para usar o prompt padrão...'
							);
					}
				},
			} );
		},

		saveCollectionPrompt() {
			const collectionId = $( '#collection-select' ).val();
			const type = $(
				'input[name="collection_prompt_type"]:checked'
			).val();
			const promptText = $( '#collection-prompt-text' ).val();
			const $btn = $( '#save-collection-prompt' );
			const originalHtml = $btn.html();

			if ( ! collectionId ) {
				Admin.showNotice( 'Selecione uma coleção primeiro.', 'error' );
				return;
			}

			$btn.prop( 'disabled', true ).html(
				'<span class="dashicons dashicons-update spin"></span> ' +
					TainacanAIAdmin.texts.saving
			);

			$.ajax( {
				url: TainacanAIAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tainacan_ai_save_collection_prompt',
					nonce: TainacanAIAdmin.nonce,
					collection_id: collectionId,
					type: type,
					prompt_text: promptText,
				},
				success( response ) {
					if ( response.success ) {
						const message =
							typeof response.data === 'string'
								? response.data
								: TainacanAIAdmin.texts.saved;
						Admin.showNotice( message, 'success' );
					} else {
						const message =
							typeof response.data === 'string'
								? response.data
								: TainacanAIAdmin.texts.error;
						Admin.showNotice( message, 'error' );
					}
				},
				error( xhr ) {
					const message =
						xhr.responseJSON?.data || TainacanAIAdmin.texts.error;
					Admin.showNotice( message, 'error' );
					console.error( '[TainacanAI] Save error:', xhr );
				},
				complete() {
					$btn.prop( 'disabled', false ).html( originalHtml );
				},
			} );
		},

		resetCollectionPrompt() {
			if ( ! confirm( TainacanAIAdmin.texts.confirmReset ) ) return;

			$( '#collection-prompt-text' ).val( '' );
			this.saveCollectionPrompt();
		},

		generatePromptSuggestion() {
			const collectionId = $( '#collection-select' ).val();
			const type = $(
				'input[name="collection_prompt_type"]:checked'
			).val();
			const $btn = $( '#generate-prompt-suggestion' );
			const originalHtml = $btn.html();

			if ( ! collectionId ) {
				Admin.showNotice( 'Selecione uma coleção primeiro.', 'error' );
				return;
			}

			$btn.prop( 'disabled', true ).html(
				'<span class="dashicons dashicons-update spin"></span> ' +
					TainacanAIAdmin.texts.generating
			);

			$.ajax( {
				url: TainacanAIAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tainacan_ai_generate_prompt_suggestion',
					nonce: TainacanAIAdmin.nonce,
					collection_id: collectionId,
					type: type,
				},
				success( response ) {
					if ( response.success && response.data.suggestion ) {
						$( '#collection-prompt-text' ).val(
							response.data.suggestion
						);
						Admin.showNotice(
							'Sugestão gerada! Revise, ajuste e clique em "Salvar Prompt".',
							'success'
						);
					} else {
						const message =
							response.data || 'Erro ao gerar sugestão.';
						Admin.showNotice( message, 'error' );
					}
				},
				error( xhr ) {
					const message =
						xhr.responseJSON?.data || TainacanAIAdmin.texts.error;
					Admin.showNotice( message, 'error' );
					console.error(
						'[TainacanAI] Generate suggestion error:',
						xhr
					);
				},
				complete() {
					$btn.prop( 'disabled', false ).html( originalHtml );
				},
			} );
		},

		showInstallHelp( e ) {
			const depType = $( e.currentTarget ).data( 'dep' );
			const modal = this.getInstallModal( depType );
			$( 'body' ).append( modal );
		},

		getInstallModal( depType ) {
			const instructions = {
				pdfparser: {
					title: 'Instalar PDF Parser (smalot/pdfparser)',
					description:
						'Biblioteca PHP para extração de texto de PDFs. Necessária para análise de documentos PDF.',
					tabs: [
						{
							id: 'localhost',
							name: 'Localhost (XAMPP/WAMP)',
							content: `
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">1</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Abra o terminal/CMD na pasta do plugin</h4>
                                        <p>Navegue até a pasta do plugin no seu computador.</p>
                                        <div class="tainacan-ai-code-block">
                                            <button class="tainacan-ai-copy-code">Copiar</button>
                                            <code>cd C:\\xampp\\htdocs\\seu-site\\wp-content\\plugins\\tainacan-ai</code>
                                        </div>
                                    </div>
                                </div>
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">2</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Execute o Composer</h4>
                                        <p>Se não tiver o Composer, baixe em <a href="https://getcomposer.org/download/" target="_blank">getcomposer.org</a></p>
                                        <div class="tainacan-ai-code-block">
                                            <button class="tainacan-ai-copy-code">Copiar</button>
                                            <code>composer require smalot/pdfparser</code>
                                        </div>
                                    </div>
                                </div>
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">3</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Verifique a instalação</h4>
                                        <p>Uma pasta "vendor" será criada no plugin. Recarregue esta página para verificar.</p>
                                    </div>
                                </div>
                            `,
						},
						{
							id: 'hostinger',
							name: 'Hostinger',
							content: `
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">1</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Acesse o Terminal SSH</h4>
                                        <p>No painel da Hostinger, vá em <strong>Avançado → Terminal SSH</strong> ou use um cliente SSH como PuTTY.</p>
                                    </div>
                                </div>
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">2</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Navegue até a pasta do plugin</h4>
                                        <div class="tainacan-ai-code-block">
                                            <button class="tainacan-ai-copy-code">Copiar</button>
                                            <code>cd ~/public_html/wp-content/plugins/tainacan-ai</code>
                                        </div>
                                    </div>
                                </div>
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">3</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Execute o Composer</h4>
                                        <p>A Hostinger já possui o Composer instalado.</p>
                                        <div class="tainacan-ai-code-block">
                                            <button class="tainacan-ai-copy-code">Copiar</button>
                                            <code>composer require smalot/pdfparser</code>
                                        </div>
                                    </div>
                                </div>
                                <div class="tainacan-ai-warning-box">
                                    <span class="dashicons dashicons-warning"></span>
                                    <p>Se receber erro de memória, tente: <code>php -d memory_limit=512M /usr/bin/composer require smalot/pdfparser</code></p>
                                </div>
                            `,
						},
						{
							id: 'cpanel',
							name: 'cPanel / Outros',
							content: `
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">1</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Acesse o Terminal SSH</h4>
                                        <p>No cPanel, procure por "Terminal" ou use um cliente SSH.</p>
                                    </div>
                                </div>
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">2</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Navegue até a pasta do plugin</h4>
                                        <div class="tainacan-ai-code-block">
                                            <button class="tainacan-ai-copy-code">Copiar</button>
                                            <code>cd public_html/wp-content/plugins/tainacan-ai</code>
                                        </div>
                                    </div>
                                </div>
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">3</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Instale o Composer (se necessário)</h4>
                                        <div class="tainacan-ai-code-block">
                                            <button class="tainacan-ai-copy-code">Copiar</button>
                                            <code>curl -sS https://getcomposer.org/installer | php</code>
                                        </div>
                                    </div>
                                </div>
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">4</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Execute o Composer</h4>
                                        <div class="tainacan-ai-code-block">
                                            <button class="tainacan-ai-copy-code">Copiar</button>
                                            <code>php composer.phar require smalot/pdfparser</code>
                                        </div>
                                    </div>
                                </div>
                            `,
						},
						{
							id: 'manual',
							name: 'Manual (FTP)',
							content: `
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">1</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Instale localmente no seu computador</h4>
                                        <p>Execute em uma pasta temporária:</p>
                                        <div class="tainacan-ai-code-block">
                                            <button class="tainacan-ai-copy-code">Copiar</button>
                                            <code>composer require smalot/pdfparser</code>
                                        </div>
                                    </div>
                                </div>
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">2</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Envie a pasta "vendor" via FTP</h4>
                                        <p>Use FileZilla ou outro cliente FTP para enviar a pasta "vendor" gerada para:</p>
                                        <div class="tainacan-ai-code-block">
                                            <code>/wp-content/plugins/tainacan-ai/vendor/</code>
                                        </div>
                                    </div>
                                </div>
                            `,
						},
					],
				},
				exif: {
					title: 'Habilitar Extensão EXIF do PHP',
					description:
						'A extensão EXIF permite extrair metadados técnicos de imagens (câmera, data, GPS, etc).',
					tabs: [
						{
							id: 'localhost',
							name: 'Localhost (XAMPP)',
							content: `
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">1</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Abra o arquivo php.ini</h4>
                                        <p>Localização típica no XAMPP:</p>
                                        <div class="tainacan-ai-code-block">
                                            <code>C:\\xampp\\php\\php.ini</code>
                                        </div>
                                    </div>
                                </div>
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">2</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Descomente a linha da extensão EXIF</h4>
                                        <p>Procure por <code>;extension=exif</code> e remova o ponto-e-vírgula:</p>
                                        <div class="tainacan-ai-code-block">
                                            <code>extension=exif</code>
                                        </div>
                                    </div>
                                </div>
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">3</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Reinicie o Apache</h4>
                                        <p>Pare e inicie novamente o Apache no Painel de Controle do XAMPP.</p>
                                    </div>
                                </div>
                            `,
						},
						{
							id: 'hostinger',
							name: 'Hostinger',
							content: `
                                <div class="tainacan-ai-prompt-info">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <p><strong>Boa notícia!</strong> A Hostinger geralmente já tem a extensão EXIF habilitada por padrão.</p>
                                </div>
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">1</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Verifique no painel</h4>
                                        <p>Acesse <strong>Avançado → Configuração PHP</strong> e procure pela extensão EXIF.</p>
                                    </div>
                                </div>
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">2</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Se não estiver habilitada</h4>
                                        <p>Entre em contato com o suporte da Hostinger para solicitar a ativação da extensão EXIF.</p>
                                    </div>
                                </div>
                            `,
						},
						{
							id: 'cpanel',
							name: 'cPanel',
							content: `
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">1</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Acesse o Seletor de PHP</h4>
                                        <p>No cPanel, procure por <strong>"Select PHP Version"</strong> ou <strong>"MultiPHP INI Editor"</strong>.</p>
                                    </div>
                                </div>
                                <div class="tainacan-ai-step">
                                    <div class="tainacan-ai-step-number">2</div>
                                    <div class="tainacan-ai-step-content">
                                        <h4>Habilite a extensão EXIF</h4>
                                        <p>Na lista de extensões, marque a caixa <strong>exif</strong> e salve.</p>
                                    </div>
                                </div>
                            `,
						},
					],
				},
			};

			const dep = instructions[ depType ];
			if ( ! dep ) return '';

			let tabsHtml = '';
			let contentHtml = '';

			dep.tabs.forEach( ( tab, index ) => {
				tabsHtml += `<button class="tainacan-ai-install-tab ${
					index === 0 ? 'active' : ''
				}" data-tab="${ tab.id }">${ tab.name }</button>`;
				contentHtml += `<div class="tainacan-ai-install-content ${
					index === 0 ? 'active' : ''
				}" data-content="${ tab.id }">${ tab.content }</div>`;
			} );

			return `
                <div class="tainacan-ai-modal-overlay">
                    <div class="tainacan-ai-modal">
                        <div class="tainacan-ai-modal-header">
                            <h3><span class="dashicons dashicons-admin-tools"></span> ${ dep.title }</h3>
                            <button class="tainacan-ai-modal-close">&times;</button>
                        </div>
                        <div class="tainacan-ai-modal-body">
                            <p>${ dep.description }</p>
                            <div class="tainacan-ai-install-tabs">${ tabsHtml }</div>
                            ${ contentHtml }
                        </div>
                        <div class="tainacan-ai-modal-footer">
                            <button class="button tainacan-ai-modal-close">Fechar</button>
                        </div>
                    </div>
                </div>
            `;
		},

		closeModal( e ) {
			if (
				$( e.target ).hasClass( 'tainacan-ai-modal-overlay' ) ||
				$( e.target ).hasClass( 'tainacan-ai-modal-close' ) ||
				$( e.target ).parent().hasClass( 'tainacan-ai-modal-close' )
			) {
				$( '.tainacan-ai-modal-overlay' ).remove();
			}
		},

		switchInstallTab( e ) {
			const tabId = $( e.currentTarget ).data( 'tab' );
			$( '.tainacan-ai-install-tab' ).removeClass( 'active' );
			$( e.currentTarget ).addClass( 'active' );
			$( '.tainacan-ai-install-content' ).removeClass( 'active' );
			$(
				`.tainacan-ai-install-content[data-content="${ tabId }"]`
			).addClass( 'active' );
		},

		copyCode( e ) {
			const $block = $( e.currentTarget ).closest(
				'.tainacan-ai-code-block'
			);
			const code = $block.find( 'code' ).text();

			navigator.clipboard.writeText( code ).then( () => {
				const $btn = $( e.currentTarget );
				$btn.text( 'Copiado!' );
				setTimeout( () => $btn.text( 'Copiar' ), 2000 );
			} );
		},

		showNotice( message, type = 'success' ) {
			const $notice = $( `
                <div class="tainacan-ai-toast ${ type }">
                    <span class="dashicons dashicons-${
						type === 'success' ? 'yes-alt' : 'warning'
					}"></span>
                    ${ message }
                </div>
            ` ).appendTo( 'body' );

			setTimeout( () => $notice.addClass( 'visible' ), 10 );
			setTimeout( () => {
				$notice.removeClass( 'visible' );
				setTimeout( () => $notice.remove(), 300 );
			}, 3000 );
		},
	};

	// Toast styles (inline para garantir funcionamento)
	const toastStyles = `
        .tainacan-ai-toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--tainacan-ai-white);
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 100001;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
        }
        .tainacan-ai-toast.visible {
            transform: translateY(0);
            opacity: 1;
        }
        .tainacan-ai-toast.success {
            border-left: 4px solid var(--tainacan-ai-success);
        }
        .tainacan-ai-toast.success .dashicons {
            color: var(--tainacan-ai-success);
        }
        .tainacan-ai-toast.error {
            border-left: 4px solid var(--tainacan-ai-error);
        }
        .tainacan-ai-toast.error .dashicons {
            color: var(--tainacan-ai-error);
        }
        .dashicons.spin {
            animation: tcgpt-spin 1s linear infinite;
        }
        @keyframes tcgpt-spin {
            to { transform: rotate(360deg); }
        }
    `;
	$( '<style>' ).text( toastStyles ).appendTo( 'head' );

	$( document ).ready( () => Admin.init() );
} )( jQuery );
