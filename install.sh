#!/bin/sh
# Sugar-Box 一键安装
#
#   sh -c "$(curl -fsSL https://raw.githubusercontent.com/hexoblog-web-site/Glue/main/install.sh)"
#
# 做的事：
#   1. 检查 Docker 与 Compose v2
#   2. 把代码放到 /opt/glue（没权限就放 ~/glue）
#   3. 自动生成数据库密码和后台密码，写好 .env —— 不需要你改任何文件
#   4. 优先拉官方镜像，拉不到就地构建
#   5. 起容器、等健康检查通过、打印后台地址与账号
#
# 可选环境变量：
#   ZAN_DIR=/opt/glue      安装目录
#   ZAN_PORT=8080          宿主机端口（前面有反代就改成 127.0.0.1:8080）
#   ZAN_REPO=...           换成自己的 fork
#   ZAN_TAG=latest         镜像标签
#   ZAN_DRY_RUN=1          只准备文件，不启动容器
set -eu

REPO="${ZAN_REPO:-https://github.com/hexoblog-web-site/Glue.git}"
TAG="${ZAN_TAG:-latest}"
IMAGE="ghcr.io/hexoblog-web-site/glue:${TAG}"
PORT="${ZAN_PORT:-8080}"
DRY="${ZAN_DRY_RUN:-0}"

say() { printf '\033[36m[glue]\033[0m %s\n' "$*"; }
die() { printf '\033[31m[glue] %s\033[0m\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------- 环境检查
command -v docker >/dev/null 2>&1 || die "没找到 docker，请先安装：https://docs.docker.com/engine/install/"
docker info >/dev/null 2>&1 || die "docker 守护进程没起来，或当前用户没有权限（试试 sudo，或把用户加进 docker 组）"
docker compose version >/dev/null 2>&1 || die "缺少 docker compose v2，请安装 docker-compose-plugin"

# ---------------------------------------------------------------- 准备目录
if [ -n "${ZAN_DIR:-}" ]; then
    DIR="$ZAN_DIR"
elif [ "$(id -u)" = "0" ]; then
    DIR=/opt/glue
else
    DIR="$HOME/glue"
fi

if [ -d "$DIR/.git" ]; then
    say "已存在代码目录，尝试更新：$DIR"
    git -C "$DIR" pull --ff-only >/dev/null 2>&1 || say "更新失败（可能改过文件），继续用现有代码"
elif [ -f "$DIR/docker-compose.yml" ]; then
    say "目录里已有 compose 文件，直接使用：$DIR"
else
    say "拉取代码到 $DIR"
    mkdir -p "$(dirname "$DIR")" 2>/dev/null || true
    git clone --depth 1 "$REPO" "$DIR" >/dev/null 2>&1 || die "git clone 失败，请检查网络或改用 ZAN_DIR 指定可写目录"
fi

cd "$DIR"

# ---------------------------------------------------------------- 生成 .env
rand() { tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "${1:-20}"; }

if [ -f .env ]; then
    say ".env 已存在，保留原有配置（不覆盖密码）"
else
    DB_PASS=$(rand 24)
    ADMIN_PASS=$(rand 16)
    cat > .env <<EOF
# 由 install.sh 自动生成于 $(date '+%Y-%m-%d %H:%M:%S')
DB_NAME=zan
DB_USER=zan
DB_PASS=${DB_PASS}
DB_ROOT_PASS=${DB_PASS}
DB_PREFIX=zan_

ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=${ADMIN_PASS}

# 前面有 Nginx / 宝塔反代时改成 127.0.0.1:8080，只监听本机
ZAN_PORT=${PORT}
ZAN_TAG=${TAG}
SITE_NAME=
ALLOWED_ORIGINS=
TZ=Asia/Shanghai
EOF
    chmod 600 .env
    say "已生成 .env（数据库密码与后台密码都是随机的）"
fi

if [ "$DRY" = "1" ]; then
    say "DRY_RUN=1，跳过启动。文件已就绪：$DIR"
    exit 0
fi

# ---------------------------------------------------------------- 起容器
say "准备镜像 $IMAGE"
if docker compose pull -q 2>/dev/null; then
    say "已拉取官方镜像"
else
    say "拉取失败（可能还没发布，或没有权限），改为本地构建，约 1-2 分钟"
    docker compose build
fi

say "启动容器"
docker compose up -d

# ---------------------------------------------------------------- 等健康
printf '[glue] 等待服务就绪'
i=0
while [ "$i" -lt 30 ]; do
    st=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' zan 2>/dev/null || echo unknown)
    [ "$st" = "healthy" ] && break
    printf '.'
    sleep 3
    i=$((i + 1))
done
printf '\n'

# ---------------------------------------------------------------- 汇报
PORT_NOW=$(grep -E '^ZAN_PORT=' .env | cut -d= -f2- || true)
PORT_NOW=${PORT_NOW:-$PORT}
EMAIL=$(grep -E '^ADMIN_EMAIL=' .env | cut -d= -f2- || true)
EMAIL=${EMAIL:-admin@example.com}
PASS=$(grep -E '^ADMIN_PASSWORD=' .env | cut -d= -f2- || true)
if [ -z "$PASS" ]; then
    PASS="（.env 里没写，是首次启动随机生成的：docker compose logs zan | grep 管理员）"
fi

{
    echo "Sugar-Box 部署信息"
    echo "目录: $DIR"
    echo "站点: http://127.0.0.1:${PORT_NOW}/"
    echo "后台: http://127.0.0.1:${PORT_NOW}/admin/login.php"
    echo "账号: $EMAIL"
    echo "密码: $PASS"
    echo "生成时间: $(date '+%Y-%m-%d %H:%M:%S')"
} > "$DIR/CREDENTIALS.txt"
chmod 600 "$DIR/CREDENTIALS.txt"

echo
say "完成"
echo "  站点   http://127.0.0.1:${PORT_NOW}/        （看到 \"code\": 0 就是好了）"
echo "  后台   http://127.0.0.1:${PORT_NOW}/admin/login.php"
echo "  账号   ${EMAIL}"
echo "  密码   ${PASS}"
echo "  备注   已存到 ${DIR}/CREDENTIALS.txt"
echo
echo "  前面有 Nginx/宝塔反代的话，把 .env 里 ZAN_PORT 改成 127.0.0.1:${PORT_NOW} 再 docker compose up -d 一次。"
echo "  想改后台密码：登录后台 → 设置，或直接改数据库 admin 表。"
