( function () {
	'use strict';

	var config = window.factDeskAdmin || {};
	var text = config.text || {};

	function copy( id, button ) {
		var node = document.getElementById( id );
		if ( ! node ) {
			return;
		}
		var value = node.textContent;
		var original = button.textContent;
		function done( message ) {
			button.textContent = message;
			window.setTimeout( function () {
				button.textContent = original;
			}, 2000 );
		}
		function fallback() {
			var range = document.createRange();
			range.selectNodeContents( node );
			var selection = window.getSelection();
			selection.removeAllRanges();
			selection.addRange( range );
			done( text.copyFailed );
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( value ).then( function () {
				done( text.copied );
			}, fallback );
		} else {
			fallback();
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-factdesk-copy]' );
		if ( button ) {
			copy( button.getAttribute( 'data-factdesk-copy' ), button );
		}
	} );

	var testButton = document.getElementById( 'factdesk-test' );
	var output = document.getElementById( 'factdesk-test-result' );
	if ( ! testButton || ! output ) {
		return;
	}

	function rpc( id, method, params ) {
		return window.fetch( config.endpoint, {
			method: 'POST',
			credentials: 'omit',
			cache: 'no-store',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
				'X-FactDesk-Test': '1'
			},
			body: JSON.stringify( { jsonrpc: '2.0', id: id, method: method, params: params || {} } )
		} ).then( function ( response ) {
			return response.text().then( function ( body ) {
				var data = null;
				try {
					data = JSON.parse( body );
				} catch ( e ) {
					data = null;
				}
				return { status: response.status, data: data };
			} );
		} );
	}

	function row( list, label, ok, detail ) {
		var item = document.createElement( 'li' );
		var mark = document.createElement( 'span' );
		mark.className = ok ? 'is-ok' : 'is-bad';
		mark.textContent = ( ok ? text.ok : text.failed ) + ': ';
		item.appendChild( mark );
		item.appendChild( document.createTextNode( label + ( detail ? ' (' + detail + ')' : '' ) ) );
		list.appendChild( item );
		return item;
	}

	function problem( result ) {
		if ( ! result ) {
			return text.unreachable;
		}
		if ( 401 === result.status || 403 === result.status ) {
			return text.blocked + ' HTTP ' + result.status;
		}
		if ( result.data && result.data.error && result.data.error.message ) {
			return result.data.error.message + ' (HTTP ' + result.status + ')';
		}
		if ( result.data && result.data.message ) {
			return result.data.message + ' (HTTP ' + result.status + ')';
		}
		return 'HTTP ' + result.status;
	}

	testButton.addEventListener( 'click', function () {
		output.textContent = '';
		if ( ! config.enabled ) {
			output.textContent = text.off;
			return;
		}
		testButton.disabled = true;
		var status = document.createElement( 'p' );
		status.textContent = text.testing;
		output.appendChild( status );
		var list = document.createElement( 'ol' );
		output.appendChild( list );
		var passed = 0;

		rpc( 1, 'initialize', { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'factdesk-admin-test', version: '1' } } )
			.then( function ( first ) {
				var ok = 200 === first.status && first.data && first.data.result;
				row( list, text.connect, ok, ok ? text.protocol + ' ' + first.data.result.protocolVersion : problem( first ) );
				if ( ! ok ) {
					throw new Error( 'stop' );
				}
				passed++;
				return rpc( 2, 'tools/list' );
			} )
			.then( function ( second ) {
				var ok = 200 === second.status && second.data && second.data.result && second.data.result.tools;
				row( list, text.tools, ok, ok ? second.data.result.tools.length + ' ' + text.toolCount : problem( second ) );
				if ( ! ok ) {
					throw new Error( 'stop' );
				}
				passed++;
				return rpc( 3, 'tools/call', { name: 'get_site_info', arguments: {} } );
			} )
			.then( function ( third ) {
				var result = third.data && third.data.result;
				var ok = 200 === third.status && result && ! result.isError && result.content && result.content[ 0 ];
				var item = row( list, text.siteInfo, ok, ok ? '' : problem( third ) );
				if ( ok ) {
					passed++;
					var pre = document.createElement( 'pre' );
					pre.textContent = result.content[ 0 ].text.split( '\n' ).slice( 0, 12 ).join( '\n' );
					item.appendChild( pre );
				}
			} )
			.catch( function ( error ) {
				if ( ! error || 'stop' !== error.message ) {
					row( list, text.connect, false, text.unreachable );
				}
			} )
			.then( function () {
				status.textContent = 3 === passed ? text.allGood : '';
				testButton.disabled = false;
			} );
	} );
}() );
