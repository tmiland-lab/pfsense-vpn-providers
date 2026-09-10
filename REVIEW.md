# Morning review — pfsense-vpn-providers (overnight build)

Built overnight in /home/opencode/work/pfsense-vpn-providers (per your rule:
unsupervised work stays in my area; nothing copied to ~/.github, the box, or
published). All logic is validated by a local test harness (48/48 green).

## What exists (2 commits, working tree clean)

- `share/vpn_providers_lib.php` — .ovpn parser + full setup planner
  (CA import, OpenVPN client, interface assignment, gateway items v4+v6)
  + enable/disable/remove, all gated by `apply_live` (dry-run by default)
- `bin/vpn_providers.php` — CLI: list / plan / import / airvpn / enable /
  disable / remove
- UI: VPN → VPN Providers (list + wizard + AirVPN quick-add) + settings
- Package scaffold: setup.sh, packagegui xml, build.sh, info.xml generated
- `tests/run_local.php` — offline harness with pfSense stubs (parse, plan,
  schema assertions vs your AirVPN_NL client shape, duplicate rejection,
  dry-run, in-memory apply, enable/disable, remove, bogus-input rejection)

## Safety (per your network rule)

- Clients are ALWAYS created disabled — openvpn_resync writes the config,
  no tunnel starts, no routes, no gateway-group impact
- `apply_live=no` factory default; every write path is a no-op that prints
  the plan
- Remove only touches registry-known clients ("Provider: " description prefix)
- Nothing was deployed to the box, nothing copied to ~/.github, nothing
  published

## Decisions made while you slept (overrule freely)

- Package name: pfSense-pkg-vpn-providers (repo pfsense-vpn-providers)
- Menu under VPN → VPN Providers (not a service — manager only)
- AirVPN quick-add reuses the installed AirVPN_CA + your tls-crypt key and
  targets <cc>3.vpn.airdns.org; remote lists stay with the airvpn-remotes
  monitor package
- Gateways PVD_<NAME>_V4/_V6 (your AIRVPN_*_VPNV4 pattern)
- data-ciphers parsed colon-separated (OpenVPN spec), keepalive mapped to
  ping_method fields like your existing clients

## Awaiting your go (2 items)

1. Copy repo → ~/.github/pfsense-vpn-providers (replaces the leftover stub
   dir from before your file-access rule - safe to delete it)
2. Deploy + validate on the box:
   a. scp + php -l (or just build there)
   b. CLI `plan` against a real AirVPN-style .ovpn (read-only, prints plan)
   c. pkg install → menu appears → UI render check (temp user, like before)
   d. OPTIONAL (needs your OK, still zero network impact): one real
      `apply_live=yes` create of a DISABLED "Provider: Test SE" client +
      config inspection + removal — proves the write path live
   e. enable-the-tunnel is your click, not mine

## Not done yet (by design)

- Mullvad/Proton direct-download providers (import path covers them manually)
- pfSense API-based creation (GUI-recipe PHP used instead - no RESTAPI
  framework dependency)
- Publish / community-repo ingestion (after your review)
