#!/bin/bash

# ======================================================== #
#
# ArcPanel Control Panel Installer for Ubuntu
# https://arcpanel.org/
#
# Currently Supported Versions:
# Ubuntu 22.04, 24.04, 26.04 LTS
#
# ======================================================== #

#----------------------------------------------------------#
#                  Variables&Functions                     #
#----------------------------------------------------------#
export PATH=$PATH:/sbin
export DEBIAN_FRONTEND=noninteractive
RHOST='apt.arcpanel.org'
VERSION='ubuntu'
ARCPANEL='/usr/local/arcpanel'
LOG="/root/arcpanel_install_backups/arc_install-$(date +%d%m%Y%H%M).log"
memory=$(grep 'MemTotal' /proc/meminfo | tr ' ' '\n' | grep [0-9])
arcpanel_backups="/root/arcpanel_install_backups/$(date +%d%m%Y%H%M)"
spinner="/-\|"
os='ubuntu'
release="$(lsb_release -s -r)"
codename="$(lsb_release -s -c)"
architecture="$(arch)"
ARCPANEL_INSTALL_DIR="$ARCPANEL/install/deb"
ARCPANEL_COMMON_DIR="$ARCPANEL/install/common"
VERBOSE='no'

# Define software versions
# ArcPanel version: the version of the arcpanel package, stored as VERSION
# in arcpanel.conf (ADR-0007)
ARCPANEL_INSTALL_VER='2.4.0~alpha'

# Build the full ArcPanel version
# Split base version (2.0.0) from channel suffix (~alpha / ~beta), if present
ARCPANEL_BASE_VER="${ARCPANEL_INSTALL_VER%%~*}"
if [[ "$ARCPANEL_INSTALL_VER" == *"~"* ]]; then
	ARCPANEL_CHANNEL="~${ARCPANEL_INSTALL_VER#*~}"
else
	ARCPANEL_CHANNEL=""
fi
# Build the distro identifier
case "$os" in
	ubuntu)
		os_id="ubuntu${release}"
		;;
	debian)
		os_id="debian${release}"
		;;
	*)
		echo "Error: unsupported distribution for determining ArcPanel version ($os)"
		exit 1
		;;
esac
# Final version string, e.g.: 1.10.0-1+deb13~alpha / 1.10.0-1+ubuntu26.04
ARCPANEL_INSTALL_BUILD="${ARCPANEL_BASE_VER}-1+${os_id}${ARCPANEL_CHANNEL}"
# Pre-release packages are published in the beta repository. ARCPANEL_APT_CHANNEL
# (stable or beta) overrides the choice: CI installs the arcpanel package of a
# line between tags (~alpha) with --with-debs and takes the engine packages from
# the stable repository, since the beta repository is empty between pre-releases.
case "${ARCPANEL_APT_CHANNEL:-}" in
	stable) RPATH='' ;;
	beta) RPATH='beta/' ;;
	'')
		if [ -n "$ARCPANEL_CHANNEL" ]; then
			RPATH='beta/'
		else
			RPATH=''
		fi
		;;
	*)
		echo "Error: ARCPANEL_APT_CHANNEL must be stable or beta"
		exit 1
		;;
esac

# Supported PHP versions: the installable versions of the version catalog
# (install/common/php/versions.conf, E41). An installer started from a
# checkout reads the catalog next to it; a downloaded one reads the catalog
# of the arcpanel package once that is installed.
php_catalog_installable() {
	grep -E "^VERSION='[0-9]{1,2}\.[0-9]{1,2}' .*INSTALLABLE='yes'" "$1" 2> /dev/null | cut -d\' -f2 | sort -V
}
multiphp_v=()
if [ -f "$(dirname "$0")/common/php/versions.conf" ]; then
	mapfile -t multiphp_v < <(php_catalog_installable "$(dirname "$0")/common/php/versions.conf")
fi
multiphp_list='no'
# One of the following PHP versions is required for Roundcube / phpmyadmin
multiphp_required=("8.1" "8.2" "8.3" "8.4" "8.5")
# Default PHP version if none supplied
fpm_v="8.5"
# MariaDB version
mariadb_v="11.8"
# Node.js version
node_v="24"

# Defining software pack for all distros
software="7zip acl apache2 apache2.2-common apache2-suexec-custom apache2-utils apparmor-utils at awstats bc bind9 bsdmainutils bsdutils
  clamav-daemon cron curl dnsutils dovecot-imapd dovecot-managesieved dovecot-pop3d dovecot-sieve e2fslibs e2fsprogs
  exim4 exim4-daemon-heavy expect fail2ban flex ftp git arcpanel=${ARCPANEL_INSTALL_BUILD} arcpanel-nginx arcpanel-php arcpanel-web-terminal
  idn2 imagemagick nftables jq libapache2-mod-fcgid libapache2-mod-php$fpm_v libapache2-mod-rpaf libonig5 libzip4 lsb-release
  lsof mariadb-client mariadb-common mariadb-server mc mysql-client mysql-common mysql-server nginx nodejs openssh-server
  php$fpm_v php$fpm_v-apcu php$fpm_v-bz2 php$fpm_v-cgi php$fpm_v-cli php$fpm_v-common php$fpm_v-curl php$fpm_v-gd
  php$fpm_v-imagick php$fpm_v-imap php$fpm_v-intl php$fpm_v-ldap php$fpm_v-mbstring php$fpm_v-mysql
  php$fpm_v-pgsql php$fpm_v-pspell php$fpm_v-readline php$fpm_v-xml php$fpm_v-zip postgresql postgresql-contrib
  proftpd-core proftpd-mod-crypto quota rrdtool rsyslog util-linux spamassassin
  sysstat unzip vim-common vsftpd whois zip zstd bubblewrap restic age"

installer_dependencies="apt-transport-https ca-certificates curl dirmngr gnupg openssl software-properties-common wget sudo"

# Defining help function
help() {
	echo "Usage: $0 [OPTIONS]
  -a, --apache            Install Apache        [yes|no]  default: yes
  -w, --phpfpm            Install PHP-FPM       [yes|no]  default: yes
  -o, --multiphp          Install MultiPHP      [yes|no]  default: no
  -v, --vsftpd            Install VSFTPD        [yes|no]  default: yes
  -j, --proftpd           Install ProFTPD       [yes|no]  default: no
  -k, --named             Install BIND          [yes|no]  default: yes
  -m, --mysql             Install MariaDB       [yes|no]  default: yes
  -M, --mysql8            Install MySQL 8       [yes|no]  default: no
  -g, --postgresql        Install PostgreSQL    [yes|no]  default: no
  -x, --exim              Install Exim          [yes|no]  default: yes
  -z, --dovecot           Install Dovecot       [yes|no]  default: yes
  -Z, --sieve             Install Sieve         [yes|no]  default: no
  -c, --clamav            Install ClamAV        [yes|no]  default: yes
  -t, --spamassassin      Install SpamAssassin  [yes|no]  default: yes
  -i, --iptables          Install the firewall  [yes|no]  default: yes
  -b, --fail2ban          Install Fail2Ban      [yes|no]  default: yes
  -q, --quota             Filesystem Quota      [yes|no]  default: no
  -L, --resourcelimit     Resource Limitation   [yes|no]  default: no
  -W, --webterminal       Web Terminal          [yes|no]  default: no
  -d, --api               Activate API          [yes|no]  default: yes
  -A, --api-v1            Activate API v1       [yes|no]  default: no
  -r, --port              Change Backend Port             default: 8083
  -l, --lang              Default language                default: en
  -y, --interactive       Interactive install   [yes|no]  default: yes
  -s, --hostname          Set hostname
  -e, --email             Set admin email
  -u, --username          Set admin user
  -p, --password          Set admin password
  -D, --with-debs         Path to ArcPanel debs
  -f, --force             Force installation
  -h, --help              Print this help

  Example: bash $0 -e demo@arcpanel.org -p p4ssw0rd --multiphp yes"
	exit 1
}

# Defining file download function
download_file() {
	wget $1 -q --show-progress --progress=bar:force
}

# Defining password-gen function
gen_pass() {
	matrix=$1
	length=$2
	if [ -z "$matrix" ]; then
		matrix="A-Za-z0-9"
	fi
	if [ -z "$length" ]; then
		length=16
	fi
	head /dev/urandom | tr -dc $matrix | head -c$length
}

# Defining return code check function
check_result() {
	if [ $1 -ne 0 ]; then
		echo "Error: $2"
		exit $1
	fi
}

# Source conf in installer
source_conf() {
	while IFS='= ' read -r lhs rhs; do
		if [[ ! $lhs =~ ^\ *# && -n $lhs ]]; then
			rhs="${rhs%%^\#*}" # Del in line right comments
			rhs="${rhs%%*( )}" # Del trailing spaces
			rhs="${rhs%\'*}"   # Del opening string quotes
			rhs="${rhs#\'*}"   # Del closing string quotes
			declare -g $lhs="$rhs"
		fi
	done < $1
}

# Defining function to set default value
set_default_value() {
	eval variable=\$$1
	if [ -z "$variable" ]; then
		eval $1=$2
	fi
	if [ "$variable" != 'yes' ] && [ "$variable" != 'no' ]; then
		eval $1=$2
	fi
}

# Defining function to set default language value
set_default_lang() {
	if [ -z "$lang" ]; then
		eval lang=$1
	fi
	lang_list="ar az bg bn bs ca cs da de el en es fa fi fr hr hu id it ja ka ku ko nl no pl pt pt-br ro ru sk sq sr sv th tr uk ur vi zh-cn zh-tw"
	if ! (echo $lang_list | grep -w $lang > /dev/null 2>&1); then
		eval lang=$1
	fi
}

# Define the default backend port
set_default_port() {
	if [ -z "$port" ]; then
		eval port=$1
	fi
}

# Write configuration KEY/VALUE pair to $ARCPANEL/conf/arcpanel.conf
write_config_value() {
	local key="$1"
	local value="$2"
	echo "$key='$value'" >> $ARCPANEL/conf/arcpanel.conf
}

# Sort configuration file values
# Write final copy to $ARCPANEL/conf/arcpanel.conf for active usage
# Duplicate file to $ARCPANEL/conf/defaults/arcpanel.conf to restore known good installation values
sort_config_file() {
	sort $ARCPANEL/conf/arcpanel.conf -o /tmp/updconf
	mv $ARCPANEL/conf/arcpanel.conf $ARCPANEL/conf/arcpanel.conf.bak
	mv /tmp/updconf $ARCPANEL/conf/arcpanel.conf
	rm -f $ARCPANEL/conf/arcpanel.conf.bak
	if [ ! -d "$ARCPANEL/conf/defaults/" ]; then
		mkdir -p "$ARCPANEL/conf/defaults/"
	fi
	cp $ARCPANEL/conf/arcpanel.conf $ARCPANEL/conf/defaults/arcpanel.conf
}

# todo add check for usernames that are blocked
validate_username() {
	if [[ "$username" =~ ^[[:alnum:]][-|\.|_[:alnum:]]{0,28}[[:alnum:]]$ ]]; then
		if [ -n "$(grep ^$username: /etc/passwd /etc/group)" ]; then
			echo -e "\nUsername or Group allready exists please select a new user name or delete the user and / or group."
		else
			return 1
		fi
	else
		echo -e "\nPlease use a valid username (ex. user)."
		return 0
	fi
}

validate_password() {
	if [ -z "$vpass" ]; then
		return 0
	else
		return 1
	fi
}

# Validate hostname according to RFC1178
validate_hostname() {
	# remove extra .
	servername=$(echo "$servername" | sed -e "s/[.]*$//g")
	servername=$(echo "$servername" | sed -e "s/^[.]*//")
	if [[ $(echo "$servername" | grep -o "\." | wc -l) -gt 1 ]] && [[ ! $servername =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
		# Hostname valid
		return 1
	else
		# Hostname invalid
		return 0
	fi
}

validate_email() {
	if [[ ! "$email" =~ ^[A-Za-z0-9._%+-]+@[[:alnum:].-]+\.[A-Za-z]{2,63}$ ]]; then
		# Email invalid
		return 0
	else
		# Email valid
		return 1
	fi
}

version_ge() { test "$(printf '%s\n' "$@" | sort -V | head -n 1)" != "$1" -o -n "$1" -a "$1" = "$2"; }

# Checked downloads and APT keys (E39 W3). A copy of func/download.sh, which
# this installer cannot source before the arcpanel package is installed;
# test/supply-chain-check.sh fails when it differs from the library.
# >>> func/download.sh

# Full fingerprints of the primary keys each APT repository may be signed with,
# read by install_apt_key through APT_KEY_FPR_<NAME>
APT_KEY_FPR_ARCPANEL='0615B3DD8398E546A060B6F74DE318A606E4002A'
APT_KEY_FPR_NGINX='573BFD6B3D8FBC641079A6ABABF5BD827BD9BF62 8540A6F18833A80E9C1653A42FD21310B49F6B46 9E9BE90EACBCDE69FE9B204CBCDCD8A38D88A2B3'
APT_KEY_FPR_SURY='15058500A0235D97F5D10063B188E2B695BD4743'
APT_KEY_FPR_MARIADB='177F4010FE56CA3336300305F1656F24C74CD1D8'
APT_KEY_FPR_POSTGRESQL='B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8'
APT_KEY_FPR_NODESOURCE='6F71F525282841EEDAF851B42F59B5F99B1BE0B4'
APT_KEY_FPR_MYSQL='BCA43417C3B485DD128EC6D4B7B3B788A8D3785C'

# Downloads URL to FILE over HTTPS only
download_https() {
	curl --proto '=https' --tlsv1.2 -fsSL --retry 3 --connect-timeout 20 -o "$2" -- "$1"
}

# Downloads URL to DEST and keeps it only when its SHA-256 is SHA256
# Usage: fetch_verified URL SHA256 DEST
fetch_verified() {
	local url="$1" sum="$2" dest="$3" name tmp actual
	name="${url##*/}"
	if ! [[ "$sum" =~ ^[0-9a-f]{64}$ ]]; then
		echo "Error: no checksum pinned for $name" >&2
		return 1
	fi
	if ! tmp=$(mktemp "$dest.XXXXXX"); then
		echo "Error: cannot write $dest" >&2
		return 1
	fi
	if ! download_https "$url" "$tmp"; then
		rm -f -- "$tmp"
		echo "Error: download of $name failed ($url)" >&2
		return 1
	fi
	actual=$(sha256sum "$tmp" | cut -d' ' -f1)
	if [ "$actual" != "$sum" ]; then
		rm -f -- "$tmp"
		echo "Error: checksum mismatch for $name (expected $sum, got $actual)" >&2
		return 1
	fi
	chmod 0644 "$tmp"
	mv -f -- "$tmp" "$dest"
}

# Like fetch_verified, with the checksum looked up by file name in SUMFILE
# (sha256sum format). A DEST that already matches is kept without a download.
# Usage: fetch_pinned URL SUMFILE DEST
fetch_pinned() {
	local url="$1" sumfile="$2" dest="$3" name sum
	name="${url##*/}"
	sum=$(awk -v f="$name" '$2 == f || $2 == "*" f { print $1; exit }' "$sumfile" 2> /dev/null)
	if [ -z "$sum" ]; then
		echo "Error: no checksum pinned for $name in ${sumfile##*/}" >&2
		return 1
	fi
	if [ -f "$dest" ] && [ "$(sha256sum "$dest" | cut -d' ' -f1)" = "$sum" ]; then
		return 0
	fi
	fetch_verified "$url" "$sum" "$dest"
}

# Downloads the key of repository NAME and writes it to KEYRING only when every
# primary key in it has a fingerprint listed in APT_KEY_FPR_<NAME>
# Usage: install_apt_key NAME URL KEYRING
install_apt_key() {
	local name="$1" url="$2" keyring="$3" var allowed tmp fprs fpr ok
	var="APT_KEY_FPR_$(echo "$name" | tr '[:lower:]-' '[:upper:]_')"
	allowed="${!var}"
	if [ -z "$allowed" ]; then
		echo "Error: no fingerprint pinned for the $name key" >&2
		return 1
	fi
	if ! tmp=$(mktemp -d); then
		return 1
	fi
	if ! download_https "$url" "$tmp/key"; then
		rm -rf -- "$tmp"
		echo "Error: download of the $name key failed ($url)" >&2
		return 1
	fi
	fprs=$(GNUPGHOME="$tmp" gpg --batch --show-keys --with-colons "$tmp/key" 2> /dev/null \
		| awk -F: '$1 == "pub" { pub = 1; next } pub && $1 == "fpr" { print $10; pub = 0 }')
	ok='yes'
	[ -n "$fprs" ] || ok='no'
	for fpr in $fprs; do
		[[ " $allowed " == *" $fpr "* ]] || ok='no'
	done
	if [ "$ok" != 'yes' ]; then
		rm -rf -- "$tmp"
		echo "Error: the $name key does not match its pinned fingerprint (got: ${fprs:-none})" >&2
		return 1
	fi
	if grep -q -- '-----BEGIN PGP PUBLIC KEY BLOCK-----' "$tmp/key"; then
		GNUPGHOME="$tmp" gpg --batch --yes --dearmor -o "$tmp/keyring" "$tmp/key" 2> /dev/null
	else
		cp -f -- "$tmp/key" "$tmp/keyring"
	fi
	if [ ! -s "$tmp/keyring" ] || ! mkdir -p -- "$(dirname "$keyring")"; then
		rm -rf -- "$tmp"
		echo "Error: cannot write the $name keyring" >&2
		return 1
	fi
	chmod 0644 "$tmp/keyring"
	mv -f -- "$tmp/keyring" "$keyring"
	rm -rf -- "$tmp"
}

# <<< func/download.sh

#----------------------------------------------------------#
#                    Verifications                         #
#----------------------------------------------------------#

# Creating temporary file
tmpfile=$(mktemp -p /tmp)

# Translating argument to --gnu-long-options
for arg; do
	delim=""
	case "$arg" in
		--apache) args="${args}-a " ;;
		--phpfpm) args="${args}-w " ;;
		--vsftpd) args="${args}-v " ;;
		--proftpd) args="${args}-j " ;;
		--named) args="${args}-k " ;;
		--mysql) args="${args}-m " ;;
		--mariadb) args="${args}-m " ;;
		--mysql-classic) args="${args}-M " ;;
		--mysql8) args="${args}-M " ;;
		--postgresql) args="${args}-g " ;;
		--exim) args="${args}-x " ;;
		--dovecot) args="${args}-z " ;;
		--sieve) args="${args}-Z " ;;
		--clamav) args="${args}-c " ;;
		--spamassassin) args="${args}-t " ;;
		--iptables) args="${args}-i " ;;
		--fail2ban) args="${args}-b " ;;
		--multiphp) args="${args}-o " ;;
		--quota) args="${args}-q " ;;
		--resourcelimit) args="${args}-L " ;;
		--webterminal) args="${args}-W " ;;
		--port) args="${args}-r " ;;
		--lang) args="${args}-l " ;;
		--interactive) args="${args}-y " ;;
		--api) args="${args}-d " ;;
		--api-v1) args="${args}-A " ;;
		--hostname) args="${args}-s " ;;
		--email) args="${args}-e " ;;
		--username) args="${args}-u " ;;
		--password) args="${args}-p " ;;
		--force) args="${args}-f " ;;
		--with-debs) args="${args}-D " ;;
		--help) args="${args}-h " ;;
		*)
			[[ "${arg:0:1}" == "-" ]] || delim="'"
			args="${args}${delim}${arg}${delim} "
			;;
	esac
done
eval set -- "$args"

# Parsing arguments
while getopts "a:w:v:j:k:m:M:g:d:A:x:z:Z:c:t:i:b:r:o:q:L:l:y:s:u:e:p:W:D:fh" Option; do
	case $Option in
		a) apache=$OPTARG ;;        # Apache
		w) phpfpm=$OPTARG ;;        # PHP-FPM
		o) multiphp=$OPTARG ;;      # Multi-PHP
		v) vsftpd=$OPTARG ;;        # Vsftpd
		j) proftpd=$OPTARG ;;       # Proftpd
		k) named=$OPTARG ;;         # Named
		m) mysql=$OPTARG ;;         # MariaDB
		M) mysql8=$OPTARG ;;        # MySQL
		g) postgresql=$OPTARG ;;    # PostgreSQL
		x) exim=$OPTARG ;;          # Exim
		z) dovecot=$OPTARG ;;       # Dovecot
		Z) sieve=$OPTARG ;;         # Sieve
		c) clamd=$OPTARG ;;         # ClamAV
		t) spamd=$OPTARG ;;         # SpamAssassin
		i) iptables=$OPTARG ;;      # Iptables
		b) fail2ban=$OPTARG ;;      # Fail2ban
		q) quota=$OPTARG ;;         # FS Quota
		L) resourcelimit=$OPTARG ;; # Resource Limitation
		W) webterminal=$OPTARG ;;   # Web Terminal
		r) port=$OPTARG ;;          # Backend Port
		l) lang=$OPTARG ;;          # Language
		d) api=$OPTARG ;;           # Activate API
		A) api_v1=$OPTARG ;;        # Activate API v1
		y) interactive=$OPTARG ;;   # Interactive install
		s) servername=$OPTARG ;;    # Hostname
		e) email=$OPTARG ;;         # Admin email
		u) username=$OPTARG ;;      # Admin username
		p) vpass=$OPTARG ;;         # Admin password
		D) withdebs=$OPTARG ;;      # ArcPanel debs path
		f) force='yes' ;;           # Force install
		h) help ;;                  # Help
		*) help ;;                  # Print help (default)
	esac
done

if [ -n "$multiphp" ]; then
	if [ "$multiphp" != 'no' ] && [ "$multiphp" != 'yes' ]; then
		multiphp_version=()
		for php_version in ${multiphp//,/ }; do
			# Exact match against the catalog when it is at hand; the format
			# otherwise, and the catalog after the package is installed
			if [[ ! "$php_version" =~ ^[0-9]{1,2}\.[0-9]{1,2}$ ]] \
				|| { [ "${#multiphp_v[@]}" -gt 0 ] && ! printf '%s\n' "${multiphp_v[@]}" | grep -qxF "$php_version"; }; then
				echo "$php_version is not supported"
				exit 1
			fi
			multiphp_version+=("$php_version")
		done
		multiphp_v=("${multiphp_version[@]}")
		multiphp_list='yes'
		fpm_old=$fpm_v
		multiphp="yes"
		fpm_v=$(printf "%s\n" "${multiphp_version[@]}" | sort -V | tail -n1)
		fpm_last=$(printf "%s\n" "${multiphp_required[@]}" | sort -V | tail -n1)
		# Allow Maintainer to set minimum fpm version to make sure phpmyadmin and roundcube keep working
		if [[ -z $(echo "${multiphp_required[@]}" | fgrep -w $fpm_v) ]]; then
			if version_ge $fpm_v $fpm_last; then
				multiphp_version=(${multiphp_version[@]} $fpm_last)
				fpm_v=$fpm_last
			else
				# Roundcube and PHPmyadmin doesn't support the version selected.
				echo "Selected PHP versions are not supported any more by Dependencies..."
				exit 1
			fi
		fi

		software=$(echo "$software" | sed -e "s/php$fpm_old/php$fpm_v/g")

	fi
fi

# Defining default software stack
set_default_value 'nginx' 'yes'
set_default_value 'apache' 'yes'
set_default_value 'phpfpm' 'yes'
set_default_value 'multiphp' 'no'
set_default_value 'vsftpd' 'yes'
set_default_value 'proftpd' 'no'
set_default_value 'named' 'yes'
set_default_value 'mysql' 'yes'
set_default_value 'mysql8' 'no'
set_default_value 'postgresql' 'no'
set_default_value 'exim' 'yes'
set_default_value 'dovecot' 'yes'
set_default_value 'sieve' 'no'
if [ $memory -lt 1500000 ]; then
	set_default_value 'clamd' 'no'
	set_default_value 'spamd' 'no'
elif [ $memory -lt 3000000 ]; then
	set_default_value 'clamd' 'no'
	set_default_value 'spamd' 'yes'
else
	set_default_value 'clamd' 'yes'
	set_default_value 'spamd' 'yes'
fi
set_default_value 'iptables' 'yes'
set_default_value 'fail2ban' 'yes'
set_default_value 'quota' 'no'
set_default_value 'resourcelimit' 'no'
set_default_value 'webterminal' 'no'
set_default_value 'interactive' 'yes'
set_default_value 'api' 'yes'
set_default_value 'api_v1' 'no'
set_default_port '8083'
set_default_lang 'en'

# Checking software conflicts
if [ "$proftpd" = 'yes' ]; then
	vsftpd='no'
fi
if [ "$exim" = 'no' ]; then
	clamd='no'
	spamd='no'
	dovecot='no'
fi
if [ "$dovecot" = 'no' ]; then
	sieve='no'
fi
if [ "$iptables" = 'no' ]; then
	fail2ban='no'
fi
if [ "$apache" = 'no' ]; then
	phpfpm='yes'
fi
if [ "$mysql" = 'yes' ] && [ "$mysql8" = 'yes' ]; then
	mysql='no'
fi

# Checking root permissions
if [ "x$(id -u)" != 'x0' ]; then
	check_result 1 "Script can be run executed only by root"
fi

if [ -d "/usr/local/arcpanel" ]; then
	check_result 1 "ArcPanel install detected. Unable to continue"
fi

type=$(grep "^ID=" /etc/os-release | cut -f 2 -d '=')
if [ "$type" = "debian" ]; then
	check_result 1 "You are running the wrong installer for Debian. Please run arcpanel-install.sh or arcpanel-install-debian.sh instead."
elif [ "$type" != "ubuntu" ]; then
	check_result 1 "You are running an unsupported OS."
fi

# Packages are built for amd64 and arm64, but installs are supported on amd64 only.
# CI and testers can set ARCPANEL_ALLOW_UNSUPPORTED_ARCH=yes to install on arm64
# (E47, docs/features/specs/2026-10-20-003-e47-arm64-packages-design.md §5.5).
check_install_arch() {
	if [ "$1" = 'aarch64' ]; then
		if [ "$ARCPANEL_ALLOW_UNSUPPORTED_ARCH" != 'yes' ]; then
			check_result 1 "arm64 (aarch64) is not supported for now: ArcPanel is installed on amd64 only."
		fi
		echo "WARNING: installing on arm64 (aarch64), which is not supported."
	fi
}
check_install_arch "$architecture"

# Clear the screen once launch permissions have been verified
clear

# Configure apt to retry downloading on error
if [ ! -f /etc/apt/apt.conf.d/80-retries ]; then
	echo "APT::Acquire::Retries \"3\";" > /etc/apt/apt.conf.d/80-retries
fi

# Welcome message
echo "Welcome to the ArcPanel installer!"
echo
echo "Please wait, the installer is now checking for missing dependencies..."
echo

# Update apt repository
apt-get -qq update

# Creating backup directory
mkdir -p "$arcpanel_backups"

# Pre-install packages
echo "[ * ] Installing dependencies..."
apt-get -y install $installer_dependencies >> $LOG
check_result $? "Package installation failed, check log file for more details."

# Check repository availability
wget --quiet "https://$RHOST/${RPATH}" -O /dev/null
check_result $? "Unable to connect to the ArcPanel APT repository (https://$RHOST/${RPATH}). Check the network, or install offline with --with-debs"

# Check installed packages
tmpfile=$(mktemp -p /tmp)
dpkg --get-selections > $tmpfile
conflicts_pkg="exim4 mariadb-server apache2 nginx hestia arcpanel postfix ufw"

# Drop postfix from the list if exim should not be installed
if [ "$exim" = 'no' ]; then
	conflicts_pkg=$(echo $conflicts_pkg | sed 's/postfix//g' | xargs)
fi

for pkg in $conflicts_pkg; do
	if [ -n "$(grep $pkg $tmpfile)" ]; then
		conflicts="$pkg* $conflicts"
	fi
done
rm -f $tmpfile
if [ -n "$conflicts" ] && [ -z "$force" ]; then
	echo '!!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!!'
	echo
	echo 'WARNING: The following packages are already installed'
	echo "$conflicts"
	echo
	echo 'It is highly recommended that you remove them before proceeding.'
	echo
	echo '!!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!!'
	echo
	read -p 'Would you like to remove the conflicting packages? [y/N] ' answer
	if [ "$answer" = 'y' ] || [ "$answer" = 'Y' ]; then
		apt-get -qq purge $conflicts -y
		check_result $? 'apt-get remove failed'
		unset $answer
	else
		check_result 1 "ArcPanel should be installed on a clean server."
	fi
fi

# Check network configuration
if [ -d /etc/netplan ] && [ -z "$force" ]; then
	if [ -z "$(ls -A /etc/netplan)" ]; then
		echo '!!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!!'
		echo
		echo 'WARNING: Your network configuration may not be set up correctly.'
		echo 'Details: The netplan configuration directory is empty.'
		echo ''
		echo 'You may have a network configuration file that was created using'
		echo 'systemd-networkd.'
		echo ''
		echo 'It is strongly recommended to migrate to netplan, which is now the'
		echo 'default network configuration system in newer releases of Ubuntu.'
		echo ''
		echo 'While you can leave your configuration as-is, please note that you'
		echo 'will not be able to use additional IPs properly.'
		echo ''
		echo 'If you wish to continue and force the installation,'
		echo 'run this script with -f option:'
		echo "Example: bash $0 --force"
		echo
		echo '!!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!! !!!'
		echo
		#check_result 1 "Unable to detect netplan configuration."

		echo "Unable to detect netplan configuration."
		echo

		read -p 'Would you like to continue without netplan? [Y/n]: ' answer

		if [ "$answer" != 'y' ] && [ "$answer" != 'Y' ] && [ "$answer" != '' ]; then
			exit 1
		fi
	fi
fi

# Validate whether installation script matches release version before continuing with install
if [ -z "$withdebs" ] || [ ! -d "$withdebs" ]; then
	release_branch_ver=$(curl -s https://raw.githubusercontent.com/arc-panel-oss/arcpanel/release/src/deb/arcpanel/control | grep "Version:" | awk '{print $2}')
	if [ "$ARCPANEL_INSTALL_VER" != "$release_branch_ver" ]; then
		echo
		echo -e "\e[91mInstallation aborted\e[0m"
		echo "===================================================================="
		echo -e "\e[33mERROR: Install script version does not match package version!\e[0m"
		echo -e "\e[33mPlease download the installer from the release branch in order to continue:\e[0m"
		echo ""
		echo -e "\e[33mhttps://arcpanel.org/install.sh\e[0m"
		echo ""
		echo -e "\e[33mTo test pre-release versions, build the .deb packages and re-run the installer:\e[0m"
		echo -e "  \e[33m./arcpanel-build.sh \e[1m--arcpanel branchname no\e[21m\e[0m"
		echo -e "  \e[33m./arcpanel-install.sh .. \e[1m--with-debs /tmp/arcpanel-src/deb\e[21m\e[0m"
		echo ""
		check_result 1 "Installation aborted"
	fi
fi

case $architecture in
	x86_64)
		ARCH="amd64"
		;;
	aarch64)
		ARCH="arm64"
		;;
	*)
		echo
		echo -e "\e[91mInstallation aborted\e[0m"
		echo "===================================================================="
		echo -e "\e[33mERROR: $architecture is currently not supported!\e[0m"
		echo -e "\e[33mPlease verify the achitecture used is currenlty supported\e[0m"
		echo ""
		echo -e "\e[33mhttps://github.com/arc-panel-oss/arcpanel/blob/main/README.md\e[0m"
		echo ""
		check_result 1 "Installation aborted"
		;;
esac

#----------------------------------------------------------#
#                       Brief Info                         #
#----------------------------------------------------------#

install_welcome_message() {
	DISPLAY_VER=$(echo $ARCPANEL_INSTALL_VER | sed "s|~alpha||g" | sed "s|~beta||g" | sed "s|~rc[0-9]*||g")
	echo
	cat <<- 'ARCPANEL_LOGO'
		              _                 ____                       _
		             / \    _ __   ___ |  _ \   __ _  _ __    ___ | |
		            / _ \  | '__| / __|| |_) | / _` || '_ \  / _ \| |
		           / ___ \ | |   | (__ |  __/ | (_| || | | ||  __/| |
		          /_/   \_\|_|    \___||_|     \__,_||_| |_| \___||_|
	ARCPANEL_LOGO
	echo "                                                                        "
	echo "                                ArcPanel                                "
	if [[ "$ARCPANEL_INSTALL_VER" =~ "rc" ]]; then
		echo "                             RELEASE CANDIDATE                      "
	fi
	if [[ "$ARCPANEL_INSTALL_VER" =~ "beta" ]]; then
		echo "                              BETA RELEASE                          "
	fi
	if [[ "$ARCPANEL_INSTALL_VER" =~ "alpha" ]]; then
		echo "                          DEVELOPMENT SNAPSHOT                      "
		echo "                    NOT INTENDED FOR PRODUCTION USE                 "
		echo "                          USE AT YOUR OWN RISK                      "
	fi
	echo "                                  ${DISPLAY_VER}                        "
	echo "                              arcpanel.org                              "
	echo "                           Based on HestiaCP                            "
	echo
	echo "========================================================================"
	echo
	echo "Thank you for downloading ArcPanel! In a few moments,"
	echo "we will begin installing the following components on your server:"
	echo
}

# Printing nice ASCII logo
clear
install_welcome_message

# Web stack
echo '   - NGINX Web / Proxy Server'
if [ "$apache" = 'yes' ]; then
	echo '   - Apache Web Server (as backend)'
fi
if [ "$phpfpm" = 'yes' ] && [ "$multiphp" = 'no' ]; then
	echo '   - PHP-FPM Application Server'
fi
if [ "$multiphp" = 'yes' ]; then
	phpfpm='yes'
	echo -n '   - Multi-PHP Environment: Version'
	if [ "${#multiphp_v[@]}" -eq 0 ]; then
		echo -n ' every installable version'
	fi
	for version in "${multiphp_v[@]}"; do
		echo -n " php$version"
	done
	echo ''
fi

# DNS stack
if [ "$named" = 'yes' ]; then
	echo '   - Bind DNS Server'
fi

# Mail stack
if [ "$exim" = 'yes' ]; then
	echo -n '   - Exim Mail Server'
	if [ "$clamd" = 'yes' ] || [ "$spamd" = 'yes' ]; then
		echo -n ' + '
		if [ "$clamd" = 'yes' ]; then
			echo -n 'ClamAV '
		fi
		if [ "$spamd" = 'yes' ]; then
			if [ "$clamd" = 'yes' ]; then
				echo -n '+ '
			fi
			echo -n 'SpamAssassin'
		fi
	fi
	echo
	if [ "$dovecot" = 'yes' ]; then
		echo -n '   - Dovecot POP3/IMAP Server'
		if [ "$sieve" = 'yes' ]; then
			echo -n '+ Sieve'
		fi
	fi
fi

echo

# Database stack
if [ "$mysql" = 'yes' ]; then
	echo '   - MariaDB Database Server'
fi
if [ "$mysql8" = 'yes' ]; then
	echo '   - MySQL8 Database Server'
fi
if [ "$postgresql" = 'yes' ]; then
	echo '   - PostgreSQL Database Server'
fi

# FTP stack
if [ "$vsftpd" = 'yes' ]; then
	echo '   - Vsftpd FTP Server'
fi
if [ "$proftpd" = 'yes' ]; then
	echo '   - ProFTPD FTP Server'
fi

if [ "$webterminal" = 'yes' ]; then
	echo '   - Web terminal'
fi

# Firewall stack
if [ "$iptables" = 'yes' ]; then
	echo -n '   - Firewall (nftables)'
fi
if [ "$iptables" = 'yes' ] && [ "$fail2ban" = 'yes' ]; then
	echo -n ' + Fail2Ban Access Monitor'
fi
echo -e "\n"
echo "========================================================================"
echo -e "\n"

# Asking for confirmation to proceed
if [ "$interactive" = 'yes' ]; then
	read -p 'Would you like to continue with the installation? [y/N]: ' answer
	if [ "$answer" != 'y' ] && [ "$answer" != 'Y' ]; then
		echo 'Goodbye'
		exit 1
	fi
fi

# Validate Username / Password / Email / Hostname even when interactive = no
if [ -z "$username" ]; then
	while validate_username; do
		read -p 'Please enter administrator username: ' username
	done
else
	if validate_username; then
		exit 1
	fi
fi

# Ask for password
if [ -z "$vpass" ]; then
	while validate_password; do
		read -p 'Please enter administrator password: ' vpass
	done
else
	if validate_password; then
		echo "Please use a valid password"
		exit 1
	fi
fi

# Asking for contact email
if [ -z "$email" ]; then
	while validate_email; do
		echo -e "\nPlease use a valid emailadress (ex. info@domain.tld)."
		read -p 'Please enter admin email address: ' email
	done
else
	if validate_email; then
		echo "Please use a valid emailadress (ex. info@domain.tld)."
		exit 1
	fi
fi

# Asking to set FQDN hostname
if [ -z "$servername" ]; then
	# Ask and validate FQDN hostname.
	read -p "Please enter FQDN hostname [$(hostname -f)]: " servername

	# Set hostname if it wasn't set
	if [ -z "$servername" ]; then
		servername=$(hostname -f)
	fi

	# Validate Hostname, go to loop if the validation fails.
	while validate_hostname; do
		echo -e "\nPlease use a valid hostname according to RFC1178 (ex. hostname.domain.tld)."
		read -p "Please enter FQDN hostname [$(hostname -f)]: " servername
	done
else
	# Validate FQDN hostname if it is preset
	if validate_hostname; then
		echo "Please use a valid hostname according to RFC1178 (ex. hostname.domain.tld)."
		exit 1
	fi
fi

# Generating admin password if it wasn't set
displaypass="The password you chose during installation."
if [ -z "$vpass" ]; then
	vpass=$(gen_pass)
	displaypass=$vpass
fi

# Set FQDN if it wasn't set
mask1='(([[:alnum:]](-?[[:alnum:]])*)\.)'
mask2='*[[:alnum:]](-?[[:alnum:]])+\.[[:alnum:]]{2,}'
if ! [[ "$servername" =~ ^${mask1}${mask2}$ ]]; then
	if [[ -n "$servername" ]]; then
		servername="$servername.example.com"
	else
		servername="example.com"
	fi
	echo "127.0.0.1 $servername" >> /etc/hosts
fi

if [[ -z $(grep -i "$servername" /etc/hosts) ]]; then
	echo "127.0.0.1 $servername" >> /etc/hosts
fi

# Set email if it wasn't set
if [[ -z "$email" ]]; then
	email="admin@$servername"
fi

# Defining backup directory
echo -e "Installation backup directory: $arcpanel_backups"

# Print Log File Path
echo "Installation log file: $LOG"

# Print new line
echo

#----------------------------------------------------------#
#                      Checking swap                       #
#----------------------------------------------------------#

# Add swap for low memory servers
if [ -z "$(swapon -s)" ] && [ "$memory" -lt 1000000 ]; then
	fallocate -l 1G /swapfile
	chmod 600 /swapfile
	mkswap /swapfile
	swapon /swapfile
	echo "/swapfile	none	swap	sw	0	0" >> /etc/fstab
fi

#----------------------------------------------------------#
#                   Install repository                     #
#----------------------------------------------------------#

# Define apt conf location
apt=/etc/apt/sources.list.d

# Create new folder if it doesn't exist
mkdir -p /root/.gnupg/ && chmod 700 /root/.gnupg/

# Updating system
echo "Adding required repositories to proceed with installation:"
echo

# Installing Nginx repo
echo "[ * ] NGINX"
echo "deb [arch=$ARCH signed-by=/usr/share/keyrings/nginx-keyring.gpg] https://nginx.org/packages/mainline/$VERSION/ $codename nginx" > $apt/nginx.list
install_apt_key nginx https://nginx.org/keys/nginx_signing.key /usr/share/keyrings/nginx-keyring.gpg
check_result $? "The nginx repository key failed its check"

# Installing sury PHP repo
# add-apt-repository does not yet support signed-by see: https://bugs.launchpad.net/ubuntu/+source/software-properties/+bug/1862764
echo "[ * ] PHP"
if [[ "$release" = "22.04" ]] || [[ "$release" = "24.04" ]]; then
	if [ "$release" = '24.04' ]; then
		# Workaround for Ubuntu 24.04 due to weak key warning it sheduled to be fixed in 24.04.1
		echo 'APT::Key::Assert-Pubkey-Algo "";' > /etc/apt/apt.conf.d/99weakkey-warning
	fi
	LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php > /dev/null 2>&1
else
	# Ubuntu 26.04 and later use the Sury repository instead of a PPA
	echo "deb [arch=$ARCH signed-by=/usr/share/keyrings/sury-keyring.gpg] https://packages.sury.org/php/ $codename main" > $apt/php.list
	install_apt_key sury https://packages.sury.org/php/apt.gpg /usr/share/keyrings/sury-keyring.gpg
	check_result $? "The PHP (Sury) repository key failed its check"
fi

# Installing sury Apache2 repo
if [ "$apache" = 'yes' ] && [ "$release" = '22.04' ]; then
	echo "[ * ] Apache2"
	echo "deb http://ppa.launchpad.net/ondrej/apache2/ubuntu $codename main" > $apt/apache2.list
fi

# Installing MariaDB repo
if [ "$mysql" = 'yes' ]; then
	echo "[ * ] MariaDB $mariadb_v"
	echo "deb [arch=$ARCH signed-by=/usr/share/keyrings/mariadb-keyring.gpg] https://dlm.mariadb.com/repo/mariadb-server/$mariadb_v/repo/$VERSION $codename main" > $apt/mariadb.list
	install_apt_key mariadb https://mariadb.org/mariadb_release_signing_key.asc /usr/share/keyrings/mariadb-keyring.gpg
	check_result $? "The MariaDB repository key failed its check"
fi

# Installing ArcPanel repo
echo "[ * ] ArcPanel"
rm -f $apt/arcpanel.list
echo "deb [arch=$ARCH signed-by=/usr/share/keyrings/arcpanel-keyring.gpg] https://$RHOST/${RPATH} $codename main" > $apt/arcpanel.list
install_apt_key arcpanel "https://$RHOST/arcpanel.gpg" /usr/share/keyrings/arcpanel-keyring.gpg
check_result $? "The ArcPanel repository key failed its check"

# Installing Node.js repo
if [ "$webterminal" = 'yes' ]; then
	echo "[ * ] Node.js $node_v"
	echo "deb [arch=$ARCH signed-by=/usr/share/keyrings/nodejs.gpg] https://deb.nodesource.com/node_$node_v.x nodistro main" > $apt/nodejs.list
	install_apt_key nodesource https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key /usr/share/keyrings/nodejs.gpg
	check_result $? "The Node.js repository key failed its check"
	apt-get -y install nodejs >> $LOG
fi

# Installing PostgreSQL repo
if [ "$postgresql" = 'yes' ]; then
	echo "[ * ] PostgreSQL"
	echo "deb [arch=$ARCH signed-by=/usr/share/keyrings/postgresql-keyring.gpg] https://apt.postgresql.org/pub/repos/apt/ $codename-pgdg main" > $apt/postgresql.list
	install_apt_key postgresql https://www.postgresql.org/media/keys/ACCC4CF8.asc /usr/share/keyrings/postgresql-keyring.gpg
	check_result $? "The PostgreSQL repository key failed its check"
fi

# Echo for a new line
echo

# Updating system
echo -ne "Updating currently installed packages, please wait... "
apt-get -qq update
apt-get -y upgrade >> $LOG &
BACK_PID=$!

# Check if package installation is done, print a spinner
spin_i=1
while kill -0 $BACK_PID > /dev/null 2>&1; do
	printf "\b${spinner:spin_i++%${#spinner}:1}"
	sleep 0.5
done

# Do a blank echo to get the \n back
echo

# Check Installation result
wait $BACK_PID
check_result $? 'apt-get upgrade failed'

#----------------------------------------------------------#
#                         Backup                           #
#----------------------------------------------------------#

# Creating backup directory tree
mkdir -p $arcpanel_backups
cd $arcpanel_backups
mkdir nginx apache2 php vsftpd proftpd bind exim4 dovecot clamd
mkdir spamassassin mysql postgresql openssl arcpanel

# Backup OpenSSL configuration
cp /etc/ssl/openssl.cnf $arcpanel_backups/openssl > /dev/null 2>&1

# Backup nginx configuration
systemctl stop nginx > /dev/null 2>&1
cp -r /etc/nginx/* $arcpanel_backups/nginx > /dev/null 2>&1

# Backup Apache configuration
systemctl stop apache2 > /dev/null 2>&1
cp -r /etc/apache2/* $arcpanel_backups/apache2 > /dev/null 2>&1
rm -f /etc/apache2/conf.d/* > /dev/null 2>&1

# Backup PHP-FPM configuration
systemctl stop php*-fpm > /dev/null 2>&1
cp -r /etc/php/* $arcpanel_backups/php > /dev/null 2>&1

# Backup Bind configuration
systemctl stop bind9 > /dev/null 2>&1
cp -r /etc/bind/* $arcpanel_backups/bind > /dev/null 2>&1

# Backup Vsftpd configuration
systemctl stop vsftpd > /dev/null 2>&1
cp /etc/vsftpd.conf $arcpanel_backups/vsftpd > /dev/null 2>&1

# Backup ProFTPD configuration
systemctl stop proftpd > /dev/null 2>&1
cp /etc/proftpd/* $arcpanel_backups/proftpd > /dev/null 2>&1

# Backup Exim configuration
systemctl stop exim4 > /dev/null 2>&1
cp -r /etc/exim4/* $arcpanel_backups/exim4 > /dev/null 2>&1

# Backup ClamAV configuration
systemctl stop clamav-daemon > /dev/null 2>&1
cp -r /etc/clamav/* $arcpanel_backups/clamav > /dev/null 2>&1

# Backup SpamAssassin configuration
systemctl stop spamassassin > /dev/null 2>&1
cp -r /etc/spamassassin/* $arcpanel_backups/spamassassin > /dev/null 2>&1

# Backup Dovecot configuration
systemctl stop dovecot > /dev/null 2>&1
cp /etc/dovecot.conf $arcpanel_backups/dovecot > /dev/null 2>&1
cp -r /etc/dovecot/* $arcpanel_backups/dovecot > /dev/null 2>&1

# Backup MySQL/MariaDB configuration and data
systemctl stop mysql > /dev/null 2>&1
killall -9 mysqld > /dev/null 2>&1
mv /var/lib/mysql $arcpanel_backups/mysql/mysql_datadir > /dev/null 2>&1
cp -r /etc/mysql/* $arcpanel_backups/mysql > /dev/null 2>&1
mv -f /root/.my.cnf $arcpanel_backups/mysql > /dev/null 2>&1

# Backup ArcPanel
systemctl stop arcpanel > /dev/null 2>&1
cp -r $ARCPANEL/* $arcpanel_backups/arcpanel > /dev/null 2>&1
apt-get -y purge arcpanel arcpanel-nginx arcpanel-php > /dev/null 2>&1
rm -rf $ARCPANEL > /dev/null 2>&1

#----------------------------------------------------------#
#                     Package Includes                     #
#----------------------------------------------------------#

if [ "$phpfpm" = 'yes' ]; then
	fpm="php$fpm_v php$fpm_v-common php$fpm_v-bcmath php$fpm_v-cli
         php$fpm_v-curl php$fpm_v-fpm php$fpm_v-gd php$fpm_v-intl
         php$fpm_v-mysql php$fpm_v-soap php$fpm_v-xml php$fpm_v-zip
         php$fpm_v-mbstring php$fpm_v-bz2 php$fpm_v-pspell
         php$fpm_v-imagick"
	software="$software $fpm"
fi

#----------------------------------------------------------#
#                     Package Excludes                     #
#----------------------------------------------------------#

# Excluding packages
software=$(echo "$software" | sed -e "s/apache2.2-common//")

if [ "$apache" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/apache2 //")
	software=$(echo "$software" | sed -e "s/apache2-bin//")
	software=$(echo "$software" | sed -e "s/apache2-utils//")
	software=$(echo "$software" | sed -e "s/apache2-suexec-custom//")
	software=$(echo "$software" | sed -e "s/apache2.2-common//")
	software=$(echo "$software" | sed -e "s/libapache2-mod-rpaf//")
	software=$(echo "$software" | sed -e "s/libapache2-mod-fcgid//")
	software=$(echo "$software" | sed -e "s/libapache2-mod-php$fpm_v//")
fi
if [ "$vsftpd" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/vsftpd//")
fi
if [ "$proftpd" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/proftpd-core//")
	software=$(echo "$software" | sed -e "s/proftpd-mod-crypto//")
fi
if [ "$named" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/bind9//")
fi
if [ "$exim" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/exim4 //")
	software=$(echo "$software" | sed -e "s/exim4-daemon-heavy//")
	software=$(echo "$software" | sed -e "s/dovecot-imapd//")
	software=$(echo "$software" | sed -e "s/dovecot-pop3d//")
	software=$(echo "$software" | sed -e "s/clamav-daemon//")
	software=$(echo "$software" | sed -e "s/spamassassin//")
	software=$(echo "$software" | sed -e "s/dovecot-sieve//")
	software=$(echo "$software" | sed -e "s/dovecot-managesieved//")
fi
if [ "$clamd" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/clamav-daemon//")
fi
if [ "$spamd" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/spamassassin//")
fi
if [ "$dovecot" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/dovecot-imapd//")
	software=$(echo "$software" | sed -e "s/dovecot-pop3d//")
fi
if [ "$sieve" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/dovecot-sieve//")
	software=$(echo "$software" | sed -e "s/dovecot-managesieved//")
fi
if [ "$mysql" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/mariadb-server//")
	software=$(echo "$software" | sed -e "s/mariadb-client//")
	software=$(echo "$software" | sed -e "s/mariadb-common//")
fi
if [ "$mysql8" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/mysql-server//")
	software=$(echo "$software" | sed -e "s/mysql-client//")
	software=$(echo "$software" | sed -e "s/mysql-common//")
fi
if [ "$mysql" = 'no' ] && [ "$mysql8" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/php$fpm_v-mysql//")
	if [ "$multiphp" = 'yes' ]; then
		for v in "${multiphp_v[@]}"; do
			software=$(echo "$software" | sed -e "s/php$v-mysql//")
			software=$(echo "$software" | sed -e "s/php$v-bz2//")
		done
	fi
fi
if [ "$postgresql" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/postgresql-contrib//")
	software=$(echo "$software" | sed -e "s/postgresql//")
	software=$(echo "$software" | sed -e "s/php$fpm_v-pgsql//")
fi
if [ "$fail2ban" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/fail2ban//")
fi
if [ "$iptables" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/nftables//")
	software=$(echo "$software" | sed -e "s/fail2ban//")
fi
if [ "$webterminal" = 'no' ]; then
	software=$(echo "$software" | sed -e "s/nodejs//")
	software=$(echo "$software" | sed -e "s/arcpanel-web-terminal//")
fi
if [ "$phpfpm" = 'yes' ]; then
	software=$(echo "$software" | sed -e "s/php$fpm_v-cgi//")
	software=$(echo "$software" | sed -e "s/libapache2-mod-ruid2//")
	software=$(echo "$software" | sed -e "s/libapache2-mod-php$fpm_v//")
fi
if [ -d "$withdebs" ]; then
	software=$(echo "$software" | sed -e "s/arcpanel-nginx//")
	software=$(echo "$software" | sed -e "s/arcpanel-php//")
	software=$(echo "$software" | sed -e "s/arcpanel-web-terminal//")
	software=$(echo "$software" | sed -e "s/arcpanel=${ARCPANEL_INSTALL_BUILD}//")
fi
if [ "$release" = '24.04' ]; then
	software=$(echo "$software" | sed -e "s/libzip4/libzip4t64/")
fi
if [ "$release" = '26.04' ]; then
	software=$(echo "$software" | sed -e "s/libzip4/libzip5/")
fi

#----------------------------------------------------------#
#                 Disable Apparmor on LXC                  #
#----------------------------------------------------------#

if grep --quiet lxc /proc/1/environ; then
	if [ -f /etc/init.d/apparmor ]; then
		systemctl stop apparmor > /dev/null 2>&1
		systemctl disable apparmor > /dev/null 2>&1
	fi
fi

#----------------------------------------------------------#
#                     Install packages                     #
#----------------------------------------------------------#

# Enable en_US.UTF-8
if [ -f /etc/locale.gen ]; then
	sed -i "s/# en_US.UTF-8 UTF-8/en_US.UTF-8 UTF-8/g" /etc/locale.gen
	locale-gen > /dev/null 2>&1
elif [ -f /usr/bin/localectl ]; then
	apt-get -y install locales-all >> $LOG
	localectl set-locale LANG=en_US.UTF-8 > /dev/null 2>&1
fi

# Disabling daemon autostart on apt-get install
echo -e '#!/bin/sh\nexit 101' > /usr/sbin/policy-rc.d
chmod a+x /usr/sbin/policy-rc.d

# Installing apt packages
echo "The installer is now downloading and installing all required packages."
echo -ne "NOTE: This process may take 10 to 15 minutes to complete, please wait... "
echo
apt-get -y install $software > $LOG
BACK_PID=$!

# Check if package installation is done, print a spinner
spin_i=1
while kill -0 $BACK_PID > /dev/null 2>&1; do
	printf "\b${spinner:spin_i++%${#spinner}:1}"
	sleep 0.5
done

# Do a blank echo to get the \n back
echo

# Check Installation result
wait $BACK_PID
check_result $? "apt-get install failed"

echo
echo "========================================================================"
echo

# Install ArcPanel packages from local folder
if [ -n "$withdebs" ] && [ -d "$withdebs" ]; then
	echo "[ * ] Installing local package files..."
	echo "    - arcpanel core package"
	dpkg -i $withdebs/arcpanel_*.deb > /dev/null 2>&1

	if [ -z $(ls $withdebs/arcpanel-php_*.deb 2> /dev/null) ]; then
		echo "    - arcpanel-php backend package (from apt)"
		apt-get -y install arcpanel-php > /dev/null 2>&1
	else
		echo "    - arcpanel-php backend package"
		dpkg -i $withdebs/arcpanel-php_*.deb > /dev/null 2>&1
	fi

	if [ -z $(ls $withdebs/arcpanel-nginx_*.deb 2> /dev/null) ]; then
		echo "    - arcpanel-nginx backend package (from apt)"
		apt-get -y install arcpanel-nginx > /dev/null 2>&1
	else
		echo "    - arcpanel-nginx backend package"
		dpkg -i $withdebs/arcpanel-nginx_*.deb > /dev/null 2>&1
	fi

	if [ "$webterminal" = "yes" ]; then
		if [ -z $(ls $withdebs/arcpanel-web-terminal_*.deb 2> /dev/null) ]; then
			echo "    - arcpanel-web-terminal package (from apt)"
			apt-get -y install arcpanel-web-terminal > /dev/null 2>&1
		else
			echo "    - arcpanel-web-terminal"
			dpkg -i $withdebs/arcpanel-web-terminal_*.deb > /dev/null 2>&1
		fi
	fi
fi

# Restoring autostart policy
rm -f /usr/sbin/policy-rc.d

#----------------------------------------------------------#
#                     Configure system                     #
#----------------------------------------------------------#

echo "[ * ] Configuring system settings..."

# Generate a random password
random_password=$(gen_pass '32')
# Create the new arcpanelweb user
/usr/sbin/useradd "arcpanelweb" -c "$email" --no-create-home
# do not allow login into arcpanelweb user
echo arcpanelweb:$random_password | sudo chpasswd -e

# Add a general group for normal users created by ArcPanel
if [ -z "$(grep ^arcpanel-users: /etc/group)" ]; then
	groupadd --system "arcpanel-users"
fi

# Create user for php-fpm configs
/usr/sbin/useradd "arcpanelmail" -c "$email" --no-create-home
# Ensures proper permissions for ArcPanel service interactions.
/usr/sbin/adduser arcpanelmail arcpanel-users

# Enable SFTP subsystem for SSH
sftp_subsys_enabled=$(grep -iE "^#?.*subsystem.+(sftp )?sftp-server" /etc/ssh/sshd_config)
if [ -n "$sftp_subsys_enabled" ]; then
	sed -i -E "s/^#?.*Subsystem.+(sftp )?sftp-server/Subsystem sftp internal-sftp/g" /etc/ssh/sshd_config
fi

# Reduce SSH login grace time
sed -i "s/[#]LoginGraceTime [[:digit:]]m/LoginGraceTime 1m/g" /etc/ssh/sshd_config

# Disable SSH suffix broadcast
if [ -z "$(grep "^DebianBanner no" /etc/ssh/sshd_config)" ]; then
	sed -i '/^[#]Banner .*/a DebianBanner no' /etc/ssh/sshd_config
	if [ -z "$(grep "^DebianBanner no" /etc/ssh/sshd_config)" ]; then
		# If first attempt fails just add it
		echo '' >> /etc/ssh/sshd_config
		echo 'DebianBanner no' >> /etc/ssh/sshd_config
	fi
fi

# Restart SSH daemon
systemctl restart ssh

# Disable AWStats cron
rm -f /etc/cron.d/awstats
# Replace AWStats function
cp -f $ARCPANEL_INSTALL_DIR/logrotate/httpd-prerotate/* /etc/logrotate.d/httpd-prerotate/

# Set directory color
if [ -z "$(grep 'LS_COLORS="$LS_COLORS:di=00;33"' /etc/profile)" ]; then
	echo 'LS_COLORS="$LS_COLORS:di=00;33"' >> /etc/profile
fi

# Register /usr/sbin/nologin
if [ -z "$(grep nologin /etc/shells)" ]; then
	echo "/usr/sbin/nologin" >> /etc/shells
fi

# Configuring NTP
# Ubuntu 26.04 doesn't install systemd-timesyncd, by default it uses Chrony
if [[ "$release" = "22.04" ]] || [[ "$release" = "24.04" ]]; then
	sed -i 's/#NTP=/NTP=pool.ntp.org/' /etc/systemd/timesyncd.conf
	systemctl enable systemd-timesyncd
	systemctl start systemd-timesyncd
fi

# Check iptables paths and add symlinks when necessary
if [ ! -e "/sbin/iptables" ]; then
	if which iptables > /dev/null; then
		ln -s "$(which iptables)" /sbin/iptables
	elif [ -e "/usr/sbin/iptables" ]; then
		ln -s /usr/sbin/iptables /sbin/iptables
	elif whereis -B /bin /sbin /usr/bin /usr/sbin -f -b iptables; then
		autoiptables=$(whereis -B /bin /sbin /usr/bin /usr/sbin -f -b iptables | cut -d '' -f 2)
		if [ -x "$autoiptables" ]; then
			ln -s "$autoiptables" /sbin/iptables
		fi
	fi
fi

if [ ! -e "/sbin/iptables-save" ]; then
	if which iptables-save > /dev/null; then
		ln -s "$(which iptables-save)" /sbin/iptables-save
	elif [ -e "/usr/sbin/iptables-save" ]; then
		ln -s /usr/sbin/iptables-save /sbin/iptables-save
	elif whereis -B /bin /sbin /usr/bin /usr/sbin -f -b iptables-save; then
		autoiptables_save=$(whereis -B /bin /sbin /usr/bin /usr/sbin -f -b iptables-save | cut -d '' -f 2)
		if [ -x "$autoiptables_save" ]; then
			ln -s "$autoiptables_save" /sbin/iptables-save
		fi
	fi
fi

if [ ! -e "/sbin/iptables-restore" ]; then
	if which iptables-restore > /dev/null; then
		ln -s "$(which iptables-restore)" /sbin/iptables-restore
	elif [ -e "/usr/sbin/iptables-restore" ]; then
		ln -s /usr/sbin/iptables-restore /sbin/iptables-restore
	elif whereis -B /bin /sbin /usr/bin /usr/sbin -f -b iptables-restore; then
		autoiptables_restore=$(whereis -B /bin /sbin /usr/bin /usr/sbin -f -b iptables-restore | cut -d '' -f 2)
		if [ -x "$autoiptables_restore" ]; then
			ln -s "$autoiptables_restore" /sbin/iptables-restore
		fi
	fi
fi

# Restrict access to /proc fs
# Prevent unpriv users from seeing each other running processes
mount -o remount,defaults,hidepid=2 /proc > /dev/null 2>&1
if [ $? -ne 0 ]; then
	echo "Info: Cannot remount /proc (LXC containers require additional perm added to host apparmor profile)"
else
	echo "@reboot root sleep 5 && mount -o remount,defaults,hidepid=2 /proc" > /etc/cron.d/arcpanel-proc
fi

#----------------------------------------------------------#
#                     Configure ArcPanel                     #
#----------------------------------------------------------#

echo "[ * ] Configuring ArcPanel..."
# Add ArcPanel global config
if [[ ! -e /etc/arcpanel/arcpanel.conf ]]; then
	mkdir -p /etc/arcpanel
	echo -e "# Do not edit this file, will get overwritten on next upgrade, use /etc/arcpanel/local.conf instead\n\nexport ARCPANEL='/usr/local/arcpanel'\n\n[[ -f /etc/arcpanel/local.conf ]] && source /etc/arcpanel/local.conf" > /etc/arcpanel/arcpanel.conf
fi

# Configuring system env
echo "export ARCPANEL='$ARCPANEL'" > /etc/profile.d/arcpanel.sh
echo 'PATH=$PATH:'$ARCPANEL'/bin' >> /etc/profile.d/arcpanel.sh
echo 'export PATH' >> /etc/profile.d/arcpanel.sh
chmod 755 /etc/profile.d/arcpanel.sh
source /etc/profile.d/arcpanel.sh

# Configuring logrotate for ArcPanel logs
cp -f $ARCPANEL_INSTALL_DIR/logrotate/arcpanel /etc/logrotate.d/arcpanel

# Create log path and symbolic link
rm -f /var/log/arcpanel
mkdir -p /var/log/arcpanel
ln -s /var/log/arcpanel $ARCPANEL/log

# Building directory tree and creating some blank files for ArcPanel
mkdir -p $ARCPANEL/conf $ARCPANEL/ssl $ARCPANEL/data/ips \
	$ARCPANEL/data/queue $ARCPANEL/data/users $ARCPANEL/data/firewall \
	$ARCPANEL/data/sessions
touch $ARCPANEL/data/queue/backup.pipe $ARCPANEL/data/queue/disk.pipe \
	$ARCPANEL/data/queue/webstats.pipe $ARCPANEL/data/queue/restart.pipe \
	$ARCPANEL/data/queue/traffic.pipe $ARCPANEL/data/queue/daily.pipe $ARCPANEL/log/system.log \
	$ARCPANEL/log/nginx-error.log $ARCPANEL/log/auth.log $ARCPANEL/log/backup.log
chmod 750 $ARCPANEL/conf $ARCPANEL/data/users $ARCPANEL/data/ips $ARCPANEL/log
chmod -R 750 $ARCPANEL/data/queue
chmod 660 /var/log/arcpanel/*
chmod 770 $ARCPANEL/data/sessions

# Generating ArcPanel configuration
rm -f $ARCPANEL/conf/arcpanel.conf > /dev/null 2>&1
touch $ARCPANEL/conf/arcpanel.conf
chmod 660 $ARCPANEL/conf/arcpanel.conf

# Sudo rules of arcpanelweb: every command but the root-only ones (E39 W4
# spec §9); v-add-sys-arcpanel writes them again at the end
$ARCPANEL/bin/v-update-sys-sudoers

# Write default port value to arcpanel.conf
# If a custom port is specified it will be set at the end of the installation process
write_config_value "BACKEND_PORT" "8083"

# Web stack
if [ "$apache" = 'yes' ]; then
	write_config_value "WEB_SYSTEM" "apache2"
	write_config_value "WEB_RGROUPS" "www-data"
	write_config_value "WEB_PORT" "8080"
	write_config_value "WEB_SSL_PORT" "8443"
	write_config_value "WEB_SSL" "mod_ssl"
	write_config_value "PROXY_SYSTEM" "nginx"
	write_config_value "PROXY_PORT" "80"
	write_config_value "PROXY_SSL_PORT" "443"
	write_config_value "STATS_SYSTEM" "awstats"
fi
if [ "$apache" = 'no' ]; then
	write_config_value "WEB_SYSTEM" "nginx"
	write_config_value "WEB_PORT" "80"
	write_config_value "WEB_SSL_PORT" "443"
	write_config_value "WEB_SSL" "openssl"
	write_config_value "STATS_SYSTEM" "awstats"
fi
if [ "$phpfpm" = 'yes' ] || [ "$multiphp" = 'yes' ]; then
	write_config_value "WEB_BACKEND" "php-fpm"
fi

# Database stack
if [ "$mysql" = 'yes' ] || [ "$mysql8" = 'yes' ]; then
	installed_db_types='mysql'
fi
if [ "$postgresql" = 'yes' ]; then
	installed_db_types="$installed_db_types,pgsql"
fi
if [ -n "$installed_db_types" ]; then
	db=$(echo "$installed_db_types" \
		| sed "s/,/\n/g" \
		| sort -r -u \
		| sed "/^$/d" \
		| sed ':a;N;$!ba;s/\n/,/g')
	write_config_value "DB_SYSTEM" "$db"
fi

# FTP stack
if [ "$vsftpd" = 'yes' ]; then
	write_config_value "FTP_SYSTEM" "vsftpd"
fi
if [ "$proftpd" = 'yes' ]; then
	write_config_value "FTP_SYSTEM" "proftpd"
fi

# DNS stack
if [ "$named" = 'yes' ]; then
	write_config_value "DNS_SYSTEM" "bind9"
fi

# Mail stack
if [ "$exim" = 'yes' ]; then
	write_config_value "MAIL_SYSTEM" "exim4"
	if [ "$clamd" = 'yes' ]; then
		write_config_value "ANTIVIRUS_SYSTEM" "clamav-daemon"
	fi
	if [ "$spamd" = 'yes' ]; then
		release_short="$(cut -d '.' -f1 <<< "$release")"
		if [[ -n "$release_short" ]] && [[ $release_short -lt 24 ]]; then
			write_config_value "ANTISPAM_SYSTEM" "spamassassin"
		else
			write_config_value "ANTISPAM_SYSTEM" "spamd"
		fi
	fi
	if [ "$dovecot" = 'yes' ]; then
		write_config_value "IMAP_SYSTEM" "dovecot"
	fi
	if [ "$sieve" = 'yes' ]; then
		write_config_value "SIEVE_SYSTEM" "yes"
	fi
fi

# Cron daemon
write_config_value "CRON_SYSTEM" "cron"

# Firewall stack
if [ "$iptables" = 'yes' ]; then
	write_config_value "FIREWALL_SYSTEM" "nftables"
fi
if [ "$iptables" = 'yes' ] && [ "$fail2ban" = 'yes' ]; then
	write_config_value "FIREWALL_EXTENSION" "fail2ban"
fi

# Disk quota
if [ "$quota" = 'yes' ]; then
	write_config_value "DISK_QUOTA" "yes"
else
	write_config_value "DISK_QUOTA" "no"
fi

# Resource limitation
if [ "$resourcelimit" = 'yes' ]; then
	write_config_value "RESOURCES_LIMIT" "yes"
else
	write_config_value "RESOURCES_LIMIT" "no"
fi

write_config_value "WEB_TERMINAL_PORT" "8085"

# Backups
write_config_value "BACKUP_SYSTEM" "local"
write_config_value "BACKUP_GZIP" "4"
write_config_value "BACKUP_MODE" "zstd"

# Language
write_config_value "LANGUAGE" "$lang"

# Login screen style
write_config_value "LOGIN_STYLE" "default"

# Theme
write_config_value "THEME" "nocturne"

# Inactive session timeout
write_config_value "INACTIVE_SESSION_TIMEOUT" "60"

# Version & Release Branch
# VERSION is the ArcPanel version (ADR-0007)
write_config_value "VERSION" "${ARCPANEL_INSTALL_VER}"
write_config_value "APP_NAME" "ArcPanel"
write_config_value "RELEASE_BRANCH" "release"

# Email notifications after upgrade
write_config_value "UPGRADE_SEND_EMAIL" "true"
write_config_value "UPGRADE_SEND_EMAIL_LOG" "false"

# Set "root" user
write_config_value "ROOT_USER" "$username"

# Installing hosting packages
cp -rf $ARCPANEL_COMMON_DIR/packages $ARCPANEL/data/

# Update nameservers in hosting package
IFS='.' read -r -a domain_elements <<< "$servername"
if [ -n "${domain_elements[-2]}" ] && [ -n "${domain_elements[-1]}" ]; then
	serverdomain="${domain_elements[-2]}.${domain_elements[-1]}"
	sed -i s/"domain.tld"/"$serverdomain"/g $ARCPANEL/data/packages/*.pkg
fi

# Installing templates
cp -rf $ARCPANEL_INSTALL_DIR/templates $ARCPANEL/data/
cp -rf $ARCPANEL_COMMON_DIR/templates/web/ $ARCPANEL/data/templates
cp -rf $ARCPANEL_COMMON_DIR/templates/dns/ $ARCPANEL/data/templates

mkdir -p /var/www/html
mkdir -p /var/www/document_errors

# Install default success page
cp -rf $ARCPANEL_COMMON_DIR/templates/web/unassigned/index.html /var/www/html/
cp -rf $ARCPANEL_COMMON_DIR/templates/web/skel/document_errors/* /var/www/document_errors/

# Installing firewall rules
cp -rf $ARCPANEL_COMMON_DIR/firewall $ARCPANEL/data/
rm -f $ARCPANEL/data/firewall/ipset/blacklist.sh $ARCPANEL/data/firewall/ipset/blacklist.ipv6.sh

# Delete rules for services that are not installed
if [ "$vsftpd" = "no" ] && [ "$proftpd" = "no" ]; then
	# Remove FTP
	sed -i "/COMMENT='FTP'/d" $ARCPANEL/data/firewall/rules.conf
fi
if [ "$exim" = "no" ]; then
	# Remove SMTP
	sed -i "/COMMENT='SMTP'/d" $ARCPANEL/data/firewall/rules.conf
fi
if [ "$dovecot" = "no" ]; then
	# Remove IMAP / Dovecot
	sed -i "/COMMENT='IMAP'/d" $ARCPANEL/data/firewall/rules.conf
	sed -i "/COMMENT='POP3'/d" $ARCPANEL/data/firewall/rules.conf
fi
if [ "$named" = "no" ]; then
	# Remove IMAP / Dovecot
	sed -i "/COMMENT='DNS'/d" $ARCPANEL/data/firewall/rules.conf
fi

# Installing API
cp -rf $ARCPANEL_COMMON_DIR/api $ARCPANEL/data/
# The secret store set keeps its name v2-secrets (E39 W4 D13)
mv -f $ARCPANEL/data/api/v2-secret-store $ARCPANEL/data/api/v2-secrets

# Configuring server hostname
$ARCPANEL/bin/v-change-sys-hostname $servername > /dev/null 2>&1

# Configuring global OpenSSL options
echo "[ * ] Configuring OpenSSL to improve TLS performance..."
tls13_ciphers="TLS_AES_128_GCM_SHA256:TLS_CHACHA20_POLY1305_SHA256:TLS_AES_256_GCM_SHA384"
if [ "$release" = "22.04" ]; then
	sed -i '/^system_default = system_default_sect$/a system_default = arcpanel_openssl_sect\n\n[arcpanel_openssl_sect]\nCiphersuites = '"$tls13_ciphers"'\nOptions = PrioritizeChaCha' /etc/ssl/openssl.cnf
else
	if ! grep -qw "^ssl_conf = ssl_sect$" /etc/ssl/openssl.cnf 2> /dev/null; then
		sed -i '/providers = provider_sect$/a ssl_conf = ssl_sect' /etc/ssl/openssl.cnf
	fi
	if ! grep -qw "^[ssl_sect]$" /etc/ssl/openssl.cnf 2> /dev/null; then
		sed -i '$a \\n[ssl_sect]\nsystem_default = arcpanel_openssl_sect\n\n[arcpanel_openssl_sect]\nCiphersuites = '"$tls13_ciphers"'\nOptions = PrioritizeChaCha' /etc/ssl/openssl.cnf
	elif grep -qw "^system_default = system_default_sect$" /etc/ssl/openssl.cnf 2> /dev/null; then
		sed -i '/^system_default = system_default_sect$/a system_default = arcpanel_openssl_sect\n\n[arcpanel_openssl_sect]\nCiphersuites = '"$tls13_ciphers"'\nOptions = PrioritizeChaCha' /etc/ssl/openssl.cnf
	fi
fi

# Generating SSL certificate
echo "[ * ] Generating default self-signed SSL certificate..."
$ARCPANEL/bin/v-generate-ssl-cert $(hostname) '' 'US' 'California' \
	'San Francisco' 'ArcPanel' 'IT' > /tmp/hst.pem

# Parsing certificate file
crt_end=$(grep -n "END CERTIFICATE-" /tmp/hst.pem | head -n1 | cut -f 1 -d:)
# Newer OpenSSL may emit BEGIN PRIVATE KEY while older flows emit BEGIN RSA PRIVATE KEY.
key_start=$(grep -nE "BEGIN (RSA |EC |ENCRYPTED )?PRIVATE KEY" /tmp/hst.pem | head -n1 | cut -f 1 -d:)
key_end=$(grep -nE "END (RSA |EC |ENCRYPTED )?PRIVATE KEY" /tmp/hst.pem | head -n1 | cut -f 1 -d:)
if [ -z "$key_start" ] || [ -z "$key_end" ]; then
	key_start=$(grep -n "BEGIN RSA" /tmp/hst.pem | head -n1 | cut -f 1 -d:)
	key_end=$(grep -n "END RSA" /tmp/hst.pem | head -n1 | cut -f 1 -d:)
fi
check_result $(
	[ -n "$crt_end" ] && [ -n "$key_start" ] && [ -n "$key_end" ]
	echo $?
) "failed to parse generated SSL certificate"

# Adding SSL certificate
echo "[ * ] Adding SSL certificate to ArcPanel..."
cd $ARCPANEL/ssl
sed -n "1,${crt_end}p" /tmp/hst.pem > certificate.crt
sed -n "$key_start,${key_end}p" /tmp/hst.pem > certificate.key
chown root:mail $ARCPANEL/ssl/*
chmod 660 $ARCPANEL/ssl/*
# The certificate is public: the phpMyAdmin single sign-on checks the panel with it (E39 W3)
chmod 644 $ARCPANEL/ssl/certificate.crt
rm /tmp/hst.pem

# Install dhparam.pem
cp -f $ARCPANEL_INSTALL_DIR/ssl/dhparam.pem /etc/ssl

# Enable SFTP jail
echo "[ * ] Enabling SFTP jail..."
$ARCPANEL/bin/v-add-sys-sftp-jail > /dev/null 2>&1
check_result $? "can't enable sftp jail"

# Enable SSH jail
echo "[ * ] Enabling SSH jail..."
$ARCPANEL/bin/v-add-sys-ssh-jail > /dev/null 2>&1
check_result $? "can't enable ssh jail"

# Adding ArcPanel admin account
echo "[ * ] Creating default admin account..."
$ARCPANEL/bin/v-add-user $username $vpass $email "default" "System Administrator"
check_result $? "can't create admin user"
$ARCPANEL/bin/v-change-user-shell $username nologin no
$ARCPANEL/bin/v-change-user-role $username admin
$ARCPANEL/bin/v-change-user-language $username $lang
$ARCPANEL/bin/v-change-sys-config-value 'POLICY_SYSTEM_PROTECTED_ADMIN' 'yes'

#----------------------------------------------------------#
#                     Configure Nginx                      #
#----------------------------------------------------------#

echo "[ * ] Configuring NGINX..."
rm -f /etc/nginx/conf.d/*.conf
cp -f $ARCPANEL_INSTALL_DIR/nginx/nginx.conf /etc/nginx/
cp -f $ARCPANEL_INSTALL_DIR/nginx/status.conf /etc/nginx/conf.d/
cp -f $ARCPANEL_INSTALL_DIR/nginx/0rtt-anti-replay.conf /etc/nginx/conf.d/
# wp-login.php rate limit of the wordpress-secure template (E6 batch 2)
cp -f $ARCPANEL_INSTALL_DIR/nginx/arcpanel-wp.conf /etc/nginx/conf.d/
cp -f $ARCPANEL_INSTALL_DIR/nginx/agents.conf /etc/nginx/conf.d/
# Copy over cloudflare.inc incase in the next step there are connection issues with CF
cp -f $ARCPANEL_INSTALL_DIR/nginx/cloudflare.inc /etc/nginx/conf.d/
cp -f $ARCPANEL_INSTALL_DIR/nginx/phpmyadmin.inc /etc/nginx/conf.d/
cp -f $ARCPANEL_INSTALL_DIR/nginx/phppgadmin.inc /etc/nginx/conf.d/
cp -f $ARCPANEL_INSTALL_DIR/logrotate/nginx /etc/logrotate.d/
mkdir -p /etc/nginx/conf.d/domains
mkdir -p /etc/nginx/conf.d/main
mkdir -p /etc/nginx/modules-enabled
mkdir -p /var/log/nginx/domains

# Update dns servers in nginx.conf
for nameserver in $(grep -is '^nameserver' /etc/resolv.conf | cut -d' ' -f2 | tr '\r\n' ' ' | xargs); do
	if [[ "$nameserver" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
		if [ -z "$resolver" ]; then
			resolver="$nameserver"
		else
			resolver="$resolver $nameserver"
		fi
	fi
done
if [ -n "$resolver" ]; then
	sed -i "s/1.0.0.1 8.8.4.4 1.1.1.1 8.8.8.8/$resolver/g" /etc/nginx/nginx.conf
fi

# https://github.com/ergin/nginx-cloudflare-real-ip/
cf_ips="$(curl -fsLm5 --retry 2 https://api.cloudflare.com/client/v4/ips)"

if [ -n "$cf_ips" ] && [ "$(echo "$cf_ips" | jq -r '.success//""')" = "true" ]; then
	cf_inc="/etc/nginx/conf.d/cloudflare.inc"

	echo "[ * ] Updating Cloudflare IP Ranges for Nginx..."
	echo "# Cloudflare IP Ranges" > $cf_inc
	echo "" >> $cf_inc
	echo "# IPv4" >> $cf_inc
	for ipv4 in $(echo "$cf_ips" | jq -r '.result.ipv4_cidrs[]//""' | sort); do
		echo "set_real_ip_from $ipv4;" >> $cf_inc
	done
	echo "" >> $cf_inc
	echo "# IPv6" >> $cf_inc
	for ipv6 in $(echo "$cf_ips" | jq -r '.result.ipv6_cidrs[]//""' | sort); do
		echo "set_real_ip_from $ipv6;" >> $cf_inc
	done
	echo "" >> $cf_inc
	echo "real_ip_header CF-Connecting-IP;" >> $cf_inc
fi

update-rc.d nginx defaults > /dev/null 2>&1
systemctl start nginx >> $LOG
check_result $? "nginx start failed"

#----------------------------------------------------------#
#                    Configure Apache                      #
#----------------------------------------------------------#

if [ "$apache" = 'yes' ]; then
	echo "[ * ] Configuring Apache Web Server..."

	mkdir -p /etc/apache2/conf.d
	mkdir -p /etc/apache2/conf.d/domains

	# Copy configuration files
	cp -f $ARCPANEL_INSTALL_DIR/apache2/apache2.conf /etc/apache2/
	cp -f $ARCPANEL_INSTALL_DIR/apache2/status.conf /etc/apache2/mods-available/arcpanel-status.conf
	cp -f /etc/apache2/mods-available/status.load /etc/apache2/mods-available/arcpanel-status.load
	cp -f $ARCPANEL_INSTALL_DIR/logrotate/apache2 /etc/logrotate.d/

	# Enable needed modules
	a2enmod rewrite > /dev/null 2>&1
	a2enmod suexec > /dev/null 2>&1
	a2enmod ssl > /dev/null 2>&1
	a2enmod actions > /dev/null 2>&1
	a2enmod headers > /dev/null 2>&1
	a2dismod --quiet status > /dev/null 2>&1
	a2enmod --quiet arcpanel-status > /dev/null 2>&1

	# Enable mod_ruid/mpm_itk or mpm_event
	if [ "$phpfpm" = 'yes' ]; then
		# Disable prefork and php, enable event
		a2dismod php$fpm_v > /dev/null 2>&1
		a2dismod mpm_prefork > /dev/null 2>&1
		a2enmod mpm_event > /dev/null 2>&1
		cp -f $ARCPANEL_INSTALL_DIR/apache2/arcpanel-event.conf /etc/apache2/conf.d/
	else
		a2enmod ruid2 > /dev/null 2>&1
	fi

	echo "# Powered by ArcPanel" > /etc/apache2/sites-available/default
	echo "# Powered by ArcPanel" > /etc/apache2/sites-available/default-ssl
	echo "# Powered by ArcPanel" > /etc/apache2/ports.conf
	echo -e "/home\npublic_html/cgi-bin" > /etc/apache2/suexec/www-data
	touch /var/log/apache2/access.log /var/log/apache2/error.log
	mkdir -p /var/log/apache2/domains
	chmod a+x /var/log/apache2
	chmod 640 /var/log/apache2/access.log /var/log/apache2/error.log
	chmod 751 /var/log/apache2/domains

	# Prevent remote access to server-status page
	sed -i '/Allow from all/d' /etc/apache2/mods-available/arcpanel-status.conf

	update-rc.d apache2 defaults > /dev/null 2>&1
	systemctl start apache2 >> $LOG
	check_result $? "apache2 start failed"
else
	update-rc.d apache2 disable > /dev/null 2>&1
	systemctl stop apache2 > /dev/null 2>&1
fi

#----------------------------------------------------------#
#                     Configure PHP-FPM                    #
#----------------------------------------------------------#

if [ "$phpfpm" = "yes" ]; then
	if [ "$multiphp" = 'yes' ]; then
		# The versions of the catalog of the installed package (E41)
		mapfile -t catalog_v < <(php_catalog_installable "$ARCPANEL_COMMON_DIR/php/versions.conf")
		if [ "$multiphp_list" = 'yes' ]; then
			selected_v=()
			for v in "${multiphp_v[@]}"; do
				if printf '%s\n' "${catalog_v[@]}" | grep -qxF "$v"; then
					selected_v+=("$v")
				else
					echo "[ ! ] PHP $v is not an installable version, skipped"
				fi
			done
			multiphp_v=("${selected_v[@]}")
		else
			multiphp_v=("${catalog_v[@]}")
		fi
		for v in "${multiphp_v[@]}"; do
			echo "[ * ] Installing PHP $v..."
			$ARCPANEL/bin/v-add-web-php "$v" > /dev/null 2>&1
		done
	else
		echo "[ * ] Installing PHP $fpm_v..."
		$ARCPANEL/bin/v-add-web-php "$fpm_v" > /dev/null 2>&1
	fi

	echo "[ * ] Configuring PHP-FPM $fpm_v..."
	# Create www.conf for webmail and php(*)admin
	cp -f $ARCPANEL_INSTALL_DIR/php-fpm/www.conf /etc/php/$fpm_v/fpm/pool.d/www.conf
	update-rc.d php$fpm_v-fpm defaults > /dev/null 2>&1
	systemctl start php$fpm_v-fpm >> $LOG
	check_result $? "php-fpm start failed"
	# Set default php version to $fpm_v
	update-alternatives --set php /usr/bin/php$fpm_v > /dev/null 2>&1
	# PHP-FPM profiles (E41)
	$ARCPANEL/bin/v-add-sys-php-profiles > /dev/null 2>&1
fi

#----------------------------------------------------------#
#                     Configure PHP                        #
#----------------------------------------------------------#

echo "[ * ] Configuring PHP..."
ZONE=$(timedatectl > /dev/null 2>&1 | grep Timezone | awk '{print $2}')
if [ -z "$ZONE" ]; then
	ZONE='UTC'
fi
for pconf in $(find /etc/php* -name php.ini); do
	sed -i "s%;date.timezone =%date.timezone = $ZONE%g" $pconf
	sed -i 's%_open_tag = Off%_open_tag = On%g' $pconf
done

# Cleanup php session files not changed in the last 7 days (60*24*7 minutes)
echo '#!/bin/sh' > /etc/cron.daily/php-session-cleanup
echo "find -O3 /home/*/tmp/ -ignore_readdir_race -depth -mindepth 1 -name 'sess_*' -type f -cmin '+10080' -delete > /dev/null 2>&1" >> /etc/cron.daily/php-session-cleanup
echo "find -O3 $ARCPANEL/data/sessions/ -ignore_readdir_race -depth -mindepth 1 -name 'sess_*' -type f -cmin '+10080' -delete > /dev/null 2>&1" >> /etc/cron.daily/php-session-cleanup
chmod 755 /etc/cron.daily/php-session-cleanup

#----------------------------------------------------------#
#                    Configure Vsftpd                      #
#----------------------------------------------------------#

if [ "$vsftpd" = 'yes' ]; then
	echo "[ * ] Configuring Vsftpd server..."
	cp -f $ARCPANEL_INSTALL_DIR/vsftpd/vsftpd.conf /etc/
	touch /var/log/vsftpd.log
	chown root:adm /var/log/vsftpd.log
	chmod 640 /var/log/vsftpd.log
	touch /var/log/xferlog
	chown root:adm /var/log/xferlog
	chmod 640 /var/log/xferlog
	if [ -s /etc/logrotate.d/vsftpd ] && ! grep -Fq "/var/log/xferlog" /etc/logrotate.d/vsftpd; then
		sed -i 's|/var/log/vsftpd.log|/var/log/vsftpd.log /var/log/xferlog|g' /etc/logrotate.d/vsftpd
	fi
	update-rc.d vsftpd defaults > /dev/null 2>&1
	systemctl start vsftpd >> $LOG
	check_result $? "vsftpd start failed"
fi

#----------------------------------------------------------#
#                    Configure ProFTPD                     #
#----------------------------------------------------------#

if [ "$proftpd" = 'yes' ]; then
	echo "[ * ] Configuring ProFTPD server..."
	echo "127.0.0.1 $servername" >> /etc/hosts
	cp -f $ARCPANEL_INSTALL_DIR/proftpd/proftpd.conf /etc/proftpd/
	cp -f $ARCPANEL_INSTALL_DIR/proftpd/tls.conf /etc/proftpd/

	# In Ubuntu 26.04 we need to:
	#   1.- Add modules.conf to proftpd.conf so tls mod is loaded.
	#   2.- Add rules to AppArmor to allow ProFTPD to read the certificates and create the socket.
	ubuntu_major="${release%%.*}"
	if [[ "$ubuntu_major" -ge 26 ]]; then
		# Add modules.conf
		if [[ -f /etc/proftpd/modules.conf ]] && grep -qF "Include /etc/proftpd/tls.conf" /etc/proftpd/proftpd.conf; then
			if ! grep -qF "Include /etc/proftpd/modules.conf" /etc/proftpd/proftpd.conf; then
				sed -i '\|Include /etc/proftpd/tls.conf|i Include /etc/proftpd/modules.conf' /etc/proftpd/proftpd.conf
			fi
		fi

		# Add ruleset for AppArmor
		mkdir -p /etc/apparmor.d/local
		if [[ ! -f /etc/apparmor.d/local/proftpd ]]; then
			cat > /etc/apparmor.d/local/proftpd << 'EOF'
# Configuration added by ArcPanel
/usr/local/arcpanel/ssl/certificate.crt r,
/usr/local/arcpanel/ssl/certificate.key r,
/run/proftpd.sock rw,
EOF
			apparmor_parser -r /etc/apparmor.d/proftpd 2> /dev/null
		elif ! grep -qF "# Configuration added by ArcPanel" /etc/apparmor.d/local/proftpd; then
			cat >> /etc/apparmor.d/local/proftpd << 'EOF'
# Configuration added by ArcPanel
/usr/local/arcpanel/ssl/certificate.crt r,
/usr/local/arcpanel/ssl/certificate.key r,
/run/proftpd.sock rw,
EOF
			apparmor_parser -r /etc/apparmor.d/proftpd 2> /dev/null
		fi
	fi

	update-rc.d proftpd defaults > /dev/null 2>&1
	systemctl start proftpd >> $LOG
	check_result $? "proftpd start failed"
fi

#----------------------------------------------------------#
#               Configure MariaDB / MySQL                  #
#----------------------------------------------------------#

if [ "$mysql" = 'yes' ] || [ "$mysql8" = 'yes' ]; then
	[ "$mysql" = 'yes' ] && mysql_type="MariaDB" || mysql_type="MySQL"
	echo "[ * ] Configuring $mysql_type database server..."
	mycnf="my-small.cnf"
	if [ $memory -gt 1200000 ]; then
		mycnf="my-medium.cnf"
	fi
	if [ $memory -gt 3900000 ]; then
		mycnf="my-large.cnf"
	fi

	if [ "$mysql_type" = 'MariaDB' ]; then
		# Run mariadb-install-db
		mariadb-install-db >> $LOG
	fi

	# Remove symbolic link
	rm -f /etc/mysql/my.cnf
	# Configuring MariaDB
	cp -f $ARCPANEL_INSTALL_DIR/mysql/$mycnf /etc/mysql/my.cnf

	# Switch MariaDB inclusions to the MySQL
	if [ "$mysql_type" = 'MySQL' ]; then
		sed -i '/query_cache_size/d' /etc/mysql/my.cnf
		sed -i 's|mariadb.conf.d|mysql.conf.d|g' /etc/mysql/my.cnf
	fi

	if [ "$mysql_type" = 'MariaDB' ]; then
		sed -i 's|/usr/share/mysql|/usr/share/mariadb|g' /etc/mysql/my.cnf
		update-rc.d mariadb defaults > /dev/null 2>&1
		systemctl -q enable mariadb 2> /dev/null
		systemctl start mariadb >> $LOG
		check_result $? "${mysql_type,,} start failed"
	fi

	if [ "$mysql_type" = 'MySQL' ]; then
		update-rc.d mysql defaults > /dev/null 2>&1
		systemctl -q enable mysql 2> /dev/null
		systemctl start mysql >> $LOG
		check_result $? "${mysql_type,,} start failed"
	fi

	# Securing MariaDB/MySQL installation
	mpass=$(gen_pass)
	echo -e "[client]\npassword='$mpass'\n" > /root/.my.cnf
	chmod 600 /root/.my.cnf

	if [ -f '/usr/bin/mariadb' ]; then
		mysql_server="mariadb"
	else
		mysql_server="mysql"
	fi
	# Alter root password
	$mysql_server -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$mpass'; FLUSH PRIVILEGES;"
	if [ "$mysql_type" = 'MariaDB' ]; then
		# Allow mysql access via socket for startup
		$mysql_server -e "UPDATE mysql.global_priv SET priv=json_set(priv, '$.password_last_changed', UNIX_TIMESTAMP(), '$.plugin', 'mysql_native_password', '$.authentication_string', 'invalid', '$.auth_or', json_array(json_object(), json_object('plugin', 'unix_socket'))) WHERE User='root';"
		# Disable anonymous users
		$mysql_server -e "DELETE FROM mysql.global_priv WHERE User='';"
	else
		$mysql_server -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '$mpass';"
		$mysql_server -e "DELETE FROM mysql.user WHERE User='';"
		$mysql_server -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');"
	fi
	# Drop test database
	$mysql_server -e "DROP DATABASE IF EXISTS test"
	$mysql_server -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%'"
	# Flush privileges
	$mysql_server -e "FLUSH PRIVILEGES;"
fi

#----------------------------------------------------------#
#                    Configure phpMyAdmin                  #
#----------------------------------------------------------#

# Source upgrade.conf with phpmyadmin versions
# shellcheck source=/usr/local/arcpanel/install/upgrade/upgrade.conf
source $ARCPANEL/install/upgrade/upgrade.conf

if [ "$mysql" = 'yes' ] || [ "$mysql8" = 'yes' ]; then
	# Display upgrade information
	echo "[ * ] Installing phpMyAdmin version v$pma_v..."

	# Download latest phpmyadmin release
	pma_work=$(mktemp -d)
	fetch_verified "https://files.phpmyadmin.net/phpMyAdmin/$pma_v/phpMyAdmin-$pma_v-all-languages.tar.gz" "$pma_sha256" "$pma_work/phpmyadmin.tar.gz"
	check_result $? "The phpMyAdmin download failed its check"

	# Unpack files
	tar xzf "$pma_work/phpmyadmin.tar.gz" -C "$pma_work"

	# Create folders
	mkdir -p /usr/share/phpmyadmin
	mkdir -p /etc/phpmyadmin
	mkdir -p /etc/phpmyadmin/conf.d/
	mkdir /usr/share/phpmyadmin/tmp

	# Configuring Apache2 for PHPMYADMIN
	if [ "$apache" = 'yes' ]; then
		touch /etc/apache2/conf.d/phpmyadmin.inc
	fi

	# Overwrite old files
	cp -rf "$pma_work/phpMyAdmin-$pma_v-all-languages/"* /usr/share/phpmyadmin

	# Create copy of config file
	cp -f $ARCPANEL_INSTALL_DIR/phpmyadmin/config.inc.php /etc/phpmyadmin/

	# Set config and log directory
	sed -i "s|'configFile' => ROOT_PATH . 'config.inc.php',|'configFile' => '/etc/phpmyadmin/config.inc.php',|g" /usr/share/phpmyadmin/libraries/vendor_config.php

	# Create temporary folder and change permission
	mkdir -p /var/lib/phpmyadmin/tmp
	chmod 770 /var/lib/phpmyadmin/tmp
	chown -R arcpanelmail:www-data /usr/share/phpmyadmin/tmp/

	# Generate blow fish
	blowfish=$(head /dev/urandom | tr -dc A-Za-z0-9 | head -c 32)
	sed -i "s|%blowfish_secret%|$blowfish|" /etc/phpmyadmin/config.inc.php

	# Clean Up
	rm -rf "$pma_work"

	write_config_value "DB_PMA_ALIAS" "phpmyadmin"
	$ARCPANEL/bin/v-change-sys-db-alias 'pma' "phpmyadmin"

	# Special thanks to Pavel Galkin (https://skurudo.ru)
	# https://github.com/skurudo/phpmyadmin-fixer
	# shellcheck source=/usr/local/arcpanel/install/deb/phpmyadmin/pma.sh
	source $ARCPANEL_INSTALL_DIR/phpmyadmin/pma.sh > /dev/null 2>&1

	# Limit access to /etc/phpmyadmin/
	chown -R root:arcpanelmail /etc/phpmyadmin/
	chmod 640 /etc/phpmyadmin/config.inc.php
	chmod 750 /etc/phpmyadmin/conf.d/
fi

#----------------------------------------------------------#
#                   Configure PostgreSQL                   #
#----------------------------------------------------------#

if [ "$postgresql" = 'yes' ]; then
	echo "[ * ] Configuring PostgreSQL database server..."
	ppass=$(gen_pass)
	cp -f $ARCPANEL_INSTALL_DIR/postgresql/pg_hba.conf /etc/postgresql/*/main/
	systemctl restart postgresql
	sudo -iu postgres psql -c "ALTER USER postgres WITH PASSWORD '$ppass'" > /dev/null 2>&1

	mkdir -p /etc/phppgadmin/
	mkdir -p /usr/share/phppgadmin/

	# phpPgAdmin $pga_v ships with ArcPanel (install/deb/pga/phppgadmin)
	cp -a "$ARCPANEL_INSTALL_DIR/pga/phppgadmin/." /usr/share/phppgadmin/

	cp -f $ARCPANEL_INSTALL_DIR/pga/config.inc.php /etc/phppgadmin/

	ln -s /etc/phppgadmin/config.inc.php /usr/share/phppgadmin/conf/

	# Configuring phpPgAdmin
	if [ "$apache" = 'yes' ]; then
		cp -f $ARCPANEL_INSTALL_DIR/pga/phppgadmin.conf /etc/apache2/conf.d/phppgadmin.inc
	fi

	write_config_value "DB_PGA_ALIAS" "phppgadmin"
	$ARCPANEL/bin/v-change-sys-db-alias 'pga' "phppgadmin"

	# Limit access to /etc/phppgadmin/
	chown -R root:arcpanelmail /etc/phppgadmin/
	chmod 640 /etc/phppgadmin/config.inc.php
fi

#----------------------------------------------------------#
#                      Configure Bind                      #
#----------------------------------------------------------#

if [ "$named" = 'yes' ]; then
	echo "[ * ] Configuring Bind DNS server..."
	cp -f $ARCPANEL_INSTALL_DIR/bind/named.conf /etc/bind/
	cp -f $ARCPANEL_INSTALL_DIR/bind/named.conf.options /etc/bind/
	chown root:bind /etc/bind/named.conf
	chown root:bind /etc/bind/named.conf.options
	chown bind:bind /var/cache/bind
	chmod 640 /etc/bind/named.conf
	chmod 640 /etc/bind/named.conf.options
	aa-complain /usr/sbin/named > /dev/null 2>&1
	echo "/home/** rwm," >> /etc/apparmor.d/local/usr.sbin.named 2> /dev/null
	if ! grep --quiet lxc /proc/1/environ; then
		systemctl status apparmor > /dev/null 2>&1
		if [ $? -ne 0 ]; then
			systemctl restart apparmor >> $LOG
		fi
	fi

	# Ubuntu 26.04 removed the named.conf.default-zones file if doesn't exsists remove it from the config file
	if [ ! -f /etc/bind/named.conf.default-zones ]; then
		sed -i "/^include.*named.conf.default-zones/d" /etc/bind/named.conf
	fi

	# Add root-hints include after named.conf.local if missing
	if [[ -f /etc/bind/named.conf.root-hints ]] \
		&& ! grep -q 'include.*named.conf.root-hints' /etc/bind/named.conf 2> /dev/null; then
		sed -i '/include.*named\.conf\.local/a include "\/etc\/bind\/named.conf.root-hints";' /etc/bind/named.conf
	fi

	update-rc.d bind9 defaults > /dev/null 2>&1
	systemctl start bind9
	check_result $? "bind9 start failed"

	# Workaround for OpenVZ/Virtuozzo
	if [ -e "/proc/vz/veinfo" ] && [ -e "/etc/rc.local" ]; then
		sed -i "s/^exit 0/service bind9 restart\nexit 0/" /etc/rc.local
	fi
fi

#----------------------------------------------------------#
#                      Configure Exim                      #
#----------------------------------------------------------#

if [ "$exim" = 'yes' ]; then
	echo "[ * ] Configuring Exim mail server..."
	gpasswd -a Debian-exim mail > /dev/null 2>&1
	exim_version=$(exim4 --version | head -1 | awk '{print $3}' | cut -f -2 -d .)
	# if Exim version > 4.9.4 or greater!
	if ! version_ge "4.94" "$exim_version"; then
		# Ubuntu 22.04 (Jammy) uses Exim 4.95 instead but config works with Exim4.94
		cp -f $ARCPANEL_INSTALL_DIR/exim/exim4.conf.4.95.template /etc/exim4/exim4.conf.template
	else
		cp -f $ARCPANEL_INSTALL_DIR/exim/exim4.conf.template /etc/exim4/
	fi
	# If Dovecot 2.4, modify the local delivery directives"
	if [[ $(dovecot --version) == 2.4* ]] \
		&& [[ -f /etc/exim4/exim4.conf.template ]]; then
		sed -i.bak '
s#  directory = "${extract{5}{:}{${lookup{$local_part}lsearch{/etc/exim4/domains/${lookup{$domain}dsearch{/etc/exim4/domains/}}/passwd}}}}/mail/${lookup{$domain}dsearch{/etc/exim4/domains/}}/${lookup{$local_part}dsearch{${extract{5}{:}{${lookup{$local_part}lsearch{/etc/exim4/domains/${lookup{$domain}dsearch{/etc/exim4/domains/}}/passwd}}}}/mail/${lookup{$domain}dsearch{/etc/exim4/domains/}}}}"#  directory = "${extract{5}{:}{${lookup{$local_part}lsearch{/etc/exim4/domains/${lookup{$domain}dsearch{/etc/exim4/domains/}}/passwd}}}}"#

s#  directory = "${extract{5}{:}{${lookup{$local_part}lsearch{/etc/exim4/domains/${lookup{$domain}dsearch{/etc/exim4/domains/}}/passwd}}}}/mail/${lookup{$domain}dsearch{/etc/exim4/domains/}}/${lookup{$local_part}dsearch{${extract{5}{:}{${lookup{$local_part}lsearch{/etc/exim4/domains/${lookup{$domain}dsearch{/etc/exim4/domains/}}/passwd}}}}/mail/${lookup{$domain}dsearch{/etc/exim4/domains/}}}}/.Spam"#  directory = "${extract{5}{:}{${lookup{$local_part}lsearch{/etc/exim4/domains/${lookup{$domain}dsearch{/etc/exim4/domains/}}/passwd}}}}/.Spam"#

s#  quota_directory = "${extract{5}{:}{${lookup{$local_part}lsearch{/etc/exim4/domains/${lookup{$domain}dsearch{/etc/exim4/domains/}}/passwd}}}}/mail/${lookup{$domain}dsearch{/etc/exim4/domains/}}/${lookup{$local_part}dsearch{${extract{5}{:}{${lookup{$local_part}lsearch{/etc/exim4/domains/${lookup{$domain}dsearch{/etc/exim4/domains/}}/passwd}}}}/mail/${lookup{$domain}dsearch{/etc/exim4/domains/}}}}"#  quota_directory = "${extract{5}{:}{${lookup{$local_part}lsearch{/etc/exim4/domains/${lookup{$domain}dsearch{/etc/exim4/domains/}}/passwd}}}}"#
' /etc/exim4/exim4.conf.template
	fi

	cp -f $ARCPANEL_INSTALL_DIR/exim/dnsbl.conf /etc/exim4/
	cp -f $ARCPANEL_INSTALL_DIR/exim/spam-blocks.conf /etc/exim4/
	cp -f $ARCPANEL_INSTALL_DIR/exim/limit.conf /etc/exim4/
	cp -f $ARCPANEL_INSTALL_DIR/exim/system.filter /etc/exim4/
	touch /etc/exim4/white-blocks.conf

	if [ "$spamd" = 'yes' ]; then
		sed -i "s/#SPAM/SPAM/g" /etc/exim4/exim4.conf.template
	fi
	if [ "$clamd" = 'yes' ]; then
		sed -i "s/#CLAMD/CLAMD/g" /etc/exim4/exim4.conf.template
	fi

	# Generate SRS KEY If not support just created it will get ignored anyway
	srs=$(gen_pass)
	echo $srs > /etc/exim4/srs.conf
	chmod 640 /etc/exim4/srs.conf
	chmod 640 /etc/exim4/exim4.conf.template
	chown root:Debian-exim /etc/exim4/srs.conf

	rm -rf /etc/exim4/domains
	mkdir -p /etc/exim4/domains

	rm -f /etc/alternatives/mta
	ln -s /usr/sbin/exim4 /etc/alternatives/mta
	update-rc.d -f sendmail remove > /dev/null 2>&1
	systemctl stop sendmail > /dev/null 2>&1
	update-rc.d -f postfix remove > /dev/null 2>&1
	systemctl stop postfix > /dev/null 2>&1
	update-rc.d exim4 defaults
	systemctl start exim4 >> $LOG
	check_result $? "exim4 start failed"
fi

#----------------------------------------------------------#
#                     Configure Dovecot                    #
#----------------------------------------------------------#

if [ "$dovecot" = 'yes' ]; then
	dovecot_version="$(dovecot --version | cut -f -2 -d .)"
	echo "[ * ] Configuring Dovecot POP/IMAP mail server..."
	gpasswd -a dovecot mail > /dev/null 2>&1
	mkdir -p /etc/dovecot/conf.d/
	if [[ "$dovecot_version" = "2.4" ]]; then
		cp -f $ARCPANEL_COMMON_DIR/dovecot/2.4/dovecot.conf /etc/dovecot/
		cp -f $ARCPANEL_COMMON_DIR/dovecot/2.4/conf.d/* /etc/dovecot/conf.d/
	else
		cp -f $ARCPANEL_COMMON_DIR/dovecot/2.3/dovecot.conf /etc/dovecot/
		cp -f $ARCPANEL_COMMON_DIR/dovecot/2.3/conf.d/* /etc/dovecot/conf.d/
		rm -f /etc/dovecot/conf.d/15-mailboxes.conf
	fi
	cp -f $ARCPANEL_INSTALL_DIR/logrotate/dovecot /etc/logrotate.d/
	chown -R root:root /etc/dovecot*
	touch /var/log/dovecot.log
	chown -R dovecot:mail /var/log/dovecot.log
	chmod 660 /var/log/dovecot.log
	# Webmail sign-on sessions (E46), empty until v-add-sys-mail-sso and the panel
	touch /etc/dovecot/arcpanel-sso.passwd
	chown root:dovecot /etc/dovecot/arcpanel-sso.passwd
	chmod 640 /etc/dovecot/arcpanel-sso.passwd
	# Alter config for 2.2
	if [ "$dovecot_version" = "2.2" ]; then
		echo "[ * ] Downgrade dovecot config to sync with 2.2 settings"
		sed -i 's|#ssl_dh_parameters_length = 4096|ssl_dh_parameters_length = 4096|g' /etc/dovecot/conf.d/10-ssl.conf
		sed -i 's|ssl_dh = </etc/ssl/dhparam.pem|#ssl_dh = </etc/ssl/dhparam.pem|g' /etc/dovecot/conf.d/10-ssl.conf
		sed -i 's|ssl_min_protocol = TLSv1.2|ssl_protocols = !SSLv3 !TLSv1 !TLSv1.1|g' /etc/dovecot/conf.d/10-ssl.conf
	fi
	update-rc.d dovecot defaults
	systemctl start dovecot >> $LOG
	check_result $? "dovecot start failed"
fi

#----------------------------------------------------------#
#                     Configure ClamAV                     #
#----------------------------------------------------------#

if [ "$clamd" = 'yes' ]; then
	gpasswd -a clamav mail > /dev/null 2>&1
	gpasswd -a clamav Debian-exim > /dev/null 2>&1
	cp -f $ARCPANEL_INSTALL_DIR/clamav/clamd.conf /etc/clamav/
	update-rc.d clamav-daemon defaults
	echo -ne "[ * ] Installing ClamAV anti-virus definitions... "
	/usr/bin/freshclam >> $LOG > /dev/null 2>&1
	BACK_PID=$!
	spin_i=1
	while kill -0 $BACK_PID > /dev/null 2>&1; do
		printf "\b${spinner:spin_i++%${#spinner}:1}"
		sleep 0.5
	done
	echo
	systemctl start clamav-daemon >> $LOG
	check_result $? "clamav-daemon start failed"
fi

#----------------------------------------------------------#
#                  Configure SpamAssassin                  #
#----------------------------------------------------------#
if [ "$spamd" = 'yes' ]; then
	# Set SpamAssassin Service Name
	if [ "$release" = '22.04' ]; then
		spamd_srvname="spamassassin"
	else
		spamd_srvname="spamd"
	fi
	echo "[ * ] Configuring SpamAssassin..."
	update-rc.d $spamd_srvname defaults > /dev/null 2>&1
	sed -i "s/ENABLED=0/ENABLED=1/" /etc/default/$spamd_srvname
	systemctl start $spamd_srvname >> $LOG
	check_result $? "$spamd_srvname start failed"
	unit_files="$(systemctl list-unit-files | grep spamassassin)"
	if [[ "$unit_files" =~ "disabled" ]]; then
		systemctl enable $spamd_srvname > /dev/null 2>&1
	fi
	sed -i "s/#CRON=1/CRON=1/" /etc/default/$spamd_srvname
fi

#----------------------------------------------------------#
#                    Configure Fail2Ban                    #
#----------------------------------------------------------#

if [ "$fail2ban" = 'yes' ]; then
	echo "[ * ] Configuring fail2ban access monitor..."
	cp -rf $ARCPANEL_INSTALL_DIR/fail2ban /etc/
	if [ "$dovecot" = 'no' ]; then
		fline=$(cat /etc/fail2ban/jail.local | grep -n dovecot-iptables -A 2)
		fline=$(echo "$fline" | grep enabled | tail -n1 | cut -f 1 -d -)
		sed -i "${fline}s/true/false/" /etc/fail2ban/jail.local
	fi
	if [ "$exim" = 'no' ]; then
		fline=$(cat /etc/fail2ban/jail.local | grep -n exim-iptables -A 2)
		fline=$(echo "$fline" | grep enabled | tail -n1 | cut -f 1 -d -)
		sed -i "${fline}s/true/false/" /etc/fail2ban/jail.local
	fi
	if [ "$vsftpd" = 'yes' ]; then
		# Create vsftpd Log File
		if [ ! -f "/var/log/vsftpd.log" ]; then
			touch /var/log/vsftpd.log
		fi
		fline=$(cat /etc/fail2ban/jail.local | grep -n vsftpd-iptables -A 2)
		fline=$(echo "$fline" | grep enabled | tail -n1 | cut -f 1 -d -)
		sed -i "${fline}s/false/true/" /etc/fail2ban/jail.local
	fi
	if [ -f /etc/fail2ban/jail.d/defaults-debian.conf ]; then
		rm -f /etc/fail2ban/jail.d/defaults-debian.conf
	fi

	update-rc.d fail2ban defaults
	# Ubuntu 22.04 doesn't start F2B by default on boot
	update-rc.d fail2ban enable
	systemctl start fail2ban >> $LOG
	check_result $? "fail2ban start failed"
fi

# Configuring MariaDB/MySQL host
if [ "$mysql" = 'yes' ] || [ "$mysql8" = 'yes' ]; then
	$ARCPANEL/bin/v-add-database-host mysql localhost root $mpass
fi

# Configuring PostgreSQL host
if [ "$postgresql" = 'yes' ]; then
	$ARCPANEL/bin/v-add-database-host pgsql localhost postgres $ppass
fi

#----------------------------------------------------------#
#                       Install Roundcube                  #
#----------------------------------------------------------#

# Min requirements Dovecot + Exim + Mysql
if ([ "$mysql" == 'yes' ] || [ "$mysql8" == 'yes' ]) && [ "$dovecot" == "yes" ]; then
	echo "[ * ] Installing Roundcube..."
	$ARCPANEL/bin/v-add-sys-roundcube
	write_config_value "WEBMAIL_ALIAS" "webmail"
else
	write_config_value "WEBMAIL_ALIAS" ""
	write_config_value "WEBMAIL_SYSTEM" ""
fi

#----------------------------------------------------------#
#                     Install Sieve                        #
#----------------------------------------------------------#

# Min requirements Dovecot + Exim + Mysql + Roundcube
if [ "$sieve" = 'yes' ]; then
	# Folder paths
	RC_INSTALL_DIR="/var/lib/roundcube"
	RC_CONFIG_DIR="/etc/roundcube"

	echo "[ * ] Installing Sieve Mail Filter..."

	dovecot_version="$(dovecot --version | cut -f -2 -d .)"
	if [[ "$dovecot_version" = "2.4" ]]; then
		# dovecot conf files
		# dovecot.conf install
		sed -i -E 's/protocols = imap/protocols = sieve imap/' /etc/dovecot/dovecot.conf
		#  10-master.conf
		sed -i -E -z 's/    user = dovecot\n  \}\n\}/    user = dovecot\n  \}\n\n  unix_listener auth-master {\n    group = mail\n    mode = 0660\n    user = dovecot\n  }\n\}/' /etc/dovecot/conf.d/10-master.conf
		#  15-lda.conf
		sed -i '/^protocol lda {$/a\  mail_plugins = mail_compress quota sieve' /etc/dovecot/conf.d/15-lda.conf
		#  20-imap.conf
		sed -i "s/quota imap_quota/quota imap_quota imap_sieve/g" /etc/dovecot/conf.d/20-imap.conf
		# replace dovecot-sieve config files
		cp -f "$ARCPANEL_COMMON_DIR"/dovecot/2.4/sieve/* /etc/dovecot/conf.d

	else
		# dovecot.conf install
		sed -i "s/namespace/service stats \{\n  unix_listener stats-writer \{\n    group = mail\n    mode = 0660\n    user = dovecot\n  \}\n\}\n\nnamespace/g" /etc/dovecot/dovecot.conf

		# Dovecot conf files
		#  10-master.conf
		sed -i -E -z "s/  }\n  user = dovecot\n}/  \}\n  unix_listener auth-master \{\n    group = mail\n    mode = 0660\n    user = dovecot\n  \}\n  user = dovecot\n\}/g" /etc/dovecot/conf.d/10-master.conf
		#  15-lda.conf
		sed -i "s/\#mail_plugins = \\\$mail_plugins/mail_plugins = \$mail_plugins quota sieve\n  auth_socket_path = \/var\/run\/dovecot\/auth-master/g" /etc/dovecot/conf.d/15-lda.conf
		#  20-imap.conf
		sed -i "s/mail_plugins = quota imap_quota/mail_plugins = quota imap_quota imap_sieve/g" /etc/dovecot/conf.d/20-imap.conf

		# Replace dovecot-sieve config files
		cp -f $ARCPANEL_COMMON_DIR/dovecot/2.3/sieve/* /etc/dovecot/conf.d
	fi

	# Dovecot default file install
	mkdir -p /etc/dovecot/sieve/
	echo -e "require [\"fileinto\"];\n# rule:[SPAM]\nif header :contains \"X-Spam-Flag\" \"YES\" {\n    fileinto \"INBOX.Spam\";\n}\n" > /etc/dovecot/sieve/default

	# exim4 install
	sed -i "s/\stransport = local_delivery/ transport = dovecot_virtual_delivery/" /etc/exim4/exim4.conf.template
	sed -i "s/address_pipe:/dovecot_virtual_delivery:\n  driver = pipe\n  command = \/usr\/lib\/dovecot\/dovecot-lda -e -d \${extract{1}{:}{\${lookup{\$local_part}lsearch{\/etc\/exim4\/domains\/\${lookup{\$domain}dsearch{\/etc\/exim4\/domains\/}}\/accounts}}}}@\${lookup{\$domain}dsearch{\/etc\/exim4\/domains\/}}\n  delivery_date_add\n  envelope_to_add\n  return_path_add\n  log_output = true\n  log_defer_output = true\n  user = \${extract{2}{:}{\${lookup{\$local_part}lsearch{\/etc\/exim4\/domains\/\${lookup{\$domain}dsearch{\/etc\/exim4\/domains\/}}\/passwd}}}}\n  group = mail\n  return_output\n\naddress_pipe:/g" /etc/exim4/exim4.conf.template

	# Permission changes
	touch /var/log/dovecot.log
	chown -R dovecot:mail /var/log/dovecot.log
	chmod 660 /var/log/dovecot.log

	if [ -d "/var/lib/roundcube" ]; then
		# Modify Roundcube config
		mkdir -p $RC_CONFIG_DIR/plugins/managesieve
		cp -f $ARCPANEL_COMMON_DIR/roundcube/plugins/config_managesieve.inc.php $RC_CONFIG_DIR/plugins/managesieve/config.inc.php
		ln -s $RC_CONFIG_DIR/plugins/managesieve/config.inc.php $RC_INSTALL_DIR/plugins/managesieve/config.inc.php
		chown -R arcpanelmail:www-data $RC_CONFIG_DIR/
		chmod 751 -R $RC_CONFIG_DIR
		chmod 644 $RC_CONFIG_DIR/*.php
		chmod 644 $RC_CONFIG_DIR/plugins/managesieve/config.inc.php
		sed -i "s/\"archive\"/\"archive\", \"managesieve\"/g" $RC_CONFIG_DIR/config.inc.php
		chmod 640 $RC_CONFIG_DIR/config.inc.php
	fi

	# Restart Dovecot and Exim4
	systemctl restart dovecot > /dev/null 2>&1
	systemctl restart exim4 > /dev/null 2>&1
fi

#----------------------------------------------------------#
#                       Configure API                      #
#----------------------------------------------------------#

# Access keys and REST API v2 with --api; REST API v1 (lib/api) and its
# legacy hash login only with --api-v1 (E39 W4 D9)
if [ "$api" = "yes" ]; then
	write_config_value "API" "$api_v1"
	write_config_value "API_V1" "$api_v1"
	write_config_value "API_SYSTEM" "1"
	write_config_value "API_ALLOWED_IP" ""
else
	write_config_value "API" "no"
	write_config_value "API_V1" "no"
	write_config_value "API_SYSTEM" "0"
	write_config_value "API_ALLOWED_IP" ""
	$ARCPANEL/bin/v-change-sys-api disable
fi

#----------------------------------------------------------#
#                  Configure File Manager                  #
#----------------------------------------------------------#

echo "[ * ] Configuring File Manager..."
$ARCPANEL/bin/v-add-sys-filemanager quiet

#----------------------------------------------------------#
#                  Configure panel app                     #
#----------------------------------------------------------#

echo "[ * ] Configuring the ArcPanel panel app..."
$ARCPANEL/bin/v-add-sys-arcpanel
# Configuring sudoers to remove unsupported requiretty option on Ubuntu 26.04
ubuntu_major="${release%%.*}"
if [[ "$ubuntu_major" -ge 26 ]]; then
	echo 'KexAlgorithms +diffie-hellman-group-exchange-sha256' > /etc/ssh/sshd_config.d/arcpanel-kex.conf
fi

#----------------------------------------------------------#
#              Configure Web terminal                      #
#----------------------------------------------------------#

# The key of the web terminal tickets (E39 W2), as on an upgrade: the package's
# postinst ran before arcpanelweb and /etc/arcpanel/arcpanel.conf existed
$ARCPANEL/bin/v-add-sys-web-terminal-key > /dev/null

# Web terminal
if [ "$webterminal" = 'yes' ]; then
	write_config_value "WEB_TERMINAL" "true"
	systemctl daemon-reload > /dev/null 2>&1
	systemctl enable arcpanel-web-terminal > /dev/null 2>&1
	systemctl restart arcpanel-web-terminal > /dev/null 2>&1
else
	write_config_value "WEB_TERMINAL" "false"
fi

#----------------------------------------------------------#
#                  Configure dependencies                  #
#----------------------------------------------------------#

echo "[ * ] Configuring PHP dependencies..."
$ARCPANEL/bin/v-add-sys-dependencies quiet

echo "[ * ] Installing Rclone & Update Restic ..."
$ARCPANEL/bin/v-add-sys-rclone > /dev/null || echo "[ ! ] rclone was not installed; run v-add-sys-rclone later to use rclone backups"
restic self-update > /dev/null 2>&1

#----------------------------------------------------------#
#                   Configure IP                           #
#----------------------------------------------------------#

# Configuring system IPs
echo "[ * ] Configuring System IP..."
$ARCPANEL/bin/v-update-sys-ip > /dev/null 2>&1

# Get primary IP
default_nic="$(ip -d -j route show | jq -r '.[] | if .dst == "default" then .dev else empty end')"
# IPv4
primary_ipv4="$(ip -4 -d -j addr show "$default_nic" | jq -r '.[] | select(length > 0) | .addr_info[] | if .scope == "global" then .local else empty end' | head -n1)"
# IPv6
#primary_ipv6="$(ip -6 -d -j addr show "$default_nic" | jq -r '.[] | select(length > 0) | .addr_info[] | if .scope == "global" then .local else empty end' | head -n1)"
ip="$primary_ipv4"
local_ip="$primary_ipv4"

# Configuring firewall
if [ "$iptables" = 'yes' ]; then
	$ARCPANEL/bin/v-update-firewall
fi

# Get public IP
pub_ipv4="$(curl -fsLm5 --retry 2 --ipv4 https://arcpanel.org/ip || curl -fsLm5 --retry 2 --ipv4 https://api.ipify.org/)"
if [ -n "$pub_ipv4" ] && [ "$pub_ipv4" != "$ip" ]; then
	if [ -e /etc/rc.local ]; then
		sed -i '/exit 0/d' /etc/rc.local
	else
		touch /etc/rc.local
	fi

	check_rclocal=$(cat /etc/rc.local | grep "#!")
	if [ -z "$check_rclocal" ]; then
		echo "#!/bin/sh" >> /etc/rc.local
	fi

	# Fix for Proxmox VE containers where hostname is reset to non-FQDN format on reboot
	check_pve=$(uname -r | grep pve)
	if [ ! -z "$check_pve" ]; then
		echo 'hostname=$(hostname --fqdn)' >> /etc/rc.local
		echo ""$ARCPANEL/bin/v-change-sys-hostname" "'"$hostname"'"" >> /etc/rc.local
	fi
	echo "$ARCPANEL/bin/v-update-sys-ip" >> /etc/rc.local
	echo "exit 0" >> /etc/rc.local
	chmod +x /etc/rc.local
	systemctl enable rc-local > /dev/null 2>&1
	$ARCPANEL/bin/v-change-sys-ip-nat "$ip" "$pub_ipv4" > /dev/null 2>&1
	ip="$pub_ipv4"
fi

# Configuring libapache2-mod-remoteip
if [ "$apache" = 'yes' ] && [ "$nginx" = 'yes' ]; then
	cd /etc/apache2/mods-available
	echo "<IfModule mod_remoteip.c>" > remoteip.conf
	echo "  RemoteIPHeader X-Real-IP" >> remoteip.conf
	if [ "$local_ip" != "127.0.0.1" ] && [ "$pub_ipv4" != "127.0.0.1" ]; then
		echo "  RemoteIPInternalProxy 127.0.0.1" >> remoteip.conf
	fi
	if [ -n "$local_ip" ] && [ "$local_ip" != "$pub_ipv4" ]; then
		echo "  RemoteIPInternalProxy $local_ip" >> remoteip.conf
	fi
	if [ -n "$pub_ipv4" ]; then
		echo "  RemoteIPInternalProxy $pub_ipv4" >> remoteip.conf
	fi
	echo "</IfModule>" >> remoteip.conf
	sed -i "s/LogFormat \"%h/LogFormat \"%a/g" /etc/apache2/apache2.conf
	a2enmod remoteip >> $LOG
	systemctl restart apache2
fi

# Adding default domain
$ARCPANEL/bin/v-add-web-domain "$username" "$servername" "$ip"
check_result $? "can't create $servername domain"

# Adding cron jobs
export SCHEDULED_RESTART="yes"

min=$(gen_pass '012345' '2')
hour=$(gen_pass '1234567' '1')
echo "MAILTO=\"\"" > /var/spool/cron/crontabs/arcpanelweb
echo "CONTENT_TYPE=\"text/plain; charset=utf-8\"" >> /var/spool/cron/crontabs/arcpanelweb
echo "*/2 * * * * sudo /usr/local/arcpanel/bin/v-update-sys-queue restart" >> /var/spool/cron/crontabs/arcpanelweb
echo "10 00 * * * sudo /usr/local/arcpanel/bin/v-update-sys-queue daily" >> /var/spool/cron/crontabs/arcpanelweb
echo "15 02 * * * sudo /usr/local/arcpanel/bin/v-update-sys-queue disk" >> /var/spool/cron/crontabs/arcpanelweb
echo "10 00 * * * sudo /usr/local/arcpanel/bin/v-update-sys-queue traffic" >> /var/spool/cron/crontabs/arcpanelweb
echo "30 03 * * * sudo /usr/local/arcpanel/bin/v-update-sys-queue webstats" >> /var/spool/cron/crontabs/arcpanelweb
echo "*/5 * * * * sudo /usr/local/arcpanel/bin/v-update-sys-queue backup" >> /var/spool/cron/crontabs/arcpanelweb
echo "10 05 * * * sudo /usr/local/arcpanel/bin/v-backup-users" >> /var/spool/cron/crontabs/arcpanelweb
echo "20 00 * * * sudo /usr/local/arcpanel/bin/v-update-user-stats" >> /var/spool/cron/crontabs/arcpanelweb
echo "*/5 * * * * sudo /usr/local/arcpanel/bin/v-update-sys-rrd" >> /var/spool/cron/crontabs/arcpanelweb
echo "$min $hour * * * sudo /usr/local/arcpanel/bin/v-update-letsencrypt-ssl" >> /var/spool/cron/crontabs/arcpanelweb
# Reseller usage totals and policies (E11)
$ARCPANEL/bin/v-add-sys-tenancy
# Port registry: server range, reserved ports, nightly check (E12)
$ARCPANEL/bin/v-add-sys-port-registry
# Node.js manager: download cache, release keys, developer package (E13)
$ARCPANEL/bin/v-add-sys-node
# App processes: run files and the developer package keys (E42)
$ARCPANEL/bin/v-add-sys-app-processes
# File manager staging tree and the panel app's scheduler (E3)
$ARCPANEL/bin/v-add-sys-panel-files
# Secret store: the master key (E38); keep a copy of it off the server
$ARCPANEL/bin/v-add-sys-secret-key
# Server identifier for API clients (E43)
$ARCPANEL/bin/v-update-sys-server-id > /dev/null

chmod 600 /var/spool/cron/crontabs/arcpanelweb
chown arcpanelweb:arcpanelweb /var/spool/cron/crontabs/arcpanelweb

# Enable automatic updates
$ARCPANEL/bin/v-add-cron-arcpanel-autoupdate apt

# Building initial rrd images
$ARCPANEL/bin/v-update-sys-rrd

# Enabling file system quota
if [ "$quota" = 'yes' ]; then
	echo "[ * ] Configuring quota..."
	$ARCPANEL/bin/v-add-sys-quota
fi

# Set backend port
$ARCPANEL/bin/v-change-sys-port $port > /dev/null 2>&1

# Webmail and database tools ports and webmail sign-on (E46)
$ARCPANEL/bin/v-add-sys-tool-ports
$ARCPANEL/bin/v-add-sys-mail-sso

# Create default configuration files
$ARCPANEL/bin/v-update-sys-defaults

# Update remaining packages since repositories have changed
echo -ne "[ * ] Installing remaining software updates..."
apt-get -qq update
apt-get -y upgrade >> $LOG &
BACK_PID=$!
echo

# Starting ArcPanel service (arcpanel.service wraps /etc/init.d/arcpanel, alias arcpanel.service)
systemctl daemon-reload
if [ -f /lib/systemd/system/arcpanel.service ]; then
	systemctl enable arcpanel > /dev/null 2>&1
	systemctl start arcpanel
else
	update-rc.d arcpanel defaults
	systemctl start arcpanel
fi
check_result $? "arcpanel start failed"
chown arcpanelweb:arcpanelweb $ARCPANEL/data/sessions

# Create backup folder and set correct permission
mkdir -p /backup/
chmod 711 /backup/

# Create cronjob to generate ssl
echo "@reboot root sleep 10 && rm /etc/cron.d/arcpanel-ssl && PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:' && /usr/local/arcpanel/bin/v-add-letsencrypt-host" > /etc/cron.d/arcpanel-ssl

#----------------------------------------------------------#
#              Set arcpanel.conf default values              #
#----------------------------------------------------------#

echo "[ * ] Updating configuration files..."
BIN="$ARCPANEL/bin"
source $ARCPANEL/func/syshealth.sh
syshealth_repair_system_config

# Add /usr/local/arcpanel/bin/ to PATH variable in .bashrc if it exists
[[ -f /root/.bashrc ]] && echo 'if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi' >> /root/.bashrc

# Add /usr/local/arcpanel/bin/ to PATH variable in .zshrc if it exists
[[ -f /root/.zshrc ]] && echo 'if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi' >> /root/.zshrc

#----------------------------------------------------------#
#                   ArcPanel Access Info                     #
#----------------------------------------------------------#

# Comparing hostname and IP
host_ip=$(host $servername | head -n 1 | awk '{print $NF}')
if [ "$host_ip" = "$ip" ]; then
	ip="$servername"
fi

echo -e "\n"
echo "===================================================================="
echo -e "\n"

# Sending notification to admin email
echo -e "Congratulations!

You have successfully installed ArcPanel on your server.

Ready to get started? Log in using the following credentials:

	Admin URL:  https://$servername:$port" > $tmpfile
if [ "$host_ip" != "$ip" ]; then
	echo "	Backup URL: https://$ip:$port" >> $tmpfile
fi
echo -e -n " 	Username:   $username
	Password:   $displaypass

Thank you for choosing ArcPanel to power your full stack web server,
we hope that you enjoy using it as much as we do!

Please feel free to contact us at any time if you have any questions,
or if you encounter any bugs or problems:

Documentation:  https://arcpanel.org/docs
GitHub:         https://github.com/arc-panel-oss/arcpanel

Note: Automatic updates are enabled by default. If you would like to disable them,
please log in and navigate to Server > Updates to turn them off.

ArcPanel is based on HestiaCP. Thanks to the HestiaCP and VestaCP communities.

--
Sincerely yours,
The ArcPanel development team

" >> $tmpfile

send_mail="$ARCPANEL/lib/mail-wrapper.php"
cat $tmpfile | $send_mail -s "ArcPanel" $email

# Congrats
echo
cat $tmpfile
rm -f $tmpfile

# Add welcome message to notification panel
$ARCPANEL/bin/v-add-user-notification "$username" 'Welcome to ArcPanel!' '<p>You are now ready to begin adding <a href="/users/create">user accounts</a> and <a href="/web/domains/create">domains</a>. For help and assistance, <a href="https://arcpanel.org/docs" target="_blank">view the documentation</a>.</p><p>Please <a href="https://github.com/arc-panel-oss/arcpanel/issues" target="_blank">report any issues via GitHub</a>.</p><p class="u-text-bold">Have a wonderful day!</p><p><i class="fas fa-heart icon-red"></i> The ArcPanel development team</p>'

# Clean-up
# Sort final configuration file
sort_config_file

if [ "$interactive" = 'yes' ]; then
	echo "[ ! ] IMPORTANT: The system will now reboot to complete the installation process."
	read -n 1 -s -r -p "Press any key to continue"
	reboot
else
	echo "[ ! ] IMPORTANT: You must restart the system before continuing!"
fi
# EOF
