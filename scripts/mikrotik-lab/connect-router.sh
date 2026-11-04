#!/usr/bin/env bash

set -euo pipefail

router_host="${MIKROTIK_LAB_HOST:-192.168.88.1}"
router_user="${MIKROTIK_LAB_USERNAME:-bradha-rest-monitor}"
ca_file="${MIKROTIK_LAB_CA_FILE:-$HOME/mikrotik-certs/bradha-ca.crt}"

if [[ ! "$router_host" =~ ^192\.168\.88\.([0-9]{1,3})$ ]] || (( 10#${BASH_REMATCH[1]} > 254 )); then
    printf '%s\n' "Refusing non-lab router address; MIKROTIK_LAB_HOST must be in 192.168.88.0/24." >&2
    exit 2
fi

if [[ "$router_user" != "bradha-rest-monitor" ]]; then
    printf '%s\n' "Refusing a non-lab account; MIKROTIK_LAB_USERNAME must be bradha-rest-monitor." >&2
    exit 2
fi

if [[ ! -r "$ca_file" ]]; then
    printf 'Trusted CA file is missing or unreadable: %s\n' "$ca_file" >&2
    exit 2
fi

if [[ -z "${MIKROTIK_LAB_PASSWORD:-}" ]]; then
    if [[ ! -t 0 ]]; then
        printf '%s\n' "Run this script in a terminal for a hidden password prompt, or set MIKROTIK_LAB_PASSWORD in the environment." >&2
        exit 2
    fi
    read -r -s -p "Password for ${router_user}: " router_password
    printf '\n'
else
    router_password="$MIKROTIK_LAB_PASSWORD"
fi

if [[ "$router_password" == *$'\n'* || "$router_password" == *$'\r'* ]]; then
    printf '%s\n' "Multiline passwords are not supported." >&2
    exit 2
fi

escape_curl_config() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    printf '%s' "$value"
}

umask 077
curl_config="$(mktemp "${TMPDIR:-/tmp}/mikrotik-lab-curl.XXXXXX")"
trap 'rm -f -- "$curl_config"' EXIT
printf 'user = "%s:%s"\n' \
    "$(escape_curl_config "$router_user")" \
    "$(escape_curl_config "$router_password")" > "$curl_config"
unset router_password

request_readonly() {
    local endpoint="$1"
    local response status body

    printf '\n=== GET %s ===\n' "$endpoint"
    if ! response="$(curl \
        --silent \
        --show-error \
        --connect-timeout 3 \
        --max-time 8 \
        --cacert "$ca_file" \
        --config "$curl_config" \
        --header 'Accept: application/json' \
        --write-out $'\nHTTP_STATUS:%{http_code}' \
        "https://${router_host}/rest/${endpoint}")"; then
        printf '%s\n' "HTTPS request failed. TLS verification remains enabled." >&2
        return 1
    fi

    status="${response##*$'\n'HTTP_STATUS:}"
    body="${response%$'\n'HTTP_STATUS:*}"
    printf '%s\n' "$body"
    printf 'HTTP status: %s\n' "$status"
    [[ "$status" =~ ^2[0-9][0-9]$ ]]
}

printf 'Lab router: %s\nAccount: %s\nCA: %s\n' "$router_host" "$router_user" "$ca_file"
printf '%s\n' "Only authenticated HTTPS GET requests will be sent; no router settings are changed."

failed=0
for endpoint in \
    system/identity \
    system/resource \
    interface \
    ip/dhcp-server/lease
do
    request_readonly "$endpoint" || failed=1
done

if (( failed )); then
    printf '%s\n' "One or more read-only requests failed; inspect the HTTP status above." >&2
    exit 1
fi
