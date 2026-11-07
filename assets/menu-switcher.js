/**
 * Language switcher in a UAE menu.
 * - Spacing: the flags get the same horizontal padding as the other entries of the menu
 *   (read from them, set as --ept-menu-pad), so they are as far apart as the others.
 * - Last item as button:
 * Language switcher in a UAE menu whose last item is a button (Navigation Menu ->
 * "Last menu item: Button", data-last-item="cta"): UAE gives the button look to whatever
 * entry is last. When the languages are the last entry, the button look goes to the entry
 * just before them (e.g. "Contatti"), with UAE's own classes, so its button settings
 * (colours, hover, typography, padding) keep applying; the flags stay plain.
 * Follows UAE: no button look while the menu is collapsed (hamburger: nav.hfe-dropdown).
 */
( function () {
	'use strict';

	const WRAPPER = [ 'hfe-button-wrapper', 'elementor-widget-button' ];

	function link( li ) {
		return li.querySelector( ':scope > a.hfe-menu-item, :scope > .hfe-has-submenu-container > a.hfe-menu-item' );
	}

	function setButton( li, on ) {
		const a = li && link( li );
		if ( ! a ) {
			return;
		}
		const holder = a.parentElement;
		WRAPPER.forEach( ( c ) => {
			if ( holder.classList.contains( c ) !== on ) {
				holder.classList.toggle( c, on );
			}
		} );
		if ( a.classList.contains( 'elementor-button' ) !== on ) {
			a.classList.toggle( 'elementor-button', on );
		}
	}

	function sync( menu ) {
		const ul = menu.querySelector( 'ul.hfe-nav-menu' );
		if ( ! ul ) {
			return;
		}
		const items = Array.prototype.filter.call( ul.children, ( el ) => el.tagName === 'LI' );
		const last = items[ items.length - 1 ];
		if ( ! last || ! last.classList.contains( 'ept-menu-lang' ) ) {
			return;
		}
		// The languages never look like a button.
		items.filter( ( li ) => li.classList.contains( 'ept-menu-lang' ) ).forEach( ( li ) => setButton( li, false ) );
		// The entry before the languages does, unless the menu is collapsed.
		let target = null;
		for ( let i = items.length - 1; i >= 0; i-- ) {
			if ( ! items[ i ].classList.contains( 'ept-menu-lang' ) ) {
				target = items[ i ];
				break;
			}
		}
		const nav = ul.closest( 'nav' );
		setButton( target, ! ( nav && nav.classList.contains( 'hfe-dropdown' ) ) );
	}

	// Horizontal padding of a normal entry of the menu (not a language, not the button).
	function pad( menu ) {
		const ul = menu.querySelector( 'ul.hfe-nav-menu' );
		if ( ! ul ) {
			return;
		}
		const normal = Array.prototype.find.call( ul.children, ( li ) => li.tagName === 'LI' && ! li.classList.contains( 'ept-menu-lang' ) && link( li ) && ! link( li ).classList.contains( 'elementor-button' ) );
		if ( ! normal ) {
			return;
		}
		const value = window.getComputedStyle( link( normal ) ).paddingLeft;
		if ( ul.style.getPropertyValue( '--ept-menu-pad' ) !== value ) {
			ul.style.setProperty( '--ept-menu-pad', value );
			ul.style.setProperty( '--ept-menu-pad-mobile', value );
		}
	}

	function init() {
		document.querySelectorAll( 'div.hfe-nav-menu' ).forEach( ( menu ) => {
			if ( menu.dataset.eptLang || ! menu.querySelector( 'li.ept-menu-lang' ) ) {
				return;
			}
			menu.dataset.eptLang = '1';
			const cta = menu.getAttribute( 'data-last-item' ) === 'cta';
			let queued = false;
			const run = () => {
				if ( ! queued ) {
					queued = true;
					window.setTimeout( () => {
						queued = false;
						if ( cta ) {
							sync( menu );
						}
						pad( menu );
					}, 0 );
				}
			};
			run();
			// UAE adds / removes its classes on load and on resize: follow it.
			new MutationObserver( run ).observe( menu, { attributes: true, attributeFilter: [ 'class' ], subtree: true } );
			window.addEventListener( 'resize', run );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
	window.addEventListener( 'load', init, { once: true } );
}() );
