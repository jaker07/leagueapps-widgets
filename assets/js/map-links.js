/**
 * Location links open the device's own maps app where there is one.
 *
 * - iPhone, iPad, Mac: Apple Maps (maps.apple.com links open the Maps app).
 * - Android: a geo: link, which opens the default maps app (or asks).
 * - Everything else: the link's own href, a Google Maps search, in a new tab.
 *
 * Uses one delegated click handler, so links added later (e.g. in page
 * builder previews) work too. Modified clicks (new tab, etc.) are left alone.
 */
( function () {
	var ua = navigator.userAgent || '';
	var isApple = /iPhone|iPad|iPod|Macintosh/.test( ua ) || ( navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1 );
	var isAndroid = /Android/.test( ua );

	if ( ! isApple && ! isAndroid ) {
		return;
	}

	function mapUrl( data ) {
		var hasCoords = typeof data.lat === 'number' && typeof data.lng === 'number';
		if ( isApple ) {
			var url = 'https://maps.apple.com/?q=' + encodeURIComponent( data.q );
			if ( hasCoords ) {
				url += '&ll=' + data.lat + ',' + data.lng;
			}
			return url;
		}
		if ( hasCoords && data.q === data.lat + ',' + data.lng ) {
			// Coordinates only: drop a labelled pin.
			return 'geo:' + data.lat + ',' + data.lng + '?q=' + data.lat + ',' + data.lng + '(' + encodeURIComponent( data.name || '' ) + ')';
		}
		return 'geo:0,0?q=' + encodeURIComponent( data.q );
	}

	document.addEventListener( 'click', function ( event ) {
		if ( event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}
		var link = event.target.closest ? event.target.closest( 'a.law-map-link[data-law-map]' ) : null;
		if ( ! link ) {
			return;
		}
		var data;
		try {
			data = JSON.parse( link.getAttribute( 'data-law-map' ) );
		} catch ( e ) {
			return;
		}
		if ( ! data || ! data.q ) {
			return;
		}
		event.preventDefault();
		window.location.href = mapUrl( data );
	} );
}() );
