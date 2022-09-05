<?php

namespace OpenIDConnectServer;

use OAuth2\Request;
use OAuth2\Server;
use OAuth2\Storage\Memory;
use function openssl_pkey_get_details;
use function openssl_pkey_get_public;

class OpenIDConnectServer {

	private $rest;

	public function __construct() {
		// Please follow the instructions in the readme for defining these.
		$public_key = defined( 'OIDC_PUBLIC_KEY' ) ? OIDC_PUBLIC_KEY : false;
		if ( ! $public_key ) {
			return false;
		}
		$private_key = defined( 'OIDC_PRIVATE_KEY' ) ? OIDC_PRIVATE_KEY : false;
		if ( ! $private_key ) {
			return false;
		}

		$config = array(
			'use_jwt_access_tokens' => true,
			'use_openid_connect'    => true,
			'issuer'                => home_url( '/' ),
		);

		$server = new Server( new OAuth2Storage(), $config );
		$server->addStorage(
			new Memory(
				array(
					'keys' => compact( 'private_key', 'public_key' ),
				)
			),
			'public_key'
		);

		// Add REST endpoints.
		$this->rest = new Rest( $server );

		add_action( 'template_redirect', array( $this, 'jwks' ) );
		add_action( 'template_redirect', array( $this, 'openid_configuration' ) );
		add_action( 'template_redirect', array( $this, 'openid_authenticate' ) );
	}

	public function jwks() {
		if ( empty( $_SERVER['REQUEST_URI'] ) || '/.well-known/jwks.json' !== $_SERVER['REQUEST_URI'] ) {
			return;
		}
		if ( ! defined( 'OIDC_PUBLIC_KEY' ) || empty( OIDC_PUBLIC_KEY ) ) {
			return;
		}
		status_header( 200 );
		header( 'Content-type: application/json' );
		header( 'Access-Control-Allow-Origin: *' );

		$key_info = openssl_pkey_get_details( openssl_pkey_get_public( OIDC_PUBLIC_KEY ) );

		echo wp_json_encode(
			array(
				'keys' =>
					array(
						array(
							'kty' => 'RSA',
							'use' => 'sig',
							'alg' => 'RS256',
							// phpcs:ignore
							'n'   => rtrim( strtr( base64_encode( $key_info['rsa']['n'] ), '+/', '-_' ), '=' ),
							// phpcs:ignore
							'e'   => rtrim( strtr( base64_encode( $key_info['rsa']['e'] ), '+/', '-_' ), '=' ),
						),
					),
			)
		);
		exit;
	}

	public function openid_configuration() {
		if ( empty( $_SERVER['REQUEST_URI'] ) || '/.well-known/openid-configuration' !== $_SERVER['REQUEST_URI'] ) {
			return;
		}
		status_header( 200 );
		header( 'Content-type: application/json' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode(
			array(
				'issuer'                                => home_url( '/' ),
				'authorization_endpoint'                => rest_url( 'openid-connect/authorize' ),
				'token_endpoint'                        => rest_url( 'openid-connect/token' ),
				'userinfo_endpoint'                     => rest_url( 'openid-connect/userinfo' ),
				'jwks_uri'                              => home_url( '/.well-known/jwks.json' ),
				'scopes_supported'                      => array( 'openid', 'profile' ),
				'response_types_supported'              => array( 'code' ),
				'id_token_signing_alg_values_supported' => array( 'RS256' ),
			)
		);
		exit;
	}

	public function openid_authenticate() {
		global $wp;

		if ( 'openid-connect/authenticate' !== $wp->request ) {
			return;
		}

		$request = Request::createFromGlobals();
		if ( empty( $request->query( 'client_id' ) ) || ! OAuth2Storage::getClientName( $request->query( 'client_id' ) ) ) {
			return;

		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		if ( $this->rest->is_consent_needed() ) {
			define( 'OIDC_DISPLAY_AUTHORIZE', true );

			status_header( 200 );
			include __DIR__ . '/Template/Authorize.php';
		} else {
			// rebuild request with all parameters and send to authorize endpoint.
			$url = rest_url( Rest::NAMESPACE . '/authorize' );
			wp_safe_redirect(
				add_query_arg(
					array_merge(
						array( '_wpnonce' => wp_create_nonce( 'wp_rest' ) ),
						$request->getAllQueryParameters()
					),
					$url
				)
			);
		}
		exit;
	}
}