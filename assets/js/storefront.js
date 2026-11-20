/**
 * Preorder, storefront micro-interaction.
 *
 * The reservation stub "validates" with a single punch: once when it first
 * appears, and again when the shopper presses the pre-order button (the moment
 * of commitment). Presentation only, it never touches cart behaviour, which
 * is handled server-side by PreorderService.
 *
 * Vanilla JS, no dependencies. Respects prefers-reduced-motion (the CSS keyframe
 * is suppressed there; we still toggle the class so the dot reaches its rest
 * state).
 */
( function () {
	'use strict';

	function validate( stub ) {
		stub.classList.remove( 'is-validated' );
		void stub.offsetWidth;
		stub.classList.add( 'is-validated' );
	}

	function init() {
		var stub = document.querySelector( '.preorder-stub' );
		if ( ! stub ) {
			return;
		}

		if ( ! stub.classList.contains( 'preorder-stub--hidden' ) ) {
			validate( stub );
		}

		var form = stub.closest( 'form.cart' ) || document.querySelector( 'form.cart' );
		if ( form ) {
			form.addEventListener( 'submit', function () {
				if ( ! stub.classList.contains( 'preorder-stub--hidden' ) ) {
					validate( stub );
				}
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
