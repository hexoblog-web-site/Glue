#!/usr/bin/env bash
#
# Glue（PHP 点赞服务）→ Beacon Monitor（Node 一体化）
#
# 用法一（推荐，一条命令）：
#   curl -fsSL https://raw.githubusercontent.com/hexoblog-web-site/beacon-monitor/main/tools/migrate-from-glue.sh | bash
#
# 用法二（已下载本仓库）：
#   bash tools/migrate-from-glue.sh
#
# 它做四件事：
#   1. 从 Glue 的 MySQL 导出点赞明细（只读，不动原库）
#   2. 停掉 Glue 容器，用同一个端口起 Beacon Monitor
#   3. 把点赞明细写进 Beacon 的 SQLite（一人一篇一次，不丢人也不翻倍）
#   4. 起不来就自动把 Glue 拉回来 —— 不让站点停在那里
#
# 可选环境变量：
#   BEACON_DIR=/opt/beacon-monitor    Beacon 安装目录
#   GLUE_APP=zan                      Glue 应用容器名
#   GLUE_DB=zan-db                    Glue 数据库容器名
#   DB_PREFIX=zan_                    表前缀（不确定就别填，脚本会自己查）
#   HOST_PORT=8080                    对外端口（默认沿用 Glue 当前的端口）
#   KEEP_GLUE=1                       迁移后保留 Glue 容器（默认就保留，只是停掉）
#
# 回滚（后悔了想换回 PHP 版）：
#   docker compose -f /opt/beacon-monitor/docker-compose.yml down && docker start zan
#
set -euo pipefail

REPO_SLUG="hexoblog-web-site/beacon-monitor"
TARBALL_URL="https://codeload.github.com/${REPO_SLUG}/tar.gz/refs/heads/main"
IMAGE="${IMAGE:-ghcr.io/hexoblog-web-site/beacon-monitor:latest}"

BEACON_DIR="${BEACON_DIR:-/opt/beacon-monitor}"
BEACON_CONTAINER="${BEACON_CONTAINER:-beacon-monitor}"
GLUE_APP="${GLUE_APP:-zan}"
GLUE_DB="${GLUE_DB:-zan-db}"
EXPORT_DIR_NAME="glue-migrate"

C_OK=$'\033[32m'; C_INFO=$'\033[36m'; C_WARN=$'\033[33m'; C_ERR=$'\033[31m'; C_DIM=$'\033[2m'; C_END=$'\033[0m'
info() { printf '%s\n' "${C_INFO}$*${C_END}"; }
ok()   { printf '%s\n' "${C_OK}$*${C_END}"; }
warn() { printf '%s\n' "${C_WARN}$*${C_END}"; }
die()  { printf '%s\n' "${C_ERR}$*${C_END}" >&2; exit 1; }
step() { printf '\n%s\n' "${C_INFO}▶ $*${C_END}"; }

printf '\n%s\n' "⇄  Glue → Beacon Monitor 迁移"
printf '%s\n' "────────────────────────────────────"

# ---------- 0. 环境 ----------
command -v docker >/dev/null 2>&1 || die "没找到 docker，请先安装 Docker。"

if docker compose version >/dev/null 2>&1; then
  DC="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  DC="docker-compose"
else
  die "没找到 docker compose，请先安装 Compose 插件。"
fi

container_exists() { docker inspect "$1" >/dev/null 2>&1; }
env_of() {
  docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$1" 2>/dev/null \
    | sed -n "s/^$2=//p" | head -1
}
# 容器是否在运行
is_running() { [ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null || echo false)" = "true" ]; }

# ---------- 1. 找到 Glue ----------
step "检查 Glue 容器"

if ! container_exists "$GLUE_APP"; then
  # 容器名被改过的话，按镜像名找一次
  FOUND=$(docker ps -a --format '{{.Names}}\t{{.Image}}' | grep -iE 'glue|/zan' | head -1 | cut -f1 || true)
  if [ -n "${FOUND}" ]; then
    warn "没找到容器 ${GLUE_APP}，按镜像特征认定为：${FOUND}"
    GLUE_APP="${FOUND}"
  else
    die "找不到 Glue 容器。用 GLUE_APP=你的容器名 再跑一次，例如：
  GLUE_APP=zan bash migrate-from-glue.sh"
  fi
fi
ok "Glue 应用容器：${GLUE_APP}"

if ! container_exists "$GLUE_DB"; then
  GUESS=$(docker ps --format '{{.Names}}' | grep -iE 'mysql|maria|zan.*db' | head -1 || true)
  if [ -n "${GUESS}" ]; then
    GLUE_DB="${GUESS}"
  fi
fi
container_exists "$GLUE_DB" || die "找不到 Glue 的数据库容器（默认 ${GLUE_DB}）。用 GLUE_DB=容器名 指定。"
ok "Glue 数据库容器：${GLUE_DB}"

# ---------- 1b. 安装目录（先定下来，导出文件要落在它的 data/ 里） ----------
step "确定安装目录"

if ! mkdir -p "$BEACON_DIR" 2>/dev/null; then
  warn "没有权限写 ${BEACON_DIR}，改用 ${HOME}/beacon-monitor"
  BEACON_DIR="${HOME}/beacon-monitor"
fi
mkdir -p "$BEACON_DIR" || die "无法创建安装目录 ${BEACON_DIR}"
ok "安装目录：${BEACON_DIR}"

# ---------- 2. 数据库凭据 ----------
step "读取数据库连接信息"

DB_USER=$(env_of "$GLUE_DB" MYSQL_USER); [ -n "$DB_USER" ] || DB_USER=$(env_of "$GLUE_APP" DB_USER)
DB_PASS=$(env_of "$GLUE_DB" MYSQL_PASSWORD); [ -n "$DB_PASS" ] || DB_PASS=$(env_of "$GLUE_APP" DB_PASS)
DB_NAME=$(env_of "$GLUE_DB" MYSQL_DATABASE); [ -n "$DB_NAME" ] || DB_NAME=$(env_of "$GLUE_APP" DB_NAME)
PREFIX="${DB_PREFIX:-$(env_of "$GLUE_APP" DB_PREFIX)}"
[ -n "${DB_USER}" ] && [ -n "${DB_PASS}" ] && [ -n "${DB_NAME}" ] \
  || die "读不到数据库用户名/密码/库名，请手动设置 DB_USER/DB_PASS/DB_NAME 环境变量后重试。"

mysql_q() {
  docker exec -e MYSQL_PWD="$DB_PASS" "$GLUE_DB" \
    mysql -u"$DB_USER" -N -B "$DB_NAME" -e "$1" 2>/dev/null
}

# 前缀优先从配置里读，其次按 zan_ 猜，再不行就扫库
if [ -z "${PREFIX}" ]; then
  PREFIX="zan_"
fi
HAS_LOG=$(mysql_q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='${PREFIX}log'" || echo 0)
if [ "${HAS_LOG:-0}" = "0" ]; then
  FOUND_TABLE=$(mysql_q "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name LIKE '%log' LIMIT 1" || true)
  if [ -n "${FOUND_TABLE}" ]; then
    PREFIX="${FOUND_TABLE%log}"
  else
    die "库里找不到 ${PREFIX}log 表，Glue 可能还没安装完。"
  fi
fi
ok "库 ${DB_NAME}，表前缀 ${PREFIX}（用户 ${DB_USER}）"

# ---------- 3. 导出点赞明细 ----------
step "导出点赞明细（只读，不动 Glue 原始数据）"

EXPORT_DIR="${BEACON_DIR%/}/data/${EXPORT_DIR_NAME}"
mkdir -p "$EXPORT_DIR"
chmod 700 "$EXPORT_DIR" 2>/dev/null || true

info "正在读取 ${PREFIX}log …"
mysql_q "SELECT TO_BASE64(page_key), TO_BASE64(visitor_hash), UNIX_TIMESTAMP(created_at) FROM \`${PREFIX}log\`" \
  > "${EXPORT_DIR}/likes.tsv" || die "导出失败。检查数据库是否在运行：docker ps | grep ${GLUE_DB}"

LIKES=$(wc -l < "${EXPORT_DIR}/likes.tsv" | tr -d ' ')
if [ "${LIKES}" = "0" ]; then
  warn "Glue 里一条点赞记录都没有，继续迁移（新站起步）。"
  # 制造一行占位，让导入脚本不报错
  : > "${EXPORT_DIR}/likes.tsv"
fi
ok "点赞记录 ${LIKES} 条"

info "正在读取 ${PREFIX}page …"
mysql_q "SELECT TO_BASE64(page_key), TO_BASE64(title), UNIX_TIMESTAMP(updated_at)*1000 FROM \`${PREFIX}page\`" \
  > "${EXPORT_DIR}/pages.tsv" || : > "${EXPORT_DIR}/pages.tsv"
ok "文章标题 $(wc -l < "${EXPORT_DIR}/pages.tsv" | tr -d ' ') 条"

# ---------- 4. 准备 Beacon ----------
step "准备 Beacon Monitor"

if [ ! -f "${BEACON_DIR}/docker-compose.yml" ]; then
  info "安装目录 ${BEACON_DIR} 不存在，正在下载项目…"
  mkdir -p "${BEACON_DIR}"
  TMP_TAR=$(mktemp)
  curl -fsSL --connect-timeout 15 "${TARBALL_URL}" -o "${TMP_TAR}" \
    || die "下载失败，请检查网络，或先手动安装：curl -fsSL https://raw.githubusercontent.com/${REPO_SLUG}/main/install.sh | bash"
  tar -xzf "${TMP_TAR}" -C "${BEACON_DIR}" --strip-components=1
  rm -f "${TMP_TAR}"
fi
ok "项目目录：${BEACON_DIR}"

# 密码：没有 .env 就生成一个，免得 compose 用默认值起不来
if [ ! -f "${BEACON_DIR}/.env" ]; then
  GEN_PASS=$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 16 || true)
  [ -n "${GEN_PASS}" ] || GEN_PASS="beacon$(date +%s)"
  {
    echo "ADMIN_PASSWORD=${GEN_PASS}"
    echo "SITE_TITLE=站点服务状态"
  } > "${BEACON_DIR}/.env"
  chmod 600 "${BEACON_DIR}/.env"
  ADMIN_PASSWORD="${GEN_PASS}"
else
  ADMIN_PASSWORD=$(sed -n 's/^ADMIN_PASSWORD=//p' "${BEACON_DIR}/.env" | head -1)
fi

# 端口沿用 Glue 当前对外的那个，反代不用改
GLUE_PORT=$(docker port "$GLUE_APP" 2>/dev/null | head -1 | sed 's/.*://' || true)
HOST_PORT="${HOST_PORT:-${GLUE_PORT:-8080}}"
ok "对外端口：${HOST_PORT}（与原 Glue 一致）"

info "拉取镜像 ${IMAGE} …"
if ! docker pull "$IMAGE" >/dev/null 2>&1; then
  warn "镜像拉不动（国内 GHCR 常见），改为本地构建，第一次会慢几分钟…"
fi

# ---------- 5. 切换 ----------
step "停 Glue、起 Beacon"

GLUE_STOPPED=0
rollback() {
  [ "${GLUE_STOPPED}" = "1" ] || return 0
  warn "Beacon 没能起来，正在回滚到 Glue…"
  $DC --project-directory "${BEACON_DIR}" -f "${BEACON_DIR}/docker-compose.yml" down >/dev/null 2>&1 || true
  docker start "$GLUE_APP" >/dev/null 2>&1 || docker restart "$GLUE_APP" >/dev/null 2>&1 || true
  warn "已回滚。Glue 容器没删，数据卷也还在，站点应已恢复。"
  warn "排查方向：docker logs ${BEACON_CONTAINER} --tail 50"
}
trap 'RC=$?; if [ $RC -ne 0 ]; then rollback; fi' EXIT

if is_running "$GLUE_APP"; then
  docker stop "$GLUE_APP" >/dev/null
  GLUE_STOPPED=1
  ok "Glue 已停止（容器保留，可回滚）"
else
  GLUE_STOPPED=1
  warn "Glue 本来就没在运行，直接起 Beacon"
fi

HOST_PORT="$HOST_PORT" IMAGE="$IMAGE" $DC --project-directory "${BEACON_DIR}" -f "${BEACON_DIR}/docker-compose.yml" up -d --no-build \
  || HOST_PORT="$HOST_PORT" IMAGE="$IMAGE" $DC --project-directory "${BEACON_DIR}" -f "${BEACON_DIR}/docker-compose.yml" up -d

info "等待健康检查…"
HEALTHY=0
for _ in $(seq 1 40); do
  if curl -fsS --max-time 2 "http://127.0.0.1:${HOST_PORT}/healthz" >/dev/null 2>&1; then
    HEALTHY=1; break
  fi
  sleep 2
done
[ "$HEALTHY" = "1" ] || die "Beacon 在 80 秒内没有响应 /healthz。"

ok "Beacon 已启动并通过健康检查"

# ---------- 6. 写入点赞数据 ----------
step "导入点赞数据"

if [ ! -f "${BEACON_DIR}/tools/import-glue.js" ]; then
  warn "镜像里没有导入脚本（旧镜像），改用宿主机挂载的方式执行"
fi

# 容器内缺 tools 就补进去（旧镜像 / 本地构建漏拷的情况）
if ! docker exec "$BEACON_CONTAINER" test -f /app/tools/import-glue.js >/dev/null 2>&1; then
  docker cp "${BEACON_DIR}/tools" "${BEACON_CONTAINER}:/app/tools" >/dev/null 2>&1 || true
fi

docker exec "$BEACON_CONTAINER" node tools/import-glue.js "/data/${EXPORT_DIR_NAME}" \
  || die "导入失败，但 Beacon 已启动。可手动重试：
  docker exec ${BEACON_CONTAINER} node tools/import-glue.js /data/${EXPORT_DIR_NAME}"

# ---------- 7. 收尾 ----------
trap - EXIT

IP=$(hostname -I 2>/dev/null | awk '{print $1}')
[ -n "${IP:-}" ] || IP="服务器IP"

printf '\n'
ok "迁移完成"
printf '%s\n' "────────────────────────────────────"
printf '  后台地址  http://%s:%s/admin\n' "$IP" "$HOST_PORT"
printf '  接入脚本  http://%s:%s/e.js\n' "$IP" "$HOST_PORT"
if [ -n "${ADMIN_PASSWORD:-}" ]; then
  printf '  后台密码  %s\n' "${ADMIN_PASSWORD}"
else
  printf '  后台密码  见 %s/.env 里的 ADMIN_PASSWORD\n' "${BEACON_DIR}"
fi
printf '\n'
info "接下来："
printf '%s\n' "  1. 打开后台 → 互动统计，确认点赞数已经过来了"
printf '%s\n' "  2. 博客里把旧的 Glue 地址换成上面的 /e.js（一行 script）"
printf '%s\n' "  3. 之后升级都在 Beacon 后台点，不再需要碰服务器"
printf '\n'
printf '%s\n' "${C_DIM}想换回 Glue：docker compose -f ${BEACON_DIR}/docker-compose.yml down && docker start ${GLUE_APP}${C_END}"
printf '%s\n' "${C_DIM}Glue 的容器和数据卷都还在，随时可以退回去。${C_END}"
printf '\n'
