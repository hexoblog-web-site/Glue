#!/usr/bin/env bash
#
# Beacon Monitor 一键安装脚本
#
# 用法一（推荐，一行）：
#   curl -fsSL https://raw.githubusercontent.com/hexoblog-web-site/beacon-monitor/main/install.sh | bash
#
# 用法二（已克隆仓库）：
#   cd beacon-monitor && bash install.sh
#
# 可选环境变量：
#   ADMIN_PASSWORD=xxx                指定后台密码（默认随机生成）
#   HOST_PORT=8080                    指定宿主机端口（默认自动挑空闲端口）
#   INSTALL_DIR=/opt/beacon-monitor   一行模式下的安装目录
#   SKIP_DOCKER_INSTALL=1             缺少 Docker 时不自作主张安装
#
set -euo pipefail

REPO_SLUG="hexoblog-web-site/beacon-monitor"
REPO_URL="https://github.com/${REPO_SLUG}.git"
TARBALL_URL="https://codeload.github.com/${REPO_SLUG}/tar.gz/refs/heads/main"
INSTALL_DIR="${INSTALL_DIR:-/opt/beacon-monitor}"

C_OK=$'\033[32m'; C_INFO=$'\033[36m'; C_WARN=$'\033[33m'; C_ERR=$'\033[31m'; C_DIM=$'\033[2m'; C_END=$'\033[0m'
info() { printf '%s\n' "${C_INFO}$*${C_END}"; }
ok()   { printf '%s\n' "${C_OK}$*${C_END}"; }
warn() { printf '%s\n' "${C_WARN}$*${C_END}"; }
die()  { printf '%s\n' "${C_ERR}$*${C_END}" >&2; exit 1; }
step() { printf '\n%s\n' "${C_INFO}▶ $*${C_END}"; }

printf '\n%s\n' "🛢  Beacon Monitor 一键安装"
printf '%s\n' "────────────────────────────────────"

# ---------- 0. 判断运行环境 ----------
SELF="${BASH_SOURCE[0]:-}"
APP_DIR=""
if [ -n "${SELF}" ] && [ -f "${SELF}" ] && [ -f "$(dirname "${SELF}")/docker-compose.yml" ]; then
  APP_DIR="$(cd "$(dirname "${SELF}")" && pwd)"
  ok "检测到本地项目目录：${APP_DIR}"
else
  # curl | bash 模式：需要先拿到代码
  step "下载项目代码"
  TARGET="${INSTALL_DIR}"
  if ! mkdir -p "${TARGET}" 2>/dev/null; then
    TARGET="${HOME}/beacon-monitor"
    warn "无法写入 ${INSTALL_DIR}，改用 ${TARGET}"
    mkdir -p "${TARGET}"
  fi
  if [ -d "${TARGET}/.git" ]; then
    info "已有仓库，尝试更新…"
    git -C "${TARGET}" pull --ff-only >/dev/null 2>&1 || warn "更新失败，继续使用现有代码"
  elif command -v git >/dev/null 2>&1; then
    if ! git clone --depth 1 "${REPO_URL}" "${TARGET}" >/dev/null 2>&1; then
      die "git clone 失败，请检查网络，或手动下载：${REPO_URL}"
    fi
  else
    command -v curl >/dev/null 2>&1 || die "需要 curl 或 git 才能下载项目"
    curl -fsSL "${TARBALL_URL}" | tar xz -C "${TARGET}" --strip-components=1 \
      || die "下载失败，请检查网络后重试"
  fi
  APP_DIR="${TARGET}"
  ok "代码已就绪：${APP_DIR}"
fi

cd "${APP_DIR}"

# ---------- 1. Docker 环境 ----------
step "检查 Docker 环境"
if ! command -v docker >/dev/null 2>&1; then
  if [ "${SKIP_DOCKER_INSTALL:-0}" = "1" ]; then
    die "未安装 Docker。请先执行：curl -fsSL https://get.docker.com | sh"
  fi
  if [ "$(id -u)" != "0" ]; then
    die "未安装 Docker 且当前不是 root。请用 root 执行，或先安装：curl -fsSL https://get.docker.com | sh"
  fi
  warn "未检测到 Docker，正在用官方脚本安装（约 1 分钟）…"
  curl -fsSL https://get.docker.com | sh >/dev/null 2>&1 || die "Docker 安装失败，请手动安装后重试"
  systemctl enable --now docker >/dev/null 2>&1 || true
fi
docker info >/dev/null 2>&1 || die "Docker 已安装但未运行，请执行：systemctl enable --now docker"
ok "Docker $(docker -v | sed 's/Docker version //;s/,.*//')"

if docker compose version >/dev/null 2>&1; then
  COMPOSE="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE="docker-compose"
else
  die "缺少 Docker Compose，请升级 Docker 到 20.10+ 或安装 docker-compose-plugin"
fi

# ---------- 2. 生成配置 ----------
step "生成配置"
ENV_FILE="${APP_DIR}/.env"
FRESH=0
if [ ! -f "${ENV_FILE}" ]; then
  FRESH=1
  PASSWORD="${ADMIN_PASSWORD:-$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 12)}"
  cat >"${ENV_FILE}" <<EOF
# 由 install.sh 生成，修改后执行 docker compose up -d 生效
ADMIN_PASSWORD=${PASSWORD}
HOST_PORT=__PORT__
AUTO_DISCOVER=1
# 自动更新：1 开启（watchtower 每小时检查一次），0 关闭
AUTO_UPDATE=${AUTO_UPDATE:-1}
INSTALL_DIR=${APP_DIR}
DISCOVER_INTERVAL_MIN=10
TZ=Asia/Shanghai
SITE_TITLE=站点服务状态
SITE_DESCRIPTION=实时监控站点与各项服务的可用性
EOF
  ok "已生成 .env（随机管理密码）"
else
  PASSWORD="$(grep -E '^ADMIN_PASSWORD=' "${ENV_FILE}" | cut -d= -f2- || true)"
  ok "检测到已有 .env，沿用原配置"
fi

# 端口：环境变量 > .env 已有值 > 自动挑选
PORT="${HOST_PORT:-$(grep -E '^HOST_PORT=' "${ENV_FILE}" | cut -d= -f2- || true)}"
port_busy() {
  (exec 3<>"/dev/tcp/127.0.0.1/$1") >/dev/null 2>&1 && { exec 3>&-; return 0; }
  return 1
}
if [ -z "${PORT}" ] || [ "${PORT}" = "__PORT__" ]; then
  for candidate in 8080 8081 8090 18080 28080; do
    if ! port_busy "${candidate}"; then PORT="${candidate}"; break; fi
  done
fi
[ -n "${PORT}" ] || die "没有找到可用端口，请用 HOST_PORT=xxxx 指定"
sed -i "s/^HOST_PORT=.*/HOST_PORT=${PORT}/" "${ENV_FILE}"
ok "使用端口：${PORT}"

mkdir -p "${APP_DIR}/data"

# ---------- 3. 构建并启动 ----------
AUTO_UPDATE="$(grep -E '^AUTO_UPDATE=' "${ENV_FILE}" | cut -d= -f2- || true)"
PROFILE_ARGS=""
if [ "${AUTO_UPDATE}" = "1" ]; then
  PROFILE_ARGS="--profile auto-update"
  ok "已开启自动更新（watchtower，默认每小时检查一次）"
else
  ok "自动更新未开启，需要时执行 bash update.sh"
fi

step "构建镜像并启动（首次约 1–3 分钟）"
# shellcheck disable=SC2086
${COMPOSE} ${PROFILE_ARGS} up -d --build

# ---------- 4. 等待就绪 ----------
step "等待服务就绪"
READY=0
for _ in $(seq 1 40); do
  if curl -fsS "http://127.0.0.1:${PORT}/healthz" >/dev/null 2>&1; then READY=1; break; fi
  sleep 2
done
if [ "${READY}" != "1" ]; then
  warn "健康检查未通过，最近日志："
  ${COMPOSE} logs --tail=40 || true
  exit 1
fi

IP="$(curl -fsS --max-time 4 https://api.ipify.org 2>/dev/null || true)"
[ -n "${IP}" ] || IP="<服务器公网IP>"

printf '\n%s\n' "${C_OK}✅ 安装完成${C_END}"
printf '%s\n' "────────────────────────────────────"
printf '  状态页    http://%s:%s/\n' "${IP}" "${PORT}"
printf '  管理后台  http://%s:%s/admin\n' "${IP}" "${PORT}"
printf '  管理密码  %s\n' "${PASSWORD:-（见 .env 的 ADMIN_PASSWORD）}"
printf '  安装目录  %s\n' "${APP_DIR}"
printf '\n'
warn "1) 云服务器请在控制台「防火墙」放行 TCP ${PORT}"
warn "2) 自动发现在启动 6 秒后开始扫描宿主机上已运行的服务，无需手动添加监控项"
if [ "${AUTO_UPDATE}" = "1" ]; then
  warn "3) 以后仓库有新版本会自动更新（最多 1 小时），也可以在 ${APP_DIR} 执行 bash update.sh 立即更新"
else
  warn "3) 以后更新只需在 ${APP_DIR} 执行 bash update.sh"
fi
printf '%s\n\n' "${C_DIM}   查看日志：cd ${APP_DIR} && ${COMPOSE} logs -f${C_END}"
