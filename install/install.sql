CREATE TABLE `mod_sources` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `domain` varchar(255) NOT NULL,
    `tags` varchar(500) DEFAULT NULL,
    `region` varchar(255) DEFAULT NULL,
    `date_created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `date_last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `mod_sources_tags` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `type` varchar(255) DEFAULT NULL,
    `tag` varchar(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `tag` (`tag`),
    KEY `type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `mod_sources_pages` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `source_id` int unsigned NOT NULL,
    `title` varchar(1000) DEFAULT NULL,
    `url` varchar(500) DEFAULT NULL,
    `image` varchar(500) DEFAULT NULL,
    `source_domain` varchar(128) DEFAULT NULL,
    `source_url` varchar(500) DEFAULT NULL,
    `source_author` varchar(255) DEFAULT NULL,
    `count_views` int unsigned DEFAULT NULL,
    `date_publish` timestamp NULL DEFAULT NULL,
    `date_created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `date_last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `source_id` (`source_id`),
    FULLTEXT KEY `ft1` (`title`),
    CONSTRAINT `fk1_mod_sources_pages` FOREIGN KEY (`source_id`) REFERENCES `mod_sources` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `mod_sources_pages_tags` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `page_id` int unsigned DEFAULT NULL,
    `tag_id` int unsigned DEFAULT NULL,
    `date_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `page_id` (`page_id`),
    KEY `tag_id` (`tag_id`),
    CONSTRAINT `fk1_mod_sources_pages_tags` FOREIGN KEY (`page_id`) REFERENCES `mod_sources_pages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk2_mod_sources_pages_tags` FOREIGN KEY (`tag_id`) REFERENCES `mod_sources_tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `mod_sources_pages_contents` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `page_id` int unsigned DEFAULT NULL,
    `content` longtext,
    `hash` varchar(128) NOT NULL,
    `date_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_last_update` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `page_id` (`page_id`),
    FULLTEXT KEY `ft1` (`content`),
    CONSTRAINT `fk1_mod_sources_pages_contents` FOREIGN KEY (`page_id`) REFERENCES `mod_sources_pages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `mod_sources_contents_raw` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `source_id` int unsigned NOT NULL,
    `domain` varchar(255) DEFAULT NULL,
    `url` varchar(255) NOT NULL,
    `status` enum('pending','process','complete','error')NOT NULL DEFAULT 'pending',
    `content_type` enum('html','json','text') DEFAULT 'html',
    `section_name` varchar(255) DEFAULT NULL,
    `content` longtext CHARACTER SET utf8 NOT NULL,
    `options` json DEFAULT NULL,
    `note` varchar(255) DEFAULT NULL,
    `date_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_last_update` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `url` (`url`),
    KEY `status` (`status`),
    KEY `source_id` (`source_id`),
    KEY `domain` (`domain`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `mod_sources_pages_media` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `page_id` int unsigned NOT NULL,
    `url` varchar(500) DEFAULT NULL,
    `type` varchar(255) DEFAULT NULL,
    `description` varchar(255) DEFAULT NULL,
    `date_created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `date_last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `page_id` (`page_id`),
    CONSTRAINT `fk1_mod_sources_pages_images` FOREIGN KEY (`page_id`) REFERENCES `mod_sources_pages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `mod_sources_pages_references` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `page_id` int unsigned NOT NULL,
    `domain` varchar(255) DEFAULT NULL,
    `url` varchar(500) DEFAULT NULL,
    `date_created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `date_last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `page_id` (`page_id`),
    CONSTRAINT `fk1_mod_sources_pages_references` FOREIGN KEY (`page_id`) REFERENCES `mod_sources_pages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;