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
		/* inline blocks */
		if (preg_match('/^<(ca|cert|key|tls-auth|tls-crypt)>/i', $line, $m)) {
			$tag = strtolower($m[1]);
			$buf = '';
			while ($i < $n && stripos($lines[$i], '</' . $tag . '>') !== 0) {
				$buf .= rtrim($lines[$i]) . "\n";
				$i++;
			}
			$i++; /* closing tag */
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
	if (empty($parsed['ca_pem'])) {
		return array('error' => 'no CA block found in the .ovpn import');
	}
	if (empty($parsed['remotes'])) {
		return array('error' => 'no remote lines found in the .ovpn import');
	}

	$vpnid = vpp_next_vpnid();
	$opt = vpp_next_opt();
	$safe = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $name));

	/* CA: reuse an existing CA with our descr OR identical certificate
	   content, else import a new one */
	$ca_descr = 'PVD ' . $name . ' CA';
	$caref = vpp_find_ca_by_descr($ca_descr);
	if ($caref === '') {
		$caref = vpp_find_ca_by_crt($parsed['ca_pem']);
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
		'remote_network' => array(),
		'remote_networkv6' => array(),
		'allow_compression' => 'no',
		'auth-retry-none' => true,
		'passtos' => false,
		'route_no_pull' => false,
		'route_no_exec' => false,
		'dns_add' => false,
		'verbosity_level' => 1,
		'create_gw' => 'both',
		'ping_method' => 'keepalive',
		'keepalive_interval' => (string)$parsed['keepalive'][0],
		'keepalive_timeout' => (string)$parsed['keepalive'][1],
		'inactive_seconds' => 300,
		'disable' => true
	);
	if ($certref !== '') {
		$client['certref'] = $certref;
	}
	if (!empty($parsed['data_ciphers'])) {
		$client['data_ciphers'] = $parsed['data_ciphers'];
	}
	if (!empty($parsed['cipher']) && empty($parsed['data_ciphers'])) {
		$client['data_ciphers_fallback'] = $parsed['cipher'];
	}
	if (!empty($parsed['tls'])) {
		$client['tls'] = base64_encode($parsed['tls']);
		$client['tls_type'] = ($parsed['tls_type'] === 'crypt') ? 'crypt' : 'auth';
		$client['tlsauth_keydir'] = in_array($parsed['tls_direction'], array('0', '1')) ? $parsed['tls_direction'] : 'default';
	}

	$gwbase = 'PVD_' . $safe;
	$gateways = array(
		array(
			'name' => $gwbase . '_V4',
			'interface' => $opt,
			'ipprotocol' => 'inet',
			'gateway' => 'dynamic',
			'descr' => 'Interface ' . $gwbase . '_V4 Gateway',
			'weight' => 1,
			'attributedown' => ''
		),
		array(
			'name' => $gwbase . '_V6',
			'interface' => $opt,
			'ipprotocol' => 'inet6',
			'gateway' => 'dynamic',
			'descr' => 'Interface ' . $gwbase . '_V6 Gateway',
			'weight' => 1,
			'attributedown' => ''
		)
	);

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
		'disabled' => true
	);
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
		'gateways' => array($plan['gateways'][0]['name'], $plan['gateways'][1]['name']),
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
		$e['disabled'] = !empty($client['disable']);
		$e['server'] = ($client['server_addr'] ?? '') . ':' . ($client['server_port'] ?? '');
		$out[$uid] = $e;
	}
	return $out;
}
