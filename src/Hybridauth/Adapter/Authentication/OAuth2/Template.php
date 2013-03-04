<?php
/*!
* This file is part of the HybridAuth PHP Library (hybridauth.sourceforge.net | github.com/hybridauth/hybridauth)
*
* This branch contains work in progress toward the next HybridAuth 3 release and may be unstable.
*/

namespace Hybridauth\Adapter\Authentication\OAuth2;

use Hybridauth\Exception;
use Hybridauth\Http\Util;
use Hybridauth\Http\Client;
use Hybridauth\Adapter\Authentication\AuthenticationInterface;
use Hybridauth\Adapter\Authentication\AuthenticationTemplate;
use Hybridauth\Adapter\Authentication\OAuth2\Application;
use Hybridauth\Adapter\Authentication\OAuth2\Endpoints;
use Hybridauth\Adapter\Authentication\OAuth2\Tokens;

class Template extends AuthenticationTemplate implements AuthenticationInterface
{
	protected $application = null;
	protected $endpoints   = null;
	protected $tokens      = null;
	protected $httpClient  = null;

	// --------------------------------------------------------------------

	function __construct()
	{
		$this->application = new Application();
		$this->endpoints   = new Endpoints();
		$this->tokens      = new Tokens();
		$this->httpClient  = new Client();
	}

	// --------------------------------------------------------------------

	function initialize()
	{
		// app credentials
		if ( ! $this->getApplicationId() || !$this->getApplicationSecret() ){
			throw new
				Exception(
					"Application credentials are missing",
					Exception::MISSING_APPLICATION_CREDENTIALS
				);
		}

		// http client
		if ( isset( $this->hybridauthConfig["http_client"] ) && $this->hybridauthConfig["http_client"] ){
			$this->httpClient = new $this->hybridauthConfig["http_client"];
		}
		else{
			$curl_options = isset( $this->hybridauthConfig["curl_options"] ) ? $this->hybridauthConfig["curl_options"] : array();

			$this->httpClient = new \Hybridauth\Http\Client( $curl_options );
		}

		// tokens
		$tokens = $this->getStoredTokens( $this->getTokens() );

		if( $tokens ){
			$this->setTokens( $tokens );
		}
	}

	// --------------------------------------------------------------------

	/**
	* begin login step 
	*/
	function loginBegin()
	{
		$parameters = $this->getEndpointAuthorizeUriAdditionalParameters();

		$url = $this->generateAuthorizeUri( $parameters );

		Util::redirect( $url );
	}

	// --------------------------------------------------------------------

	/**
	* finish login step 
	*/
	function loginFinish( $code = null, $parameters = array(), $method = 'POST' )
	{
		if( ! $code ){
			$code  = ( array_key_exists( 'code', $_REQUEST  ) ) ? $_REQUEST['code']  : "";
			$error = ( array_key_exists( 'error', $_REQUEST ) ) ? $_REQUEST['error'] : "";

			if ( $error ){
				throw new
					Exception(
						"Authentication failed! Provider returned an error: $error",
						Exception::AUTHENTIFICATION_FAILED,
						null,
						$this
					);
			}
		}

		$this->requestAccessToken( $code, $parameters, $method );

		// check if authenticated
		if ( ! $this->getTokens()->accessToken ){
			throw new
				Exception(
					"Authentication failed! Provider returned an invalid access token",
					Exception::AUTHENTIFICATION_FAILED,
					null,
					$this
				);
		}

		// store tokens
		$this->storeTokens( $this->getTokens() );
	}

	// --------------------------------------------------------------------

	function generateAuthorizeUri( $parameters = array() )
	{
		$defaults = array(
			"client_id"     => $this->getApplicationId(),
			"redirect_uri"  => $this->endpoints->redirectUri,
			"scope"         => $this->getApplicationScope(),
			"response_type" => "code"
		);

		$parameters = array_merge( $defaults, (array) $parameters );

		return $this->endpoints->authorizeUri . "?" . http_build_query( $parameters );
	}

	// --------------------------------------------------------------------

	/**
	* Exchanges authorization code for an access grant.
	*/
	function requestAccessToken( $code, $parameters = array(), $method = 'POST' )
	{
		$defaults = array(
			"client_id"     => $this->getApplicationId(),
			"client_secret" => $this->getApplicationSecret(),
			"grant_type"    => "authorization_code",
			"redirect_uri"  => $this->endpoints->redirectUri,
			"code"          => $code
		);

		$parameters = array_merge( $defaults, (array) $parameters );

		if( $method == 'POST' ){
			$this->httpClient->post( $this->endpoints->requestTokenUri, $parameters );
		}
		else{
			$this->httpClient->get( $this->endpoints->requestTokenUri, $parameters );
		}

		if( $this->httpClient->getResponseStatus() != 200 ){
			throw new
				Exception(
					"Provider returned and error. HTTP Error (" . $this->httpClient->getResponseStatus() . ")",
					Exception::AUTHENTIFICATION_FAILED,
					null,
					$this
				);
		}

		$response = $this->parseRequestResult( $this->httpClient->getResponseBody() );

		if( isset( $response->access_token  ) ) $this->getTokens()->accessToken          = $response->access_token;
		if( isset( $response->refresh_token ) ) $this->getTokens()->refreshToken         = $response->refresh_token;
		if( isset( $response->expires_in    ) ) $this->getTokens()->accessTokenExpiresIn = $response->expires_in;

		// calculate when the access token expire
		if( isset($response->expires_in) ){
			$this->getTokens()->accessTokenExpiresAt = time() + $response->expires_in;
		}

		return $response;
	}

	// --------------------------------------------------------------------

	function refreshAccessToken( $parameters = array(), $method = 'POST', $force = false )
	{
		// have an access token?
		if( ! $force && ! $this->getTokens()->accessToken ){
			return false;
		}

		// have to refresh?
		if( ! $force && ! ( $this->getTokens()->refreshToken && $this->getTokens()->accessTokenExpiresIn ) ){
			return false;
		}

		// expired?
		if( ! $force && $this->getTokens()->accessTokenExpiresIn > time() ){
			return false;
		}

		$defaults = array(
			"client_id"     => $this->getApplicationId(),
			"client_secret" => $this->getApplicationSecret(),
			"grant_type"    => "refresh_token"
		);

		$parameters = array_merge( $defaults, (array) $parameters );

		if( $method == 'POST' ){
			$this->httpClient->post( $this->endpoints->requestTokenUri, $parameters );
		}
		else{
			$this->httpClient->get( $this->endpoints->requestTokenUri, $parameters );
		}

		$response = $this->parseRequestResult( $this->httpClient->getResponseBody() );

		if( $response === false ){
			return;
		}

		// error?
		if( ! isset( $response->access_token ) || ! $response->access_token ){
			throw new
				Exception(
					"Authentication failed! Provider returned an invalid access/refresh token",
					Exception::AUTHENTIFICATION_FAILED,
					null,
					$this
				);
		}

		// set new access_token
		$this->accessToken = $response->access_token;

		if( isset( $response->refresh_token ) ){
			$this->refreshToken = $response->refresh_token;
		}

		if( isset( $response->expires_in ) && (int) $response->expires_in ){
			$this->accessTokenExpiresIn = $response->expires_in;

			// even given by some idp, we should calculate this
			$this->accessTokenExpiresAt = time() + (int) $response->expires_in;
		}

		// overwrite stored tokens
		$this->storeTokens( $this->getTokens() );
	}

	// --------------------------------------------------------------------

	function getStoredTokens()
	{
		return $this->storage->get( "{$this->providerId}.tokens" );
	}

	// --------------------------------------------------------------------

	function storeTokens( \Hybridauth\Adapter\Authentication\OAuth2\TokensInterface $tokens )
	{
		$this->storage->set( "{$this->providerId}.tokens", $tokens );
	}

	// --------------------------------------------------------------------

	function isAuthorized()
	{
		return $this->getTokens()->accessToken != null;
	}

	// --------------------------------------------------------------------

	function signedRequest( $uri, $method = 'GET', $parameters = array() )
	{
		if ( strrpos($uri, 'http://') !== 0 && strrpos($uri, 'https://') !== 0 ){
			$uri = $this->endpoints->baseUri . $uri;
		}

		$parameters[ 'access_token' ] = $this->getTokens()->accessToken;

		switch( $method ){
			case 'GET'  : $this->httpClient->get ( $uri, $parameters ); break;
			case 'POST' : $this->httpClient->post( $uri, $parameters ); break;
		}

		return $this->httpClient->getResponseBody();
	}
}
