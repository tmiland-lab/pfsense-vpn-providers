<?php
/*
 * vpn_providers - list + wizard page.
 * Creates OpenVPN clients from provider configs. Clients are always created
 * DISABLED; writes only happen when apply_live=yes in settings.
 */
require_once("guiconfig.inc");

$base = '/usr/local/pfsense_vpn_providers';
require_once($base . '/share/vpn_providers_lib.php');

$apply_live = vpp_apply_live();

$tab_array = array(
	array(gettext('Clients'), true, '/packages/vpn_providers/providers.php'),
	array(gettext('Settings'), false, '/packages/vpn_providers/settings.php'),
);

function vpp_post($key) {
	$v = $_POST[$key] ?? '';
	return str_replace(array("\r", "\0"), '', $v);
}

$done = null;
$input_errors = array();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && hash_equals($_POST['csrf'] ?? '', $_SESSION['request_token'] ?? '')) {
	$action = $_POST['action'] ?? '';

	if ($action === 'add_import') {
		$name = trim(vpp_post('name'));
		$text = vpp_post('ovpntext');
		if (isset($_FILES['ovpnfile']['tmp_name']) && is_uploaded_file($_FILES['ovpnfile']['tmp_name'])) {
			$text = file_get_contents($_FILES['ovpnfile']['tmp_name']);
		}
		if ($name === '' || trim($text) === '') {
			$input_errors[] = gettext('Name and .ovpn config are both required.');
		} else {
			$parsed = vpp_parse_ovpn($text);
			$plan = vpp_plan_create($name, $parsed, array('provider' => 'import'));
			if (isset($plan['error'])) {
				$input_errors[] = $plan['error'];
			} else {
				$r = vpp_apply_create($plan);
				if (isset($r['error'])) {
					$input_errors[] = $r['error'];
				} elseif (isset($r['dryrun'])) {
					$done = array('dryrun', sprintf(gettext('Dry run - nothing written. Enable "apply changes live" in settings to create: %s'), $plan['description']));
				} else {
					$done = array('ok', sprintf(gettext('Created %s (disabled). Enable it from the list when ready.'), $plan['description']));
				}
			}
		}
	} elseif ($action === 'add_airvpn') {
		$name = trim(vpp_post('name'));
		$cc = strtoupper(trim(vpp_post('country')));
		$tls = vpp_post('tlskey');
		if ($name === '' || $cc === '' || trim($tls) === '') {
			$input_errors[] = gettext('Name, country code and the tls-crypt key are required.');
		} else {
			$host = strtolower($cc) . '3.vpn.airdns.org';
			$parsed = vpp_parse_ovpn("client\nremote {$host} 443 udp4\nauth-user-pass\n");
			$parsed['tls'] = trim($tls) . "\n";
			$parsed['tls_type'] = 'crypt';
			$plan = vpp_plan_create($name, $parsed, array('provider' => 'airvpn'));
			if (isset($plan['error'])) {
				$input_errors[] = $plan['error'];
			} else {
				/* reuse the installed AirVPN CA instead of importing one */
				$existing = vpp_find_ca_by_descr('AirVPN_CA');
				if ($existing !== '') {
					$plan['caref'] = $existing;
					$plan['client']['caref'] = $existing;
					$plan['ca_item'] = null;
				}
				$r = vpp_apply_create($plan);
				if (isset($r['error'])) {
					$input_errors[] = $r['error'];
				} elseif (isset($r['dryrun'])) {
					$done = array('dryrun', sprintf(gettext('Dry run - nothing written. Enable "apply changes live" in settings to create: %s (%s)'), $plan['description'], $host));
				} else {
					$done = array('ok', sprintf(gettext('Created %s (%s, disabled).'), $plan['description'], $host));
				}
			}
		}
	} elseif ($action === 'enable' || $action === 'disable') {
		$r = vpp_set_enabled(vpp_post('uid'), $action === 'enable');
		if (isset($r['error'])) {
			$input_errors[] = $r['error'];
		} else {
			$done = array('ok', $action === 'enable' ? gettext('Client enabled.') : gettext('Client disabled.'));
		}
	} elseif ($action === 'remove') {
		$r = vpp_remove(vpp_post('uid'));
		if (isset($r['error'])) {
			$input_errors[] = $r['error'];
		} else {
			$done = array('ok', gettext('Client removed (client, interface, gateways, owned CA/cert).'));
		}
	}
}

$pgtitle = array(gettext('VPN'), gettext('VPN Providers'));
include("head.inc");
display_top_tabs($tab_array);
?>
<?php if (!$apply_live): ?>
	<?= print_info_box(gettext('Dry-run mode: nothing is written to the configuration. Enable "apply changes live" in the ') . '<a href="settings.php">' . gettext('settings') . '</a> ' . gettext('to create clients.'), 'info', null, true) ?>
<?php endif; ?>
<?php if ($done !== null): ?>
	<?= print_info_box(htmlspecialchars($done[1]), $done[0] === 'ok' ? 'success' : 'info', null, true) ?>
<?php endif; ?>
<?php if (!empty($input_errors)): ?>
	<?= print_input_errors($input_errors) ?>
<?php endif; ?>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?= gettext('Provider clients') ?></h2></div>
	<div class="panel-body">
		<table class="table table-striped table-hover">
			<thead>
				<tr>
					<th><?= gettext('Name') ?></th>
					<th><?= gettext('Server') ?></th>
					<th><?= gettext('Interface') ?></th>
					<th><?= gettext('Gateways') ?></th>
					<th><?= gettext('State') ?></th>
					<th><?= gettext('Actions') ?></th>
				</tr>
			</thead>
			<tbody>
<?php $rows = vpp_list(); ?>
<?php if (empty($rows)): ?>
				<tr><td colspan="6" class="text-muted"><?= gettext('No provider clients yet - add one below.') ?></td></tr>
<?php else: foreach ($rows as $e): ?>
				<tr>
					<td><?= htmlspecialchars($e['name']) ?></td>
					<td><?= htmlspecialchars($e['server']) ?></td>
					<td><?= htmlspecialchars(($e['opt'] ?? '') . ' / ovpnc' . $e['vpnid']) ?></td>
					<td><?= htmlspecialchars(implode(', ', (array)($e['gateways'] ?? array()))) ?></td>
					<td><?= !$e['exists'] ? '<span class="text-warning">' . gettext('missing') . '</span>' : ($e['disabled'] ? gettext('disabled') : '<strong class="text-success">' . gettext('enabled') . '</strong>') ?></td>
					<td>
						<form method="post" style="display:inline">
							<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['request_token']) ?>" />
							<input type="hidden" name="uid" value="<?= htmlspecialchars($e['uid']) ?>" />
							<?php if ($e['disabled']): ?>
								<button type="submit" name="action" value="enable" class="btn btn-xs btn-success" <?= $apply_live ? '' : 'disabled' ?>><?= gettext('Enable') ?></button>
							<?php else: ?>
								<button type="submit" name="action" value="disable" class="btn btn-xs btn-warning" <?= $apply_live ? '' : 'disabled' ?>><?= gettext('Disable') ?></button>
							<?php endif; ?>
							<button type="submit" name="action" value="remove" class="btn btn-xs btn-danger" <?= $apply_live ? '' : 'disabled' ?>><?= gettext('Remove') ?></button>
						</form>
					</td>
				</tr>
<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?= gettext('Add client from a provider .ovpn config') ?></h2></div>
	<div class="panel-body">
		<form method="post" enctype="multipart/form-data">
			<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['request_token']) ?>" />
			<input type="hidden" name="action" value="add_import" />
			<table class="table">
				<tr><td style="width:25%"><strong><?= gettext('Name') ?></strong><br /><span class="text-muted"><?= gettext('letters, digits, space, _ - (e.g. Mullvad SE)') ?></span></td>
					<td><input class="form-control" type="text" name="name" maxlength="40" autocomplete="off" required /></td></tr>
				<tr><td><strong><?= gettext('.ovpn file') ?></strong><br /><span class="text-muted"><?= gettext('upload or paste below') ?></span></td>
					<td><input type="file" name="ovpnfile" class="form-control" /></td></tr>
				<tr><td><strong><?= gettext('.ovpn contents') ?></strong></td>
					<td><textarea class="form-control" name="ovpntext" rows="6" placeholder="client&#10;remote vpn.example.com 1194 udp4&#10;..."></textarea></td></tr>
			</table>
			<button type="submit" class="btn btn-primary"><?= gettext('Parse and create (disabled)') ?></button>
		</form>
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?= gettext('AirVPN quick add') ?></h2></div>
	<div class="panel-body">
		<form method="post">
			<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['request_token']) ?>" />
			<input type="hidden" name="action" value="add_airvpn" />
			<table class="table">
				<tr><td style="width:25%"><strong><?= gettext('Name') ?></strong><br /><span class="text-muted"><?= gettext('e.g. AirVPN Sweden') ?></span></td>
					<td><input class="form-control" type="text" name="name" maxlength="40" autocomplete="off" required /></td></tr>
				<tr><td><strong><?= gettext('Country code') ?></strong><br /><span class="text-muted"><?= gettext('SE, DE, US, ... - server <cc>3.vpn.airdns.org') ?></span></td>
					<td><input class="form-control" type="text" name="country" maxlength="3" autocomplete="off" required /></td></tr>
				<tr><td><strong><?= gettext('tls-crypt key') ?></strong><br /><span class="text-muted"><?= gettext('paste the &lt;tls-crypt&gt; block from any AirVPN .ovpn export') ?></span></td>
					<td><textarea class="form-control" name="tlskey" rows="5" placeholder="-----BEGIN OpenVPN Static key V1-----"></textarea></td></tr>
			</table>
			<p class="text-muted"><?= gettext('Reuses the installed AirVPN_CA and the AirVPN API key from settings. The remote list is managed by the AirVPN Remotes monitor package once the client is enabled.') ?></p>
			<button type="submit" class="btn btn-primary"><?= gettext('Create (disabled)') ?></button>
		</form>
	</div>
</div>
<?php include("foot.inc");
