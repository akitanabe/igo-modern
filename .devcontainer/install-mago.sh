#!/usr/bin/env bash

set -euo pipefail

# Install a pinned Mago binary into the shared command path used by the container.
MAGO_VERSION="${MAGO_VERSION:-1.29.0}"
INSTALL_DIR="${INSTALL_DIR:-/usr/local/bin}"
INSTALLER_URL="https://carthage.software/mago.sh"

if [ ! -d "${INSTALL_DIR}" ]; then
    mkdir -p "${INSTALL_DIR}"
fi

if [ ! -w "${INSTALL_DIR}" ]; then
    echo "Cannot write to ${INSTALL_DIR}. Run this script as a user with permission to install command binaries." >&2
    exit 1
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "${tmp_dir}"' EXIT

installer="${tmp_dir}/mago.sh"

curl --proto '=https' --tlsv1.2 -sSf -o "${installer}" "${INSTALLER_URL}"
bash "${installer}" --version="${MAGO_VERSION}" --install-dir="${INSTALL_DIR}"

mago --version
