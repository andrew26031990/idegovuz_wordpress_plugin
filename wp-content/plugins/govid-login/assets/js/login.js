/**
 * Reveals the username/password form without reloading the page.
 * Without JavaScript the link falls back to wp-login.php?govid_password=1.
 */
( function () {
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( '.govid-login__toggle' );
		if ( ! link ) {
			return;
		}

		event.preventDefault();
		document.body.classList.remove( 'govid-only' );

		var login = document.getElementById( 'user_login' );
		if ( login ) {
			login.focus();
		}
	} );
} )();
