-- 旧 DB の構造(DDL のみ)。2026-10-25 に本番の旧 DB から書き出した(#27)。
-- データ・DB 名・AUTO_INCREMENT の値は含めない。旧 DB には外部キーが無い。

CREATE TABLE `affiliates` (
  `category_id` int(11) NOT NULL,
  `middle_id` int(11) NOT NULL,
  `brand_id` int(11) NOT NULL,
  `name` varchar(256) NOT NULL,
  `title` text DEFAULT NULL,
  `price` int(11) DEFAULT NULL,
  `img_large_url` text DEFAULT NULL,
  `img_medium_url` text DEFAULT NULL,
  `img_small_url` text DEFAULT NULL,
  `human_image_url` text DEFAULT NULL,
  `amazon_detail_url` text DEFAULT NULL,
  `amazon_search_url` text DEFAULT NULL,
  `human_amazon_detail_url` text DEFAULT NULL,
  `human_amazon_search_url` text DEFAULT NULL,
  `human_price` int(11) DEFAULT NULL,
  `human_title` varchar(256) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `rakuten_title` text DEFAULT NULL,
  `rakuten_price` int(11) DEFAULT NULL,
  `rakuten_detail_url` text DEFAULT NULL,
  `rakuten_search_url` text DEFAULT NULL,
  `rakuten_image_url` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `m_auto_input` (
  `header` varchar(255) NOT NULL,
  `number` int(11) NOT NULL,
  `attention` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

CREATE TABLE `m_brand` (
  `id` int(11) NOT NULL,
  `category` int(11) NOT NULL,
  `middle_category` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `name_search` varchar(255) NOT NULL,
  `name_eng` varchar(255) NOT NULL,
  `volume` float NOT NULL,
  `description` text DEFAULT NULL,
  `image` text DEFAULT NULL,
  `instagram` varchar(255) DEFAULT NULL,
  `youtube` varchar(255) DEFAULT NULL,
  `youtube_created_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `image_txt` text DEFAULT NULL,
  `image_url` text DEFAULT NULL,
  `is_major` tinyint(1) NOT NULL DEFAULT 0,
  `is_like` tinyint(1) NOT NULL DEFAULT 0,
  `y_hour` int(11) DEFAULT NULL,
  `y_minute` int(11) DEFAULT NULL,
  `y_second` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

CREATE TABLE `m_category` (
  `id` int(11) NOT NULL,
  `kind` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `name_search` varchar(255) NOT NULL,
  `is_major` tinyint(1) NOT NULL DEFAULT 0,
  `name_eng` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `famous_brand_id` int(11) DEFAULT NULL,
  `is_powder` tinyint(1) NOT NULL DEFAULT 0,
  `youtube` varchar(255) DEFAULT NULL,
  `youtube_created_at` datetime DEFAULT NULL,
  `y_hour` int(11) DEFAULT NULL,
  `y_minute` int(11) DEFAULT NULL,
  `y_second` int(11) DEFAULT NULL,
  `is_like` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `image` varchar(255) DEFAULT NULL,
  `image_txt` varchar(255) DEFAULT NULL,
  `image_url` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

CREATE TABLE `m_category_kinds` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `name_eng` varchar(255) NOT NULL,
  `description` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

CREATE TABLE `m_dictionary_category` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `name_eng` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

CREATE TABLE `m_ice` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `name_eng` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

CREATE TABLE `m_method` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `name_eng` varchar(255) NOT NULL,
  `description` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

CREATE TABLE `m_middle` (
  `id` int(11) NOT NULL,
  `parent` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `name_search` varchar(255) NOT NULL,
  `name_eng` varchar(255) NOT NULL,
  `is_major` tinyint(1) NOT NULL DEFAULT 0,
  `description` text NOT NULL,
  `famous_brand_id` int(11) DEFAULT NULL,
  `is_powder` tinyint(1) NOT NULL DEFAULT 0,
  `youtube` varchar(255) DEFAULT NULL,
  `youtube_created_at` datetime DEFAULT NULL,
  `is_like` tinyint(1) NOT NULL,
  `y_hour` int(11) DEFAULT NULL,
  `y_minute` int(11) DEFAULT NULL,
  `y_second` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `image` varchar(255) DEFAULT NULL,
  `image_txt` varchar(255) DEFAULT NULL,
  `image_url` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

CREATE TABLE `paraphrases` (
  `name` varchar(255) NOT NULL,
  `converted` varchar(255) NOT NULL,
  `middle` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `command` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

CREATE TABLE `skip_db` (
  `name` varchar(256) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

CREATE TABLE `t_cocktails` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `name_search` varchar(255) NOT NULL,
  `name_eng` varchar(255) DEFAULT NULL,
  `base` int(11) NOT NULL,
  `instagram` varchar(255) DEFAULT NULL,
  `youtube` varchar(255) DEFAULT NULL,
  `volume` float NOT NULL,
  `sweet_degree` int(11) DEFAULT NULL,
  `rate` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `method` int(11) NOT NULL,
  `glass` int(11) DEFAULT NULL,
  `ice` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `image` text DEFAULT NULL,
  `is_draft` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `image_txt` text DEFAULT NULL,
  `image_url` text DEFAULT NULL,
  `is_major` tinyint(1) NOT NULL DEFAULT 0,
  `cc_youtube_created_at` datetime DEFAULT NULL,
  `recipe_count` int(11) NOT NULL,
  `y_hour` int(11) DEFAULT NULL,
  `y_minute` int(11) DEFAULT NULL,
  `y_second` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

CREATE TABLE `t_dictionary` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `name_eng` varchar(255) NOT NULL,
  `name_search` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `category` int(11) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `image_txt` varchar(255) DEFAULT NULL,
  `image_url` text DEFAULT NULL,
  `y_hour` int(11) DEFAULT NULL,
  `y_minute` int(11) DEFAULT NULL,
  `y_second` int(11) DEFAULT NULL,
  `youtube` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

CREATE TABLE `t_recipies` (
  `cocktail_id` int(11) NOT NULL,
  `category` int(11) NOT NULL,
  `middle_category` int(11) DEFAULT NULL,
  `brand` int(11) DEFAULT NULL,
  `amount` varchar(20) NOT NULL,
  `is_optional` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `twitter_id` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `affiliates`
  ADD PRIMARY KEY (`category_id`,`middle_id`,`brand_id`);

ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `m_brand`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category` (`category`),
  ADD KEY `middle_category` (`middle_category`),
  ADD KEY `name_search` (`name_search`),
  ADD KEY `name` (`name`),
  ADD KEY `name_eng` (`name_eng`),
  ADD KEY `youtube_created_at` (`youtube_created_at`),
  ADD KEY `is_major` (`is_major`),
  ADD KEY `is_like` (`is_like`);

ALTER TABLE `m_category`
  ADD PRIMARY KEY (`id`),
  ADD KEY `kind` (`kind`),
  ADD KEY `name_search` (`name_search`),
  ADD KEY `name_eng` (`name_eng`),
  ADD KEY `is_major` (`is_major`),
  ADD KEY `youtube_created_at` (`youtube_created_at`),
  ADD KEY `is_like` (`is_like`);

ALTER TABLE `m_category_kinds`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `m_dictionary_category`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `m_ice`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `m_method`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `m_middle`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parent` (`parent`),
  ADD KEY `name_search` (`name_search`),
  ADD KEY `name_eng` (`name_eng`),
  ADD KEY `is_major` (`is_major`),
  ADD KEY `youtube_created_at` (`youtube_created_at`),
  ADD KEY `is_like` (`is_like`);

ALTER TABLE `paraphrases`
  ADD UNIQUE KEY `name` (`name`);

ALTER TABLE `skip_db`
  ADD UNIQUE KEY `name` (`name`);

ALTER TABLE `t_cocktails`
  ADD PRIMARY KEY (`id`),
  ADD KEY `name` (`name`),
  ADD KEY `name_eng` (`name_eng`),
  ADD KEY `base` (`base`),
  ADD KEY `is_draft` (`is_draft`),
  ADD KEY `name_search` (`name_search`),
  ADD KEY `rate` (`rate`),
  ADD KEY `method` (`method`),
  ADD KEY `glass` (`glass`),
  ADD KEY `is_major` (`is_major`),
  ADD KEY `cc_youtube_created_at` (`cc_youtube_created_at`),
  ADD KEY `recipe_count` (`recipe_count`);

ALTER TABLE `t_dictionary`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `t_recipies`
  ADD KEY `cocktail_id` (`cocktail_id`),
  ADD KEY `category` (`category`),
  ADD KEY `middle_category` (`middle_category`),
  ADD KEY `brand` (`brand`),
  ADD KEY `is_optional` (`is_optional`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `m_brand`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `m_category`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `m_category_kinds`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `m_dictionary_category`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `m_ice`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `m_method`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `m_middle`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `t_cocktails`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `t_dictionary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
