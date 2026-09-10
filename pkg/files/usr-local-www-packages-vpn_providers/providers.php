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
		$tls = vpp_post('tlskey');
		$ipv = (vpp_post('ipv4') === '1' ? '4' : '') . (vpp_post('ipv6') === '1' ? '6' : '');
		$enabled = vpp_post('enabled') === '1';
		/* routing extras: gateway group membership + priority, outbound NAT */
		$opt_group = vpp_post('gwgroup_existing');
		if ($opt_group === '__new__') {
			$opt_group = trim(vpp_post('gwgroup_new'));
		}
		$gw_opts = array('gateway_group' => $opt_group, 'gateway_group_weight' => vpp_post('gwgroup_prio'));
		$nat_src = vpp_post('natout_src');
		if (vpp_post('natout') === '1') {
			if (trim($nat_src) === '') {
				$nat_src = vpp_lan_cidr();
			}
			$gw_opts['nat_outbound'] = 1;
			$gw_opts['nat_src'] = trim($nat_src);
		}
		if ($name === '') {
			$input_errors[] = gettext('Name is required.');
		}
		if (!in_array($ipv, array('4', '6', '46'), true)) {
			$input_errors[] = gettext('Pick at least one IP protocol - IPv4, IPv6 or both.');
		}
		if (trim($tls) === '') {
			/* reuse the shared AirVPN tls-crypt key from an existing tunnel */
			$tls = vpp_airvpn_tlskey();
		}
		if ($name === '' || trim($tls) === '') {
			$input_errors[] = gettext('The tls-crypt key is required - paste the &lt;tls-crypt&gt; block from an AirVPN .ovpn export.');
		} else {
			$servers = vpp_airvpn_servers();
			if (isset($servers['error'])) {
				/* fall back to the manual country-code host when the API is unavailable */
				$cc = strtoupper(trim(vpp_post('country')));
				if ($cc === '' || !preg_match('/^[A-Z]{2}$/', $cc)) {
					$input_errors[] = gettext('Pick a server from the list, or enter a 2-letter country code.');
				} else {
					$host = strtolower($cc) . '3.vpn.airdns.org';
					$parsed = vpp_parse_ovpn("client\nremote {$host} 443 udp4\nauth-user-pass\n");
					$parsed['tls'] = trim($tls) . "\n";
					$parsed['tls_type'] = 'crypt';
					$plan = vpp_plan_create($name, $parsed, array('provider' => 'airvpn', 'ipv' => $ipv, 'create_enabled' => $enabled) + $gw_opts);
					if (isset($plan['error'])) {
						$input_errors[] = $plan['error'];
					} else {
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
			} else {
				$host = vpp_post('server');
				$valid = array();
				foreach ($servers['list'] as $s) {
					$valid[$s['host']] = $s;
				}
				if (!isset($valid[$host])) {
					$input_errors[] = gettext('Pick a server from the list.');
				} else {
					$srv = $valid[$host];
					/* connect by entry IP (what the AirVPN monitor uses) - public_name
					   is a display name, not always a resolvable connect hostname */
					$remote = $srv['ip'] !== '' ? $srv['ip'] : $srv['host'];
					$parsed = vpp_parse_ovpn("client\nremote {$remote} 443 udp4\nauth-user-pass\n");
					$parsed['tls'] = trim($tls) . "\n";
					$parsed['tls_type'] = 'crypt';
					$plan = vpp_plan_create($name, $parsed, array('provider' => 'airvpn', 'ipv' => $ipv, 'create_enabled' => $enabled) + $gw_opts);
					if (isset($plan['error'])) {
						$input_errors[] = $plan['error'];
					} else {
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
							$done = array('dryrun', sprintf(gettext('Dry run - nothing written. Enable "apply changes live" in settings to create: %s (%s)'), $plan['description'], $remote));
						} else {
							$done = array('ok', sprintf(gettext('Created %s (%s, disabled).'), $plan['description'], $remote));
						}
					}
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
	} elseif ($action === 'adopt') {
		$added = vpp_adopt_existing();
		$done = array('ok', sprintf(gettext('Adopted %d existing provider client(s).'), $added));
	} elseif ($action === 'backup') {
		$data = vpp_backup();
		header('Content-Type: application/json');
		header('Content-Disposition: attachment; filename="vpn-providers-backup.json"');
		echo $data;
		exit;
	} elseif ($action === 'restore' && isset($_FILES['restorefile']['tmp_name']) && is_uploaded_file($_FILES['restorefile']['tmp_name'])) {
		$r = vpp_restore(file_get_contents($_FILES['restorefile']['tmp_name']));
		if (isset($r['error'])) {
			$input_errors[] = $r['error'];
		} else {
			$done = array('ok', sprintf(gettext('Restored %d client(s).'), $r['added']));
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
		<form method="post" style="display:inline">
			<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['request_token']) ?>" />
			<input type="hidden" name="action" value="adopt" />
			<button type="submit" class="btn btn-xs btn-info"><?= gettext('Adopt existing provider clients') ?></button>
		</form>
		<form method="post" style="display:inline" enctype="multipart/form-data">
			<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['request_token']) ?>" />
			<input type="hidden" name="action" value="backup" />
			<button type="submit" class="btn btn-xs btn-default"><?= gettext('Backup clients') ?></button>
		</form>
		<form method="post" style="display:inline" enctype="multipart/form-data">
			<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['request_token']) ?>" />
			<input type="hidden" name="action" value="restore" />
			<input type="file" name="restorefile" class="btn btn-xs" accept="application/json" />
			<button type="submit" class="btn btn-xs btn-default" <?= $apply_live ? '' : 'disabled' ?>><?= gettext('Restore clients') ?></button>
		</form>
		<p class="text-muted" style="margin-top:8px"><?= gettext('Backup exports the provider client entries (client, interface, gateways) as JSON. Restore re-inserts any that are missing. Adopt registers existing AirVPN / Provider clients so they can be managed here.') ?></p>
		<table class="table table-striped table-hover">
			<thead>
				<tr>
					<th><?= gettext('Name') ?></th>
					<th><?= gettext('Server') ?></th>
					<th><?= gettext('Interface') ?></th>
					<th><?= gettext('Gateways') ?></th>
					<th><?= gettext('Routing') ?></th>
					<th><?= gettext('State') ?></th>
					<th><?= gettext('Actions') ?></th>
				</tr>
			</thead>
			<tbody>
<?php $rows = vpp_list(); ?>
<?php if (empty($rows)): ?>
				<tr><td colspan="7" class="text-muted"><?= gettext('No provider clients yet - add one below.') ?></td></tr>
<?php else: foreach ($rows as $e): ?>
				<tr>
					<td><?= htmlspecialchars($e['name']) ?></td>
					<td><?= htmlspecialchars($e['server']) ?></td>
					<td><?= htmlspecialchars(($e['opt'] ?? '') . ' / ovpnc' . $e['vpnid']) ?></td>
					<td><?= htmlspecialchars(implode(', ', (array)($e['gateways'] ?? array()))) ?></td>
					<td><?php
						$bits = array();
						if (!empty($e['groups'])) {
							$bits[] = gettext('grp') . ': ' . implode(', ', array_keys($e['groups']));
						}
						if (!empty($e['nat'])) {
							$bits[] = gettext('NAT');
						}
						echo empty($bits) ? '<span class="text-muted">-</span>' : htmlspecialchars(implode('<br />', $bits));
					?></td>
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
	<div class="panel-heading"><h2 class="panel-title"><?= gettext('AirVPN quick add') ?></h2></div>
	<div class="panel-body">
<?php $airvpn_servers = vpp_airvpn_servers(isset($_GET['refresh_servers']) && $_GET['refresh_servers'] === '1'); ?>
<?php $airvpn_tlskey = vpp_airvpn_tlskey(); ?>
<?php if (isset($airvpn_servers['error'])): ?>
		<?= print_info_box(htmlspecialchars($airvpn_servers['error']) . ' - ' . gettext('falling back to a manual country code.'), 'warning') ?>
<?php endif; ?>
		<form method="post">
			<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['request_token']) ?>" />
			<input type="hidden" name="action" value="add_airvpn" />
			<table class="table">
				<tr><td style="width:25%"><strong><?= gettext('Name') ?></strong><br /><span class="text-muted"><?= gettext('auto-filled from the selected server as e.g. AirVPN_SE - you can edit it') ?></span></td>
					<td><input class="form-control" type="text" name="name" id="pvd-name" maxlength="40" autocomplete="off" required placeholder="AirVPN_SE" /></td></tr>
<?php if (isset($airvpn_servers['list'])): ?>
<?php $grouped = array(); foreach ($airvpn_servers['list'] as $s) { $grouped[$s['continent']][] = $s; } ?>
				<tr><td><strong><?= gettext('Continent') ?></strong><br /><span class="text-muted"><?= gettext('as grouped on airvpn.org/status') ?></span></td>
					<td>
						<select class="form-control" id="airvpn-continent" onchange="vppFilterContinent()">
							<option value="" selected><?= sprintf(gettext('Earth (%d servers)'), count($airvpn_servers['list'])) ?></option>
<?php foreach ($grouped as $continent => $g): ?>
							<option value="<?= htmlspecialchars($continent) ?>"><?= htmlspecialchars($continent) ?> (<?= count($g) ?>)</option>
<?php endforeach; ?>
						</select>
					</td></tr>
				<tr><td><strong><?= gettext('Server') ?></strong><br /><span class="text-muted"><?= sprintf(gettext('%d healthy servers from the AirVPN API'), count($airvpn_servers['list'])) . ' <a href="?refresh_servers=1">' . gettext('refresh') . '</a>' ?></span></td>
					<td>
						<select class="form-control" name="server" id="airvpn-server" onchange="vppDeriveName()" required>
							<option value="" selected><?= gettext('select a server') ?></option>
<?php foreach ($grouped as $continent => $g): ?>
							<optgroup label="<?= htmlspecialchars($continent) ?>">
<?php foreach ($g as $s): ?>
								<option value="<?= htmlspecialchars($s['host']) ?>" data-cc="<?= htmlspecialchars($s['cc']) ?>">[<?= htmlspecialchars($s['cc']) ?>] <?= htmlspecialchars($s['country']) ?> - <?= htmlspecialchars($s['name']) ?> (<?= (int)$s['load'] ?>%, <?= htmlspecialchars($s['ip']) ?>)</option>
<?php endforeach; ?>
							</optgroup>
<?php endforeach; ?>
						</select>
					</td></tr>
<?php else: ?>
				<tr><td><strong><?= gettext('Country code') ?></strong><br /><span class="text-muted"><?= gettext('SE, DE, US, ... - server <cc>3.vpn.airdns.org') ?></span></td>
					<td><input class="form-control" type="text" name="country" maxlength="3" autocomplete="off" required /></td></tr>
<?php endif; ?>
				<tr><td><strong><?= gettext('tls-crypt key') ?></strong><br /><span class="text-muted"><?= $airvpn_tlskey === '' ? gettext('paste the &lt;tls-crypt&gt; block from any AirVPN .ovpn export') : gettext('shared across all AirVPN servers - already configured on this box, edit only if needed') ?></span></td>
					<td><textarea class="form-control" name="tlskey" rows="5" placeholder="-----BEGIN OpenVPN Static key V1-----"><?= htmlspecialchars($airvpn_tlskey) ?></textarea></td></tr>
				<tr><td><strong><?= gettext('IP protocols') ?></strong><br /><span class="text-muted"><?= gettext('which tunnel gateways to create') ?></span></td>
					<td>
						<label class="checkbox-inline"><input type="checkbox" name="ipv4" value="1" checked /> <?= gettext('IPv4') ?></label>
						<label class="checkbox-inline"><input type="checkbox" name="ipv6" value="1" checked /> <?= gettext('IPv6') ?></label>
					</td></tr>
				<tr><td><strong><?= gettext('Start enabled') ?></strong><br /><span class="text-muted"><?= gettext('create the tunnel enabled (default: disabled)') ?></span></td>
					<td><label class="checkbox-inline"><input type="checkbox" name="enabled" value="1" /> <?= gettext('Enable immediately') ?></label></td></tr>
				<tr><td><strong><?= gettext('Gateway group') ?></strong><br /><span class="text-muted"><?= gettext('add the new gateways to a load-balancing / failover group') ?></span></td>
					<td>
						<select class="form-control" name="gwgroup_existing" id="airvpn-gwgroup-exist">
							<option value=""><?= gettext('- none -') ?></option>
<?php foreach (vpp_gateway_groups() as $gname => $gdescr): ?>
							<option value="<?= htmlspecialchars($gname) ?>"><?= htmlspecialchars($gdescr !== $gname ? $gname . ' (' . $gdescr . ')' : $gname) ?></option>
<?php endforeach; ?>
							<option value="__new__"><?= gettext('- new group -') ?></option>
						</select>
						<input class="form-control" type="text" name="gwgroup_new" id="airvpn-gwgroup-new" value="VPN_Group" style="display:none" maxlength="63" placeholder="<?= gettext('group name') ?>" />
					</td></tr>
				<tr><td><strong><?= gettext('Priority (weight)') ?></strong><br /><span class="text-muted"><?= gettext('gateway weight for the group, 1-500') ?></span></td>
					<td><input class="form-control" type="number" name="gwgroup_prio" id="airvpn-gwgroup-prio" min="1" max="500" value="1" style="width:120px" /></td></tr>
				<tr><td><strong><?= gettext('Outbound NAT') ?></strong><br /><span class="text-muted"><?= gettext('route LAN traffic out of this tunnel (advanced NAT, same style as your existing AirVPN rules)') ?></span></td>
					<td>
						<label class="checkbox-inline"><input type="checkbox" name="natout" id="airvpn-natout" value="1" /> <?= gettext('Add outbound NAT rule') ?></label>
						<input class="form-control" type="text" name="natout_src" id="airvpn-natout-src" value="<?= htmlspecialchars(vpp_lan_cidr()) ?>" style="display:none;width:200px;margin-top:6px" placeholder="LAN subnet, e.g. 192.168.1.0/24" />
					</td></tr>
			</table>
			<p class="text-muted"><?= gettext('Reuses the installed AirVPN_CA. The remote list is managed by the AirVPN Remotes monitor package once the client is enabled.') ?></p>
<?php if (isset($airvpn_servers['list'])): ?>
			<script>
function vppFilterContinent() {
	var cont = document.getElementById('airvpn-continent');
	var sel = document.getElementById('airvpn-server');
	if (!cont || !sel) { return; }
	var wanted = cont.value;
	[].forEach.call(sel.querySelectorAll('optgroup'), function (g) {
		g.style.display = (wanted === '' || g.getAttribute('label') === wanted) ? '' : 'none';
	});
	if (sel.selectedIndex < 0 || sel.options[sel.selectedIndex].parentNode.style.display === 'none') {
		sel.selectedIndex = 0;
	}
	vppDeriveName();
}
function vppDeriveName() {
	var sel = document.getElementById('airvpn-server');
	var nm = document.getElementById('pvd-name');
	if (!sel || !nm) { return; }
	var opt = sel.options[sel.selectedIndex];
	if (!opt) { return; }
	var cc = (opt.getAttribute('data-cc') || '').toUpperCase();
	if (!/^[A-Z]{2}$/.test(cc)) { return; }
	var cur = nm.value.trim();
	if (cur === '' || /^AirVPN_[A-Z]{2}$/i.test(cur)) {
		nm.value = 'AirVPN_' + cc;
	}
}
function vppGwgroupToggle() {
	var exist = document.getElementById('airvpn-gwgroup-exist');
	var fresh = document.getElementById('airvpn-gwgroup-new');
	var prio = document.getElementById('airvpn-gwgroup-prio');
	if (!exist || !fresh) { return; }
	fresh.style.display = (exist.value === '__new__') ? '' : 'none';
	if (prio) {
		prio.disabled = (exist.value === '' && fresh.value === '');
	}
}
function vppNatToggle() {
	var on = document.getElementById('airvpn-natout');
	var src = document.getElementById('airvpn-natout-src');
	if (on && src) {
		src.style.display = on.checked && src.value !== '' ? '' : 'none';
		src.disabled = !on.checked;
	}
}
var _gwe = document.getElementById('airvpn-gwgroup-exist');
if (_gwe) { _gwe.addEventListener('change', vppGwgroupToggle); vppGwgroupToggle(); }
var _nate = document.getElementById('airvpn-natout');
if (_nate) { _nate.addEventListener('change', vppNatToggle); }
</script>
<?php endif; ?>
			<button type="submit" class="btn btn-primary"><?= gettext('Create (disabled)') ?></button>
		</form>
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

<?php include("foot.inc");
