#!/bin/sh
# vpn_providers package setup: registers the menu + package entry in
# config.xml. No service: the manager is UI/CLI driven and gated by
# apply_live (default no = dry-run).

PKG_BASE="/usr/local/pfsense_vpn_providers"

PATH="/usr/local/sbin:/usr/local/bin:${PATH}"
export PATH

register_config() {
  php <<'PHP'
<?php
global $config;
require_once("/etc/inc/config.inc");
$config = parse_config(true);

$menus = config_get_path('installedpackages/menu', []);
$found = false;
$menu = array(
    'name' => 'VPN Providers',
    'tooltiptext' => 'Add and remove VPN provider clients (OpenVPN)',
    'section' => 'VPN',
    'url' => '/packages/vpn_providers/providers.php'
);
foreach ($menus as $k => $m) {
    if (isset($m['name']) && $m['name'] == $menu['name']) {
        $menus[$k] = $menu;
        $found = true;
        break;
    }
}
if (!$found) {
    $menus[] = $menu;
}
config_set_path('installedpackages/menu', $menus);

write_config("Installed pfSense-pkg-vpn-providers: registered menu");
PHP
}

unregister_config() {
  php <<'PHP'
<?php
global $config;
require_once("/etc/inc/config.inc");
$config = parse_config(true);

$menus = config_get_path('installedpackages/menu', []);
$out = array();
foreach ($menus as $m) {
    if (isset($m['name']) && $m['name'] == 'VPN Providers') {
        continue;
    }
    $out[] = $m;
}
config_set_path('installedpackages/menu', $out);

write_config("Removed pfSense-pkg-vpn-providers: unregistered menu");
PHP
}

register_package() {
  php <<'PHP' 2>/dev/null
<?php
global $config;
require_once("/etc/inc/config.inc");
require_once("/etc/inc/pkg-utils.inc");
$config = parse_config(true);
install_package_xml("vpn-providers");
PHP
}

unregister_package() {
  php <<'PHP' 2>/dev/null
<?php
global $config;
require_once("/etc/inc/config.inc");
require_once("/etc/inc/pkg-utils.inc");
$config = parse_config(true);
uninstall_package_xml("vpn-providers");
PHP
}

case "$1" in
install)
  mkdir -p "${PKG_BASE}/etc" /var/db/pfsense_vpn_providers
  register_config
  register_package
  ;;
deinstall)
  unregister_package
  unregister_config
  ;;
*)
  echo "Usage: $0 install|deinstall"
  exit 1
  ;;
esac
