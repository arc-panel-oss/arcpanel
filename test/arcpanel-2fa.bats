#!/usr/bin/env bats
# Two-factor enrolment and recovery codes of E39 W5 (spec §11,
# docs/features/specs/2026-10-21-002-e39-w5-resources-and-hardening-design.md):
# a pending secret confirmed by a code, hashed single-use recovery codes, the
# secret kept out of v-list-user, and the keys kept by a package change.
# Run as root on a test server.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

ARCPANEL=/usr/local/arcpanel
TFUSER=tfaw5user
PASS="Tf-W5-2fa-Pass-31c9"
AUTHLOG="$ARCPANEL/log/auth.log"

setup_file() {
    v-delete-user "$TFUSER" > /dev/null 2>&1 || true
    v-add-user "$TFUSER" "$PASS" "$TFUSER@example.invalid" default > /dev/null
}

teardown_file() {
    v-delete-user "$TFUSER" > /dev/null 2>&1 || true
    rm -f /tmp/arcbats-2fa-*
}

# The TOTP code of SECRET for the time step STEP (default: now); the
# library's QR code provider is never asked to draw
totp() {
    "$ARCPANEL/php/bin/php" -r '
        require "/usr/local/arcpanel/lib/vendor/autoload.php";
        $qr = new class implements RobThree\Auth\Providers\Qr\IQRCodeProvider {
            public function getQRCodeImage(string $t, int $s): string { throw new LogicException(); }
            public function getMimeType(): string { return "image/svg+xml"; }
        };
        $t = new RobThree\Auth\TwoFactorAuth($qr, "ArcPanel");
        echo $t->getCode($argv[1], isset($argv[2]) ? (int) $argv[2] * 30 : null);
    ' "$@"
}

user_value() {
    grep "^$2=" "$ARCPANEL/data/users/$1/user.conf" | cut -d "'" -f 2
}

# A value file as the panel writes it: a plain name in /tmp
code_file() {
    local file
    file=$(mktemp /tmp/arcbats-2fa-XXXXXX)
    printf '%s\n' "$1" > "$file"
    echo "$file"
}

# Enrol TFUSER and print the recovery codes, one per line
enrol() {
    local secret
    secret=$(v-add-user-2fa "$TFUSER" json | jq -r .SECRET)
    v-change-user-2fa "$TFUSER" "$(totp "$secret")" json | jq -r '.RECOVERY_CODES[]'
}

@test "v-add-user-2fa starts an enrolment: a pending secret and its URI, 2FA still off" {
    run v-add-user-2fa "$TFUSER" json
    assert_success
    secret=$(jq -r .SECRET <<< "$output")
    [[ "$secret" =~ ^[A-Z2-7]{32}$ ]]
    assert_equal "$(jq -r .URI <<< "$output")" \
        "otpauth://totp/ArcPanel%3A$TFUSER%40$(hostname -f 2> /dev/null | jq -rR @uri)?secret=$secret&issuer=ArcPanel&algorithm=SHA1&digits=6&period=30"
    run stat -c '%U %a' "$ARCPANEL/data/users/$TFUSER/2fa-pending"
    assert_output 'root 600'
    assert_equal "$(user_value "$TFUSER" TWOFA)" ''

    # A second start replaces the pending secret
    run v-add-user-2fa "$TFUSER" json
    assert_not_equal "$(jq -r .SECRET <<< "$output")" "$secret"
    run grep -c "SECRET='$secret'" "$ARCPANEL/data/users/$TFUSER/2fa-pending"
    assert_output 0
}

@test "v-change-user-2fa turns 2FA on only with a code of the pending secret, once, and prints ten codes" {
    v-delete-user-2fa "$TFUSER" > /dev/null 2>&1 || true
    run v-change-user-2fa "$TFUSER" 123456
    assert_failure 3

    secret=$(v-add-user-2fa "$TFUSER" json | jq -r .SECRET)
    run v-change-user-2fa "$TFUSER" 000000
    assert_failure 9
    assert_equal "$(user_value "$TFUSER" TWOFA)" ''

    # An enrolment older than ten minutes has expired
    sed -i "s/^CREATED='.*'/CREATED='$(($(date +%s) - 601))'/" "$ARCPANEL/data/users/$TFUSER/2fa-pending"
    run v-change-user-2fa "$TFUSER" "$(totp "$secret")"
    assert_failure 3
    sed -i "s/^CREATED='.*'/CREATED='$(date +%s)'/" "$ARCPANEL/data/users/$TFUSER/2fa-pending"

    run v-change-user-2fa "$TFUSER" "$(totp "$secret")" json
    assert_success
    assert_equal "$(jq -r '.RECOVERY_CODES | length' <<< "$output")" 10
    assert_equal "$(jq -r '[.RECOVERY_CODES[] | test("^([A-Z2-7]{4}-){3}[A-Z2-7]{4}$")] | all' <<< "$output")" true
    assert_equal "$(jq -r '.RECOVERY_CODES | unique | length' <<< "$output")" 10
    assert_equal "$(user_value "$TFUSER" TWOFA)" "$secret"
    [[ "$(user_value "$TFUSER" TWOFA_STEP)" =~ ^[0-9]+$ ]]
    assert_equal "$(user_value "$TFUSER" TWOFA_RECOVERY | tr ',' '\n' | grep -cE '^[0-9a-f]{64}$')" 10
    # The codes themselves are not kept
    code=$(jq -r '.RECOVERY_CODES[0]' <<< "$output" | tr -d '-')
    run grep -rc "$code" "$ARCPANEL/data/users/$TFUSER/"
    refute_output --regexp ':[1-9]'
    [ ! -e "$ARCPANEL/data/users/$TFUSER/2fa-pending" ]

    run v-add-user-2fa "$TFUSER"
    assert_failure 4
}

@test "v-list-user prints 2FA as yes and the codes left, never the secret or a QR code (E39 W5 N19)" {
    secret=$(user_value "$TFUSER" TWOFA)
    for format in json shell plain csv; do
        run v-list-user "$TFUSER" "$format"
        assert_success
        refute_output --partial "$secret"
        refute_output --partial 'QRCODE'
    done
    run v-list-user "$TFUSER" json
    assert_equal "$(jq -r ".[\"$TFUSER\"].TWOFA" <<< "$output")" yes
    assert_equal "$(jq -r ".[\"$TFUSER\"].TWOFA_RECOVERY_LEFT" <<< "$output")" 10
}

@test "v-check-user-2fa-recovery-code accepts each code once, in any case and grouping, from a panel file only" {
    v-delete-user-2fa "$TFUSER" > /dev/null
    mapfile -t codes < <(enrol)
    assert_equal "${#codes[@]}" 10

    run v-check-user-2fa-recovery-code "$TFUSER" "$(code_file "${codes[0]}")" 203.0.113.21
    assert_success
    assert_output 9
    lower=$(echo "${codes[1]}" | tr '[:upper:]' '[:lower:]' | tr '-' ' ')
    run v-check-user-2fa-recovery-code "$TFUSER" "$(code_file "$lower")" 203.0.113.21
    assert_success
    assert_output 8

    # Used up: a second use fails and reaches the fail2ban log
    run v-check-user-2fa-recovery-code "$TFUSER" "$(code_file "${codes[0]}")" 203.0.113.22
    assert_failure 9
    run tail -n 1 "$AUTHLOG"
    assert_output --partial "$TFUSER 203.0.113.22 failed to login (Invalid or missing 2FA token)"
    run v-check-user-2fa-recovery-code "$TFUSER" "$(code_file 'AAAA-AAAA-AAAA-AAAA')" 203.0.113.22
    assert_failure 9

    # Only a plain file the panel wrote in /tmp (E39 W5 P1)
    printf '%s\n' "${codes[2]}" > /root/arcbats-2fa-code
    run v-check-user-2fa-recovery-code "$TFUSER" /root/arcbats-2fa-code
    assert_failure 10
    ln -sf /root/arcbats-2fa-code /tmp/arcbats-2fa-link
    run v-check-user-2fa-recovery-code "$TFUSER" /tmp/arcbats-2fa-link
    assert_failure 10
    rm -f /root/arcbats-2fa-code /tmp/arcbats-2fa-link
    assert_equal "$(user_value "$TFUSER" TWOFA_RECOVERY | tr ',' '\n' | grep -c .)" 8
}

@test "v-add-user-2fa-recovery-codes replaces every code; refused while 2FA is off" {
    mapfile -t old < <(user_value "$TFUSER" TWOFA_RECOVERY | tr ',' '\n')
    run v-add-user-2fa-recovery-codes "$TFUSER" json
    assert_success
    first=$(jq -r '.RECOVERY_CODES[0]' <<< "$output")
    assert_equal "$(user_value "$TFUSER" TWOFA_RECOVERY | tr ',' '\n' | grep -c .)" 10
    for hash in "${old[@]}"; do
        run grep -c "$hash" "$ARCPANEL/data/users/$TFUSER/user.conf"
        assert_output 0
    done
    run v-check-user-2fa-recovery-code "$TFUSER" "$(code_file "$first")"
    assert_output 9
}

@test "a package change keeps the 2FA keys and the session stamp" {
    v-change-user-password "$TFUSER" "$PASS-2" > /dev/null
    before=$(grep -E "^(TWOFA|TWOFA_STEP|TWOFA_RECOVERY|SESSIONS_VALID_AFTER)=" "$ARCPANEL/data/users/$TFUSER/user.conf" | sort)
    run v-change-user-package "$TFUSER" default yes
    assert_success
    after=$(grep -E "^(TWOFA|TWOFA_STEP|TWOFA_RECOVERY|SESSIONS_VALID_AFTER)=" "$ARCPANEL/data/users/$TFUSER/user.conf" | sort)
    assert_equal "$after" "$before"
    assert_equal "$(echo "$after" | grep -c "=''")" 0
}

@test "restores keep the recovery codes and drop the QR code of an older archive (E39 W5 §11.2)" {
    local codes archive record
    codes=$(user_value "$TFUSER" TWOFA_RECOVERY)
    [ -n "$codes" ]
    # A full restic restore builds user.conf from the allow-listed keys
    record="NAME='x' TWOFA='SECRETX' TWOFA_RECOVERY='$codes' QRCODE='data:image/png;base64,AAAA' ROLE='user'"
    run bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/safe-fs.sh
        parse_backup_record \"\$(restore_record_keys user)\" \"$record\" && restore_record user \"ROLE='user'\""
    assert_success
    assert_output --partial "TWOFA_RECOVERY='$codes'"
    refute_output --partial 'QRCODE'
    # An archive of 1.x still holds the QR code; v-restore-user copies its user.conf
    sed -i "s|^QRCODE='.*'|QRCODE='data:image/png;base64,AAAA'|" "$ARCPANEL/data/users/$TFUSER/user.conf"
    rm -f /backup/"$TFUSER".*.tar
    v-backup-user "$TFUSER" > /dev/null
    archive=$(basename "$(ls -t /backup/"$TFUSER".*.tar | head -n1)")
    v-delete-user "$TFUSER" > /dev/null
    run v-restore-user "$TFUSER" "$archive" 'no' 'no' 'no' 'no' 'no' 'no' 'no'
    assert_success
    assert_equal "$(user_value "$TFUSER" QRCODE)" ''
    assert_equal "$(user_value "$TFUSER" TWOFA_RECOVERY)" "$codes"
    rm -f "/backup/$archive"
}

@test "v-delete-user-2fa clears the secret, the step, the codes and a pending enrolment" {
    v-add-user-2fa "$TFUSER" > /dev/null 2>&1 || true
    printf "SECRET='%s'\nCREATED='%s'\n" AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA "$(date +%s)" > "$ARCPANEL/data/users/$TFUSER/2fa-pending"
    run v-delete-user-2fa "$TFUSER"
    assert_success
    for key in TWOFA TWOFA_STEP TWOFA_RECOVERY QRCODE; do
        assert_equal "$(user_value "$TFUSER" "$key")" ''
    done
    [ ! -e "$ARCPANEL/data/users/$TFUSER/2fa-pending" ]
    run v-add-user-2fa-recovery-codes "$TFUSER"
    assert_failure 3
}

@test "the 2.6.0 upgrade step clears the QR codes 1.x kept in user.conf (E39 W5 §11.4)" {
    sed -i "s|^QRCODE='.*'|QRCODE='data:image/png;base64,AAAA'|" "$ARCPANEL/data/users/$TFUSER/user.conf"
    bash -c 'source /etc/arcpanel/arcpanel.conf; source "$ARCPANEL/func/main.sh"; source_conf "$ARCPANEL/conf/arcpanel.conf"; source "$ARCPANEL/install/upgrade/versions/2.6.0.sh"' > /dev/null 2>&1
    assert_equal "$(user_value "$TFUSER" QRCODE)" ''
}

@test "the TOTP helper names no QR code service (E39 W5 N18)" {
    run grep -c 'QRServerProvider' "$ARCPANEL/lib/2fa/check.php"
    assert_output 0
    [ ! -e "$ARCPANEL/lib/2fa/secret.php" ]
}
