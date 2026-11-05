# Sugar-Box

自托管的**点赞 + 网站统计**盒子：点赞只在内容页生效，统计覆盖全站。
后端一个接口，前端一个 JS，样式一份 CSS，不依赖框架、不依赖 Composer，也不需要再装一套 Umami。

- 点赞：`<div data-zan data-zan-scope="content"></div>` 一行就能用，也能接管主题里现成的按钮；
- **点赞只发生在内容页**：容器默认只读，只有显式声明 `data-zan-scope="content"` 的才可写，
  首页、归档、标签页、侧栏里的文章卡片只显示数字，点了不发请求；
- **统计不分页面**：脚本一加载就给当前页记一条浏览，首页、归档、标签页都算；
- **写入模型 1:1 照 Umami**：`session` + `event` 两张表，浏览器不存任何统计标识，
  身份由服务端按 IP + UA + 当期盐推导，指标全在查询侧算；
- 后台自带趋势、页面、来源、浏览器、系统、设备、语言、地区与实时在线。

> 内部文件名、表前缀、主题配置项仍然是 `zan`（`zan_*`、`zan.js`、`zan:`），
> 这是为了让已经部署的数据和主题配置不用改一行；对外品牌从 2.0 起叫 **Sugar-Box**。

---

## 环境要求

| 项 | 要求 |
|---|---|
| PHP | 7.4 及以上（需要 PDO、pdo_mysql、mbstring、JSON） |
| MySQL | 5.5.5 及以上（MariaDB 10.x 同样可用） |
| Web 服务器 | Apache / nginx / IIS 都行，有面板或纯 FTP 都行 |
| 其他 | 不需要 Composer、不需要 Node、不需要 `exec` / `mysqldump` / `ZipArchive` |

---

## 安装

1. 把整个目录上传到网站（可以放在根目录，也可以放在 `/zan` 这样的子目录）。
2. 浏览器打开 `https://你的域名/install/`。
3. 填数据库信息（库名、用户名、密码、主机、表前缀，默认 `zan_`）→ 提交。
4. 填管理员邮箱与密码 → 安装完成页会给出**可直接粘贴的接入代码**。
5. **删掉服务器上的 `install` 文件夹**（不删的话别人可以重装）。

装完打开后端根地址会看到一行 JSON：

```json
{"code":0,"message":"Sugar-Box 运行正常：点赞只在内容页生效，统计覆盖全站","version":"2.0.1"}
```

`?format=html` 是可视化状态页；后台在 `admin/login.php`。

---

## 前台接入

### 方式一：用内置样式（推荐给没有现成按钮的站长）

在文章页放一个**空容器**，页面底部引一次脚本。容器要带 `data-zan-scope="content"`，
否则是只读的（只显示数字）：

```html
<div data-zan data-zan-scope="content"></div>

<script src="https://你的域名/api/js/zan.js"
        data-server="https://你的域名" defer></script>
```

CSS 由脚本自动注入（`api/style/zan.css`），重复加载有 ID 保护，不用自己引。
写在 `/zan` 子目录时，`data-server` 要带上子目录，例如 `https://example.com/zan`。

容器属性（都可以不写）：

| 属性 | 作用 | 默认 |
|---|---|---|
| `data-zan-variant` | `heart` / `pill` / `mini` | `heart` |
| `data-zan-size` | `sm` / `md` / `lg` | `md` |
| `data-zan-color` | 已赞颜色 | `#e0245e` |
| `data-zan-key` | 覆盖文章标识 | 当前 pathname |
| `data-zan-read` | 强制只读：只显示数字，点了也没反应 | 关 |
| `data-zan-scope` | `content` 声明这是内容页自己的容器（**可点赞的前提**）；`read` 只读 | 不写 = 只读 |
| `data-zan-css="off"`（写在 `<script>` 上） | 不自动注入样式 | 注入 |
| `data-stats="off"`（写在 `<script>` 上） | 只关统计，点赞照旧 | 开 |

### 点赞：只在内容页有效

规则只有一条：**容器显式声明 `data-zan-scope="content"` 才可点赞**，其余一概只读。
站点根 `/` 永远不算内容页。

| 场景 | 容器写法 | 结果 |
|---|---|---|
| 文章页正文的按钮 | `data-zan data-zan-scope="content"` | 可点赞 |
| 首页 / 归档 / 标签页里的卡片 | 只有 `data-zan-key` | 只读：显示数字，不发写请求 |
| 列表页上的裸容器（没写 key） | 只有 `data-zan` | 只读 |
| 文章页侧栏的「相关文章」 | 只有 `data-zan-key` | 只读 |
| 首页裸容器（落在 `/`） | 只有 `data-zan` | 只读 |

列表页的卡片即使 key 恰好等于当前页路径，也不会变成可写——可写与否只看有没有那句
`data-zan-scope="content"`，不看路径巧合。
自定义了 `data-zan-key`（和真实路径不一致）时，同样靠 `data-zan-scope="content"`
放行，服务端只要求请求确实来自某个非根路径。
整站只读就在脚本上写 `data-zan-scope="read"`。

服务端同样校验：`like` 必须带 `path`，且与 `key` 一致（或显式 `scope=content`），
否则返回 `{"code":403,"message":"只能在内容页点赞"}`。旧版 SDK 不传 `path` 会被拒，
更新 `api/js/zan.js` 即可恢复。

### 网站统计：不分页面，全站都记

脚本一加载就给当前页记一条浏览，**不需要任何容器**；首页、归档、标签页、文章页都算。
容器只是把数字显示出来：

```html
<!-- 这一页自己的浏览次数 -->
<span data-zan-view></span>

<!-- 全站累计浏览次数 -->
<span data-zan-view data-zan-scope="site"></span>
```

| 属性 | 作用 | 默认 |
|---|---|---|
| `data-zan-label` | 数字后面的文案，写 `""` 则只显示数字 | `次浏览` |
| `data-zan-key` | 显示其它页面的数字（只读） | 当前 pathname |
| `data-zan-scope` | `site` 显示全站累计 | 本页 |

容器里**有内容就不接管**，不改 DOM——留给站长自己填数字。

#### 指标与算法（与 Umami 一致）

| 指标 | 算法 | 含义 |
|---|---|---|
| 浏览次数 | 每条访问的事件数之和 | 每打开一次算一次，刷新就涨 |
| 访客数 | `COUNT(DISTINCT session)` | 同一 IP + 同一浏览器在周期内只算一个人 |
| 访问次数 | `COUNT(DISTINCT visit)` | 同一个人同一小时算一次 |
| 跳出率 | 只有一条事件的访问 / 访问次数 | 打开一页就走 |
| 平均停留 | `SUM(max_time - min_time)` / 访问次数 | 同一次访问里第一次到最后一页的间隔 |
| 当前在线 | 最近 5 分钟内的会话数 | 实时 |

`data-zan-view` 默认显示「访客数」，后台「设置 → 网站统计」可以改成浏览次数；
两个数一直在记，切换不丢历史。爬虫、预取、后台标签页一律不计。

### 方式二：接管站长自己的按钮（HTML 一个字都不用改）

你主题里原本就有这样一段：

```html
<div class="widget-action like">
  <i class="fa-solid fa-heart"></i>
  <span>喜欢</span>
  <span class='number'>0</span>
</div>
```

**只加一个属性**，结构、文案、图标、类名全部保留：

```html
<div class="widget-action like" data-zan>
  ...
</div>
```

脚本会按这个顺序找东西，找到就用，找不到就不动：

1. **数字**：`[data-zan-count]` → `.zan-number` → `.number` / `[data-count]` / `.count` → 容器里最后一个纯数字节点
2. **点击**：`[data-zan-trigger]` → 内部第一个 `button` / `a` → 容器本身
3. **状态**：只在根容器上挂 `is-ready` / `is-liked` / `is-loading` / `is-error`，**不重建 DOM、不加内联样式**

所以你那行 CSS 继续生效。想让"点过之后变红"，在自己的主题 CSS 里写一行：

```css
.widget-action.like.is-liked { color: #e0245e; }
```

这种方式下脚本**不注入任何我们的 CSS**，`.zan-*` 类不会出现在你的页面里，不会和主题打架。

### 列表页要注意

列表页的卡片可以显示数字，但**不能参与点赞**。每张卡片照样要写 `data-zan-key`，
否则会落到当前页面路径上：

```html
<div data-zan data-zan-key="/articles/09640a11.html"></div>
<span data-zan-view data-zan-key="/articles/09640a11.html"></span>
```

脚本会认出「没写 `data-zan-scope="content"`」，把它们渲染成只读（内部不是 `<button>`，
没有点击监听），数字用一次 `GET action=query` 批量取。想显式强调可以再补一个 `data-zan-read`。

**浏览只记当前页**：打开列表页会把一次浏览记在列表页自己身上，不会记到卡片指向的文章。
同一页里同一个 key 出现多次（比如文章顶部和底部各一个），脚本会编成一组：**一次请求、同步刷新**。

### 方式三：写在主题的 _config.yml 里（Hexo）

mirage 主题已经接好了，配置块和 twikoo 摆在一起，写法也一样：

```yml
zan:
  enable: true
  server: "https://zan.liublog.cn"       # 服务地址，末尾不带斜杠
  js: "https://zan.liublog.cn/api/js/zan.js"
  path: ""                               # 留空用 location.pathname
  once: true                             # 单次点击（比 twikoo 多这一个字段）
```

`once` 是唯一多出来的开关：

| 值 | 表现 |
|---|---|
| `true`（默认） | 点过一次就锁住，按钮不再响应，也不会再发请求 |
| `false` | 允许重复点，每次都会请求服务端；服务端仍按访客去重，数字不会重复增加 |

模板里做两件事：给按钮挂 `data-zan`（数字加 `data-zan-count`），在**页脚**按开关输出脚本。
脚本放在 `_partial/footer.ejs` 而不是 `post.ejs`，这样首页、归档、标签页也会加载，
统计才能覆盖全站；点赞按钮仍然只在文章页出现。`enable: false` 时两样都不输出。

> mirage 的 `source/js/post-views.js` 在 provider 为 `zan` 时只做 `action=query` 读数字，
> 上报交给 SDK。若你的主题自己 POST 上报阅读，记得去掉，否则一条浏览会被算两次。

> 主题自带的 `.widget-action.like` 点击逻辑（WP 那套 ajax）要跳过被接管的按钮，
> 否则会同时打两个请求。mirage 里已经把选择器改成 `.widget-action.like:not([data-zan])`。

---

## 后台

| 页面 | 作用 |
|---|---|
| 概览 | 总赞数、今日赞、今日浏览 / 访客、当前在线、最近 7 天（赞 / 浏览双色）、页面排行、接入代码 |
| 统计 | 任意时间范围：浏览 / 访客 / 访问 / 跳出率 / 平均停留，趋势图、页面、来源、浏览器、系统、设备、语言、地区、实时在线 |
| 点赞明细 | 按页面汇总浏览、访客、访问次数和赞，点进去看最近 200 条点赞记录；可删除单个页面的数据 |
| 设置 | 站点名称、统计开关、界面显示口径（访客 / 浏览）、访客去重周期、跨域白名单、反向代理 IP |
| 数据维护 | 下载备份、上传恢复（合并 / 覆盖）、清空数据、补建缺失的表、导入 1.4 的旧阅读记录 |
| 系统更新 | 从 GitHub 仓库拉新版本，自动备份、失败回滚、可回滚到旧版 |

备份是一个 JSON 文件，**不含管理员密码**，包含页面标题、点赞、浏览事件与访客会话；
恢复用 `INSERT IGNORE`，重复导入数字不会翻倍。旧版本（只有 `view` 段）的备份也能导入，
会被自动转换成浏览事件。

### 忘记密码

后台密码是 `password_hash()` 的哈希值，直接改数据库里的字符串没用，必须重新生成。两条路：

**一、用重置脚本（推荐，不需要命令行）**

1. 把 `reset-password.php` 放到站点根目录，和 `config.php` 同级；
2. 打开它，在 `define('RESET_KEY', '');` 的引号里填一个 6 位以上、只有你知道的口令；
3. 浏览器访问 `https://你的域名/reset-password.php`，输入口令、新密码，提交；
4. **重置成功后立刻从服务器删掉这个文件**，否则知道口令的人随时能再改一次。

**二、有命令行或 phpMyAdmin 时**

```bash
php -r "echo password_hash('你的新密码', PASSWORD_DEFAULT), PHP_EOL;"
```

把输出的 `$2y$...` 整串复制出来，在 phpMyAdmin 里执行：

```sql
UPDATE zan_admin SET password_hash = '$2y$...' WHERE email = '你的邮箱';
```

---

## 自动更新（走 GitHub 仓库）

后台「系统更新」页可以直接把 GitHub 上的新版本装到本地，不用再传压缩包。

**它做的事**：比对仓库最新提交 → 下载该提交的源码 zip → 自己解压（不依赖 ZipArchive）→
先备份程序区 → 只覆盖白名单文件 → 任何一步失败整批回滚。

**只覆盖这些**：`api/`、`admin/`、`include/`、`assets/`、`index.php`、`README.md`

**绝不动这些**：`config.php`（数据库账号与 site_secret）、`data/`（更新状态与备份）、
`install/`（装完就该删的安装器）、`reset-password.php`（你自己填了口令的救急工具）

### 怎么用

1. 把程序源码推到一个**公开**的 GitHub 仓库，根目录结构和本包一致（`include/defs.php` 里是版本号）；
2. 后台「系统更新 → 更新设置」填 `owner/repo` 和分支（默认 `main`），保存；
3. 点「立即检查」。有新版本会显示出来，点「立即更新到 x.y.z」即可；
4. 更新完刷新页面生效。想反悔就回到这一页，在「备份与回滚」里点「回滚」。

版本号从 `include/defs.php` 的 `define('ZAN_VERSION', 'x.y.z')` 读。
**远程版本低于本地一律拒绝**，避免手滑把站点降级。仓库只改了代码没改版本号时，
只要之前更新过（状态里记着已应用的 commit），也会照样提示。

### 自动检查与定时

- 「自动检查」打开后，打开后台任意页面都会在**页面渲染完之后**异步查一次，到点才查
  （默认 6 小时，最多 168），有新版本会在「系统更新」菜单上点一个红点；不影响页面速度。
- 后台不开也想查：把「无人值守」里的地址加到服务器的计划任务，例如
  `curl -s "https://你的域名/admin/cron.php?token=xxxx" >/dev/null`，返回 JSON。
  令牌可在页面上一键重置，重置后旧地址立即失效。
- 想让它发现就装：勾「自动更新」。仓库只有你能推代码时再开。

### 邮件通知

填了「通知邮箱」并配好发信方式后，发现新版本会发一封邮件，同一个 commit 只发一次。
发信方式支持 PHP `mail()` 和 SMTP（QQ / 163 / 阿里企业邮 / Gmail / Outlook 都有现成参数），
选 SMTP 后先点「发一封测试邮件」确认能收到再等自动通知。

### 需要知道的限制

- **仓库必须公开**：本版本不带 token，私有库拉不到。
- **GitHub 接口有频率限制**：未登录按 IP 算 60 次/小时。默认 6 小时查一次完全够，
  别把间隔改成 1 小时以下，也不要在同一台机器上反复点「立即检查」。
- **服务器要能访问 GitHub**：国内主机偶尔连不上 `api.github.com` / `codeload.github.com`，
  页面会把具体原因显示出来（连不上、403 限流、404 仓库或分支不存在等）。
- **Windows 下要配 CA 证书**：报 `SSL certificate ... unable to get local issuer certificate` 时，
  下载 [cacert.pem](https://curl.se/ca/cacert.pem)，在 `php.ini` 里设
  `curl.cainfo = "D:/php/cacert.pem"` 后重启 PHP。Linux 装 `ca-certificates` 即可。
- **备份占空间**：每次更新前把程序区复制到 `data/backup/update/`，只保留最近 3 份。
- 更新只换程序文件，**不改数据库**；新版需要新表时，后台「数据维护」会提示补建。

---

## 数据表

前缀默认 `zan_`（可在安装时改）：

```
zan_page     page_key(PK) / title / created_at / updated_at
zan_log      id / page_key / visitor_hash / uniq / created_at
             UNIQUE(uniq)            ← 一人一篇只能一条
             KEY(page_key), KEY(visitor_hash)
zan_session  session_id(PK) / website_id / browser / os / device / screen / language
             / country / region / city / distinct_id / created_at
zan_event    id / event_id(UNIQUE) / website_id / session_id / visit_id
             / url_path / url_query / referrer_path / referrer_query / referrer_domain
             / page_title / hostname / event_type / event_name / created_at
             KEY(url_path, created_at), KEY(created_at), KEY(session_id), KEY(visit_id)
zan_view     1.4 及更早版本的阅读表 —— 只作为升级时的迁移来源，不再写入
zan_admin    id / email / password_hash / created_at
```

**不存计数字段，全部现算。** 存了计数器就有两个事实来源，一次写入失败就永久漂移；
`event` 是唯一事实，任何时间窗都能重算。个人博客的量级，带索引的 COUNT 完全够用。

`session` 与 `event` 的字段、含义与 Umami 的 `session` / `website_event` 对齐
（少了对本项目没意义的 performance、revenue、UTM 点击 ID 等列）。

**从 1.4 升级**：第一次请求会自动建 `session` / `event` 表，并把 `zan_view` 里的旧记录
按「一条 = 一次浏览」搬进来；也可以在「数据维护 → 从旧阅读表导入」手动再跑一次（幂等）。
旧表保留不删，确认数据没问题后可以自行 DROP。
旧记录里的 `visit_id` 如果整列是空的（1.4 早期写过一批），迁移时按「访客 + 小时」补算，
不会把几万条浏览全算成同一次访问。

---

## 它是怎么工作的

**统计不需要容器**：脚本加载后，页面可见时给当前页发一次
`POST action=pageview&url=/a&title=...&referrer=...&screen=...&language=...`，
响应里带回这一页的 `views / pageviews / visitors / visits`，同一次请求把数字填好。
页面上有全站计数器时再加 `want_site=1`，才顺带算全站，避免每次浏览都做一次全表 distinct。

**点赞写即读**：`POST action=like`，后端写库，**同一个响应带回最新计数和「我点过没有」**，
前端不用「写完再查一遍」。

**页面被真正看见才上报**：`visibilityState` 为 `hidden` 时先等，预取和预渲染的请求带
`Sec-Purpose: prefetch|prerender`，直接跳过不写库；SPA 的 `pushState / replaceState / popstate`
会补一条。

**谁不算浏览**：UA 为空、`bot/crawl/spider/headless/curl/python-requests/rss` 以及
`gptbot / claudebot / bytespider / ahrefs / semrush` 这类抓取器一律只读不写（规则集照 `isbot` 铺开）；
同一会话一小时内超过 300 次也拒绝。所以 RSS 阅读器、搜索引擎、AI 爬虫都不会把数字顶上去。

**页面怎么认**：`location.pathname` 归一化——去 query / hash / 域名 / `index.html` / 尾斜杠。
查询串单独存进 `url_query`，页面数字按路径聚合。

**点赞怎么认同一个人**：浏览器 `localStorage` 里一个 uuid，服务端存 `sha256(vid + site_secret)`。
换网络、换 IP 不会掉票。没有 uuid（禁用存储或首次被清）时，退回 `sha256(IP + UA + site_secret)` 指纹。
这个 vid **只服务点赞**，统计请求完全不碰它。

**统计怎么认同一个人**（与 Umami 的 `send/route.ts` + `lib/crypto.ts` 同构）：

```
salt      = sha256('zan-salt|month|2026-11-01 00:00:00')   ← 当期时间桶，按 day/week/month 轮换
session   = sha256('s2|' + 站点 + '|' + IP + '|' + UA + '|' + salt + '|' + site_secret)
visit     = sha256('v2|' + session + '|' + sha256(当前小时))
```

session 决定访客数，visit 决定访问次数，两者都由服务端推导。浏览器不存任何统计标识，
没有 Cookie、没有指纹脚本，请求里也不带 vid。盐按月换，跨月算不出同一个人。
代价是同一出口 IP + 同一浏览器的人会算成一个人（公司、学校、运营商 NAT 都会这样），
这是「浏览器零存储」方案公认的取舍，Umami 也一样。

**地区**：套了 Cloudflare 才有国家（读 `CF-IPCountry`），没套时该列留空。
本项目不打包 GeoIP 库——为省/市字段塞几十 MB 数据进虚拟主机不划算。浏览器、系统、设备
用内置的 UA 规则识别常见值，识别不出来显示「未知」，不影响计数。

**错误码**：业务错误一律 HTTP 200 + `code`（`0` 成功、`2` 已点过、`403` 不是内容页、`400` 参数错、`429` 太频繁、`500` 服务端异常）。
原因很实际：线上 nginx 的 `fastcgi_intercept_errors` 会把 PHP 的 404 换成 HTML 错误页，前端 `JSON.parse` 直接炸。

**限流**：同一个访客一小时内最多 60 次点赞、300 次浏览事件，超出返回 `429`。

**接口一览**

```
GET  /api/zan.php?action=query&keys[]=/a&keys[]=/b   批量读单页计数，最多 30 个 key，不写库
GET  /api/zan.php?action=site                        全站累计
POST /api/zan.php   action=like&key=/a&path=/a&vid=z...   点赞，返回 likes / liked
POST /api/zan.php   action=pageview&url=/a&title=...      记一条浏览事件
POST /api/zan.php   action=view&key=/a&path=/a            1.4 及更早 SDK 的旧入口，等同 pageview
```

`like` 的 `path` 是当前页面的真实路径，必须和 `key` 一致（或带 `scope=content`），
否则返回 `403`——列表页只能读，不能借别人的 key 写数据。`vid` 只在点赞时需要。
`pageview` 不需要 `path`，全站任何页面都可以记。

`views` 是按当前口径算好的展示值，`pageviews` / `visitors` / `visits` 是三个原始指标，
想做自定义展示直接用后面这组。

---

## 常见问题

**清空浏览器数据后又能点一次？**
会。这是因为身份存在 localStorage。twikoo 和 umami 都是这个取舍——换来的是不要 Cookie、不要登录。

**换域名或改固定链接，赞数会不会丢？**
key 是按路径存的，路径变了就是新页面。要么保持路径不变，要么用 `data-zan-key` 显式固定（推荐 `/articles/xxx.html` 这种稳定地址）。

**博客和接口不同域，需要配什么？**
后台「设置 → 跨域白名单」填博客域名（带 `https://`，一行一个）。留空表示不限制。

**套了 Cloudflare / 面板反代，所有人算同一个人？**
访问日志里所有人 IP 都一样时，把回源 IP 填进「反向代理 IP」。有 uuid 的访客不受影响。

**备份怎么恢复？**
数据维护页选文件、选合并或覆盖、输入 `RESTORE`。合并会跳过已有记录，所以重复导入不会把票数翻倍。

**能放在子目录吗？**
能。接入时 `data-server` 带上子目录即可；后台、接口的地址都会自动跟随。

**刷新页面阅读数怎么不涨？**
界面默认显示「访客数」，刷新、回看不重复计人——这是有意的，不然按 F5 就能刷数据。
换设备、换浏览器（或换出口 IP）才会 +1。想看「每打开一次算一次」的次数，去后台
「设置 → 网站统计」把显示口径改成浏览次数；两个数一直在记，切换不会丢历史。

**阅读数和别的统计插件对不上？**
先看口径。这里和 Umami 一样覆盖全站所有页面，过滤爬虫与预取；不蒜子这类
「每次加载 +1」不过滤爬虫，数值偏高是正常的。和 Umami 仍有差异时，多半是
「同一出口 IP + 同一浏览器算一个人」以及盐轮换周期不同，属于预期。

**同一台电脑换网络，访客数会不会变？**
会。访客身份由 IP + UA 推导（浏览器不存标识），换网络等于换了 IP。
点赞不受影响——那个走浏览器里的 vid，换网络不掉票。

**浏览次数在涨，访客数一直是 1？**
说明服务端拿到的 IP 全都一样：多半是套了 CDN / 反向代理但没配「反向代理 IP」。
去后台「设置 → 访问控制」把回源 IP（如 Cloudflare 段、`127.0.0.1`）填上，
接口才会读 `X-Forwarded-For` 里的真实客户端地址。

**从 1.4 升到 2.0 要做什么？**
换后端文件即可。第一次请求会自动建 `session` / `event` 表并把旧 `view` 记录搬进来，
不用点维护页；也可以在「数据维护 → 从旧阅读表导入」手动再跑一次（幂等）。
旧数据按「一条记录 = 一次浏览」计入，访客数按旧 `visitor_hash` 去重，所以升级后
访客数可能比之前显示的小——那是从「按天去重的计数」换成了「真正的会话去重」，不是丢数据。

**以前只在内容页统计，现在首页也算，数字会变吗？**
会。2.0 起统计不分页面，首页、归档、标签页的浏览也会计入全站指标；单篇文章的
「浏览」仍然只算访问该文章路径的次数，不会被别的页面顶高。

**原来写好的 `<div data-zan></div>` 升上来点不动了？**
2.0 起容器默认只读，要能点赞必须写 `data-zan-scope="content"`。
这是故意的：靠「key 等于当前路径」判断不可靠——列表页上的裸容器 key 也等于当前路径，
那样首页、归档的卡片就又能点了。加上这个属性，可点范围只剩内容页模板里那几个容器。

**后台不想要统计，只想要点赞？**
「设置 → 网站统计」关掉即可：接口只返回已有数字、不再写入，点赞和按钮都照常。

**从更老的版本升上来，缺 `session` / `event` 表？**
打开后台「数据维护」，页面会提示缺表，点「补建表结构」；不补也能用，只是统计一直是 0，点赞照常。

---

## 目录结构

```
index.php              健康检查（默认 JSON，?format=html 是状态页）
reset-password.php     忘记密码时的一次性重置工具（用完请删除）
config.php             安装时生成：数据库账号、site_secret、跨域白名单
install/               安装向导（装完请删除）
data/                  运行时生成：更新状态、锁、临时包、更新前备份（删掉不影响运行）
include/
  defs.php             版本号、品牌名、根目录
  common.php           配置、数据库、key 归一化、会话推导、UA 解析、统计查询、鉴权、后台框架
  install-ui.php       安装页框架、环境自检
  schema.php           全部表的 DDL
  backup-lib.php       备份导出、解析、写回
  updater.php          自动更新：查 GitHub、下载、备份、覆盖、回滚
  zipreader.php        极简 zip 读取（不依赖 ZipArchive）
  mailer.php           发信：PHP mail() 与 SMTP
api/
  zan.php              唯一接口：批量读 / 全站累计 / 点赞 / 浏览事件
  js/zan.js            前端 SDK
  style/zan.css        内置样式
admin/                 登录、概览、统计、明细、设置、维护、更新、退出
  stats.php            全站统计报表
  cron.php             计划任务入口（令牌校验，不登录）
assets/                后台与安装页的样式
```

---

## 安全建议

- 装完**删掉 `install/`**；
- 全站走 HTTPS（`config.php` 里的 `site_secret` 是访客哈希的盐，泄露了也拿不到原始 IP，但仍建议保护）；
- `config.php` 含数据库密码，不要提交到公开仓库；**自动更新也永远不会碰它**；
- 后台密码请用 12 位以上，登录失败 8 次会锁 10 分钟；
- `data/` 里有更新前的源码备份，Apache 已由自带的 `.htaccess` 挡住，**nginx 请加一条**：
  ```nginx
  location ^~ /data/ { deny all; return 404; }
  ```
