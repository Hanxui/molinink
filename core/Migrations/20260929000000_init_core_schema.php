<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * 内核首批表结构,DDL 逐条来自《21-数据模型》第 2-9 节与《34-内容模型》第 6 节。
 *
 * commentmeta 在《21》第 1 节列为内核必需,但该文只给了 postmeta/usermeta 的 DDL,
 * 此处按同构的 meta 三件套补齐(comment_id + meta_key + meta_value),待文档补定后校正。
 * sessions / cron 两张可选表不在本迁移内(《21》第 10 节标 ⭕,等会话与定时任务落地再加)。
 */
final class InitCoreSchema20260929000000 extends AbstractMigration
{
    /** 建表顺序即依赖顺序(无物理外键,靠约定) */
    private const TABLES = [
        'options',
        'users',
        'usermeta',
        'posts',
        'postmeta',
        'post_revisions',
        'terms',
        'term_taxonomy',
        'term_relationships',
        'media',
        'comments',
        'commentmeta',
        'plugins',
        'themes',
    ];

    public function up(): void
    {
        $p = $this->prefix();

        $this->execute(<<<SQL
CREATE TABLE `{$p}options` (
  `option_id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `option_name`   VARCHAR(191) NOT NULL,
  `option_value`  LONGTEXT NULL,
  `autoload`      TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=启动时预载',
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `uk_name` (`option_name`),
  KEY `idx_autoload` (`autoload`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);

        $this->execute(<<<SQL
CREATE TABLE `{$p}users` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(60)  NOT NULL,
  `email`         VARCHAR(191) NOT NULL,
  `password`      VARCHAR(255) NOT NULL COMMENT 'argon2id 哈希',
  `display_name`  VARCHAR(60)  NOT NULL DEFAULT '',
  `nickname`      VARCHAR(60)  NOT NULL DEFAULT '',
  `url`           VARCHAR(255) NULL,
  `status`        VARCHAR(20)  NOT NULL DEFAULT 'active' COMMENT 'active/pending/banned',
  `registered_at` DATETIME     NOT NULL,
  `last_login_at` DATETIME     NULL,
  `last_login_ip` VARCHAR(45)  NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);

        $this->execute(<<<SQL
CREATE TABLE `{$p}usermeta` (
  `meta_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `meta_key`   VARCHAR(191) NOT NULL,
  `meta_value` LONGTEXT NULL,
  PRIMARY KEY (`meta_id`),
  KEY `idx_user_key` (`user_id`, `meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);

        $this->execute(<<<SQL
CREATE TABLE `{$p}posts` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`             VARCHAR(32)  NOT NULL DEFAULT 'post',
  `status`           VARCHAR(20)  NOT NULL DEFAULT 'draft',
  `author_id`        BIGINT UNSIGNED NOT NULL,
  `title`            VARCHAR(255) NOT NULL DEFAULT '',
  `slug`             VARCHAR(255) NOT NULL DEFAULT '',
  `excerpt`          TEXT         NULL,
  `content`          LONGTEXT     NULL,
  `content_filtered` LONGTEXT     NULL COMMENT '经钩子过滤后的渲染结果缓存',
  `parent_id`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `menu_order`       INT          NOT NULL DEFAULT 0,
  `comment_status`   VARCHAR(12)  NOT NULL DEFAULT 'open',
  `comment_count`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `view_count`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `password`         VARCHAR(255) NULL COMMENT '私密内容口令哈希',
  `thumbnail_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `published_at`     DATETIME     NULL,
  `created_at`       DATETIME     NOT NULL,
  `updated_at`       DATETIME     NOT NULL,
  `deleted_at`       DATETIME     NULL COMMENT '回收站(软删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_slug` (`type`, `slug`),
  KEY `idx_type_status_pub` (`type`, `status`, `published_at`),
  KEY `idx_author` (`author_id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_deleted` (`deleted_at`),
  FULLTEXT KEY `ft_title_content` (`title`, `content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);

        $this->execute(<<<SQL
CREATE TABLE `{$p}postmeta` (
  `meta_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id`    BIGINT UNSIGNED NOT NULL,
  `meta_key`   VARCHAR(191) NOT NULL,
  `meta_value` LONGTEXT NULL,
  PRIMARY KEY (`meta_id`),
  KEY `idx_post_key` (`post_id`, `meta_key`),
  KEY `idx_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);

        $this->execute(<<<SQL
CREATE TABLE `{$p}post_revisions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id`    BIGINT UNSIGNED NOT NULL,
  `title`      VARCHAR(255) NOT NULL,
  `content`    LONGTEXT NULL,
  `author_id`  BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_post` (`post_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);

        $this->execute(<<<SQL
CREATE TABLE `{$p}terms` (
  `term_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(200) NOT NULL,
  `slug`       VARCHAR(200) NOT NULL,
  `term_group` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`term_id`),
  KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);

        $this->execute(<<<SQL
CREATE TABLE `{$p}term_taxonomy` (
  `term_taxonomy_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `term_id`     BIGINT UNSIGNED NOT NULL,
  `taxonomy`    VARCHAR(32) NOT NULL COMMENT 'category / post_tag / 自定义',
  `description` TEXT NULL,
  `parent`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `count`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`term_taxonomy_id`),
  KEY `idx_term` (`term_id`),
  KEY `idx_tax` (`taxonomy`),
  KEY `idx_parent` (`parent`),
  UNIQUE KEY `uk_term_tax` (`term_id`, `taxonomy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);

        $this->execute(<<<SQL
CREATE TABLE `{$p}term_relationships` (
  `post_id`          BIGINT UNSIGNED NOT NULL,
  `term_taxonomy_id` BIGINT UNSIGNED NOT NULL,
  `order`            INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`post_id`, `term_taxonomy_id`),
  KEY `idx_tt` (`term_taxonomy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);

        $this->execute(<<<SQL
CREATE TABLE `{$p}media` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id`     BIGINT UNSIGNED NOT NULL COMMENT '关联 posts(附件型)',
  `file_path`   VARCHAR(255) NOT NULL COMMENT '相对 storage/uploads 的路径',
  `file_name`   VARCHAR(255) NOT NULL,
  `mime_type`   VARCHAR(100) NOT NULL,
  `file_size`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `width`       INT NULL,
  `height`      INT NULL,
  `alt_text`    VARCHAR(255) NULL,
  `caption`     VARCHAR(255) NULL,
  `sizes`       JSON NULL COMMENT '各尺寸文件信息',
  `hash`        CHAR(64) NULL COMMENT 'sha256,去重与校验',
  `uploaded_by` BIGINT UNSIGNED NOT NULL,
  `created_at`  DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_post` (`post_id`),
  KEY `idx_mime` (`mime_type`),
  KEY `idx_hash` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);

        $this->execute(<<<SQL
CREATE TABLE `{$p}comments` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id`      BIGINT UNSIGNED NOT NULL,
  `parent_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '嵌套父评论',
  `user_id`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '登录用户,0=游客',
  `author_name`  VARCHAR(120) NULL,
  `author_email` VARCHAR(191) NULL,
  `author_url`   VARCHAR(255) NULL,
  `author_ip`    VARCHAR(45) NULL,
  `author_ua`    VARCHAR(255) NULL,
  `content`      TEXT NOT NULL,
  `status`       VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/approved/spam/trash',
  `type`         VARCHAR(20) NOT NULL DEFAULT 'comment' COMMENT 'comment/pingback/trackback',
  `created_at`   DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_post_status` (`post_id`, `status`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);

        $this->execute(<<<SQL
CREATE TABLE `{$p}commentmeta` (
  `meta_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `comment_id` BIGINT UNSIGNED NOT NULL,
  `meta_key`   VARCHAR(191) NOT NULL,
  `meta_value` LONGTEXT NULL,
  PRIMARY KEY (`meta_id`),
  KEY `idx_comment_key` (`comment_id`, `meta_key`),
  KEY `idx_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);

        $this->execute(<<<SQL
CREATE TABLE `{$p}plugins` (
  `slug`         VARCHAR(50) NOT NULL COMMENT '插件 name',
  `version`      VARCHAR(20) NOT NULL,
  `active`       TINYINT(1) NOT NULL DEFAULT 0,
  `activated_at` DATETIME NULL,
  `settings`     JSON NULL,
  PRIMARY KEY (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);

        $this->execute(<<<SQL
CREATE TABLE `{$p}themes` (
  `slug`     VARCHAR(50) NOT NULL,
  `version`  VARCHAR(20) NOT NULL,
  `active`   TINYINT(1) NOT NULL DEFAULT 0,
  `settings` JSON NULL COMMENT '主题设置项的值',
  PRIMARY KEY (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);
    }

    public function down(): void
    {
        $p = $this->prefix();

        foreach (array_reverse(self::TABLES) as $table) {
            $this->execute('DROP TABLE IF EXISTS `' . $p . $table . '`');
        }
    }
}
