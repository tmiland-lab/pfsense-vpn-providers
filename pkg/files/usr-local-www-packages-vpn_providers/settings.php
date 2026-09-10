<?php
/*
 * vpn_providers settings: apply_live gate + AirVPN API key.
 * Stored in config.xml, no secrets rendered back.
 */
require_once("guiconfig.inc");

$pkg_name = 'vpn_providers';

$tab_array = array(
	array(gettext('Clients'), false, '/packages/vpn_providers/providers.php'),
	array(gettext('Settings'), true, '/packages/vpn_providers/settings.php'),
);

$fields = array(
	'apply_live'     => array('Apply changes live (write config.xml - clients are still created disabled)', 'select'),
	'airvpn_api_key' => array('AirVPN API key', 'password')
);

$defaults = array('apply_live' => 'no', 'airvpn_api_key' => '');

function vpp_settings_get($key) {
	$v = config_get_path("installedpackages/vpn_providers/settings/{$key}");
	return ($v === null) ? '' : $v;
}

$stored_key = vpp_settings_get('airvpn_api_key');

$input_errors = array();
$saved = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && hash_equals($_POST['csrf'] ?? '', $_SESSION['request_token'] ?? '')) {
	$new = array();
	foreach ($fields as $key => $f) {
		$val = trim(str_replace(array("\r", "\n", "\0"), '', $_POST[$key] ?? ''));
		if ($f[1] == 'select' && !in_array($val, array('yes', 'no'))) {
			$val = 'no';
		}
		$new[$key] = $val;
	}
	/* empty secret keeps the stored value */
	if ($new['airvpn_api_key'] === '') {
		$new['airvpn_api_key'] = $stored_key;
	}
	config_set_path("installedpackages/vpn_providers/settings", $new);
	write_config('Saved VPN Providers settings');
	$saved = true;
	$stored_key = $new['airvpn_api_key'];
}

$pgtitle = array(gettext('VPN'), gettext('VPN Providers'), gettext('Settings'));
include("head.inc");
display_top_tabs($tab_array);

if ($saved) {
	print_info_box(gettext('Settings saved.'), 'success');
}
if (vpp_settings_get('apply_live') !== 'yes') {
	print_info_box(gettext('Dry-run mode: the manager only prints plans. "apply changes live" must be set to create/enable/remove anything.'), 'warning');
}
?>
<form method="post">
	<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['request_token']) ?>" />
	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><?= gettext('VPN Providers - Settings') ?></h2></div>
		<div class="panel-body">
			<table class="table table-striped">
				<tbody>
<?php foreach ($fields as $key => $f): ?>
					<tr>
						<td style="width:40%"><?= htmlspecialchars($f[0]) ?></td>
						<td>
<?php if ($f[1] == 'select'): ?>
							<select class="form-control" name="<?= htmlspecialchars($key) ?>">
								<option value="yes" <?= vpp_settings_get($key) == 'yes' ? 'selected' : '' ?>><?= gettext('yes') ?></option>
								<option value="no" <?= vpp_settings_get($key) != 'yes' ? 'selected' : '' ?>><?= gettext('no') ?></option>
							</select>
<?php else: ?>
							<input class="form-control" type="password" name="<?= htmlspecialchars($key) ?>" value="" autocomplete="new-password" placeholder="<?= $stored_key !== '' ? gettext('(stored - leave blank to keep)') : '' ?>" />
<?php endif; ?>
						</td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="panel-footer">
			<button type="submit" class="btn btn-primary"><?= gettext('Save') ?></button>
			<a href="providers.php" class="btn btn-default"><?= gettext('VPN Providers') ?></a>
		</div>
	</div>
</form>
<?php include("foot.inc");
