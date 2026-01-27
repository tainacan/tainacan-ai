/**
 * Tainacan AI - Item Form JavaScript
 * @version 1.0.0
 */
import { addAction } from '@wordpress/hooks';

( function ( $ ) {
	'use strict';

	const TainacanAIApp = {
		// State
		state: {
			itemId: null,
			collectionId: null,
			attachmentId: null,
			documentInfo: null,
			isAnalyzing: false,
			lastResult: null,
			panelOpen: false,
			isItemEditContext: false,
		},

		// DOM elements
		elements: {},

		// Scoped observer for .document-field (disconnect when leaving item edit)
		documentFieldObserver: null,
		documentFieldObservedNode: null,
		documentFieldDebounceMs: 750,
		documentFieldDebounceTimer: null,

		/**
		 * Initialize the application
		 */
		init() {
			this.bindEvents();
			this.createSidebarPanel();
			this.registerHooks();

			if ( TainacanAI.debug ) {
				console.log( '[TainacanAI] Initialized', this.state );
			}
		},

		/**
		 * Cache DOM elements
		 */
		cacheElements() {
			this.elements = {
				widget: $( '#tainacan-ai-widget' ),
				analyzeBtn: $( '#tainacan-ai-analyze' ),
				refreshBtn: $( '#tainacan-ai-refresh' ),
				status: $( '#tainacan-ai-status' ),
				results: $( '#tainacan-ai-results' ),
				resultsContent: $( '#tainacan-ai-results-content' ),
				exifContent: $( '#tainacan-ai-exif-content' ),
				cacheBadge: $( '#tainacan-ai-cache-badge' ),
				documentInfo: $( '#tainacan-ai-document-info' ),
				docType: $( '#tainacan-ai-doc-type' ),
				docName: $( '#tainacan-ai-doc-name' ),
				tabExif: $( '#tainacan-ai-tab-exif' ),
				modelInfo: $( '#tainacan-ai-model' ),
				tokensInfo: $( '#tainacan-ai-tokens' ),
				copyAllBtn: $( '#tainacan-ai-copy-all' ),
			};
		},

		/**
		 * Create the sidebar panel
		 */
		createSidebarPanel() {
			// Remove if already exists
			$( '.tainacan-ai-sidebar-panel' ).remove();
			$( '.tainacan-ai-sidebar-overlay' ).remove();
			$( '.tainacan-ai-panel-indicator' ).remove();

			// Overlay
			$( 'body' ).append(
				'<div class="tainacan-ai-sidebar-overlay"></div>'
			);

			// Side indicator (appears when panel is closed and there are results)
			$( 'body' ).append( `
                <div class="tainacan-ai-panel-indicator" title="${ TainacanAI.texts?.openResults || 'Open analysis results' }">
                    <span class="dashicons dashicons-arrow-left-alt2"></span>
                </div>
            ` );

			// Sidebar panel
			const panelHtml = `
                <div class="tainacan-ai-sidebar-panel">
                    <div class="tainacan-ai-sidebar-header">
                        <h3>
                            <span class="dashicons dashicons-format-aside"></span>
                            ${
								TainacanAI.texts?.analysisResults ||
								'Analysis Results'
							}
                        </h3>
                        <button type="button" class="tainacan-ai-sidebar-close" title="${ TainacanAI.texts?.close || 'Close' }">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                    <div class="tainacan-ai-sidebar-actions">
                        <button type="button" class="button button-primary" id="tainacan-ai-fill-all" title="${
							TainacanAI.texts?.fillAllTooltip ||
							'Automatically fills Tainacan fields with extracted values'
						}">
                            <span class="dashicons dashicons-download"></span>
                            ${ TainacanAI.texts?.fillAll || 'Fill Fields' }
                        </button>
                        <button type="button" class="button button-secondary" id="tainacan-ai-panel-copy-all">
                            <span class="dashicons dashicons-admin-page"></span>
                            ${ TainacanAI.texts?.copyAll || 'Copy All' }
                        </button>
                        <button type="button" class="button button-secondary" id="tainacan-ai-panel-refresh">
                            <span class="dashicons dashicons-update"></span>
                            ${ TainacanAI.texts?.newAnalysis || 'New Analysis' }
                        </button>
                    </div>
                    <div class="tainacan-ai-sidebar-body" id="tainacan-ai-sidebar-content">
                        <!-- Content will be inserted here -->
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

			$( 'body' ).append( panelHtml );

			// Cache new elements
			this.elements.sidebarPanel = $( '.tainacan-ai-sidebar-panel' );
			this.elements.sidebarOverlay = $( '.tainacan-ai-sidebar-overlay' );
			this.elements.sidebarContent = $( '#tainacan-ai-sidebar-content' );
			this.elements.panelIndicator = $( '.tainacan-ai-panel-indicator' );
		},

		/**
		 * Bind events
		 */
		bindEvents() {
			// Analyze button
			$( document ).on( 'click', '#tainacan-ai-analyze', ( e ) => {
				e.preventDefault();
				this.analyze( false );
			} );

			// Refresh button (forces new analysis)
			$( document ).on( 'click', '#tainacan-ai-refresh', ( e ) => {
				e.preventDefault();
				this.analyze( true );
			} );

			// Tabs
			$( document ).on( 'click', '.tainacan-ai-tab', ( e ) => {
				this.switchTab( $( e.currentTarget ).data( 'tab' ) );
			} );

			// Copy individual value
			$( document ).on(
				'click',
				'.tainacan-ai-copy-btn, .tainacan-ai-copy-mini',
				( e ) => {
					this.copyValue( $( e.currentTarget ) );
				}
			);

			// Copy all
			$( document ).on(
				'click',
				'#tainacan-ai-copy-all, #tainacan-ai-panel-copy-all',
				() => {
					this.copyAllValues();
				}
			);

			// Close sidebar panel (only via X button, not via overlay)
			$( document ).on( 'click', '.tainacan-ai-sidebar-close', () => {
				this.closeSidebarPanel();
			} );

			// Fill individual field
			$( document ).on( 'click', '.tainacan-ai-fill-field', ( e ) => {
				const $btn = $( e.currentTarget );
				const metadataKey = $btn.data( 'metadata-key' );
				const value = $btn.data( 'value' );
				this.fillTainacanField( metadataKey, value, $btn );
			} );

			// Fill all mapped fields
			$( document ).on( 'click', '#tainacan-ai-fill-all', () => {
				this.fillAllMappedFields();
			} );

			// Open panel via indicator
			$( document ).on( 'click', '.tainacan-ai-panel-indicator', () => {
				this.openSidebarPanel();
			} );

			// New analysis from panel
			$( document ).on( 'click', '#tainacan-ai-panel-refresh', ( e ) => {
				e.preventDefault();
				this.analyze( true );
			} );

			// ESC key to close
			$( document ).on( 'keydown', ( e ) => {
				if ( e.key === 'Escape' && this.state.panelOpen ) {
					this.closeSidebarPanel();
				}
			} );

			// Close panel when Tainacan save is clicked
			$( document ).on(
				'click',
				'.tainacan-form button[type="submit"], .item-page button.is-success, [class*="submit-button"]',
				() => {
					setTimeout( () => this.closeSidebarPanel(), 500 );
				}
			);
		},

		/**
		 * Open the sidebar panel
		 */
		openSidebarPanel() {
			// Ensure panel exists
			if (
				! this.elements.sidebarPanel ||
				! this.elements.sidebarPanel.length
			) {
				this.createSidebarPanel();
			}

			if ( this.elements.sidebarPanel ) {
				this.elements.sidebarPanel.addClass( 'open' );
			}
			if ( this.elements.sidebarOverlay ) {
				this.elements.sidebarOverlay.addClass( 'visible' );
			}
			if ( this.elements.panelIndicator ) {
				this.elements.panelIndicator.removeClass( 'visible' );
			}
			this.state.panelOpen = true;
		},

		/**
		 * Close the sidebar panel
		 */
		closeSidebarPanel() {
			if ( this.elements.sidebarPanel ) {
				this.elements.sidebarPanel.removeClass( 'open' );
			}
			if ( this.elements.sidebarOverlay ) {
				this.elements.sidebarOverlay.removeClass( 'visible' );
			}
			this.state.panelOpen = false;

			// Show indicator if there are results
			if ( this.state.lastResult && this.elements.panelIndicator ) {
				this.elements.panelIndicator.addClass( 'visible' );
			}
		},

		/**
		 * Reset analysis state (when changing items)
		 */
		resetAnalysisState() {
			this.state.lastResult = null;
			this.state.attachmentId = null;
			this.state.documentInfo = null;

			// Hide indicator
			if ( this.elements.panelIndicator ) {
				this.elements.panelIndicator.removeClass( 'visible' );
			}

			// Clear panel content
			if ( this.elements.sidebarPanel ) {
				this.elements.sidebarPanel.find( '.tainacan-ai-panel-body' )
					.html( `
                    <div class="tainacan-ai-panel-placeholder">
                        <span class="dashicons dashicons-search"></span>
                        <p>${ TainacanAI.texts?.clickToAnalyze || 'Click "Analyze Document" to extract metadata' }</p>
                    </div>
                ` );
			}

			if ( TainacanAI.debug ) {
				console.log( '[TainacanAI] Analysis state reset' );
			}
		},

		/**
		 * Register Tainacan wp.hooks listeners for item-edit context and document updates.
		 * Replaces body-wide MutationObserver and hashchange; "left item edit" is detected
		 * from tainacan_navigation_path_updated payload.
		 */
		registerHooks() {
			if ( typeof addAction !== 'function' ) {
				return;
			}

			addAction(
				'tainacan_navigation_path_updated',
				'tainacan_ai_item_form',
				( payload ) => {
					const path = payload?.childEntity?.defaultLink || payload?.currentRoute?.path || '';
					const editMatch = path.match( /collections\/(\d+)\/items\/(\d+)\/edit/ );
					const isItemEdit = Boolean( editMatch );

					if ( isItemEdit ) {
						const prevCollectionId = this.state.collectionId;
						this.state.isItemEditContext = true;
						this.state.collectionId = parseInt( editMatch[ 1 ], 10 );
						this.state.itemId = parseInt( editMatch[ 2 ], 10 );
						if ( this.state.collectionId && this.state.collectionId !== prevCollectionId ) {
							this.fetchMetadataMapping();
						}
					} else {
						this.leaveItemEditContext();
					}
				}
			);

			addAction(
				'tainacan_item_edition_item_loaded',
				'tainacan_ai_item_form',
				( collection, item ) => {
					if ( ! collection || ! item ) {
						return;
					}
					const prevCollectionId = this.state.collectionId;
					const newCollectionId = collection.id ? parseInt( collection.id, 10 ) : null;
					this.state.isItemEditContext = true;
					this.state.collectionId = newCollectionId;
					this.state.itemId = item.id ? parseInt( item.id, 10 ) : null;
					this.state.documentInfo = null;
					this.state.attachmentId = null;

					this.cacheElements();

					if ( newCollectionId && newCollectionId !== prevCollectionId ) {
						this.fetchMetadataMapping();
					}
					this.detectDocument();
					this.startDocumentFieldObserver();
				}
			);
		},

		/**
		 * Clear state and disconnect observer when we leave item-edit context.
		 */
		leaveItemEditContext() {
			this.state.isItemEditContext = false;
			this.state.itemId = null;
			this.state.collectionId = null;
			this.state.documentInfo = null;
			this.state.attachmentId = null;
			this.closeSidebarPanel();
			this.resetAnalysisState();
			this.stopDocumentFieldObserver();
		},

		/**
		 * Start a MutationObserver on .document-field to re-run detectDocument when
		 * the document UI changes (edit/delete/add). Debounced so that rapid mutations
		 * (e.g. empty shell then async document content) result in a single call.
		 */
		startDocumentFieldObserver() {
			this.stopDocumentFieldObserver();
			const node = document.querySelector( '.document-field' );
			if ( ! node ) {
				return;
			}
			const app = this;
			const delay = app.documentFieldDebounceMs;
			this.documentFieldObserver = new MutationObserver( () => {
				if ( ! app.state.isItemEditContext || ! app.state.itemId ) {
					return;
				}
				clearTimeout( app.documentFieldDebounceTimer );
				app.documentFieldDebounceTimer = setTimeout( () => {
					app.documentFieldDebounceTimer = null;
					app.detectDocument();
				}, delay );
			} );
			this.documentFieldObserver.observe( node, {
				childList: true,
				subtree: true,
			} );
			this.documentFieldObservedNode = node;
		},

		/**
		 * Disconnect the .document-field observer when leaving item-edit context.
		 */
		stopDocumentFieldObserver() {
			if ( this.documentFieldDebounceTimer ) {
				clearTimeout( this.documentFieldDebounceTimer );
				this.documentFieldDebounceTimer = null;
			}
			if ( this.documentFieldObserver && this.documentFieldObservedNode ) {
				this.documentFieldObserver.disconnect();
				this.documentFieldObserver = null;
				this.documentFieldObservedNode = null;
			}
		},

		/**
		 * Fetch metadata mapping via AJAX
		 */
		async fetchMetadataMapping() {
			if ( ! this.state.collectionId ) return;

			try {
				const response = await $.ajax( {
					url: TainacanAI.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tainacan_ai_get_item_mapping',
						nonce: TainacanAI.nonce,
						collection_id: this.state.collectionId,
					},
				} );

				if ( response.success && response.data ) {
					TainacanAI.metadataMapping = response.data;
					if ( TainacanAI.debug ) {
						console.log(
							'[TainacanAI] Mapping updated via AJAX:',
							response.data
						);
					}
				}
			} catch ( error ) {
				console.error(
					'[TainacanAI] Error fetching mapping:',
					error
				);
			}
		},

		/**
		 * Detect item document
		 */
		async detectDocument() {
			if ( ! this.state.itemId ) return;

			try {
				const response = await $.ajax( {
					url: TainacanAI.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tainacan_ai_get_item_document',
						nonce: TainacanAI.nonce,
						item_id: this.state.itemId,
					},
				} );

				if ( response.success && response.data ) {
					this.state.documentInfo = response.data;
					this.state.attachmentId = response.data.id;
					this.showDocumentInfo( response.data );

					if ( TainacanAI.debug ) {
						console.log( '[TainacanAI] Document detected:', response.data );
					}
				} else {
					this.state.documentInfo = null;
					this.state.attachmentId = null;
					this.showNoDocumentInfo();
				}
			} catch ( error ) {
				if ( TainacanAI.debug ) {
					console.error( '[TainacanAI] No document found' );
				}
				this.state.documentInfo = null;
				this.state.attachmentId = null;
				this.showNoDocumentInfo();
			}
		},

		/**
		 * Show message in document info section when no document was detected yet.
		 */
		showNoDocumentInfo() {
			const msg = TainacanAI.texts?.noDocument || 'No document found in this item.';
			this.elements.docType.html(
				'<span class="dashicons dashicons-info"></span> ' + msg
			);
			this.elements.docName.text( '' );
			this.elements.documentInfo.show();
			this.elements.analyzeBtn.addClass( 'tainacan-ai-forced-hidden' );
			this.elements.refreshBtn.addClass( 'tainacan-ai-forced-hidden' );
		},

		/**
		 * Display detected document information
		 */
		showDocumentInfo( doc ) {
			if ( ! doc ) return;

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
				`<span class="dashicons ${
					typeIcons[ doc.type ] || 'dashicons-media-default'
				}"></span> ` + ( typeLabels[ doc.type ] || doc.type )
			);
			this.elements.docName.text( doc.title || '' );
			this.elements.documentInfo.show();
			this.elements.analyzeBtn.removeClass( 'tainacan-ai-forced-hidden' );
			this.elements.refreshBtn.removeClass( 'tainacan-ai-forced-hidden' );
		},

		/**
		 * Execute analysis
		 */
		async analyze( forceRefresh = false ) {
			if ( this.state.isAnalyzing ) return;

			// Check if we have item or attachment
			if ( ! this.state.itemId && ! this.state.attachmentId ) {
				this.showError( TainacanAI.texts.noDocument );
				return;
			}

			this.state.isAnalyzing = true;
			this.showLoading();

			let hasError = false;

			try {
				const response = await $.ajax( {
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
				} );

				if ( response.success ) {
					this.state.lastResult = response.data.result;
					this.displayResults(
						response.data.result,
						response.data.from_cache
					);
				} else {
					hasError = true;
					this.showError( response.data || TainacanAI.texts.error );
				}
			} catch ( error ) {
				hasError = true;
				console.error( '[TainacanAI] Analysis error:', error );
				this.showError(
					error.responseJSON?.data || TainacanAI.texts.error
				);
			} finally {
				this.state.isAnalyzing = false;
				if ( ! hasError ) {
					this.hideLoading();
				}
			}
		},

		/**
		 * Display results
		 */
		displayResults( result, fromCache ) {
			// Cache badge
			if ( fromCache ) {
				this.elements.cacheBadge.show();
			} else {
				this.elements.cacheBadge.hide();
			}

			// AI metadata - render in sidebar panel
			if ( result.ai_metadata ) {
				this.renderMetadataInPanel( result.ai_metadata );
			}

			// EXIF (show in area below button if available)
			if ( result.exif && Object.keys( result.exif ).length > 0 ) {
				this.elements.tabExif.show();
				this.elements.results.show();
				this.renderExif( result.exif );
			} else {
				this.elements.tabExif.hide();
				this.elements.results.hide();
			}

			// Analysis info in sidebar panel
			if ( result.model ) {
				$( '#tainacan-ai-panel-model .model-name' ).text(
					result.model
				);
			}
			if ( result.tokens_used ) {
				$( '#tainacan-ai-panel-tokens .tokens-count' ).text(
					`${ result.tokens_used } ${ TainacanAI.texts?.tokens || 'tokens' }`
				);
			}

			// Open sidebar panel automatically
			this.openSidebarPanel();
		},

		/**
		 * Render metadata in sidebar panel with evidence
		 */
		renderMetadataInPanel( metadata ) {
			// Ensure panel exists
			if (
				! this.elements.sidebarContent ||
				! this.elements.sidebarContent.length
			) {
				this.createSidebarPanel();
			}

			const $container = this.elements.sidebarContent;
			if ( ! $container || ! $container.length ) {
				console.error(
					'[TainacanAI] Sidebar content container not found'
				);
				return;
			}

			$container.empty();

			Object.entries( metadata ).forEach( ( [ key, data ], index ) => {
				const formattedLabel = this.formatLabel( key );

				// Check if data has new format with evidence
				let value, evidence;
				if ( data && typeof data === 'object' && 'valor' in data ) {
					value = data.valor;
					evidence = data.evidencia;
				} else {
					value = data;
					evidence = null;
				}

				const formattedValue = this.formatValue( value );
				// Convert arrays to comma-separated string for field filling
				let rawValue;
				if ( Array.isArray( value ) ) {
					rawValue = value.join( ', ' );
				} else if ( typeof value === 'string' ) {
					rawValue = value;
				} else if ( value === null || value === undefined ) {
					rawValue = '';
				} else {
					rawValue = JSON.stringify( value );
				}
				const isEmpty =
					value === null ||
					value === undefined ||
					( Array.isArray( value ) && value.length === 0 ) ||
					value === '';

				// Check if there's mapping for this field
				const mapping = TainacanAI.metadataMapping || {};
				const mappedField = mapping[ key ];
				const hasMappedField = mappedField && ! isEmpty;

				const $item = $( `
                    <div class="tainacan-ai-metadata-item-with-evidence ${
						isEmpty ? 'empty' : ''
					}"
                         style="animation-delay: ${ index * 0.05 }s"
                         data-metadata-key="${ this.escapeHtml( key ) }">
                        <div class="tainacan-ai-metadata-main">
                            <div class="tainacan-ai-metadata-top">
                                <div class="tainacan-ai-metadata-label-with-copy">
                                    <span class="tainacan-ai-metadata-label">${ formattedLabel }</span>
                                    ${
										hasMappedField
											? `<span class="tainacan-ai-mapped-badge" title="${ TainacanAI.texts?.mappedTo || 'Mapped to: ' }${ this.escapeHtml(
													mappedField.name ||
														mappedField
											  ) }">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                    </span>`
											: ''
									}
                                </div>
                                <div class="tainacan-ai-metadata-actions-mini">
                                    ${
										hasMappedField
											? `
                                    <button type="button" class="tainacan-ai-fill-field"
                                            data-metadata-key="${ this.escapeHtml(
												key
											) }"
                                            data-value="${ this.escapeHtml(
												rawValue
											) }"
                                            title="${
												TainacanAI.texts?.fillField ||
												'Fill field'
											}">
                                        <span class="dashicons dashicons-download"></span>
                                    </button>
                                    `
											: ''
									}
                                    <button type="button" class="tainacan-ai-copy-mini"
                                            data-value="${ this.escapeHtml(
												rawValue
											) }"
                                            title="${
												TainacanAI.texts?.copy ||
												'Copy'
											}">
                                        <span class="dashicons dashicons-clipboard"></span>
                                    </button>
                                </div>
                            </div>
                            <div class="tainacan-ai-metadata-value-box">
                                <div class="tainacan-ai-metadata-value-text">${ formattedValue }</div>
                            </div>
                        </div>
                        ${
							evidence
								? `
                        <div class="tainacan-ai-metadata-evidence">
                            <div class="tainacan-ai-evidence-label">
                                <span class="dashicons dashicons-search"></span>
                                ${ TainacanAI.texts?.evidence || 'Evidence' }
                            </div>
                            <div class="tainacan-ai-evidence-text">${ this.escapeHtml(
								evidence
							) }</div>
                        </div>
                        `
								: ''
						}
                    </div>
                ` );

				$container.append( $item );
			} );
		},

		/**
		 * Render EXIF data
		 */
		renderExif( exif ) {
			const $container = this.elements.exifContent;
			$container.empty();

			const renderSection = ( title, data ) => {
				if ( ! data || Object.keys( data ).length === 0 ) return;

				const $section = $( `
                    <div class="tainacan-ai-exif-section">
                        <h6>${ title }</h6>
                        <div class="tainacan-ai-exif-grid"></div>
                    </div>
                ` );

				const $grid = $section.find( '.tainacan-ai-exif-grid' );

				Object.entries( data ).forEach( ( [ key, value ] ) => {
					if ( value !== null && value !== undefined ) {
						$grid.append( `
                            <div class="tainacan-ai-exif-item">
                                <span class="tainacan-ai-exif-label">${ this.formatLabel(
									key
								) }</span>
                                <span class="tainacan-ai-exif-value">${ this.escapeHtml(
									String( value )
								) }</span>
                            </div>
                        ` );
					}
				} );

				$container.append( $section );
			};

			const sectionTitles = {
				camera: TainacanAI.texts?.camera || 'Camera',
				captura: TainacanAI.texts?.capture || 'Capture',
				imagem: TainacanAI.texts?.image || 'Image',
				gps: TainacanAI.texts?.location || 'Location',
				autoria: TainacanAI.texts?.authorship || 'Authorship',
			};

			Object.entries( exif ).forEach( ( [ section, data ] ) => {
				renderSection( sectionTitles[ section ] || section, data );
			} );

			if ( exif.gps?.google_maps_link ) {
				$container.append( `
                    <div class="tainacan-ai-exif-map">
                        <a href="${ exif.gps.google_maps_link }" target="_blank" class="button">
                            <span class="dashicons dashicons-location"></span>
                            ${ TainacanAI.texts?.viewOnGoogleMaps || 'View on Google Maps' }
                        </a>
                    </div>
                ` );
			}
		},

		/**
		 * Switch between tabs
		 */
		switchTab( tabId ) {
			$( '.tainacan-ai-tab' ).removeClass( 'active' );
			$( `.tainacan-ai-tab[data-tab="${ tabId }"]` ).addClass( 'active' );

			$( '.tainacan-ai-tab-content' ).removeClass( 'active' );
			$( `#tainacan-ai-content-${ tabId }` ).addClass( 'active' );
		},

		/**
		 * Copy value to clipboard
		 */
		async copyValue( $btn ) {
			const value = $btn.data( 'value' );

			try {
				await navigator.clipboard.writeText( value );
				this.showCopySuccess( $btn );
			} catch ( error ) {
				const $temp = $( '<textarea>' )
					.val( value )
					.appendTo( 'body' )
					.select();
				document.execCommand( 'copy' );
				$temp.remove();
				this.showCopySuccess( $btn );
			}
		},

		/**
		 * Copy all values
		 */
		async copyAllValues() {
			if ( ! this.state.lastResult?.ai_metadata ) return;

			const text = Object.entries( this.state.lastResult.ai_metadata )
				.map( ( [ key, data ] ) => {
					let value;
					if ( data && typeof data === 'object' && 'valor' in data ) {
						value = data.valor;
					} else {
						value = data;
					}
					const formattedValue =
						typeof value === 'string'
							? value
							: JSON.stringify( value );
					return `${ this.formatLabel( key ) }: ${ formattedValue }`;
				} )
				.join( '\n' );

			try {
				await navigator.clipboard.writeText( text );
				this.showToast( TainacanAI.texts?.allCopied || 'Copiado!' );
			} catch ( error ) {
				const $temp = $( '<textarea>' )
					.val( text )
					.appendTo( 'body' )
					.select();
				document.execCommand( 'copy' );
				$temp.remove();
				this.showToast( TainacanAI.texts?.allCopied || 'Copiado!' );
			}
		},

		/**
		 * Visual feedback for copy
		 */
		showCopySuccess( $btn ) {
			const $icon = $btn.find( '.dashicons' );
			$icon
				.removeClass( 'dashicons-clipboard' )
				.addClass( 'dashicons-yes' );
			$btn.addClass( 'copied' );

			setTimeout( () => {
				$icon
					.removeClass( 'dashicons-yes' )
					.addClass( 'dashicons-clipboard' );
				$btn.removeClass( 'copied' );
			}, 1500 );
		},

		/**
		 * Show loading state
		 */
		showLoading() {
			this.elements.analyzeBtn.prop( 'disabled', true );
			this.elements.refreshBtn.prop( 'disabled', true );
			this.elements.status.show();
			this.elements.results.hide();
		},

		/**
		 * Hide loading
		 */
		hideLoading() {
			this.elements.analyzeBtn.prop( 'disabled', false );
			this.elements.refreshBtn.prop( 'disabled', false );
			this.elements.status.hide();
		},

		/**
		 * Show error
		 */
		showError( message ) {
			this.elements.analyzeBtn.prop( 'disabled', false );
			this.elements.refreshBtn.prop( 'disabled', false );

			this.elements.status
				.html(
					`
                <div class="tainacan-ai-error">
                    <span class="dashicons dashicons-warning"></span>
                    <div class="tainacan-ai-error-text">
                        <strong>${ TainacanAI.texts?.errorLabel || 'Error' }</strong>
                        <span>${ message }</span>
                    </div>
                </div>
            `
				)
				.show();

			setTimeout( () => {
				this.elements.status.fadeOut();
			}, 10000 );
		},

		/**
		 * Show toast notification
		 */
		showToast( message ) {
			const $toast = $( `
                <div class="tainacan-ai-toast">
                    <span class="dashicons dashicons-yes-alt"></span>
                    ${ message }
                </div>
            ` ).appendTo( 'body' );

			setTimeout( () => {
				$toast.addClass( 'visible' );
			}, 10 );

			setTimeout( () => {
				$toast.removeClass( 'visible' );
				setTimeout( () => $toast.remove(), 300 );
			}, 2000 );
		},

		/**
		 * Format metadata label
		 */
		formatLabel( key ) {
			return key
				.replace( /_/g, ' ' )
				.replace( /([A-Z])/g, ' $1' )
				.replace( /^./, ( str ) => str.toUpperCase() )
				.trim();
		},

		/**
		 * Format value for display
		 */
		formatValue( value ) {
			if ( value === null || value === undefined || value === '' ) {
				return '<span class="tainacan-ai-empty-value">-</span>';
			}

			if ( Array.isArray( value ) ) {
				if ( value.length === 0 ) {
					return '<span class="tainacan-ai-empty-value">-</span>';
				}
				return value
					.map(
						( v ) =>
							`<span class="tainacan-ai-tag">${ this.escapeHtml(
								String( v )
							) }</span>`
					)
					.join( ' ' );
			}

			if ( typeof value === 'object' ) {
				return `<pre class="tainacan-ai-json">${ this.escapeHtml(
					JSON.stringify( value, null, 2 )
				) }</pre>`;
			}

			return this.escapeHtml( String( value ) );
		},

		/**
		 * Fill a Tainacan field with extracted value
		 */
		fillTainacanField( metadataKey, value, $btn = null ) {
			const mapping = TainacanAI.metadataMapping || {};
			const fieldInfo = mapping[ metadataKey ];

			if ( ! fieldInfo ) {
				console.log(
					`[TainacanAI] Field "${ metadataKey }" has no mapping`
				);
				return false;
			}

			// Try to find the Tainacan field
			const metadataId = fieldInfo.id || fieldInfo;
			console.log(
				`[TainacanAI] Attempting to fill "${ metadataKey }" -> id=${ metadataId }, slug=${ fieldInfo.slug }`
			);

			const $field = this.findTainacanField( metadataId, fieldInfo.slug );

			if ( ! $field || ! $field.length ) {
				console.log(
					`[TainacanAI] Field "${ metadataKey }" NOT found in DOM`
				);
				return false;
			}

			console.log(
				`[TainacanAI] Field "${ metadataKey }" FOUND:`,
				$field[ 0 ]
			);

			// Fill field based on type
			const success = this.setFieldValue( $field, value, fieldInfo.type );

			if ( success ) {
				// Visual feedback
				if ( $btn ) {
					const $icon = $btn.find( '.dashicons' );
					$icon
						.removeClass( 'dashicons-download' )
						.addClass( 'dashicons-yes' );
					$btn.addClass( 'filled' );
					setTimeout( () => {
						$icon
							.removeClass( 'dashicons-yes' )
							.addClass( 'dashicons-download' );
						$btn.removeClass( 'filled' );
					}, 2000 );
				}

				// Highlight filled field
				$field.addClass( 'tainacan-ai-field-filled' );
				setTimeout( () => {
					$field.removeClass( 'tainacan-ai-field-filled' );
				}, 3000 );

				return true;
			}

			return false;
		},

		/**
		 * Find Tainacan field in DOM
		 */
		findTainacanField( metadataId, slug ) {
			// Try several strategies to find the field
			let $field;

			// 1. MAIN: By input ID in Tainacan format (tainacan-item-metadatum_id-{ID})
			$field = $( `#tainacan-item-metadatum_id-${ metadataId }` );
			if ( $field.length ) {
				// If it's a container (div), find input/textarea inside it
				if ( $field.is( 'div, span, section' ) ) {
					const $innerField = $field
						.find( 'input:not([type="hidden"]), textarea' )
						.first();
					if ( $innerField.length ) {
						console.log(
							'[findField] Found via tainacan-item-metadatum_id (inner)'
						);
						return $innerField;
					}
				}
				// If it's input/textarea directly
				if ( $field.is( 'input, textarea' ) ) {
					console.log(
						'[findField] Found via tainacan-item-metadatum_id'
					);
					return $field;
				}
			}

			// 2. By metadata ID in Vue/Tainacan (various formats)
			$field = $(
				`[data-metadatum-id="${ metadataId }"] input:not([type="hidden"]), [data-metadatum-id="${ metadataId }"] textarea`
			).first();
			if ( $field.length ) {
				console.log( '[findField] Found via data-metadatum-id' );
				return $field;
			}

			// 3. By tainacan-metadatum-id attribute (Vue)
			$field = $(
				`[tainacan-metadatum-id="${ metadataId }"] input, [tainacan-metadatum-id="${ metadataId }"] textarea`
			).first();
			if ( $field.length ) {
				console.log(
					'[findField] Found via tainacan-metadatum-id attr'
				);
				return $field;
			}

			// 4. By other ID formats
			$field = $(
				`#tainacan-metadata-${ metadataId }, #metadatum-${ metadataId }, #metadata-${ metadataId }`
			);
			if ( $field.length ) {
				console.log( '[findField] Found via alternative ID' );
				return $field;
			}

			// 4. By field name
			$field = $(
				`[name="metadata[${ metadataId }]"], [name="tainacan_metadatum[${ metadataId }]"], [name="metadatum_id_${ metadataId }"]`
			);
			if ( $field.length ) {
				console.log( '[findField] Found via strategy 4' );
				return $field;
			}

			// 5. By slug/class - with several variations
			if ( slug ) {
				// Try with exact and partial slug
				const slugVariations = [
					slug,
					slug.replace( /-\d+$/, '' ),
					slug.replace( /-/g, '_' ),
				];
				for ( const s of slugVariations ) {
					$field = $(
						`.tainacan-metadatum-${ s } input, .tainacan-metadatum-${ s } textarea`
					).first();
					if ( $field.length ) {
						console.log(
							'[findField] Found via slug class:',
							s
						);
						return $field;
					}

					$field = $(
						`[data-metadatum-slug="${ s }"] input, [data-metadatum-slug="${ s }"] textarea`
					).first();
					if ( $field.length ) {
						console.log(
							'[findField] Found via data-slug:',
							s
						);
						return $field;
					}
				}
			}

			// 6. Search in Tainacan Vue form
			$field = $(
				`.tainacan-item-metadatum[data-metadatum-id="${ metadataId }"]`
			)
				.find( 'input:not([type="hidden"]), textarea' )
				.first();
			if ( $field.length ) {
				console.log( '[findField] Found via strategy 6' );
				return $field;
			}

			// 7. Search by aria-label or placeholder containing slug
			if ( slug ) {
				const slugBase = slug.replace( /-\d+$/, '' );
				$field = $(
					`input[aria-label*="${ slugBase }"], textarea[aria-label*="${ slugBase }"]`
				).first();
				if ( $field.length ) {
					console.log( '[findField] Found via aria-label' );
					return $field;
				}
			}

			// 8. Search by label (by metadata name)
			if ( slug ) {
				const $labels = $( 'label' );
				$labels.each( function () {
					const labelText = $( this ).text().toLowerCase().trim();
					const slugLower = slug
						.toLowerCase()
						.replace( /-\d+$/, '' )
						.replace( /dc/g, '' );
					if (
						labelText.includes( slugLower ) ||
						slugLower.includes( labelText.replace( /[^a-z]/g, '' ) )
					) {
						const forId = $( this ).attr( 'for' );
						if ( forId ) {
							$field = $( `#${ forId }` );
							if ( $field.length ) {
								console.log(
									'[findField] Found via label for:',
									labelText
								);
								return false; // break
							}
						}
						// Try to get input/textarea in same container
						$field = $( this )
							.closest(
								'.field, .tainacan-metadatum, .form-group, [class*="metadatum"]'
							)
							.find( 'input:not([type="hidden"]), textarea' )
							.first();
						if ( $field.length ) {
							console.log(
								'[findField] Found via label container:',
								labelText
							);
							return false; // break
						}
					}
				} );
				if ( $field && $field.length ) return $field;
			}

			// 9. Debug: show all available inputs/textareas
			if ( TainacanAI.debug ) {
				console.log( '[findField] Available inputs in DOM:' );
				$( 'input:not([type="hidden"]), textarea' ).each(
					function ( i ) {
						if ( i < 20 ) {
							// limit to 20 to avoid clutter
							console.log( `  - ${ this.tagName }`, {
								id: this.id,
								name: this.name,
								class: this.className,
								'data-*': this.dataset,
							} );
						}
					}
				);
			}

			return null;
		},

		/**
		 * Set field value
		 */
		setFieldValue( $field, value, fieldType ) {
			try {
				// Convert arrays to string if necessary
				let finalValue = value;
				if ( Array.isArray( value ) ) {
					finalValue = value.join( ', ' );
				} else if ( typeof value === 'object' ) {
					finalValue = JSON.stringify( value );
				}

				const tagName = $field.prop( 'tagName' ).toLowerCase();
				const inputType = $field.attr( 'type' );

				// Text/textarea fields
				if (
					tagName === 'textarea' ||
					inputType === 'text' ||
					! inputType
				) {
					// For Vue/React, trigger events to update state
					$field.val( finalValue );
					$field.trigger( 'input' );
					$field.trigger( 'change' );

					// Try to dispatch native event for reactive frameworks
					const nativeEvent = new Event( 'input', { bubbles: true } );
					$field[ 0 ].dispatchEvent( nativeEvent );

					return true;
				}

				// Selection fields
				if ( tagName === 'select' ) {
					$field.val( finalValue ).trigger( 'change' );
					return true;
				}

				// Checkbox
				if ( inputType === 'checkbox' ) {
					const shouldCheck =
						finalValue === true ||
						finalValue === '1' ||
						finalValue === 'true';
					$field.prop( 'checked', shouldCheck ).trigger( 'change' );
					return true;
				}

				// Other input types
				$field.val( finalValue ).trigger( 'input' ).trigger( 'change' );
				return true;
			} catch ( error ) {
				console.error(
					'[TainacanAI] Error setting field value:',
					error
				);
				return false;
			}
		},

		/**
		 * Fill all mapped fields
		 */
		fillAllMappedFields() {
			if ( ! this.state.lastResult?.ai_metadata ) {
				this.showToast(
					TainacanAI.texts?.noResults || 'No results available'
				);
				return;
			}

			const mapping = TainacanAI.metadataMapping || {};
			let filledCount = 0;
			let totalMapped = 0;

			// Debug: show mapping and AI result
			console.log( '[TainacanAI] Available mapping:', mapping );
			console.log(
				'[TainacanAI] AI result:',
				this.state.lastResult.ai_metadata
			);

			Object.entries( this.state.lastResult.ai_metadata ).forEach(
				( [ key, data ] ) => {
					console.log(
						`[TainacanAI] Checking key: "${ key }" -> exists in mapping:`,
						!! mapping[ key ]
					);
					if ( ! mapping[ key ] ) return;

					totalMapped++;

					// Extract value (new or old format)
					let value;
					if ( data && typeof data === 'object' && 'valor' in data ) {
						value = data.valor;
					} else {
						value = data;
					}

					// Skip empty values
					if (
						value === null ||
						value === undefined ||
						value === '' ||
						( Array.isArray( value ) && value.length === 0 )
					) {
						return;
					}

					if ( this.fillTainacanField( key, value ) ) {
						filledCount++;
					}
				}
			);

			if ( filledCount > 0 ) {
				this.showToast(
					(
						TainacanAI.texts?.fieldsFilled ||
						'{count} fields filled'
					).replace( '{count}', filledCount )
				);
			} else if ( totalMapped === 0 ) {
				this.showToast(
					TainacanAI.texts?.noMappedFields ||
						'No mapped fields found'
				);
			} else {
				this.showToast(
					TainacanAI.texts?.noFieldsToFill ||
						'No fields to fill'
				);
			}
		},

		/**
		 * Escape HTML
		 */
		escapeHtml( text ) {
			const div = document.createElement( 'div' );
			div.textContent = text;
			return div.innerHTML;
		},
	};

	// Initialize when DOM is ready
	$( document ).ready( () => {
		TainacanAIApp.init();
	} );
} )( jQuery );
