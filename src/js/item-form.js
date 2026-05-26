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
				tokensInfo: $( '#tainacan-ai-tokens' ),
				copyAllBtn: $( '#tainacan-ai-copy-all' ),
			};
		},

		/**
		 * Ensure widget DOM refs exist (widget is injected when the item form mounts).
		 */
		ensureElementsCached() {
			if ( ! this.elements.analyzeBtn?.length ) {
				this.cacheElements();
			}
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
                            <svg class="tainacan-ai-icon" xmlns="http://www.w3.org/2000/svg" xml:space="preserve" id="svg5" width="32" height="32" version="1.1" viewBox="0 0 8.467 8.467">
								<g id="layer1" transform="translate(-51.439 -147.782)"><path id="path11554" d="m58.994 153.057-.247.062-.349.082c.124.134.217.267.282.396.158.318.161.607.012.927v.002l-.005.007c-.172.37-.412.548-.824.616-.002 0-.004 0-.005.002-.074.012-.16.018-.257.018-.383 0-.864-.118-1.415-.372l-.009-.005a.534.534 0 0 0-.078-.033 4.111 4.111 0 0 1-.427-.191h-.004c-.016.064-.03.131-.05.21a3.34 3.34 0 0 1-.083.302l-.01.029c.144.07.273.124.38.164l.037.019c.608.282 1.165.426 1.658.426.122 0 .24-.007.352-.026a1.588 1.588 0 0 0 1.235-.927l.003-.007c.215-.46.212-.95-.014-1.405-.051-.102-.111-.2-.182-.297z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><path id="path11552" d="M57.188 148.868c-.079 0-.16.006-.241.017-.359.047-.732.2-1.112.455.028.116.055.228.077.311.095.025.226.055.36.087.266-.158.536-.28.748-.307.366-.05.646.046.91.31h.003v.002c.27.272.363.549.314.915a1.85 1.85 0 0 1-.213.62l.055.216c.01.04.017.061.026.091.03.01.053.016.094.027l.238.058c.19-.32.306-.634.346-.94a1.592 1.592 0 0 0-.467-1.375l-.004-.003a1.583 1.583 0 0 0-1.134-.484z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><path id="path1" d="M53.574 148.312a1.671 1.671 0 0 0-.67.161l-.006.003c-1.05.493-1.246 1.706-.527 3.248l.015.034c.122.323.356.82.783 1.372a5.33 5.33 0 0 0-.208 1.435v.148h.148c.285 0 .731-.031 1.282-.17-.036-.007-.067-.016-.108-.025a3.406 3.406 0 0 1-.301-.083.791.791 0 0 1-.16-.071.441.441 0 0 1-.222-.295h-.002c-.028-.15 0-.435.101-.79a.55.55 0 0 0-.096-.486 4.834 4.834 0 0 1-.717-1.266l-.016-.034c-.328-.704-.421-1.293-.356-1.697.066-.404.238-.642.618-.82l.004-.002c.168-.078.323-.117.476-.115v-.001c.153 0 .305.042.465.122.15.075.33.237.496.415l.03-.121c.033-.14.067-.281.11-.409l.036-.092v-.001a2.172 2.172 0 0 0-.424-.284 1.605 1.605 0 0 0-.75-.176z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><g id="path6974" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(1.07603 0 0 1.0728 -16.96 -11.535)"><path id="path10029" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g><g id="path6968" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(1.51152 0 0 1.50697 -44.11 -74.969)"><path id="path10038" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g><g id="path6976" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(.77239 0 0 .77006 3.277 37.782)"><path id="path10046" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g></g>
							</svg>
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
			$( document ).on( 'click', '.tainacan-ai-fill-field', async ( e ) => {
				const $btn = $( e.currentTarget );
				const metadataKey = $btn.data( 'metadata-key' );
				await this.fillTainacanField( metadataKey, null, $btn, {
					dispatchReloadEvent: true,
				} );
			} );

			// Fill all extraction-enabled fields
			$( document ).on( 'click', '#tainacan-ai-fill-all', async () => {
				await this.fillAllExtractionFields();
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
					const path =
						payload?.childEntity?.defaultLink ||
						payload?.currentRoute?.path ||
						'';
					const editMatch = path.match( /collections\/(\d+)\/items\/(\d+)\/edit/ );
					const isItemEdit = Boolean( editMatch );

					if ( isItemEdit ) {
						const prevCollectionId = this.state.collectionId;
						this.state.isItemEditContext = true;
						this.state.collectionId = parseInt( editMatch[ 1 ], 10 );
						this.state.itemId = parseInt( editMatch[ 2 ], 10 );
						if ( this.state.collectionId && this.state.collectionId !== prevCollectionId ) {
							this.fetchExtractionFields();
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
						this.fetchExtractionFields();
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
		 * Fetch extraction-enabled metadata for the collection (keyed by slug).
		 */
		async fetchExtractionFields() {
			if ( ! this.state.collectionId ) return;

			try {
				const response = await $.ajax( {
					url: TainacanAI.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tainacan_ai_get_extraction_fields',
						nonce: TainacanAI.nonce,
						collection_id: this.state.collectionId,
					},
				} );

				if ( response.success && response.data ) {
					TainacanAI.extractionFields = response.data;
					if ( TainacanAI.debug ) {
						console.log(
							'[TainacanAI] Extraction fields updated via AJAX:',
							response.data
						);
					}
				}
			} catch ( error ) {
				console.error(
					'[TainacanAI] Error fetching extraction fields:',
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
				url: TainacanAI.texts.url || 'URL',
			};

			const typeIcons = {
				image: 'dashicons-format-image',
				pdf: 'dashicons-pdf',
				text: 'dashicons-media-text',
				url: 'dashicons-admin-links',
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

			this.ensureElementsCached();

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
					if ( TainacanAI.debug && response.data.prompt_debug ) {
						console.group( '[TainacanAI] Resolved analysis prompt' );
						if ( response.data.prompt_debug.parts ) {
							console.log( 'User intro:', response.data.prompt_debug.parts.user );
							console.log( 'Fields section:', response.data.prompt_debug.parts.fields );
							console.log( 'Evidence / format:', response.data.prompt_debug.parts.evidence );
						}
						if ( response.data.prompt_debug.analysis_mode ) {
							console.log(
								'Analysis mode:',
								response.data.prompt_debug.analysis_mode
							);
						}
						if ( response.data.prompt_debug.attachment_note ) {
							console.log(
								'Attachment:',
								response.data.prompt_debug.attachment_note
							);
						}
						console.log( 'Full prompt:', response.data.prompt_debug.prompt );
						console.groupEnd();
					}
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

			if ( result.tokens_used ) {
				$( '#tainacan-ai-panel-tokens .tokens-count' ).text(
					`${ result.tokens_used } ${ TainacanAI.texts?.tokens || 'tokens' }`
				);
			}

			// Open sidebar panel automatically
			this.openSidebarPanel();
		},

		/**
		 * Coerce AI field payload to { value, evidence } (parallel arrays when multivalued).
		 */
		parseFieldEntry( data ) {
			if (
				Array.isArray( data ) &&
				data.length > 0 &&
				data.every(
					( item ) =>
						item &&
						typeof item === 'object' &&
						! Array.isArray( item ) &&
						'value' in item
				)
			) {
				return this.coalesceValueEvidenceObjects( data );
			}

			if ( data && typeof data === 'object' && 'value' in data ) {
				let value = data.value;
				let evidence = data.evidence ?? null;

				if (
					Array.isArray( value ) &&
					value.length > 0 &&
					value.every(
						( item ) =>
							item &&
							typeof item === 'object' &&
							! Array.isArray( item ) &&
							'value' in item
					)
				) {
					const coalesced = this.coalesceValueEvidenceObjects( value );
					const hasEvidence =
						evidence !== null &&
						evidence !== undefined &&
						evidence !== '' &&
						! ( Array.isArray( evidence ) && evidence.length === 0 );

					value = coalesced.value;
					if ( ! hasEvidence ) {
						evidence = coalesced.evidence;
					}
				}

				return { value, evidence };
			}

			return { value: data, evidence: null };
		},

		coalesceValueEvidenceObjects( items ) {
			return {
				value: items.map( ( item ) => item.value ),
				evidence: items.map( ( item ) =>
					item.evidence != null ? String( item.evidence ) : ''
				),
			};
		},

		formatEvidence( evidence ) {
			if ( evidence === null || evidence === undefined || evidence === '' ) {
				return '';
			}

			if ( Array.isArray( evidence ) ) {
				if ( evidence.length === 0 ) {
					return '';
				}

				return evidence
					.map( ( entry, index ) => {
						return `<div class="tainacan-ai-evidence-item"><span class="tainacan-ai-evidence-index">${ index + 1 }.</span> ${ this.escapeHtml(
							String( entry )
						) }</div>`;
					} )
					.join( '' );
			}

			return this.escapeHtml( String( evidence ) );
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

				const { value, evidence } = this.parseFieldEntry( data );
				const formattedValue = this.formatValue( value );
				const formattedEvidence = this.formatEvidence( evidence );
				const isEmpty =
					value === null ||
					value === undefined ||
					( Array.isArray( value ) && value.length === 0 ) ||
					value === '';

				const extractionFields = TainacanAI.extractionFields || {};
				const extractionField = extractionFields[ key ];
				const displayLabel = extractionField?.name
					? this.escapeHtml( extractionField.name )
					: formattedLabel;
				const canFill = extractionField && ! isEmpty;
				const notFoundText =
					TainacanAI.texts?.valueNotFound || 'Not found in document';

				let $item;

				if ( isEmpty ) {
					$item = $( `
                    <div class="tainacan-ai-metadata-item-with-evidence is-not-found"
                         style="animation-delay: ${ index * 0.05 }s"
                         data-metadata-key="${ this.escapeHtml( key ) }">
                        <div class="tainacan-ai-metadata-main tainacan-ai-metadata-main--not-found">
                            <span class="tainacan-ai-metadata-label">${ displayLabel }</span>
                            <span class="tainacan-ai-metadata-not-found">${ this.escapeHtml(
								notFoundText
							) }</span>
                        </div>
                    </div>
                ` );
				} else {
					$item = $( `
                    <div class="tainacan-ai-metadata-item-with-evidence"
                         style="animation-delay: ${ index * 0.05 }s"
                         data-metadata-key="${ this.escapeHtml( key ) }">
                        <div class="tainacan-ai-metadata-main">
                            <div class="tainacan-ai-metadata-top">
                                <div class="tainacan-ai-metadata-label-with-copy">
                                    <span class="tainacan-ai-metadata-label">${ displayLabel }</span>
                                    ${
										canFill
											? `<span class="tainacan-ai-extraction-field-badge" title="${ TainacanAI.texts?.fieldLabel || 'Tainacan field: ' }${ this.escapeHtml(
													extractionField.name ||
														extractionField
											  ) }">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                    </span>`
											: ''
									}
                                </div>
                                <div class="tainacan-ai-metadata-actions-mini">
                                    ${
										canFill
											? `
                                    <button type="button" class="tainacan-ai-fill-field"
                                            data-metadata-key="${ this.escapeHtml(
												key
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
                                            data-metadata-key="${ this.escapeHtml(
												key
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
							formattedEvidence
								? `
                        <div class="tainacan-ai-metadata-evidence">
                            <div class="tainacan-ai-evidence-label">
                                <span class="dashicons dashicons-search"></span>
                                ${ TainacanAI.texts?.evidence || 'Evidence' }
                            </div>
                            <div class="tainacan-ai-evidence-text">${ formattedEvidence }</div>
                        </div>
                        `
								: ''
						}
                    </div>
                ` );
				}

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
			const metadataKey = $btn.data( 'metadataKey' );
			const value = this.resolveClipboardValueForMetadata( metadataKey, $btn );

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

		resolveClipboardValueForMetadata( metadataKey, $btn = null ) {
			const metadataEntry =
				metadataKey && this.state.lastResult?.ai_metadata
					? this.state.lastResult.ai_metadata[ metadataKey ]
					: undefined;
			if ( metadataEntry !== undefined ) {
				const { value } = this.parseFieldEntry( metadataEntry );
				return this.stringifyClipboardValue( value );
			}

			// Backward-compatible fallback for previously rendered nodes.
			return String( $btn?.data( 'value' ) ?? '' );
		},

		stringifyClipboardValue( value ) {
			if ( Array.isArray( value ) ) {
				return value
					.map( ( entry ) =>
						entry === null || entry === undefined
							? ''
							: typeof entry === 'object'
							? JSON.stringify( entry )
							: String( entry )
					)
					.join( ', ' );
			}

			if ( value === null || value === undefined ) {
				return '';
			}

			return typeof value === 'object'
				? JSON.stringify( value )
				: String( value );
		},

		/**
		 * Copy all values
		 */
		async copyAllValues() {
			if ( ! this.state.lastResult?.ai_metadata ) return;

			const text = Object.entries( this.state.lastResult.ai_metadata )
				.map( ( [ key, data ] ) => {
					const { value } = this.parseFieldEntry( data );
					const formattedValue = this.stringifyClipboardValue( value );
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
			this.ensureElementsCached();
			this.elements.analyzeBtn.prop( 'disabled', true );
			this.elements.refreshBtn.prop( 'disabled', true );
			this.elements.status.show();
			this.elements.results.hide();
		},

		/**
		 * Hide loading
		 */
		hideLoading() {
			this.ensureElementsCached();
			this.elements.analyzeBtn.prop( 'disabled', false );
			this.elements.refreshBtn.prop( 'disabled', false );
			this.elements.status.hide();
		},

		/**
		 * Show error
		 */
		showError( message ) {
			this.ensureElementsCached();
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
		 * Fill a Tainacan field via Tainacan REST API.
		 */
		async fillTainacanField(
			metadataKey,
			value = null,
			$btn = null,
			options = {}
		) {
			const extractionFields = TainacanAI.extractionFields || {};
			const fieldInfo = extractionFields[ metadataKey ];
			const dispatchReloadEvent = options.dispatchReloadEvent !== false;

			if ( ! fieldInfo ) {
				const message =
					TainacanAI.texts?.noExtractionFields ||
					'No extraction-enabled metadata found';
				this.showError( message );
				return {
					success: false,
					error: message,
				};
			}

			if ( ! this.state.itemId ) {
				const message =
					TainacanAI.texts?.noDocument || 'No document found in this item.';
				this.showError( message );
				return {
					success: false,
					error: message,
				};
			}

			const metadataId = fieldInfo.id || fieldInfo;
			if ( ! metadataId ) {
				const message = `${
					TainacanAI.texts?.fillFailed || 'Could not update field.'
				} (${ metadataKey })`;
				this.showError( message );
				return {
					success: false,
					error: message,
				};
			}

			let fieldValue = value;
			if ( fieldValue === null || fieldValue === undefined ) {
				const aiEntry = this.state.lastResult?.ai_metadata?.[ metadataKey ];
				const parsedEntry = this.parseFieldEntry( aiEntry );
				fieldValue = parsedEntry.value;
			}

			if (
				fieldValue === null ||
				fieldValue === undefined ||
				fieldValue === '' ||
				( Array.isArray( fieldValue ) && fieldValue.length === 0 )
			) {
				const message =
					TainacanAI.texts?.noFieldsToFill || 'No fields to fill';
				return {
					success: false,
					error: message,
					skipped: true,
				};
			}

			const payload = {
				values: this.normalizeValueForMetadata(
					fieldValue,
					fieldInfo.multiple === true,
					fieldInfo.type
				),
			};

			if ( TainacanAI.debug ) {
				console.log(
					`[TainacanAI] Updating metadata "${ metadataKey }" (ID ${ metadataId })`,
					payload
				);
			}

			if ( $btn && $btn.length ) {
				$btn.prop( 'disabled', true );
			}

			try {
				await this.updateItemMetadataValue(
					this.state.itemId,
					metadataId,
					payload
				);
			} catch ( error ) {
				const parsedError = this.parseMetadataUpdateError( error );
				const fieldLabel = fieldInfo?.name || this.formatLabel( metadataKey );
				const errorPrefix =
					TainacanAI.texts?.fillFailedFor || 'Failed to update';
				const message = `${ errorPrefix } "${ fieldLabel }": ${
					parsedError.message
				}`;
				this.showError( message );
				return {
					success: false,
					error: message,
				};
			} finally {
				if ( $btn && $btn.length ) {
					$btn.prop( 'disabled', false );
				}
			}

			if ( dispatchReloadEvent ) {
				this.dispatchTainacanMetadataReload(
					this.state.itemId,
					metadataId
				);
			}

			if ( $btn && $btn.length ) {
				const $icon = $btn.find( '.dashicons' );
				$icon.removeClass( 'dashicons-download' ).addClass( 'dashicons-yes' );
				$btn.addClass( 'filled' );
				setTimeout( () => {
					$icon
						.removeClass( 'dashicons-yes' )
						.addClass( 'dashicons-download' );
					$btn.removeClass( 'filled' );
				}, 2000 );
			}

			return {
				success: true,
			};
		},

		getTainacanApiBaseUrl() {
			const explicitBase = TainacanAI.tainacanApiUrl;
			if ( typeof explicitBase === 'string' && explicitBase !== '' ) {
				return explicitBase.replace( /\/+$/, '' );
			}
			return '';
		},

		async updateItemMetadataValue( itemId, metadatumId, payload ) {
			const baseUrl = this.getTainacanApiBaseUrl();
			const url = `${ baseUrl }/item/${ itemId }/metadata/${ metadatumId }`;

			return $.ajax( {
				url,
				method: 'PUT',
				contentType: 'application/json',
				data: JSON.stringify( payload ),
				processData: false,
				headers: {
					'X-WP-Nonce': TainacanAI.restNonce,
				},
			} );
		},

		normalizeValueForMetadata( value, isMultiple, fieldType ) {
			if ( isMultiple ) {
				if (
					value === null ||
					value === undefined ||
					value === '' ||
					( Array.isArray( value ) && value.length === 0 )
				) {
					return [];
				}

				const values = Array.isArray( value ) ? value : [ value ];
				return values.map( ( entry ) => this.normalizeSingleValue( entry ) );
			}

			if (
				Array.isArray( value ) &&
				fieldType &&
				String( fieldType ).toLowerCase().includes( 'geocoordinate' ) &&
				value.length >= 2
			) {
				return `[${ value[ 0 ] },${ value[ 1 ] }]`;
			}

			if ( Array.isArray( value ) ) {
				return value.length > 0 ? this.normalizeSingleValue( value[ 0 ] ) : '';
			}

			return this.normalizeSingleValue( value );
		},

		normalizeSingleValue( value ) {
			if ( value === null || value === undefined ) {
				return '';
			}

			if ( typeof value === 'object' ) {
				return JSON.stringify( value );
			}

			return value;
		},

		parseMetadataUpdateError( error ) {
			const responseJSON = error?.responseJSON || {};
			const status = Number( error?.status || responseJSON?.data?.status || 0 );

			if ( status === 401 ) {
				return {
					message:
						TainacanAI.texts?.fillUnauthorized ||
						'You are not authorized to update this metadata.',
				};
			}

			if ( status === 403 ) {
				return {
					message:
						TainacanAI.texts?.fillForbidden ||
						'Access denied while updating metadata.',
				};
			}

			let message =
				responseJSON?.error_message ||
				responseJSON?.message ||
				error?.statusText ||
				error?.message ||
				'Unknown error';

			if ( Array.isArray( responseJSON?.errors ) && responseJSON.errors.length ) {
				const details = responseJSON.errors
					.map( (entry) => {
						if ( ! entry || typeof entry !== 'object' ) {
							return '';
						}
						const firstValue = Object.values( entry )[ 0 ];
						return firstValue ? String( firstValue ) : '';
					} )
					.filter( Boolean );

				if ( details.length ) {
					message += ` (${ details.join( '; ' ) })`;
				}
			}

			return { message };
		},

		dispatchTainacanMetadataReload( itemId = null, metadatumId = null ) {
			if ( ! TainacanAI.features?.supportsMetadataReloadEvent ) {
				return;
			}

			if ( typeof window === 'undefined' || typeof window.dispatchEvent !== 'function' ) {
				return;
			}

			if (
				itemId !== null &&
				metadatumId !== null &&
				itemId !== undefined &&
				metadatumId !== undefined
			) {
				window.dispatchEvent(
					new CustomEvent( 'TainacanReloadItemMetadataForm', {
						detail: {
							itemId,
							metadatumId,
						},
					} )
				);
				return;
			}

			window.dispatchEvent(
				new CustomEvent( 'TainacanReloadItemMetadataForm' )
			);
		},

		/**
		 * Fill all extraction-enabled fields that have values in the AI result.
		 */
		async fillAllExtractionFields() {
			if ( ! this.state.lastResult?.ai_metadata ) {
				this.showToast(
					TainacanAI.texts?.noResults || 'No results available'
				);
				return;
			}

			const extractionFields = TainacanAI.extractionFields || {};
			let filledCount = 0;
			let failedCount = 0;
			let skippedCount = 0;
			let totalFillable = 0;
			const fieldErrors = [];

			if ( TainacanAI.debug ) {
				console.log(
					'[TainacanAI] Extraction fields:',
					extractionFields
				);
				console.log(
					'[TainacanAI] AI result:',
					this.state.lastResult.ai_metadata
				);
			}

			for ( const [ key, data ] of Object.entries(
				this.state.lastResult.ai_metadata
			) ) {
				if ( TainacanAI.debug ) {
					console.log(
						`[TainacanAI] Checking key: "${ key }" -> extraction enabled:`,
						!! extractionFields[ key ]
					);
				}
				if ( ! extractionFields[ key ] ) {
					continue;
				}

				totalFillable++;
				const { value } = this.parseFieldEntry( data );

				const result = await this.fillTainacanField( key, value, null, {
					dispatchReloadEvent: false,
				} );

				if ( result.success ) {
					filledCount++;
				} else if ( result.skipped ) {
					skippedCount++;
				} else {
					failedCount++;
					if ( result.error ) {
						fieldErrors.push( result.error );
					}
				}
			}

			if (
				filledCount > 0 &&
				TainacanAI.features?.supportsMetadataReloadEvent
			) {
				this.dispatchTainacanMetadataReload();
			}

			if ( filledCount > 0 || failedCount > 0 ) {
				const filledLabel =
					TainacanAI.texts?.fieldsFilled || 'fields filled';
				const failedLabel =
					TainacanAI.texts?.fieldsFailed || 'fields failed';
				this.showToast(
					`${ filledCount } ${ filledLabel }${
						failedCount > 0 ? `, ${ failedCount } ${ failedLabel }` : ''
					}`
				);

				if ( fieldErrors.length > 0 ) {
					this.showError( fieldErrors.slice( 0, 3 ).join( ' | ' ) );
				}
			} else if ( totalFillable === 0 ) {
				this.showToast(
					TainacanAI.texts?.noExtractionFields ||
						'No extraction-enabled metadata found'
				);
			} else {
				this.showToast(
					skippedCount > 0
						? TainacanAI.texts?.noFieldsToFill || 'No fields to fill'
						: TainacanAI.texts?.fillFailed || 'Could not update field.'
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
