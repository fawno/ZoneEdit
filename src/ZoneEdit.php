<?php
/*
  Copyright 2019, Fawno (https://github.com/fawno)

  Licensed under The MIT License
  Redistributions of files must retain the above copyright notice.

  @copyright Copyright 2018, Fawno (https://github.com/fawno)
  @license MIT License (http://www.opensource.org/licenses/mit-license.php)
*/
	namespace Fawno\ZoneEdit;

	use DOMDocument;

	if (!function_exists('simplexml_import_html')) {
		function simplexml_import_html ($html) {
			$doc = new DOMDocument();
			@$doc->loadHTML($html);
			$xml = simplexml_import_dom($doc);
			return $xml;
		}
	}

	class ZoneEdit {
		protected $curl = null;
		protected $txt_records = [];

		public function __construct (string $username, string $password) {
			$this->curl = curl_init();
			curl_setopt($this->curl, CURLOPT_COOKIESESSION, true);
			curl_setopt($this->curl, CURLOPT_COOKIEFILE, '');
			curl_setopt($this->curl, CURLOPT_COOKIEJAR, '');
			curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);

			$response = $this->get_url('https://cp.zoneedit.com/login.php');
			if ($response['http_code'] == 200) {
				$xml = simplexml_import_html($response['body']);
				$login_chal = (string) current($xml->xpath('//input[@name="login_chal"]'))->attributes()->value;
				$csrf_token = (string) current($xml->xpath('//input[@id="csrf_token"]'))->attributes()->value;

				$login = [
					'login_chal' => $login_chal,
					'login_hash' => $this->login_hash($username, $password, $login_chal),
					'login_user' => $username,
					'login_pass' => $password,
					'csrf_token' => $csrf_token,
					'login' => '',
				];

				$response = $this->get_url('https://cp.zoneedit.com/home/', $login);
				if ($response['http_code'] != 200) {
				}
			}
		}

		public function __destruct () {
			$this->get_url('https://cp.zoneedit.com/logout.php');
			curl_close($this->curl);
		}

		protected function get_url ($url, $post_fields = null) {
			curl_setopt($this->curl, CURLOPT_POST, is_array($post_fields));
			curl_setopt($this->curl, CURLOPT_POSTFIELDS, $post_fields);
			curl_setopt($this->curl, CURLOPT_HTTPGET, !is_array($post_fields));
			curl_setopt($this->curl, CURLOPT_URL, $url);

			$body = curl_exec($this->curl);
			$curl_info = curl_getinfo($this->curl);
			$curl_info['body'] = $body;
			$curl_info['curl_errno'] = curl_errno($this->curl);
			$curl_info['curl_strerror'] = curl_strerror($curl_info['curl_errno']);
			return $curl_info;
		}

		protected function login_hash (string $login_user, string $login_pass, string $login_chal) {
			return md5($login_user . md5($login_pass) . $login_chal);
		}

		protected function parse_form (string $html): array {
			$xml = simplexml_import_html($html);

			$form = [];
			foreach ($xml->xpath('//input[@name]|//button[@name]') as $input) {
				$name = (string) $input->attributes()->name;
				$value = (string) $input->attributes()->value;

				if (!preg_match('~TXT::\d+::del~', $name)) {
					$form[$name] = $value;
				}
			}

			return $form;
		}

		/*
			ttl < 1 => delete record
		*/
		public function txt_edit (string $domain, string $host = null, string $value = null, int $ttl = 60): array {
			$delete = ($ttl < 1);

			if (empty($this->txt_records)) {
				$response = $this->get_url('https://cp.zoneedit.com/manage/domains/zone/index.php?LOGIN=' . $domain);
				if ($response['http_code'] != 200) {
				}

				$response = $this->get_url('https://cp.zoneedit.com/manage/domains/txt/edit.php');
				if ($response['http_code'] != 200) {
				}

				$this->txt_records = $this->parse_form($response['body']);
			}

			foreach ($this->txt_records as $key_host => $input_value) {
				if (preg_match('~TXT::\d+::host~', $key_host)) {
					$key_txt = preg_replace('~TXT::(\d+)::host~','TXT::$1::txt', $key_host);
					$key_ttl = preg_replace('~TXT::(\d+)::host~','TXT::$1::ttl', $key_host);
					$key_del = preg_replace('~TXT::(\d+)::host~','TXT::$1::del', $key_host);

					if (!$delete and empty($input_value) and !empty($host)) {
						$this->txt_records[$key_host] = $host;
						$this->txt_records[$key_txt] = $value;
						$this->txt_records[$key_ttl] = $ttl;
						return array_diff_key($this->txt_records, array_fill_keys(['MODE', 'csrf_token', 'next', 'multipleTabFix'], null));
					} elseif ($delete and !empty($input_value)) {
						if (!empty($host) and $this->txt_records[$key_host] != $host) continue;
						if (!empty($value) and $this->txt_records[$key_txt] != $value) continue;
						$this->txt_records[$key_del] = 1;
					}
				}
			}

			return array_diff_key($this->txt_records, array_fill_keys(['MODE', 'csrf_token', 'next', 'multipleTabFix'], null));
		}

		public function txt_save () {
			if (!empty($this->txt_records)) {
				$response = $this->get_url('https://cp.zoneedit.com/manage/domains/txt/edit.php', $this->txt_records);
				if ($response['http_code'] != 200) {
				}

				$data = $this->parse_form($response['body']);
				$response = $this->get_url('https://cp.zoneedit.com/manage/domains/txt/confirm.php', $data);
				if ($response['http_code'] != 200) {
					return false;
				}
				$this->txt_records = [];
				return true;
			}
		}
	}
