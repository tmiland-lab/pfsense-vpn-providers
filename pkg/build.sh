#!/bin/sh
# Builds pfSense-pkg-vpn-providers and pkg(8) repo metadata.
# Run ON a pfSense host (or matching FreeBSD box): sh pkg/build.sh
# Output: /tmp/pfsense-vpn-providers-repo-out (flat layout)
set -eu

PKGDIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO=$(dirname "$PKGDIR")
WORK=$(mktemp -d /tmp/pfsense-vpn-providers-build.XXXXXX)
STAGE="$WORK/stage"
META="$WORK/meta"
OUT="/tmp/pfsense-vpn-providers-repo-out"
rm -rf "$OUT"
mkdir -p "$STAGE" "$META" "$OUT"

PBASE="usr/local/pfsense_vpn_providers"
WBASE="usr/local/www/packages/vpn_providers"
mkdir -p "$STAGE/$PBASE/bin" "$STAGE/$PBASE/etc" "$STAGE/$PBASE/sbin" \
	"$STAGE/$PBASE/share" "$STAGE/$WBASE" "$STAGE/usr/local/pkg" \
	"$STAGE/var/db/pfsense_vpn_providers"

install -m 0755 "$REPO/bin/vpn_providers.php" "$STAGE/$PBASE/bin/vpn_providers.php"
install -m 0644 "$REPO/share/vpn_providers_lib.php" "$STAGE/$PBASE/share/vpn_providers_lib.php"
install -m 0644 "$PKGDIR/files/usr-local-pfsense_vpn_providers/share/vpn_providers.xml" \
	"$STAGE/$PBASE/share/vpn_providers.xml"
install -m 0644 "$PKGDIR/files/usr-local-pfsense_vpn_providers/share/vpn_providers.xml" \
	"$STAGE/usr/local/pkg/vpn_providers.xml"
install -m 0755 "$PKGDIR/files/usr-local-pfsense_vpn_providers/sbin/setup.sh" \
	"$STAGE/$PBASE/sbin/setup.sh"
for page in "$PKGDIR"/files/usr-local-www-packages-vpn_providers/*.php; do
	install -m 0644 "$page" "$STAGE/$WBASE/$(basename "$page")"
done

for page in "$STAGE/$WBASE"/*.php; do
	php -l "$page" >/dev/null
done
php -l "$STAGE/$PBASE/share/vpn_providers_lib.php" >/dev/null
php -l "$STAGE/$PBASE/bin/vpn_providers.php" >/dev/null
sh -n "$STAGE/$PBASE/sbin/setup.sh"

VERSION=$(sed -n 's/.*<version>\([^<]*\)<.*/\1/p' "$STAGE/$PBASE/share/vpn_providers.xml" | head -1)
ABI=$(pkg config abi)

mkdir -p "$STAGE/usr/local/share/pfSense-pkg-vpn-providers"
cat > "$STAGE/usr/local/share/pfSense-pkg-vpn-providers/info.xml" <<EOF
<?xml version="1.0"?>
<pfsensepkgs>
    <package>
        <name>vpn-providers</name>
        <website>https://github.com/tmiland/pfsense-vpn-providers</website>
        <descr><![CDATA[VPN provider manager for pfSense - add/remove OpenVPN clients from provider configs (import or provider quick-add), always created disabled.]]></descr>
        <version>${VERSION}</version>
        <configurationfile>vpn_providers.xml</configurationfile>
    </package>
</pfsensepkgs>
EOF
NAME="pfSense-pkg-vpn-providers"
ORIGIN="net/pfSense-pkg-vpn-providers"

cat > "$META/+POST_INSTALL" <<'EOF'
#!/bin/sh
/usr/local/pfsense_vpn_providers/sbin/setup.sh install
exit 0
EOF

cat > "$META/+PRE_DEINSTALL" <<'EOF'
#!/bin/sh
/usr/local/pfsense_vpn_providers/sbin/setup.sh deinstall
exit 0
EOF

chmod 0755 "$META/+POST_INSTALL" "$META/+PRE_DEINSTALL"

MANIFEST=$(php -r '
$stage = $argv[1]; $meta = $argv[2]; $abi = $argv[3];
$version = $argv[4]; $name = $argv[5]; $origin = $argv[6];
$files = array(); $flatsize = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = ltrim(str_replace($stage, "", $f->getPathname()), "/");
    $files["/" . $rel] = hash_file("sha256", $f->getPathname());
    $flatsize += $f->getSize();
}
$deps = array();
foreach (array("php82", "curl") as $dep) {
    $out = shell_exec("/usr/sbin/pkg query -q \"%n|%o|%v\" $dep 2>/dev/null");
    if (empty(trim($out ?? ""))) {
        $out = shell_exec("/usr/sbin/pkg query -q \"%n|%o|%v\" php83 2>/dev/null");
    }
    if (!empty(trim($out ?? ""))) {
        $parts = explode("|", trim($out));
        $deps[$parts[0]] = array("origin" => $parts[1], "version" => $parts[2]);
    }
}
$dirs = array();
foreach (array(
    "usr/local/pfsense_vpn_providers",
    "usr/local/pfsense_vpn_providers/bin",
    "usr/local/pfsense_vpn_providers/etc",
    "usr/local/pfsense_vpn_providers/sbin",
    "usr/local/pfsense_vpn_providers/share",
    "usr/local/www/packages/vpn_providers",
    "var/db/pfsense_vpn_providers"
) as $d) {
    $dirs["/" . $d] = "y";
}
$manifest = array(
    "name" => $name,
    "origin" => $origin,
    "version" => $version,
    "comment" => "VPN provider manager for pfSense",
    "desc" => "Adds and removes OpenVPN clients from VPN provider configs (.ovpn import, AirVPN quick-add). Creates client + CA + interface + gateways, always disabled first; dry-run by default.",
    "maintainer" => "kontakt@tmiland.com",
    "www" => "https://github.com/tmiland/pfsense-vpn-providers",
    "abi" => $abi,
    "arch" => $abi,
    "prefix" => "/",
    "categories" => array("pfSense"),
    "licenses" => array("MIT"),
    "flatsize" => $flatsize,
    "deps" => (object) $deps,
    "files" => (object) $files,
    "directories" => (object) $dirs,
    "scripts" => array(
        "post-install" => "+POST_INSTALL",
        "pre-deinstall" => "+PRE_DEINSTALL"
    )
);
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
' "$STAGE" "$META" "$ABI" "$VERSION" "$NAME" "$ORIGIN")

echo "$MANIFEST" > "$META/+MANIFEST"

pkg create -m "$META" -r "$STAGE" -o "$OUT" >/dev/null

PKG_FILE=$(find "$OUT" -name "*.pkg" | head -1)
REPO_OUT="$OUT"
mkdir -p "$REPO_OUT/All"
mv "$PKG_FILE" "$REPO_OUT/All/"
(cd "$REPO_OUT" && pkg repo . >/dev/null)

echo "=== Build complete: $REPO_OUT"
find "$REPO_OUT" -type f | sort
rm -rf "$WORK"
