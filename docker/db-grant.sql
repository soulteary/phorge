-- Phorge 数据库授权（由 docker-compose.yml 的一次性 db-init 服务执行）。
--
-- Phorge 使用命名空间多库：本 fork 未设置 storage.default-namespace，
-- 因此沿用默认值 `phabricator`（见 PhabricatorMySQLConfigOptions），
-- 实际会创建 phabricator_user、phabricator_differential 等一整组库。
--
-- MySQL 官方镜像的 MYSQL_USER/MYSQL_DATABASE 只会给单个库授权，普通用户
-- 直接跑 `bin/storage upgrade` 会报 ERROR 1044 (Access denied)。这里补上整
-- 组库的权限；ALL PRIVILEGES 覆盖了建库所需的 CREATE，因此不需要 root。
--
-- 注意: 库名模式里的 `_` 在 GRANT 中同样是通配符（匹配任意单字符），所以
-- 这条授权比字面量 "phabricator_*" 略宽；MySQL 里给引号内的 `_` 转义在各版
-- 本行为不一致，宁可保持这个已验证可用的写法。
--
-- __PHORGE_USER__ 由 db-init 服务在执行前用 MYSQL_USER 的值替换。

GRANT ALL PRIVILEGES ON `phabricator_%`.* TO '__PHORGE_USER__'@'%';
FLUSH PRIVILEGES;
