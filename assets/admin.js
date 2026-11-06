/**
 * Mail Catcher: tabs on the message screen.
 *
 * Without this file every panel is simply shown one under another.
 */
( function () {
	'use strict';

	document.querySelectorAll( '[data-fmc-tabs]' ).forEach( function ( root ) {
		var tabs = Array.prototype.slice.call( root.querySelectorAll( '[role="tab"]' ) );

		if ( ! tabs.length ) {
			return;
		}

		function select( tab, focus ) {
			tabs.forEach( function ( other ) {
				var selected = other === tab;
				var panel = document.getElementById( other.getAttribute( 'aria-controls' ) );

				other.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
				other.tabIndex = selected ? 0 : -1;

				if ( panel ) {
					panel.hidden = ! selected;
				}
			} );

			if ( focus ) {
				tab.focus();
			}
		}

		tabs.forEach( function ( tab, index ) {
			var panel = document.getElementById( tab.getAttribute( 'aria-controls' ) );

			tab.id = tab.id || 'fmc-tab-' + index;

			if ( panel ) {
				panel.setAttribute( 'aria-labelledby', tab.id );
			}

			tab.addEventListener( 'click', function () {
				select( tab, false );
			} );

			tab.addEventListener( 'keydown', function ( event ) {
				var next = null;

				if ( 'ArrowRight' === event.key ) {
					next = tabs[ ( index + 1 ) % tabs.length ];
				} else if ( 'ArrowLeft' === event.key ) {
					next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
				} else if ( 'Home' === event.key ) {
					next = tabs[ 0 ];
				} else if ( 'End' === event.key ) {
					next = tabs[ tabs.length - 1 ];
				}

				if ( next ) {
					event.preventDefault();
					select( next, true );
				}
			} );
		} );

		root.classList.add( 'is-ready' );
		select( tabs[ 0 ], false );
	} );
}() );
