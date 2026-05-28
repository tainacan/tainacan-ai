/**
 * Tainacan AI - Admin JavaScript
 */
( function ( $ ) {
	'use strict';

	const Admin = {
		init() {
			this.bindEvents();
			this.syncCollapsedCardStates();
		},

		bindEvents() {
			$( '#clear-all-cache' ).on(
				'click',
				this.clearAllCache.bind( this )
			);

			$( '.tainacan-ai-toggle-card' ).on( 'click', this.toggleCard );
			$( '.tainacan-ai-use-prompt-template' ).on(
				'click',
				this.applyPromptTemplate.bind( this )
			);
		},

		clearAllCache() {
			const $btn = $( '#clear-all-cache' );
			const originalHtml = $btn.html();

			if (
				! confirm(
					TainacanAIAdmin.texts.confirmClearCache ||
						'Are you sure you want to clear all cache?'
				)
			) {
				return;
			}

			$btn.prop( 'disabled', true ).html(
				'<span class="dashicons dashicons-update spin"></span> ' +
					( TainacanAIAdmin.texts.clearing || 'Clearing cache...' )
			);

			$.ajax( {
				url: TainacanAIAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tainacan_ai_clear_cache',
					nonce: TainacanAIAdmin.nonce,
				},
				success: ( response ) => {
					if ( response.success ) {
						const message =
							typeof response.data === 'string'
								? response.data
								: TainacanAIAdmin.texts.cacheCleared;
						Admin.showNotice( message, 'success' );
					} else {
						Admin.showNotice(
							TainacanAIAdmin.texts.error,
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

		syncCollapsedCardStates() {
			$( '.tainacan-ai-card' ).each( function () {
				const $card = $( this );
				const $body = $card.find( '.tainacan-ai-card-body' );
				const $toggle = $card.find( '.tainacan-ai-toggle-card' );
				if ( ! $body.length || ! $toggle.length ) {
					return;
				}
				if ( $body.hasClass( 'collapsed' ) ) {
					$toggle
						.find( '.dashicons' )
						.removeClass( 'dashicons-arrow-down-alt2' )
						.addClass( 'dashicons-arrow-up-alt2' );
				}
			} );
		},

		toggleCard( e ) {
			const $card = $( e.currentTarget ).closest( '.tainacan-ai-card' );
			const $body = $card.find( '.tainacan-ai-card-body' );
			const $icon = $( e.currentTarget ).find( '.dashicons' );

			$body.toggleClass( 'collapsed' );

			if ( $body.hasClass( 'collapsed' ) ) {
				$icon
					.removeClass( 'dashicons-arrow-down-alt2' )
					.addClass( 'dashicons-arrow-up-alt2' );
			} else {
				$icon
					.removeClass( 'dashicons-arrow-up-alt2' )
					.addClass( 'dashicons-arrow-down-alt2' );
			}
		},

		applyPromptTemplate( e ) {
			e.preventDefault();

			const $btn = $( e.currentTarget );
			const templateKey = $btn.attr( 'data-template-key' );
			const templates = TainacanAIAdmin.promptTemplates || {};
			const template = templates[ templateKey ];

			if ( ! template || ! template.content ) {
				return;
			}

			const $textarea = $( '#default_preamble' );
			if ( ! $textarea.length ) {
				return;
			}

			const currentValue = ( $textarea.val() || '' ).toString().trim();
			const nextValue = template.content.toString();
			if (
				currentValue &&
				currentValue !== nextValue.trim() &&
				! confirm(
					TainacanAIAdmin.texts.confirmReplacePreambleTemplate ||
						'Replace the current preamble with this template?'
				)
			) {
				return;
			}

			$textarea.val( nextValue ).trigger( 'change' ).focus();
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

	const toastStyles = `
        .tainacan-ai-toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--tainacan-ai-white);
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 100001;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.3s ease;
        }
        .tainacan-ai-toast.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .tainacan-ai-toast.success {
            border-left: 4px solid var(--tainacan-ai-success);
        }
        .tainacan-ai-toast.error {
            border-left: 4px solid var(--tainacan-ai-error);
        }
    `;

	if ( ! document.getElementById( 'tainacan-ai-toast-styles' ) ) {
		$( 'head' ).append(
			`<style id="tainacan-ai-toast-styles">${ toastStyles }</style>`
		);
	}

	$( document ).ready( () => Admin.init() );
} )( jQuery );
