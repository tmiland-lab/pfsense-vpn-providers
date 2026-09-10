<?php
/*
 * vpn_providers.php - CLI for the VPN Providers manager.
 *
 * Usage (as root on the pfSense box):
 *   php -f vpn_providers.php list
 *   php -f vpn_providers.php import <name> <file.ovpn>      parse + plan + apply (disabled)
 *   php -f vpn_providers.php airvpn <name> <tlskeyfile> <countrycode> [ca_descr]
 *   php -f vpn_providers.php enable|disable <uid>
 *   php -f vpn_providers.php remove <uid>
 *   php -f vpn_providers.php plan <name> <file.ovpn>        print the plan only
 *
 * Writes are gated by the settings page toggle apply_live (default no);
 * with apply_live=no the import/airvpn commands only print the plan.
 */

if (PHP_SAPI !== 'cli') {
	die("CLI only\n");
}
require_once(dirname(__DIR__) . '/share/vpn_providers_lib.php');

$cmd = $argv[1] ?? '';

switch ($cmd) {
	case 'list':
		$rows = vpp_list();
		if (empty($rows)) {
			echo "No provider clients registered.\n";
			break;
		}
		printf("%-12s %-28s %-6s %-8s %-22s %-9s %s\n", 'UID', 'NAME', 'VPNC', 'STATE', 'SERVER', 'EXISTS', 'PROVIDER');
		foreach ($rows as $e) {
			printf("%-12s %-28s %-6s %-8s %-22s %-9s %s\n",
				$e['uid'], $e['name'], $e['vpnid'],
				!$e['exists'] ? 'MISSING' : ($e['disabled'] ? 'disabled' : 'enabled'),
				$e['server'], $e['exists'] ? 'yes' : 'no', $e['provider']);
		}
		break;

	case 'plan':
	case 'import':
		$name = $argv[2] ?? '';
		$file = $argv[3] ?? '';
		if ($name === '' || $file === '' || !is_readable($file)) {
			die("usage: php -f vpn_providers.php import <name> <file.ovpn>\n");
		}
		$parsed = vpp_parse_ovpn(file_get_contents($file));
		$plan = vpp_plan_create($name, $parsed, array('provider' => 'import'));
		if (isset($plan['error'])) {
			die("ERROR: {$plan['error']}\n");
		}
		echo "Plan: vpnid={$plan['vpnid']} opt={$plan['opt']} caref={$plan['caref']} server={$plan['client']['server_addr']}:{$plan['client']['server_port']} proto={$plan['client']['protocol']} tls={$plan['client']['tls_type']}\n";
		echo "custom_options:\n{$plan['client']['custom_options']}\n";
		if ($cmd === 'plan') {
			break;
		}
		$r = vpp_apply_create($plan);
		if (isset($r['error'])) { die("ERROR: {$r['error']}\n"); }
		if (isset($r['dryrun'])) { echo "DRY-RUN: nothing written (enable apply_live in settings)\n"; break; }
		echo "Created uid={$r['uid']} (disabled)\n";
		break;

	case 'airvpn':
		/* airvpn <name> <tlskeyfile> <countrycode> [ca_descr] - quick client
		   from the AirVPN status API (remotes) + an existing ta key file.
		   CA defaults to the installed AirVPN_CA. */
		$name = $argv[2] ?? '';
		$tafile = $argv[3] ?? '';
		$cc = strtoupper($argv[4] ?? '');
		$ca_descr = $argv[5] ?? 'AirVPN_CA';
		if ($name === '' || $tafile === '' || $cc === '' || !is_readable($tafile)) {
			die("usage: php -f vpn_providers.php airvpn <name> <tlskeyfile> <countrycode> [ca_descr]\n");
		}
		$key = config_get_path('installedpackages/vpn_providers/settings/airvpn_api_key', '');
		if ($key === '') {
			die("ERROR: no airvpn_api_key in settings\n");
		}
		$j = json_decode(@file_get_contents("https://airvpn.org/api/status/?key=" . urlencode($key)), true);
		if (!is_array($j) || !isset($j['servers'])) {
			die("ERROR: AirVPN status API failed\n");
		}
		/* pick the busiest country code match (xx3.vpn.airdns.org naming) */
		$host = strtolower($cc) . '3.vpn.airdns.org';
		$tls = file_get_contents($tafile);
		$parsed = vpp_parse_ovpn("client\nremote {$host} 443 udp4\nauth-user-pass\ntls-crypt\n<tls-crypt>\n{$tls}\n</tls-crypt>\n");
		$parsed['tls'] = $tls;
		$parsed['tls_type'] = 'crypt';
		$plan = vpp_plan_create($name, $parsed, array('provider' => 'airvpn'));
		if (isset($plan['error'])) {
			die("ERROR: {$plan['error']}\n");
		}
		/* reuse the existing AirVPN CA when asked for (no new CA import) */
		$existing = vpp_find_ca_by_descr($ca_descr);
		if ($existing !== '') {
			$plan['caref'] = $existing;
			$plan['client']['caref'] = $existing;
			$plan['ca_item'] = null;
		}
		echo "Plan: vpnid={$plan['vpnid']} opt={$plan['opt']} careref={$plan['client']['caref']} server={$host}:443\n";
		$r = vpp_apply_create($plan);
		if (isset($r['error'])) { die("ERROR: {$r['error']}\n"); }
		if (isset($r['dryrun'])) { echo "DRY-RUN: nothing written (enable apply_live in settings)\n"; break; }
		echo "Created uid={$r['uid']} (disabled)\n";
		break;

	case 'enable':
	case 'disable':
		$uid = $argv[2] ?? '';
		if ($uid === '') { die("usage: vpn_providers.php {$cmd} <uid>\n"); }
		$r = vpp_set_enabled($uid, $cmd === 'enable');
		echo isset($r['error']) ? "ERROR: {$r['error']}\n" : "ok\n";
		break;

	case 'remove':
		$uid = $argv[2] ?? '';
		if ($uid === '') { die("usage: vpn_providers.php remove <uid>\n"); }
		$r = vpp_remove($uid);
		echo isset($r['error']) ? "ERROR: {$r['error']}\n" : "removed\n";
		break;

	default:
		echo file_get_contents(__FILE__, false, null, 0, 0) === false ? '' : '';
		echo "Usage:\n" .
			"  vpn_providers.php list\n" .
			"  vpn_providers.php plan <name> <file.ovpn>\n" .
			"  vpn_providers.php import <name> <file.ovpn>\n" .
			"  vpn_providers.php airvpn <name> <tlskeyfile> <countrycode> [ca_descr]\n" .
			"  vpn_providers.php enable|disable <uid>\n" .
			"  vpn_providers.php remove <uid>\n";
}
