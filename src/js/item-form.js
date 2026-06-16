/**
 * Tainacan AI - Item Form JavaScript
 * @version 1.0.0
 */
import { addAction } from '@wordpress/hooks';
import apiFetch from '@wordpress/api-fetch';

let hasTainacanAiNonceMiddleware = false;

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
			lastPrompt: null,
			lastDocumentBody: null,
			lastPromptDebug: null,
			lastRunId: null,
			lastRunStartedAt: null,
			lastFromCache: false,
			lastExtraction: null,
			analysisPhase: null,
			activeSidebarTab: 'results',
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
			this.setupApiFetchNonceMiddleware();
			this.bindEvents();
			this.createSidebarPanel();
			this.registerHooks();

			if ( TainacanAI.debug ) {
				console.log( '[TainacanAI] Initialized', this.state );
			}
		},

		setupApiFetchNonceMiddleware() {
			if ( hasTainacanAiNonceMiddleware ) {
				return;
			}

			const nonce = TainacanAI?.restNonce;
			if ( ! nonce ) {
				return;
			}

			apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
			hasTainacanAiNonceMiddleware = true;
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
				cacheBadge: $( '#tainacan-ai-cache-badge' ),
				documentInfo: $( '#tainacan-ai-document-info' ),
				docType: $( '#tainacan-ai-doc-type' ),
				docName: $( '#tainacan-ai-doc-name' ),
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
                <div class="tainacan-ai-panel-indicator" title="${ TainacanAI.texts?.panelTitle || TainacanAI.texts?.openResults || 'Open Tainacan AI' }">
                    <span class="dashicons dashicons-arrow-left-alt2"></span>
                </div>
            ` );

			const aiIconSvg = `<svg class="tainacan-ai-icon" xmlns="http://www.w3.org/2000/svg" xml:space="preserve" id="svg5" width="32" height="32" version="1.1" viewBox="0 0 8.467 8.467">
                        <g id="layer1" transform="translate(-51.439 -147.782)"><path id="path11554" d="m58.994 153.057-.247.062-.349.082c.124.134.217.267.282.396.158.318.161.607.012.927v.002l-.005.007c-.172.37-.412.548-.824.616-.002 0-.004 0-.005.002-.074.012-.16.018-.257.018-.383 0-.864-.118-1.415-.372l-.009-.005a.534.534 0 0 0-.078-.033 4.111 4.111 0 0 1-.427-.191h-.004c-.016.064-.03.131-.05.21a3.34 3.34 0 0 1-.083.302l-.01.029c.144.07.273.124.38.164l.037.019c.608.282 1.165.426 1.658.426.122 0 .24-.007.352-.026a1.588 1.588 0 0 0 1.235-.927l.003-.007c.215-.46.212-.95-.014-1.405-.051-.102-.111-.2-.182-.297z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><path id="path11552" d="M57.188 148.868c-.079 0-.16.006-.241.017-.359.047-.732.2-1.112.455.028.116.055.228.077.311.095.025.226.055.36.087.266-.158.536-.28.748-.307.366-.05.646.046.91.31h.003v.002c.27.272.363.549.314.915a1.85 1.85 0 0 1-.213.62l.055.216c.01.04.017.061.026.091.03.01.053.016.094.027l.238.058c.19-.32.306-.634.346-.94a1.592 1.592 0 0 0-.467-1.375l-.004-.003a1.583 1.583 0 0 0-1.134-.484z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><path id="path1" d="M53.574 148.312a1.671 1.671 0 0 0-.67.161l-.006.003c-1.05.493-1.246 1.706-.527 3.248l.015.034c.122.323.356.82.783 1.372a5.33 5.33 0 0 0-.208 1.435v.148h.148c.285 0 .731-.031 1.282-.17-.036-.007-.067-.016-.108-.025a3.406 3.406 0 0 1-.301-.083.791.791 0 0 1-.16-.071.441.441 0 0 1-.222-.295h-.002c-.028-.15 0-.435.101-.79a.55.55 0 0 0-.096-.486 4.834 4.834 0 0 1-.717-1.266l-.016-.034c-.328-.704-.421-1.293-.356-1.697.066-.404.238-.642.618-.82l.004-.002c.168-.078.323-.117.476-.115v-.001c.153 0 .305.042.465.122.15.075.33.237.496.415l.03-.121c.033-.14.067-.281.11-.409l.036-.092v-.001a2.172 2.172 0 0 0-.424-.284 1.605 1.605 0 0 0-.75-.176z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><g id="path6974" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(1.07603 0 0 1.0728 -16.96 -11.535)"><path id="path10029" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g><g id="path6968" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(1.51152 0 0 1.50697 -44.11 -74.969)"><path id="path10038" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g><g id="path6976" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(.77239 0 0 .77006 3.277 37.782)"><path id="path10046" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g></g>
                    </svg>`;

			const promptEditorBlock = TainacanAI.advancedDebug
				? `
                        <div class="tainacan-ai-request-prompt-section">
                            <div class="tainacan-ai-prompt-editor" id="tainacan-ai-prompt-editor">
                                <div class="tainacan-ai-prompt-editor-header">
                                    <strong>${ TainacanAI.texts?.promptEditorTitle || 'Analysis prompt' }</strong>
                                </div>
                                <p class="tainacan-ai-prompt-editor-hint">${ TainacanAI.texts?.promptEditorHint || '' }</p>
                                <textarea
                                    id="tainacan-ai-prompt-textarea"
                                    class="tainacan-ai-prompt-textarea"
                                    rows="12"
                                    spellcheck="false"
                                ></textarea>
                                <div class="tainacan-ai-prompt-editor-actions">
                                    <button type="button" class="button button-primary" id="tainacan-ai-run-with-prompt">
                                        ${ TainacanAI.texts?.runWithPrompt || 'Run with this prompt' }
                                    </button>
                                    <button type="button" class="button button-secondary" id="tainacan-ai-reset-prompt">
                                        ${ TainacanAI.texts?.resetPrompt || 'Reset to last resolved prompt' }
                                    </button>
                                </div>
                                <div class="tainacan-ai-prompt-document-block" id="tainacan-ai-prompt-document-preview" hidden>
                                    <div class="tainacan-ai-prompt-document-label" id="tainacan-ai-prompt-document-preview-summary">${ TainacanAI.texts?.promptDocumentPreview || 'Document sent to the model (read-only)' }</div>
                                    <p class="tainacan-ai-prompt-document-preview-truncated" id="tainacan-ai-prompt-document-preview-truncated" hidden>${ TainacanAI.texts?.promptDocumentTruncated || 'Truncated to the same limit used during analysis.' }</p>
                                    <pre class="tainacan-ai-prompt-document-preview-content" id="tainacan-ai-prompt-document-preview-content"></pre>
                                </div>
                            </div>
                        </div>
                    `
				: '';

			const requestExportsBlock = TainacanAI.advancedDebug
				? `
                            <div class="tainacan-ai-sidebar-actions">
                                <div class="tainacan-ai-sidebar-meta-summary">${ TainacanAI.texts?.exportSubheader || 'Export' }</div>
                                <div class="tainacan-ai-sidebar-actions-right">
                                    <button type="button" class="button button-secondary" id="tainacan-ai-download-analysis-csv" disabled>
                                        <span class="dashicons dashicons-download"></span>
                                        ${ TainacanAI.texts?.downloadAnalysisCsv || 'Download analysis.csv' }
                                    </button>
                                    <button type="button" class="button button-secondary" id="tainacan-ai-download-prompt-txt" disabled>
                                        <span class="dashicons dashicons-download"></span>
                                        ${ TainacanAI.texts?.downloadPromptTxt || 'Download prompt.txt' }
                                    </button>
                                </div>
                            </div>
                    `
				: '';

			const panelHtml = `
                <div class="tainacan-ai-sidebar-panel">
                    <div class="tainacan-ai-sidebar-header">
                        <h3>
                            ${ aiIconSvg }
                            ${ TainacanAI.texts?.panelTitle || 'Tainacan AI' }
                        </h3>
                        <div class="tainacan-ai-sidebar-header-actions">
                            <button type="button" class="button button-primary" id="tainacan-ai-panel-refresh">
                                <span class="dashicons dashicons-update"></span>
                                ${ TainacanAI.texts?.newAnalysis || 'New Analysis' }
                            </button>
                            <button type="button" class="tainacan-ai-sidebar-close" title="${ TainacanAI.texts?.close || 'Close' }">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>
                        </div>
                    </div>
                    <nav class="tainacan-ai-sidebar-tabs" role="tablist">
                        <button type="button" class="tainacan-ai-sidebar-tab active" data-tab="results" role="tab" aria-selected="true">
                            ${ TainacanAI.texts?.analysisResults || 'Analysis Results' }
                        </button>
                        <button type="button" class="tainacan-ai-sidebar-tab" data-tab="image-data" id="tainacan-ai-sidebar-tab-image-data" role="tab" aria-selected="false" hidden>
                            ${ TainacanAI.texts?.tabImageData || 'Image data' }
                        </button>
                        <button type="button" class="tainacan-ai-sidebar-tab" data-tab="request" id="tainacan-ai-sidebar-tab-request" role="tab" aria-selected="false" hidden disabled>
                            ${ TainacanAI.texts?.tabRequest || 'Request' }
                        </button>
                    </nav>
                    <div class="tainacan-ai-sidebar-tab-panels">
                        <div class="tainacan-ai-sidebar-tab-panel active" id="tainacan-ai-tab-panel-results" data-tab-panel="results" role="tabpanel">
                            <div class="tainacan-ai-sidebar-actions">
                                <div class="tainacan-ai-sidebar-meta-summary" id="tainacan-ai-panel-meta-summary">0 metadata extracted</div>
                                <div class="tainacan-ai-sidebar-actions-right">
                                    <button type="button" class="button button-secondary" id="tainacan-ai-fill-all" title="${
										TainacanAI.texts?.fillAllTooltip ||
										'Automatically fills Tainacan fields with extracted values'
									}">
                                        <span class="dashicons dashicons-yes"></span>
                                        ${ TainacanAI.texts?.fillAll || 'Fill all' }
                                    </button>
                                    <button type="button" class="button button-secondary" id="tainacan-ai-panel-copy-all">
                                        <span class="dashicons dashicons-admin-page"></span>
                                        ${ TainacanAI.texts?.copyAll || 'Copy all' }
                                    </button>
                                    <button type="button" class="button button-secondary" id="tainacan-ai-panel-download-results-csv" disabled title="${ TainacanAI.texts?.downloadResultsCsv || 'Download results.csv' }">
                                        <span class="dashicons dashicons-download"></span>
                                        ${ TainacanAI.texts?.downloadResultsCsvShort || 'results.csv' }
                                    </button>
                                </div>
                            </div>
                            <div class="tainacan-ai-sidebar-body" id="tainacan-ai-sidebar-content"></div>
                        </div>
                        <div class="tainacan-ai-sidebar-tab-panel" id="tainacan-ai-tab-panel-image-data" data-tab-panel="image-data" role="tabpanel" hidden>
                            <div class="tainacan-ai-sidebar-tab-panel-scroll">
                                <div class="tainacan-ai-exif-content" id="tainacan-ai-sidebar-exif-content"></div>
                            </div>
                        </div>
                        <div class="tainacan-ai-sidebar-tab-panel" id="tainacan-ai-tab-panel-request" data-tab-panel="request" role="tabpanel" hidden>
                            ${ requestExportsBlock }
                            <div class="tainacan-ai-sidebar-tab-panel-scroll">
                                <dl class="tainacan-ai-detail-list tainacan-ai-request-details">
                                    <div class="tainacan-ai-detail-row">
                                        <dt>${ TainacanAI.texts?.requestTokens || 'Tokens' }</dt>
                                        <dd id="tainacan-ai-request-tokens">-</dd>
                                    </div>
                                    <div class="tainacan-ai-detail-row">
                                        <dt>${ TainacanAI.texts?.requestCharacters || 'Prompt text (characters)' }</dt>
                                        <dd id="tainacan-ai-request-characters">-</dd>
                                    </div>
                                    <div class="tainacan-ai-detail-row">
                                        <dt>${ TainacanAI.texts?.requestResponseCharacters || 'Response (characters)' }</dt>
                                        <dd id="tainacan-ai-request-response-characters">-</dd>
                                    </div>
                                    <div class="tainacan-ai-detail-row">
                                        <dt>${ TainacanAI.texts?.requestAnalysisMode || 'Analysis mode' }</dt>
                                        <dd id="tainacan-ai-request-analysis-mode">-</dd>
                                    </div>
                                    <div class="tainacan-ai-detail-row">
                                        <dt>${ TainacanAI.texts?.requestFinishReason || 'Finish reason' }</dt>
                                        <dd id="tainacan-ai-request-finish-reason">-</dd>
                                    </div>
                                    <div class="tainacan-ai-detail-row">
                                        <dt>${ TainacanAI.texts?.requestDuration || 'Duration' }</dt>
                                        <dd id="tainacan-ai-request-duration">-</dd>
                                    </div>
                                    <div class="tainacan-ai-detail-row">
                                        <dt>${ TainacanAI.texts?.requestConnector || 'Connector' }</dt>
                                        <dd id="tainacan-ai-request-connector">-</dd>
                                    </div>
                                    <div class="tainacan-ai-detail-row">
                                        <dt>${ TainacanAI.texts?.requestModel || 'Model' }</dt>
                                        <dd id="tainacan-ai-request-model">-</dd>
                                    </div>
                                </dl>
                                ${ promptEditorBlock }
                            </div>
                        </div>
                    </div>
                </div>
            `;

			$( 'body' ).append( panelHtml );
			this.reconcileSidebarElements();
			this.switchSidebarTab( this.state.activeSidebarTab || 'results' );
		},

		reconcileSidebarElements() {
			this.elements.sidebarPanel = $( '.tainacan-ai-sidebar-panel' );
			this.elements.sidebarOverlay = $( '.tainacan-ai-sidebar-overlay' );
			this.elements.sidebarContent = $( '#tainacan-ai-sidebar-content' );
			this.elements.panelIndicator = $( '.tainacan-ai-panel-indicator' );
			this.elements.exifContent = $( '#tainacan-ai-sidebar-exif-content' );
			this.elements.sidebarTabImageData = $( '#tainacan-ai-sidebar-tab-image-data' );
			this.elements.sidebarTabRequest = $( '#tainacan-ai-sidebar-tab-request' );
			this.elements.promptEditor = $( '#tainacan-ai-prompt-editor' );
			this.elements.promptTextarea = $( '#tainacan-ai-prompt-textarea' );
			this.elements.promptDocumentPreview = $( '#tainacan-ai-prompt-document-preview' );
			this.elements.promptDocumentPreviewSummary = $( '#tainacan-ai-prompt-document-preview-summary' );
			this.elements.promptDocumentPreviewTruncated = $( '#tainacan-ai-prompt-document-preview-truncated' );
			this.elements.promptDocumentPreviewContent = $( '#tainacan-ai-prompt-document-preview-content' );
			this.elements.downloadResultsCsv = $( '#tainacan-ai-panel-download-results-csv' );
			this.elements.downloadAnalysisCsv = $( '#tainacan-ai-download-analysis-csv' );
			this.elements.downloadPromptTxt = $( '#tainacan-ai-download-prompt-txt' );
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

			$( document ).on( 'click', '.tainacan-ai-sidebar-tab', ( e ) => {
				e.preventDefault();
				this.switchSidebarTab( $( e.currentTarget ).data( 'tab' ) );
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
				( e ) => {
					e.preventDefault();
					this.copyAllValues();
				}
			);

			$( document ).on(
				'click',
				'#tainacan-ai-panel-download-results-csv',
				( e ) => {
					e.preventDefault();
					this.downloadResultsCsv();
				}
			);

			$( document ).on(
				'click',
				'#tainacan-ai-download-analysis-csv',
				( e ) => {
					e.preventDefault();
					this.downloadAnalysisCsv();
				}
			);

			$( document ).on(
				'click',
				'#tainacan-ai-download-prompt-txt',
				( e ) => {
					e.preventDefault();
					this.downloadPromptTxt();
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

			$( document ).on(
				'click',
				'.tainacan-ai-create-pending-term',
				async ( e ) => {
					const $btn = $( e.currentTarget );
					const metadataKey = String( $btn.data( 'metadata-key' ) || '' );
					const termIndex = Number( $btn.data( 'term-index' ) );

					await this.createPendingTermAndFill(
						metadataKey,
						termIndex,
						$btn
					);
				}
			);

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

			$( document ).on( 'click', '#tainacan-ai-run-with-prompt', ( e ) => {
				e.preventDefault();
				const overridePrompt = $( '#tainacan-ai-prompt-textarea' ).val();
				this.analyze( true, overridePrompt );
			} );

			$( document ).on( 'click', '#tainacan-ai-reset-prompt', ( e ) => {
				e.preventDefault();
				this.resetPromptEditor();
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

			if ( ! this.elements.sidebarContent?.length ) {
				this.reconcileSidebarElements();
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

			// Show indicator if there are results or a stored analysis error
			if (
				( this.state.lastResult || this.state.lastAnalysisError ) &&
				this.elements.panelIndicator
			) {
				this.elements.panelIndicator.addClass( 'visible' );
			}
		},

		/**
		 * Reset analysis state (when changing items)
		 */
		resetAnalysisState() {
			this.state.lastResult = null;
			this.state.lastAnalysisError = null;
			this.state.lastPrompt = null;
			this.state.lastDocumentBody = null;
			this.state.lastPromptDebug = null;
			this.state.lastRunId = null;
			this.state.lastRunStartedAt = null;
			this.state.lastFromCache = false;
			this.state.activeSidebarTab = 'results';
			this.state.attachmentId = null;
			this.state.documentInfo = null;
			if ( this.elements.promptTextarea?.length ) {
				this.elements.promptTextarea.val( '' );
			}
			this.updatePromptDocumentPreviewDisplay( null );
			this.updateRequestTabDetails( null );
			this.updateRequestTabAvailability( false );
			this.updateImageDataTab( null );
			this.updateExportButtonsState();

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
				const response = await apiFetch( {
					url: `${ TainacanAI.restUrl }extraction-fields/${ this.state.collectionId }`,
					method: 'GET',
				} );

				TainacanAI.extractionFields = response;
				if ( TainacanAI.debug ) {
					console.log(
						'[TainacanAI] Extraction fields updated via REST:',
						response
					);
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
				const response = await apiFetch( {
					url: `${ TainacanAI.restUrl }item-document/${ this.state.itemId }`,
					method: 'GET',
				} );

				this.state.documentInfo = response;
				this.state.attachmentId = response.id;
				this.showDocumentInfo( response );

				if ( TainacanAI.debug ) {
					console.log( '[TainacanAI] Document detected:', response );
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
		async analyze( forceRefresh = false, overridePrompt = null ) {
			if ( this.state.isAnalyzing ) return;

			this.ensureElementsCached();

			// Check if we have item or attachment
			if ( ! this.state.itemId && ! this.state.attachmentId ) {
				this.displayAnalysisError( TainacanAI.texts.noDocument );
				return;
			}

			const hasOverride =
				overridePrompt !== null &&
				overridePrompt !== undefined &&
				String( overridePrompt ).trim() !== '';

			this.state.isAnalyzing = true;
			this.beginAnalysisRun();
			this.showLoading();

			try {
				const requestData = {
					item_id: this.state.itemId,
					attachment_id: this.state.attachmentId,
					collection_id: this.state.collectionId,
					force_refresh: forceRefresh || hasOverride,
				};

				this.setAnalysisLoadingPhase( 'extracting' );

				const extractResponse = await apiFetch( {
					url: `${ TainacanAI.restUrl }extract`,
					method: 'POST',
					data: requestData,
				} );

				this.state.lastExtraction = extractResponse.extraction || null;
				this.updateImageDataTab( extractResponse.extraction?.exif || null );

				const analyzeRequestData = { ...requestData };

				if ( hasOverride && TainacanAI.advancedDebug ) {
					analyzeRequestData.override_prompt = String( overridePrompt );
				}

				this.setAnalysisLoadingPhase( 'analyzing' );

				const response = await apiFetch( {
					url: `${ TainacanAI.restUrl }analyze`,
					method: 'POST',
					data: analyzeRequestData,
				} );

				this.state.lastResult = response.result;
				this.state.lastFromCache = Boolean( response.from_cache );
				this.state.lastRunId = this.resolveRunId( response.result );
				if ( response.prompt_debug && ! hasOverride ) {
					this.applyPromptDebugFromPayload( response.prompt_debug );
				}
				if ( TainacanAI.debug && response.prompt_debug ) {
						console.group( '[TainacanAI] Resolved analysis prompt' );
						if ( response.prompt_debug.parts ) {
							console.log( 'User intro:', response.prompt_debug.parts.user );
							console.log( 'Fields section:', response.prompt_debug.parts.fields );
							console.log( 'Evidence / format:', response.prompt_debug.parts.evidence );
						}
						if ( response.prompt_debug.analysis_mode ) {
							console.log(
								'Analysis mode:',
								response.prompt_debug.analysis_mode
							);
						}
						if ( response.prompt_debug.attachment_note ) {
							console.log(
								'Attachment:',
								response.prompt_debug.attachment_note
							);
						}
						console.log( 'Full prompt:', response.prompt_debug.prompt );
						console.groupEnd();
				}
				this.displayResults( response.result, response.from_cache );
				this.updateExportButtonsState();
			} catch ( error ) {
				console.error( '[TainacanAI] Analysis error:', error );
				this.displayAnalysisError(
					error,
					this.state.analysisPhase === 'extracting' ? 'extraction' : 'analysis'
				);
				this.updateExportButtonsState();
			} finally {
				this.state.isAnalyzing = false;
				this.hideLoading();
			}
		},

		/**
		 * Render user-visible processing warnings (truncation, filtering, limits).
		 */
		renderProcessingWarningsHtml( warnings ) {
			if ( ! Array.isArray( warnings ) || warnings.length === 0 ) {
				return '';
			}

			const items = warnings
				.map( ( warning ) => {
					const severity =
						warning?.severity === 'info' ? 'info' : 'warning';
					const message =
						typeof warning?.message === 'string'
							? warning.message
							: '';

					if ( ! message ) {
						return '';
					}

					return `<li class="tainacan-ai-processing-warning is-${ severity }">${ this.escapeHtml(
						message
					) }</li>`;
				} )
				.filter( Boolean )
				.join( '' );

			if ( ! items ) {
				return '';
			}

			return `
                <div class="tainacan-ai-processing-warnings" role="status">
                    <p class="tainacan-ai-processing-warnings-title">
                        <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                        ${ this.escapeHtml(
							TainacanAI.texts?.processingWarningsTitle ||
								'Some content was not fully processed'
						) }
                    </p>
                    <ul class="tainacan-ai-processing-warnings-list">${ items }</ul>
                </div>
            `;
		},

		/**
		 * Display results
		 */
		displayResults( result, fromCache ) {
			this.state.lastAnalysisError = null;
			this.setSidebarResultsActionsVisible( true );

			// Cache badge
			if ( fromCache ) {
				this.elements.cacheBadge.show();
			} else {
				this.elements.cacheBadge.hide();
			}

			// AI metadata - render in sidebar panel
			if ( result.ai_metadata ) {
				this.renderMetadataInPanel(
					result.ai_metadata,
					result.processing?.warnings || []
				);
			} else {
				this.renderEmptyResultsPanel( result.processing?.warnings || [] );
			}

			this.updateImageDataTab( result.exif );
			this.updateRequestTabDetails( result );

			if ( TainacanAI.debug ) {
				console.log(
					'[TainacanAI] Model used:',
					result.provider_used || '',
					result.model_used || ''
				);
			}

			// Open sidebar panel automatically
			this.openSidebarPanel();
			this.updateExportButtonsState();
		},

		/**
		 * Resolve REST error payload from apiFetch / legacy response shapes.
		 *
		 * @return {{ response: object|null, data: object }}
		 */
		resolveRestErrorPayload( error ) {
			if ( ! error || typeof error !== 'object' ) {
				return { response: null, data: {} };
			}

			// api-fetch rejects with { code, message, data } on the error itself.
			const response =
				error.responseJSON && typeof error.responseJSON === 'object'
					? error.responseJSON
					: error;

			const data =
				( error.data && typeof error.data === 'object' ? error.data : null ) ||
				( response?.data && typeof response.data === 'object'
					? response.data
					: {} );

			return { response, data };
		},

		resolveAnalysisErrorMessage( code, fallbackMessage ) {
			if ( code === 'vision_text_model_refusal' ) {
				return (
					TainacanAI.texts?.errorVisionTextModelRefusal ||
					fallbackMessage
				);
			}

			if ( code === 'vision_images_not_forwarded' ) {
				return (
					TainacanAI.texts?.errorVisionImagesNotForwarded ||
					fallbackMessage
				);
			}

			return fallbackMessage;
		},

		/**
		 * Normalize analysis failures (REST error object or plain message).
		 */
		parseAnalysisError( error ) {
			if ( typeof error === 'string' ) {
				return {
					message: error,
					status: null,
					code: null,
					detailRows: [],
					debugDetails: null,
					requestMeta: null,
					processingWarnings: [],
					raw: null,
				};
			}

			const { response, data } = this.resolveRestErrorPayload( error );
			const status =
				error?.status ||
				error?.statusCode ||
				data.status ||
				null;
			const code =
				( typeof response?.code === 'string' && response.code ) ||
				( typeof error?.code === 'string' && error.code ) ||
				( typeof data.code === 'string' && data.code ) ||
				null;
			const message = this.resolveAnalysisErrorMessage(
				code,
				( typeof data.message === 'string' && data.message ) ||
					( typeof response?.message === 'string' && response.message ) ||
					( typeof error?.message === 'string' && error.message ) ||
					TainacanAI.texts.error
			);

			// Server only includes debug_details when advanced debug is allowed.
			const debugDetails = this.normalizeErrorDebugDetails(
				data.debug_details
			);

			const detailRows = [];
			const requestMeta = this.normalizeRequestMetaFromErrorData( data );

			const skipKeys = new Set( [
				'message',
				'status',
				'code',
				'data',
				'debug_details',
				'request_meta',
				'prompt_debug',
				'processing',
				...this.requestTabDebugFieldIds(),
			] );

			const appendDetail = ( label, value ) => {
				if ( value === null || value === undefined || value === '' ) {
					return;
				}
				const text =
					typeof value === 'string'
						? value
						: JSON.stringify( value, null, 2 );
				detailRows.push( { label, value: text } );
			};

			if ( data.params && typeof data.params === 'object' ) {
				appendDetail(
					TainacanAI.texts?.errorDetails || 'Details',
					data.params
				);
			}

			Object.entries( data ).forEach( ( [ key, value ] ) => {
				if ( skipKeys.has( key ) || key === 'params' ) {
					return;
				}
				appendDetail( this.formatLabel( key ), value );
			} );

			const processingWarnings = Array.isArray( data.processing?.warnings )
				? data.processing.warnings
				: [];

			return {
				message,
				status,
				code,
				detailRows,
				debugDetails,
				requestMeta,
				processingWarnings,
				raw: response || error || null,
			};
		},

		normalizeRequestMetaFromErrorData( data ) {
			if ( ! data || typeof data !== 'object' ) {
				return null;
			}

			const nested =
				data.request_meta && typeof data.request_meta === 'object'
					? data.request_meta
					: {};

			return this.normalizeRequestDetails( {
				...nested,
				...data,
				request_meta: nested,
			} );
		},

		normalizeRequestDetails( source ) {
			if ( ! source || typeof source !== 'object' ) {
				return null;
			}

			const nested =
				source.request_meta && typeof source.request_meta === 'object'
					? source.request_meta
					: {};
			const pick = ( key ) => {
				if ( source[ key ] !== undefined && source[ key ] !== null ) {
					return source[ key ];
				}
				return nested[ key ];
			};

			const details = {
				tokens_used: Number( pick( 'tokens_used' ) ?? pick( 'total_tokens' ) ?? 0 ),
				prompt_tokens: Number( pick( 'prompt_tokens' ) ?? 0 ),
				completion_tokens: Number( pick( 'completion_tokens' ) ?? 0 ),
				request_characters: Number( pick( 'request_characters' ) ?? 0 ),
				response_characters: Number( pick( 'response_characters' ) ?? 0 ),
				duration_ms: Number( pick( 'duration_ms' ) ?? 0 ),
				provider_used: String( pick( 'provider_used' ) ?? pick( 'provider' ) ?? '' ).trim(),
				provider_name: String( pick( 'provider_name' ) ?? '' ).trim(),
				model_used: String( pick( 'model_used' ) ?? pick( 'model' ) ?? '' ).trim(),
				model_name: String( pick( 'model_name' ) ?? '' ).trim(),
				finish_reason: String( pick( 'finish_reason' ) ?? '' ).trim(),
				analysis_mode: String( pick( 'analysis_mode' ) ?? '' ).trim(),
			};

			if ( ! this.requestDetailsHasData( details ) ) {
				return null;
			}

			return details;
		},

		requestDetailsHasData( details ) {
			if ( ! details || typeof details !== 'object' ) {
				return false;
			}

			return Boolean(
				details.tokens_used > 0 ||
					details.prompt_tokens > 0 ||
					details.completion_tokens > 0 ||
					details.request_characters > 0 ||
					details.response_characters > 0 ||
					details.duration_ms > 0 ||
					details.provider_used ||
					details.model_used ||
					details.finish_reason ||
					details.analysis_mode
			);
		},

		formatTokensBreakdown( details ) {
			const prompt = Number( details?.prompt_tokens ?? 0 );
			const completion = Number( details?.completion_tokens ?? 0 );
			const total = Number( details?.tokens_used ?? 0 );

			if ( ! prompt && ! completion && ! total ) {
				return '-';
			}

			if ( prompt || completion ) {
				const promptLabel = TainacanAI.texts?.tokensPrompt || 'prompt';
				const completionLabel =
					TainacanAI.texts?.tokensCompletion || 'completion';
				const totalLabel = TainacanAI.texts?.tokensTotal || 'total';
				const shownTotal = total || prompt + completion;

				return `${ prompt.toLocaleString() } ${ promptLabel } + ${ completion.toLocaleString() } ${ completionLabel } = ${ shownTotal.toLocaleString() } ${ totalLabel }`;
			}

			return `${ total.toLocaleString() } ${ TainacanAI.texts?.tokens || 'tokens' }`;
		},

		formatNamedIdentifier( displayName, id, unknownFallback = '-' ) {
			const name = String( displayName ?? '' ).trim();
			const identifier = String( id ?? '' ).trim();

			if ( name && identifier && name !== identifier ) {
				return `${ name } (${ identifier })`;
			}

			return name || identifier || unknownFallback;
		},

		formatFinishReason( value ) {
			const reason = String( value ?? '' ).trim();
			if ( ! reason ) {
				return '-';
			}

			const labels = {
				stop: TainacanAI.texts?.finishReasonStop || 'Completed normally',
				length:
					TainacanAI.texts?.finishReasonLength ||
					'Stopped at max length',
				content_filter:
					TainacanAI.texts?.finishReasonContentFilter ||
					'Blocked by content filter',
				tool_calls:
					TainacanAI.texts?.finishReasonToolCalls ||
					'Stopped for tool calls',
				error:
					TainacanAI.texts?.finishReasonError ||
					'Stopped due to error',
			};

			return labels[ reason ] || reason;
		},

		formatAnalysisMode( value ) {
			const mode = String( value ?? '' ).trim();
			if ( ! mode ) {
				return '-';
			}

			const labels = {
				image: TainacanAI.texts?.analysisModeImage || 'Image (vision)',
				text: TainacanAI.texts?.analysisModeText || 'Text',
				pdf_text: TainacanAI.texts?.analysisModePdfText || 'PDF text',
				pdf_visual:
					TainacanAI.texts?.analysisModePdfVisual || 'PDF visual',
			};

			return labels[ mode ] || mode.replace( /_/g, ' ' );
		},

		formatRequestDuration( durationMs ) {
			const ms = Number( durationMs ?? 0 );
			if ( ! ms ) {
				return '';
			}

			const seconds = ms / 1000;
			const label = TainacanAI.texts?.durationSeconds || 'seconds';

			return `${ seconds.toLocaleString( undefined, {
				minimumFractionDigits: 1,
				maximumFractionDigits: 1,
			} ) } ${ label }`;
		},

		setRequestDetailRow( $dd, text, visible ) {
			if ( ! $dd?.length ) {
				return;
			}

			const $row = $dd.closest( '.tainacan-ai-detail-row' );
			if ( ! $row.length ) {
				return;
			}

			if ( visible ) {
				$dd.text( text );
				$row.prop( 'hidden', false );
			} else {
				$dd.text( '' );
				$row.prop( 'hidden', true );
			}
		},

		hideAllRequestDetailRows() {
			[
				'#tainacan-ai-request-tokens',
				'#tainacan-ai-request-characters',
				'#tainacan-ai-request-response-characters',
				'#tainacan-ai-request-analysis-mode',
				'#tainacan-ai-request-finish-reason',
				'#tainacan-ai-request-duration',
				'#tainacan-ai-request-connector',
				'#tainacan-ai-request-model',
			].forEach( ( selector ) => {
				this.setRequestDetailRow( $( selector ), '', false );
			} );

			$( '.tainacan-ai-request-details' ).prop( 'hidden', true );
		},

		updateRequestDetailsListVisibility() {
			const $list = $( '.tainacan-ai-request-details' );
			if ( ! $list.length ) {
				return;
			}

			const hasVisibleRow = $list
				.find( '.tainacan-ai-detail-row' )
				.toArray()
				.some( ( row ) => ! row.hidden );

			$list.prop( 'hidden', ! hasVisibleRow );
		},

		formatRequestCharacters( value ) {
			const count = Number( value ?? 0 );
			if ( ! count ) {
				return '-';
			}

			const formatted = count.toLocaleString();
			const label = TainacanAI.texts?.characters || 'characters';
			return `${ formatted } ${ label }`;
		},

		normalizeErrorDebugDetails( debugDetails ) {
			if ( ! debugDetails || typeof debugDetails !== 'object' ) {
				return null;
			}

			if ( Array.isArray( debugDetails.sections ) ) {
				return debugDetails.sections.length ? debugDetails : null;
			}

			// Defensive: accept a bare list of sections.
			if ( Array.isArray( debugDetails ) && debugDetails.length ) {
				return { sections: debugDetails };
			}

			return null;
		},

		requestTabDebugFieldIds() {
			return [
				'model_used',
				'model',
				'model_name',
				'provider_used',
				'provider',
				'provider_name',
				'prompt_tokens',
				'completion_tokens',
				'tokens_used',
				'total_tokens',
				'finish_reason',
				'analysis_mode',
				'request_characters',
				'response_characters',
				'duration_ms',
				'http_status',
			];
		},

		resolveDebugFieldBaseId( fieldId ) {
			const id = String( fieldId ?? '' );
			const prefixes = [
				'underlying_',
				'visual_analysis_',
				'text_extraction_',
			];

			for ( const prefix of prefixes ) {
				if ( id.startsWith( prefix ) ) {
					return id.slice( prefix.length );
				}
			}

			return id;
		},

		isRequestTabDebugField( fieldId ) {
			const baseId = this.resolveDebugFieldBaseId( fieldId );
			return this.requestTabDebugFieldIds().includes( baseId );
		},

		primaryErrorDebugFieldIds() {
			return [ 'error_code', 'error_message', 'http_status' ];
		},

		isPrimaryErrorDebugField( fieldId ) {
			const baseId = this.resolveDebugFieldBaseId( fieldId );
			return this.primaryErrorDebugFieldIds().includes( baseId );
		},

		deduplicateDebugSectionsByCanonicalContent( sections ) {
			const canonical = new Map();

			sections.forEach( ( section ) => {
				const sectionId = String( section?.id ?? '' );
				const baseId = this.resolveDebugFieldBaseId( sectionId );
				if ( baseId === sectionId ) {
					canonical.set(
						baseId,
						String( section?.content ?? '' ).trim()
					);
				}
			} );

			return sections.filter( ( section ) => {
				const sectionId = String( section?.id ?? '' );
				const baseId = this.resolveDebugFieldBaseId( sectionId );
				if ( baseId === sectionId ) {
					return true;
				}

				const content = String( section?.content ?? '' ).trim();
				return ! (
					canonical.has( baseId ) && canonical.get( baseId ) === content
				);
			} );
		},

		filterDebugSectionsForDisplay( debugDetails, requestMeta, parsed ) {
			const normalized = this.normalizeErrorDebugDetails( debugDetails );
			if ( ! normalized?.sections?.length ) {
				return null;
			}

			let sections = this.deduplicateDebugSectionsByCanonicalContent(
				normalized.sections
			);

			sections = sections.filter( ( section ) => {
				const sectionId = section?.id;
				if (
					this.requestDetailsHasData( requestMeta ) &&
					this.isRequestTabDebugField( sectionId )
				) {
					return false;
				}

				if ( ! this.isPrimaryErrorDebugField( sectionId ) ) {
					return true;
				}

				const content = String( section?.content ?? '' ).trim();
				const baseId = this.resolveDebugFieldBaseId( sectionId );

				if (
					baseId === 'error_code' &&
					parsed?.code &&
					content === parsed.code
				) {
					return false;
				}

				if (
					baseId === 'http_status' &&
					parsed?.status &&
					content === String( parsed.status )
				) {
					return false;
				}

				if ( baseId === 'error_message' && content && parsed?.message ) {
					return ! parsed.message.includes( content );
				}

				return true;
			} );

			return sections.length ? { sections } : null;
		},

		debugDetailInlineMaxLength() {
			return 240;
		},

		shouldUseCollapsibleDebugDetail( section ) {
			const content = String( section?.content ?? '' );
			if ( section?.truncated ) {
				return true;
			}
			if ( content.includes( '\n' ) ) {
				return true;
			}

			return content.length > this.debugDetailInlineMaxLength();
		},

		renderDebugDetailSection( section ) {
			const label = section.label || section.id || '';
			const content = section.content || '';
			const truncatedLabel =
				TainacanAI.texts?.errorContentTruncated || 'truncated';
			const summarySuffix = section.truncated
				? ` (${ truncatedLabel })`
				: '';

			if ( ! this.shouldUseCollapsibleDebugDetail( section ) ) {
				return `
                <div class="tainacan-ai-detail-row">
                    <dt>${ this.escapeHtml( label ) }${ summarySuffix }</dt>
                    <dd>${ this.escapeHtml( content ) }</dd>
                </div>
            `;
			}

			return `
                <details class="tainacan-ai-error-debug-section">
                    <summary>${ this.escapeHtml( label ) }${ summarySuffix }</summary>
                    <pre class="tainacan-ai-error-debug-content">${ this.escapeHtml(
						content
					) }</pre>
                </details>
            `;
		},

		renderAnalysisErrorDebugSections(
			debugDetails,
			requestMeta = null,
			parsed = null
		) {
			if ( ! TainacanAI.advancedDebug ) {
				return '';
			}

			const normalized = this.filterDebugSectionsForDisplay(
				debugDetails,
				requestMeta,
				parsed
			);
			if ( ! normalized?.sections?.length ) {
				return '';
			}

			const inlineRows = [];
			const collapsibleBlocks = [];

			normalized.sections.forEach( ( section ) => {
				const html = this.renderDebugDetailSection( section );
				if ( this.shouldUseCollapsibleDebugDetail( section ) ) {
					collapsibleBlocks.push( html );
				} else {
					inlineRows.push( html );
				}
			} );

			const inlineHtml = inlineRows.length
				? `<dl class="tainacan-ai-detail-list tainacan-ai-debug-details">${ inlineRows.join(
						''
				  ) }</dl>`
				: '';

			return `
                <div class="tainacan-ai-error-debug-group">
                    <p class="tainacan-ai-error-debug-heading">${ this.escapeHtml(
						TainacanAI.texts?.errorDebugDetails || 'Technical details'
					) }</p>
                    ${ inlineHtml }
                    ${ collapsibleBlocks.join( '' ) }
                </div>
            `;
		},

		/**
		 * Show analysis failure in the sidebar Analysis Results tab.
		 */
		displayAnalysisError( error, phase = 'analysis' ) {
			this.ensureElementsCached();
			this.state.lastResult = null;
			this.state.lastAnalysisError = this.parseAnalysisError( error );

			if (
				! this.elements.sidebarContent ||
				! this.elements.sidebarContent.length
			) {
				this.createSidebarPanel();
			}

			const parsed = this.state.lastAnalysisError;
			const $summary = $( '#tainacan-ai-panel-meta-summary' );
			if ( $summary.length ) {
				$summary.text(
					phase === 'extraction'
						? TainacanAI.texts?.extractionFailedSummary ||
								'Extraction failed'
						: TainacanAI.texts?.analysisFailedSummary ||
								'Analysis failed'
				);
			}

			this.setSidebarResultsActionsVisible( false );
			this.updateImageDataTab( null );
			this.updateRequestTabDetails( parsed.requestMeta );

			const { data: errorData } = this.resolveRestErrorPayload( error );
			if ( errorData?.prompt_debug ) {
				this.applyPromptDebugFromPayload( errorData.prompt_debug );
			}

			this.updateRequestTabAvailability(
				this.computeRequestTabHasData( parsed.requestMeta )
			);
			this.updateExportButtonsState();

			const debugSectionsHtml = this.renderAnalysisErrorDebugSections(
				parsed.debugDetails,
				parsed.requestMeta,
				parsed
			);

			const showInlineErrorDetails = ! debugSectionsHtml;

			const detailHtml = showInlineErrorDetails
				? parsed.detailRows
						.map(
							( row ) => `
                <div class="tainacan-ai-detail-row">
                    <dt>${ this.escapeHtml( row.label ) }</dt>
                    <dd>${ this.escapeHtml( row.value ) }</dd>
                </div>
            `
						)
						.join( '' )
				: '';

			const metaRows = [];
			if ( showInlineErrorDetails && parsed.status ) {
				metaRows.push( {
					label: TainacanAI.texts?.errorHttpStatus || 'HTTP status',
					value: String( parsed.status ),
				} );
			}
			if ( showInlineErrorDetails && parsed.code ) {
				metaRows.push( {
					label: TainacanAI.texts?.errorCode || 'Error code',
					value: parsed.code,
				} );
			}

			const metaHtml = metaRows
				.map(
					( row ) => `
                <div class="tainacan-ai-detail-row">
                    <dt>${ this.escapeHtml( row.label ) }</dt>
                    <dd><code>${ this.escapeHtml( row.value ) }</code></dd>
                </div>
            `
				)
				.join( '' );
			const processingWarningsHtml = this.renderProcessingWarningsHtml(
				errorData?.processing?.warnings || []
			);

			const showRawResponse =
				TainacanAI.advancedDebug &&
				parsed.raw &&
				! parsed.debugDetails?.sections?.length;

			const debugBlock = showRawResponse
				? `
                <details class="tainacan-ai-error-response-details">
                    <summary>${ this.escapeHtml(
						TainacanAI.texts?.errorResponse || 'Server response'
					) }</summary>
                    <pre class="tainacan-ai-error-response-json">${ this.escapeHtml(
						JSON.stringify( parsed.raw, null, 2 )
					) }</pre>
                </details>
            `
				: '';

			this.elements.sidebarContent.html( `
                ${ processingWarningsHtml }
                <div class="tainacan-ai-sidebar-error">
                    <div class="tainacan-ai-error">
                        <span class="dashicons dashicons-warning"></span>
                        <div class="tainacan-ai-error-text">
                            <strong>${ this.escapeHtml(
								TainacanAI.texts?.errorLabel || 'Error'
							) }</strong>
                            <span>${ this.escapeHtml( parsed.message ) }</span>
                        </div>
                    </div>
                    ${
						metaHtml || detailHtml
							? `<dl class="tainacan-ai-detail-list tainacan-ai-error-details">${ metaHtml }${ detailHtml }</dl>`
							: ''
					}
                    ${ debugSectionsHtml }
                    ${ debugBlock }
                </div>
            ` );

			this.switchSidebarTab( 'results' );
			this.openSidebarPanel();
		},

		setSidebarResultsActionsVisible( visible ) {
			const $actions = $( '.tainacan-ai-sidebar-actions' );
			if ( ! $actions.length ) {
				return;
			}
			$actions.toggle( visible );
		},

		showSidebarAnalysisLoading( phase = 'analyzing' ) {
			if (
				! this.elements.sidebarContent ||
				! this.elements.sidebarContent.length
			) {
				this.createSidebarPanel();
			}

			this.state.analysisPhase = phase;

			const title =
				phase === 'extracting'
					? TainacanAI.texts?.extracting || 'Extracting document...'
					: TainacanAI.texts?.analyzing || 'Analyzing...';

			const $summary = $( '#tainacan-ai-panel-meta-summary' );
			if ( $summary.length ) {
				$summary.text( title );
			}

			this.setSidebarResultsActionsVisible( false );
			this.updateRequestTabDetails( null );
			this.elements.sidebarContent.html( `
                <div class="tainacan-ai-sidebar-loading tainacan-ai-loading">
                    <div class="tainacan-ai-spinner"></div>
                    <div class="tainacan-ai-loading-text">
                        <span class="tainacan-ai-loading-title">${ this.escapeHtml(
							title
						) }</span>
                        <span class="tainacan-ai-loading-subtitle">${ this.escapeHtml(
							TainacanAI.texts?.loadingSubtitle ||
								'This may take a few seconds'
						) }</span>
                    </div>
                </div>
            ` );

			this.switchSidebarTab( 'results' );
			this.openSidebarPanel();
		},

		setAnalysisLoadingPhase( phase ) {
			this.showSidebarAnalysisLoading( phase );
		},

		/**
		 * Coerce AI field payload to { value, evidence, label? } (parallel arrays when multivalued).
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
				let label = data.label ?? null;
				const pendingNewTerms = this.normalizePendingNewTerms(
					data.pending_new_terms
				);

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

					const hasLabel =
						label !== null &&
						label !== undefined &&
						label !== '' &&
						! ( Array.isArray( label ) && label.length === 0 );
					if ( ! hasLabel ) {
						label = coalesced.label;
					}
				}

				return {
					value,
					evidence,
					label: this.sanitizeMetadataLabel( label ),
					pendingNewTerms,
				};
			}

			return {
				value: data,
				evidence: null,
				label: null,
				pendingNewTerms: [],
			};
		},

		sanitizeMetadataLabel( label ) {
			if ( label === null || label === undefined || label === '' ) {
				return null;
			}

			if ( typeof label === 'string' ) {
				const trimmed = label.trim();
				return trimmed === '' ? null : trimmed;
			}

			if ( ! Array.isArray( label ) || label.length === 0 ) {
				return null;
			}

			const filtered = label
				.map( ( item ) =>
					item === null || item === undefined ? '' : String( item ).trim()
				)
				.filter( ( item ) => item !== '' );

			return filtered.length > 0 ? filtered : null;
		},

		normalizePendingNewTerms( pendingTerms ) {
			if ( ! Array.isArray( pendingTerms ) ) {
				return [];
			}

			return pendingTerms
				.map( ( row ) => {
					if ( ! row || typeof row !== 'object' || Array.isArray( row ) ) {
						return null;
					}

					const label =
						typeof row.label === 'string' ? row.label.trim() : '';
					if ( ! label ) {
						return null;
					}

					const evidence =
						row.evidence === null || row.evidence === undefined
							? null
							: String( row.evidence ).trim();

					return {
						label,
						evidence: evidence || null,
					};
				} )
				.filter( Boolean );
		},

		coalesceValueEvidenceObjects( items ) {
			return {
				value: items.map( ( item ) => item.value ),
				evidence: items.map( ( item ) =>
					item.evidence != null ? String( item.evidence ) : ''
				),
				label: this.sanitizeMetadataLabel(
					items.map( ( item ) =>
						item.label != null ? String( item.label ) : ''
					)
				),
			};
		},

		isEmptyMetadataValue( value ) {
			if ( value === null || value === undefined || value === '' ) {
				return true;
			}

			if ( Array.isArray( value ) ) {
				if ( value.length === 0 ) {
					return true;
				}

				return value.every( ( entry ) => {
					if ( entry === null || entry === undefined ) {
						return true;
					}

					if ( typeof entry === 'string' ) {
						return entry.trim() === '';
					}

					return false;
				} );
			}

			return false;
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

		renderPendingNewTermsBlock(
			metadataKey,
			pendingTerms,
			canCreatePendingTerms = false
		) {
			const title =
				TainacanAI.texts?.pendingTermsTitle || 'Suggested new terms';
			const helperText =
				TainacanAI.texts?.pendingTermsHint ||
				'No existing term matched. Review and create if appropriate.';
			const createText =
				TainacanAI.texts?.createTermAndApply || 'Create';

			const rows = pendingTerms
				.map( (term, index) => {
					const evidence = term?.evidence
						? `<div class="tainacan-ai-pending-term-evidence">${ this.escapeHtml(
								String( term.evidence )
						  ) }</div>`
						: '';

					return `
                        <div class="tainacan-ai-pending-term-row">
                            <div class="tainacan-ai-pending-term-controls">
                                <input
                                    type="text"
                                    class="tainacan-ai-pending-term-input"
                                    data-metadata-key="${ this.escapeHtml(
										metadataKey
									) }"
                                    data-term-index="${ index }"
                                    value="${ this.escapeHtml( term.label ) }"
                                />
                                ${
									canCreatePendingTerms
										? `<button type="button"
                                    class="button button-secondary tainacan-ai-create-pending-term"
                                    data-metadata-key="${ this.escapeHtml(
										metadataKey
									) }"
                                    data-term-index="${ index }">
                                    ${ this.escapeHtml( createText ) }
                                </button>`
										: ''
								}
                            </div>
                            ${ evidence }
                        </div>
                    `;
				} )
				.join( '' );

			return `
                <div class="tainacan-ai-pending-terms-block">
                    <div class="tainacan-ai-pending-terms-title">${ this.escapeHtml(
						title
					) }</div>
                    <div class="tainacan-ai-pending-terms-hint">${ this.escapeHtml(
						helperText
					) }</div>
                    <div class="tainacan-ai-pending-terms-list">${ rows }</div>
                </div>
            `;
		},

		renderEmptyResultsPanel( warnings = [] ) {
			this.ensureElementsCached();

			if (
				! this.elements.sidebarContent ||
				! this.elements.sidebarContent.length
			) {
				this.createSidebarPanel();
			}

			const $container = this.elements.sidebarContent;
			if ( ! $container || ! $container.length ) {
				return;
			}

			const warningsHtml = this.renderProcessingWarningsHtml( warnings );
			$container.html(
				`${ warningsHtml }<div class="tainacan-ai-panel-placeholder"><span class="dashicons dashicons-search"></span><p>${ this.escapeHtml(
					TainacanAI.texts?.noResults || 'No results available'
				) }</p></div>`
			);
			this.updatePanelMetadataSummary( {} );
		},

		/**
		 * Render metadata in sidebar panel with evidence
		 */
		renderMetadataInPanel( metadata, warnings = [] ) {
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
			this.updatePanelMetadataSummary( metadata );

			const warningsHtml = this.renderProcessingWarningsHtml( warnings );
			if ( warningsHtml ) {
				$container.append( warningsHtml );
			}

			Object.entries( metadata ).forEach( ( [ key, data ], index ) => {
				const formattedLabel = this.formatLabel( key );

				const extractionFields = TainacanAI.extractionFields || {};
				const extractionField = extractionFields[ key ];
				const { value, evidence, label, pendingNewTerms } =
					this.parseFieldEntry( data );
				const displayValue = this.resolveDisplayValueForField(
					value,
					label,
					extractionField
				);
				const formattedValue = this.formatValue( displayValue );
				const formattedEvidence = this.formatEvidence( evidence );
				const isEmpty = this.isEmptyMetadataValue( displayValue );
				const hasFillValue = ! this.isEmptyMetadataValue( value );
				const hasPendingNewTerms =
					Array.isArray( pendingNewTerms ) && pendingNewTerms.length > 0;
				const displayLabel = extractionField?.name
					? this.escapeHtml( extractionField.name )
					: formattedLabel;
				const canFill = extractionField && hasFillValue;
				const canCreatePendingTerms =
					extractionField &&
					extractionField.allow_new_terms === true &&
					String( extractionField?.type || '' )
						.toLowerCase()
						.includes( 'taxonomy' ) &&
					Number( extractionField?.taxonomy_id ) > 0;
				const notFoundText =
					TainacanAI.texts?.valueNotFound || 'Not found in document';
				const pendingTermsHtml = hasPendingNewTerms
					? this.renderPendingNewTermsBlock(
							key,
							pendingNewTerms,
							canCreatePendingTerms
					  )
					: '';

				let $item;

				if ( isEmpty && ! hasPendingNewTerms ) {
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
                                </div>
                                <div class="tainacan-ai-metadata-actions-mini">
                                    ${
										canFill
											? `
                                    <button type="button" class="button button-secondary is-small tainacan-ai-fill-field"
                                            data-metadata-key="${ this.escapeHtml(
												key
											) }"
                                            title="${
												TainacanAI.texts?.fillField ||
												'Fill metadatum'
											}">
                                        <span class="dashicons dashicons-yes"></span>
                                    </button>
                                    `
											: ''
									}
                                    <button type="button" class="button button-secondary is-small tainacan-ai-copy-mini"
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
                            ${
								isEmpty
									? ''
									: `<div class="tainacan-ai-metadata-value-box">
                                <div class="tainacan-ai-metadata-value-text">${ formattedValue }</div>
                            </div>`
							}
                            ${ pendingTermsHtml }
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

		updatePanelMetadataSummary( metadata ) {
			const $summary = $( '#tainacan-ai-panel-meta-summary' );
			if ( ! $summary.length ) {
				return;
			}

			const extractedCount = Object.values( metadata || {} ).reduce(
				( count, fieldData ) => {
					const { value, pendingNewTerms } = this.parseFieldEntry( fieldData );
					const hasValue = ! this.isEmptyMetadataValue( value );
					const hasPendingTerms =
						Array.isArray( pendingNewTerms ) && pendingNewTerms.length > 0;
					return hasValue || hasPendingTerms ? count + 1 : count;
				},
				0
			);

			$summary.text( `${ extractedCount } metadata extracted` );
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

		hasExifData( exif ) {
			return Boolean(
				exif && typeof exif === 'object' && Object.keys( exif ).length > 0
			);
		},

		switchSidebarTab( tabId ) {
			if ( ! tabId ) {
				return;
			}

			if (
				tabId === 'image-data' &&
				this.elements.sidebarTabImageData?.prop( 'hidden' )
			) {
				return;
			}

			if (
				tabId === 'request' &&
				( this.elements.sidebarTabRequest?.prop( 'hidden' ) ||
					this.elements.sidebarTabRequest?.prop( 'disabled' ) )
			) {
				return;
			}

			this.state.activeSidebarTab = tabId;

			$( '.tainacan-ai-sidebar-tab' )
				.removeClass( 'active' )
				.attr( 'aria-selected', 'false' );
			$( `.tainacan-ai-sidebar-tab[data-tab="${ tabId }"]` )
				.addClass( 'active' )
				.attr( 'aria-selected', 'true' );

			$( '.tainacan-ai-sidebar-tab-panel' )
				.removeClass( 'active' )
				.prop( 'hidden', true );
			$( `#tainacan-ai-tab-panel-${ tabId }` )
				.addClass( 'active' )
				.prop( 'hidden', false );
		},

		computeRequestTabHasData( requestMeta = null ) {
			if ( this.requestDetailsHasData( requestMeta ) ) {
				return true;
			}

			if ( ! TainacanAI.advancedDebug ) {
				return false;
			}

			if (
				this.state.lastPrompt &&
				String( this.state.lastPrompt ).trim()
			) {
				return true;
			}

			if ( this.state.lastDocumentBody ) {
				return this.resolveDocumentPreviewContent(
					this.state.lastDocumentBody
				).showPreview;
			}

			return false;
		},

		updateRequestTabAvailability( hasData = null ) {
			this.reconcileSidebarElements();

			const $tab = this.elements.sidebarTabRequest;
			if ( ! $tab?.length ) {
				return;
			}

			const available =
				hasData !== null ? Boolean( hasData ) : this.computeRequestTabHasData();

			$tab.prop( 'hidden', ! available );
			$tab.prop( 'disabled', ! available );
			$tab.attr( 'aria-disabled', available ? 'false' : 'true' );

			if ( ! available && this.state.activeSidebarTab === 'request' ) {
				this.switchSidebarTab( 'results' );
			}
		},

		updateRequestTabDetails( result ) {
			this.reconcileSidebarElements();

			const $tokens = $( '#tainacan-ai-request-tokens' );
			const $characters = $( '#tainacan-ai-request-characters' );
			const $responseCharacters = $(
				'#tainacan-ai-request-response-characters'
			);
			const $analysisMode = $( '#tainacan-ai-request-analysis-mode' );
			const $finishReason = $( '#tainacan-ai-request-finish-reason' );
			const $duration = $( '#tainacan-ai-request-duration' );
			const $connector = $( '#tainacan-ai-request-connector' );
			const $model = $( '#tainacan-ai-request-model' );

			if (
				! $tokens.length ||
				! $characters.length ||
				! $responseCharacters.length ||
				! $analysisMode.length ||
				! $finishReason.length ||
				! $duration.length ||
				! $connector.length ||
				! $model.length
			) {
				return;
			}

			const details = this.normalizeRequestDetails( result );

			if ( ! details ) {
				this.hideAllRequestDetailRows();
				this.updateRequestTabAvailability( false );
				return;
			}

			const hasTokens =
				details.tokens_used > 0 ||
				details.prompt_tokens > 0 ||
				details.completion_tokens > 0;
			const hasRequestCharacters = details.request_characters > 0;
			const hasResponseCharacters = details.response_characters > 0;
			const hasAnalysisMode = Boolean( details.analysis_mode );
			const hasFinishReason = Boolean( details.finish_reason );
			const hasDuration = details.duration_ms > 0;
			const hasConnector = Boolean(
				details.provider_used || details.provider_name
			);
			const hasModel = Boolean( details.model_used || details.model_name );

			this.setRequestDetailRow(
				$tokens,
				this.formatTokensBreakdown( details ),
				hasTokens
			);
			this.setRequestDetailRow(
				$characters,
				this.formatRequestCharacters( details.request_characters ),
				hasRequestCharacters
			);
			this.setRequestDetailRow(
				$responseCharacters,
				this.formatRequestCharacters( details.response_characters ),
				hasResponseCharacters
			);
			this.setRequestDetailRow(
				$analysisMode,
				this.formatAnalysisMode( details.analysis_mode ),
				hasAnalysisMode
			);
			this.setRequestDetailRow(
				$finishReason,
				this.formatFinishReason( details.finish_reason ),
				hasFinishReason
			);
			this.setRequestDetailRow(
				$duration,
				this.formatRequestDuration( details.duration_ms ),
				hasDuration
			);
			this.setRequestDetailRow(
				$connector,
				this.formatNamedIdentifier(
					details.provider_name,
					details.provider_used,
					''
				),
				hasConnector
			);
			this.setRequestDetailRow(
				$model,
				this.formatNamedIdentifier(
					details.model_name,
					details.model_used,
					''
				),
				hasModel
			);

			this.updateRequestDetailsListVisibility();

			this.updateRequestTabAvailability(
				this.computeRequestTabHasData( details )
			);
		},

		updateImageDataTab( exif ) {
			this.reconcileSidebarElements();

			const hasExif = this.hasExifData( exif );

			if ( this.elements.sidebarTabImageData?.length ) {
				this.elements.sidebarTabImageData.prop( 'hidden', ! hasExif );
			}

			if ( hasExif ) {
				this.renderExif( exif );
				return;
			}

			if ( this.state.activeSidebarTab === 'image-data' ) {
				this.switchSidebarTab( 'results' );
			}

			if ( this.elements.exifContent?.length ) {
				this.elements.exifContent.empty();
			}
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
				const { value, label } = this.parseFieldEntry( metadataEntry );
				return this.stringifyClipboardValue(
					this.resolveDisplayValueForKey( metadataKey, value, label )
				);
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
					const { value, label } = this.parseFieldEntry( data );
					const formattedValue = this.stringifyClipboardValue(
						this.resolveDisplayValueForKey( key, value, label )
					);
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
			this.elements.status.hide();
			this.showSidebarAnalysisLoading();
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

				const tags = value
					.map( ( v ) =>
						v === null || v === undefined ? '' : String( v ).trim()
					)
					.filter( ( v ) => v !== '' )
					.map(
						( v ) =>
							`<span class="tainacan-ai-tag">${ this.escapeHtml(
								v
							) }</span>`
					);

				if ( tags.length === 0 ) {
					return '<span class="tainacan-ai-empty-value">-</span>';
				}

				return tags.join( ' ' );
			}

			if ( typeof value === 'object' ) {
				return `<pre class="tainacan-ai-json">${ this.escapeHtml(
					JSON.stringify( value, null, 2 )
				) }</pre>`;
			}

			return this.escapeHtml( String( value ) );
		},

		resolveDisplayValue( value, label = null ) {
			if ( ! this.isEmptyMetadataValue( label ) ) {
				return label;
			}

			return value;
		},

		resolveDisplayValueForField( value, label = null, fieldInfo = null ) {
			const explicitDisplayValue = this.resolveDisplayValue( value, label );
			if ( explicitDisplayValue !== value ) {
				return explicitDisplayValue;
			}

			const normalizedType = String( fieldInfo?.type || '' ).toLowerCase();
			if ( ! normalizedType.includes( 'taxonomy' ) ) {
				return value;
			}

			const allowedValueOptions = fieldInfo?.allowed_value_options;
			if (
				! Array.isArray( allowedValueOptions ) ||
				allowedValueOptions.length === 0
			) {
				return value;
			}

			const resolveOne = ( entry ) => {
				const numericEntry = Number( entry );
				if ( Number.isInteger( numericEntry ) && numericEntry > 0 ) {
					const matched = allowedValueOptions.find(
						( candidate ) =>
							candidate &&
							typeof candidate === 'object' &&
							Number( candidate.value ) === numericEntry &&
							typeof candidate.label === 'string' &&
							candidate.label.trim() !== ''
					);
					return matched ? matched.label : entry;
				}
				return entry;
			};

			if ( Array.isArray( value ) ) {
				return value.map( resolveOne );
			}

			return resolveOne( value );
		},

		resolveDisplayValueForKey( metadataKey, value, label = null ) {
			const extractionFields = TainacanAI.extractionFields || {};
			const extractionField = metadataKey ? extractionFields[ metadataKey ] : null;
			return this.resolveDisplayValueForField( value, label, extractionField );
		},

		mergeFieldTermIds( existingValue, newTermId ) {
			const merged = [];
			const pushIfValid = ( candidate ) => {
				const parsed = Number( candidate );
				if ( ! Number.isInteger( parsed ) || parsed <= 0 ) {
					return;
				}
				if ( ! merged.includes( parsed ) ) {
					merged.push( parsed );
				}
			};

			if ( Array.isArray( existingValue ) ) {
				existingValue.forEach( pushIfValid );
			} else {
				pushIfValid( existingValue );
			}
			pushIfValid( newTermId );

			return merged;
		},

		updateMetadataEntryAfterPendingCreate(
			metadataKey,
			termIndex,
			newTermId,
			newLabel,
			isMultiple
		) {
			if ( ! this.state.lastResult?.ai_metadata ) {
				return;
			}

			const rawEntry = this.state.lastResult.ai_metadata[ metadataKey ];
			if ( ! rawEntry || typeof rawEntry !== 'object' || Array.isArray( rawEntry ) ) {
				return;
			}

			const updated = { ...rawEntry };
			const mergedIds = this.mergeFieldTermIds( updated.value, newTermId );

			if ( isMultiple ) {
				updated.value = mergedIds;
				const currentLabels = Array.isArray( updated.label )
					? updated.label
						.map( (entry) =>
							typeof entry === 'string' ? entry.trim() : ''
						)
						.filter( Boolean )
					: [];
				if ( newLabel && ! currentLabels.includes( newLabel ) ) {
					currentLabels.push( newLabel );
				}
				if ( currentLabels.length > 0 ) {
					updated.label = currentLabels;
				}
			} else {
				updated.value = mergedIds[ 0 ] || newTermId;
				if ( newLabel ) {
					updated.label = newLabel;
				}
			}

			const pending = this.normalizePendingNewTerms( updated.pending_new_terms );
			updated.pending_new_terms = pending.filter(
				( _, index ) => index !== termIndex
			);
			if ( updated.pending_new_terms.length === 0 ) {
				delete updated.pending_new_terms;
			}

			this.state.lastResult.ai_metadata[ metadataKey ] = updated;
		},

		async createTaxonomyTerm( taxonomyId, payload ) {
			const baseUrl = this.getTainacanApiBaseUrl();
			const url = `${ baseUrl }/taxonomy/${ taxonomyId }/terms`;

			return apiFetch( {
				url,
				method: 'POST',
				data: payload,
			} );
		},

		extractTermIdFromResponse( response ) {
			const candidates = [
				response?.id,
				response?.data?.id,
				response?.term_id,
				response?.data?.term_id,
			];

			for ( const candidate of candidates ) {
				const parsed = Number( candidate );
				if ( Number.isInteger( parsed ) && parsed > 0 ) {
					return parsed;
				}
			}

			return null;
		},

		async createPendingTermAndFill( metadataKey, termIndex, $btn = null ) {
			const extractionFields = TainacanAI.extractionFields || {};
			const fieldInfo = extractionFields[ metadataKey ];
			if ( ! fieldInfo ) {
				this.showError(
					TainacanAI.texts?.noExtractionFields ||
						'No extraction-enabled metadata found'
				);
				return;
			}

			const taxonomyId = Number( fieldInfo?.taxonomy_id || 0 );
			if ( taxonomyId <= 0 ) {
				this.showError(
					TainacanAI.texts?.createTermMissingTaxonomy ||
						'Taxonomy field is not configured for term creation.'
				);
				return;
			}

			const aiEntry = this.state.lastResult?.ai_metadata?.[ metadataKey ];
			const parsedEntry = this.parseFieldEntry( aiEntry );
			const pendingTerms = parsedEntry.pendingNewTerms || [];
			const pending = pendingTerms[ termIndex ];
			if ( ! pending ) {
				this.showError(
					TainacanAI.texts?.pendingTermNotFound ||
						'Suggested term is no longer available.'
				);
				return;
			}

			const selector = `.tainacan-ai-pending-term-input[data-metadata-key="${ this.escapeHtml(
				metadataKey
			) }"][data-term-index="${ termIndex }"]`;
			const inputValue = $( selector ).val();
			const termLabel = String( inputValue ?? pending.label ?? '' ).trim();
			if ( termLabel === '' ) {
				this.showError(
					TainacanAI.texts?.pendingTermEmpty ||
						'Please provide a term name before creating it.'
				);
				return;
			}

			if ( $btn && $btn.length ) {
				$btn.prop( 'disabled', true );
			}

			let createdTermId = null;
			try {
				const created = await this.createTaxonomyTerm( taxonomyId, {
					name: termLabel,
					item_id: this.state.itemId,
					metadatum_id: fieldInfo.id,
				} );
				createdTermId = this.extractTermIdFromResponse( created );
				if ( ! createdTermId ) {
					throw new Error(
						TainacanAI.texts?.createTermMissingId ||
							'The created term response did not include an ID.'
					);
				}
			} catch ( error ) {
				const parsedError = this.parseMetadataUpdateError( error );
				this.showError(
					`${ TainacanAI.texts?.createTermFailed || 'Could not create term.' }: ${
						parsedError.message
					}`
				);
				if ( $btn && $btn.length ) {
					$btn.prop( 'disabled', false );
				}
				return;
			}

			const mergedIds = this.mergeFieldTermIds( parsedEntry.value, createdTermId );
			const valueToFill = fieldInfo.multiple === true ? mergedIds : createdTermId;
			const fillResult = await this.fillTainacanField(
				metadataKey,
				valueToFill,
				null,
				{
					dispatchReloadEvent: true,
				}
			);

			if ( ! fillResult.success ) {
				if ( $btn && $btn.length ) {
					$btn.prop( 'disabled', false );
				}
				return;
			}

			this.updateMetadataEntryAfterPendingCreate(
				metadataKey,
				termIndex,
				createdTermId,
				termLabel,
				fieldInfo.multiple === true
			);
			this.renderMetadataInPanel(
				this.state.lastResult.ai_metadata,
				this.state.lastResult.processing?.warnings || []
			);
			this.showToast(
				TainacanAI.texts?.termCreatedAndApplied || 'Term created and applied.'
			);
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

			const aiEntry = this.state.lastResult?.ai_metadata?.[ metadataKey ];
			const parsedEntry = this.parseFieldEntry( aiEntry );
			const hasPendingNewTerms =
				Array.isArray( parsedEntry.pendingNewTerms ) &&
				parsedEntry.pendingNewTerms.length > 0;

			if (
				fieldValue === null ||
				fieldValue === undefined ||
				fieldValue === '' ||
				( Array.isArray( fieldValue ) && fieldValue.length === 0 )
			) {
				if ( hasPendingNewTerms ) {
					const message =
						TainacanAI.texts?.pendingTermsNeedCreation ||
						'Create suggested terms first, then fill this field.';
					this.showToast( message );
					return {
						success: false,
						error: message,
						skipped: true,
					};
				}

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

			return apiFetch( {
				url,
				method: 'PUT',
				data: payload,
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
				return values.map( ( entry ) =>
					this.normalizeSingleValue( entry, fieldType )
				);
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
				return value.length > 0
					? this.normalizeSingleValue( value[ 0 ], fieldType )
					: '';
			}

			return this.normalizeSingleValue( value, fieldType );
		},

		normalizeSingleValue( value, fieldType = null ) {
			if ( value === null || value === undefined ) {
				return '';
			}

			const normalizedType = fieldType ? String( fieldType ).toLowerCase() : '';
			if ( normalizedType.includes( 'taxonomy' ) ) {
				const parsed = Number( value );
				if ( Number.isInteger( parsed ) && parsed > 0 ) {
					return parsed;
				}
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

		beginAnalysisRun() {
			this.state.lastRunId = String( Date.now() );
			this.state.lastRunStartedAt = new Date().toISOString();
			this.state.lastFromCache = false;
			this.state.lastAnalysisError = null;
			this.state.lastExtraction = null;
			this.state.analysisPhase = null;
		},

		resolveRunId( result ) {
			if ( result?.run_id !== null && result?.run_id !== undefined ) {
				const runId = String( result.run_id ).trim();
				if ( runId !== '' ) {
					return runId;
				}
			}

			const analyzedAt = result?.analyzed_at;
			if ( typeof analyzedAt === 'string' && analyzedAt.trim() !== '' ) {
				const digits = analyzedAt.replace( /\D/g, '' );
				if ( digits !== '' ) {
					return digits;
				}
			}

			return String( Date.now() );
		},

		buildAnalysisExportRow() {
			const emptyRow = {
				outcome: '',
				error_code: '',
				error_message: '',
				http_status: '',
				run_id: this.state.lastRunId || '',
				analyzed_at: '',
				from_cache: this.state.lastFromCache ? 'true' : 'false',
				item_id: this.state.itemId ?? '',
				attachment_id: this.state.attachmentId ?? '',
				collection_id: this.state.collectionId ?? '',
				document_type: this.state.documentInfo?.type || '',
				extraction_method: '',
				analysis_mode: '',
				provider_used: '',
				provider_name: '',
				model_used: '',
				model_name: '',
				finish_reason: '',
				tokens_used: '',
				prompt_tokens: '',
				completion_tokens: '',
				request_characters: '',
				response_characters: '',
				duration_ms: '',
				processing_warnings: '',
			};

			const applyRequestMeta = ( row, metaSource ) => {
				const meta = this.normalizeRequestDetails( metaSource );
				if ( ! meta ) {
					return row;
				}

				return {
					...row,
					analysis_mode: meta.analysis_mode || row.analysis_mode,
					provider_used: meta.provider_used || row.provider_used,
					provider_name: meta.provider_name || row.provider_name,
					model_used: meta.model_used || row.model_used,
					model_name: meta.model_name || row.model_name,
					finish_reason: meta.finish_reason || row.finish_reason,
					tokens_used:
						meta.tokens_used > 0
							? String( meta.tokens_used )
							: row.tokens_used,
					prompt_tokens:
						meta.prompt_tokens > 0
							? String( meta.prompt_tokens )
							: row.prompt_tokens,
					completion_tokens:
						meta.completion_tokens > 0
							? String( meta.completion_tokens )
							: row.completion_tokens,
					request_characters:
						meta.request_characters > 0
							? String( meta.request_characters )
							: row.request_characters,
					response_characters:
						meta.response_characters > 0
							? String( meta.response_characters )
							: row.response_characters,
					duration_ms:
						meta.duration_ms > 0
							? String( meta.duration_ms )
							: row.duration_ms,
				};
			};

			if ( this.state.lastResult ) {
				const result = this.state.lastResult;
				let row = {
					...emptyRow,
					outcome: 'success',
					run_id: this.state.lastRunId || this.resolveRunId( result ),
					analyzed_at: result.analyzed_at || this.state.lastRunStartedAt || '',
					from_cache: this.state.lastFromCache ? 'true' : 'false',
					document_type: result.document_type || emptyRow.document_type,
					extraction_method: result.extraction_method || '',
					processing_warnings: this.formatProcessingWarningsForExport(
						result.processing?.warnings
					),
				};
				row = applyRequestMeta( row, result );
				return row;
			}

			const parsedError = this.state.lastAnalysisError;
			if ( parsedError ) {
				let row = {
					...emptyRow,
					outcome: 'error',
					error_code: parsedError.code || '',
					error_message: parsedError.message || '',
					http_status:
						parsedError.status !== null &&
						parsedError.status !== undefined
							? String( parsedError.status )
							: '',
					analyzed_at: this.state.lastRunStartedAt || '',
					from_cache: 'false',
					analysis_mode:
						this.state.lastPromptDebug?.analysis_mode || '',
				};
				row = applyRequestMeta( row, parsedError.requestMeta );
				if ( parsedError.processingWarnings ) {
					row.processing_warnings =
						this.formatProcessingWarningsForExport(
							parsedError.processingWarnings
						);
				}
				return row;
			}

			return emptyRow;
		},

		updateExportButtonsState() {
			this.reconcileSidebarElements();

			const runId = this.state.lastRunId;
			const hasRun = Boolean( runId && String( runId ).trim() );
			const hasMetadata = Boolean(
				this.state.lastResult?.ai_metadata &&
					typeof this.state.lastResult.ai_metadata === 'object'
			);

			if ( this.elements.downloadResultsCsv?.length ) {
				this.elements.downloadResultsCsv.prop(
					'disabled',
					! ( hasRun && hasMetadata )
				);
			}

			if ( this.elements.downloadAnalysisCsv?.length ) {
				const canExportAnalysis = Boolean(
					hasRun &&
						( this.state.lastResult || this.state.lastAnalysisError )
				);
				this.elements.downloadAnalysisCsv.prop(
					'disabled',
					! canExportAnalysis
				);
			}

			if ( this.elements.downloadPromptTxt?.length ) {
				const hasPromptSource = Boolean(
					( this.state.lastPromptDebug &&
						! this.state.lastPromptDebug.error ) ||
						( this.state.lastPrompt &&
							String( this.state.lastPrompt ).trim() )
				);
				this.elements.downloadPromptTxt.prop(
					'disabled',
					! ( hasRun && hasPromptSource )
				);
			}
		},

		escapeCsvCell( value ) {
			const text =
				value === null || value === undefined ? '' : String( value );
			if (
				text.includes( '"' ) ||
				text.includes( ',' ) ||
				text.includes( '\n' ) ||
				text.includes( '\r' )
			) {
				return `"${ text.replace( /"/g, '""' ) }"`;
			}
			return text;
		},

		formatExportScalar( value ) {
			if ( value === null || value === undefined ) {
				return '';
			}
			if ( typeof value === 'boolean' ) {
				return value ? 'true' : 'false';
			}
			if ( typeof value === 'object' ) {
				return JSON.stringify( value );
			}
			return String( value );
		},

		formatExportFieldValue( value ) {
			if ( Array.isArray( value ) ) {
				return value
					.map( ( entry ) => this.formatExportScalar( entry ) )
					.filter( ( entry ) => entry !== '' )
					.join( '|' );
			}
			return this.formatExportScalar( value );
		},

		formatExportEvidence( evidence ) {
			if ( evidence === null || evidence === undefined ) {
				return '';
			}
			if ( Array.isArray( evidence ) ) {
				return evidence
					.map( ( entry ) => this.formatExportScalar( entry ) )
					.filter( ( entry ) => entry !== '' )
					.join( '|' );
			}
			return this.formatExportScalar( evidence );
		},

		getExtractionFieldSlugs() {
			const fromConfig = Object.keys( TainacanAI.extractionFields || {} );
			if ( fromConfig.length > 0 ) {
				return fromConfig;
			}

			return Object.keys( this.state.lastResult?.ai_metadata || {} );
		},

		buildResultsCsv() {
			const slugs = this.getExtractionFieldSlugs();
			const metadata = this.state.lastResult?.ai_metadata || {};
			const headers = [];
			const cells = [];

			slugs.forEach( ( slug ) => {
				headers.push( slug, `${ slug }_evidence` );
				const entry = metadata[ slug ];
				if ( entry === undefined || entry === null ) {
					cells.push( '', '' );
					return;
				}
				const { value, evidence } = this.parseFieldEntry( entry );
				cells.push(
					this.formatExportFieldValue( value ),
					this.formatExportEvidence( evidence )
				);
			} );

			return `${ headers.map( ( h ) => this.escapeCsvCell( h ) ).join( ',' ) }\n${ cells.map( ( c ) => this.escapeCsvCell( c ) ).join( ',' ) }`;
		},

		formatProcessingWarningsForExport( warnings ) {
			if ( ! Array.isArray( warnings ) || warnings.length === 0 ) {
				return '';
			}

			return warnings
				.map( ( warning ) => {
					const code =
						typeof warning?.code === 'string' ? warning.code : '';
					const message =
						typeof warning?.message === 'string'
							? warning.message
							: '';
					if ( code && message ) {
						return `${ code }: ${ message }`;
					}
					return code || message;
				} )
				.filter( Boolean )
				.join( '|' );
		},

		buildAnalysisCsv() {
			const columns = [
				'outcome',
				'error_code',
				'error_message',
				'http_status',
				'run_id',
				'analyzed_at',
				'from_cache',
				'item_id',
				'attachment_id',
				'collection_id',
				'document_type',
				'extraction_method',
				'analysis_mode',
				'provider_used',
				'provider_name',
				'model_used',
				'model_name',
				'finish_reason',
				'tokens_used',
				'prompt_tokens',
				'completion_tokens',
				'request_characters',
				'response_characters',
				'duration_ms',
				'processing_warnings',
			];

			const row = this.buildAnalysisExportRow();
			const values = columns.map( ( column ) => row[ column ] ?? '' );

			return `${ columns.map( ( c ) => this.escapeCsvCell( c ) ).join( ',' ) }\n${ values.map( ( v ) => this.escapeCsvCell( v ) ).join( ',' ) }`;
		},

		buildPromptTxt() {
			const sections = [];
			const promptDebug = this.state.lastPromptDebug;
			const runId = this.state.lastRunId || '';

			if ( promptDebug && ! promptDebug.error ) {
				const systemInstruction =
					promptDebug.system_instruction ||
					promptDebug.instruction_prompt ||
					'';
				if ( systemInstruction ) {
					sections.push(
						'=== System instruction ===',
						String( systemInstruction )
					);
				}

				const userPrompt = promptDebug.user_prompt || '';
				if ( userPrompt ) {
					sections.push( '=== User message ===', String( userPrompt ) );
				}

				const documentBody = promptDebug.document_body;
				const docType = String( documentBody?.type || '' );
				const docContent =
					documentBody?.content === null ||
					documentBody?.content === undefined
						? ''
						: String( documentBody.content );

				if ( docType === 'image' || docType === 'pdf_visual' ) {
					const attachmentLines = [
						'=== Attachment ===',
						promptDebug.attachment_note ||
							'[Binary content attached to the API request]',
					];
					if ( docContent.trim() !== '' ) {
						attachmentLines.push( docContent.trim() );
					}
					sections.push( attachmentLines.join( '\n\n' ) );
				} else if ( docContent.trim() !== '' ) {
					const userText = String( userPrompt );
					if ( ! userText.includes( docContent.trim() ) ) {
						sections.push(
							'=== Document ===',
							docContent.trim()
						);
					}
				}
			} else if ( this.state.lastPrompt ) {
				sections.push(
					'=== System instruction ===',
					String( this.state.lastPrompt ),
					'',
					'[Prompt debug payload was not available for this run.]'
				);
				if ( this.elements.promptTextarea?.length ) {
					const override = this.elements.promptTextarea.val();
					if ( override && String( override ).trim() ) {
						sections.push(
							'=== Prompt editor (current) ===',
							String( override )
						);
					}
				}
			}

			const footer = [
				'---',
				`run_id: ${ runId }`,
				`from_cache: ${ this.state.lastFromCache ? 'true' : 'false' }`,
				`analysis_mode: ${
					this.state.lastResult?.analysis_mode ||
					promptDebug?.analysis_mode ||
					this.state.lastAnalysisError?.requestMeta?.analysis_mode ||
					''
				}`,
				`outcome: ${
					this.state.lastResult
						? 'success'
						: this.state.lastAnalysisError
							? 'error'
							: ''
				}`,
			];
			if ( this.state.lastAnalysisError?.code ) {
				footer.push(
					`error_code: ${ this.state.lastAnalysisError.code }`
				);
			}
			sections.push( footer.join( '\n' ) );

			return sections.filter( ( part ) => part !== '' ).join( '\n\n' );
		},

		downloadTextFile( filename, content, mime = 'text/plain;charset=utf-8' ) {
			const bom = mime.startsWith( 'text/csv' ) ? '\uFEFF' : '';
			const blob = new Blob( [ bom + content ], { type: mime } );
			const url = URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = filename;
			link.style.display = 'none';
			document.body.appendChild( link );
			link.click();
			link.remove();
			URL.revokeObjectURL( url );
		},

		downloadResultsCsv() {
			if ( ! this.state.lastResult?.ai_metadata ) {
				this.showToast(
					TainacanAI.texts?.exportNoData ||
						'No analysis data to export.'
				);
				return;
			}

			const runId = this.state.lastRunId || this.resolveRunId( this.state.lastResult );
			this.downloadTextFile(
				`results-${ runId }.csv`,
				this.buildResultsCsv(),
				'text/csv;charset=utf-8'
			);
		},

		downloadAnalysisCsv() {
			if ( ! this.state.lastResult && ! this.state.lastAnalysisError ) {
				this.showToast(
					TainacanAI.texts?.exportNoData ||
						'No analysis data to export.'
				);
				return;
			}

			const runId =
				this.state.lastRunId ||
				( this.state.lastResult
					? this.resolveRunId( this.state.lastResult )
					: String( Date.now() ) );
			this.downloadTextFile(
				`analysis-${ runId }.csv`,
				this.buildAnalysisCsv(),
				'text/csv;charset=utf-8'
			);
		},

		downloadPromptTxt() {
			const content = this.buildPromptTxt();
			if ( ! content.trim() ) {
				this.showToast(
					TainacanAI.texts?.exportNoData ||
						'No analysis data to export.'
				);
				return;
			}

			const runId = this.state.lastRunId || String( Date.now() );
			this.downloadTextFile( `prompt-${ runId }.txt`, content, 'text/plain;charset=utf-8' );
		},

		applyPromptDebugFromPayload( promptDebug ) {
			if ( ! promptDebug || typeof promptDebug !== 'object' ) {
				return;
			}

			if ( promptDebug.error ) {
				return;
			}

			this.state.lastPromptDebug = promptDebug;

			const instructionPrompt =
				this.resolveInstructionPromptFromDebug( promptDebug );
			if ( instructionPrompt ) {
				this.state.lastPrompt = instructionPrompt;
			}

			this.state.lastDocumentBody = promptDebug.document_body || null;
			this.updatePromptDocumentPreviewDisplay( this.state.lastDocumentBody );

			if ( this.elements.promptTextarea?.length && this.state.lastPrompt ) {
				this.elements.promptTextarea.val( this.state.lastPrompt );
			}

			this.updateRequestTabAvailability();
			this.updateExportButtonsState();
		},

		resolveInstructionPromptFromDebug( promptDebug ) {
			if ( ! promptDebug ) {
				return null;
			}

			if ( promptDebug.instruction_prompt ) {
				return String( promptDebug.instruction_prompt );
			}

			const fullPrompt = promptDebug.prompt;
			const attachmentNote = promptDebug.attachment_note;

			if (
				typeof fullPrompt === 'string' &&
				fullPrompt !== '' &&
				typeof attachmentNote === 'string' &&
				attachmentNote !== '' &&
				fullPrompt.endsWith( attachmentNote )
			) {
				return fullPrompt
					.slice( 0, fullPrompt.length - attachmentNote.length )
					.replace( /\n+$/, '' );
			}

			return typeof fullPrompt === 'string' && fullPrompt !== ''
				? fullPrompt
				: null;
		},

		resolveDocumentPreviewContent( documentBody ) {
			if ( ! documentBody || typeof documentBody !== 'object' ) {
				return {
					content: '',
					showPreview: false,
				};
			}

			const type = String( documentBody.type || '' );
			const rawContent =
				documentBody.content === null || documentBody.content === undefined
					? ''
					: String( documentBody.content );
			const trimmed = rawContent.trim();

			if ( type === 'image' ) {
				const lines = [
					TainacanAI.texts?.promptDocumentImage ||
						'Image bytes are attached to the API request.',
					trimmed,
				].filter( Boolean );
				return {
					content: lines.join( '\n\n' ),
					showPreview: true,
				};
			}

			if ( type === 'pdf_visual' ) {
				const lines = [
					TainacanAI.texts?.promptDocumentPdfVisual ||
						'PDF pages are sent as images.',
					trimmed,
				].filter( Boolean );
				return {
					content: lines.join( '\n\n' ),
					showPreview: true,
				};
			}

			if ( trimmed === '' ) {
				return {
					content:
						TainacanAI.texts?.promptDocumentEmpty ||
						'No extractable text was found in this document.',
					showPreview: true,
				};
			}

			return {
				content: rawContent,
				showPreview: true,
			};
		},

		updatePromptDocumentPreviewDisplay( documentBody ) {
			this.ensureElementsCached();
			if ( ! this.elements.promptDocumentPreview?.length ) {
				return;
			}

			const resolved = this.resolveDocumentPreviewContent( documentBody );

			if ( ! resolved.showPreview ) {
				this.elements.promptDocumentPreview.prop( 'hidden', true );
				if ( this.elements.promptDocumentPreviewContent?.length ) {
					this.elements.promptDocumentPreviewContent.text( '' );
				}
				if ( this.elements.promptDocumentPreviewTruncated?.length ) {
					this.elements.promptDocumentPreviewTruncated.prop( 'hidden', true );
				}
				return;
			}

			const baseLabel =
				TainacanAI.texts?.promptDocumentPreview ||
				'Document sent to the model (read-only)';
			const charCount = resolved.content.trim().length;
			const summaryLabel =
				charCount > 0 ? `${ baseLabel } (${ charCount } chars)` : baseLabel;

			if ( this.elements.promptDocumentPreviewSummary?.length ) {
				this.elements.promptDocumentPreviewSummary.text( summaryLabel );
			}
			if ( this.elements.promptDocumentPreviewContent?.length ) {
				this.elements.promptDocumentPreviewContent.text( resolved.content );
			}
			if ( this.elements.promptDocumentPreviewTruncated?.length ) {
				const documentWarnings = Array.isArray( documentBody?.warnings )
					? documentBody.warnings
					: [];
				const truncationWarning = documentWarnings.find(
					( warning ) => warning?.code === 'document_truncated'
				);
				const infoWarnings = documentWarnings.filter(
					( warning ) => warning?.code !== 'document_truncated'
				);
				const isTruncated = Boolean( documentBody?.truncated );

				let noticeText = '';
				if ( truncationWarning?.message ) {
					noticeText = truncationWarning.message;
				} else if ( isTruncated ) {
					const sentLength = Number( documentBody?.sent_length ?? 0 );
					const originalLength = Number(
						documentBody?.original_length ?? 0
					);
					if ( sentLength > 0 && originalLength > sentLength ) {
						noticeText = `${
							TainacanAI.texts?.promptDocumentTruncated ||
							'Only part of the document was sent to the model.'
						} (${ sentLength.toLocaleString() } / ${ originalLength.toLocaleString() } characters).`;
					} else {
						noticeText =
							TainacanAI.texts?.promptDocumentTruncated ||
							'Only part of the document was sent to the model.';
					}
				} else if ( infoWarnings.length > 0 ) {
					noticeText = infoWarnings
						.map( ( warning ) => warning.message )
						.filter( Boolean )
						.join( ' ' );
				}

				if ( noticeText ) {
					this.elements.promptDocumentPreviewTruncated
						.text( noticeText )
						.prop( 'hidden', false );
				} else {
					this.elements.promptDocumentPreviewTruncated.prop(
						'hidden',
						true
					);
				}
			}

			this.elements.promptDocumentPreview.prop( 'hidden', false );
		},

		resetPromptEditor() {
			if ( ! this.elements.promptTextarea?.length ) {
				return;
			}

			if ( this.state.lastPrompt ) {
				this.elements.promptTextarea.val( this.state.lastPrompt );
			} else {
				this.elements.promptTextarea.val( '' );
			}
			this.updatePromptDocumentPreviewDisplay( this.state.lastDocumentBody );
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
