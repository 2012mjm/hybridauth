<?php
/*!
* This file is part of the HybridAuth PHP Library (hybridauth.sourceforge.net | github.com/hybridauth/hybridauth)
*
* This branch contains work in progress toward the next HybridAuth 3 release and may be unstable.
*/

namespace Hybridauth\Adapter\Api\OAuth2;

class Tokens implements \Hybridauth\Adapter\Api\TokensInterface
{
	public function __construct()
	{
		$this->accessToken          = null;
		$this->refreshToken         = null;
		$this->accessTokenExpiresIn = null;
		$this->accessTokenExpiresAt = null;
	}
}
