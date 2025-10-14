<?php
// Параметры подключения к БД
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'joomla';
$prefix = 'jhvdn_';

// Подключение к MySQL
$db = new mysqli($host, $user, $password, $database);
if ($db->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . $db->connect_error]));
}

// Создаем папку для сохранения файлов, если ее нет
$export_dir = 'category_exports';
if (!file_exists($export_dir)) {
    mkdir($export_dir, 0755, true);
}

// Максимальное количество материалов в одном файле
$max_items_per_file = 150;

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

// Экспортируем каждую категорию
foreach ($categories_to_export as $category) {
    $category_id = $category['id'];
    
    // Получаем контент для категории
    $content_query = "
        SELECT c.`id`, c.`title`, c.`alias`, c.`introtext`, c.`fulltext`, c.`created`, c.`modified`
        FROM {$prefix}content AS c
        WHERE c.catid = {$category_id}
        AND c.state = 1
    ";
    $content_result = $db->query($content_query);
    
    if (!$content_result) {
        echo "Query failed for category ID {$category_id}: " . $db->error . "\n";
        continue;
    }
    
    // Формируем данные в нужном формате
    $content_data = [];
    while ($row = $content_result->fetch_assoc()) {
        // Преобразуем дату в формат Y-m-d
        $created_date = date('Y-m-d', strtotime($row['created']));
        
        // Формируем запись в требуемом формате
        $content_data[] = [
            'id_news' => $row['id'],
            'name' => $row['title'],
            'anons' => $row['introtext'],
            'body' => $row['fulltext'],
            'small' => '',
            'middle' => '',
            'big' => '',
            'putdate' => $created_date,
            'slider' => 'no',
            'hide' => 'show'
        ];
    }
    
    // Пропускаем категории без контента
    if (empty($content_data)) {
        echo "Skipping category ID {$category_id} - no content found\n";
        continue;
    }
    
    // Разбиваем данные на части по $max_items_per_file
    $content_chunks = array_chunk($content_data, $max_items_per_file);
    $total_files = count($content_chunks);
    
    echo "Category ID {$category_id} ({$category['title']}): " . count($content_data) . " items, splitting into {$total_files} files\n";
    
    // Создаем файлы для каждой части
    $safe_alias = preg_replace('/[^a-zA-Z0-9_-]/', '_', $category['alias'] ?? 'noalias');
    
    foreach ($content_chunks as $chunk_index => $chunk_data) {
        // Формируем имя файла с индексом
        $filename = $export_dir . '/category_' . $category_id . '_' . $safe_alias;
        
        // Добавляем индекс в скобках, если файлов больше одного
        if ($total_files > 1) {
            $filename .= '(' . ($chunk_index + 1) . ')';
        }
        
        $filename .= '.json';
        
        // Создаем структуру JSON в требуемом формате
        $json_structure = [
            [
                "type" => "header",
                "version" => "5.0.4deb2+deb11u1",
                "comment" => "Export to JSON plugin for PHPMyAdmin"
            ],
            [
                "type" => "database",
                "name" => $database
            ],
            [
                "type" => "table",
                "name" => "system_news",
                "database" => $database,
                "data" => $chunk_data
            ]
        ];
        
        // Сохраняем в файл
        if (file_put_contents($filename, json_encode($json_structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
            echo "  Created file: {$filename} with " . count($chunk_data) . " items\n";
        } else {
            echo "  Failed to create file: {$filename}\n";
        }
    }
}

$db->close();

// Выводим статистику
echo "\nExport completed!\n";
echo "Total categories processed: " . count($categories_to_export) . "\n";
?>