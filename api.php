<?php
// Параметры подключения к БД
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'joomla';
$prefix = 'okvsi_';

// ID родительской категории
$parent_category_id = 	298;

// Подключение к MySQL
$db = new mysqli($host, $user, $password, $database);
if ($db->connect_error) {
    die(json_encode(['error' => 'Database connection failed']));
}

// 1. Получаем все дочерние категории (1 уровень вложенности)
$child_categories_query = "
    SELECT id, title, alias 
    FROM {$prefix}categories 
    WHERE parent_id = {$parent_category_id}
    AND published = 1
";
$child_categories = $db->query($child_categories_query);

if (!$child_categories) {
    die(json_encode(['error' => 'Child categories query failed: ' . $db->error]));
}

// 2. Для каждой дочерней категории получаем контент и создаем JSON файл
while ($child_category = $child_categories->fetch_assoc()) {
    $content_query = "
        SELECT c.`id`, c.`title`, c.`introtext`, c.`fulltext`, c.`created` 
        FROM {$prefix}content AS c
        WHERE c.catid = {$child_category['id']}
        AND c.state = 1
    ";
    $content_result = $db->query($content_query);
    
    if (!$content_result) {
        continue; // Пропускаем категории с ошибками
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
    
    // Генерируем имя файла (используем alias или ID категории)
    $filename = 'category_' . ($child_category['alias'] ?? $child_category['id']) . '.json';
    
    // Сохраняем в файл
    file_put_contents($filename, json_encode($json_structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    echo "Created file: {$filename} with " . count($content_data) . " items\n";
}

$db->close();
?>