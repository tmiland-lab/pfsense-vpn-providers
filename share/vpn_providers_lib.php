<?php
/*
 * vpn_providers_lib.php - core library for the pfSense VPN Providers manager.
 *
 * Parses provider .ovpn configs, plans a complete OpenVPN client setup
 * (CA import, client, interface assignment, gateways) and applies it to
 * config.xml using the same calls the pfSense GUI uses.
 *
 * SAFETY MODEL:
 *  - Clients are ALWAYS created disabled (no tunnel, no routes).
 *  - apply_live=no (default) turns every write into a printed plan.
 *  - Only clients registered in /var/db/pfsense_vpn_providers/registry.json
 *    (description prefix "Provider: ") are ever touched by remove.
 */

/* pfSense internals - guarded so the library can be unit-tested off-box
   with the harness stubs (they always exist on pfSense itself). */
foreach (array('/etc/inc/config.inc', '/etc/inc/functions.inc', '/etc/inc/certs.inc', '/etc/inc/openvpn.inc') as $_inc) {
	if (file_exists($_inc)) {
		require_once($_inc);
	}
}
if (!function_exists('openvpn_resync')) {
	function openvpn_resync($mode, $item) { vpp_log('stub openvpn_resync called'); }
}
if (!function_exists('openvpn_kill_client')) {
	function openvpn_kill_client($port, $remipp, $client_id) { return true; }
}
if (!function_exists('openvpn_configure')) {
	function openvpn_configure($restart = false) { return true; }
}
if (!function_exists('services_unbound_configure')) {
	function services_unbound_configure($restart = true) { return true; }
}
if (!function_exists('filter_configure')) {
	function filter_configure() { return true; }
}

define('VPP_BASE', '/usr/local/pfsense_vpn_providers');
define('VPP_STATE', getenv('VPP_STATE') ?: '/var/db/pfsense_vpn_providers');
define('VPP_REGISTRY', VPP_STATE . '/registry.json');
define('VPP_MARKER', 'Provider: ');

function vpp_log($msg) {
	$line = date('Y-m-d H:i:s') . ' ' . $msg;
	if (PHP_SAPI === 'cli') {
		echo $line . PHP_EOL;
	} else {
		syslog(LOG_WARNING, 'vpn_providers: ' . $msg);
	}
}

function vpp_apply_live() {
	$v = config_get_path('installedpackages/vpn_providers/settings/apply_live', 'no');
	return ($v === 'yes');
}

function vpp_registry() {
	if (file_exists(VPP_REGISTRY)) {
		$j = json_decode(file_get_contents(VPP_REGISTRY), true);
		if (is_array($j)) {
			return $j;
		}
	}
	return array();
}

function vpp_registry_save($reg) {
	if (!is_dir(VPP_STATE)) {
		mkdir(VPP_STATE, 0755, true);
	}
	file_put_contents(VPP_REGISTRY, json_encode($reg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	chmod(VPP_REGISTRY, 0600);
}

/* ------------------------------------------------------------------
 * AirVPN API: server list for the quick-add picker
 * ------------------------------------------------------------------ */

define('VPP_AIRVPN_STATUS_URL', 'https://airvpn.org/api/status/');

/* Continent grouping matching the AirVPN website (Earth / America / Asia /
   Europe / Oceania). "America" spans North + Central + South + Caribbean.
   Africa and Middle East are supported for any future locations. */
define('VPP_AIRVPN_CONTINENTS', array(
	'AF' => 'Asia', 'AL' => 'Europe', 'DZ' => 'Africa', 'AS' => 'Oceania', 'AD' => 'Europe',
	'AO' => 'Africa', 'AI' => 'America', 'AG' => 'America', 'AR' => 'America', 'AM' => 'Asia',
	'AW' => 'America', 'AU' => 'Oceania', 'AT' => 'Europe', 'AZ' => 'Asia', 'BS' => 'America',
	'BH' => 'Middle East', 'BD' => 'Asia', 'BB' => 'America', 'BY' => 'Europe', 'BE' => 'Europe',
	'BZ' => 'America', 'BJ' => 'Africa', 'BM' => 'America', 'BT' => 'Asia', 'BO' => 'America',
	'BQ' => 'America', 'BA' => 'Europe', 'BW' => 'Africa', 'BR' => 'America', 'VG' => 'America',
	'BN' => 'Asia', 'BG' => 'Europe', 'BF' => 'Africa', 'BI' => 'Africa', 'CV' => 'Africa',
	'KH' => 'Asia', 'CM' => 'Africa', 'CA' => 'America', 'KY' => 'America', 'CF' => 'Africa',
	'TD' => 'Africa', 'CL' => 'America', 'CN' => 'Asia', 'CX' => 'Asia', 'CO' => 'America',
	'KM' => 'Africa', 'CG' => 'Africa', 'CD' => 'Africa', 'CK' => 'Oceania', 'CR' => 'America',
	'HR' => 'Europe', 'CU' => 'America', 'CW' => 'America', 'CY' => 'Europe', 'CZ' => 'Europe',
	'DK' => 'Europe', 'DJ' => 'Africa', 'DM' => 'America', 'DO' => 'America', 'EC' => 'America',
	'EG' => 'Africa', 'SV' => 'America', 'GQ' => 'Africa', 'ER' => 'Africa', 'EE' => 'Europe',
	'SZ' => 'Africa', 'ET' => 'Africa', 'FK' => 'America', 'FO' => 'Europe', 'FJ' => 'Oceania',
	'FI' => 'Europe', 'FR' => 'Europe', 'GF' => 'America', 'PF' => 'Oceania', 'GA' => 'Africa',
	'GM' => 'Africa', 'GE' => 'Asia', 'DE' => 'Europe', 'GH' => 'Africa', 'GI' => 'Europe',
	'GR' => 'Europe', 'GL' => 'America', 'GD' => 'America', 'GP' => 'America', 'GU' => 'Oceania',
	'GT' => 'America', 'GG' => 'Europe', 'GN' => 'Africa', 'GW' => 'Africa', 'GY' => 'America',
	'HT' => 'America', 'HN' => 'America', 'HK' => 'Asia', 'HU' => 'Europe', 'IS' => 'Europe',
	'IN' => 'Asia', 'ID' => 'Asia', 'IR' => 'Middle East', 'IQ' => 'Middle East', 'IE' => 'Europe',
	'IM' => 'Europe', 'IL' => 'Middle East', 'IT' => 'Europe', 'CI' => 'Africa', 'JM' => 'America',
	'JP' => 'Asia', 'JE' => 'Europe', 'JO' => 'Middle East', 'KZ' => 'Asia', 'KE' => 'Africa',
	'KI' => 'Oceania', 'KP' => 'Asia', 'KR' => 'Asia', 'KW' => 'Middle East', 'KG' => 'Asia',
	'LA' => 'Asia', 'LV' => 'Europe', 'LB' => 'Middle East', 'LS' => 'Africa', 'LR' => 'Africa',
	'LY' => 'Africa', 'LI' => 'Europe', 'LT' => 'Europe', 'LU' => 'Europe', 'MO' => 'Asia',
	'MG' => 'Africa', 'MW' => 'Africa', 'MY' => 'Asia', 'MV' => 'Asia', 'ML' => 'Africa',
	'MT' => 'Europe', 'MH' => 'Oceania', 'MQ' => 'America', 'MR' => 'Africa', 'MU' => 'Africa',
	'YT' => 'Africa', 'MX' => 'America', 'FM' => 'Oceania', 'MD' => 'Europe', 'MC' => 'Europe',
	'MN' => 'Asia', 'ME' => 'Europe', 'MS' => 'America', 'MA' => 'Africa', 'MZ' => 'Africa',
	'MM' => 'Asia', 'NA' => 'Africa', 'NR' => 'Oceania', 'NP' => 'Asia', 'NL' => 'Europe',
	'NC' => 'Oceania', 'NZ' => 'Oceania', 'NI' => 'America', 'NE' => 'Africa', 'NG' => 'Africa',
	'NU' => 'Oceania', 'NF' => 'Oceania', 'MK' => 'Europe', 'MP' => 'Oceania', 'NO' => 'Europe',
	'OM' => 'Middle East', 'PK' => 'Asia', 'PW' => 'Oceania', 'PS' => 'Middle East', 'PA' => 'America',
	'PG' => 'Oceania', 'PY' => 'America', 'PE' => 'America', 'PH' => 'Asia', 'PN' => 'Oceania',
	'PL' => 'Europe', 'PT' => 'Europe', 'PR' => 'America', 'QA' => 'Middle East', 'RE' => 'Africa',
	'RO' => 'Europe', 'RU' => 'Europe', 'RW' => 'Africa', 'BL' => 'America', 'SH' => 'Africa',
	'KN' => 'America', 'LC' => 'America', 'MF' => 'America', 'PM' => 'America', 'VC' => 'America',
	'WS' => 'Oceania', 'SM' => 'Europe', 'ST' => 'Africa', 'SA' => 'Middle East', 'SN' => 'Africa',
	'RS' => 'Europe', 'SC' => 'Africa', 'SL' => 'Africa', 'SG' => 'Asia', 'SX' => 'America',
	'SK' => 'Europe', 'SI' => 'Europe', 'SB' => 'Oceania', 'SO' => 'Africa', 'ZA' => 'Africa',
	'SS' => 'Africa', 'ES' => 'Europe', 'LK' => 'Asia', 'SD' => 'Africa', 'SR' => 'America',
	'SJ' => 'Europe', 'SE' => 'Europe', 'CH' => 'Europe', 'SY' => 'Middle East', 'TW' => 'Asia',
	'TJ' => 'Asia', 'TZ' => 'Africa', 'TH' => 'Asia', 'TL' => 'Asia', 'TG' => 'Africa',
	'TK' => 'Oceania', 'TO' => 'Oceania', 'TT' => 'America', 'TN' => 'Africa', 'TR' => 'Asia',
	'TM' => 'Asia', 'TC' => 'America', 'TV' => 'Oceania', 'UG' => 'Africa', 'UA' => 'Europe',
	'AE' => 'Middle East', 'GB' => 'Europe', 'US' => 'America', 'UM' => 'Oceania', 'UY' => 'America',
	'UZ' => 'Asia', 'VU' => 'Oceania', 'VA' => 'Europe', 'VE' => 'America', 'VN' => 'Asia',
	'VI' => 'America', 'WF' => 'Oceania', 'EH' => 'Africa', 'YE' => 'Middle East', 'ZM' => 'Africa',
	'ZW' => 'Africa',
));

/* Display order used everywhere (matches the AirVPN website listing). */
define('VPP_AIRVPN_CONTINENT_ORDER', array('America', 'Asia', 'Europe', 'Oceania', 'Africa', 'Middle East', 'Other'));

function vpp_airvpn_continent($cc) {
	$cc = strtoupper($cc);
	return VPP_AIRVPN_CONTINENTS[$cc] ?? 'Other';
}

function vpp_airvpn_key() {
	return trim((string)config_get_path('installedpackages/vpn_providers/settings/airvpn_api_key', ''));
}

/* AirVPN uses one shared tls-crypt static key across all servers. Reuse the
   one already configured on an existing AirVPN tunnel so quick-add needs no
   manual paste. Prefers an AirVPN-named client, falls back to any tls-crypt. */
function vpp_airvpn_tlskey() {
	$airvpn = '';
	$any = '';
	foreach ((array)config_get_path('openvpn/openvpn-client', array()) as $c) {
		$b64 = (string)($c['tls'] ?? '');
		if ($b64 === '' || ($c['tls_type'] ?? '') !== 'crypt') {
			continue;
		}
		$pem = base64_decode($b64, true);
		if ($pem === false || $pem === '') {
			continue;
		}
		$descr = strtolower((string)($c['description'] ?? ''));
		if (strpos($descr, 'airvpn') !== false || strpos($descr, 'provider:') !== false) {
			$airvpn = trim($pem);
			break;
		}
		if ($any === '') {
			$any = trim($pem);
		}
	}
	return $airvpn !== '' ? $airvpn : $any;
}

function vpp_airvpn_parse_status($json) {
	$j = json_decode($json, true);
	if (!is_array($j) || !isset($j['servers']) || !is_array($j['servers'])) {
		return array('error' => 'unexpected AirVPN API response');
	}
	$list = array();
	foreach ($j['servers'] as $s) {
		$host = trim((string)($s['public_name'] ?? ''));
		if ($host === '') {
			continue;
		}
		if (($s['health'] ?? '') !== 'ok') {
			continue;
		}
		$name = trim((string)($s['name'] ?? ''));
		if ($name === '') {
			$name = $host;
		}
		$cc = strtoupper((string)($s['country_code'] ?? '??'));
		$list[] = array(
			'host' => $host,
			'cc' => $cc,
			'country' => trim((string)($s['country_name'] ?? '')),
			'name' => $name,
			'load' => (int)($s['currentload'] ?? -1),
			'ip' => (string)($s['ip_v4_in1'] ?? ''),
			'continent' => vpp_airvpn_continent($cc),
		);
	}
	if (empty($list)) {
		return array('error' => 'no healthy AirVPN servers returned by the API');
	}
	usort($list, function ($a, $b) {
		$ca = array_search($a['continent'], VPP_AIRVPN_CONTINENT_ORDER, true);
		$cb = array_search($b['continent'], VPP_AIRVPN_CONTINENT_ORDER, true);
		return ($ca <=> $cb) ?: (strcmp($a['country'], $b['country']) ?: ($a['load'] <=> $b['load']));
	});
	return array('list' => $list);
}

function vpp_airvpn_servers($force = false) {
	if (!function_exists('curl_init')) {
		return array('error' => 'PHP curl extension not available');
	}
	$cache = VPP_STATE . '/airvpn_servers.json';
	if (!$force && file_exists($cache) && (time() - filemtime($cache)) < 600) {
		$c = json_decode(file_get_contents($cache), true);
		if (is_array($c) && isset($c['list'])) {
			return $c;
		}
	}
	$key = vpp_airvpn_key();
	if ($key === '') {
		return array('error' => 'no AirVPN API key configured - set it in VPN Providers settings');
	}
	$ch = curl_init(VPP_AIRVPN_STATUS_URL . '?key=' . urlencode($key));
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_TIMEOUT => 12,
		CURLOPT_CONNECTTIMEOUT => 8,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS => 2,
		CURLOPT_USERAGENT => 'pfsense-vpn-providers',
	));
	$body = curl_exec($ch);
	$err = curl_errno($ch);
	curl_close($ch);
	if ($err !== 0 || $body === false || $body === '') {
		return array('error' => 'AirVPN API unreachable (curl errno ' . $err . ')');
	}
	$rows = vpp_airvpn_parse_status($body);
	if (isset($rows['error'])) {
		return $rows;
	}
	if (!is_dir(VPP_STATE)) {
		mkdir(VPP_STATE, 0755, true);
	}
	file_put_contents($cache, json_encode($rows, JSON_UNESCAPED_SLASHES));
	return $rows;
}

/* ------------------------------------------------------------------
 * .ovpn parsing
 * ------------------------------------------------------------------ */

function vpp_parse_ovpn($text) {
	$out = array(
		'remotes' => array(), 'proto' => 'udp4', 'auth' => 'SHA256',
		'data_ciphers' => array(), 'cipher' => '',
		'tls' => '', 'tls_type' => '', 'tls_direction' => '',
		'ca_pem' => '', 'cert_pem' => '', 'key_pem' => '',
		'auth_user_pass' => false, 'remote_cert_tls' => false,
		'route_nopull' => false, 'keepalive' => array(10, 60),
		'custom' => array(), 'verb' => ''
	);
	$lines = preg_split('/\r\n|\r|\n/', $text);
	$n = count($lines);
	$i = 0;
	while ($i < $n) {
		$line = trim($lines[$i]);
		$i++;
		if ($line === '' || $line[0] === '#' || $line[0] === ';') {
			continue;
		}
		/* inline blocks - the closing tag may be glued to content
		   (-----END CERTIFICATE-----</ca>) so match containment, not position */
		if (preg_match('/^<(ca|cert|key|tls-auth|tls-crypt)>/i', $line, $m)) {
			$tag = strtolower($m[1]);
			$buf = '';
			while ($i < $n) {
				$cl = $lines[$i];
				$pos = stripos($cl, '</' . $tag . '>');
				if ($pos !== false) {
					if ($pos > 0) {
						$buf .= rtrim(substr($cl, 0, $pos)) . "\n";
					}
					$i++;
					break;
				}
				$buf .= rtrim($cl) . "\n";
				$i++;
			}
			$buf = trim($buf) . "\n";
			if ($tag === 'ca') { $out['ca_pem'] = $buf; }
			elseif ($tag === 'cert') { $out['cert_pem'] = $buf; }
			elseif ($tag === 'key') { $out['key_pem'] = $buf; }
			elseif ($tag === 'tls-auth') {
				$out['tls'] = $buf; $out['tls_type'] = 'auth';
				$out['tls_direction'] = '';
			}
			elseif ($tag === 'tls-crypt') {
				$out['tls'] = $buf; $out['tls_type'] = 'crypt';
				$out['tls_direction'] = '';
			}
			continue;
		}
		$parts = preg_split('/\s+/', $line);
		$kw = strtolower($parts[0]);
		$arg = isset($parts[1]) ? $parts[1] : '';
		switch ($kw) {
			case 'remote':
				$host = $parts[1] ?? '';
				$port = $parts[2] ?? '1194';
				$rproto = strtolower($parts[3] ?? '');
				$out['remotes'][] = array($host, $port, $rproto);
				break;
			case 'proto':
				$p = strtolower($arg);
				if (in_array($p, array('udp', 'udp4'), true)) { $out['proto'] = 'udp4'; }
				elseif (in_array($p, array('tcp', 'tcp4'), true)) { $out['proto'] = 'tcp4'; }
				elseif ($p === 'udp6') { $out['proto'] = 'udp6'; }
				elseif ($p === 'tcp6') { $out['proto'] = 'tcp6'; }
				break;
			case 'auth':
				if (strtoupper($arg) !== $arg || !in_array($arg, array('SHA1', 'SHA256', 'SHA384', 'SHA512'))) {
					/* keep known digests only */
				}
				$out['auth'] = strtoupper($arg);
				break;
			case 'data-ciphers':
				/* colon separated per OpenVPN */
				$out['data_ciphers'] = array_values(array_filter(array_map('trim', explode(':', implode(' ', array_slice($parts, 1))))));
				break;
			case 'cipher':
				$out['cipher'] = strtoupper($arg);
				break;
			case 'auth-user-pass':
				$out['auth_user_pass'] = true;
				break;
			case 'remote-cert-tls':
				$out['remote_cert_tls'] = true;
				break;
			case 'route-nopull':
				$out['route_nopull'] = true;
				break;
			case 'keepalive':
				$out['keepalive'] = array((int)($parts[1] ?? 10), (int)($parts[2] ?? 60));
				break;
			case 'verb':
				$out['verb'] = $arg;
				break;
			case 'comp-lzo':
			case 'compression':
			case 'compress':
				/* mapped later via allow_compression/compression fields */
				$out['custom'][] = $line;
				break;
			case 'tls-auth':
				/* inline handled above; keydir may ride this line as arg 2 */
				$out['tls_direction'] = isset($parts[2]) ? $parts[2] : '';
				$out['tls_type'] = 'auth';
				break;
			case 'tls-crypt':
				$out['tls_direction'] = '';
				$out['tls_type'] = 'crypt';
				break;
			case 'key-direction':
				$out['tls_direction'] = $arg;
				break;
			case 'mssfix':
			case 'mlock':
			case 'auth-nocache':
			case 'persist-key':
			case 'persist-tun':
			case 'remote-random':
			case 'remote-cert-tls':
				/* handled natively by pfSense or harmless as custom */
				$out['custom'][] = $line;
				break;
			default:
				/* collect everything else so nothing the provider sent is lost */
				if (count($out['custom']) < 40) {
					$out['custom'][] = $line;
				}
				break;
		}
	}
	/* tls-auth inline blocks keep the key; direction came from key-direction
	   or a trailing tls-auth line. Fall back to AirVPN-style default. */
	if ($out['tls'] !== '' && $out['tls_type'] === '') {
		$out['tls_type'] = 'auth';
	}
	return $out;
}

/* ------------------------------------------------------------------
 * Planning (no writes)
 * ------------------------------------------------------------------ */

function vpp_used_vpnids() {
	$used = array();
	foreach ((array)config_get_path('openvpn/openvpn-client', array()) as $c) {
		$used[] = (int)($c['vpnid'] ?? 0);
	}
	foreach ((array)config_get_path('openvpn/openvpn-server', array()) as $s) {
		$used[] = (int)($s['vpnid'] ?? 0);
	}
	return $used;
}

function vpp_next_vpnid() {
	$used = vpp_used_vpnids();
	$n = 1;
	while (in_array($n, $used)) { $n++; }
	return $n;
}

function vpp_next_opt() {
	$max = 0;
	foreach (array_keys((array)config_get_path('interfaces', array())) as $k) {
		if (preg_match('/^opt(\d+)$/', $k, $m)) {
			$max = max($max, (int)$m[1]);
		}
	}
	return 'opt' . ($max + 1);
}

function vpp_find_ca_by_descr($descr) {
	foreach ((array)config_get_path('ca', array()) as $ca) {
		if (($ca['descr'] ?? '') === $descr) {
			return $ca['refid'];
		}
	}
	return '';
}

/* Connectable remote host for a country. AirVPN's legacy per-country
   server hostnames (<cc>N.vpn.airdns.org) accept handshakes; the API entry
   IPs (ip_v4_in1) are reachable but silently drop them - so quick-add must
   connect via the hostname, exactly like the working .ovpn exports do.
   Tries N=3 first (what the working exports use), then 1, 2, 4, 5. */
function vpp_airvpn_cc_host($cc, $resolver = 'gethostbyname') {
	$cc = strtolower(trim($cc));
	if (!preg_match('/^[a-z]{2}$/', $cc)) {
		return '';
	}
	foreach (array(3, 1, 2, 4, 5) as $n) {
		$host = $cc . $n . '.vpn.airdns.org';
		$ip = @$resolver($host);
		if (is_string($ip) && $ip !== '' && $ip !== $host) {
			return $host;
		}
	}
	return '';
}

/* Find the client certificate an existing AirVPN tunnel uses (same CA), so
   quick-add can authenticate by certificate instead of user/pass. */
function vpp_find_airvpn_certref($caref) {
	if ($caref === '') {
		return '';
	}
	/* prefer the certref already in use by an AirVPN-named client on this CA */
	foreach ((array)config_get_path('openvpn/openvpn-client', array()) as $c) {
		if (strpos((string)($c['description'] ?? ''), 'AirVPN') === 0 &&
				($c['caref'] ?? '') === $caref && ($c['certref'] ?? '') !== '') {
			return $c['certref'];
		}
	}
	/* fallback: a cert issued by this CA, AirVPN-named one first */
	$fallback = '';
	foreach ((array)config_get_path('cert', array()) as $cert) {
		if (($cert['caref'] ?? '') !== $caref || ($cert['refid'] ?? '') === '') {
			continue;
		}
		if (strpos(strtolower((string)($cert['descr'] ?? '')), 'airvpn') !== false) {
			return $cert['refid'];
		}
		if ($fallback === '') {
			$fallback = $cert['refid'];
		}
	}
	return $fallback;
}

/* Reuse an existing CA with identical certificate content - importing
   several provider configs must not duplicate the same CA. */
function vpp_find_ca_by_crt($pem) {
	$finger = base64_encode(preg_replace('/\s+/', '', $pem));
	foreach ((array)config_get_path('ca', array()) as $ca) {
		if (($ca['crt'] ?? '') === $finger) {
			return $ca['refid'];
		}
		/* same certificate with different line wrapping */
		$existing = base64_decode($ca['crt'] ?? '');
		if ($existing !== false && preg_replace('/\s+/', '', $existing) === preg_replace('/\s+/', '', $pem)) {
			return $ca['refid'];
		}
	}
	return '';
}

function vpp_find_client_by_descr($descr) {
	$id = 0;
	foreach ((array)config_get_path('openvpn/openvpn-client', array()) as $k => $c) {
		if (($c['description'] ?? '') === $descr) {
			$id = $k;
			break;
		}
	}
	return $id;
}

/* Build the complete setup plan. Never writes. */
function vpp_plan_create($name, $parsed, $opts = array()) {
	$name = trim($name);
	if ($name === '' || strlen($name) > 40 || !preg_match('/^[A-Za-z0-9 _-]+$/', $name)) {
		return array('error' => 'invalid name (letters, digits, space, _ - max 40 chars)');
	}
	$descr = VPP_MARKER . $name;
	foreach ((array)config_get_path('openvpn/openvpn-client', array()) as $c) {
		if (($c['description'] ?? '') === $descr) {
			return array('error' => 'a client named "' . $name . '" already exists');
		}
	}
	if (empty($parsed['remotes'])) {
		return array('error' => 'no remote lines found in the .ovpn import');
	}

	$vpnid = vpp_next_vpnid();
	$opt = vpp_next_opt();
	$safe = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $name));

	/* CA: reuse an existing CA with our descr OR identical certificate
	   content, else import a new one. AirVPN quick-add ships no CA block -
	   it always reuses the installed AirVPN_CA. */
	$ca_descr = 'PVD ' . $name . ' CA';
	$caref = vpp_find_ca_by_descr($ca_descr);
	if ($caref === '') {
		$caref = vpp_find_ca_by_crt($parsed['ca_pem'] ?? '');
	}
	if ($caref === '' && ($opts['provider'] ?? '') === 'airvpn') {
		$caref = vpp_find_ca_by_descr('AirVPN_CA');
	}
	if ($caref === '' && empty($parsed['ca_pem'])) {
		return array('error' => 'no CA block found in the .ovpn import and no installed AirVPN_CA to reuse');
	}
	$ca_item = null;
	if ($caref === '') {
		$caref = uniqid();
		$ca_item = array(
			'refid' => $caref,
			'descr' => $ca_descr,
			'crt' => base64_encode($parsed['ca_pem']),
			'serial' => 0
		);
	}

	/* client cert (optional) */
	$cert_item = null;
	$certref = '';
	if (!empty($parsed['cert_pem']) && !empty($parsed['key_pem'])) {
		$certref = uniqid();
		$cert_item = array(
			'refid' => $certref,
			'descr' => 'PVD ' . $name . ' Client',
			'crt' => base64_encode($parsed['cert_pem']),
			'prv' => base64_encode($parsed['key_pem'])
		);
	} elseif (($opts['provider'] ?? '') === 'airvpn') {
		/* AirVPN quick-add ships no cert+key: authenticate with the client
		   certificate an existing AirVPN tunnel already uses. pfSense ignores
		   a bare auth-user-pass flag, so a config without either would abort
		   OpenVPN with "No client-side authentication method is specified". */
		$certref = vpp_find_airvpn_certref($caref);
		if ($certref === '') {
			return array('error' => 'no AirVPN client certificate found - create or import one AirVPN tunnel first; quick-add reuses its certificate to authenticate');
		}
	}

	/* custom options: pass through the provider directives pfSense has no
	   field for, prefixed by the standard client hardening set */
	$custom = "client;\npersist-key;\npersist-tun;\n";
	if (!empty($parsed['remote_cert_tls'])) {
		$custom .= "remote-cert-tls server;\n";
	}
	foreach ($parsed['custom'] as $c) {
		if (preg_match('/^(client\b|dev\b|persist-key|persist-tun|remote-random|nobind|resolv-retry|remote\b|proto\b|auth\b|cipher\b|data-ciphers|keepalive|verb\b|local\b|lport\b|management\b|ca\b|cert\b|key\b|tls-auth|tls-crypt|auth-user-pass|route-nopull|up\b|down\b|script-security|daemon|writepid|dev-node|dev-type|pull\b|remote-cert-tls)/i', $c)) {
			continue; /* mapped natively or already added */
		}
		$custom .= rtrim($c) . ";\n";
	}
	if ($parsed['route_nopull']) {
		$custom .= "route-nopull;\n";
	}
	$custom = rtrim($custom, "\n");

	$client = array(
		'vpnid' => $vpnid,
		'protocol' => $parsed['proto'],
		'dev_mode' => 'tun',
		'interface' => 'wan',
		'server_addr' => $parsed['remotes'][0][0],
		'server_port' => $parsed['remotes'][0][1],
		'description' => $descr,
		'mode' => 'p2p_tls',
		'topology' => 'subnet',
		'custom_options' => $custom,
		'caref' => $caref,
		'digest' => $parsed['auth'],
		'tunnel_network' => '',
		'tunnel_networkv6' => '',
		'remote_network' => '',
		'remote_networkv6' => '',
		'allow_compression' => 'no',
		'auth-retry-none' => true,
		'passtos' => false,
		'route_no_pull' => false,
		'route_no_exec' => false,
		'dns_add' => false,
		'verbosity_level' => 1,
		'ping_method' => 'keepalive',
		'keepalive_interval' => (string)$parsed['keepalive'][0],
		'keepalive_timeout' => (string)$parsed['keepalive'][1],
		'inactive_seconds' => 300,
	);
	/* pfSense treats the disable flag by KEY PRESENCE (isset), and serializes
	   PHP true/false into an empty XML element - so an absent key means enabled,
	   a present key (even value '') means disabled. Only set it when disabled. */
	if (empty($opts['create_enabled'])) {
		$client['disable'] = true;
	}
	if ($certref !== '') {
		$client['certref'] = $certref;
	}
	/* pfSense stores cipher lists as comma-joined STRINGS (writes them into
	   config.ovpn colon-separated). OpenVPN 2.6 fails to start when the line
	   has no argument, so ALWAYS provide a value - defaulting to the cipher
	   set the installed AirVPN tunnels actually use. */
	if (!empty($parsed['data_ciphers'])) {
		$client['data_ciphers'] = implode(',', $parsed['data_ciphers']);
	} else {
		$client['data_ciphers'] = 'AES-256-GCM,AES-256-CBC';
	}
	if (!empty($parsed['cipher'])) {
		$client['data_ciphers_fallback'] = $parsed['cipher'];
	} else {
		$client['data_ciphers_fallback'] = 'AES-256-CBC';
	}
	if (!empty($parsed['tls'])) {
		$client['tls'] = base64_encode($parsed['tls']);
		$client['tls_type'] = ($parsed['tls_type'] === 'crypt') ? 'crypt' : 'auth';
		$client['tlsauth_keydir'] = in_array($parsed['tls_direction'], array('0', '1')) ? $parsed['tls_direction'] : 'default';
	}

	/* which tunnel IP protocols to create: '4', '6' or both ('46') */
	$ipv = (string)($opts['ipv'] ?? '46');
	if (!in_array($ipv, array('4', '6', '46'), true)) {
		$ipv = '46';
	}
	$client['create_gw'] = $ipv === '4' ? 'v4only' : ($ipv === '6' ? 'v6only' : 'both');

	/* optional routing: gateway group membership (with priority/weight) and an
	   outbound NAT rule mirroring the existing AirVPN rules */
	$gateway_group = trim((string)($opts['gateway_group'] ?? ''));
	if ($gateway_group !== '' && !preg_match('/^[A-Za-z0-9 _.-]{1,63}$/', $gateway_group)) {
		return array('error' => 'invalid gateway group name');
	}
	$gateway_group_weight = max(1, min(500, (int)($opts['gateway_group_weight'] ?? 1)));
	$nat_src = trim((string)($opts['nat_src'] ?? ''));
	if ($nat_src !== '' && !preg_match('#^\d{1,3}(\.\d{1,3}){3}/(\d{1,2})$#', $nat_src)) {
		return array('error' => 'invalid NAT source subnet');
	}
	$nat_outbound = !empty($opts['nat_outbound']);

	$gwbase = 'PVD_' . $safe;
	$gateways = array();
	if ($ipv !== '6') {
		$gateways[] = array(
			'name' => $gwbase . '_V4',
			'interface' => $opt,
			'ipprotocol' => 'inet',
			'gateway' => 'dynamic',
			'descr' => 'Interface ' . $gwbase . '_V4 Gateway',
			'weight' => 1,
			'attributedown' => ''
		);
	}
	if ($ipv !== '4') {
		$gateways[] = array(
			'name' => $gwbase . '_V6',
			'interface' => $opt,
			'ipprotocol' => 'inet6',
			'gateway' => 'dynamic',
			'descr' => 'Interface ' . $gwbase . '_V6 Gateway',
			'weight' => 1,
			'attributedown' => ''
		);
	}

	$interface = array(
		'if' => 'ovpnc' . $vpnid,
		'descr' => $name,
		'enable' => ''
	);

	return array(
		'name' => $name,
		'description' => $descr,
		'vpnid' => $vpnid,
		'opt' => $opt,
		'caref' => $caref,
		'certref' => $certref,
		'ca_item' => $ca_item,
		'cert_item' => $cert_item,
		'client' => $client,
		'interface' => $interface,
		'gateways' => $gateways,
		'gw_names' => array_column($gateways, 'name'),
		'gateway_group' => $gateway_group,
		'gateway_group_weight' => $gateway_group_weight,
		'nat_outbound' => array('src' => $nat_src, 'descr' => 'PVD ' . $name),
		'disabled' => true
	);
}

/* ------------------------------------------------------------------
 * Gateway groups + outbound NAT (routing for a created client)
 * ------------------------------------------------------------------ */

/* Existing gateway groups, keyed by name. pfSense stores them as
   gateways/gateway_group, each {name, item[], trigger, descr} where each
   item is "GATEWAYNAME|WEIGHT|address". */
function vpp_gateway_groups() {
	$out = array();
	foreach ((array)config_get_path('gateways/gateway_group', array()) as $g) {
		$name = trim((string)($g['name'] ?? ''));
		if ($name !== '') {
			$out[$name] = trim((string)($g['descr'] ?? '')) !== '' ? $g['descr'] : $name;
		}
	}
	return $out;
}

/* Add gateways to a group (creating it when needed). A gateway already in
   the group is re-weighted, not duplicated. */
function vpp_gateway_group_add($name, $gateways, $weight = 1) {
	$name = trim($name);
	if ($name === '' || strlen($name) > 63 || !preg_match('/^[A-Za-z0-9 _.-]+$/', $name)) {
		return array('error' => 'invalid gateway group name');
	}
	$weight = max(1, min(500, (int)$weight));
	$groups = (array)config_get_path('gateways/gateway_group', array());
	$idx = null;
	foreach ($groups as $k => $g) {
		if (($g['name'] ?? '') === $name) {
			$idx = $k;
			break;
		}
	}
	if ($idx === null) {
		$idx = count($groups);
		$groups[$idx] = array('name' => $name, 'item' => array(), 'trigger' => 'down', 'descr' => $name);
	}
	$item = array();
	foreach ((array)($groups[$idx]['item'] ?? array()) as $it) {
		$item[$it] = $it;
	}
	foreach ((array)$gateways as $gw) {
		foreach (array_keys($item) as $k_it) {
			if (explode('|', (string)$item[$k_it])[0] === $gw) {
				unset($item[$k_it]);
			}
		}
		$item[$gw . '|' . $weight . '|address'] = $gw . '|' . $weight . '|address';
	}
	$groups[$idx]['item'] = array_values($item);
	config_set_path('gateways/gateway_group', $groups);
	return array('ok' => true, 'group' => $name);
}

/* Remove the given gateways from every group; groups themselves stay. */
function vpp_gateway_groups_strip($gateways) {
	$gateways = (array)$gateways;
	$groups = (array)config_get_path('gateways/gateway_group', array());
	$changed = false;
	foreach ($groups as $k => $g) {
		$keep = array();
		foreach ((array)($g['item'] ?? array()) as $it) {
			if (in_array(explode('|', (string)$it)[0], $gateways, true)) {
				$changed = true;
				continue;
			}
			$keep[] = $it;
		}
		$groups[$k]['item'] = $keep;
	}
	if ($changed) {
		config_set_path('gateways/gateway_group', $groups);
	}
	return $changed;
}

/* Groups a set of gateways belongs to: name => matching group item. */
function vpp_gateway_groups_for($gateways) {
	$out = array();
	$gateways = (array)$gateways;
	if (empty($gateways)) {
		return $out;
	}
	foreach ((array)config_get_path('gateways/gateway_group', array()) as $g) {
		foreach ((array)($g['item'] ?? array()) as $it) {
			if (in_array(explode('|', (string)$it)[0], $gateways, true)) {
				$out[$g['name'] ?? '?'] = $it;
			}
		}
	}
	return $out;
}

/* LAN CIDR derived from the box config (what the existing AirVPN outbound
   NAT rules use as their source). */
function vpp_lan_cidr() {
	$ip = (string)config_get_path('interfaces/lan/ipaddr', '');
	$subnet = (string)config_get_path('interfaces/lan/subnet', '');
	if ($ip !== '' && (int)$subnet > 0) {
		return $ip . '/' . (int)$subnet;
	}
	return '192.168.1.0/24';
}

/* Outbound NAT rule in the exact shape of the existing AirVPN rules:
   source = LAN CIDR, target = <optN>ip, ipprotocol = inet, descr tag. */
function vpp_nat_outbound_add($interface, $src_cidr, $descr) {
	if (!preg_match('#^\d{1,3}(\.\d{1,3}){3}/(\d{1,2})$#', $src_cidr)) {
		return array('error' => 'invalid NAT source subnet');
	}
	$rules = array_values(array_filter(
		(array)config_get_path('nat/outbound/rule', array()),
		function ($r) use ($interface, $descr) {
			return !((($r['interface'] ?? '') === $interface) && (($r['descr'] ?? '') === $descr));
		}
	));
	$rules[] = array(
		'source' => array('network' => $src_cidr),
		'sourceport' => '',
		'descr' => $descr,
		'target' => $interface . 'ip',
		'interface' => $interface,
		'poolopts' => '',
		'source_hash_key' => '',
		'ipprotocol' => 'inet',
		'destination' => array('any' => ''),
		'target_subnet' => '',
	);
	config_set_path('nat/outbound/rule', $rules);
	return array('ok' => true);
}

/* Remove our outbound NAT rules (matching interface + descr prefix). */
function vpp_nat_outbound_remove($interface, $descr_prefix) {
	$rules = (array)config_get_path('nat/outbound/rule', array());
	$before = count($rules);
	$rules = array_values(array_filter($rules, function ($r) use ($interface, $descr_prefix) {
		if (($r['interface'] ?? '') !== $interface) {
			return true;
		}
		return strpos((string)($r['descr'] ?? ''), $descr_prefix) !== 0;
	}));
	if (count($rules) !== $before) {
		config_set_path('nat/outbound/rule', $rules);
		return true;
	}
	return false;
}

/* ------------------------------------------------------------------
 * Applying (respects apply_live)
 * ------------------------------------------------------------------ */

function vpp_apply_create($plan) {
	if (isset($plan['error'])) {
		return array('error' => $plan['error']);
	}
	if (!vpp_apply_live()) {
		vpp_log('DRY-RUN create ' . $plan['description'] . ' (apply_live=no)');
		return array('dryrun' => true, 'plan' => $plan);
	}

	/* CA / cert */
	if ($plan['ca_item']) {
		$a_ca = (array)config_get_path('ca', array());
		$a_ca[] = $plan['ca_item'];
		config_set_path('ca', $a_ca);
	}
	if ($plan['cert_item']) {
		$a_cert = (array)config_get_path('cert', array());
		$a_cert[] = $plan['cert_item'];
		config_set_path('cert', $a_cert);
	}
	/* client (append) */
	$a_client = (array)config_get_path('openvpn/openvpn-client', array());
	$a_client[] = $plan['client'];
	config_set_path('openvpn/openvpn-client', $a_client);
	/* interface assignment */
	config_set_path('interfaces/' . $plan['opt'], $plan['interface']);
	/* gateways */
	$a_gw = (array)config_get_path('gateways/gateway_item', array());
	foreach ($plan['gateways'] as $g) {
		$a_gw[] = $g;
	}
	config_set_path('gateways/gateway_item', $a_gw);

	/* routing extras the user opted into */
	if ($plan['gateway_group'] !== '') {
		vpp_gateway_group_add($plan['gateway_group'], $plan['gw_names'], $plan['gateway_group_weight']);
	}
	if (!empty($plan['nat_outbound']['src'])) {
		vpp_nat_outbound_add($plan['opt'], $plan['nat_outbound']['src'], $plan['nat_outbound']['descr']);
	}

	write_config('VPN Providers: added ' . $plan['description'] . ' (disabled)');

	/* regenerate the (disabled) client config; no tunnel starts */
	openvpn_resync('client', $plan['client']);
	services_unbound_configure(false);
	filter_configure();

	/* registry */
	$reg = vpp_registry();
	$uid = uniqid('pvd');
	$reg[$uid] = array(
		'uid' => $uid,
		'name' => $plan['name'],
		'description' => $plan['description'],
		'vpnid' => $plan['vpnid'],
		'opt' => $plan['opt'],
		'caref' => $plan['caref'],
		'certref' => $plan['certref'],
		'gateways' => $plan['gw_names'],
		'created' => date('c'),
		'provider' => $plan['provider'] ?? 'import'
	);
	vpp_registry_save($reg);
	vpp_log('Created ' . $plan['description'] . ' (vpnid=' . $plan['vpnid'] . ', disabled)');
	return array('ok' => true, 'uid' => $uid, 'plan' => $plan);
}

/* ------------------------------------------------------------------
 * Enable / disable / remove
 * ------------------------------------------------------------------ */

function vpp_set_enabled($uid, $enabled) {
	$reg = vpp_registry();
	if (!isset($reg[$uid])) {
		return array('error' => 'unknown uid');
	}
	$entry = $reg[$uid];
	$descr = $entry['description'];
	$id = 0;
	$client = null;
	foreach ((array)config_get_path('openvpn/openvpn-client', array()) as $k => $c) {
		if (($c['description'] ?? '') === $descr) {
			$id = $k;
			$client = $c;
			break;
		}
	}
	if ($client === null) {
		return array('error' => 'client not found in config');
	}
	if ($enabled) {
		unset($client['disable']);
	} else {
		$client['disable'] = true;
	}
	config_set_path("openvpn/openvpn-client/{$id}", $client);
	write_config('VPN Providers: ' . ($enabled ? 'enabled' : 'disabled') . ' ' . $descr);
	if ($enabled) {
		openvpn_resync('client', $client);
	} else {
		openvpn_kill_client($client['vpnid'] ?? 0, '', 0);
	}
	vpp_log(($enabled ? 'Enabled' : 'Disabled') . ' ' . $descr);
	return array('ok' => true);
}

function vpp_remove($uid) {
	$reg = vpp_registry();
	if (!isset($reg[$uid])) {
		return array('error' => 'unknown uid');
	}
	$entry = $reg[$uid];
	$descr = $entry['description'];

	/* stop a running tunnel first */
	$id = 0;
	$client = null;
	$a_client = (array)config_get_path('openvpn/openvpn-client', array());
	foreach ($a_client as $k => $c) {
		if (($c['description'] ?? '') === $descr) {
			$id = $k;
			$client = $c;
			break;
		}
	}
	if ($client !== null) {
		openvpn_kill_client($client['vpnid'] ?? 0, '', 0);
		unset($a_client[$id]);
		config_set_path('openvpn/openvpn-client', array_values($a_client));
	}

	/* owned CA / cert */
	foreach ((array)config_get_path('ca', array()) as $k => $ca) {
		if (($ca['descr'] ?? '') === ('PVD ' . $entry['name'] . ' CA')) {
			$a_ca = (array)config_get_path('ca', array());
			unset($a_ca[$k]);
			config_set_path('ca', array_values($a_ca));
			break;
		}
	}
	foreach ((array)config_get_path('cert', array()) as $k => $cert) {
		if (($cert['descr'] ?? '') === ('PVD ' . $entry['name'] . ' Client')) {
			$a_cert = (array)config_get_path('cert', array());
			unset($a_cert[$k]);
			config_set_path('cert', array_values($a_cert));
			break;
		}
	}

	/* interface assignment (only ours: if == ovpnc<vpnid> of the removed client) */
	$opt = $entry['opt'] ?? '';
	$if = 'ovpnc' . ($entry['vpnid'] ?? '');
	if ($opt !== '' && (config_get_path("interfaces/{$opt}/if") ?? '') === $if) {
		config_del_path("interfaces/{$opt}");
	}

	/* gateways */
	$a_gw = (array)config_get_path('gateways/gateway_item', array());
	$a_gw = array_values(array_filter($a_gw, function ($g) use ($entry) {
		return !in_array(($g['name'] ?? ''), (array)($entry['gateways'] ?? array()), true);
	}));
	config_set_path('gateways/gateway_item', $a_gw);

	/* drop the client's gateways from any gateway groups, and remove our
	   outbound NAT rules for this interface */
	vpp_gateway_groups_strip($entry['gateways'] ?? array());
	vpp_nat_outbound_remove($opt, 'PVD ');

	unset($reg[$uid]);
	vpp_registry_save($reg);
	write_config('VPN Providers: removed ' . $descr);
	openvpn_configure();
	filter_configure();
	vpp_log('Removed ' . $descr);
	return array('ok' => true);
}

/* ------------------------------------------------------------------
 * Listing
 * ------------------------------------------------------------------ */

function vpp_list() {
	$out = array();
	foreach (vpp_registry() as $uid => $e) {
		$client = null;
		foreach ((array)config_get_path('openvpn/openvpn-client', array()) as $c) {
			if (($c['description'] ?? '') === ($e['description'] ?? '')) {
				$client = $c;
				break;
			}
		}
		$e['exists'] = ($client !== null);
		$e['disabled'] = isset($client['disable']);
		$e['server'] = ($client['server_addr'] ?? '') . ':' . ($client['server_port'] ?? '');
		$e['groups'] = vpp_gateway_groups_for($e['gateways'] ?? array());
		$e['nat'] = false;
		if ($client !== null && !empty($e['opt'])) {
			foreach ((array)config_get_path('nat/outbound/rule', array()) as $r) {
				if (($r['interface'] ?? '') === $e['opt'] && strpos((string)($r['descr'] ?? ''), 'PVD ') === 0) {
					$e['nat'] = true;
					break;
				}
			}
		}
		$out[$uid] = $e;
	}
	return $out;
}

/* Adopt existing OpenVPN clients that belong to this package into the
   registry so they appear in the list, can be managed/removed and are
   included in backups. Idempotent (keyed on the client description). */
function vpp_adopt_existing() {
	$reg = vpp_registry();
	$have = array();
	foreach ($reg as $e) {
		$have[$e['description']] = true;
	}
	$interface_map = array();
	foreach ((array)config_get_path('interfaces', array()) as $k => $v) {
		if (is_array($v) && !empty($v['if'])) {
			$interface_map[$v['if']] = $k;
		}
	}
	$by_opt = array();
	foreach ((array)config_get_path('gateways/gateway_item', array()) as $g) {
		$by_opt[$g['interface']][] = $g['name'];
	}
	$added = 0;
	foreach ((array)config_get_path('openvpn/openvpn-client', array()) as $c) {
		$descr = trim((string)($c['description'] ?? ''));
		if ($descr === '' || !(strpos($descr, 'AirVPN') === 0 || strpos($descr, 'Provider: ') === 0)) {
			continue;
		}
		if (isset($have[$descr])) {
			continue;
		}
		$name = strpos($descr, 'Provider: ') === 0 ? substr($descr, 10) : $descr;
		$vpnid = (int)($c['vpnid'] ?? 0);
		$opt = $interface_map['ovpnc' . $vpnid] ?? '';
		$uid = uniqid('pvd');
		$reg[$uid] = array(
			'uid' => $uid,
			'name' => $name,
			'description' => $descr,
			'vpnid' => $vpnid,
			'opt' => $opt,
			'caref' => (string)($c['caref'] ?? ''),
			'certref' => (string)($c['certref'] ?? ''),
			'gateways' => isset($by_opt[$opt]) ? array_values($by_opt[$opt]) : array(),
			'created' => date('c'),
			'provider' => strpos($descr, 'AirVPN ') === 0 ? 'airvpn' : 'import'
		);
		$have[$descr] = true;
		$added++;
	}
	if ($added > 0) {
		vpp_registry_save($reg);
	}
	return $added;
}

/* ------------------------------------------------------------------
 * Backup / restore (provider clients it manages)
 * ------------------------------------------------------------------ */

function vpp_backup() {
	$out = array('format' => 'pfsense-vpn-providers', 'version' => '1', 'exported' => date('c'));
	$reg = vpp_registry();
	$a_client = (array)config_get_path('openvpn/openvpn-client', array());
	$intf = (array)config_get_path('interfaces', array());
	$a_gw = (array)config_get_path('gateways/gateway_item', array());
	foreach ($reg as $e) {
		$client = null;
		foreach ($a_client as $c) {
			if (($c['description'] ?? '') === ($e['description'] ?? '')) {
				$client = $c;
				break;
			}
		}
		if ($client === null) {
			continue;
		}
		$entry = array(
			'registry' => $e,
			'client' => $client,
			'interface' => ($e['opt'] !== '' && isset($intf[$e['opt']])) ? $intf[$e['opt']] : null,
			'gateways' => array()
		);
		foreach ($a_gw as $g) {
			if (in_array(($g['name'] ?? ''), (array)($e['gateways'] ?? array()), true)) {
				$entry['gateways'][] = $g;
			}
		}
		$out['clients'][] = $entry;
	}
	return json_encode($out);
}

/* Restore a backup: re-insert any client entry that is not already present
   (matched by description), with its interface assignment + gateways. */
function vpp_restore($json) {
	$data = json_decode($json, true);
	if (!is_array($data) || ($data['format'] ?? '') !== 'pfsense-vpn-providers') {
		return array('error' => 'not a vpn-providers backup file');
	}
	$a_client = (array)config_get_path('openvpn/openvpn-client', array());
	$have = array();
	foreach ($a_client as $c) {
		if (($c['description'] ?? '') !== '') {
			$have[$c['description']] = true;
		}
	}
	$reg = vpp_registry();
	$added = 0;
	foreach ((array)($data['clients'] ?? array()) as $entry) {
		$client = $entry['client'] ?? array();
		$descr = (string)($client['description'] ?? '');
		if ($descr === '' || isset($have[$descr])) {
			continue;
		}
		$a_client[] = $client;
		$have[$descr] = true;
		$iface = $entry['interface'] ?? array();
		if (is_array($iface) && !empty($iface['if'])) {
			foreach ((array)($entry['registry'] ?? array()) as $k => $v) {
				if ($k === 'opt' && $v !== '') {
					config_set_path("interfaces/{$v}", $iface);
				}
			}
		}
		foreach ((array)($entry['gateways'] ?? array()) as $gw) {
			$a_gw = (array)config_get_path('gateways/gateway_item', array());
			$a_gw[] = $gw;
			config_set_path('gateways/gateway_item', $a_gw);
		}
		if (!empty($entry['registry']['description']) && !isset($reg[$entry['registry']['uid'] ?? ''])) {
			$r = $entry['registry'];
			$r['uid'] = $r['uid'] ?? uniqid('pvd');
			$reg[$r['uid']] = $r;
		}
		$added++;
	}
	config_set_path('openvpn/openvpn-client', $a_client);
	if ($added > 0) {
		vpp_registry_save($reg);
		write_config('VPN Providers: restored ' . $added . ' client(s)');
	}
	return array('added' => $added);
}
