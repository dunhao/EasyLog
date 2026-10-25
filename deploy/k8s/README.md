# EasyLog K8s 部署清单（v0.2）

适用于已有外部中间件的集群：MySQL、Redis、Kafka、Elasticsearch 需自行准备
（推荐用 Bitnami / Elastic 官方 chart 安装，本目录只包含 EasyLog 应用层）。

## 文件说明

| 文件 | 内容 |
|---|---|
| 00-namespace.yaml | `easylog` 命名空间 |
| 10-config.yaml | ConfigMap（公共配置）+ Secret（APP_KEY / DB_PASSWORD，**部署前必须替换**） |
| 20-app.yaml | 5 个服务角色的 Deployment + Service（同一应用镜像，`SERVICE_ROLE` 区分） |
| 30-frontend.yaml | 前端控制台 Deployment + Service（nginx 静态镜像） |
| 40-ingress.yaml | Ingress：控制台域名 → frontend，`/api` → query 服务 |

## 镜像准备

集群默认无法拉取本地镜像，二选一：

```bash
# 方式一（测试集群）：kind / minikube 直接载入
kind load docker-image easylog/app:dev easylog/frontend:dev
# minikube: minikube image load easylog/app:dev easylog/frontend:dev

# 方式二：推送到集群可达的镜像仓库，并修改 20-app.yaml / 30-frontend.yaml 的 image 字段
```

## 部署步骤

```bash
# 1. 准备中间件（示例，values 按需调整）
helm install mysql bitnami/mysql -n easylog --create-namespace
helm install redis bitnami/redis -n easylog
helm install kafka bitnami/kafka -n easylog
helm install elasticsearch elastic/eck-operator   # 或 bitnami/elasticsearch

# 2. 修改 10-config.yaml：Secret 中的 APP_KEY（php artisan key:generate 生成）与 DB_PASSWORD
#    ConfigMap 中的 DB_HOST / REDIS_HOST / KAFKA_BROKERS / ES_HOSTS 指向实际中间件地址

# 3. 应用清单
kubectl apply -f deploy/k8s/

# 4. 验证
kubectl -n easylog get pods
kubectl -n easylog logs deploy/easylog-admin | tail -20
```

## 说明

- **首次启动**：admin Deployment 的 entrypoint 会自动执行 migrate + seed（与 compose 行为一致），
  因此 admin 保持 1 副本；其它角色可水平扩副本。
- **告警进程**：worker 角色的 entrypoint 会同时拉起 `easylog:alert-evaluator` 与
  `easylog:dispatch-alert-notifications`（多副本 worker 会导致告警重复评估，worker 建议 1 副本）。
- **配额**：Manifest 默认不限制；如需配额，建好工作空间后在控制台或 `PUT /workspaces/{ws}/quota` 设置。
- **Ingress**：默认注释了域名配置，按集群实际 IngressClass（nginx / traefik）启用。
- **接入网关隔离（重要）**：ingestion(9514) **只暴露公开接入 API**（`/api/v1/ingest/*` 与
  `/api/v1/health`），`routes/api.php` 用 `EasyLog\Support\ServiceRole` 门控，**不注册任何管理路由**
  （`/api/v1/users`、`/api/v1/workspaces`、`/audit-logs` 等）。控制台与 Ingress 的 `/api`
  **必须指向 admin / query(9515)**，切勿指向 ingestion(9514)，否则管理面不可达。
- **DB_HOST**：`10-config.yaml` 现已包含 `DB_HOST`（默认 `mysql.easylog.svc.cluster.local`），
  部署前按实际 MySQL 服务名调整（bitnami/mysql 主节点常为 `mysql-primary`）。
