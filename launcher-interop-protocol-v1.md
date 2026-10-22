# 跨站启动器互操作协议 v1（mc-interop v1）

> 状态：**草案（v1 简化结构；站点出口已在 sitmc-launcher 1.3.0 实现）**。本文件是站点
> 对外的统一出口协议：整合包与服务器绑定目录 + 多站发现，供任意启动器与第三方站点消费
> （不限于 YDA / YMCL）。
>
> 与 `launcher-adapter-protocol-v2.md` 的关系：v2 是 YDA **站内**启动器契约（导航/页面/动作/分发），
> 本协议是**跨站**互操作契约。两者并存：统一出口在站点内部可由 launcher-adapter v2 或
> ymcl-adapter（YAP/MIP）作为分发与绑定后端承接，经适配层投影为本文档的中立模型。
> 本文件与 v2 文档平级、互不覆盖；冲突时站内行为以 v2 文档为准，跨站出口以本文档为准。

## 0. 目标与非目标

目标：

1. 每个站点只暴露**一个稳定统一出口**（well-known 端点），任何启动器据此获得：
   站点身份、**管理员选择发布**的实例目录、服务器地址与友站发现。
2. **适配器架构**：入口侧适配多种整合包格式（mrpack / CurseForge zip / MultiMC / 原版 /
   遗留 schema），出口侧序列化**唯一**——v1 只出一种 canonical JSON。
3. **多站互通**：跨站聚合发生在**站点服务端**——本家站轮询友站清单、合并后经自己的
   统一出口下发给启动器；启动器只读本家一个 origin，不做多站客户端聚合。
4. **启动器侧对应协议**：发现、缓存、安装/更新、加入服务器的完整客户端行为规范。

非目标（v1 明确不做）：

- 不替代 YDA 站内 v2/YAP（页面、动作、认证、主题仍走站内契约）。
- **不含启动器自升级策略**。参考站 `skin.sitmc.club/api/launcher/manifest` 中的
  `launcher{min_version,latest_version,force_update}` 属于启动器应用自身的更新通道，
  与「哪个站点有哪些包、服务器要什么包」是两回事，继续留在各启动器自己的升级端点。
- 不做跨站账号互通、通用文件 CDN、支付/身份体系。

## 1. 总体架构

```text
                    ┌─ ingest 适配器（站点内部）────────────────┐
 mrpack 包 ───────►│ mrpack ingest → canonical Pack 元数据      │
 CF zip 包 ───────►│ cf-zip ingest  → canonical Pack 元数据      │──┐
 原版/serverjar ──►│ vanilla ingest → canonical Pack 元数据      │  │
 遗留 schema:1 ───►│ legacy import  → canonical（一次性迁移）    │  │
                    └────────────────────────────────────────────┘  │
                                                                     ▼
 服务器档案（mc-server 周目绑定、                ┌──────────────────────────┐
 发布控制台绑定、YAP serverBindings）──────────►│  统一出口（唯一序列化）    │
                                                 │  /.well-known/           │
 分发后端（launcher-adapter v2 /                 │  mc-interop-v1.json      │
 ymcl-adapter MIP / 纯 URL 托管）──────────────►│  SiteDocument            │
                                                 └──────────────────────────┘
                                                             ▲
 任意启动器（YMCL / 第三方）────────────────────────────────┘
  site 内部：canonical → native 适配器
```

- **入口适配**：整合包以什么格式上传/导入由站点内部决定，ingest 适配器把不同格式解析为
  canonical 元数据（gameVersion、loader、hash、size、artifact URL）。
- **出口唯一**：无论内部用哪套存储/分发系统，对外只有一份 SiteDocument，一种 wire 格式。
  「多站互通」的所有消费方只依赖这份文档，不再出现 sitmc schema:1、YAP MIP、v2 各说各话。
- **整合包与服务器合并表达**：每个条目同时描述一个整合包和它绑定的一个服务器地址，
  出口不再拆成 `packs` 与 `servers` 两个区块。
- **分发后端可插拔**：artifact 从哪下载、能否增量对账，由 `artifact.distribution` 声明，
  支持它的启动器走后端原生端点（如 v2 的 head→manifest→sha1 对账），其余走整包 URL。

## 2. 端点与 wire 约定

### 2.1 统一出口端点

```text
GET {origin}/.well-known/mc-interop-v1.json        → SiteDocument（200）
```

- `origin` 为站点 HTTPS 根；强制 HTTPS，http 请求方应自行升级。
- 路径**版本化**（`mc-interop-v1.json`）：路径中的主版本即文档版本，响应体不再重复
  声明 `manifestVersion`；未来破坏性变更发布 `mc-interop-v2.json` 并并存过渡，
  已发布路径永不改变语义。
- YDA 部署形态：由宿主反代把 well-known 路径转发到适配器插件端点（如
  `/api/plugins/launcher-adapter/interop/v1/site`），对外不暴露插件路径细节；非 YDA 站点
  用任意静态文件/网关实现均可——协议只约定路径与响应体。

### 2.2 wire 约定（与 launcher-adapter v2 同基调）

- 响应为裸 JSON（无 `{code,data}` 信封）；错误用 HTTP 状态码 + `{"message": "..."}`。
- 字段命名 **camelCase**（站内 YAP/MIP 的 snake_case 属内部形态，出口一律转 camelCase）。
- 所有 Long/Snowflake ID 在 JSON 中一律为 `string`。
- 枚举值小写中划线（`stable`、`fabric`、`yda-launcher-v2`）。
- 时间戳一律 UTC ISO-8601（`2026-10-21T08:00:00Z`）。
- **容忍规则**：客户端必须忽略未知字段；服务端必须容忍缺省字段并给默认值。
  未知枚举值：集合类（`packs`/`peers`）跳过该条并记诊断，标量字段按缺省处理。
- 缓存：响应应带 `ETag`（由 `updatedAt`/`revision` 派生）与
  `Cache-Control: public, max-age=300`；客户端条件请求 `If-None-Match` → `304`。
- 尺寸建议：SiteDocument ≤ 256 KiB；v1 不定义拆分，超限站点应减少发布条目或升级到
  带拆分能力的新主版本。

## 3. SiteDocument（发现文档）

```json
{
  "site": {
    "id": "sitmc",
    "name": "SIT Minecraft",
    "description": "SIT MC 公共服务器与整合包",
    "domain": "skin.sitmc.club"
  },
  "packs": [
    { /* PackEntry，§4 */ }
  ],
  "peers": [
    { /* PeerEntry，§7 */ }
  ],
  "updatedAt": "2026-10-21T08:00:00Z"
}
```

- 文档版本由**请求路径**决定（`mc-interop-v1.json` 的 `v1`），响应体不再携带
  `manifestVersion` 或 `protocol` 块；同一路径内只允许增量加字段，破坏性变更换新路径。
- `site.id` 是站点稳定标识；`site.name` **直接取站点自身的站点名设置**（本站为
  Blessing Skin 后台的站点名称），互操作配置不单独维护名称；`site.description` 必填，
  可为空字符串。
- `site.domain` 是本站的**根域名**（host，不带 scheme/path，如 `skin.sitmc.club`），
  用于标识站点身份；客户端实际请求的 origin 仍是请求根。
- v1 出口**匿名可读**，不定义 `auth` 字段，也不出口任何门控内容；`site` 中不放置
  secret/token/内网地址。
- `packs` 是唯一的目录数组；名称沿用历史字段，但元素语义是**实例**：
  `整合包（artifact，可省略）+ 服务器 IP（server）+ 版本（有包时为 revision，无包时为 gameVersion）`。
  不要再另起 `servers` 或 `instances` 字段。条目可以没有 `artifact`（纯服务器 / vanilla），
  但必须有一个 `server`。
- `site` 标识本站自己；`peers[]` 只列已知友站的信息（不重复列本站），供其他站点
  互相发现。
- 每个整合包只输出**当前 revision**，历史版本不进入出口；更新检测以条目的 `revision`
  为准。
- `peers` 始终输出；没有友站时为 `[]`。它只给其他站点互采参考，启动器可完全忽略。
- `updatedAt` 只在顶层出现，各集合不再带自己的 `updatedAt`；顶层 `updatedAt` 变化即
  整份文档重拉。
- 出口中的条目可包含本站互采合并的友站内容（§7），外来条目携带 `source` 溯源字段、
  本家条目省略。
- v1 不定义 `site.origin`、`site.website`、`site.icon`、`site.contact`，客户端以实际
  请求的 origin 为准。
- 机器可读结构定义见附录 B，完整示例见附录 C。

### 3.1 发布范围（强制）

- 站点**不得自动把全部实例**写进 `packs[]`；管理员必须在后台显式选择要推送到清单的
  实例（发布开关 / 白名单 / 选择列表）。
- 只有被选中的实例才出现在出口；未选中的实例不得通过任何字段或接口泄露。
- 发布选择是站内配置，不进入 JSON；`packs[]` 里只有被选中的条目。附录 C 只列被选中的
  3 个实例，站内其他实例不会出现。
- 友站内容按各友站自己的发布选择合并；本家不得替友站扩大范围。

## 4. PackEntry：整合包 + 服务器条目

```json
{
  "id": "survival",
  "name": "生存服整合包",
  "summary": "第三周目生存服官方整合包",
  "revision": 12,
  "gameVersion": "26.2",
  "loader": "fabric",
  "loaderVersion": "0.19.5",
  "artifact": {
    "format": "mrpack",
    "url": "https://skin.sitmc.club/api/launcher/packs/survival/12",
    "size": 511459395,
    "sha1": "b00e0021aada1321b81cbd9693782df39b5f67a0",
    "sha512": null
  },
  "server": {
    "address": "play.sitmc.club",
    "port": 25565
  }
}
```

字段规则：

| 字段 | 规则 |
| --- | --- |
| `id` | 站内稳定 ID（string），**站点内唯一**；无 `artifact` 的纯服务器条目用它表示服务器标识。 |
| `name` | 必填，展示名。 |
| `summary` | 可选，简短说明。 |
| `revision` | 有 `artifact` 时必填，单调递增整数；同包同 revision 内容变化必须 +1。无 `artifact` 时省略。 |
| `gameVersion` | 必填；MC 版本字符串，原样透传不做猜测。 |
| `loader` | `vanilla` \| `fabric` \| `forge` \| `neoforge` \| `quilt`；**枚举外值禁止出站**（fail-closed，见 §6.4）。`vanilla` 时 `loaderVersion` 省略。 |
| `loaderVersion` | 可选；非 `vanilla` 时加载器版本。 |
| `artifact` | 可选；有整合包时必填对象，无整合包（纯服务器 / vanilla）时整个字段省略。 |
| `artifact.format` | `mrpack` \| `multimc` \| `curseforge-zip` \| `server-zip` \| `raw`；声明**artifact 容器格式**，ingest 适配器清单见 §6.1。 |
| `artifact.url` | 必填、绝对 HTTPS URL，直接作为下载地址；v1 不定义镜像列表。 |
| `artifact.size` | 必填，字节数。 |
| `artifact.sha1` / `sha512` | 两个键都输出，未知的写 `null`；至少一个非 `null`。`sha512` 建议必给，`sha1` 为兼容保留；两者同时存在时以 `sha512` 为准。 |
| `artifact.distribution` | 可选。声明可增量对账的分发后端，当前登记：`yda-launcher-v2`（launcher-adapter `/v2/packs/**` head→manifest→files 对账）、`yap-mip`（ymcl-adapter CAS）。不支持的启动器忽略它、整包装。 |
| `server` | 必填；绑定一个服务器地址，最多一个。 |
| `server.address` | 必填；玩家可见主地址。 |
| `server.port` | 可选；省略 = 不指定（由 SRV 记录或客户端默认处理），**禁止**服务端擅自补 25565。 |
| `source` | 友站条目才有：`{siteId, domain, stale?}`；本家条目省略。 |

- 一个条目就是一个**实例**：一个整合包（可省略）+ 一个服务器 IP + 版本。同一整合包
  需要出现在多个服务器时，使用多个条目并保证 `id` 唯一。
- 目录**只含包级/版本级元数据**，不含文件级 entry 清单——文件级细节在 artifact 内部
  （mrpack index 等）或分发后端端点里，出口保持轻量。
- 不再有 `version`、`channel`、`flags`、`releaseNotes`、`icon` 等字段；当前 revision 就是
  有包条目的唯一版本，无包条目用 `gameVersion` 表达版本。

## 5. server 对象（内嵌，不是独立区块）

```json
{
  "address": "play.sitmc.club",
  "port": 25565
}
```

- `server` 内嵌在 PackEntry 中；**每个条目必须有一个服务器**，不再有 `servers[]` 和
  `endpoints[]`。
- `address` 必填；`port` 可选，省略时不补默认端口。
- 不再有 `status`、`currentSeason`、`binding`、`sources`、`authority`、`conflict`、
  `optionalPackRef`、`updatePolicy`：条目本身已经是「整合包 → 服务器」的绑定。
- 没有整合包的服务器（vanilla）就是一个省略 `artifact` 的条目，`gameVersion` + `loader`
  描述启动要求，`server` 描述地址。

## 6. 适配器架构

### 6.1 ingest 适配器（包格式 → canonical 元数据）

| 适配器 | 输入 | 解析产出 | 备注 |
| --- | --- | --- | --- |
| `mrpack` | Modrinth `.mrpack`（`modrinth.index.json`） | `gameVersion`、`loader`/`loaderVersion`（dependencies）、hash（sha1/sha512，与官方 files 字段同名同义）、size | 首选格式；文件级 entry 留在包内，不物化进目录。 |
| `curseforge-zip` | CF 导出 zip | 同上（解析其 manifest） | 平台托管文件需在发布时解析为直链或托管副本；**再分发许可由站点发布方自证**，协议不背书。 |
| `multimc` | MultiMC 实例目录打包 | `instance.cfg` + `mmc-pack.json` → 版本/加载器 | `format: "multimc"`。 |
| `vanilla` | 手填版本 + 可选 overrides.zip | `gameVersion`/`loader`（可 `vanilla`） | overrides 走分发后端对账或 `server-zip` 整包。 |
| `legacy-sitmc-1` | 遗留 `schema:1` manifest | 一次性导入为 canonical（§9.4） | 迁移完成后停用。 |

通用规则：适配器**只产出元数据与 artifact 引用，不改变包体**；解析失败必须整版本拒绝
（fail-closed），不得产出半填充的 canonical 条目；loader/枚举校验在 ingest 完成后出站数据
保证合法。

### 6.2 分发后端适配（artifact 从哪来、怎么更新）

- `yda-launcher-v2`：`distribution.packId/versionId` 指向 launcher-adapter 的 pack 体系，
  支持 head/etag 检测 + entries 对账增量更新（v2 §5.4 算法原样复用）。
- `yap-mip`：`distribution` 指向 ymcl-adapter `ymcl_packs`（CAS sha512 寻址）。
- 无 `distribution`：纯 URL 整包安装，`sha512/sha1` 校验通过即完成，无增量。
- 两套 YDA pack 目录的 `packId` 命名空间不互通（现状风险），canonical 层用
  `distribution.system` 隔离命名空间，禁止跨系统裸引用 packId。

### 6.3 出口序列化器（唯一）

站点内部任意存储 → 单一 SiteDocument。序列化器职责：camelCase 投影、ID 字符串化、
枚举白名单校验、绝对 URL 化（相对路径一律按站点 origin 补全）、把整合包与服务器档案
合并为同一个 `packs[]` 条目、ETag/updatedAt 生成。**出口不允许按请求方切换格式**（那会
重新碎片化）；未来新格式 = 新 major 路径，不做运行时协商。

### 6.4 启动器侧适配器（canonical → native）

每个启动器实现一个 canonical 消费适配器：

- **YMCL**：SiteDocument → 站内 v2 模型映射（§9.1），目录页用既有 `pack-catalog`
  renderer，安装走既有分发管线；加入服务器动作映射 `client:launch-server`。
- **通用第三方启动器**：直接按本文档实现发现/目录/安装/进服四步即可，无 YDA 依赖。
- 启动器本地声明自己支持的 `artifact.format` 集合；目录中不支持的 format 条目
  显示但禁用安装，不算协议违规。

## 7. 多站互通（站点侧互采聚合）

跨站聚合发生在**站点服务端**，启动器不参与：启动器只认一个本家 origin，本家站后台
轮询友站清单、合并进自己的统一出口。角色只有两种，且可兼任（A 站采 B 站的同时被 C 站采）：

- **源站**：被别人采的站。只需按本协议发布 well-known 清单，无需任何"被聚合"配置。
- **本家（聚合站）**：启动器配置的站点。后台维护友站 allowlist，定时拉取各友站
  SiteDocument 并合并为自己的出口视图。

### 7.1 PeerEntry（本家声明自己采谁 / 推荐谁）

```json
{"siteId": "yda", "name": "余梦", "description": "余梦的公共服务器",
 "domain": "mc.yudream.app",
 "manifestUrl": "https://mc.yudream.app/.well-known/mc-interop-v1.json"}
```

- `siteId` 站内唯一；`domain` 是友站根域名（host，不带 scheme/path）；`manifestUrl`
  是可直接拉取的 v1 清单地址。
- `name`、`description` 可选，仅展示。
- `site` 标识本站自己；`peers[]` 只列已知友站，**不重复列本站**，供其他站点互相发现。
  `peers` 始终输出，没有友站时为 `[]`。启动器可完全忽略该字段。
- v1 不再定义 `trust` 字段。

### 7.2 互采与合并规则

- **拉取**：条件请求（`If-None-Match`）+ 15s 超时 + 3 次退避重试；友站失败时沿用最近
  一次成功快照继续服务，并把该来源条目的 `source.stale: true` 置位，**永不静默清空**
  已采入内容。友站清单 URL 必须指向 v1 路径（`mc-interop-v1.json`）；若迁移到
  `mc-interop-v2.json` 等新主版本，本家不得按 v1 解释，应暂停采入并告警，等管理员
  改回 v1 或本家完成适配。
- **溯源**：合并视图中每条外来条目携带
  `source: {"siteId": "...", "domain": "..."}`；快照过期时**才**追加 `"stale": true`，
  正常快照不输出 `stale`。本家自有条目省略 `source`。前端可据此分组展示"友站内容"。
- **命名空间**：本站内部资源键 = `domain + "/" + id`；对外出口仍是一个扁平
  `packs[]`，消费方无感知。外来条目 id 与本家冲突时，本家丢弃该外来条目并记诊断，
  避免服务器绑定指向不明确。
- **原样合并**：外来条目已经自带 `server`，合并时不需要再重写绑定；`artifact.url`
  保持指向源站绝对 URL，完整性以 hash 校验为准。
- **门控**：v1 出口匿名可读，不定义 `auth` 字段，也不采入需要认证的友站内容；门控
  内容留在各站站内契约。

### 7.3 站点添加深链

`mcinterop://add-site?url={origin}`（编码后的站点 origin 或 manifestUrl）语义是
"把该站**添加/切换为本家站点**"：启动器维护的是本家 origin 列表（供切换），不是聚合源
列表。启动器必须同时兼容遗留 `ymcl://add-site?url=`。

## 8. 启动器侧行为规范

1. **单源原则与发现**：启动器**只访问本家 origin**，不实现多站客户端聚合（`peers`
   字段可忽略；多站切换 = 本家站点列表切换后重新发现）。用户给 origin（或深链）→
   `GET /.well-known/mc-interop-v1.json`。超时 15s、响应上限 1 MiB、
   失败重试 3 次退避；发现失败给出明确错误（DNS/TLS/404/格式），不得静默降级为空目录。
2. **缓存**：持久化最近 SiteDocument + ETag；启动时与每 6 小时条件请求刷新；
   站点切换主版本路径（如改配为 `mc-interop-v2.json`）视为新站点协议，弹窗确认后重新解释。
3. **目录页**：`packs` 按数组顺序渲染；有 `artifact` 的条目展示当前 `revision`，没有
   `artifact` 的条目显示为纯服务器（版本看 `gameVersion`）。
4. **安装/进服**：
   - 有 `artifact`：检查本地安装态，`revision` 落后时按站点/用户策略更新（有
     `distribution` 且支持 → 走对账增量；否则整包下载 + hash 校验）。
   - 无 `artifact`：不安装整合包，按 `gameVersion` + `loader(+loaderVersion)` 匹配或
     创建本地实例。
   - 安装/匹配完成后连接 `server.address`，`port` 缺省交给 SRV 记录或客户端默认。
5. **单服务器**：每个条目恰好一个 `server`；不再有多线路选择，端口缺省时不擅自补 25565。
6. **诊断与降级**：未知字段忽略；未知枚举（loader/format）按 §2.2 容忍规则处理并记本地
   诊断日志；`packs` 条目解析失败时跳过该条，不影响其他条目。

## 9. 与现有系统的对接与迁移

### 9.1 canonical ↔ launcher-adapter v2 字段映射

| canonical | v2 对应 | 说明 |
| --- | --- | --- |
| `site` | `domain` | `site.domain` 对应 v2 `domain`；`site.id` 是稳定标识；`site.name` 取站点名设置（Blessing Skin 为 `site_name`）。 |
| `packs[]` | `LauncherPack`/`PackVersion` | 一个条目对应一个包版本的投影；`revision` 同义。 |
| `artifact.distribution.system="yda-launcher-v2"` | `/v2/packs/{packId}/versions/{versionId}/**` | 支持 v2 的启动器走原对账。 |
| `entry.server` | `ModpackBinding` | 条目内嵌的服务器地址/端口；`artifact` 存在 ≈ MRPACK，不存在 ≈ VANILLA。 |
| `client:launch-server` 等动作 | v2 §4.3 动作表 | 语义不变，出口不承载动作（目录协议无交互动作）。 |

### 9.2 canonical ↔ YAP/MIP 映射（ymcl-adapter）

| canonical | YAP/MIP 对应 |
| --- | --- |
| `packs[]` | `ymcl_packs`/`ymcl_pack_versions`（CAS sha512 → `artifact.sha512`）。 |
| `entry.server` | `/mip/api/servers` 聚合视图（serverId/name/mcAddress）。 |
| `revision` | `ymcl_pack_versions` 的版本序号；更新检测基准。 |
| 访问控制 | MIP 面需 `plugin:ymcl-adapter:view`；**统一出口匿名可读**，门控内容 v1 不出口，也不定义认证字段。 |

### 9.3 mc-server 周目绑定投影

v1 不再做「周目 / 发布控制台」双源聚合。站点在生成条目时自行选定最终的整合包与服务器，
`MinecraftServerSeason.modpackBinding` 只作为站内取值来源；出口只表达选中的结果，不再
输出 `authority`/`conflict`/`sources`。

### 9.4 遗留 sitmc schema:1 迁移（一次性）

- `instances[]` → PackEntry：`pack.kind` → `artifact.format`；
  `pack.url/sha1/size` → `artifact`；`minecraft.*` → 版本三元组；
  `server.address/port` → `server`；`revision` → `revision`；源 `sort` 忽略。
- `required` 不再有对应字段：条目绑定服务器即视为该服的包。
- 只迁移**管理员选择发布**的实例；未选择发布的实例不进入出口（见 §3.1）。
- `server.address` 为空的遗留实例不进入出口——没有服务器 IP 的条目不是一个实例。
- `launcher{...}` 块**不迁移**（启动器自升级，见 §0 非目标）。
- 数据修正规则：`loader: "fabirc"` 等非法枚举**拒绝该条目**并告警，修源后重导；
  `port: null` → 省略字段；源 `sort` 不迁移。
- 迁移完成后源 manifest 保留只读一个观察期，然后 301/下线。

### 9.5 落地顺序

1. 定稿本规范 → 2. 出口序列化器（整合包 + 服务器合并投影）→ 3. legacy-sitmc-1 ingest
   导入 → 4. YMCL canonical 适配器（目录/安装/进服）→ 5. 站点侧互采聚合（peers
   拉取/合并/溯源）与深链。

## 10. 安全

- 强制 HTTPS；出口匿名可读，不放置任何 secret/token/内网地址。
- artifact 必须带 hash（建议 sha512）；启动器下载后先验 hash 再落盘，原子替换。
- ingest 适配器解析外来包时按不可信输入处理（zip 路径穿越、超大小 entry、解压炸弹
  防护）；artifact URL 只允许 https 且禁止指向站点内网段（SSRF 防护在发布侧校验）。
- `curseforge-zip` 等第三方格式再分发许可由站点发布方负责，协议层只做技术适配。

## 附录 A：字段速查

- SiteDocument：`site{id,name,description,domain}` / `packs[]` / `peers[]` / `updatedAt`
- PackEntry：`id` / `name` / `summary` / `source?{siteId,domain,stale}` / `revision?` / `gameVersion` / `loader` / `loaderVersion?` / `artifact?{format,url,size,sha1,sha512,distribution{system,packId,versionId}}` / `server{address,port?}`
- Server（内嵌）：`address` / `port?`
- PeerEntry：`siteId` / `name?` / `description?` / `domain` / `manifestUrl`

## 附录 B：JSON Schema（v1）

以下是 v1 SiteDocument 的机器可读结构定义（JSON Schema draft-07）。实现方应至少保证
必填字段、类型、枚举与条件约束与之一致；`additionalProperties: true` 仅为兼容未来增量
字段，v1 的生成方不应主动输出未在本文档登记的字段。

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "$id": "https://skin.sitmc.club/.well-known/mc-interop-v1.schema.json",
  "title": "mc-interop v1 SiteDocument",
  "type": "object",
  "required": ["site", "packs", "peers", "updatedAt"],
  "properties": {
    "site": { "$ref": "#/definitions/site" },
    "packs": { "type": "array", "items": { "$ref": "#/definitions/packEntry" } },
    "peers": { "type": "array", "items": { "$ref": "#/definitions/peerEntry" } },
    "updatedAt": { "type": "string", "format": "date-time" }
  },
  "additionalProperties": true,
  "definitions": {
    "host": {
      "type": "string",
      "minLength": 1,
      "maxLength": 253,
      "format": "hostname",
      "pattern": "^[A-Za-z0-9.-]+$"
    },
    "site": {
      "type": "object",
      "required": ["id", "name", "description", "domain"],
      "properties": {
        "id": { "type": "string", "pattern": "^[A-Za-z0-9._-]{1,128}$" },
        "name": { "type": "string", "minLength": 1, "maxLength": 128 },
        "description": { "type": "string", "maxLength": 2000 },
        "domain": { "$ref": "#/definitions/host" }
      },
      "additionalProperties": true
    },
    "server": {
      "type": "object",
      "required": ["address"],
      "properties": {
        "address": { "type": "string", "minLength": 1, "maxLength": 255 },
        "port": { "type": "integer", "minimum": 1, "maximum": 65535 }
      },
      "additionalProperties": true
    },
    "distribution": {
      "type": "object",
      "required": ["system"],
      "properties": {
        "system": { "enum": ["yda-launcher-v2", "yap-mip"] },
        "packId": { "type": "string", "minLength": 1 },
        "versionId": { "type": "string", "minLength": 1 }
      },
      "additionalProperties": true
    },
    "artifact": {
      "type": "object",
      "required": ["format", "url", "size", "sha1", "sha512"],
      "properties": {
        "format": { "enum": ["mrpack", "multimc", "curseforge-zip", "server-zip", "raw"] },
        "url": { "type": "string", "format": "uri", "pattern": "^https://" },
        "size": { "type": "integer", "minimum": 1 },
        "sha1": { "type": ["string", "null"], "pattern": "^[0-9a-f]{40}$" },
        "sha512": { "type": ["string", "null"], "pattern": "^[0-9a-f]{128}$" },
        "distribution": { "$ref": "#/definitions/distribution" }
      },
      "anyOf": [
        { "properties": { "sha1": { "type": "string" } } },
        { "properties": { "sha512": { "type": "string" } } }
      ],
      "additionalProperties": true
    },
    "source": {
      "type": "object",
      "required": ["siteId", "domain"],
      "properties": {
        "siteId": { "type": "string", "pattern": "^[A-Za-z0-9._-]{1,128}$" },
        "domain": { "$ref": "#/definitions/host" },
        "stale": { "type": "boolean" }
      },
      "additionalProperties": true
    },
    "packEntry": {
      "type": "object",
      "required": ["id", "name", "gameVersion", "loader", "server"],
      "properties": {
        "id": { "type": "string", "pattern": "^[A-Za-z0-9._-]{1,128}$" },
        "name": { "type": "string", "minLength": 1, "maxLength": 128 },
        "summary": { "type": "string", "maxLength": 2000 },
        "source": { "$ref": "#/definitions/source" },
        "revision": { "type": "integer", "minimum": 0 },
        "gameVersion": { "type": "string", "minLength": 1, "maxLength": 64 },
        "loader": { "enum": ["vanilla", "fabric", "forge", "neoforge", "quilt"] },
        "loaderVersion": { "type": "string", "maxLength": 64 },
        "artifact": { "$ref": "#/definitions/artifact" },
        "server": { "$ref": "#/definitions/server" }
      },
      "dependencies": {
        "artifact": ["revision"],
        "revision": ["artifact"]
      },
      "additionalProperties": true
    },
    "peerEntry": {
      "type": "object",
      "required": ["siteId", "domain", "manifestUrl"],
      "properties": {
        "siteId": { "type": "string", "pattern": "^[A-Za-z0-9._-]{1,128}$" },
        "name": { "type": "string", "maxLength": 128 },
        "description": { "type": "string", "maxLength": 2000 },
        "domain": { "$ref": "#/definitions/host" },
        "manifestUrl": { "type": "string", "format": "uri", "pattern": "^https://" }
      },
      "additionalProperties": true
    }
  }
}
```

## 附录 C：完整 SiteDocument 示例

下面是 SIT Minecraft 在 2026-10-21 的一个完整出口示例，可直接作为实现与联调参照。
示例中的 hash 与端口仅用于展示格式；`packs` 使用内联数组，友站条目若被合并会额外携带
`source`，本示例只展示本家条目。

```json
{
  "site": {
    "id": "sitmc",
    "name": "SIT Minecraft",
    "description": "SIT MC 公共服务器与整合包",
    "domain": "skin.sitmc.club"
  },
  "packs": [
    {
      "id": "survival",
      "name": "生存服整合包",
      "summary": "第三周目生存服官方整合包",
      "revision": 12,
      "gameVersion": "26.2",
      "loader": "fabric",
      "loaderVersion": "0.19.5",
      "artifact": {
        "format": "mrpack",
        "url": "https://skin.sitmc.club/api/launcher/packs/survival/12",
        "size": 511459395,
        "sha1": "b00e0021aada1321b81cbd9693782df39b5f67a0",
        "sha512": null
      },
      "server": {
        "address": "play.sitmc.club",
        "port": 25565
      }
    },
    {
      "id": "pixelsit",
      "name": "像素生存整合包",
      "summary": "轻量像素风整合包",
      "revision": 3,
      "gameVersion": "1.20.1",
      "loader": "fabric",
      "loaderVersion": "0.15.11",
      "artifact": {
        "format": "mrpack",
        "url": "https://skin.sitmc.club/api/launcher/packs/pixelsit/3",
        "size": 48201122,
        "sha1": "9a7b6c5d4e3f201182736455463728190a1b2c3d",
        "sha512": "f1e2d3c4b5a697887766554433221100ffeeddccbbaa99887766554433221100f1e2d3c4b5a697887766554433221100ffeeddccbbaa99887766554433221100"
      },
      "server": {
        "address": "pixel.sitmc.club",
        "port": 25565
      }
    },
    {
      "id": "creative",
      "name": "创造服",
      "gameVersion": "26.2",
      "loader": "vanilla",
      "server": {
        "address": "build.sitmc.club"
      }
    }
  ],
  "peers": [
    {
      "siteId": "yda",
      "name": "余梦",
      "description": "余梦的公共服务器",
      "domain": "mc.yudream.app",
      "manifestUrl": "https://mc.yudream.app/.well-known/mc-interop-v1.json"
    }
  ],
  "updatedAt": "2026-10-21T08:00:00Z"
}
```
