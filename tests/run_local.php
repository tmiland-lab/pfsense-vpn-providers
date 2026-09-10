<?php
/* Local test harness: stubs the pfSense config/API surface in memory,
 * then exercises the parser, planner, dry-run and apply paths.
 * Runs entirely offline: php -f tests/run_local.php
 */
error_reporting(E_ALL);

/* State dir must not touch the real /var/db in local tests */
putenv('VPP_STATE=' . sys_get_temp_dir() . '/vpp-test-state');
@mkdir(getenv('VPP_STATE'), 0755, true);

/* ---- pfSense stubs ---- */
$GLOBALS['CFG'] = array();
$GLOBALS['WRITTEN'] = array();
$GLOBALS['RESYNCED'] = array();

function config_get_path($path, $default = null) {
	$node = &$GLOBALS['CFG'];
	foreach (explode('/', trim($path, '/')) as $p) {
		if ($p === '' || !is_array($node) || !array_key_exists($p, $node)) {
			return $default;
		}
		$node = &$node[$p];
	}
	return $node;
}
function config_set_path($path, $value) {
	$parts = explode('/', trim($path, '/'));
	$last = array_pop($parts);
	$node = &$GLOBALS['CFG'];
	foreach ($parts as $p) {
		if (!isset($node[$p]) || !is_array($node[$p])) {
			$node[$p] = array();
		}
		$node = &$node[$p];
	}
	if ($last === '') { /* trailing slash = array append */
		$node[] = $value;
	} else {
		$node[$last] = $value;
	}
	return true;
}
function config_del_path($path) {
	$parts = explode('/', trim($path, '/'));
	$last = array_pop($parts);
	$node = &$GLOBALS['CFG'];
	foreach ($parts as $p) {
		if (!isset($node[$p])) { return false; }
		$node = &$node[$p];
	}
	unset($node[$last]);
	return true;
}
function write_config($msg = '') { $GLOBALS['WRITTEN'][] = $msg; return true; }
function openvpn_resync($mode, $item) { $GLOBALS['RESYNCED'][] = array($mode, $item['description'] ?? '?'); }
function openvpn_kill_client($port, $remipp, $client_id) { return true; }
function openvpn_configure($restart = false) { return true; }
function filter_configure() { return true; }

/* Load the real library */
require_once(dirname(__DIR__) . '/share/vpn_providers_lib.php');

/* ---- fixture: AirVPN-style export (tls-crypt, auth-user-pass, inline CA) ---- */
$CA = "-----BEGIN CERTIFICATE-----\nMIIBfakeCAline1\nMIIBfakeCAline2\n-----END CERTIFICATE-----\n";
$TA = "-----BEGIN OpenVPN Static key V1-----\n8888aaaabbbbccccddddeeeeffff0000\n8888aaaabbbbccccddddeeeeffff0000\n-----END OpenVPN Static key V1-----\n";
$ovpn = <<<OVPN
client
dev tun
proto udp
remote se3.vpn.airdns.org 443
remote se4.vpn.airdns.org 443
remote-random
resolv-retry infinite
nobind
persist-key
persist-tun
auth SHA512
data-ciphers AES-256-GCM:AES-128-GCM
auth-user-pass
remote-cert-tls server
verb 3
keepalive 5 30
mlock
tls-crypt
<tls-crypt>
{$TA}</tls-crypt>
<ca>
{$CA}</ca>
OVPN;

$pass = 0; $fail = 0;
function check($cond, $msg) {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  ok: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "== parse ==\n";
$p = vpp_parse_ovpn($ovpn);
check(count($p['remotes']) === 2 && $p['remotes'][0][0] === 'se3.vpn.airdns.org', 'remotes parsed');
check($p['remotes'][0][1] === '443', 'remote port');
check($p['proto'] === 'udp4', 'proto udp -> udp4');
check($p['auth'] === 'SHA512', 'digest');
check($p['data_ciphers'] === array('AES-256-GCM', 'AES-128-GCM'), 'data-ciphers split');
check($p['tls'] === $TA, 'tls-crypt key captured');
check($p['tls_type'] === 'crypt', 'tls_type=crypt');
check($p['ca_pem'] === $CA, 'ca captured');
check($p['auth_user_pass'] === true, 'auth-user-pass flag');
check($p['remote_cert_tls'] === true, 'remote-cert-tls flag');
check($p['keepalive'] === array(5, 30), 'keepalive 5/30');
check(!in_array('remote se3.vpn.airdns.org 443', $p['custom']), 'remotes not in custom passthrough');
check(in_array('mlock', $p['custom']), 'mlock in custom passthrough');

echo "== plan ==\n";
$plan = vpp_plan_create('AirVPN Sweden', $p);
check(!isset($plan['error']), 'plan builds: ' . ($plan['error'] ?? 'ok'));
check($plan['client']['description'] === 'Provider: AirVPN Sweden', 'description marker');
check($plan['client']['disable'] === true, 'created disabled');
check($plan['client']['protocol'] === 'udp4', 'protocol');
check($plan['client']['server_addr'] === 'se3.vpn.airdns.org' && $plan['client']['server_port'] === '443', 'server from first remote');
check($plan['client']['tls_type'] === 'crypt' && $plan['client']['tlsauth_keydir'] === 'default', 'tls mapping');
check(substr($plan['client']['tls'], 0, 5) !== '-----', 'tls base64-encoded');
check($plan['client']['keepalive_interval'] === '5', 'keepalive interval');
check($plan['client']['data_ciphers'] === 'AES-256-GCM,AES-128-GCM', 'data_ciphers joined string (pfSense storage format)');
check(strpos($plan['client']['custom_options'], 'remote-cert-tls server;') !== false, 'remote-cert-tls in custom_options');
check(strpos($plan['client']['custom_options'], 'mlock;') !== false, 'mlock in custom_options');
check(strpos($plan['client']['custom_options'], 'route-nopull;') === false, 'route-nopull absent');
check($plan['gateways'][0]['name'] === 'PVD_AIRVPN_SWEDEN_V4', 'gateway v4 name');
check($plan['gateways'][1]['ipprotocol'] === 'inet6', 'gateway v6');
check($plan['interface']['if'] === 'ovpnc' . $plan['vpnid'], 'interface assignment');

echo "== AirVPN quick-add reuses installed AirVPN_CA (no CA block in config) ==\n";
$airvpn_ca = array('refid' => 'aaaairvpnca01', 'descr' => 'AirVPN_CA', 'crt' => base64_encode('FAKECERT'));
$GLOBALS['CFG'] = array('ca' => array($airvpn_ca), 'openvpn' => array('openvpn-client' => array()));
$qa = vpp_parse_ovpn("client\nremote 62.102.148.141 443 udp4\nauth-user-pass\n");
$qa['tls'] = "-----BEGIN OpenVPN Static key V1-----\nfake\n-----END OpenVPN Static key V1-----\n";
$qa['tls_type'] = 'crypt';
$qplan = vpp_plan_create('AirVPN Pick', $qa, array('provider' => 'airvpn'));
check(!isset($qplan['error']), 'quick-add plan ok without CA block');
check($qplan['caref'] === 'aaaairvpnca01', 'quick-add reuses AirVPN_CA');
check($qplan['ca_item'] === null, 'no CA import planned');
check($qplan['client']['server_addr'] === '62.102.148.141', 'quick-add remote = entry IP');

echo "== AirVPN status API parse ==\n";
$api_json = json_encode(array('servers' => array(
	array('name' => 'Wolfsburg', 'country_code' => 'de', 'country_name' => 'Germany', 'public_name' => 'de2.vpn.airdns.org', 'health' => 'ok', 'currentload' => 33, 'ip_v4_in1' => '1.2.3.4'),
	array('name' => 'Stockholm', 'country_code' => 'se', 'country_name' => 'Sweden', 'public_name' => 'se3.vpn.airdns.org', 'health' => 'ok', 'currentload' => 12, 'ip_v4_in1' => '5.6.7.8'),
	array('name' => 'Downed', 'country_code' => 'nl', 'country_name' => 'Netherlands', 'public_name' => 'nl9.vpn.airdns.org', 'health' => 'down', 'currentload' => 0),
	array('name' => 'NoHost', 'country_code' => 'us', 'country_name' => 'USA', 'health' => 'ok', 'currentload' => 5),
)));
$parsed_api = vpp_airvpn_parse_status($api_json);
check(!isset($parsed_api['error']), 'status parse ok');
check(count($parsed_api['list']) === 2, 'healthy+hosted servers only (2)');
check($parsed_api['list'][0]['host'] === 'de2.vpn.airdns.org', 'sorted by country then load (Germany first)');
check($parsed_api['list'][0]['cc'] === 'DE' && $parsed_api['list'][0]['load'] === 33, 'country code uppercased + load int');
check($parsed_api['list'][1]['host'] === 'se3.vpn.airdns.org', 'Sweden second');
check(isset(vpp_airvpn_parse_status('{bad')[ 'error']), 'bad json -> error');

echo "== duplicate name rejected ==\n";
$GLOBALS['CFG'] = array('openvpn' => array('openvpn-client' => array($plan['client'])));
$dup = vpp_plan_create('AirVPN Sweden', $p);
check(isset($dup['error']), 'duplicate detected');

echo "== dry-run (apply_live=no) ==\n";
$GLOBALS['CFG'] = array();
$GLOBALS['WRITTEN'] = array();
$GLOBALS['CFG']['installedpackages']['vpn_providers']['settings']['apply_live'] = 'no';
$r = vpp_apply_create($plan);
check(isset($r['dryrun']), 'dry-run result');
check(count($GLOBALS['WRITTEN']) === 0, 'no write_config in dry-run');

echo "== apply (apply_live=yes, in-memory) ==\n";
$GLOBALS['CFG']['installedpackages']['vpn_providers']['settings']['apply_live'] = 'yes';
$r = vpp_apply_create($plan);
check(isset($r['ok']), 'created');
check(count($GLOBALS['WRITTEN']) === 1 && strpos($GLOBALS['WRITTEN'][0], 'disabled') !== false, 'write_config called with disabled note');
$cl = config_get_path('openvpn/openvpn-client');
check(count($cl) === 1 && $cl[0]['description'] === 'Provider: AirVPN Sweden', 'client stored');
check(config_get_path('ca')[0]['descr'] === 'PVD AirVPN Sweden CA', 'CA stored');
check(config_get_path('interfaces/opt1')['if'] === 'ovpnc' . $plan['vpnid'], 'interface assigned (opt1 in empty config)');
check(count(config_get_path('gateways/gateway_item')) === 2, 'two gateways stored');
check(count($GLOBALS['RESYNCED']) === 1, 'openvpn_resync called');
$reg = vpp_registry();
check(count($reg) === 1, 'registry entry written');
$uid = array_key_first($reg);

echo "== disable/enable ==\n";
$r = vpp_set_enabled($uid, true);
check(isset($r['ok']), 'enable ok');
$cl = config_get_path('openvpn/openvpn-client');
check(!isset($cl[0]['disable']), 'disable flag removed on enable');
$r = vpp_set_enabled($uid, false);
$cl = config_get_path('openvpn/openvpn-client');
check(isset($cl[0]['disable']) && $cl[0]['disable'] === true, 'disable flag back');

echo "== remove ==\n";
$r = vpp_remove($uid);
check(isset($r['ok']), 'remove ok');
check(count((array)config_get_path('openvpn/openvpn-client', array())) === 0, 'client removed');
check(count((array)config_get_path('ca', array())) === 0, 'CA removed');
check(config_get_path('interfaces/opt1') === null, 'interface removed');
check(count((array)config_get_path('gateways/gateway_item', array())) === 0, 'gateways removed');
check(count(vpp_registry()) === 0, 'registry cleaned');

echo "== bogus ovpn rejected ==\n";
$bad = vpp_parse_ovpn("client\nremote vpn.example.com 1194\n");
$bp = vpp_plan_create('NoCA Test', $bad);
check(isset($bp['error']) && strpos($bp['error'], 'CA') !== false, 'missing CA rejected');

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
