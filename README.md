# pfsense-vpn-providers

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![pfSense](https://img.shields.io/badge/platform-pfSense%202.8-blue)](https://www.pfsense.org/)

**pfSense package that adds and removes VPN provider clients end-to-end.**

Import a provider's `.ovpn` config (or use the AirVPN quick-add) and the manager
creates the complete setup — OpenVPN client, CA import, interface assignment and
gateway items — using the same config calls the pfSense GUI uses. Removal cleans
up exactly what was created and nothing else.

## Safety model

- Clients are **always created disabled** — no tunnel, no routes, zero network impact
- **Dry-run by default**: with `apply changes live` off, the manager only prints
  the plan (dry-run is the factory default and survives upgrades)
- Only clients registered in `/var/db/pfsense_vpn_providers/registry.json`
  (description prefix `Provider: `) can ever be touched by remove
- Enabling a client is a separate, explicit action once you're ready

## Providers

- **Generic import** — paste or upload any provider's `.ovpn`: remotes, inline
  `<ca>`/`<cert>`/`<key>`, `<tls-crypt>`/`<tls-auth>` keys, ciphers, digests and
  keepalive are mapped to pfSense fields; unknown directives pass through as
  custom options
- **AirVPN quick-add** — name + country code + the tls-crypt key from any AirVPN
  export → `xx3.vpn.airdns.org` client reusing the installed AirVPN CA; the
  remote list is then managed by the [AirVPN Remotes monitor](https://github.com/tmiland-lab/pfsense-airvpn-remotes)
- More providers (Mullvad/Proton direct download) are stubbed for later — the
  import path already covers them manually

## Install (CLI)

```
pkg add https://tmiland-lab.github.io/pfsense-vpn-providers/repo/All/pfSense-pkg-vpn-providers-0.1.3.pkg
```

Then **VPN → VPN Providers**. Nothing is written until you enable
`apply changes live` in the settings.

CLI equivalent on the box:

```
php -f /usr/local/pfsense_vpn_providers/bin/vpn_providers.php plan <name> <file.ovpn>
php -f /usr/local/pfsense_vpn_providers/bin/vpn_providers.php import <name> <file.ovpn>
php -f /usr/local/pfsense_vpn_providers/bin/vpn_providers.php list
```

## License

MIT - see [LICENSE](LICENSE)
