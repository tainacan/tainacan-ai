/**
 * Tainacan AI - Admin JavaScript
 * @version 1.0.0
 */
( function ( $ ) {
	'use strict';

	const Admin = {
		init() {
			this.bindEvents();
			this.syncCollapsedCardStates();
			this.populateMappingCollections();
			this.collectionMetadata = [];
			this.currentMapping = {};
		},

		bindEvents() {
			// Clear cache
			$( '#clear-all-cache' ).on(
				'click',
				this.clearAllCache.bind( this )
			);

			// Toggle cards
			$( '.tainacan-ai-toggle-card' ).on( 'click', this.toggleCard );

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
		},

		clearAllCache() {
			const $btn = $( '#clear-all-cache' );
			const originalHtml = $btn.html();

			if ( ! confirm( TainacanAIAdmin.texts.confirmClearCache || 'Are you sure you want to clear all cache?' ) ) {
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
			$card.toggleClass( 'is-collapsed', $body.hasClass( 'collapsed' ) );
			$icon.toggleClass(
				'dashicons-arrow-down-alt2 dashicons-arrow-up-alt2'
			);
		},

		syncCollapsedCardStates() {
			$( '.tainacan-ai-card' ).each( function () {
				const $card = $( this );
				const isCollapsed = $card
					.find( '.tainacan-ai-collapsible' )
					.hasClass( 'collapsed' );
				$card.toggleClass( 'is-collapsed', isCollapsed );
			} );
		},

		populateMappingCollections() {
			// Collections are already populated directly in PHP/HTML
			// This function is now just a placeholder for compatibility
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
				'<div class="tainacan-ai-loading"><span class="tainacan-ai-spinner"></span> ' + ( TainacanAIAdmin.texts.loadingMetadata || 'Loading metadata...' ) + '</div>'
			);

			// Load collection metadata
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
							'<p class="error">' + ( TainacanAIAdmin.texts.errorLoadingMetadata || 'Error loading metadata.' ) + '</p>'
						);
					}
				},
				error: () => {
					$( '#metadata-mapping-list' ).html(
						'<p class="error">' + ( TainacanAIAdmin.texts.errorLoadingMetadata || 'Error loading metadata.' ) + '</p>'
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

			// Default AI fields that can be mapped
			const defaultAiFields = [
				{ key: 'titulo', label: TainacanAIAdmin.texts.title || 'Title' },
				{ key: 'descricao', label: TainacanAIAdmin.texts.description || 'Description' },
				{ key: 'autor', label: TainacanAIAdmin.texts.author || 'Author' },
				{ key: 'data', label: TainacanAIAdmin.texts.date || 'Date' },
				{ key: 'assunto', label: TainacanAIAdmin.texts.subject || 'Subject' },
				{ key: 'tipo', label: TainacanAIAdmin.texts.type || 'Type' },
				{ key: 'formato', label: TainacanAIAdmin.texts.format || 'Format' },
				{ key: 'idioma', label: TainacanAIAdmin.texts.language || 'Language' },
				{ key: 'fonte', label: TainacanAIAdmin.texts.source || 'Source' },
				{ key: 'direitos', label: TainacanAIAdmin.texts.rights || 'Rights' },
				{ key: 'cobertura', label: TainacanAIAdmin.texts.coverage || 'Coverage' },
				{ key: 'editor', label: TainacanAIAdmin.texts.publisher || 'Publisher' },
				{ key: 'contribuidor', label: TainacanAIAdmin.texts.contributor || 'Contributor' },
				{ key: 'relacao', label: TainacanAIAdmin.texts.relation || 'Relation' },
				{ key: 'identificador', label: TainacanAIAdmin.texts.identifier || 'Identifier' },
			];

			// Add custom fields that are in the current mapping
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

			// Create metadata selector
			const metadataOptions = this.collectionMetadata
				.map(
					( meta ) =>
						`<option value="${ meta.id }">${ meta.name }</option>`
				)
				.join( '' );

			// Render each mapping row
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
                                <option value="">${ TainacanAIAdmin.texts.doNotMap || '-- Do not map --' }</option>
                                ${ metadataOptions }
                            </select>
                        </div>
                        ${
							field.custom
								? `<button type="button" class="button button-small remove-mapping-row" title="${ TainacanAIAdmin.texts.remove || 'Remove' }">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>`
								: '<span class="tainacan-ai-mapping-action-slot" aria-hidden="true"></span>'
						}
                    </div>
                `;

				$container.append( row );

				// Set selected value
				if ( selectedId ) {
					$container
						.find(
							`.tainacan-ai-mapping-row[data-ai-field="${ field.key }"] .metadata-select`
						)
						.val( selectedId );
				}
			} );

			// Button to add custom field
			$container.append( `
                <div class="tainacan-ai-mapping-add">
                    <button type="button" class="button button-small" id="add-mapping-row">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        ${ TainacanAIAdmin.texts.addCustomField || 'Add custom field' }
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
                        <input type="text" class="ai-field-name" placeholder="${ TainacanAIAdmin.texts.aiFieldName || 'AI field name' }" />
                    </div>
                    <div class="tainacan-ai-mapping-arrow">
                        <span class="dashicons dashicons-arrow-right-alt"></span>
                    </div>
                    <div class="tainacan-ai-mapping-metadata">
                        <select class="metadata-select">
                            <option value="">${ TainacanAIAdmin.texts.doNotMap || '-- Do not map --' }</option>
                            ${ metadataOptions }
                        </select>
                    </div>
                    <button type="button" class="button button-small remove-mapping-row" title="${ TainacanAIAdmin.texts.remove || 'Remove' }">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
            `;

			// Insert before add button
			$( '.tainacan-ai-mapping-add' ).before( row );
		},

		removeMappingRow( e ) {
			$( e.currentTarget ).closest( '.tainacan-ai-mapping-row' ).remove();
		},

		saveMetadataMapping() {
			const collectionId = $( '#mapping-collection-select' ).val();
			if ( ! collectionId ) {
				Admin.showNotice( TainacanAIAdmin.texts.selectCollectionFirst || 'Select a collection first.', 'error' );
				return;
			}

			const $btn = $( '#save-metadata-mapping' );
			const originalHtml = $btn.html();

			// Collect mapping
			const mapping = {};
			$( '.tainacan-ai-mapping-row' ).each( function () {
				let aiField = $( this ).data( 'ai-field' );
				const $nameInput = $( this ).find( '.ai-field-name' );

				// If custom field, use input value
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
				Admin.showNotice( TainacanAIAdmin.texts.selectCollectionFirst || 'Select a collection first.', 'error' );
				return;
			}

			const $btn = $( '#auto-detect-mapping' );
			const originalHtml = $btn.html();

			$btn.prop( 'disabled', true ).html(
				'<span class="dashicons dashicons-update spin"></span> ' + ( TainacanAIAdmin.texts.detecting || 'Detecting...' )
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
							`${ count } ${ TainacanAIAdmin.texts.fieldsDetected || 'field(s) detected automatically!' }`,
							'success'
						);
					} else {
						Admin.showNotice(
							TainacanAIAdmin.texts.errorDetectingMapping || 'Error detecting mapping.',
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
				! confirm( TainacanAIAdmin.texts.confirmClearMapping || 'Are you sure you want to clear all mapping?' )
			)
				return;

			this.currentMapping = {};
			this.renderMappingEditor();
			Admin.showNotice(
				TainacanAIAdmin.texts.mappingCleared || 'Mapping cleared. Click "Save" to confirm.',
				'success'
			);
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

	// Toast styles (inline to ensure functionality)
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
