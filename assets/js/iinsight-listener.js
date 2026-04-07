/**
 * iinsight Form Listener
 *
 * Hooks into the iinsight external form and fires a WordPress AJAX request
 * to send notification emails after iinsight's own submission succeeds.
 *
 * Detection methods (in priority order):
 *   1. XHR/Fetch intercept — detects iinsight's own API call success
 *   2. MutationObserver    — watches phase_1/phase_2 visibility changes
 *
 * Uses navigator.sendBeacon() so the notification survives page redirects
 * (iinsight redirects to completion_url after successful submission).
 *
 * iinsightVars is injected by PHP via wp_localize_script():
 *   { ajaxurl, nonce, action, debug }
 */

( function () {
	'use strict';

	// ── Logger — only prints when debug is enabled in plugin settings ────────
	var isDebug = iinsightVars.debug === 'true';
	var log = {
		info:  function( msg ) { if ( isDebug ) console.log(  '[iinsight] ' + msg ); },
		warn:  function( msg ) { if ( isDebug ) console.warn( '[iinsight] ' + msg ); },
		error: function( msg ) { console.error( '[iinsight] ' + msg ); }
	};

	// ── Guards ───────────────────────────────────────────────────────────────
	var notificationSent = false;
	var submitClicked    = false;   // gate: only fire after actual submit click
	var capturedData     = null;

	// ── Read a form field value safely ────────────────────────────────────────
	function getVal( id ) {
		var el = document.getElementById( id );
		return el ? el.value.trim() : '';
	}

	// ── Capture current form values ──────────────────────────────────────────
	function captureFormData() {
		return {
			first_name:    getVal( 'MEDIUM_TEXT_1' ),
			last_name:     getVal( 'MEDIUM_TEXT_2' ),
			email:         getVal( 'EMAIL_ADDRESS_1' ),
			phone:         getVal( 'PHONE_NUMBER_1' ),
			ndis_funding:  getVal( 'DROP_DOWN_2' ),
			plan_type:     getVal( 'DROP_DOWN_1' )
		};
	}

	// ── Build & send the notification request ────────────────────────────────
	// Uses sendBeacon so it survives iinsight's redirect to completion_url.
	function sendNotification( source ) {
		if ( ! submitClicked ) {
			log.info( 'Ignoring "' + source + '" — submit button not clicked yet (likely a conditional field change).' );
			return;
		}

		if ( notificationSent ) {
			log.warn( 'Notification already sent, skipping duplicate (' + source + ').' );
			return;
		}

		var data  = capturedData || captureFormData();
		var email = data.email;

		if ( ! email || email.indexOf( '@' ) === -1 ) {
			log.warn( 'Skipping notification — email field is empty or invalid.' );
			return;
		}

		notificationSent = true;
		log.info( 'Sending notification (' + source + ') for ' + email );

		var fd = new FormData();
		fd.append( 'action',     iinsightVars.action );
		fd.append( 'nonce',      iinsightVars.nonce );
		fd.append( 'first_name',   data.first_name );
		fd.append( 'last_name',    data.last_name );
		fd.append( 'email',        email );
		fd.append( 'phone',        data.phone );
		fd.append( 'ndis_funding', data.ndis_funding );
		fd.append( 'plan_type',    data.plan_type );

		// sendBeacon survives page navigation (redirect to completion_url)
		if ( navigator.sendBeacon ) {
			var sent = navigator.sendBeacon( iinsightVars.ajaxurl, fd );
			log.info( 'sendBeacon dispatched: ' + sent );
		} else {
			// Fallback: fetch with keepalive for older browsers
			fetch( iinsightVars.ajaxurl, {
				method:      'POST',
				credentials: 'same-origin',
				body:        fd,
				keepalive:   true
			} ).catch( function ( err ) {
				log.error( 'Fetch fallback failed.', err );
			} );
		}
	}

	// ── METHOD 1: Intercept XMLHttpRequest ───────────────────────────────────
	// iinsight submits the form via XHR to its api_referral.php endpoint.
	// We patch XMLHttpRequest.prototype to detect when that call succeeds,
	// then fire our notification before iinsight redirects the page.
	var origXHROpen = XMLHttpRequest.prototype.open;
	var origXHRSend = XMLHttpRequest.prototype.send;

	XMLHttpRequest.prototype.open = function ( method, url ) {
		this._iinsightUrl = ( typeof url === 'string' ) ? url : '';
		return origXHROpen.apply( this, arguments );
	};

	XMLHttpRequest.prototype.send = function () {
		var xhr = this;
		if ( xhr._iinsightUrl.indexOf( 'api_referral' ) !== -1 ) {
			xhr.addEventListener( 'load', function () {
				if ( xhr.status >= 200 && xhr.status < 300 ) {
					sendNotification( 'xhr-intercept' );
				}
			} );
		}
		return origXHRSend.apply( this, arguments );
	};

	// ── Also intercept fetch (in case iinsight uses it) ──────────────────────
	if ( window.fetch ) {
		var origFetch = window.fetch;
		window.fetch = function ( input ) {
			var url = ( typeof input === 'string' ) ? input : ( input && input.url ? input.url : '' );
			var promise = origFetch.apply( this, arguments );
			if ( url.indexOf( 'api_referral' ) !== -1 ) {
				promise.then( function ( response ) {
					if ( response.ok ) {
						sendNotification( 'fetch-intercept' );
					}
				} ).catch( function () {} );
			}
			return promise;
		};
	}

	// ── METHOD 2: MutationObserver (fallback) ────────────────────────────────
	// If iinsight shows phase_2 without redirecting, this catches it.
	function attachMutationObserver( phase1, phase2 ) {
		var observer = new MutationObserver( function () {
			var p1Hidden  = phase1.style.display === 'none';
			var p2Visible = phase2.style.display !== '' && phase2.style.display !== 'none';
			if ( p1Hidden && p2Visible ) {
				observer.disconnect();
				sendNotification( 'MutationObserver' );
			}
		} );

		observer.observe( phase1, { attributes: true, attributeFilter: [ 'style' ] } );
		observer.observe( phase2, { attributes: true, attributeFilter: [ 'style' ] } );
	}

	// ── Wait for iinsight to inject its DOM ──────────────────────────────────
	function waitForForm( attempts ) {
		attempts = attempts || 0;

		if ( attempts > 100 ) {
			log.warn( 'iinsight form not found after 30 s — giving up.' );
			return;
		}

		var submitBtn = document.getElementById( 'save_external_form_on_web' );

		if ( ! submitBtn ) {
			setTimeout( function () { waitForForm( attempts + 1 ); }, 300 );
			return;
		}

		log.info( 'Form detected.' );

		// Capture form data on click — before iinsight validates and redirects
		submitBtn.addEventListener( 'click', function () {
			submitClicked = true;
			capturedData  = captureFormData();
			log.info( 'Submit clicked.' );
		} );

		// Attach MutationObserver as fallback
		var phase1 = document.getElementById( 'phase_1' );
		var phase2 = document.getElementById( 'phase_2' );

		if ( phase1 && phase2 ) {
			attachMutationObserver( phase1, phase2 );
		}
	}

	// ── Kick off after DOM is ready ──────────────────────────────────────────
	log.info( 'Listener loaded.' );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { waitForForm( 0 ); } );
	} else {
		waitForForm( 0 );
	}

} )();
