<?php

namespace Dti;

/**
 * dti_* 테이블 정의. 파이썬 magazine 의 core/db.py 모델을 그대로 옮긴 것이다.
 * topics 의 presenter·planned_date·material_* 는 발표 분리(24ca257) 뒤로 쓰지 않지만,
 * 이관 정확성을 "응답이 같다"로 검증하므로 정리하지 않고 그대로 만든다.
 */
final class Schema
{
    public static function tables(): array
    {
        return [
            'dti_topics' => "
                CREATE TABLE IF NOT EXISTS `dti_topics` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `title` VARCHAR(500) NOT NULL,
                    `field` VARCHAR(150) NOT NULL DEFAULT '',
                    `keywords` VARCHAR(1000) NOT NULL DEFAULT '',
                    `magazine` VARCHAR(50) NOT NULL DEFAULT '',
                    `volume` VARCHAR(50) NOT NULL DEFAULT '',
                    `page` VARCHAR(50) NOT NULL DEFAULT '',
                    `year` INT NULL,
                    `requirement` VARCHAR(20) NOT NULL DEFAULT 'recommended' COMMENT 'required|recommended|normal',
                    `team` VARCHAR(20) NOT NULL DEFAULT '',
                    `presenter` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '발표 분리 전 컬럼. 값만 남아 있다',
                    `presenter_email` VARCHAR(190) NOT NULL DEFAULT '',
                    `planned_date` VARCHAR(10) NOT NULL DEFAULT '',
                    `done_date` VARCHAR(10) NOT NULL DEFAULT '',
                    `note` VARCHAR(2000) NOT NULL DEFAULT '',
                    `active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '구성원 화면 노출',
                    `archived` TINYINT(1) NOT NULL DEFAULT 0,
                    `material_kind` VARCHAR(10) NULL COMMENT 'link|file. NULL 이 없음이다',
                    `material_name` VARCHAR(500) NULL,
                    `material_url` VARCHAR(1000) NULL,
                    `material_path` VARCHAR(255) NULL,
                    `scan_kind` VARCHAR(10) NULL,
                    `scan_name` VARCHAR(500) NULL,
                    `scan_url` VARCHAR(1000) NULL,
                    `scan_path` VARCHAR(255) NULL,
                    `created_by` VARCHAR(190) NOT NULL DEFAULT '',
                    `created_at` VARCHAR(19) NOT NULL DEFAULT '',
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'dti_presentations' => "
                CREATE TABLE IF NOT EXISTS `dti_presentations` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `topic_id` INT UNSIGNED NOT NULL,
                    `presenter` VARCHAR(60) NOT NULL DEFAULT '',
                    `presenter_email` VARCHAR(190) NOT NULL DEFAULT '',
                    `planned_date` VARCHAR(10) NOT NULL DEFAULT '',
                    `done_date` VARCHAR(10) NOT NULL DEFAULT '',
                    `material_kind` VARCHAR(10) NULL,
                    `material_name` VARCHAR(500) NULL,
                    `material_url` VARCHAR(1000) NULL,
                    `material_path` VARCHAR(255) NULL,
                    `created_at` VARCHAR(19) NOT NULL DEFAULT '',
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_topic` (`topic_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'dti_emotions' => "
                CREATE TABLE IF NOT EXISTS `dti_emotions` (
                    `presentation_id` INT UNSIGNED NOT NULL,
                    `email` VARCHAR(190) NOT NULL,
                    `kind` VARCHAR(20) NOT NULL COMMENT 'like|apply|easy|new',
                    `created_at` VARCHAR(19) NOT NULL DEFAULT '',
                    PRIMARY KEY (`presentation_id`, `email`, `kind`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'dti_fields' => "
                CREATE TABLE IF NOT EXISTS `dti_fields` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(150) NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'dti_related' => "
                CREATE TABLE IF NOT EXISTS `dti_related` (
                    `topic_id` INT UNSIGNED NOT NULL,
                    `related_id` INT UNSIGNED NOT NULL,
                    `score` INT NOT NULL,
                    PRIMARY KEY (`topic_id`, `related_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    /** 이미 만들어진 설치본에 컬럼을 붙일 때 여기에 ALTER 문을 더한다 */
    public static function alters(): array
    {
        return [
            "ALTER TABLE `dti_topics` ADD COLUMN `note` VARCHAR(2000) NOT NULL DEFAULT '' AFTER `done_date`",
            "ALTER TABLE `dti_topics` ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `note`",
            "ALTER TABLE `dti_topics` ADD COLUMN `archived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `active`",
        ];
    }
}
