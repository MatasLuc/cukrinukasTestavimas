<?php
// setup.php - atsakingas tik už DB struktūros sukūrimą ir pradinių duomenų įrašymą.

function ensureUsersTable(PDO $pdo): void {
    // Kuriame lentelę, jei jos nėra
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            is_admin TINYINT(1) NOT NULL DEFAULT 0,
            remember_token VARCHAR(255) DEFAULT NULL,
            profile_photo VARCHAR(255) DEFAULT NULL,
            birthdate DATE DEFAULT NULL,
            gender VARCHAR(20) DEFAULT NULL,
            city VARCHAR(120) DEFAULT NULL,
            country VARCHAR(120) DEFAULT NULL,
            google_id VARCHAR(50) DEFAULT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    // Patikriname esamus stulpelius ir pridedame trūkstamus
    $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    
    $addIfMissing = [
        'is_admin' => "ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash",
        'remember_token' => "ALTER TABLE users ADD COLUMN remember_token VARCHAR(255) DEFAULT NULL AFTER is_admin",
        'profile_photo' => "ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL AFTER remember_token",
        'birthdate' => "ALTER TABLE users ADD COLUMN birthdate DATE DEFAULT NULL AFTER profile_photo",
        'gender' => "ALTER TABLE users ADD COLUMN gender VARCHAR(20) DEFAULT NULL AFTER birthdate",
        'city' => "ALTER TABLE users ADD COLUMN city VARCHAR(120) DEFAULT NULL AFTER gender",
        'country' => "ALTER TABLE users ADD COLUMN country VARCHAR(120) DEFAULT NULL AFTER city",
        // Naujas stulpelis Google ID saugojimui
        'google_id' => "ALTER TABLE users ADD COLUMN google_id VARCHAR(50) DEFAULT NULL UNIQUE AFTER country",
    ];

    foreach ($addIfMissing as $field => $sql) {
        if (!in_array($field, $columns, true)) {
            $pdo->exec($sql);
        }
    }
}

function ensureAdminAccount(PDO $pdo): void {
    ensureUsersTable($pdo);
    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
    if ($adminCount === 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, is_admin) VALUES (?, ?, ?, 1)');
        $stmt->execute(['Administratorius', 'admin@e-kolekcija.lt', $hash]);
    }
}

function ensureSystemUser(PDO $pdo): int {
    ensureUsersTable($pdo);

    $email = 'noreply@cukrinukas.lt';
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int)$existing;
    }

    $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash, is_admin) VALUES (?, ?, ?, 0)');
    $insert->execute(['Cukrinukas.lt', $email, $hash]);

    return (int)$pdo->lastInsertId();
}

function ensureNewsCategoriesTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS news_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(120) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureNewsCategoryRelationsTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS news_category_relations (
            news_id INT NOT NULL,
            category_id INT NOT NULL,
            PRIMARY KEY (news_id, category_id),
            FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES news_categories(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureNewsTable(PDO $pdo): void {
    ensureNewsCategoriesTable($pdo);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS news (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            summary TEXT NULL,
            author VARCHAR(100) DEFAULT NULL,
            image_url VARCHAR(500) NOT NULL,
            body TEXT NOT NULL,
            visibility ENUM("public","members") NOT NULL DEFAULT "public",
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    ensureNewsCategoryRelationsTable($pdo);

    $columns = $pdo->query("SHOW COLUMNS FROM news")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('summary', $columns, true)) {
        $pdo->exec('ALTER TABLE news ADD COLUMN summary TEXT NULL AFTER title');
    }
    if (!in_array('visibility', $columns, true)) {
        $pdo->exec('ALTER TABLE news ADD COLUMN visibility ENUM("public","members") NOT NULL DEFAULT "public" AFTER body');
    }
    if (!in_array('author', $columns, true)) {
        $pdo->exec('ALTER TABLE news ADD COLUMN author VARCHAR(100) DEFAULT NULL AFTER summary');
    }
    if (!in_array('category_id', $columns, true)) {
        $pdo->exec('ALTER TABLE news ADD COLUMN category_id INT NULL AFTER id');
    }
}

function ensureRecipeCategoriesTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS recipe_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(120) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureRecipeCategoryRelationsTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS recipe_category_relations (
            recipe_id INT NOT NULL,
            category_id INT NOT NULL,
            PRIMARY KEY (recipe_id, category_id),
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES recipe_categories(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureRecipesTable(PDO $pdo): void {
    ensureRecipeCategoriesTable($pdo);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS recipes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            author VARCHAR(255) NULL,
            summary TEXT NULL,
            image_url VARCHAR(500) NOT NULL,
            body TEXT NOT NULL,
            visibility ENUM("public","members") NOT NULL DEFAULT "public",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    
    ensureRecipeCategoryRelationsTable($pdo);

    $columns = $pdo->query("SHOW COLUMNS FROM recipes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('summary', $columns, true)) {
        $pdo->exec('ALTER TABLE recipes ADD COLUMN summary TEXT NULL AFTER title');
    }
    if (!in_array('author', $columns, true)) {
        $pdo->exec('ALTER TABLE recipes ADD COLUMN author VARCHAR(255) NULL AFTER title');
    }
    if (!in_array('visibility', $columns, true)) {
        $pdo->exec('ALTER TABLE recipes ADD COLUMN visibility ENUM("public","members") NOT NULL DEFAULT "public" AFTER body');
    }
}

function ensureCommunityTables(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS community_thread_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS community_listing_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS community_threads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            category_id INT NULL,
            title VARCHAR(200) NOT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES community_thread_categories(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS community_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            thread_id INT NOT NULL,
            user_id INT NOT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (thread_id) REFERENCES community_threads(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS community_blocks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            banned_until DATETIME NULL,
            reason VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS community_listings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            category_id INT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            status ENUM("active","sold") NOT NULL DEFAULT "active",
            seller_email VARCHAR(190) DEFAULT NULL,
            seller_phone VARCHAR(60) DEFAULT NULL,
            image_url VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES community_listing_categories(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $columns = $pdo->query('SHOW COLUMNS FROM community_listings')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('seller_email', $columns, true)) {
        $pdo->exec('ALTER TABLE community_listings ADD COLUMN seller_email VARCHAR(190) DEFAULT NULL AFTER status');
    }
    if (!in_array('seller_phone', $columns, true)) {
        $pdo->exec('ALTER TABLE community_listings ADD COLUMN seller_phone VARCHAR(60) DEFAULT NULL AFTER seller_email');
    }
    if (!in_array('category_id', $columns, true)) {
        $pdo->exec('ALTER TABLE community_listings ADD COLUMN category_id INT NULL AFTER user_id');
        $pdo->exec('ALTER TABLE community_listings ADD CONSTRAINT fk_listing_category FOREIGN KEY (category_id) REFERENCES community_listing_categories(id) ON DELETE SET NULL');
    }

    $threadColumns = $pdo->query('SHOW COLUMNS FROM community_threads')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('category_id', $threadColumns, true)) {
        $pdo->exec('ALTER TABLE community_threads ADD COLUMN category_id INT NULL AFTER user_id');
        $pdo->exec('ALTER TABLE community_threads ADD CONSTRAINT fk_thread_category FOREIGN KEY (category_id) REFERENCES community_thread_categories(id) ON DELETE SET NULL');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS community_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            listing_id INT NOT NULL,
            buyer_id INT NOT NULL,
            status ENUM("laukiama","patvirtinta","atšaukta","įvykdyta") NOT NULL DEFAULT "laukiama",
            note TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (listing_id) REFERENCES community_listings(id) ON DELETE CASCADE,
            FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS community_order_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            user_id INT NOT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES community_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureDirectMessages(PDO $pdo): void {
    ensureUsersTable($pdo);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS direct_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            recipient_id INT NOT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function seedRecipeExamples(PDO $pdo): void {
    $count = (int) $pdo->query('SELECT COUNT(*) FROM recipes')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $recipes = [
        [
            'title' => 'Mažo GI avižų dubenėlis su uogomis',
            'image_url' => 'https://images.unsplash.com/photo-1490645935967-10de6ba17061?auto=format&fit=crop&w=1200&q=80',
            'body' => '<p>Trumpas pusryčių receptas: avižos, graikiškas jogurtas, mėlynės ir šaukštelis linų sėmenų. Balansas tarp skaidulų ir baltymų.</p>',
        ],
        [
            'title' => 'Traškios daržovių lazdelės su humusu',
            'image_url' => 'https://images.unsplash.com/photo-1522184216315-dc2a82a2f3f8?auto=format&fit=crop&w=1200&q=80',
            'body' => '<p>Morkos, agurkai ir salierai patiekiami su baltymingu humusu – puikus užkandis tarp matavimų.</p>',
        ],
        [
            'title' => 'Kepta lašiša su cukinijų juostelėmis',
            'image_url' => 'https://images.unsplash.com/photo-1604908177075-0ac1c9bb6466?auto=format&fit=crop&w=1200&q=80',
            'body' => '<p>Lašišą kepkite orkaitėje su citrina ir žolelėmis, patiekite su lengvai troškintomis cukinijomis.</p>',
        ],
    ];

    $stmt = $pdo->prepare('INSERT INTO recipes (title, image_url, body) VALUES (?, ?, ?)');
    foreach ($recipes as $recipe) {
        $stmt->execute([$recipe['title'], $recipe['image_url'], $recipe['body']]);
    }
}

function ensureCategoriesTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            slug VARCHAR(180) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureProductImagesTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS product_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            path VARCHAR(255) NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureProductsTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NULL,
            title VARCHAR(200) NOT NULL,
            subtitle VARCHAR(200) DEFAULT NULL,
            description TEXT NOT NULL,
            ribbon_text VARCHAR(120) DEFAULT NULL,
            image_url VARCHAR(500) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            sale_price DECIMAL(10,2) DEFAULT NULL,
            quantity INT NOT NULL DEFAULT 0,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            meta_tags TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    ensureProductImagesTable($pdo);
    ensureProductRelations($pdo);

    $columns = $pdo->query("SHOW COLUMNS FROM products")->fetchAll();
    $names = array_column($columns, 'Field');
    if (!in_array('subtitle', $names, true)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN subtitle VARCHAR(200) DEFAULT NULL AFTER title");
    }
    if (!in_array('ribbon_text', $names, true)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN ribbon_text VARCHAR(120) DEFAULT NULL AFTER description");
    }
    if (!in_array('sale_price', $names, true)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN sale_price DECIMAL(10,2) DEFAULT NULL AFTER price");
    }
    if (!in_array('meta_tags', $names, true)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN meta_tags TEXT NULL AFTER is_featured");
    }
}

function ensureFooterLinksTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS footer_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(180) NOT NULL,
            url VARCHAR(500) NOT NULL,
            section ENUM("quick","help") NOT NULL DEFAULT "quick",
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $count = (int)$pdo->query('SELECT COUNT(*) FROM footer_links')->fetchColumn();
    if ($count === 0) {
        $seeds = [
            ['Apie mus', '/about.php', 'quick', 1],
            ['Parduotuvė', '/products.php', 'quick', 2],
            ['Naujienos', '/news.php', 'quick', 3],
            ['DUK', '/faq.php', 'help', 1],
            ['Pristatymas', '/shipping.php', 'help', 2],
            ['Grąžinimas', '/returns.php', 'help', 3],
        ];
        $stmt = $pdo->prepare('INSERT INTO footer_links (label, url, section, sort_order) VALUES (?, ?, ?, ?)');
        foreach ($seeds as $row) {
            $stmt->execute($row);
        }
    }
}

function ensureFooterLinks(PDO $pdo): void {
    ensureFooterLinksTable($pdo);
}

function ensureSiteContentTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS site_content (
            `key` VARCHAR(120) PRIMARY KEY,
            `value` TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $defaults = [
        'hero_title' => 'Pagalba kasdienei diabeto priežiūrai',
        'hero_body' => 'Gliukometrai, sensoriai, maži GI užkandžiai ir bendruomenės patarimai – viskas vienoje vietoje, kad matavimai būtų ramūs.',
        'hero_cta_label' => 'Peržiūrėti pasiūlymus →',
        'hero_cta_url' => '/products.php',
        'hero_media_type' => 'image',
        'hero_media_color' => '#829ed6',
        'hero_media_image' => 'https://images.pexels.com/photos/6942003/pexels-photo-6942003.jpeg',
        'hero_media_video' => '',
        'hero_media_poster' => '',
        'hero_media_alt' => 'Cukrinukas fonas',
        'hero_shadow_intensity' => '70',
        'news_hero_pill' => '📰 Bendruomenės pulsas',
        'news_hero_title' => 'Šviežiausios naujienos ir patarimai',
        'news_hero_body' => 'Aktualijos apie diabetą, kasdienę priežiūrą ir mūsų parduotuvės atnaujinimus – viskas vienoje vietoje.',
        'news_hero_cta_label' => 'Peržiūrėti straipsnius',
        'news_hero_cta_url' => '#news',
        'news_hero_card_meta' => 'Temos žyma',
        'news_hero_card_title' => 'Inovatyvi priežiūra',
        'news_hero_card_body' => 'Atrinkti patarimai ir sėkmės istorijos',
        'recipes_hero_pill' => '🍽️ Subalansuotos idėjos kasdienai',
        'recipes_hero_title' => 'Šiuolaikiški receptai, kurie įkvepia',
        'recipes_hero_body' => 'Lengvai paruošiami patiekalai, praturtinti patarimais ir mitybos įkvėpimu kiekvienai dienai.',
        'recipes_hero_cta_label' => 'Naršyti receptus',
        'recipes_hero_cta_url' => '#recipes',
        'recipes_hero_card_meta' => 'Šio mėnesio skonis',
        'recipes_hero_card_title' => 'Mėtos ir pistacijos',
        'recipes_hero_card_body' => 'Gaivus duetas desertams ir užkandžiams',
        'faq_hero_pill' => '💡 Pagalba ir gairės',
        'faq_hero_title' => 'Dažniausiai užduodami klausimai',
        'faq_hero_body' => 'Trumpi atsakymai apie pristatymą, grąžinimus ir kaip išsirinkti tinkamus produktus diabetui prižiūrėti.',
        'contact_hero_pill' => '🤝 Esame šalia',
        'contact_hero_title' => 'Susisiekime ir aptarkime, kaip galime padėti',
        'contact_hero_body' => 'Greiti atsakymai, nuoširdūs patarimai ir pagalba parenkant reikiamus produktus – parašykite mums.',
        'contact_cta_primary_label' => 'Rašyti el. laišką',
        'contact_cta_primary_url' => 'mailto:e.kolekcija@gmail.com',
        'contact_cta_secondary_label' => 'Skambinti +37060093880',
        'contact_cta_secondary_url' => 'tel:+37060093880',
        'contact_card_pill' => 'Greita reakcija',
        'contact_card_title' => 'Iki 1 darbo dienos',
        'contact_card_body' => 'Į užklausas atsakome kuo greičiau, kad galėtumėte pasirūpinti savo poreikiais.',
        'banner_enabled' => '0',
        'banner_text' => '',
        'banner_link' => '',
        'banner_background' => '#829ed6',
        'promo_1_icon' => '1%',
        'promo_1_title' => 'Žemas GI prioritetas',
        'promo_1_body' => 'Visi užkandžiai atrinkti taip, kad padėtų išlaikyti stabilesnį gliukozės lygį.',
        'promo_2_icon' => '24/7',
        'promo_2_title' => 'Greita pagalba',
        'promo_2_body' => 'Klauskite apie sensorius ar pompų priedus – atsakome ir telefonu, ir el. paštu.',
        'promo_3_icon' => '★',
        'promo_3_title' => 'Bendruomenės patirtys',
        'promo_3_body' => 'Dalijamės realių vartotojų patarimais apie matavimus, sportą ir mitybą.',
        'storyband_badge' => 'Nuo gliukometro iki lėkštės',
        'storyband_title' => 'Kasdieniai sprendimai diabetui',
        'storyband_body' => 'Sudėjome priemones ir žinias, kurios palengvina cukrinio diabeto priežiūrą: nuo matavimų iki receptų ir užkandžių.',
        'storyband_cta_label' => 'Rinktis rinkinį',
        'storyband_cta_url' => '/products.php',
        'storyband_card_eyebrow' => 'Reklaminis akcentas',
        'storyband_card_title' => '„Cukrinukas“ rinkiniai',
        'storyband_card_body' => 'Starteriai su gliukometrais, užkandžiais ir atsargomis 30 dienų. Pradėkite be streso.',
        'storyband_metric_1_value' => '1200+',
        'storyband_metric_1_label' => 'užsakymų per metus',
        'storyband_metric_2_value' => '25',
        'storyband_metric_2_label' => 'receptai su subalansuotu GI',
        'storyband_metric_3_value' => '5 min',
        'storyband_metric_3_label' => 'vidutinis atsakymo laikas',
        'storyrow_eyebrow' => 'Dienos rutina',
        'storyrow_title' => 'Stebėjimas, užkandžiai ir ramybė',
        'storyrow_body' => 'Greitai pasiekiami sensorių pleistrai, cukraus kiekį subalansuojantys batonėliai ir starterių rinkiniai, kad kiekviena diena būtų šiek tiek lengvesnė.',
        'storyrow_pill_1' => 'Gliukozės matavimai',
        'storyrow_pill_2' => 'Subalansuotos užkandžių dėžutės',
        'storyrow_pill_3' => 'Kelionėms paruošti rinkiniai',
        'storyrow_bubble_meta' => 'Rekomendacija',
        'storyrow_bubble_title' => '„Cukrinukas“ specialistai',
        'storyrow_bubble_body' => 'Suderiname atsargas pagal jūsų dienos režimą: nuo ankstyvų matavimų iki vakaro koregavimų.',
        'storyrow_floating_meta' => 'Greitas pristatymas',
        'storyrow_floating_title' => '1-2 d.d.',
        'storyrow_floating_body' => 'Visoje Lietuvoje nuo 2.50 €',
        'support_meta' => 'Bendruomenė',
        'support_title' => 'Pagalba jums ir šeimai',
        'support_body' => 'Nuo pirmo sensoriaus iki subalansuotos vakarienės – čia rasite trumpus gidus, vaizdo pamokas ir dietologės patarimus.',
        'support_chip_1' => 'Vaizdo gidai',
        'support_chip_2' => 'Dietologės Q&A',
        'support_chip_3' => 'Tėvų kampelis',
        'support_card_meta' => 'Gyva konsultacija',
        'support_card_title' => '5 d. per savaitę',
        'support_card_body' => 'Trumpi pokalbiai su cukrinio diabeto slaugytoja per „Messenger“ – pasikalbam apie sensorius, vaikus ar receptų koregavimus.',
        'support_card_cta_label' => 'Rezervuoti laiką',
        'support_card_cta_url' => '/contact.php',
        'footer_brand_title' => 'Cukrinukas.lt',
        'footer_brand_body' => 'Diabeto priemonės, mažo GI užkandžiai ir kasdienių sprendimų gidai vienoje vietoje.',
        'footer_brand_pill' => 'Kasdienė priežiūra',
        'footer_quick_title' => 'Greitos nuorodos',
        'footer_help_title' => 'Pagalba',
        'footer_contact_title' => 'Kontaktai',
        'footer_contact_email' => 'info@cukrinukas.lt',
        'footer_contact_phone' => '+370 600 00000',
        'footer_contact_hours' => 'I–V 09:00–18:00',
        'testimonial_1_name' => 'Gintarė, 1 tipo diabetas',
        'testimonial_1_role' => 'Mėgsta aktyvią dieną',
        'testimonial_1_text' => 'Sensoriai, užkandžiai ir patarimai vienoje vietoje sutaupo daug laiko.',
        'testimonial_2_name' => 'Mantas, tėtis',
        'testimonial_2_role' => 'Prižiūri sūnaus matavimus',
        'testimonial_2_text' => 'Greitas pristatymas ir aiškūs gidai padeda jaustis užtikrintai.',
        'testimonial_3_name' => 'Rūta, dietologė',
        'testimonial_3_role' => 'Dalijasi mitybos idėjomis',
        'testimonial_3_text' => 'Receptų santraukos ir mažo GI produktai yra tai, ko reikia kasdienai.',
    ];

    $stmt = $pdo->prepare('INSERT IGNORE INTO site_content (`key`, `value`) VALUES (?, ?)');
    foreach ($defaults as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

function ensureShippingSettings(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS shipping_settings (
            id INT PRIMARY KEY,
            base_price DECIMAL(10,2) NOT NULL DEFAULT 3.99,
            courier_price DECIMAL(10,2) NOT NULL DEFAULT 3.99,
            locker_price DECIMAL(10,2) NOT NULL DEFAULT 2.49,
            free_over DECIMAL(10,2) DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $columns = $pdo->query('SHOW COLUMNS FROM shipping_settings')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('courier_price', $columns, true)) {
        $pdo->exec('ALTER TABLE shipping_settings ADD COLUMN courier_price DECIMAL(10,2) NOT NULL DEFAULT 3.99 AFTER base_price');
        $pdo->exec('UPDATE shipping_settings SET courier_price = base_price');
    }
    if (!in_array('locker_price', $columns, true)) {
        $pdo->exec('ALTER TABLE shipping_settings ADD COLUMN locker_price DECIMAL(10,2) NOT NULL DEFAULT 2.49 AFTER courier_price');
        $pdo->exec('UPDATE shipping_settings SET locker_price = base_price');
    }

    $exists = $pdo->query('SELECT COUNT(*) FROM shipping_settings WHERE id = 1')->fetchColumn();
    if (!$exists) {
        $pdo->prepare('INSERT INTO shipping_settings (id, base_price, courier_price, locker_price, free_over) VALUES (1, 3.99, 3.99, 2.49, NULL)')->execute();
    }
}

function ensureLockerTables(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS parcel_lockers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            address VARCHAR(255) NOT NULL,
            note TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY provider_title_address (provider, title, address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureFreeShippingProducts(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS shipping_free_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL UNIQUE,
            position TINYINT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureProductRelations(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS product_related (
            product_id INT NOT NULL,
            related_product_id INT NOT NULL,
            PRIMARY KEY (product_id, related_product_id),
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (related_product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS product_attributes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            label VARCHAR(180) NOT NULL,
            value TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS product_variations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            group_name VARCHAR(255) NULL DEFAULT "",
            name VARCHAR(180) NOT NULL,
            price_delta DECIMAL(10,2) NOT NULL DEFAULT 0,
            quantity INT NOT NULL DEFAULT 0,
            image_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    
    // Add track_price and track_stock if missing
    $cols = $pdo->query("SHOW COLUMNS FROM product_variations")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('track_price', $cols, true)) {
        $pdo->exec("ALTER TABLE product_variations ADD COLUMN track_price TINYINT(1) NOT NULL DEFAULT 0 AFTER price_delta");
    }
    if (!in_array('track_stock', $cols, true)) {
        $pdo->exec("ALTER TABLE product_variations ADD COLUMN track_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER quantity");
    }

    $columns = $pdo->query("SHOW COLUMNS FROM product_variations")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('group_name', $columns, true)) {
        $pdo->exec("ALTER TABLE product_variations ADD COLUMN group_name VARCHAR(255) NULL DEFAULT '' AFTER product_id");
    }
    if (!in_array('quantity', $columns, true)) {
        $pdo->exec("ALTER TABLE product_variations ADD COLUMN quantity INT NOT NULL DEFAULT 0 AFTER price_delta");
    }
    if (!in_array('image_id', $columns, true)) {
        $pdo->exec("ALTER TABLE product_variations ADD COLUMN image_id INT DEFAULT NULL AFTER quantity");
    }
}

function ensureFeaturedProductsTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS featured_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL UNIQUE,
            position TINYINT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $count = (int)$pdo->query('SELECT COUNT(*) FROM featured_products')->fetchColumn();
    if ($count === 0) {
        $seeds = $pdo->query('SELECT id FROM products ORDER BY is_featured DESC, created_at DESC LIMIT 3')->fetchAll();
        $stmt = $pdo->prepare('INSERT INTO featured_products (product_id, position) VALUES (?, ?)');
        $pos = 1;
        foreach ($seeds as $seed) {
            $stmt->execute([(int)$seed['id'], $pos]);
            $pos++;
        }
    }
}

function ensureWishlistTables(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS wishlist_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            product_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_product (user_id, product_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureSavedContentTables(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS saved_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            item_type ENUM("product","news","recipe") NOT NULL,
            item_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_saved (user_id, item_type, item_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureNavigationTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS navigation_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(120) NOT NULL,
            url VARCHAR(255) NOT NULL,
            parent_id INT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (parent_id) REFERENCES navigation_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $count = (int) $pdo->query('SELECT COUNT(*) FROM navigation_items')->fetchColumn();
    if ($count === 0) {
        $items = [
            ['Parduotuvė', '/products.php', null, 1],
            ['Naujienos', '/news.php', null, 2],
            ['Receptai', '/recipes.php', null, 3],
            ['Bendruomenė', '/community.php', null, 4],
            ['Kontaktai', '/contact.php', null, 5],
            ['DUK', '/faq.php', null, 6],
        ];
        $stmt = $pdo->prepare('INSERT INTO navigation_items (label, url, parent_id, sort_order) VALUES (?, ?, ?, ?)');
        foreach ($items as $item) {
            $stmt->execute($item);
        }
    } else {
        $upserts = [
            ['Bendruomenė', '/community.php', 4],
            ['Kontaktai', '/contact.php', 5],
            ['DUK', '/faq.php', 6],
        ];
        foreach ($upserts as $row) {
            [$label, $url, $sort] = $row;
            $check = $pdo->prepare('SELECT id FROM navigation_items WHERE label = ? LIMIT 1');
            $check->execute([$label]);
            $existingId = $check->fetchColumn();
            if ($existingId) {
                $pdo->prepare('UPDATE navigation_items SET url = ?, sort_order = ? WHERE id = ?')->execute([$url, $sort, $existingId]);
            } else {
                $pdo->prepare('INSERT INTO navigation_items (label, url, parent_id, sort_order) VALUES (?, ?, NULL, ?)')->execute([$label, $url, $sort]);
            }
        }
    }
}

function seedStoreExamples(PDO $pdo): void {
    ensureCategoriesTable($pdo);
    ensureProductsTable($pdo);

    $categoryCount = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($categoryCount === 0) {
        $categories = [
            ['name' => 'Gliukometrai', 'slug' => 'gliukometrai'],
            ['name' => 'Juostelės ir lancetai', 'slug' => 'juosteles-lancetai'],
            ['name' => 'Sensoriai', 'slug' => 'sensoriai'],
            ['name' => 'Mitybos produktai', 'slug' => 'mitybos-produktai'],
        ];

        $stmt = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)');
        foreach ($categories as $category) {
            $stmt->execute([$category['name'], $category['slug']]);
        }
    }

    $productCount = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ($productCount === 0) {
        $glucometersId = (int) $pdo->query("SELECT id FROM categories WHERE slug = 'gliukometrai'")->fetchColumn();
        $stripsId = (int) $pdo->query("SELECT id FROM categories WHERE slug = 'juosteles-lancetai'")->fetchColumn();
        $sensorsId = (int) $pdo->query("SELECT id FROM categories WHERE slug = 'sensoriai'")->fetchColumn();
        $foodId = (int) $pdo->query("SELECT id FROM categories WHERE slug = 'mitybos-produktai'")->fetchColumn();

        $products = [
            [
                'category_id' => $glucometersId,
                'title' => 'SmartSense gliukometras',
                'description' => 'Bluetooth gliukometras su mobilia programėle ir automatinėmis ataskaitomis.',
                'image_url' => 'https://images.unsplash.com/photo-1582719478190-9f0e2c09c6ee?auto=format&fit=crop&w=800&q=80',
                'price' => 79.99,
                'quantity' => 20,
                'is_featured' => 1,
            ],
            [
                'category_id' => $stripsId,
                'title' => 'Testo juostelės (50 vnt.)',
                'description' => 'Greitos ir tikslios juostelės gliukometro matavimams namuose.',
                'image_url' => 'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=800&q=80',
                'price' => 24.50,
                'quantity' => 100,
                'is_featured' => 1,
            ],
            [
                'category_id' => $sensorsId,
                'title' => 'CGM sensorius (14 d.)',
                'description' => 'Nuolatinis gliukozės stebėjimo sensorius su programėlės pranešimais.',
                'image_url' => 'https://images.unsplash.com/photo-1582719478250-5c7ff88f2375?auto=format&fit=crop&w=800&q=80',
                'price' => 59.00,
                'quantity' => 40,
                'is_featured' => 1,
            ],
            [
                'category_id' => $foodId,
                'title' => 'Mažo GI baltymų batonėliai (12 vnt.)',
                'description' => 'Sotūs batonėliai su mažesniu cukraus kiekiu ir subalansuotu baltymų kiekiu.',
                'image_url' => 'https://images.unsplash.com/photo-1528715471579-d1bcf0ba5e83?auto=format&fit=crop&w=800&q=80',
                'price' => 18.99,
                'quantity' => 60,
                'is_featured' => 0,
            ],
        ];

        $stmt = $pdo->prepare('INSERT INTO products (category_id, title, description, image_url, price, quantity, is_featured) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($products as $product) {
            $stmt->execute([
                $product['category_id'],
                $product['title'],
                $product['description'],
                $product['image_url'],
                $product['price'],
                $product['quantity'],
                $product['is_featured'],
            ]);
        }
    }
}

function ensureOrdersTables(PDO $pdo): void {
    ensureUsersTable($pdo);
    ensureProductsTable($pdo);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            customer_name VARCHAR(200) NOT NULL,
            customer_email VARCHAR(200) NOT NULL,
            customer_phone VARCHAR(50) NOT NULL DEFAULT "",
            customer_address TEXT NOT NULL,
            discount_code VARCHAR(80) NULL,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            shipping_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            status VARCHAR(50) NOT NULL DEFAULT "laukiama",
            delivery_method VARCHAR(50) NOT NULL DEFAULT "address",
            delivery_details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $orderColumns = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('discount_code', $orderColumns, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN discount_code VARCHAR(80) NULL AFTER customer_address');
    }
    if (!in_array('discount_amount', $orderColumns, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER discount_code');
    }
    if (!in_array('shipping_amount', $orderColumns, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN shipping_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER discount_amount');
    }
    if (!in_array('delivery_method', $orderColumns, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN delivery_method VARCHAR(50) NOT NULL DEFAULT "address" AFTER status');
    }
    if (!in_array('delivery_details', $orderColumns, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN delivery_details TEXT NULL AFTER delivery_method');
    }
    if (!in_array('customer_phone', $orderColumns, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN customer_phone VARCHAR(50) NOT NULL DEFAULT "" AFTER customer_email');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    
    // ATNAUJINTA: Pridedame variation_info stulpelį, jei jo nėra
    $orderItemColumns = $pdo->query("SHOW COLUMNS FROM order_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('variation_info', $orderItemColumns, true)) {
        $pdo->exec('ALTER TABLE order_items ADD COLUMN variation_info TEXT DEFAULT NULL AFTER price');
    }
}

function ensureDiscountTables(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS discount_settings (
            id TINYINT PRIMARY KEY,
            type ENUM("none","percent","amount","free_shipping") NOT NULL DEFAULT "none",
            value DECIMAL(10,2) NOT NULL DEFAULT 0,
            free_shipping TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS discount_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(80) NOT NULL UNIQUE,
            type ENUM("percent","amount","free_shipping") NOT NULL DEFAULT "percent",
            value DECIMAL(10,2) NOT NULL DEFAULT 0,
            usage_limit INT NOT NULL DEFAULT 0,
            used_count INT NOT NULL DEFAULT 0,
            free_shipping TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $existing = $pdo->query('SELECT COUNT(*) FROM discount_settings')->fetchColumn();
    if ((int)$existing === 0) {
        $pdo->exec("INSERT INTO discount_settings (id, type, value, free_shipping) VALUES (1, 'none', 0, 0)");
    }

    $columns = $pdo->query("SHOW COLUMNS FROM discount_settings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('free_shipping', $columns, true)) {
        $pdo->exec('ALTER TABLE discount_settings ADD COLUMN free_shipping TINYINT(1) NOT NULL DEFAULT 0 AFTER value');
    }

    $codeColumns = $pdo->query("SHOW COLUMNS FROM discount_codes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('free_shipping', $codeColumns, true)) {
        $pdo->exec('ALTER TABLE discount_codes ADD COLUMN free_shipping TINYINT(1) NOT NULL DEFAULT 0 AFTER used_count');
    }
    $settingsType = $pdo->query("SHOW COLUMNS FROM discount_settings LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
    if ($settingsType && strpos($settingsType['Type'], 'free_shipping') === false) {
        $pdo->exec('ALTER TABLE discount_settings MODIFY type ENUM("none","percent","amount","free_shipping") NOT NULL DEFAULT "none"');
    }
    $codeType = $pdo->query("SHOW COLUMNS FROM discount_codes LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
    if ($codeType && strpos($codeType['Type'], 'free_shipping') === false) {
        $pdo->exec('ALTER TABLE discount_codes MODIFY type ENUM("percent","amount","free_shipping") NOT NULL DEFAULT "percent"');
    }
}

function ensureCategoryDiscounts(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS category_discounts (
            category_id INT PRIMARY KEY,
            type ENUM("none","percent","amount","free_shipping") NOT NULL DEFAULT "none",
            value DECIMAL(10,2) NOT NULL DEFAULT 0,
            free_shipping TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $typeCol = $pdo->query("SHOW COLUMNS FROM category_discounts LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
    if ($typeCol && strpos($typeCol['Type'], 'free_shipping') === false) {
        $pdo->exec('ALTER TABLE category_discounts MODIFY type ENUM("none","percent","amount","free_shipping") NOT NULL DEFAULT "none"');
    }
}

function ensureCartTables(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cart_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_product (user_id, product_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function seedNewsExamples(PDO $pdo): void {
    $count = (int) $pdo->query('SELECT COUNT(*) FROM news')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $samples = [
        [
            'title' => 'Nuotolinis cukraus stebėjimas kasdienybėje',
            'image_url' => 'https://images.unsplash.com/photo-1518611012118-696072aa579a?auto=format&fit=crop&w=1200&q=80',
            'body' => 'Dalijamės patarimais, kaip naudoti nuolatinio gliukozės stebėjimo sensorius ir gauti įspėjimus telefone laiku.',
            'is_featured' => 1,
        ],
        [
            'title' => 'Nauji mažo GI užkandžiai kelionei',
            'image_url' => 'https://images.unsplash.com/photo-1540189549336-e6e99c3679fe?auto=format&fit=crop&w=1200&q=80',
            'body' => 'Į asortimentą įtraukėme baltyminius batonėlius ir riešutų mišinius, pritaikytus diabetui kontroliuoti.',
            'is_featured' => 1,
        ],
        [
            'title' => 'Kaip kalibruoti gliukometrą namuose',
            'image_url' => 'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=1200&q=80',
            'body' => 'Trumpas žingsnis po žingsnio gidas, kaip pasiruošti matavimams, kad rezultatai būtų patikimi.',
            'is_featured' => 1,
        ],
        [
            'title' => 'Cukrinio diabeto klubas Marijampolėje',
            'image_url' => 'https://images.unsplash.com/photo-1478144592103-25e218a04891?auto=format&fit=crop&w=1200&q=80',
            'body' => 'Kviečiame į bendruomenės susitikimus pasidalinti receptais, fizinio aktyvumo patarimais ir pagalba naujokams.',
            'is_featured' => 0,
        ],
    ];

    $stmt = $pdo->prepare('INSERT INTO news (title, summary, image_url, body, visibility, is_featured) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($samples as $news) {
        $summary = mb_substr(strip_tags($news['body']), 0, 160) . '...';
        $stmt->execute([$news['title'], $summary, $news['image_url'], $news['body'], 'public', $news['is_featured']]);
    }
}

function ensurePasswordResetsTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            token VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureRecipeRatingsTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS recipe_ratings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipe_id INT NOT NULL,
            user_id INT NOT NULL,
            rating TINYINT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_recipe_rating (recipe_id, user_id),
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}
?>
