<?php

namespace EllisLab\ExpressionEngine\Service\Encrypt;

/**
 * ExpressionEngine - by EllisLab
 *
 * @package		ExpressionEngine
 * @author		EllisLab Dev Team
 * @copyright	Copyright (c) 2003 - 2017, EllisLab, Inc.
 * @license		https://expressionengine.com/license
 * @link		https://ellislab.com
 * @since		Version 3.5.5
 * @filesource
 */

// ------------------------------------------------------------------------

/**
 * ExpressionEngine Encrypt Class
 *
 * @package		ExpressionEngine
 * @category	Service
 * @author		EllisLab Dev Team
 * @link		https://ellislab.com
 */
class Cookie {

	/**
	 * Given raw cookie data appended with a signature, returns the verified,
	 * decoded data
	 *
	 * @param string $cookie Raw cookie data with signature
	 * @return mixed Result of json_decoding the data, or NULL if signature or
	 *   data invalid
	 */
	public function getVerifiedCookieData($cookie)
	{
		$hash = $this->getCookieHash();

		if (strlen($cookie) <= strlen($hash)) return NULL;

		$signature = substr($cookie, -strlen($hash));
		$payload = substr($cookie, 0, -strlen($hash));

		if (hash_equals($this->generateHashForCookieData($payload), $signature))
		{
			return json_decode(stripslashes($payload), TRUE);
		}

		return NULL;
	}

	/**
	 * Create encoded, signed cookie data
	 *
	 * @param mixed $data Data to be stored in a cookie
	 * @return string json_encoded data with signature of data appended
	 */
	public function signCookieData($data)
	{
		// JSON_UNESCAPED_SLASHES not available until PHP 5.4; but we need to
		// do this because our flashdata often has markup in it and json_encode
		// will break closing tags by escaping their forward slashes
		$payload = str_replace("\\/","/", json_encode($data));

		return $payload.$this->generateHashForCookieData($payload);
	}

	/**
	 * Hash to sign cookie data with
	 *
	 * @return string Hash
	 */
	protected function getCookieHash()
	{
		// TODO: This hash seed will need to change
		return hash('sha384', ee('Model')->get('Site')
			->first()
			->getRawProperty('site_system_preferences')
		);
	}

	/**
	 * Creates signature of cookie data
	 *
	 * @return string Signature
	 */
	protected function generateHashForCookieData($data)
	{
		return ee('Encrypt')->sign(
			$data,
			$this->getCookieHash(),
			'sha384'
		);
	}
}

// EOF
