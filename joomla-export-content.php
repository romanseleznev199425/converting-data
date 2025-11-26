<?php
// Параметры подключения к БД
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'joomla';
$prefix = 'goss_';

// Подключение к MySQL
$db = new mysqli($host, $user, $password, $database);
if ($db->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . $db->connect_error]));
}

// Создаем папку для сохранения файлов, если ее нет
$export_dir = 'joomla_articles_export';
if (!file_exists($export_dir)) {
    mkdir($export_dir, 0755, true);
}

// Максимальное количество материалов в одном файле
$max_items_per_file = 50;

// Получаем ВСЕ опубликованные категории
$all_categories_query = "
    SELECT id, title, alias, parent_id, level, path
    FROM {$prefix}categories 
    WHERE published = 1
    ORDER BY lft ASC
";
$all_categories_result = $db->query($all_categories_query);

if (!$all_categories_result) {
    die("Failed to get categories: " . $db->error);
}

$categories_to_export = [];
while ($category = $all_categories_result->fetch_assoc()) {
    $categories_to_export[] = $category;
}

echo "Found " . count($categories_to_export) . " categories for export\n";

// Функция для парсинга изображений из Joomla
function parseJoomlaImages($imagesData) {
    if (empty($imagesData)) {
        return ['image_intro' => '', 'image_intro_alt' => ''];
    }
    
    // Пробуем декодировать как JSON (Joomla 4)
    $decoded = json_decode($imagesData, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return [
            'image_intro' => $decoded['image_intro'] ?? '',
            'image_intro_alt' => $decoded['image_intro_alt'] ?? ''
        ];
    }
    
    // Пробуем как сериализованный массив (Joomla 2-3)
    if (preg_match('/^a:\d+:{/', $imagesData)) {
        $unserialized = @unserialize($imagesData);
        if ($unserialized !== false) {
            return [
                'image_intro' => $unserialized['image_intro'] ?? '',
                'image_intro_alt' => $unserialized['image_intro_alt'] ?? ''
            ];
        }
    }
    
    return ['image_intro' => '', 'image_intro_alt' => ''];
}

// Функция для форматирования даты
function formatExportDate($date) {
    if (empty($date) || $date === '0000-00-00 00:00:00') {
        return '';
    }
    
    try {
        $dateTime = new DateTime($date);
        return $dateTime->format('d.m.Y, H:i:s');
    } catch (Exception $e) {
        return '';
    }
}

// Функция для очистки текста
function cleanExportText($text) {
    if (empty($text)) return '';
    $text = trim($text);
    $text = preg_replace('/\s+/', ' ', $text);
    return $text;
}

// Экспортируем каждую категорию
foreach ($categories_to_export as $category) {
    $category_id = $category['id'];
    
    // Получаем контент для категории с ВСЕМИ полями
    $content_query = "
        SELECT 
            c.`id`, 
            c.`title`, 
            c.`alias`, 
            c.`introtext`, 
            c.`fulltext`, 
            c.`created`, 
            c.`modified`,
            c.`publish_up`,
            c.`metadesc`,
            c.`metakey`,
            c.`images`,
            c.`state`,
            c.`catid`
        FROM {$prefix}content AS c
        WHERE c.catid = {$category_id}
        AND c.state = 1
        ORDER BY c.created DESC
    ";
    $content_result = $db->query($content_query);
    
    if (!$content_result) {
        echo "Query failed for category ID {$category_id}: " . $db->error . "\n";
        continue;
    }
    
    // Формируем данные в нужном формате
    $articles_data = [];
    while ($row = $content_result->fetch_assoc()) {
        // Парсим изображения
        $images = parseJoomlaImages($row['images']);
        
        // Форматируем даты
        $created_date = formatExportDate($row['created']);
        $publish_up_date = formatExportDate($row['publish_up']);
        
        // Формируем запись в требуемом формате
        $articles_data[] = [
            'title' => $row['title'],
            'alias' => $row['alias'],
            'introtext' => cleanExportText($row['introtext']),
            'fulltext' => cleanExportText($row['fulltext']),
            'image_intro' => $images['image_intro'],
            'image_intro_alt' => $images['image_intro_alt'],
            'metadesc' => $row['metadesc'] ?? '',
            'metakey' => $row['metakey'] ?? '',
            'created' => $created_date,
            'publish_up' => $publish_up_date,
            'catid' => $row['catid'],
            'state' => $row['state'],
            'original_id' => $row['id']
        ];
    }
    
    // Пропускаем категории без контента
    if (empty($articles_data)) {
        echo "Skipping category ID {$category_id} - no content found\n";
        continue;
    }
    
    // Разбиваем данные на части по $max_items_per_file
    $articles_chunks = array_chunk($articles_data, $max_items_per_file);
    $total_files = count($articles_chunks);
    
    echo "Category ID {$category_id} ({$category['title']}): " . count($articles_data) . " articles, splitting into {$total_files} files\n";
    
    // Создаем файлы для каждой части
    $safe_alias = preg_replace('/[^a-zA-Z0-9_-]/', '_', $category['alias'] ?? 'category_' . $category_id);
    
    foreach ($articles_chunks as $chunk_index => $chunk_data) {
        // Формируем имя файла
        $filename = $export_dir . '/articles_' . $safe_alias;
        
        // Добавляем индекс в скобках, если файлов больше одного
        if ($total_files > 1) {
            $filename .= '_part' . ($chunk_index + 1);
        }
        
        $filename .= '.json';
        
        // Создаем структуру JSON в требуемом формате
        $json_structure = [
            'articles' => $chunk_data
        ];
        
        // Сохраняем в файл
        if (file_put_contents($filename, json_encode($json_structure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT))) {
            echo "  Created file: {$filename} with " . count($chunk_data) . " articles\n";
            
            // Выводим пример первой статьи для проверки формата
            if ($chunk_index === 0 && !empty($chunk_data)) {
                $first_article = $chunk_data[0];
                echo "    Sample article: \"{$first_article['title']}\"";
                if (!empty($first_article['image_intro'])) {
                    echo " [with image: {$first_article['image_intro']}]";
                }
                echo "\n";
            }
        } else {
            echo "  Failed to create file: {$filename}\n";
        }
    }
}

$db->close();

// Выводим статистику
echo "\nExport completed!\n";
echo "Total categories processed: " . count($categories_to_export) . "\n";
echo "Export files saved in: {$export_dir}/\n";

// Создаем README файл с информацией о экспорте
$readme_content = "Joomla Articles Export\n";
$readme_content .= "=====================\n\n";
$readme_content .= "Exported from database: {$database}\n";
$readme_content .= "Table prefix: {$prefix}\n";
$readme_content .= "Export date: " . date('Y-m-d H:i:s') . "\n";
$readme_content .= "Total categories: " . count($categories_to_export) . "\n\n";
$readme_content .= "File format:\n";
$readme_content .= "------------\n";
$readme_content .= "Each file contains JSON in the following format:\n";
$readme_content .= "{\n";
$readme_content .= "  \"articles\": [\n";
$readme_content .= "    {\n";
$readme_content .= "      \"title\": \"Article title\",\n";
$readme_content .= "      \"alias\": \"article-alias\",\n";
$readme_content .= "      \"introtext\": \"Intro text...\",\n";
$readme_content .= "      \"fulltext\": \"Full text...\",\n";
$readme_content .= "      \"image_intro\": \"/path/to/image.jpg\",\n";
$readme_content .= "      \"image_intro_alt\": \"Alt text\",\n";
$readme_content .= "      \"metadesc\": \"Meta description\",\n";
$readme_content .= "      \"metakey\": \"keywords\",\n";
$readme_content .= "      \"created\": \"24.11.2025, 11:11:11\",\n";
$readme_content .= "      \"publish_up\": \"24.11.2025, 11:11:11\"\n";
$readme_content .= "    }\n";
$readme_content .= "  ]\n";
$readme_content .= "}\n\n";
$readme_content .= "Files are split by category and limited to {$max_items_per_file} articles per file.\n";

file_put_contents($export_dir . '/README.txt', $readme_content);
echo "README file created: {$export_dir}/README.txt\n";
?>