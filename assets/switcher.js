/**
 * Language switcher dropdown: disclosure button + list of links.
 * Keyboard: Enter/Space opens, ArrowDown/ArrowUp/Home/End move, Escape closes and
 * returns focus to the button, Tab leaves. Touch: tap toggles, tap outside closes.
 * No library.
 */
( function () {
	'use strict';

	function setup( root ) {
		const button = root.querySelector( '.ept-switcher__toggle' );
		const list = root.querySelector( '.ept-switcher__list' );
		if ( ! button || ! list ) {
			return;
		}
		const links = () => Array.prototype.slice.call( list.querySelectorAll( 'a' ) );

		function open( focusIndex ) {
			list.hidden = false;
			button.setAttribute( 'aria-expanded', 'true' );
			if ( typeof focusIndex === 'number' ) {
				const items = links();
				const target = items[ ( focusIndex + items.length ) % items.length ];
				if ( target ) {
					target.focus();
				}
			}
		}

		function close( returnFocus ) {
			list.hidden = true;
			button.setAttribute( 'aria-expanded', 'false' );
			if ( returnFocus ) {
				button.focus();
			}
		}

		button.addEventListener( 'click', function () {
			if ( list.hidden ) {
				open();
			} else {
				close( false );
			}
		} );

		button.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'ArrowDown' ) {
				event.preventDefault();
				open( 0 );
			} else if ( event.key === 'ArrowUp' ) {
				event.preventDefault();
				open( -1 );
			} else if ( event.key === 'Escape' ) {
				close( true );
			}
		} );

		list.addEventListener( 'keydown', function ( event ) {
			const items = links();
			const index = items.indexOf( document.activeElement );
			if ( event.key === 'ArrowDown' ) {
				event.preventDefault();
				items[ ( index + 1 ) % items.length ].focus();
			} else if ( event.key === 'ArrowUp' ) {
				event.preventDefault();
				items[ ( index - 1 + items.length ) % items.length ].focus();
			} else if ( event.key === 'Home' ) {
				event.preventDefault();
				items[ 0 ].focus();
			} else if ( event.key === 'End' ) {
				event.preventDefault();
				items[ items.length - 1 ].focus();
			} else if ( event.key === 'Escape' ) {
				event.preventDefault();
				close( true );
			}
		} );

		root.addEventListener( 'focusout', function ( event ) {
			if ( ! root.contains( event.relatedTarget ) ) {
				close( false );
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! root.contains( event.target ) ) {
				close( false );
			}
		} );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-ept-switcher]' ), setup );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
