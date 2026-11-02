# 启动器对接说明（SIT-Minecraft 站点）

面向启动器实现的对接文档。所有响应都是 UTF-8 JSON（整合包下载除外）。

- 站点根：`https://skin.sitmc.club`
- 所有接口**匿名可读**，不需要登录；只有皮肤管理那组需要玩家会话。
- 时间戳一律 UTC ISO-8601，例如 `2026-10-22T03:54:48Z`。

## 1. 接口一览

| 用途 | 方法 | 路径 | 备注 |
| --- | --- | --- | --- |
| 实例目录（主） | GET | `/api/launcher/manifest` | 启动器只需要这一个就能工作 |
| 下载整合包 | GET | `/api/launcher/packs/{id}/{revision}` | 返回 zip，带 `Content-Length` |
| 公告 | GET | `/api/launcher/announcements` | **裸数组**，不是对象 |
| 更新提示 | GET | `/api/launcher/update/latest` | 无更新时 204 |
| 动态更新清单 | GET | `/api/launcher/update/{target}/{arch}/{currentVersion}` | Tauri v2 格式，无更新 204 |
| 友站实例（可选） | GET | `/api/launcher/friends` | 按站点分组 |
| 跨站协议（可选） | GET | `/.well-known/mc-interop-v1.json` | 与启动器主流程无关 |
| 皮肤（可选） | GET/POST/PUT/DELETE | `/api/launcher/skins` | 需要玩家会话 |

## 2. 主清单 `/api/launcher/manifest`

### 2.1 响应结构

```json
{
  "schema": 1,
  "generated_at": "2026-10-22T03:54:48Z",
  "launcher": {
    "min_version": "2.0.9",
    "latest_version": "2.0.9",
    "force_update": true
  },
  "instances": [
    {
      "id": "survival",
      "name": "生存世界",
      "description": "生存世界是……",
      "icon_url": "https://cloud.sitmc.club/f/jjWIl/Crafting_Table.png",
      "sort": -1,
      "revision": 12,
      "minecraft": {
        "game_version": "26.2",
        "loader": "fabric",
        "loader_version": "0.19.5"
      },
      "pack": {
        "kind": "mrpack",
        "url": "https://skin.sitmc.club/api/launcher/packs/survival/12",
        "sha1": "b00e0021aada1321b81cbd9693782df39b5f67a0",
        "size": 511459395
      },
      "server": { "address": "play.sitmc.club", "port": 25565 },
      "required": false
    }
  ]
}
```

### 2.2 字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `schema` | int | 固定 `1` |
| `generated_at` | string | 文档生成时间 |
| `launcher.min_version` | string \| null | 低于此版本的启动器应全屏强制更新 |
| `launcher.latest_version` | string \| null | 有新版本时提示，不阻断 |
| `launcher.force_update` | bool | 为真时玩家不能推迟更新 |
| `instances[].id` | string | 实例标识，**稳定**；本站实例形如 `survival`，被精选的友站实例形如 `ysumc:survival` |
| `instances[].name` | string | 展示名 |
| `instances[].description` | string \| null | 长描述，可能含换行 |
| `instances[].icon_url` | string \| null | 图标绝对地址 |
| `instances[].sort` | int | **排序值，`-1` 表示精选置顶** |
| `instances[].revision` | int \| null | 整合包版本号，变化即需重新下载 |
| `instances[].minecraft.game_version` | string | MC 版本 |
| `instances[].minecraft.loader` | string \| null | `fabric` / `forge` / `neoforge` / `quilt`；`null` 表示原版 |
| `instances[].minecraft.loader_version` | string \| null | 加载器版本，原版时为 `null` |
| `instances[].pack` | object \| null | **可能为 `null`**（纯服务器实例，没有整合包） |
| `instances[].pack.kind` | string | 目前只有 `mrpack` |
| `instances[].pack.url` | string | 绝对地址，直接 GET 下载 |
| `instances[].pack.sha1` | string \| null | 40 位小写十六进制；**可能为 `null`** |
| `instances[].pack.size` | int \| null | 字节数；**可能为 `null`** |
| `instances[].server.address` | string | 服务器地址（域名或 IP） |
| `instances[].server.port` | int \| null | 为 `null` 时不要补 25565，交给 SRV 记录或客户端默认逻辑 |
| `instances[].required` | bool | 是否为必装实例 |

### 2.3 顺序与精选置顶

服务端已经按 `(sort, name)` 排好序，**按数组顺序渲染即可**：

- `sort = -1` 的是管理员在后台勾选的「精选置顶」实例，永远在最前面；
- 其余实例保持 `sort`（1、2、3…）原顺序；
- 精选可以是本站实例，也可以是已同步友站的实例（`id` 带 `ysumc:` 前缀）。

启动器**不要**自己重排 `sort`，也不要因为 `sort = -1` 就当成异常值。

### 2.4 缓存

- 响应带 `ETag`（强校验）和 `Cache-Control: no-cache, must-revalidate`。
- 请求时带上 `If-None-Match: <上次的 ETag>`，命中时返回 `304` 无 body，直接复用本地缓存。
- 建议：启动时拉一次，之后每 6 小时拉一次，或用户手动刷新时拉。

```http
GET /api/launcher/manifest
If-None-Match: "c44c4456ae788ec4b4abcba7a09da79584b7ed9c"
```

### 2.5 错误处理

| 状态 | 含义 | 建议 |
| --- | --- | --- |
| 200 | 正常 | 解析 |
| 304 | 未变化 | 用缓存 |
| 429 | 触发限流（60 次/分钟） | 读 `Retry-After` 秒数后退避重试 |
| 5xx | 服务端故障 | 指数退避重试，保留上次缓存 |

解析失败或网络失败时**不要清空实例列表**，继续用上次成功的缓存。

## 3. 安装整合包

1. 取 `instances[].pack`；为 `null` 时跳过下载，直接进服（纯服务器实例）。
2. 下载 `pack.url`。
3. 校验：
   - `sha1` 非 `null` 时必须校验，不匹配则拒绝安装并报错；
   - `size` 非 `null` 时可校验长度（可选）；
   - 两者都为 `null` 时（外部 CDN 且管理员未提供），只能跳过校验，建议提示用户「该实例未提供完整性校验」。
4. 按 mrpack 规范解压安装（`modrinth.index.json` + `overrides/`）。
5. 记录该实例的 `revision`；下次发现 `revision` 变化时重新下载安装。

下载请求建议带 `Accept: application/zip`，并支持断点续传（服务端支持 `Content-Length` 和范围请求）。

### 3.1 实例 id 不能直接当文件夹名

精选友站实例的 id 形如 `ysumc:survival`，`:` 在 Windows 路径里非法。请：

- 本地目录用 UUID 或 sanitize 后的 id（如把 `:` 替换成 `_`）；
- 只用 id 作为**内存/数据库主键**比对。

## 4. 服务器地址与进服

- 直接用 `server.address` + `server.port` 拼接。
- `port` 为 `null` 时：
  1. 先查 SRV 记录 `_minecraft._tcp.<address>`；
  2. 没有 SRV 再用 25565。
- 不要因为 `pack` 为 `null` 就不显示实例——纯服务器实例也应该能点「进服」。

## 5. 公告 `/api/launcher/announcements`

**返回裸数组**，没有外层对象：

```json
[
  {
    "id": "2026-summer",
    "title": "暑期活动",
    "summary": "……",
    "content": "Markdown 正文",
    "type": "notification",
    "priority": "high",
    "starts_at": "2026-07-01T00:00:00Z",
    "ends_at": "2026-09-01T00:00:00Z",
    "published_at": "2026-06-20T00:00:00Z",
    "action_url": "https://example.com",
    "action_label": "查看详情"
  }
]
```

- 服务端已经过滤掉未启用、时间窗之外、字段非法的条目，并按优先级 + 发布时间排序，最多 200 条。
- `type` 取值 `modal` / `notification`；`priority` 取值 `low` / `normal` / `high` / `critical`。
- `modal` 建议弹窗展示，`notification` 建议在通知栏/列表展示。
- `summary`、`ends_at`、`action_url`、`action_label` 可能为 `null`。
- 客户端必须能处理**空数组**。

## 6. 更新

### 6.1 轻量提示

```http
GET /api/launcher/update/latest?channel=release
```

- 有更新：`{ "version", "notes", "pub_date", "published_at" }`
- 无更新：`204 No Content`

### 6.2 Tauri v2 动态更新器

```http
GET /api/launcher/update/{target}/{arch}/{currentVersion}?channel=release
```

- 有更新：`{ "version", "notes", "pub_date", "published_at", "url", "signature" }`
- 已是最新：`204 No Content`
- `channel` 也支持请求头 `X-Axolotl-Channel`，缺省为 `release`。

## 7. 友站实例（可选）`/api/launcher/friends`

如果启动器想单独做「友站」页，可以拉这个：

```json
{
  "schema": 1,
  "generated_at": "2026-10-22T08:00:00Z",
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
          "description": "……",
          "icon_url": null,
          "sort": 1,
          "revision": 3,
          "minecraft": { "game_version": "1.20.1", "loader": "fabric", "loader_version": "0.15.0" },
          "pack": { "kind": "mrpack", "url": "https://mc.yudream.app/packs/pixelsit/3", "sha1": "…", "size": 100 },
          "server": { "address": "pixel.yudream.app", "port": 25565 },
          "required": false
        }
      ]
    }
  ]
}
```

- `stale: true` 表示该友站本轮拉取失败，内容来自上次成功快照。
- 没有可用实例的友站不会出现。
- 不做友站页可以完全忽略这个接口。

## 8. 皮肤（可选，需要会话）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/launcher/skins?player=<角色名>` | 查角色当前皮肤 |
| POST | `/api/launcher/skins` | 上传皮肤（`multipart/form-data`） |
| PUT | `/api/launcher/skins/equip` | 装备某个皮肤 |
| DELETE | `/api/launcher/skins?player=<角色名>` | 移除皮肤 |

这组接口用玩家自己的启动器会话鉴权（不是站点 Passport token），未登录返回 `401`，并带 `{ "error", "message" }`。限流 30 次/分钟。

## 9. 实现清单（最小可用）

1. `GET /api/launcher/manifest`，带 `If-None-Match`，处理 200/304/429/5xx。
2. 按数组顺序渲染实例；`sort = -1` 的排最前面，不需要特殊样式。
3. 点击实例 → 有 `pack` 就下载并校验 `sha1`（非空时）→ 按 `revision` 判断是否需要重装。
4. `server.address` + `server.port`（`null` 时走 SRV → 25565）。
5. 实例 id 用于本地目录时先 sanitize（`:` 等字符）。
6. 可选：公告、更新、友站页、皮肤。

## 10. 常见坑

- **`pack` 可能为 `null`**：纯服务器实例。不要假设实例一定有整合包。
- **`pack.sha1` / `pack.size` 可能为 `null`**：外部 CDN 托管且管理员未提供，此时只能跳过校验。
- **`server.port` 可能为 `null`**：不要硬编码 25565。
- **实例 id 可能带 `:`**：不能直接做路径。
- **`sort = -1` 是正常的**：表示精选置顶，不是脏数据。
- **公告是裸数组**：`{ "announcements": [...] }` 这种结构会被解析成空。
- **429 必须退避**：清单 60 次/分钟，皮肤 30 次/分钟。
- **不要清空缓存**：拉取失败时继续用上次成功的清单。
