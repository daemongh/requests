<?php

class requests
{
	public $config = null;

	public $defaultConfig = array(
		'allow_redirects'   => true,
		'auth'              => array(),
		'base_headers'      => '',
		'base_url'          => '',
		'cookies'           => false,
		'max_redirects'     => 30,
		'timeout'           => 0,
		'verify'            => false,
	);

	public function  __construct($url = null, $config = null)
	{
		if (!$config || !is_array($config)) {
			$this->config = $this->defaultConfig;
		} else {
			$this->config = array_merge($this->defaultConfig, $config);
		}

		if ($url) {
			$this->config['base_url'] = $url;
		}
	}

	public function get($url, $config = null)
	{
		$this->configure($config);
		return $this->request($url);
	}

	public function delete($url, $config = null)
	{
		$this->configure($config);
		return $this->request($url, 'DELETE');
	}

	public function post($url, $data = null, $config = null)
	{
		$this->configure($config);
		return $this->request($url, 'POST', $data);
	}

	public function put($url, $data = null, $config = null)
	{
		$this->configure($config);
		return $this->request($url, 'PUT', $data);
	}


	private function request($url, $method = 'GET', $data = null)
	{
		$curl = curl_init();

		$url = $this->config['base_url'] . $url;

		curl_setopt($curl, CURLOPT_URL,             $url);
		curl_setopt($curl, CURLOPT_HEADER,          true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER,  true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST,  $this->config['verify']);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER,  $this->config['verify']);

		if ($this->config['cookies']) {
			curl_setopt($curl, CURLOPT_COOKIEFILE,  $this->config['cookies']);
			curl_setopt($curl, CURLOPT_COOKIEJAR,   $this->config['cookies']);
		}

		if ($this->config['allow_redirects']) {
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION,  true);
			curl_setopt($curl, CURLOPT_MAXREDIRS,   $this->config['max_redirects']);
		}

		if ($this->config['timeout']) {
			curl_setopt($curl, CURLOPT_TIMEOUT,     $this->config['timeout']);
		}

		if ($this->config['auth']) {
			curl_setopt($curl, CURLOPT_HTTPAUTH,    CURLAUTH_BASIC) ;
			curl_setopt($curl, CURLOPT_USERPWD,     implode($this->config['auth'], ':'));
		}

		if ($data) {
			if (is_array($data)) {
				$data = http_build_query($data);
			}
			curl_setopt($curl, CURLOPT_POSTFIELDS,  $data);
		}

		if ($method === 'PUT' || $method === 'DELETE') {
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST,   $method);
			if ($method === 'PUT') {
				$length = 0;
				if ($data) {
					$length = strlen($data);
				}
				curl_setopt($curl, CURLOPT_HTTPHEADER,  array('Content-Length: ' . $length));
			}
		}

		if ($method === 'POST') {
			curl_setopt($curl, CURLOPT_POST,        true);
		}

		$raw = curl_exec($curl);
		$info = curl_getinfo($curl);
		$error = curl_error($curl);

		curl_close($curl);

		$response = new response($this, $raw, $info, $error);
		return $response;
	}

	private function configure($config)
	{
		if ($config && is_array($config)) {
			$this->config = array_merge($this->defaultConfig, $config);
		}
	}
}

class response
{
	public $content = null;
	public $content_type = null;
	public $cookies = null;
	public $encoding = null;
	public $error = null;
	public $headers = null;
	public $raw = null;
	public $request = null;
	public $status_code = null;
	public $url = null;

	public $info = null;

	public function __construct($request, $raw, $info, $error)
	{
		$this->request = $request;
		$this->raw = $raw;
		$this->info = $info;
		$this->error = $error;

		$this->status_code = $info['http_code'];
		$this->url = $info['url'];

		$this->content = substr($this->raw, $this->info['header_size']);

		$this->process_headers();
		$this->process_cookies();
	}

	private function process_headers()
	{
		$header_length = $this->info['header_size'];

		$headers = substr($this->raw, 0, $header_length);
		$headers = str_replace("\r", "", $headers);
		$headers = substr($headers, strrpos($headers, "\n\n", -3));
		$headers = trim($headers);

		$headers = explode("\n", $headers);
		array_shift($headers);

		foreach ($headers as $header) {
			$header_array = explode(':', $header, 2);
			if (isset($header_array[0]) && isset($header_array[1])) {
				$header_array[0] = trim($header_array[0]);
				$header_array[1] = trim($header_array[1]);
				if (isset($this->headers[$header_array[0]])) {
					if (is_array($this->headers[$header_array[0]])) {
						$this->headers[$header_array[0]][] = $header_array[1];
					} else {
						$this->headers[$header_array[0]] = array(
							$this->headers[$header_array[0]],
							$header_array[1],
						);
					}
				} else {
					if (strtolower($header_array[0]) == 'content-type') {
						$content_type_array = explode(';', $header_array[1]);
						if (isset($content_type_array[0])) {
							$this->content_type = $content_type_array[0];
						}
						if (isset($content_type_array[1])) {
							$this->encoding = str_ireplace('charset=', '', $content_type_array[1]);
						}
					}
					$this->headers[$header_array[0]] = $header_array[1];
				}
			}
		}
	}

	public function header($label)
	{
		if (!$this->headers) {
			return null;
		}

		foreach ($this->headers as $header_label => $header) {
			if (strtolower($header_label) == strtolower($label)) {
				return $header;
			}
		}

		return null;
	}

	private function process_cookies()
	{
		$header_cookies = $this->header('set-cookie');

		if (is_array($header_cookies)) {
			$cookies = $header_cookies;
		} else {
			$cookies = array($header_cookies);
		}

		foreach ($cookies as $cookie) {
			$cookie_array = explode(';', $cookie);
			$cookie_data = array();
			foreach ($cookie_array as $cookie_element) {
				$cookie_info = explode('=', $cookie_element, 2);
				if (count($cookie_info) == 2) {
					$cookie_info[0] = trim($cookie_info[0]);
					$cookie_info[1] = trim($cookie_info[1]);
					if (in_array(strtolower($cookie_info[0]), array('domain', 'expires', 'path', 'secure', 'comment'))) {
						$cookie_data[$cookie_info[0]] = $cookie_info[1];
					} else {
						$cookie_data['name'] = $cookie_info[0];
						$cookie_data['value'] = $cookie_info[1];
					}
				}
			}
			if ($cookie_data) {
				$this->cookies[] = $cookie_data;
			}
		}

		if ($this->headers) {
			foreach ($this->headers as $header_label => $header) {
				if (strtolower($header_label) == 'set-cookie') {
					unset($this->headers[$header_label]);
				}
			}
		}
	}

	public function cookie($label)
	{
		if (!$this->cookies) {
			return null;
		}

		foreach ($this->cookies as $cookie) {
			if (strtolower($cookie['name']) == strtolower($label)) {
				return $cookie;
			}
		}

		return null;
	}
}