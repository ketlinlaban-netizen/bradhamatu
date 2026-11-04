#!/usr/bin/env bash

set -euo pipefail

key_file="${HOME}/.ssh/pandatechsco_onlineconsole"
host="169.58.129.32"
port="6622"
user="pandatechsco"
remote_directory="/home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke"

if [[ ! -r "$key_file" ]]; then
    printf 'SSH key is missing or unreadable: %s\n' "$key_file" >&2
    printf '%s\n' "Convert the PuTTY key to this path first; see the SSH instructions in README.md." >&2
    exit 2
fi

exec ssh \
    -t \
    -p "$port" \
    -i "$key_file" \
    -o IdentitiesOnly=yes \
    "${user}@${host}" \
    "cd '${remote_directory}' && exec \"\${SHELL:-/bin/bash}\" -l"
