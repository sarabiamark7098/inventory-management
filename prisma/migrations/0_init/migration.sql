-- CreateTable
CREATE TABLE `categories` (
    `id` CHAR(36) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `custom_fields` JSON NOT NULL,
    `default_loan_days` INTEGER NOT NULL DEFAULT 7,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `deleted_at` DATETIME(3) NULL,

    INDEX `categories_updated_at_idx`(`updated_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `equipment` (
    `id` CHAR(36) NOT NULL,
    `asset_tag` VARCHAR(100) NOT NULL,
    `serial_number` VARCHAR(100) NULL,
    `category_id` CHAR(36) NULL,
    `model_name` VARCHAR(255) NOT NULL,
    `brand` VARCHAR(100) NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'AVAILABLE',
    `condition` VARCHAR(50) NOT NULL DEFAULT 'GOOD',
    `location` VARCHAR(255) NULL,
    `specifications` JSON NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `deleted_at` DATETIME(3) NULL,

    UNIQUE INDEX `equipment_asset_tag_key`(`asset_tag`),
    UNIQUE INDEX `equipment_serial_number_key`(`serial_number`),
    INDEX `equipment_category_id_idx`(`category_id`),
    INDEX `equipment_status_idx`(`status`),
    INDEX `equipment_updated_at_idx`(`updated_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `borrowing_transactions` (
    `id` CHAR(36) NOT NULL,
    `equipment_id` CHAR(36) NULL,
    `borrower_name` VARCHAR(255) NOT NULL,
    `borrower_email` VARCHAR(255) NOT NULL,
    `issue_date` DATETIME(3) NOT NULL,
    `expected_return_date` DATETIME(3) NOT NULL,
    `actual_return_date` DATETIME(3) NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'ISSUED',
    `notes` TEXT NULL,
    `conflict_reason` TEXT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `deleted_at` DATETIME(3) NULL,

    INDEX `borrowing_transactions_equipment_id_idx`(`equipment_id`),
    INDEX `borrowing_transactions_status_idx`(`status`),
    INDEX `borrowing_transactions_updated_at_idx`(`updated_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `sync_changes` (
    `id` CHAR(36) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` CHAR(36) NOT NULL,
    `operation` VARCHAR(20) NOT NULL,
    `data` JSON NOT NULL,
    `client_id` VARCHAR(100) NULL,
    `client_timestamp` DATETIME(3) NOT NULL,
    `synced_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `sync_changes_entity_type_entity_id_idx`(`entity_type`, `entity_id`),
    INDEX `sync_changes_synced_at_idx`(`synced_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- AddForeignKey
ALTER TABLE `equipment` ADD CONSTRAINT `equipment_category_id_fkey` FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `borrowing_transactions` ADD CONSTRAINT `borrowing_transactions_equipment_id_fkey` FOREIGN KEY (`equipment_id`) REFERENCES `equipment`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;
