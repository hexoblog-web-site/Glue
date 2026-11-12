# Glue 3.0

Hexo 博客的**自托管点赞服务**，对标 Twikoo 的部署体验：一条命令起容器，前端一行脚本接入。

PHP + MySQL，只做点赞。**不记录任何浏览、访客、来源、地区或设备信息**——数据库里只有文章路径、标题，以及加盐哈希后的匿名指纹。

- **只做点赞**：`<div data-zan data-zan-scope="content"></div>` 一行接入，样式自动注入
- **一人一篇一票**：浏览器本地 id + 服务端指纹双重去重，同一篇只能点一次
- **只在内容页可点**：容器默认只读，只有显式声明 `data-zan-scope="content"` 的才渲染成按钮；列表页、侧栏只显示数字
- **三条表**：`page` / `log` / `admin`，没有任何事件流水
- **一条命令起服务**：容器启动时自动建库建表建管理员，不用打开安装向导

---

## 安装

### 方式一：一行命令（和 twikoo 一样）

```bash
sh -c "$(curl -fsSL https://raw.githubusercontent.com/hexoblog-web-site/Glue/main/install.sh)"
```

一行里做完：检查 Docker → 拉代码 → **自动生成数据库密码和后台密码**（你不用改任何文件）→ 拉官方镜像 → 起容器 → 等健康检查 → 打印后台地址与账号。

装完在 `/opt/glue`（非 root 用户是 `~/glue`），凭据写在同目录的 `CREDENTIALS.txt`（600 权限）。

可选参数（都是环境变量）：

```bash
ZAN_PORT=127.0.0.1:8090 sh install.sh    # 前面有反代时只监听本机
ZAN_DIR=/srv/glue        sh install.sh    # 换安装目录
```

### 方式二：docker compose

```bash
git clone https://github.com/hexoblog-web-site/Glue.git
cd Glue
cp .env.example .env
vi .env                     # 只需要填一个 DB_PASS
docker compose pull || docker compose build
docker compose up -d
```

打开 `http://服务器IP:8080/`，看到 `"code": 0` 就是好了。
首次启动的管理员账号和随机密码会打印在日志里：

```bash
docker compose logs zan | grep 管理员
```

> 如果你前面有 Nginx / 宝塔反代，把 `.env` 里的 `ZAN_PORT` 改成 `127.0.0.1:8080` 只监听本机，再反代到域名。

### 方式三：docker run（复用已有的 MySQL）

镜像已发布在 GitHub Container Registry，不需要自己构建：

```bash
docker run -d --name zan \
  -p 8080:80 \
  -e DB_HOST=你的MySQL地址 -e DB_PORT=3306 \
  -e DB_NAME=zan -e DB_USER=zan -e DB_PASS=数据库密码 \
  -e ADMIN_EMAIL=you@example.com -e ADMIN_PASSWORD=你的后台密码 \
  -v zan_data:/data \
  ghcr.io/hexoblog-web-site/glue:latest
```

数据库可以复用现有的 MySQL（本程序的 compose 自带一个 `mysql:5.7`，不用另装）。


---

## 环境变量

| 变量 | 默认 | 说明 |
|---|---|---|
| `DB_HOST` | `db` | MySQL 地址 |
| `DB_PORT` | `3306` | MySQL 端口 |
| `DB_NAME` | `zan` | 库名 |
| `DB_USER` | `zan` | 账号 |
| `DB_PASS` | — | **必填** |
| `DB_PREFIX` | `zan_` | 表前缀，多站点共库时改 |
| `ADMIN_EMAIL` | `admin@example.com` | 首次启动创建的管理员账号 |
| `ADMIN_PASSWORD` | 随机 16 位 | 留空则随机生成并打印一次 |
| `SITE_NAME` | 空 | 后台显示的名字 |
| `ALLOWED_ORIGINS` | 空 | 跨域白名单，逗号分隔；留空不限制 |
| `ZAN_PORT` | `8080` | 宿主机端口（compose 用） |
| `FORCE_CONFIG` | — | 设为 `1` 时用环境变量重写 `config.php`（改数据库密码时用） |

配置写在 `/data/config.php`（数据卷里），改后台设置不会被重启覆盖；
只有数据库连接信息变了才需要 `FORCE_CONFIG=1` 重建一次。

---

## 接到 Hexo 上

### 1. 模板里放容器

文章模板（`layout/post.ejs` 或主题的 `post.pug`）里，正文结束处：

```html
<div data-zan data-zan-scope="content"></div>
```

### 2. 页面底部引脚本

```html
<script src="https://zan.你的域名/api/js/zan.js" data-server="https://zan.你的域名" defer></script>
```

- 脚本和接口同域时可以省略 `data-server`
- CSS 由脚本自动注入，不用另外引
- 想用自带样式以外的外观，加 `data-css="off"` 自己写

### 3. 外观参数

```html
<!-- 心形（默认） -->
<div data-zan data-zan-scope="content"></div>

<!-- 带文字的胶囊 -->
<div data-zan data-zan-scope="content" data-zan-variant="pill"></div>

<!-- 小号、换颜色、文案自定义 -->
<div data-zan data-zan-scope="content" data-zan-size="sm"
     data-zan-color="#0b7285" data-zan-label="喜欢"></div>

<!-- 允许重复点赞（服务端仍按访客去重） -->
<div data-zan data-zan-scope="content" data-zan-once="false"></div>
```

### 4. 列表页只显示数字

首页、归档、标签页里的卡片，**去掉** `data-zan-scope="content"`：

```html
<div data-zan data-zan-key="/2026/11/hello/"></div>
```

只读容器渲染成 `<span>`，不是按钮，点了不发请求。

### 5. 监听点赞事件

```js
document.addEventListener('zan:change', function (e) {
  console.log(e.detail.key, e.detail.likes, e.detail.liked);
});
```

也可以拿到全局对象：`Zan.version` / `Zan.server` / `Zan.vid()` / `Zan.key(path)`。

---

## 接口

只有一个入口，返回统一为 `{code, message, ver, data}`，业务错误也是 HTTP 200。

| 方法 | 请求 | 说明 |
|---|---|---|
| `GET` | `/api/zan.php?action=query&keys[]=/a&keys[]=/b` | 批量读赞数，最多 30 个，不写库 |
| `POST` | `/api/zan.php`，body: `action=like&key=/a&path=/a&vid=xxx` | 点赞 |

`code`：`0` 成功 / `2` 已经点过 / `403` 不是内容页 / `400` 参数有误 / `429` 太频繁 / `500` 服务端异常。

```bash
curl 'https://zan.example.com/api/zan.php?action=query&keys[]=/2026/11/hello/'
# {"code":0,"message":"ok","ver":"3.0.0","data":{"/2026/11/hello/":{"likes":12}}}
```

---

## 后台

`https://zan.你的域名/admin/login.php`

- **仪表盘**：总赞数、今日赞、最近 7 天曲线、页面排行、接入代码
- **点赞排行**：按文章看赞数，点进去看每一条记录（只有时间和指纹前 12 位）
- **数据备份**：导出 / 导入 JSON、清空数据、补建表
- **系统设置**：站点名、跨域白名单、反代 IP

登录接口有节流：同一 IP 10 分钟内失败 20 次就锁 10 分钟。

---

## 数据与备份

整个状态都在两处：

- MySQL：`page` / `log` / `admin`
- 数据卷 `/data`：`config.php`、登录节流记录

```bash
# 备份数据库
docker exec zan-db mysqldump -uzan -p"$DB_PASS" zan > zan-$(date +%F).sql

# 备份配置
docker run --rm -v zan_data:/data -v "$PWD":/backup alpine tar czf /backup/zan-data.tgz -C /data .
```

后台的「下载备份」导出的是 JSON，只含页面标题和点赞记录，**不含**管理员密码。

---

## 从 2.x（带网站统计的版本）升级

3.0 删掉了统计：`session` / `event` / `view` 三张表和对应的接口、页面都不再使用。

1. 先备份数据库
2. 换上新镜像，`/data/config.php` 保留即可（旧配置里的 `view_on`、`salt_rotation` 等键会被忽略）
3. 旧的 `session` / `event` / `view` 表**不会自动删除**，确认不要了再手动删：

```sql
DROP TABLE IF EXISTS zan_session, zan_event, zan_view;
```

4. 2.x 的备份文件仍可导入：程序只读其中的 `page` 与 `log`，其余忽略

---

## 常见问题

**点赞提示「网络错误」，但刷新后数字 +1**
跨域白名单没配对。浏览器发来的 `Origin` 是 `协议://域名`，白名单里不要带路径和结尾斜杠。

**同一个人能重复点赞**
点赞去重靠浏览器 localStorage 里的 `zan:vid`。清缓存、换浏览器、无痕模式都算新访客；这是设计如此。

**后台登录不上**
先看日志里有没有打印初始密码：`docker compose logs zan | grep 管理员`。忘了就去数据卷里改：
`docker exec zan php -r '...'`，或者直接删掉 `admin` 表里的记录重启容器重新生成。

**容器起来了但页面 500**
`docker compose logs zan` 看 `[zan] 自检未通过`。九成是数据库连不上：核对 `.env` 里的 `DB_PASS` 与 `DB_NAME`。

---

## 目录结构

```
api/zan.php          唯一接口（query / like）
api/js/zan.js        前端 SDK
api/style/zan.css    样式
admin/               后台（仪表盘 / 排行 / 备份 / 设置）
include/             common（配置、数据库、鉴权）、schema（建表）、backup-lib
install/             浏览器安装向导（不用 Docker 时走这条）
docker/              entrypoint、启动自检、php.ini、nginx 站点配置
Dockerfile
docker-compose.yml
```

## 许可

MIT，见 `LICENSE`。
