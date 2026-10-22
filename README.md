# sitmc-launcher

把 Blessing Skin(Blessing Skin 6.x / Laravel 8)变成 SIT-Minecraft 启动器的**服务端**:
对外提供实例清单、远程公告、启动器自更新元数据,以及 **mc-interop v1** 对外清单,
并在后台提供实例、整合包版本、公告、版本与上架实例/友站的管理界面。

**不修改任何 Blessing Skin 核心文件**:全部逻辑都在这个插件目录里,删除插件目录即可完全移除。

## 安装

1. 把整个 `sitmc-launcher` 目录放到站点的 `plugins/` 下(默认 `base_path('plugins')`,可用 `.env` 的 `PLUGINS_DIR` 改)。
2. 进后台 **插件管理**(`/admin/plugins/manage`)启用它;或在服务器上执行 `php artisan plugin:enable sitmc-launcher`。
3. 无需跑迁移:插件会在启用时(以及首次请求时兜底)自动创建自己的全部数据表。
4. 从旧版升级:覆盖插件目录即可;首次访问「对外清单」标签页会自动补 `published` / `description` 列。上架实例默认关闭,需要重新勾选。

启用后后台侧栏只多一个「**启动器**」入口,里面用「实例」「公告」「版本」「对外清单」四个标签页切换。

## 客户端怎么指向它

清单地址是**启动器构建期变量**,不能运行时修改:

```bash
VITE_SITMC_MANIFEST_URL=https://skin.sitmc.club/api/launcher/manifest \
VITE_SITMC_ANNOUNCEMENTS_URL=https://skin.sitmc.club/api/launcher/announcements \
VITE_SITMC_UPDATE_URL=https://skin.sitmc.club/api/launcher/update \
pnpm app:build
```

接口契约见启动器仓库的 `docs/launcher-protocol.md`;本插件实现的端点:

| 端点 | 说明 |
| --- | --- |
| `GET /api/launcher/manifest` | 清单 JSON。带 `ETag`,客户端回传 `If-None-Match` 时返回 `304` |
| `GET /api/launcher/packs/{instanceId}/{revision}` | 下载某个版本的整合包(登记了外链的版本会 302 到外链) |
| `GET /api/launcher/announcements` | 公告**裸 JSON 数组**(不带外层对象)。只返回启用、规则合法且处于生效时间内的公告,超过 200 条时按优先级与发布时间截断 |
| `GET /api/launcher/update/latest?channel=release\|beta` | 最新版本元数据 `{version, notes, pub_date}`。通道缺失或未知一律按 `release`;该通道没有版本时返回 `204` |
| `GET /api/launcher/update/{target}/{arch}/{current_version}` | Tauri v2 **动态更新器**文档 `{version, notes, pub_date, url, signature}`;没有比 `current_version` 更新的版本时返回 `204` |

清单、公告与更新元数据都是公开的(启动器不带凭据),但**不包含任何玩家数据**,只描述有哪些实例、什么版本、从哪里下载。

## 对外清单(mc-interop v1)

协议见仓库根目录的 `launcher-interop-protocol-v1.md`。原有 `/api/launcher/manifest`、整合包下载、公告与自更新端点**路径和响应体都没有改动**；旧启动器继续读旧清单，新启动器/第三方站点读统一出口。

| 新增端点 | 说明 |
| --- | --- |
| `GET /.well-known/mc-interop-v1.json` | 唯一的 SiteDocument |
| `GET /api/launcher/interop/v1/site` | 同一份文档的插件端点，供反代把 well-known 转发过来 |
| `GET /api/launcher/friends` | 只含友站实例的清单，manifest 结构并带 `source` |
| `GET /api/launcher/interop/v1/friends` | 同上，兼容旧地址 |

响应带 `ETag` 与 `Cache-Control: public, max-age=300`；客户端带 `If-None-Match` 命中时返回 `304`。所有时间戳为 UTC ISO-8601。

### 数据结构

一个实例就是 `packs[]` 的一个条目：

```text
实例 = 整合包（artifact，可省略）+ 服务器 IP（server，必填）+ 版本（有包用 revision，无包用 gameVersion）
```

- `site`：`{id, name, description, domain}`。`name` 只读，直接取 Blessing Skin 后台的站点名称（`site_name`）；`domain` 是站点根域名 host（如 `skin.sitmc.club`，不带 `https://` 和路径）。
- `packs[]`：扁平数组，不再有独立的 `servers` 区块、`binding`、`endpoints`、`flags`、`channel`、`mirrors`、`auth`、`manifestVersion` 等字段。
- 不再有 `order`：`packs[]` 固定按 `id` 排序，本站条目在前，友站条目按 `id` 再按来源域名；后台的「排序」字段只影响旧 manifest。
- `peers[]`：只列友站（不重复列本站），字段 `siteId / name? / description? / domain / manifestUrl`，没有 `trust`。
- `updatedAt`：只在顶层出现。
- `artifact` 可选：有包时输出 `format/url/size/sha1/sha512`；两个 hash 键都输出，未知写 `null`，至少一个非 `null`。无包条目省略 `artifact` 和 `revision`。
- `server.port` 省略表示不指定（交给 SRV 或客户端默认），不会自动补 25565。
- 每个整合包只输出**当前 revision**，历史版本不进入出口。

### 友站实例接口

`GET /api/launcher/friends` 给启动器单独做「友站」页用，实例字段参照 `/api/launcher/manifest`，但按站点分组：

```text
GET /api/launcher/friends
GET /api/launcher/interop/v1/friends   （同一个响应，兼容旧地址）
```

- `sites[]` 每个元素是一个友站，带 `site_id / name / description / domain / manifest_url / stale` 和该站的 `instances[]`。
- 只有至少有一个可用实例的友站才会出现在 `sites[]`；没有实例的友站不占位。
- `instances[]` 里的字段沿用 manifest：`id / name / description / icon_url / sort / revision / minecraft / pack / server / required`。
- `sort` 由本站按 `id` 生成（每个友站 1..n），不采用友站自己的排序值。
- 实例 `id` 带来源前缀，例如 `yda:pixelsit`，和主清单一致。
- 实例不再重复带 `source`，来源就是它所在的 `sites[]` 分组。
- `stale: true` 表示该友站这次拉取失败，`instances` 来自上一次成功快照。
- 友站聚合关闭或没有快照时，`sites` 是空数组。
- 没有整合包的友站实例：`revision` 和 `pack` 为 `null`，`minecraft.loader` 为 `null`。
- 响应带 `ETag` / `If-None-Match` / `304`。

示例（两个友站）：

```json
{
  "schema": 1,
  "generated_at": "2026-10-21T08:00:00Z",
  "sites": [
    {
      "site_id": "yda",
      "name": "余梦",
      "description": "余梦的公共服务器",
      "domain": "mc.yudream.app",
      "manifest_url": "https://mc.yudream.app/.well-known/mc-interop-v1.json",
      "stale": false,
      "instances": [
        {
          "id": "yda:pixelsit",
          "name": "PixelSIT",
          "description": "余梦的整合包服务器",
          "icon_url": null,
          "sort": 2,
          "revision": 3,
          "minecraft": {
            "game_version": "1.20.1",
            "loader": "fabric",
            "loader_version": "0.15.0"
          },
          "pack": {
            "kind": "mrpack",
            "url": "https://mc.yudream.app/packs/pixelsit/3",
            "sha1": "...",
            "size": 100
          },
          "server": { "address": "pixel.yudream.app", "port": 25565 },
          "required": false
        }
      ]
    },
    {
      "site_id": "another",
      "name": "另一个友站",
      "description": "另一个友站的公共服务器",
      "domain": "mc.example.com",
      "manifest_url": "https://mc.example.com/.well-known/mc-interop-v1.json",
      "stale": false,
      "instances": [
        {
          "id": "another:other-pack",
          "name": "另一个实例",
          "description": null,
          "icon_url": null,
          "sort": 1,
          "revision": 1,
          "minecraft": { "game_version": "1.21.10", "loader": null, "loader_version": null },
          "pack": { "kind": "mrpack", "url": "https://mc.example.com/packs/other-pack/1", "sha1": "...", "size": 200 },
          "server": { "address": "play.example.com", "port": null },
          "required": false
        }
      ]
    }
  ]
}
```

### 上架规则

- **默认不上架任何实例**：后台「对外清单」标签页的「上架实例」需要显式勾选。从旧版升级后该选择默认关闭，需要重新勾选。
- 未勾选的实例不会出现在 `packs[]`，也不会通过其他字段泄露；上架选择是站内配置，不进入 JSON。
- 勾选但没有服务器地址、没有当前 revision、或整合包 URL/hash/size 不合法的实例不会上架，并在诊断列表中给出原因。
- 上架选择与旧清单的「下发给客户端」（`enabled`）相互独立：旧清单停用的实例仍可单独上架到对外清单。

### 友站聚合

- 添加友站只需要填根域名；后台会读取 `https://{domain}/.well-known/mc-interop-v1.json`，从 `site` 块取站点标识、名称和描述，不需要手填。
- 友站离线也可以添加：先用 `pending-...` 占位，首次成功同步后自动换成对方真实的 `site.id`。离线期间状态显示「未同步/待识别」，不会影响其他友站；占位 ID 不会出现在公开的 `peers[]` 里。
- 后台维护友站 allowlist；默认每 5 分钟检查一次。没有 cron 时，到点后由下一次访问清单触发；后台「定时同步」里给出 crontab 命令，可以固定每 5 分钟拉取，不再依赖访问。单次拉取 15 秒超时、3 次退避重试。
- 后台「友站」里可以点「同步」或「同步全部」手动触发。
- 友站失败时沿用最近一次成功快照，外来条目带 `source.stale: true`，**永不静默清空**。
- 外来条目带 `source{siteId, domain, stale?}`；本家条目不带。
- 外来条目的 `id` 会加来源前缀，例如 `ysumc:survival`；本站自己的 `survival` 保持不变，因此两边同名实例可以同时存在。
- 友站条目已自带 `server`，合并时不再重写绑定；同一友站文档内重复的 `id` 仍会丢弃并记诊断。
- 公开请求发现快照过期时，在响应结束后触发一次同步，不阻塞启动器；后台「同步」/「同步全部」也可手动触发。

### 定时同步

后台「对外清单」的「定时同步（cron）」会显示一条带 token 的命令，把它加进服务器 crontab 即可固定拉取：

```cron
*/5 * * * * curl -fsS -X POST --max-time 300 'https://skin.sitmc.club/api/launcher/interop/v1/refresh?token=...' >/dev/null
```

token 可以在同一处重置；重置后记得更新 crontab。

### 后台与测试

- 后台「**对外清单**」标签页（`/admin/launcher/interop`）：站点标识/描述/域名、上架实例、友站、同步开关/间隔和诊断。
- 协议投影与友站合并是无框架、无 I/O 的纯函数，可直接跑断言：

```bash
php tests/run.php
```
## 公告怎么下发

公告是**裸数组**而不是对象:客户端的解析器直接校验数组本身,多一层包装会被当成空文档。因此:

- 只有 `enabled` 为真、**所有字段都符合客户端规则**、且 `starts_at` 已到而 `ends_at` 未到的公告才会被下发;
- 规则在 `AnnouncementRules` 里与客户端逐条对齐(字段长度、枚举、时间可解析、按钮链接必须是 http(s) 且不带用户名密码),后台列表会直接写出某条公告**为什么不会下发**;发布时间已过但还未生效、或已经过期的公告会标为「未开始 / 已结束」;
- 文档最多 200 条,超出时按优先级(高→低)与发布时间(新→旧)截断——超过 200 条会让客户端**丢弃整份文档**;
- 时间在后台用 `datetime-local` 填写,按站点时区(`config('app.timezone')`)存储,下发给客户端时统一转成 UTC 的 ISO-8601(`2026-02-01T00:00:00Z`)。

## 自更新（Tauri 动态更新器）

`GET /api/launcher/update/{target}/{arch}/{current_version}` 返回 Tauri v2 动态更新器文档:

```json
{
  "version": "1.3.0",
  "notes": "### 新增\n- ...",
  "pub_date": "2026-02-01T00:00:00Z",
  "url": "https://cdn.sitmc.club/launcher/1.3.0/Axolotl_1.3.0_x64-setup.nsis.zip",
  "signature": "untrusted comment: signature from minisign secret key\nRWS...\n..."
}
```

选择规则:

1. 只考虑 `enabled` 为真、通道匹配、版本号与发布日期都合法的版本;
2. 优先 `release` 通道;只有当 `target`/`arch` 在 `release` 里**没有任何可用版本**时才回退到 `beta`;
3. 版本可以用「目标平台/架构」限定:**两项都留空表示所有平台**,填写后只对匹配的请求生效,取其中发布日期最新的一个;
4. 动态更新器需要**制品地址与 minisign 签名同时存在**,缺任一则该版本不参与选择(但仍可以只用来提示版本);
5. 配置的版本**不比** `current_version` 新时返回 `204 No Content`;版本号按 `.` 与 `-` 分段做数字比较(`1.10.0` > `1.9.0`,`1.3.0` > `1.3.0-beta.1`);**任意一端无法解析时直接返回文档**——宁可多发一次更新,也不能把玩家锁在旧版本上。

当前发行版还没有启用这个端点(仍然走 `update.axlmc.org`);社团开始自己签名发版后,把上面的地址填进 Tauri 更新配置即可,服务端无需再改。


## 后台怎么用

1. **新建实例**:实例标识只能用小写字母/数字/`_`/`-`(与启动器一致,最长 64 字符),创建后不可改。
2. **上传 `.mrpack`**:上传后自动计算 SHA-1 与大小,并**自动作为新版本发布**——版本号一变,所有客户端下次启动就会强制重装。
3. 也可以**登记外链整合包**(对象存储/CDN),此时需要手工填 40 位小写 SHA-1(客户端强校验,填错会拒绝安装)。
4. **回滚**:在版本列表点「回滚到此版本」即可把当前版本指回旧版本。
5. 右上「启动器更新策略」:`min_version`(低于此版本全屏阻断)、`latest_version`(仅提示)、`force_update`(玩家无法推迟更新)。
6. **公告**(`/admin/launcher/announcements`):正文与摘要都按 Markdown 填写;列表里的「下发状态」会写明这条公告会不会被客户端接受、是否还没到开始时间或已经结束。
7. **版本**(`/admin/launcher/releases`):一个版本可以先只写版本号与更新说明(用于提示),等签名完成后再补上制品地址与 minisign 签名,那时动态更新器才会真的安装它。
8. **对外清单**(`/admin/launcher/interop`):配置站点标识/描述/域名、友站、同步开关与间隔,并在「上架实例」里勾选要公开的实例;页面同时给出清单地址、概览和诊断。

只有状态为「已发布」且**有整合包**的实例才会下发给客户端;列表里的「发布状态」会写明缺什么(缺 SHA-1、缺游戏版本、外链不是 http(s) 等)。

## 存储与限制

- 上传的整合包保存在 `storage/app/launcher-packs/`,只通过上面的下载路由对外提供,**不放在 web 根目录**。
- 单个整合包大小受 PHP 限制:后台会直接显示当前的 `upload_max_filesize` / `post_max_size`。整合包通常很大,建议把这两个值调到 512M 以上,或者改用外链。
- 文件按 `实例-版本-摘要前 8 位.mrpack` 命名,便于人工核对。

## 卸载

在后台删除插件会**删除它创建的全部表以及 storage 里已上传的整合包文件**(包括友站快照)。只想停用而不丢数据时,用「停用」而不是「删除」。

## 表结构

| 表 | 用途 |
| --- | --- |
| `sitmc_launcher_instances` | 实例(标识、名称、描述、图标、排序、当前版本、游戏/加载器版本、服务器地址、是否必需/启用) |
| `sitmc_launcher_packs` | 每个实例的每个版本(上传文件路径或外链、原始文件名、SHA-1、大小),`(instance_id, revision)` 唯一 |
| `sitmc_launcher_settings` | 启动器更新策略(min/latest/force_update)与 mc-interop 站点标识/描述/根域名/聚合设置 |
| `sitmc_launcher_announcements` | 公告(标识、标题、摘要、Markdown 正文、展示方式、优先级、开始/结束/发布时间、按钮链接与文案、是否启用) |
| `sitmc_launcher_releases` | 启动器版本(通道、版本号、Markdown 更新说明、发布日期、制品地址、minisign 签名、可选的 target/arch、是否启用) |
| `sitmc_launcher_interop_peers` | mc-interop 友站 allowlist(站点标识、名称、描述、origin、清单地址、是否启用) |
| `sitmc_launcher_interop_snapshots` | 每个友站最近一次成功拉取的 SiteDocument 快照(ETag、原始文档、拉取时间、是否过期、最近错误) |
| `sitmc_launcher_interop_servers` | 每个实例的「上架」选择(`published`,默认关闭);旧版互操作字段保留兼容 |
| `sitmc_launcher_interop_endpoints` | 旧版多线路字段,v1 不再使用(保留兼容) |

表名不加前缀,但都通过 Laravel 的 `Schema`/`DB` 门面访问,所以在 `.env` 里配了 `DB_PREFIX` 也会正常工作。
