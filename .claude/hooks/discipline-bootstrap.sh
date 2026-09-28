#!/bin/bash
# Written by `discipline hook install --agent claude-code`.
# A Claude Code cloud session starts on a fresh VM without discipline. This installs
# the pinned release, checksum-verified, so the hooks in .claude/settings.json can
# check the change. Locally it does nothing: install discipline yourself.
set -u
[ "${CLAUDE_CODE_REMOTE:-}" = "true" ] || exit 0
command -v discipline >/dev/null 2>&1 && exit 0

version="v0.14.4"
say() { echo "discipline bootstrap: $*" >&2; }
case "$(uname -m)" in
  x86_64|amd64) arch="x86_64" ;;
  aarch64|arm64) arch="aarch64" ;;
  *) say "unsupported architecture $(uname -m); the hooks cannot check this session"; exit 0 ;;
esac
asset="discipline-${arch}-unknown-linux-musl.tar.gz"
base="https://github.com/orieg/discipline/releases/download/${version}"
dir="$(mktemp -d)"
if ! curl -fsSL --retry 3 -o "${dir}/${asset}" "${base}/${asset}" \
  || ! curl -fsSL --retry 3 -o "${dir}/SHA256SUMS" "${base}/SHA256SUMS"; then
  say "could not download ${version} (network access level?); the hooks cannot check this session"
  exit 0
fi
want="$(awk -v f="${asset}" '$2 == f || $2 == "*" f { print $1 }' "${dir}/SHA256SUMS")"
got="$(sha256sum "${dir}/${asset}" | awk '{ print $1 }')"
if [ -z "${want}" ] || [ "${want}" != "${got}" ]; then
  say "checksum mismatch for ${asset}; not installed"
  exit 0
fi
mkdir -p "${dir}/x" "${HOME}/.local/bin"
if tar -xzf "${dir}/${asset}" -C "${dir}/x" \
  && install -m 0755 "${dir}/x/discipline" "${HOME}/.local/bin/discipline"; then
  say "installed $("${HOME}/.local/bin/discipline" --version)"
  # The hooks call `discipline` by name: put its directory on PATH for the session.
  case ":${PATH}:" in
    *":${HOME}/.local/bin:"*) ;;
    *)
      if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
        echo "export PATH=\"${HOME}/.local/bin:\${PATH}\"" >> "${CLAUDE_ENV_FILE}"
      else
        say "${HOME}/.local/bin is not on PATH; the hooks cannot find discipline"
      fi
      ;;
  esac
else
  say "could not unpack ${asset}; the hooks cannot check this session"
fi
exit 0
