<?php
/* Local test harness: stubs the pfSense config/API surface in memory,
 * then exercises the parser, planner, dry-run and apply paths.
 * Runs entirely offline: php -f tests/run_local.php
 */
error_reporting(E_ALL);

/* State dir must not touch the real /var/db in local tests */
putenv('VPP_STATE=' . sys_get_temp_dir() . '/vpp-test-state');
@mkdir(getenv('VPP_STATE'), 0755, true);
@unlink(getenv('VPP_STATE') . '/registry.json');

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
check($qplan['client']['data_ciphers'] === 'AES-256-GCM,AES-256-CBC' && $qplan['client']['data_ciphers_fallback'] === 'AES-256-CBC', 'no-cipher config gets valid data-ciphers defaults (OpenVPN 2.6 empty-line fix)');

echo "== IP protocol selection (v4 / v6 / both) ==\n";
check($plan['client']['create_gw'] === 'both' && count($plan['gateways']) === 2, 'default creates v4+v6 gateways');
$p4 = vpp_plan_create('Ipv4 Only', $qa, array('provider' => 'airvpn', 'ipv' => '4'));
check(!isset($p4['error']) && count($p4['gateways']) === 1, 'ipv4-only: one gateway');
check($p4['gateways'][0]['name'] === 'PVD_IPV4_ONLY_V4' && $p4['gateways'][0]['ipprotocol'] === 'inet', 'ipv4-only gateway is _V4/inet');
check($p4['client']['create_gw'] === 'v4only', 'ipv4-only create_gw=v4only');
$p6 = vpp_plan_create('Ipv6 Only', $qa, array('provider' => 'airvpn', 'ipv' => '6'));
check(!isset($p6['error']) && count($p6['gateways']) === 1, 'ipv6-only: one gateway');
check($p6['gateways'][0]['name'] === 'PVD_IPV6_ONLY_V6' && $p6['gateways'][0]['ipprotocol'] === 'inet6', 'ipv6-only gateway is _V6/inet6');
check($p6['client']['create_gw'] === 'v6only', 'ipv6-only create_gw=v6only');
$pb = vpp_plan_create('Ipv4 N6', $qa, array('provider' => 'airvpn', 'ipv' => '46'));
check(count($pb['gateways']) === 2 && $pb['client']['create_gw'] === 'both', 'both selected creates both');
$pb = vpp_plan_create('Ipv4 Bad', $qa, array('provider' => 'airvpn', 'ipv' => 'x'));
check(count($pb['gateways']) === 2, 'invalid ipv falls back to both');

echo "== AirVPN status API parse ==\n";
$api_json = json_encode(array('servers' => array(
	array('name' => 'Wolfsburg', 'country_code' => 'de', 'country_name' => 'Germany', 'public_name' => 'de2.vpn.airdns.org', 'health' => 'ok', 'currentload' => 33, 'ip_v4_in1' => '1.2.3.4'),
	array('name' => 'Stockholm', 'country_code' => 'se', 'country_name' => 'Sweden', 'public_name' => 'se3.vpn.airdns.org', 'health' => 'ok', 'currentload' => 12, 'ip_v4_in1' => '5.6.7.8'),
	array('name' => '', 'country_code' => 'us', 'country_name' => 'United States', 'public_name' => 'us2.vpn.airdns.org', 'health' => 'ok', 'currentload' => 5, 'ip_v4_in1' => '9.9.9.9'),
	array('name' => 'Auckland', 'country_code' => 'nz', 'country_name' => 'New Zealand', 'public_name' => 'nz2.vpn.airdns.org', 'health' => 'ok', 'currentload' => 2, 'ip_v4_in1' => '8.8.8.8'),
	array('name' => 'Downed', 'country_code' => 'nl', 'country_name' => 'Netherlands', 'public_name' => 'nl9.vpn.airdns.org', 'health' => 'down', 'currentload' => 0),
	array('name' => 'NoHost', 'country_code' => 'pt', 'country_name' => 'Portugal', 'health' => 'ok', 'currentload' => 0),
)));
$parsed_api = vpp_airvpn_parse_status($api_json);
check(!isset($parsed_api['error']), 'status parse ok');
check(count($parsed_api['list']) === 4, 'healthy+hosted servers only (4)');
check($parsed_api['list'][0]['host'] === 'us2.vpn.airdns.org' && $parsed_api['list'][0]['continent'] === 'America', 'america first (AirVPN website order)');
check($parsed_api['list'][0]['cc'] === 'US' && $parsed_api['list'][0]['load'] === 5, 'country code uppercased + load int');
check($parsed_api['list'][0]['name'] === 'us2.vpn.airdns.org', 'empty API name falls back to public_name');
check($parsed_api['list'][1]['continent'] === 'Europe' && $parsed_api['list'][1]['host'] === 'de2.vpn.airdns.org', 'Germany first within Europe');
check($parsed_api['list'][2]['host'] === 'se3.vpn.airdns.org', 'Sweden second within Europe');
check($parsed_api['list'][3]['host'] === 'nz2.vpn.airdns.org' && $parsed_api['list'][3]['continent'] === 'Oceania', 'Oceania last');
check(isset(vpp_airvpn_parse_status('{bad')[ 'error']), 'bad json -> error');
check(vpp_airvpn_continent('nl') === 'Europe' && vpp_airvpn_continent('us') === 'America' && vpp_airvpn_continent('za') === 'Africa' && vpp_airvpn_continent('ae') === 'Middle East', 'continent map: nl/us/za/ae');

echo "== AirVPN shared tls-crypt key reuse ==\n";
$key_pem = "-----BEGIN OpenVPN Static key V1-----\n". bin2hex("sharedkey") . "\n-----END OpenVPN Static key V1-----";
$other_pem = "-----BEGIN OpenVPN Static key V1-----\n". bin2hex("other-other-other") . "\n-----END OpenVPN Static key V1-----";
$GLOBALS['CFG'] = array('openvpn' => array('openvpn-client' => array(
	array('vpnid' => 3, 'description' => 'AirVPN_NO', 'tls' => base64_encode($key_pem), 'tls_type' => 'crypt'),
	array('vpnid' => 7, 'description' => 'Other VPN', 'tls' => base64_encode($other_pem), 'tls_type' => 'crypt'),
	array('vpnid' => 9, 'description' => 'No Key', 'tls_type' => 'auth'),
)));
$found = vpp_airvpn_tlskey();
check($found === $key_pem, 'reuses AirVPN-named client key');
$GLOBALS['CFG'] = array('openvpn' => array('openvpn-client' => array(
	array('vpnid' => 7, 'description' => 'Other VPN', 'tls' => base64_encode($key_pem), 'tls_type' => 'crypt'),
)));
check(vpp_airvpn_tlskey() === $key_pem, 'falls back to any tls-crypt client');
$GLOBALS['CFG'] = array();
check(vpp_airvpn_tlskey() === '', 'empty when no client has a key');

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

echo "== pfSense disable flag round-trips as empty element (isset semantics) ==\n";
$cl = config_get_path('openvpn/openvpn-client');
$cl[0]['disable'] = '';
config_set_path('openvpn/openvpn-client', $cl);
$rows = vpp_list();
$ruid = array_key_first($rows);
check(isset($rows[$ruid]['disabled']) && $rows[$ruid]['disabled'] === true, 'vpp_list reports disabled for empty-string disable flag');
$cl = config_get_path('openvpn/openvpn-client');
unset($cl[0]['disable']);
config_set_path('openvpn/openvpn-client', $cl);
$rows = vpp_list();
check($rows[array_key_first($rows)]['disabled'] === false, 'vpp_list reports enabled when disable key absent');

echo "== create_enabled=true leaves no disable key ==\n";
$planE = vpp_plan_create('AirVPN Sweden', $p, array('create_enabled' => true));
check(!isset($planE['client']['disable']), 'no disable key when create_enabled=true');

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

echo "== gateway groups ==\n";
$GLOBALS['CFG'] = array();
check(is_array(vpp_gateway_groups()) && count(vpp_gateway_groups()) === 0, 'no groups initially');
$r = vpp_gateway_group_add('VPN_Group', array('PVD_A_V4', 'PVD_A_V6'), 3);
check(isset($r['error']) === false, 'group created');
$g = vpp_gateway_groups();
check(isset($g['VPN_Group']), 'group listed');
$gg = config_get_path('gateways/gateway_group');
check(count($gg) === 1 && count($gg[0]['item']) === 2, 'group has 2 items');
check($gg[0]['item'][0] === 'PVD_A_V4|3|address', 'item format GW|weight|address');
$r = vpp_gateway_group_add('VPN_Group', array('PVD_A_V4'), 9);
$gg = config_get_path('gateways/gateway_group');
check(count($gg) === 1 && count($gg[0]['item']) === 2, 're-add dedups');
check(in_array('PVD_A_V4|9|address', $gg[0]['item'], true), 'weight updated on re-add');
check(isset(vpp_gateway_group_add('../evil', array('X'))['error']), 'bad group name rejected');
check(count(vpp_gateway_groups_for(array('PVD_A_V6'))) === 1, 'groups_for finds membership');
check(vpp_gateway_groups_strip(array('PVD_A_V6')) === true, 'strip removed gateway');
check(count(vpp_gateway_groups_for(array('PVD_A_V6'))) === 0, 'membership gone after strip');
$gg = config_get_path('gateways/gateway_group');
check(count($gg[0]['item']) === 1 && $gg[0]['item'][0] === 'PVD_A_V4|9|address', 'strip keeps the other gateway');

echo "== outbound NAT ==\n";
$r = vpp_nat_outbound_add('opt1', '192.168.1.0/24', 'PVD Test');
check(isset($r['error']) === false, 'NAT rule added');
$nr = config_get_path('nat/outbound/rule');
check(count($nr) === 1 && $nr[0]['interface'] === 'opt1' && $nr[0]['target'] === 'opt1ip', 'rule binds opt1 -> opt1ip');
check(($nr[0]['source']['network'] ?? '') === '192.168.1.0/24' && $nr[0]['ipprotocol'] === 'inet' && ($nr[0]['destination']['any'] ?? '') === '', 'rule shape matches existing AirVPN rules');
$r = vpp_nat_outbound_add('opt1', '192.168.1.0/24', 'PVD Test');
check(count((array)config_get_path('nat/outbound/rule', array())) === 1, 'duplicate rule skipped');
check(isset(vpp_nat_outbound_add('opt1', 'bogus', 'PVD X')['error']), 'bad NAT subnet rejected');
check(vpp_nat_outbound_remove('opt1', 'PVD ') === true, 'our NAT rules removed');
check(count((array)config_get_path('nat/outbound/rule', array())) === 0, 'NAT list empty');

echo "== plan opts: group + nat + ipv4-only ==\n";
$GLOBALS['CFG'] = array('interfaces' => array('lan' => array('ipaddr' => '192.168.1.1', 'subnet' => '24'), 'opt1' => array('if' => 'ovpnc2')));
$planG = vpp_plan_create('Grouper', $p, array('provider' => 'import', 'gateway_group' => 'VPN_Group', 'gateway_group_weight' => 7, 'nat_outbound' => 1, 'nat_src' => '192.168.1.0/24'));
check(!isset($planG['error']), 'plan with group+nat built: ' . ($planG['error'] ?? 'ok'));
check($planG['nat_outbound']['descr'] === 'PVD Grouper', 'nat descr tagged PVD');
$GLOBALS['WRITTEN'] = array();
$GLOBALS['CFG']['installedpackages']['vpn_providers']['settings']['apply_live'] = 'yes';
$r = vpp_apply_create($planG);
check(isset($r['ok']), 'group+nat apply ok');
check((config_get_path('gateways/gateway_group')[0]['item'][0] ?? '') === 'PVD_GROUPER_V4|7|address', 'gateway added to group with weight');
check((config_get_path('nat/outbound/rule')[0]['interface'] ?? '') === $planG['opt'], 'NAT rule created for client interface');
$regG = vpp_registry();
$planIP = vpp_plan_create('V4Only', $p, array('provider' => 'import', 'ipv' => '4'));
check(count($planIP['gateways']) === 1 && $planIP['gateways'][0]['ipprotocol'] === 'inet', 'ipv4-only plan keeps one gateway');
check($planIP['gw_names'] === array('PVD_V4ONLY_V4'), 'gw_names reflects single gateway (registry bug fix)');

echo "== adopt existing ==\n";
$GLOBALS['CFG'] = array(
	'interfaces' => array('opt3' => array('if' => 'ovpnc3')),
	'openvpn' => array('openvpn-client' => array(
		array('description' => 'AirVPN_GB', 'vpnid' => 3, 'server_addr' => 'gb3.vpn.airdns.org', 'server_port' => '443'),
		array('description' => 'Wireguard Home', 'vpnid' => 9),
	)),
	'gateways' => array('gateway_item' => array(array('name' => 'AIRVPN_GB_VPNV4', 'interface' => 'opt3'))),
);
$added = vpp_adopt_existing();
check($added === 1, 'adopted exactly the AirVPN_* client');
$reg = vpp_registry();
$ad = null;
foreach ($reg as $e) {
	if (($e['description'] ?? '') === 'AirVPN_GB') { $ad = $e; break; }
}
check($ad !== null, 'adopted entry registered');
check(($ad['name'] ?? '') === 'AirVPN_GB' && ($ad['opt'] ?? '') === 'opt3', 'adopted entry wired to opt3');
check(in_array('AIRVPN_GB_VPNV4', (array)($ad['gateways'] ?? array()), true), 'adopted gateways mapped via interface');
check(vpp_adopt_existing() === 0, 'adopt is idempotent');

echo "== backup / restore ==\n";
$backup = vpp_backup();
$data = json_decode($backup, true);
check(($data['format'] ?? '') === 'pfsense-vpn-providers', 'backup tagged with format');
check(isset($data['clients'][0]['client']['description']), 'backup contains client entry');
$GLOBALS['CFG']['openvpn']['openvpn-client'] = array();
unset($GLOBALS['CFG']['interfaces']['opt3']);
$idog = array_pop($GLOBALS['CFG']['gateways']['gateway_item']);
$r = vpp_restore($backup);
check(isset($r['error']) === false && $r['added'] === 1, 'restore re-inserted the client');
check(count((array)config_get_path('openvpn/openvpn-client', array())) === 1, 'restored client back in config');
check((config_get_path('interfaces/opt3/if') ?? '') === 'ovpnc3', 'restored interface assignment');
check((config_get_path('gateways/gateway_item')[0]['name'] ?? '') === 'AIRVPN_GB_VPNV4', 'restored gateway');
$r = vpp_restore($backup);
check($r['added'] === 0, 'restore skips existing clients');
check(isset(vpp_restore('{"nope":1}')['error']), 'garbage restore rejected');

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
