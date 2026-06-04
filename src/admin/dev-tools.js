/* global njDevTools */
( function () {
	const restUrl = njDevTools.restUrl;
	const nonce = njDevTools.nonce;

	function post( endpoint, body ) {
		return fetch( restUrl + endpoint, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( body ),
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function get( endpoint ) {
		return fetch( restUrl + endpoint, {
			headers: { 'X-WP-Nonce': nonce },
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function showNotice( msg, type ) {
		const el = document.getElementById( 'stilus-dev-notice' );
		el.className = 'notice notice-' + type + ' inline';
		el.textContent = msg;
		el.style.display = '';
	}

	function refreshState() {
		get( 'status' ).then( function ( data ) {
			if ( ! data.tier ) {
				return;
			}
			const strong = document.createElement( 'strong' );
			strong.textContent = data.tier_label;
			document
				.getElementById( 'stilus-dev-tier-label' )
				.replaceChildren( strong );
			document.getElementById( 'stilus-dev-usage' ).textContent =
				data.usage_display;
			document.getElementById( 'stilus-dev-can-use' ).textContent =
				data.can_use ? '✓ Yes' : '✗ No (limit reached)';
			document.getElementById( 'stilus-tier-select' ).value = data.tier;
		} );
	}

	document
		.getElementById( 'stilus-apply-tier' )
		.addEventListener( 'click', function () {
			const tier = document.getElementById( 'stilus-tier-select' ).value;
			post( 'set-tier', { tier } ).then( function ( data ) {
				showNotice(
					data.message || ( data.success ? 'Done.' : 'Error.' ),
					data.success ? 'success' : 'error'
				);
				if ( data.success ) {
					refreshState();
				}
			} );
		} );

	document
		.getElementById( 'stilus-reset-usage' )
		.addEventListener( 'click', function () {
			post( 'reset-usage', {} ).then( function ( data ) {
				showNotice(
					data.message || ( data.success ? 'Done.' : 'Error.' ),
					data.success ? 'success' : 'error'
				);
				if ( data.success ) {
					refreshState();
				}
			} );
		} );

	document
		.getElementById( 'stilus-set-ceiling' )
		.addEventListener( 'click', function () {
			post( 'set-ceiling', {} ).then( function ( data ) {
				showNotice(
					data.message || ( data.success ? 'Done.' : 'Error.' ),
					data.success ? 'success' : 'error'
				);
				if ( data.success ) {
					refreshState();
				}
			} );
		} );
} )();
