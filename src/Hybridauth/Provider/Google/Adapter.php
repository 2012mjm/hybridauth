<?php
/*!
* This file is part of the HybridAuth PHP Library (hybridauth.sourceforge.net | github.com/hybridauth/hybridauth)
*
* This branch contains work in progress toward the next HybridAuth 3 release and may be unstable.
*/

namespace Hybridauth\Provider\Google;

/**
* Google adapter
* 
* http://hybridauth.sourceforge.net/userguide/IDProvider_info_Google.html
*/
class Adapter extends \Hybridauth\Adapter\Template\OAuth2
{
	// default permissions 
	public $scope = "https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email https://www.google.com/m8/feeds/";

	// --------------------------------------------------------------------

	/**
	* IDp wrappers initializer 
	*/
	function initialize() 
	{
		parent::initialize();

		// Provider api end-points
		$this->api->endpoints->authorizeUri    = "https://accounts.google.com/o/oauth2/auth";
		$this->api->endpoints->requestTokenUri = "https://accounts.google.com/o/oauth2/token";
		$this->api->endpoints->tokenInfoUri    = "https://www.googleapis.com/oauth2/v1/tokeninfo";
	}

	// --------------------------------------------------------------------

	/**
	* begin login step 
	*/
	function loginBegin()
	{
		$parameters = array( "scope" => $this->scope, "access_type" => "offline" );
		$optionals  = array( "scope", "access_type", "redirect_uri", "approval_prompt" );

		foreach ($optionals as $parameter){
			if( isset( $this->config[$parameter] ) && ! empty( $this->config[$parameter] ) ){
				$parameters[$parameter] = $this->config[$parameter];
			}
		}

		$url = $this->api->generateAuthorizeUri( $parameters );

		\Hybridauth\Http\Util::redirect( $url ); 
	}

	// --------------------------------------------------------------------

	/**
	* Get user profile 
	*/
	function getUserProfile()
	{
		// refresh tokens if needed 
		$this->refreshToken();

		// ask google api for user infos
		$response = $this->api->api( "https://www.googleapis.com/oauth2/v1/userinfo" );

		if ( ! isset( $response->id ) || isset( $response->error ) ){
			throw new
				\Hybridauth\Exception(
					"User profile request failed! {$this->providerId} returned an invalid response.", 
					\Hybridauth\Exception::USER_PROFILE_REQUEST_FAILED, 
					null,
					$this
				);
		}

		$profile = new \Hybridauth\User\Profile();

		$profile->provider    = $this->providerId;
		$profile->identifier  = ( property_exists( $response, 'id'          ) ) ? $response->id          : "";
		$profile->firstName   = ( property_exists( $response, 'given_name'  ) ) ? $response->given_name  : "";
		$profile->lastName    = ( property_exists( $response, 'family_name' ) ) ? $response->family_name : "";
		$profile->displayName = ( property_exists( $response, 'name'        ) ) ? $response->name        : "";
		$profile->photoURL    = ( property_exists( $response, 'picture'     ) ) ? $response->picture     : "";
		$profile->profileURL  = ( property_exists( $response, 'link'        ) ) ? $response->link        : "";
		$profile->gender      = ( property_exists( $response, 'gender'      ) ) ? $response->gender      : ""; 
		$profile->email       = ( property_exists( $response, 'email'       ) ) ? $response->email       : "";
		$profile->language    = ( property_exists( $response, 'locale'      ) ) ? $response->locale      : "";

		if( property_exists( $response,'birthday' ) ){ 
			list($birthday_year, $birthday_month, $birthday_day) = explode( '-', $response->birthday );

			$profile->birthDay   = (int) $birthday_day;
			$profile->birthMonth = (int) $birthday_month;
			$profile->birthYear  = (int) $birthday_year;
		}

		if( property_exists( $response, 'verified_email' ) && $response->verified_email ){ 
			$profile->emailVerified = $profile->email ;
		}

		return $profile;
	}

	// --------------------------------------------------------------------

	/**
	* Get gmail contacts 
	*/
	function getUserContacts()
	{
		// refresh tokens if needed 
		$this->refreshToken();  

		if( ! isset( $this->config['contacts_param'] ) ){
			$this->config['contacts_param'] = array( "max-results" => 500 );
		}

		$response = $this->api->api(
				"https://www.google.com/m8/feeds/contacts/default/full?" . 
					http_build_query( array_merge( array( 'alt' => 'json' ), 
					$this->config['contacts_param'] ) )
			);

		if( ! $response ){
			return array();
		}
 
		$contacts = array(); 

		foreach( $response->feed->entry as $idx => $entry ){
			$uc = new \Hybridauth\User\Contact();

			$uc->email        = isset($entry->{'gd$email'}[0]->address) ? (string) $entry->{'gd$email'}[0]->address : ''; 
			$uc->displayName  = isset($entry->title->{'$t'}) ? (string) $entry->title->{'$t'} : '';  
			$uc->identifier   = $uc->email;

			$contacts[] = $uc;
		}  

		return $contacts;
 	}
}
