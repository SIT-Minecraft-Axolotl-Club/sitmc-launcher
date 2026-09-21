# sitmc-launcher

把 Blessing Skin(Blessing Skin 6.x / Laravel 8)变成 SIT-Minecraft 启动器的**服务端**:
对外提供实例清单、远程公告与启动器自更新元数据三组接口,并在后台提供实例、整合包版本、公告与版本的管理界面。

**不修改任何 Blessing Skin 核心文件**:全部逻辑都在这个插件目录里,删除插件目录即可完全移除。

## 安装

1. 把整个 `sitmc-launcher` 目录放到站点的 `plugins/` 下(默认 `base_path('plugins')`,可用 `.env` 的 `PLUGINS_DIR` 改)。
2. 进后台 **插件管理**(`/admin/plugins/manage`)启用它;或在服务器上执行 `php artisan plugin:enable sitmc-launcher`。
3. 无需跑迁移:插件会在启用时(以及首次请求时兜底)自动创建自己的五张表。

启用后后台侧栏会出现「**启动器实例**」「**启动器公告**」「**启动器版本**」三项,分别对应 `/admin/launcher`、`/admin/launcher/announcements`、`/admin/launcher/releases`。

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

只有状态为「已发布」且**有整合包**的实例才会下发给客户端;列表里的「发布状态」会写明缺什么(缺 SHA-1、缺游戏版本、外链不是 http(s) 等)。

## 存储与限制

- 上传的整合包保存在 `storage/app/launcher-packs/`,只通过上面的下载路由对外提供,**不放在 web 根目录**。
- 单个整合包大小受 PHP 限制:后台会直接显示当前的 `upload_max_filesize` / `post_max_size`。整合包通常很大,建议把这两个值调到 512M 以上,或者改用外链。
- 文件按 `实例-版本-摘要前 8 位.mrpack` 命名,便于人工核对。

## 卸载

在后台删除插件会**删除五张表以及 storage 里已上传的整合包文件**。只想停用而不丢数据时,用「停用」而不是「删除」。

## 表结构

| 表 | 用途 |
| --- | --- |
| `sitmc_launcher_instances` | 实例(标识、名称、描述、图标、排序、当前版本、游戏/加载器版本、服务器地址、是否必需/启用) |
| `sitmc_launcher_packs` | 每个实例的每个版本(上传文件路径或外链、原始文件名、SHA-1、大小),`(instance_id, revision)` 唯一 |
| `sitmc_launcher_settings` | 启动器更新策略(min/latest/force_update) |
| `sitmc_launcher_announcements` | 公告(标识、标题、摘要、Markdown 正文、展示方式、优先级、开始/结束/发布时间、按钮链接与文案、是否启用) |
| `sitmc_launcher_releases` | 启动器版本(通道、版本号、Markdown 更新说明、发布日期、制品地址、minisign 签名、可选的 target/arch、是否启用) |

表名不加前缀,但都通过 Laravel 的 `Schema`/`DB` 门面访问,所以在 `.env` 里配了 `DB_PREFIX` 也会正常工作。
