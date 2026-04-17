document.addEventListener( 'change', function( e ) {
	if ( ! e.target.classList.contains( 'wofc-lock-toggle' ) ) return;
	var row = e.target.closest( 'tr' );
	if ( e.target.checked ) {
		row.classList.add( 'wofc-row-locked' );
	} else {
		row.classList.remove( 'wofc-row-locked' );
	}
});
