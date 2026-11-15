#!/usr/bin/env bash
# Beacon Monitor 更新脚本
# 用法：bash update.sh            （拉取新版本并重启，数据不动）
#       bash update.sh --build    （强制用本地源码重新构建）
set -euo pipefail

cd "$(dirname "$0")"

info() { printf '\033[36m%s\033[0m\n' "$*"; }
ok() { printf '\033[32m%s\033[0m\n' "$*"; }
warn() { printf '\033[33m%s\033[0m\n' "$*"; }
die() { printf '\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

FORCE_BUILD=0
[ "${1:-}" = "--build" ] && FORCE_BUILD=1

[ -f docker-compose.yml ] || die "请在安装目录（含 docker-compose.yml）里执行本脚本"

if docker compose version >/dev/null 2>&1; then
  COMPOSE="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE="docker-compose"
else
  die "找不到 Docker Compose"
fi

PORT="$(grep -E '^HOST_PORT=' .env 2>/dev/null | cut -d= -f2- || true)"
[ -n "${PORT}" ] || PORT=8080

AUTO_UPDATE="$(grep -E '^AUTO_UPDATE=' .env 2>/dev/null | cut -d= -f2- || true)"
PROFILE_ARGS=""
if [ "${AUTO_UPDATE}" = "1" ]; then
  PROFILE_ARGS="--profile auto-update"
fi

before="$(curl -fsS --max-time 5 "http://127.0.0.1:${PORT}/api/version" 2>/dev/null | sed -n 's/.*"version":"\([^"]*\)".*/\1/p' || true)"

# ---------- 1. 源码更新（如果是 git 仓库） ----------
if [ -d .git ] && command -v git >/dev/null 2>&1; then
  info "→ 拉取最新源码"
  git pull --ff-only || warn "git pull 失败，继续尝试用镜像更新"
fi

# ---------- 2. 拉新镜像，失败则本地构建 ----------
info "→ 获取新版本"
if [ "${FORCE_BUILD}" = "0" ] && ${COMPOSE} pull --quiet beacon 2>/dev/null; then
  ok "已拉取最新镜像"
  # shellcheck disable=SC2086
  ${COMPOSE} ${PROFILE_ARGS} up -d --remove-orphans
else
  [ "${FORCE_BUILD}" = "0" ] && warn "镜像拉取失败（可能还没发布或仓库为私有），改用本地构建"
  # shellcheck disable=SC2086
  ${COMPOSE} ${PROFILE_ARGS} up -d --build --remove-orphans
fi

# ---------- 3. 等待就绪 ----------
info "→ 等待服务就绪"
READY=0
for _ in $(seq 1 40); do
  if curl -fsS "http://127.0.0.1:${PORT}/healthz" >/dev/null 2>&1; then READY=1; break; fi
  sleep 2
done

if [ "${READY}" != "1" ]; then
  warn "健康检查未通过，最近日志："
  # shellcheck disable=SC2086
  ${COMPOSE} ${PROFILE_ARGS} logs --tail=40 || true
  die "更新失败，可用 docker compose up -d --build 重试"
fi

after="$(curl -fsS --max-time 5 "http://127.0.0.1:${PORT}/api/version" 2>/dev/null | sed -n 's/.*"version":"\([^"]*\)".*/\1/p' || true)"

if [ -n "${before}" ] && [ "${before}" = "${after}" ]; then
  ok "已是最新版本（v${after}）"
else
  ok "更新完成：${before:-未知} → ${after:-未知}"
fi
printf '  状态页  http://127.0.0.1:%s/\n' "${PORT}"
