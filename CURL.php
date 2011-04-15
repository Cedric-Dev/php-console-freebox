<?php

/* geany_encoding=ISO-8859-15 */

class CURL {
	protected $user_agent = 'Mozilla/5.0 (X11; U; Linux i686; fr; rv:1.9.2.16) Gecko/20110323 Ubuntu/10.10 (maverick) Firefox/3.6.16';

	function doRequest($method, $url, $vars) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
		if(ini_get('safe_mode')!=true) curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

		# curl_setopt($ch, CURLOPT_VERBOSE, true); verbose mode

		if(ini_get('safe_mode')!=true) curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookie.txt');
		if(ini_get('safe_mode')!=true) curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookie.txt');

		if ($method == 'POST') {
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $vars);
		}
		$data = curl_exec($ch);
		curl_close($ch);
		if ($data) {
			return $data;
		} else {
			return curl_error($ch);
		}
	}

	public function get($url) {
		return $this->doRequest('GET', $url, 'NULL');
	}
	
	public function post($url, $vars) {
		return $this->doRequest('POST', $url, $vars);
	}
}

?>
