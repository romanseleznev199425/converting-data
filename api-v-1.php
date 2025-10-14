<?php
// Параметры подключения к БД
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'joomla';
$prefix = 'okvsi_';

// Массив ID категорий для обработки
$category_ids = [230, 231, 232, 339]; // Укажите нужные ID категорий

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

foreach ($category_ids as $category_id) {
    // 1. Получаем информацию о категории
    $category_query = "
        SELECT id, title, alias 
        FROM {$prefix}categories 
        WHERE id = {$category_id}
        AND published = 1
    ";
    $category_result = $db->query($category_query);
    
    if (!$category_result || $category_result->num_rows === 0) {
        echo "Category ID {$category_id} not found or not published\n";
        continue;
    }
    
    $category = $category_result->fetch_assoc();
    
    // 2. Получаем контент для категории
    $content_query = "
        SELECT c.`id`, c.`title`, c.`introtext`, c.`fulltext`, c.`created` 
        FROM {$prefix}content AS c
        WHERE c.catid = {$category_id}
        AND c.state = 1
    ";
    $content_result = $db->query($content_query);
    
    if (!$content_result) {
        echo "Query failed for category ID {$category_id}: " . $db->error . "\n";
        continue;
    }
    
    // Формируем данные для JSON
    $content_data = [];
    while ($row = $content_result->fetch_assoc()) {
        $content_data[] = $row;
    }
    
    // Создаем структуру JSON
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
            "name" => "{$prefix}content",
            "database" => $database,
            "data" => $content_data
        ]
    ];
    
    // Генерируем имя файла
    $filename = $export_dir . '/category_' . $category_id . '_' . 
               ($category['alias'] ?? 'noalias') . '.json';
    
    // Сохраняем в файл
    file_put_contents($filename, json_encode($json_structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    echo "Created file: {$filename} with " . count($content_data) . " items\n";
}

$db->close();

echo "Export completed!\n";
?>